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

namespace Uhifadhi\Incident\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use FundiStadi\PostGISBundle\FundiStadiPostGISBundle;
use League\FlysystemBundle\FlysystemBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Tests\Fixtures\Account\User;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedKpiProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;
use Uhifadhi\Incident\Tests\Integration\Fixtures\HeaderUserAuthenticator;
use Uhifadhi\Incident\Tests\Integration\Fixtures\StubRecordFileSource;
use Uhifadhi\Incident\UhifadhiIncidentBundle;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Repository\WidgetCustomPresetRepository;
use Uhifadhi\Repository\WidgetPreferenceRepository;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\UhifadhiStorageBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * THE SMALLEST HOST THAT IS STILL A REAL ONE: framework + twig + doctrine + the
 * PostGIS bundle + security + incidents, talking to a REAL PostGIS database
 * (INCIDENTS_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * It also plays the parts of uhifadhi that this bundle BINDS TO, because a module
 * that is only tested against mocks of its host is a module nobody has proved
 * installs:
 *
 *  - the host ENTITIES (AreaOfInterest, Zone, Position, Department) are mapped
 *    from tests/Fixtures/Uhifadhi/Entity, and the PERSON is not among them: the
 *    module points at one through a published contract, so this kernel supplies
 *    an account class of its own and resolves the contract to it;
 *  - the host's WIDGET FRAMEWORK (WidgetService, WidgetEndpoint and their two
 *    entities) is registered here exactly as the host registers it, so the
 *    incidents dashboard and library are exercised on the REAL framework rather
 *    than on a copy of its algebra;
 *  - the host's TEMPLATES (layout.html.twig and widgets/_library.html.twig and
 *    friends) are on the Twig path from tests/Integration/Fixtures/templates.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        // The host renders every icon through symfony/ux-icons (lucide:*), so the
        // module's templates do too — and the test host has to provide it.
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        yield new FundiStadiPostGISBundle();
        yield new SecurityBundle();
        // The platform's Files hub. OPTIONAL for this module — the source is
        // registered only where this bundle is in the kernel — and registered
        // here in the order a host registers it: flysystem first, because the
        // storage bundle PREPENDS a flysystem storage.
        yield new FlysystemBundle();
        yield new UhifadhiStorageBundle();
        yield new UhifadhiIncidentBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // loginUser() needs a stateful firewall and flashes need a session;
            // the mock file storage is the documented test-env choice.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // Every widget write and every incident transition carries a CSRF
            // token, so the token manager must exist here as it does in a real
            // host (FrameworkBundle only defines it when csrf_protection is on).
            'csrf_protection' => ['enabled' => true],
        ]);

        // A minimal but REAL security setup: loginUser() needs a stateful
        // firewall, and permission checks must go through the real
        // AuthorizationChecker rather than a stub that always says yes.
        $container->extension('security', [
            'providers' => [
                'app_users' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'app_users',
                    'custom_authenticators' => [HeaderUserAuthenticator::class],
                ],
            ],
        ]);

        $container->services()->set(HeaderUserAuthenticator::class)
            ->args([service('doctrine.orm.entity_manager')]);

        // The HOST's permission voter, played by a fixture: this bundle declares
        // "incidents.record" and "incidents.manage" and grants them to nobody, so
        // something has to decide who holds them. Tagged by hand — a
        // reusable-bundle test kernel does not autoconfigure.
        $container->services()->set(FixedPermissionVoter::class)->tag('security.voter');

        // ANOTHER MODULE, holding the photographs of a record this bundle knows
        // nothing about — the far side of the cross-module file seam the report
        // flow's source card draws through. Tagged by hand: a reusable-bundle
        // test kernel does not autoconfigure.
        $container->services()->set(StubRecordFileSource::class)
            ->tag(FileSourceInterface::TAG);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(INCIDENTS_TEST_DATABASE_URL)%'],
            'orm' => [
                // The host's own choice (config/packages/doctrine.yaml), mirrored
                // here so the bundle's metadata-driven SQL is exercised against
                // the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                'mappings' => [
                    // The dev-only Uhifadhi\Entity stubs (area, zone, position,
                    // department), so the Incident relations resolve standalone.
                    // They stand in for host things this module has no published
                    // contract for yet; the PERSON is not among them any more —
                    // see the account mapping and the resolution below.
                    'UhifadhiHostStubs' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/Fixtures/Uhifadhi/Entity',
                        'prefix' => 'Uhifadhi\\Entity',
                        'is_bundle' => false,
                    ],
                    // The test installation's own account class, mapped the way
                    // an installation maps the module that provides its team.
                    'TestInstallationAccount' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/Fixtures/Account',
                        'prefix' => 'Uhifadhi\\Incident\\Tests\\Fixtures\\Account',
                        'is_bundle' => false,
                    ],
                ],
                // THE ONE LINE AN INSTALLATION WRITES. Every person on an
                // incident is declared against the contract, so without this the
                // bundle cannot build a schema at all — and with it, the foreign
                // keys point at whatever account table the installation has.
                'resolve_target_entities' => [
                    UserInterface::class => User::class,
                ],
            ],
        ]);

        $container->extension('twig', [
            'paths' => [__DIR__.'/Fixtures/templates'],
        ]);

        // A real host vendors its icon set (bin/console ux:icons:import). These
        // tests are about the module's markup, not about which glyph an icon
        // resolves to, so a missing one renders as nothing rather than failing
        // the page — and the assertions never depend on an icon being there.
        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        /*
         * THE HOST'S WIDGET FRAMEWORK, registered the way the host registers it.
         * The incidents surface rides this rather than shipping a copy, so the
         * tests have to exercise the real thing — a library that only worked
         * against a stand-in would be a library nobody has proved works.
         */
        $services = $container->services();
        $services->set(WidgetPreferenceRepository::class)
            ->args([service('doctrine')])->tag('doctrine.repository_service');
        $services->set(WidgetCustomPresetRepository::class)
            ->args([service('doctrine')])->tag('doctrine.repository_service');
        $services->set(WidgetService::class)->args([
            service(WidgetPreferenceRepository::class),
            service(WidgetCustomPresetRepository::class),
            service('doctrine.orm.entity_manager'),
        ]);
        $services->set(WidgetEndpoint::class)->args([
            service(WidgetService::class),
            service('security.token_storage'),
            service('security.csrf.token_manager'),
        ]);

        // Stands in for the HOST's module catalogue and its department-KPI
        // service: both collect tagged services, and tagged services are private,
        // so these collectors are what make the bundle's contributions observable.
        $services->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])->public();
        $services->set(CollectedKpiProviders::class)
            ->args([tagged_iterator('uhifadhi.department_kpi')])->public();

        // Public aliases so tests can reach the bundle's private services, keyed
        // by service id for readability (see IntegrationTestCase::service()).
        foreach ([
            'incident.taxonomy_installer',
            'incident.report',
            'incident.dashboard',
            'incident.overview.figures',
            // The five area-overview providers. Tagged by hand in the extension
            // (a reusable bundle does not autoconfigure), and public here so the
            // seam test can ask each one what it contributes.
            'incident.overview.contributor',
            'incident.overview.now_tiles',
            'incident.overview.attention',
            'incident.overview.map_layers',
            'incident.overview.pulse',
            'incident.overview.copy',
            'incident.transitions',
            'incident.zone_locator',
            // The storage seam: the source itself, and the registry the hub
            // reads it through.
            'incident.file_source',
            'storage.file_registry',
        ] as $id) {
            $services->alias('test_public.'.$id, $id)->public();
        }

        // The repositories are registered under their CLASS names, so their test
        // aliases are keyed by hand.
        $services->alias('test_public.incident.repository', IncidentRepository::class)->public();

        // A throwaway evidence store. Incidents writes no bytes through it yet
        // (see IncidentFileSource); it exists so the bundle boots as it does in
        // a host, and so the hub's registry has a real storage behind it.
        $container->extension('storage', [
            'evidence' => [
                'adapter' => 'local',
                'directory' => sys_get_temp_dir().'/incident-module-tests/evidence',
            ],
        ]);

        $container->extension('incident', [
            'dev_tools' => true, // this IS the test env — the recipe enables it via when@test
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $controllers = \dirname(__DIR__, 2).'/src/Controller/';
        if (is_dir($controllers)) {
            $routes->import($controllers, 'attribute');
        }

        // THE EVIDENCE ROUTE, from the bundle that owns it. A real host importing
        // uhifadhi/storage-module gets it; the report flow's source card
        // serves the source record's photographs through it, so a kernel without
        // it would prove the card works only in a deployment that cannot show one.
        $evidence = \dirname(__DIR__, 2).'/vendor/uhifadhi/storage-module/src/Controller/EvidenceController.php';
        if (is_file($evidence)) {
            $routes->import($evidence, 'attribute');
        }

        // The host routes the bundle's crumbs and back-links generate URLs for —
        // stubbed here (URL generation needs only the definition).
        $routes->add('dashboard_index', '/_host/dashboard');
        $routes->add('dashboard_area_show', '/_host/areas/{uuid}');
        $routes->add('dashboard_area_modules_grid', '/_host/areas/{uuid}/modules');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/incident-module-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/incident-module-tests/log';
    }
}
