import { Controller } from '@hotwired/stimulus';

/*
 * IN·03 / IN·04 — where every incident was filed.
 *
 * SELF-HOSTED LEAFLET, read off `window.L`. The host loads it as a classic
 * script in <head> (see @UhifadhiLabsIncident/base.html.twig), so it is there
 * before this module runs. MapLibre is deliberately not used anywhere in this
 * deployment: raster tiles plus GeoJSON need no WebGL, and WebGL failed silently
 * — a blank map — in constrained environments.
 *
 * THE MARKS MEAN SOMETHING, and they mean exactly what the legend beside them
 * says: hue is the CATEGORY, filled means still OPEN, hollow means resolved or
 * closed, and a dashed ring means HIGH severity. The colours are read from the
 * same CSS custom properties the chips use (--i-poach and friends), so a marker
 * and a chip for one category can never drift apart.
 *
 * An area with no incidents still gets a real map — just the boundary.
 */
export default class extends Controller {
    static values = {
        incidents: Object,
        boundary: String,
    };

    connect() {
        this.L = window.L;
        if (!this.L) {
            // No Leaflet, no map — and no exception either. The legend and the
            // rest of the widget are still perfectly readable.
            return;
        }

        // A Turbo preview is a display-only clone of the last snapshot; the real
        // connect follows on the live render. Building a map here means building
        // it twice into the same navigation, and the preview copy dies mid-add
        // when Turbo swaps the body under it.
        if (document.documentElement.hasAttribute('data-turbo-preview')) {
            return;
        }

        // A restored snapshot still carries the previous map's panes and
        // leaflet-* classes (the DOM survives the cache even though the map
        // instance did not). Leaflet must start from the same blank container it
        // got on first load.
        this.element.innerHTML = '';
        this.element.className = this.element.className.replace(/\bleaflet-\S+/g, '').trim();

        // Disconnect fires only after Turbo has already replaced the body — too
        // late to keep the cached snapshot clean. Tear down before it caches.
        this.beforeCache = () => this.teardown();
        document.addEventListener('turbo:before-cache', this.beforeCache);

        this.map = this.L.map(this.element, { zoomControl: true, attributionControl: false });
        this.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(this.map);

        const bounds = this.L.latLngBounds([]);
        const boundary = this.drawBoundary();
        if (boundary) {
            bounds.extend(boundary.getBounds());
        }

        for (const feature of this.incidentsValue.features ?? []) {
            const marker = this.drawIncident(feature);
            if (marker) {
                bounds.extend(marker.getLatLng());
            }
        }

        if (bounds.isValid()) {
            this.map.fitBounds(bounds.pad(0.08));
        } else {
            // Nothing to show is not an error; the world is a fine default.
            this.map.setView([0, 0], 2);
        }
    }

    disconnect() {
        this.teardown();
    }

    teardown() {
        if (this.beforeCache) {
            document.removeEventListener('turbo:before-cache', this.beforeCache);
            this.beforeCache = null;
        }
        // stop() before remove(): an animation still in flight fires on a pane
        // remove() has already detached (same crash leaflet_plate documents in
        // patrol-module). And when Turbo has already swapped the body away, the
        // map's DOM is gone before remove() runs — that must not throw either.
        try {
            this.map?.stop();
            this.map?.remove();
        } catch (error) {
            // The map being torn down is the outcome we wanted.
        }
        this.map = null;
    }

    drawBoundary() {
        const geometry = parse(this.boundaryValue);
        if (!geometry) {
            return null;
        }

        return this.L.geoJSON(geometry, {
            style: { color: cssVar('--acc', '#3ED9A8'), weight: 1.4, opacity: 0.7, fill: false, dashArray: '4 4' },
        }).addTo(this.map);
    }

    drawIncident(feature) {
        const coordinates = feature?.geometry?.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length < 2) {
            return null;
        }

        const props = feature.properties ?? {};
        const colour = cssVar(`--i-${props.colour}`, cssVar('--acc', '#3ED9A8'));
        const open = true === props.open;

        const marker = this.L.circleMarker([coordinates[1], coordinates[0]], {
            radius: 'high' === props.severity ? 7 : 5.5,
            color: colour,
            weight: 'high' === props.severity ? 2.4 : 1.6,
            // FILLED means open, HOLLOW means finished — exactly what the legend
            // promises, and the only difference between the two marks.
            fillColor: colour,
            fillOpacity: open ? 0.85 : 0,
            dashArray: 'high' === props.severity ? '3 3' : null,
        }).addTo(this.map);

        marker.bindTooltip(
            `${props.reference} · ${props.subcategory} · ${props.statusLabel}`,
            { direction: 'top', sticky: true },
        );
        marker.bindPopup(
            `<b>${escape(props.reference)}</b><br>${escape(props.title)}<br>` +
            `<small>${escape(props.category)} · ${escape(props.zone)} · ${escape(props.statusLabel)}</small>`,
        );

        return marker;
    }
}

/** The module's palette lives in incidents.css; JS reads it rather than repeating it. */
function cssVar(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return '' !== value ? value : fallback;
}

function parse(raw) {
    if (!raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (error) {
        // A geometry we cannot read is one layer missing, never a broken page.
        return null;
    }
}

function escape(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
