import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';
import { drawBoundary } from 'uhifadhi/boundary';
import { mountMapChrome } from 'uhifadhi/map-chrome';

/*
 * IN·03 / IN·04 / IN·01 — where every incident was filed, on the platform's one
 * map plate. The overview maps and the detail "Where" card are the same plate.
 *
 * THE PLATE IS THE PLATFORM'S, NOT THIS MODULE'S. The two base layers come from
 * the atlas's one basemap module (`uhifadhi/basemaps`, an importmap
 * specifier): satellite is the platform's imagery, standard is OSM. The boundary
 * comes from `uhifadhi/boundary` and the zoom column, layer menu, scale bar and
 * fullscreen from `uhifadhi/map-chrome`, exactly as the patrols module draws
 * them — so an incident map, a patrol map and the area map cannot disagree about
 * what satellite, a boundary or a control looks like. The module holds no second
 * opinion, and ships no second copy of the chrome CSS (that lives in map.css,
 * which the base template links).
 *
 * SELF-HOSTED LEAFLET, read off `window.L`: the host loads it as a classic
 * <script> in <head> (see @UhifadhiIncident/base.html.twig), so it is there
 * before this module runs. MapLibre is deliberately not used — raster tiles plus
 * GeoJSON need no WebGL, and WebGL failed silently (a blank map) in constrained
 * environments.
 *
 * THE MARKS MEAN SOMETHING, and they mean exactly what the legend beside them
 * says: hue is the CATEGORY, filled means still OPEN, hollow means resolved or
 * closed, and a dashed ring marks the SERIOUS END (high or critical). The colours are read from the
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
            console.error('[incident] window.L (Leaflet) is not loaded — the incident base template must include leaflet.js');

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

        // A container without layout (a hidden widget-library preview card, a
        // collapsed panel) gives Leaflet's vector renderer no bounds, and the
        // first geometry added dies in _clipPoints. Build only once the element
        // actually has a size.
        if (0 === this.element.clientWidth || 0 === this.element.clientHeight) {
            this.sizeObserver = new ResizeObserver(() => {
                if (this.element.clientWidth > 0 && this.element.clientHeight > 0) {
                    this.sizeObserver.disconnect();
                    this.sizeObserver = null;
                    this.build();
                }
            });
            this.sizeObserver.observe(this.element);

            return;
        }

        this.build();
    }

    build() {
        const L = this.L;

        // The Leaflet container is an inner canvas so the chrome (which mounts on
        // the .viewer frame) sits over it, never inside its pane — the same split
        // every host map draws (a canvas child of the plate). It carries
        // .map-chrome-host, the class the HOST's app.css hangs the control,
        // scale and tooltip styling on, so those read the platform way.
        this.canvas = document.createElement('div');
        this.canvas.className = 'i-mapcanvas map-chrome-host';
        this.element.appendChild(this.canvas);

        // Controls, the live scale bar, attribution and the Ctrl/⌘-scroll bargain
        // all come from the platform chrome module, mounted after the overlay.
        this.map = L.map(this.canvas, { zoomControl: false, attributionControl: true });

        // The platform's basemaps, not the module's own: the same imagery a person
        // sees on the area map, on every incident map. Satellite is the default.
        this.bases = {
            satellite: satelliteLayer(L, this.map),
            osm: streetLayer(L),
        };
        this.bases.satellite.addTo(this.map);

        // The view must exist BEFORE any vector layer: on a view-less map every
        // addLayer is deferred until the first setView, and draining that queue
        // trips Leaflet 1.9.4 when a geoJSON group and a bare circleMarker share
        // the renderer. Setting a fallback view first is the verified fix, and
        // fitBounds below still wins whenever there is anything to frame.
        this.map.setView([-3.2, -29.5], 8);

        const bounds = L.latLngBounds([]);

        // What is DRAWN must never be able to kill the map itself: a bad payload
        // must still leave the tiles, the zoom pills, the layer menu and the
        // scroll bargain alive. The overlay is wrapped; the chrome is not.
        try {
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
        } catch (error) {
            console.error('[incident] the map overlay failed to draw', error);
        }

        if (bounds.isValid()) {
            this.lastFit = bounds.pad(0.08);
            this.map.fitBounds(this.lastFit);
        }
        // Nothing to show is not an error; the fallback view set above stands.

        // After the overlay, so the DIM pill has this plate's scrim to switch, and
        // fullscreen takes the whole widget card (the filter chips and the legend
        // are part of reading the map), not just the tiles.
        this.chrome = mountMapChrome(L, this.map, this.element, {
            bases: this.bases,
            scrim: this.scrimLayer,
            scrimOn: Boolean(this.scrimLayer) && this.map.hasLayer(this.scrimLayer),
            fullscreenTarget: this.element.closest('.c') ?? this.element,
            onResize: () => this.refit(),
        });
    }

    /** Re-frame whatever the plate opened on when the viewport changes size. */
    refit() {
        if (this.lastFit && this.lastFit.isValid()) {
            this.map?.fitBounds(this.lastFit);
        }
    }

    disconnect() {
        this.teardown();
    }

    teardown() {
        this.sizeObserver?.disconnect();
        this.sizeObserver = null;
        if (this.beforeCache) {
            document.removeEventListener('turbo:before-cache', this.beforeCache);
            this.beforeCache = null;
        }
        this.chrome?.destroy();
        this.chrome = null;
        // stop() before remove(): an animation still in flight fires on a pane
        // remove() has already detached. And when Turbo has already swapped the
        // body away, the map's DOM is gone before remove() runs — neither must throw.
        try {
            this.map?.stop();
            this.map?.remove();
        } catch (error) {
            // The map being torn down is the outcome we wanted.
        }
        this.map = null;
    }

    /**
     * The area outline in the platform's one boundary treatment (the white
     * casing, the jade line and the outside-the-area scrim), so a boundary reads
     * the same on the area map and on an incident map. The scrim rides on the
     * returned layer and is switched by the DIM pill.
     */
    drawBoundary() {
        const geometry = parse(this.boundaryValue);
        if (!geometry) {
            return null;
        }

        const boundary = drawBoundary(this.L, this.map, geometry, { scrim: true });
        this.scrimLayer = boundary?.scrimLayer ?? null;

        return boundary;
    }

    drawIncident(feature) {
        const coordinates = feature?.geometry?.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length < 2) {
            return null;
        }

        const props = feature.properties ?? {};
        const colour = cssVar(`--i-${props.colour}`, cssVar('--acc', '#3ED9A8'));
        const open = true === props.open;
        // The ring marks the SERIOUS END — high or critical — not high alone.
        const serious = 'high' === props.severity || 'critical' === props.severity;

        const marker = this.L.circleMarker([coordinates[1], coordinates[0]], {
            radius: serious ? 7 : 5.5,
            color: colour,
            weight: serious ? 2.4 : 1.6,
            // FILLED means open, HOLLOW means finished — exactly what the legend
            // promises, and the only difference between the two marks.
            fillColor: colour,
            fillOpacity: open ? 0.85 : 0,
            dashArray: serious ? '3 3' : null,
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
