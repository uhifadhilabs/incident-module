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

namespace Uhifadhi\Incident\Tests\Unit\Shell;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Shell\IncidentModuleTabs;

/**
 * THE MODULE'S DATA PLACES — two, and neither of them configures anything.
 */
final class IncidentModuleTabsTest extends TestCase
{
    public function testItDeclaresItsOwnSlug(): void
    {
        self::assertSame(IncidentModuleProvider::SLUG, new IncidentModuleTabs()->slug());
    }

    public function testItDeclaresTheOverviewAndTheFullList(): void
    {
        $tabs = new IncidentModuleTabs()->tabs();

        self::assertSame(['Overview', 'Incidents'], array_map(static fn (ModuleTab $t): string => $t->label, $tabs));
        self::assertSame(['incident_dashboard', 'incident_list'], array_map(static fn (ModuleTab $t): string => $t->routeName, $tabs));
    }

    /** A list and its case file are one place: opening a case does not leave it. */
    public function testTheListTabStaysLitOnTheCaseFile(): void
    {
        [$overview, $list] = new IncidentModuleTabs()->tabs();

        self::assertTrue($overview->lightsFor('incident_dashboard'));
        self::assertFalse($overview->lightsFor('incident_list'));

        self::assertTrue($list->lightsFor('incident_list'));
        self::assertTrue($list->lightsFor('incident_show'));
        self::assertFalse($list->lightsFor('incident_dashboard'));
    }

    /** The word "register" is nowhere on the surface this module draws. */
    public function testNoTabSaysRegister(): void
    {
        foreach (new IncidentModuleTabs()->tabs() as $tab) {
            self::assertStringNotContainsStringIgnoringCase('register', $tab->label);
            self::assertStringNotContainsStringIgnoringCase('register', $tab->routeName);
        }
    }
}
