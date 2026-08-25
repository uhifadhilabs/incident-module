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

namespace UhifadhiLabs\Incident\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use UhifadhiLabs\Incident\Module\IncidentModuleProvider;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

final class IncidentModuleProviderTest extends TestCase
{
    public function testDeclaresTheIncidentsModule(): void
    {
        $provider = new IncidentModuleProvider('pressure');

        self::assertInstanceOf(ModuleProviderInterface::class, $provider);
        self::assertSame('incidents', $provider->slug());
        self::assertSame('Incidents', $provider->name());
        self::assertSame('pressure', $provider->category());
        self::assertSame('Field incident reports', $provider->dataSource());
        self::assertSame('triangle-alert', $provider->icon());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('biodiversity', new IncidentModuleProvider('biodiversity')->category());
    }

    /**
     * The module has no screens yet — the incidents design has not been ruled
     * on, so there is nothing to link to and the host renders the module
     * through its generic module page. This assertion is the reminder: it
     * changes the day the first incidents route lands.
     */
    public function testRendersThroughTheHostsGenericModulePageUntilItsScreensLand(): void
    {
        self::assertNull(new IncidentModuleProvider('pressure')->entryRoute());
    }

    /**
     * Permissions are declared alongside the routes that check them. There are
     * no routes yet, so declaring a permission here would hand admins something
     * that guards nothing.
     */
    public function testDeclaresNoPermissionsYet(): void
    {
        self::assertSame([], new IncidentModuleProvider('pressure')->permissions());
    }
}
