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
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use FundiStadi\PostGISBundle\FundiStadiPostGISBundle;
use League\FlysystemBundle\FlysystemBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceRegistry;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedContentProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedKpiProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;
use Uhifadhi\Incident\Tests\Integration\Fixtures\HeaderUserAuthenticator;
use Uhifadhi\Incident\Tests\Integration\Fixtures\StubRecordFileSource;
use Uhifadhi\Incident\UhifadhiIncidentBundle;
use Uhifadhi\Storage\Controller\EvidenceController;
use Uhifadhi\Storage\Controller\UploadController;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\UhifadhiStorageBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The smallest INSTALLATION this bundle can live in, and every part of it is
 * real: framework + twig + doctrine + PostGIS + security, and five of the core's
 * bundles — the registry this module registers itself in, the shell every screen
 * renders through and whose widget machinery the dashboard IS, the atlas whose
 * Leaflet build and map sheet the base template links, the area an incident
 * happens in, and the team the account class and the org chart come from —
 * beside the storage the Files hub reads, against a REAL PostGIS database
 * (INCIDENTS_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * NOTHING HERE IS A COPY, and that is the point. Every entity, frame and
 * contribution point this module talks to is the published one: a copy cannot
 * hold a contract, because it pins whatever the copyist believed on the day it
 * was made.
 *
 * TEAM AND AREA ARE BOOTED FOR THEIR MODELS, NOT FOR THEIR DASHBOARDS.
 * {@see OnlyThisModulesSurfacesPass} takes the widget-surface tag off everything
 * outside this module's namespace, so what the registry holds is this module's
 * business and not a dependency's release notes.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        // Every icon is drawn by name through symfony/ux-icons, under a prefix
        // its package answers for — `incident:` here, `shell:` in the core.
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        // The history every package here ships, run the way an installation runs
        // it. Nothing else in this suite uses it — the rest builds its tables
        // with SchemaTool — but the locks under Integration/Migrations are about
        // the shipped versions, and those need the bundle that finds them.
        yield new DoctrineMigrationsBundle();
        yield new FundiStadiPostGISBundle();
        yield new SecurityBundle();
        // The per-area catalogue this module registers itself in, and the gate
        // that closes a parked module's pages. TeamBundle requires it too.
        yield new RegistryBundle();
        // The frame every incident screen renders in, and the widget machinery
        // the dashboard IS — a widget surface, not a page with widgets on it.
        yield new ShellBundle();
        // UX Map and its Leaflet bridge: the atlas's plate is built on them, so
        // an installation that draws a map registers both — and every incident
        // screen with a map renders through the configured renderer.
        yield new UXMapBundle();
        // The maps: this module's base template links the atlas's map sheet by
        // the constant the bundle publishes, and an installation that draws an
        // incident's position has it.
        yield new AtlasBundle();
        // The place an incident happens in, the zones its map reads, and the six
        // contribution points this module fills on an area's overview.
        yield new AreaBundle();
        // For the account class every incident, event and stored layout is keyed
        // by — and for the org chart the department figures walk.
        yield new TeamBundle();
        // The platform's Files hub, where an incident's photographs are stored
        // and listed. A hard requirement of this module, registered here in the
        // order an installation registers it: flysystem first, because the
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
            // installation (FrameworkBundle only defines it when csrf_protection
            // is on).
            'csrf_protection' => ['enabled' => true],
            // asset() has to exist: the shell's document and this module's base
            // template both link stylesheets with it. AssetMapper takes over path
            // resolution here, exactly as in a real installation.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        // A minimal but REAL security setup: loginUser() needs a stateful
        // firewall, and permission checks must go through the real
        // AuthorizationChecker rather than a stub that always says yes. The
        // people are TEAM's own entity rather than InMemoryUser, because an
        // incident and a stored layout both carry a foreign key to a person and
        // an in-memory one has no row to point at.
        // THE INSTALLATION'S SECURITY FILE, minus the screens this kernel does
        // not mount. The hashers, the entity provider over the account TeamBundle
        // owns and the role hierarchy are the ones the core's own throwaway
        // application configures, and the firewall takes the user checker that
        // refuses a deactivated account. No form_login and no access_control:
        // TeamBundle's sign-in screens are not mounted here, and the suite signs
        // people in through loginUser() and a test header instead.
        //
        // The people are TeamBundle's own entity rather than InMemoryUser,
        // because an incident and a stored layout both carry a foreign key to a
        // person and an in-memory one has no row to point at.
        $container->extension('security', [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => [
                    // Test-only cost floor, the documented Symfony practice.
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                    'custom_authenticators' => [HeaderUserAuthenticator::class],
                ],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
        ]);

        $container->services()->set(HeaderUserAuthenticator::class)
            ->args([service('doctrine.orm.entity_manager')]);

        // The INSTALLATION's permission voter, played by a fixture: this bundle
        // declares "incidents.record" and "incidents.manage" and grants them to
        // nobody, so something has to decide who holds them. Tagged by hand — a
        // reusable-bundle test kernel does not autoconfigure.
        $container->services()->set(FixedPermissionVoter::class)->tag('security.voter');

        // ANOTHER MODULE, holding the photographs of a record this bundle knows
        // nothing about — the far side of the cross-module file contract the report
        // flow's source card draws through. Tagged by hand, for the same reason.
        $container->services()->set(StubRecordFileSource::class)
            ->tag(FileSourceInterface::TAG);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(INCIDENTS_TEST_DATABASE_URL)%'],
            'orm' => [
                // The skeleton's own choice (config/packages/doctrine.yaml),
                // mirrored here so the bundle's metadata-driven SQL is exercised
                // against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO 'mappings' AND NO 'resolve_target_entities' HERE, both
                // deliberately. Every entity this module points at now arrives
                // with the module that owns it — the area and its zones from
                // AreaBundle, the person and the org chart from
                // TeamBundle — and each maps its own; team prepends the
                // contract's resolution from its own bundle, which is the one
                // line an installation used to have to write. If either ever
                // stopped happening the schema would not build and this whole
                // suite would say so at once.
            ],
        ]);

        /*
         * THE ICONS ARE LOCAL, AND A MISSING ONE IS A FAILURE. `icon_dir` is the
         * application's own directory, which every installation has and which
         * answers BARE names only; the prefixed names this module draws are
         * answered by the sets their packages register — `incident:` from this
         * bundle's assets/icons/incident, `shell:` from ShellBundle's.
         *
         * On-demand fetching is OFF, which is what a deployment configures: with
         * it on, a name no file answers to is fetched from a remote API and
         * cached, so a missing glyph stays invisible until the deployment with no
         * outbound network draws a blank square.
         *
         * @see https://symfony.com/bundles/ux-icons/current/index.html#icons-on-demand
         */
        // Which renderer draws the maps. UX Map draws nothing at all until one
        // is named, and an installation names it in config/packages/ux_map.yaml.
        $container->extension('ux_map', ['renderer' => 'leaflet://default']);

        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'iconify' => ['on_demand' => false],
        ]);

        $services = $container->services();

        // Stands in for the REGISTRY's module catalogue collector and the area
        // module's department-KPI service: both collect tagged services, and
        // tagged services are private, so these collectors are what make the
        // bundle's contributions observable.
        $services->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])->public();
        $services->set(CollectedKpiProviders::class)
            ->args([tagged_iterator('uhifadhi.department_kpi')])->public();

        // And for DEVKIT's content collector, which the migrations upgrade lock
        // seeds through: this module's demo month depends on team's people, and
        // the tag is where that dependency is actually satisfied.
        $services->set(CollectedContentProviders::class)
            ->args([tagged_iterator('uhifadhi.devkit.content_provider')])->public();
        $services->alias('test_public.devkit.content_providers', CollectedContentProviders::class)->public();

        // The migrations bundle's dependency factory, which is private — the
        // locks read the configured paths off it and run the plans through it.
        $services->alias('test_public.doctrine.migrations.dependency_factory', 'doctrine.migrations.dependency_factory')
            ->public();

        // Public aliases so tests can reach private services, keyed by service id
        // for readability (see IntegrationTestCase::service()).
        foreach ([
            'incident.taxonomy_installer',
            // The area-scoped taxonomy admin's logic, reached directly by its
            // integration test.
            'incident.taxonomy_admin',
            'incident.report',
            'incident.money',
            'incident.dashboard',
            'incident.overview.figures',
            // The six area-overview providers. Tagged by hand in the extension
            // (a reusable bundle does not autoconfigure), and public here so the
            // contribution test can ask each one what it contributes.
            'incident.overview.contributor',
            'incident.overview.now_tiles',
            'incident.overview.attention',
            'incident.overview.map_layers',
            'incident.overview.pulse',
            'incident.overview.copy',
            'incident.transitions',
            // The case file's write surface — the durable half of a move.
            'incident.case',
            // The inert demo-content declaration devkit collects in a dev
            // install. Nothing in this suite is devkit, so the provider is
            // reached directly and asked to do the one thing it does.
            'incident.devkit.content',
            'incident.zone_locator',
            // The registry's own two: the reconciliation that puts this module
            // in the catalogue, and the service an admin's Customize page calls
            // to switch it on for one area. A suite that renders a module page
            // needs both, because the gate reads what they wrote.
            'registry.sync',
            'registry.area_modules',
            // The storage contract: the source itself, and the registry the hub
            // reads it through.
            'incident.file_source',
            'incident.evidence',
            'storage.file_registry',
            'storage.evidence_storage',
        ] as $id) {
            $services->alias('test_public.'.$id, $id)->public();
        }

        // Services registered under their CLASS names get their test aliases
        // keyed by hand — this module's repository, and the widget machinery by
        // the ids ShellBundle publishes plus the registry a surface has to be
        // findable in.
        foreach ([
            'incident.repository' => IncidentRepository::class,
            'shell.widget.service' => WidgetService::class,
            'shell.widget.endpoint' => WidgetEndpoint::class,
            'shell.widget.surfaces' => WidgetSurfaceRegistry::class,
        ] as $alias => $id) {
            $services->alias('test_public.'.$alias, $id)->public();
        }

        // A throwaway evidence store, under the system temp dir. The demo seeder
        // writes real bytes through it — photographs through EvidenceStorage and
        // signed documents straight to the storage — so the Files-hub assertions
        // read a genuine size and preview back. It also lets the bundle boot as it
        // does in an installation, with a real storage behind the hub's registry.
        $container->extension('storage', [
            'evidence' => [
                'adapter' => 'local',
                'directory' => sys_get_temp_dir().'/incident-module-tests/evidence',
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $controllers = \dirname(__DIR__, 2).'/src/Controller/';
        if (is_dir($controllers)) {
            $routes->import($controllers, 'attribute');
        }

        // THE EVIDENCE ROUTE, from the bundle that owns it. A real installation
        // importing uhifadhi/storage-module gets it; the report flow's source
        // card serves the source record's photographs through it, so a kernel
        // without it would prove the card works only where nobody can see one.
        $evidence = (new \ReflectionClass(EvidenceController::class))->getFileName();
        if (\is_string($evidence)) {
            $routes->import($evidence, 'attribute');
        }

        // THE UPLOAD ENDPOINT, from the same bundle and for the same reason: the
        // case file's evidence card posts to it, and a kernel without it would
        // prove the card renders and never that a file can reach a case.
        $upload = (new \ReflectionClass(UploadController::class))->getFileName();
        if (\is_string($upload)) {
            $routes->import($upload, 'attribute');
        }

        // THE SCREENS THIS MODULE'S CRUMB POINTS AT, mounted from the bundles
        // that own them rather than declared as bare paths here. The area
        // register, the area page and the per-area module grid are AreaBundle's;
        // the front door is the shell's, shipped as a RESOURCE the shell never
        // loads and an application imports.
        $routes->import(ShellBundle::ROUTES);
        $routes->import('@AreaBundle/Controller/', 'attribute');

        // THE CONFIGURE PAGE, mounted the way an installation mounts it — a
        // second resource the shell ships and an application asks for in one
        // line. This module declares its configure sections through the
        // contract, and without the page behind them a declared section has no
        // address and the strip drops it.
        $routes->import(ShellBundle::CONFIGURE_ROUTES);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Team and area are booted for their models, not for their dashboards.
        $container->addCompilerPass(new OnlyThisModulesSurfacesPass());
    }

    /**
     * THE STAND-IN INSTALLATION'S PROJECT DIRECTORY — an application's asset side
     * and nothing else. The shell's document renders the importmap of whatever
     * application it is installed in, so a suite that renders any page through
     * the page frame needs an application that has one. Pointing the kernel at a
     * fixture is how it gets one without this bundle growing an importmap of its
     * own, which a shipped bundle has no business carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
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
