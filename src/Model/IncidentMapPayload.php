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

namespace UhifadhiLabs\Incident\Model;

use UhifadhiLabs\Incident\Entity\Incident;

/**
 * WHAT A MAP DRAWS, and what each mark MEANS.
 *
 * ONE BUILDER for every incidents map there is — the dashboard's whole month, the
 * map+results plate, and the single point on a case file. The host rule is that
 * the same layer renders identically everywhere, and the only way to guarantee
 * that is for there to be one place the layer is described.
 *
 * The meaning is exactly what the legend beside it promises:
 *
 *   hue          = the category
 *   filled       = still open · hollow = resolved or closed
 *   dashed ring  = high severity
 *
 * A JSON-able array rather than rendered markup: the map is Leaflet
 * (`window.L`), fed by a Stimulus controller — never MapLibre, and never an SVG
 * plate pretending to be a map.
 */
final class IncidentMapPayload
{
    /**
     * @param list<Incident> $incidents
     *
     * @return array{type: string, features: list<array{type: string, geometry: array<string, mixed>, properties: array<string, mixed>}>}
     */
    public static function of(array $incidents): array
    {
        $features = [];
        foreach ($incidents as $incident) {
            /** @var array<string, mixed>|null $geometry */
            $geometry = json_decode($incident->getPosition(), true);
            if (!\is_array($geometry)) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'reference' => $incident->getReference(),
                    'title' => $incident->getTitle(),
                    'colour' => $incident->getCategory()->getColourKey(),
                    'category' => $incident->getCategory()->getLabel(),
                    'subcategory' => $incident->getSubcategory()->getLabel(),
                    'status' => $incident->getStatus()->value,
                    'statusLabel' => $incident->getStatus()->label(),
                    'open' => $incident->getStatus()->isOpen(),
                    'severity' => $incident->getSeverity()->value,
                    'zone' => $incident->zoneLabel(),
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
