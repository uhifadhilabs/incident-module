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

namespace Uhifadhi\Incident\Shell;

use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

/**
 * WHERE INCIDENT DATA LIVES — the two places, and the whole of what this module
 * says about its own navigation.
 *
 * A TAB IS A PLACE WHERE DATA LIVES. The dashboard reads the month; the full
 * list is every incident filed in it, uncapped. Nothing that CONFIGURES the
 * module is here — the kinds, the widget library and the module's settings are
 * sections of the one configure page, reached from the one Configure action the
 * shell draws.
 *
 * THE SHELL DRAWS BOTH RENDERINGS — the strip under the page head and the
 * module's children in the sidebar's tree — so the two cannot drift, which is
 * why this module ships no tab partial of its own.
 */
final readonly class IncidentModuleTabs implements ModuleTabsInterface
{
    public function slug(): string
    {
        return IncidentModuleProvider::SLUG;
    }

    public function tabs(): array
    {
        return [
            new ModuleTab('Overview', 'incident_dashboard'),
            /*
             * A LIST AND ITS CASE FILE ARE ONE PLACE. Opening a case does not
             * leave the place cases live in, so the family is named here rather
             * than guessed from a url shape by anybody else.
             */
            new ModuleTab('Incidents', 'incident_list', lightsFor: [
                'incident_list',
                'incident_show',
            ]),
        ];
    }
}
