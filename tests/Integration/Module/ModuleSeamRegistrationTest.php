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

namespace UhifadhiLabs\Incident\Tests\Integration\Module;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UhifadhiLabs\Incident\Module\IncidentModuleProvider;
use UhifadhiLabs\Incident\Tests\Integration\Fixtures\CollectedKpiProviders;
use UhifadhiLabs\Incident\Tests\Integration\Fixtures\CollectedModules;
use UhifadhiLabs\ModuleContracts\ModulePermission;

/**
 * The host contract: installing this bundle puts "incidents" in the catalogue.
 * A reusable bundle is not autoconfigured, so the "uhifadhi.module" tag is
 * applied by hand in the extension — this test is what proves it stuck.
 */
final class ModuleSeamRegistrationTest extends KernelTestCase
{
    public function testTheIncidentsModuleReachesTheHostsCatalogueSeam(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);
        $modules = $catalogue->bySlug();

        self::assertArrayHasKey('incidents', $modules);
        self::assertInstanceOf(IncidentModuleProvider::class, $modules['incidents']);
        self::assertSame('Incidents', $modules['incidents']->name());
        self::assertSame('triangle-alert', $modules['incidents']->icon());
        // It owns its screens, so the host's tile links straight to them.
        self::assertSame('incident_dashboard', $modules['incidents']->entryRoute());
    }

    /**
     * The permissions an admin can assign. Declared by the module, granted by
     * nobody here — and they vanish with the module on uninstall.
     */
    public function testItHandsTheHostItsTwoPermissionTiers(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);

        self::assertSame(
            ['incidents.record', 'incidents.manage'],
            array_map(
                static fn (ModulePermission $permission) => $permission->value,
                $catalogue->bySlug()['incidents']->permissions(),
            ),
        );
    }

    /**
     * THE DEPARTMENT KPI SEAM. The tag is applied BY HAND in the extension (a
     * reusable bundle is not autoconfigured), and a provider that failed to
     * register would show up only as every incidents plate quietly vanishing from
     * every performance page. This test is what makes that loud.
     */
    public function testItReachesTheHostsDepartmentPerformanceSeam(): void
    {
        self::bootKernel();

        /** @var CollectedKpiProviders $providers */
        $providers = self::getContainer()->get(CollectedKpiProviders::class);

        self::assertArrayHasKey('incidents', $providers->bySlug());
    }

    /**
     * The category a deployment configures is the category the host files the
     * tile under — the config value has to reach the provider, not just the
     * container.
     */
    public function testTheConfiguredCategoryReachesTheProvider(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);

        self::assertSame('pressure', $catalogue->bySlug()['incidents']->category());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
