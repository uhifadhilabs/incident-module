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

namespace Uhifadhi\Incident\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\ModuleContracts\ModulePermission;
use Uhifadhi\ModuleContracts\ModuleProviderInterface;

final class IncidentModuleProviderTest extends TestCase
{
    public function testDeclaresTheIncidentsModule(): void
    {
        $provider = new IncidentModuleProvider('operations');

        self::assertInstanceOf(ModuleProviderInterface::class, $provider);
        self::assertSame('incidents', $provider->slug());
        self::assertSame('Incidents', $provider->name());
        self::assertSame('operations', $provider->category());
        self::assertSame('Field incident reports', $provider->dataSource());
        self::assertSame('triangle-alert', $provider->icon());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new IncidentModuleProvider('biodiversity')->category());
    }

    /**
     * The module owns its screens, so the host's tile links straight to the
     * incidents dashboard rather than to the generic module page.
     */
    public function testTheHostLinksStraightToTheIncidentsDashboard(): void
    {
        self::assertSame('incident_dashboard', new IncidentModuleProvider('operations')->entryRoute());
    }

    /**
     * TWO TIERS, and each value is the exact attribute a route checks: filing an
     * incident, and moving one through its workflow.
     *
     * There is deliberately NO "view" permission — reading incidents is reading
     * the module, and a view permission is exactly the tool somebody would
     * eventually use to hide one department's rows from another, which this
     * module's charter forbids.
     */
    public function testDeclaresTheRecordAndManageTiersAndNothingElse(): void
    {
        $permissions = new IncidentModuleProvider('operations')->permissions();

        self::assertSame(
            ['incidents.record', 'incidents.manage'],
            array_map(static fn (ModulePermission $p) => $p->value, $permissions),
        );
        self::assertSame(['Incidents', 'Incidents'], array_map(static fn (ModulePermission $p) => $p->umbrella, $permissions));
        self::assertSame(['Record', 'Manage'], array_map(static fn (ModulePermission $p) => $p->action, $permissions));
    }
}
