<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Incidents Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Incident\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\Boundary;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentCategory;
use Uhifadhi\Incident\Model\IncidentHues;
use Uhifadhi\Incident\Model\IncidentMapPayload;

/**
 * WHERE EVERY INCIDENT WAS FILED, STATED IN PHP.
 *
 * ONE BUILDER for every incidents map there is — the dashboard's plate, the
 * map+results plate, and the single point on a case file — so the same mark
 * cannot mean two things on two screens.
 *
 * The module writes no map JavaScript. It says what is on the map and the atlas
 * draws it: the deployment's imagery, the boundary's one treatment, the control
 * stack, the legend with a switch per row, and fullscreen.
 *
 * ONE LAYER PER CATEGORY, because a category is what a person switches on and
 * off. The hue is the category's own, read from {@see IncidentHues} — the same
 * value the chips are drawn in — so a marker and a chip cannot drift apart.
 *
 * The zones under them are the AREA's, not this module's: drawn as quiet
 * outlines wearing their names, so a mark can be read against the ground it
 * sits in.
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AreaBundle/Service/AreaMap.php
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/docs/components.md
 */
final readonly class IncidentMapService
{
    /** The heading every category row sits under, so the plate reads as this module's. */
    public const string GROUP = 'Incidents';

    /** The zones layer's id, which is also what its legend row switches. */
    public const string ZONES_LAYER = 'incident.zones';

    /**
     * The quiet outline the area's zones are drawn in.
     *
     * A layer's colour is data, and the atlas offers no token for it: a legend
     * swatch is handed over as a colour, not as a class the host would have to
     * have read this module's stylesheet to understand.
     */
    public const string ZONE_SWATCH = '#B9C8BD';

    public function __construct(
        private MapBuilderInterface $maps,
        private ZoneRepository $zones,
    ) {
    }

    /**
     * The plate an incidents screen renders: the area, its zones, and the
     * incidents handed in, split by category.
     *
     * The categories are the CALLER's choice, not the whole taxonomy: a
     * dashboard states every category it filed under, a case file states the
     * one its incident belongs to.
     *
     * @param list<Incident>         $incidents
     * @param list<IncidentCategory> $categories
     */
    public function forArea(AreaOfInterest $area, array $incidents, array $categories): AtlasMap
    {
        return self::compose(
            $this->maps,
            $area->hasBoundary() ? $area->getGeom() : null,
            IncidentMapPayload::of($incidents),
            array_map(static fn (IncidentCategory $category): array => [
                'slug' => $category->getSlug(),
                'label' => $category->getLabel(),
                'colourKey' => $category->getColourKey(),
            ], $categories),
            array_map(static fn (Zone $zone): array => [
                // A zone with no name is drawn without a label rather than left
                // off the map: the outline is the fact, the name is the caption.
                'name' => $zone->getName() ?? '',
                'geom' => $zone->getGeom(),
            ], $this->zones->zonesFor($area)),
        );
    }

    /**
     * The map itself, from plain data.
     *
     * Static and entity-free so the shape of the plate — which layer, which
     * hue, which legend row — is unit-tested without a database behind it.
     *
     * @param string|null                                                 $boundary   the area's geom as GeoJSON text
     * @param array<string, mixed>                                        $collection the FeatureCollection {@see IncidentMapPayload::of()} builds
     * @param list<array{slug: string, label: string, colourKey: string}> $categories
     * @param list<array{name: string, geom: string|null}>                $zones
     */
    public static function compose(
        MapBuilderInterface $maps,
        ?string $boundary,
        array $collection,
        array $categories,
        array $zones,
    ): AtlasMap {
        $map = $maps->createMap();

        $geometry = self::decode($boundary);
        if (null !== $geometry) {
            $map->boundary(new Boundary($geometry));
        }

        $drawnZones = [];
        foreach ($zones as $zone) {
            $shape = self::decode($zone['geom']);
            if (null !== $shape) {
                $drawnZones[] = self::feature($shape, ['label' => $zone['name']]);
            }
        }

        // THE ZONES SIT UNDER THE MARKS, so they are added first: the plate
        // draws layers in the order they are stated.
        $map->addLayer(new GeoJsonLayer(
            id: self::ZONES_LAYER,
            label: 'Zones',
            features: self::collection($drawnZones),
            swatch: self::ZONE_SWATCH,
            shape: LayerShape::Line,
            visible: [] !== $drawnZones,
            count: \count($drawnZones),
            group: self::GROUP,
        ));

        $features = $collection['features'] ?? [];
        foreach ($categories as $category) {
            $own = \is_array($features) ? array_values(array_filter(
                $features,
                static fn (mixed $feature): bool => \is_array($feature)
                    && \is_array($feature['properties'] ?? null)
                    && ($feature['properties']['slug'] ?? null) === $category['slug'],
            )) : [];

            $map->addLayer(new GeoJsonLayer(
                id: 'incident.'.$category['slug'],
                label: $category['label'],
                features: self::collection($own),
                swatch: IncidentHues::of($category['colourKey']),
                shape: LayerShape::Point,
                visible: [] !== $own,
                count: \count($own),
                group: self::GROUP,
            ));
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function feature(array $geometry, array $properties): array
    {
        return ['type' => 'Feature', 'properties' => $properties, 'geometry' => $geometry];
    }

    /**
     * @param list<mixed> $features
     *
     * @return array<string, mixed>
     */
    private static function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $geoJson): ?array
    {
        if (null === $geoJson || '' === $geoJson) {
            return null;
        }

        try {
            $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_string($decoded['type'] ?? null)) {
            return null;
        }

        $geometry = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $geometry[$key] = $value;
            }
        }

        return $geometry;
    }
}
