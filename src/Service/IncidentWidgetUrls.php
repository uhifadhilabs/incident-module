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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Widget\Model\WidgetDom;

/**
 * THE WIDGET LIBRARY'S WIRE, as URLs — the map the host's shared preset component
 * is handed, with THIS AREA named in every one of them.
 *
 * Its own service rather than a private method on a controller because BOTH the
 * dashboard and the library hand the map to a template, and a second copy would
 * eventually name one route the other did not.
 *
 * Arranging one area's incidents dashboard can never rearrange another's, and
 * that is stated in the URLs rather than trusted to a check.
 */
final readonly class IncidentWidgetUrls
{
    public function __construct(
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * A template carries {@see WidgetDom::ID_PLACEHOLDER} where a preset's id or
     * uuid goes; the library's script substitutes into it, because a preset card
     * that only exists after a click has no server-rendered href to read.
     *
     * @return array<string, string>
     */
    public function forArea(AreaOfInterest $area): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;
        $uuid = ['uuid' => $area->getUuidString()];
        $url = fn (string $route, array $extra = []): string => $this->router->generate($route, [...$uuid, ...$extra]);

        return [
            'save' => $url('incident_widgets_save'),
            'reset' => $url('incident_widgets_reset'),
            'preset' => $url('incident_widgets_preset', ['presetId' => $id]),
            'copy' => $url('incident_widgets_preset_copy', ['presetId' => $id]),
            'presets' => $url('incident_widgets_preset_create'),
            'apply' => $url('incident_widgets_preset_apply', ['presetUuid' => $id]),
            'rename' => $url('incident_widgets_preset_rename', ['presetUuid' => $id]),
            'delete' => $url('incident_widgets_preset_delete', ['presetUuid' => $id]),
            'dashboard' => $url('incident_dashboard'),
        ];
    }
}
