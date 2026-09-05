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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Area\UhifadhiAreaBundle;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedKpiProviders;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;
use Uhifadhi\Incident\Tests\Integration\Fixtures\HeaderUserAuthenticator;
use Uhifadhi\Incident\Tests\Integration\Fixtures\StubRecordFileSource;
use Uhifadhi\Incident\UhifadhiIncidentBundle;
use Uhifadhi\Seam\UhifadhiSeamBundle;
use Uhifadhi\Shell\UhifadhiShellBundle;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\UhifadhiStorageBundle;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\UhifadhiTeamBundle;
use Uhifadhi\Widget\UhifadhiWidgetBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The smallest INSTALLATION this bundle can live in, and every part of it is
 * real: framework + twig + doctrine + PostGIS + security, the shell every
 * incident screen renders through, the widget framework the dashboard IS, the
 * area an incident happens in, the seam that switches this module on there, the
 * team the account class and the org chart come from, and the storage the Files
 * hub reads — against a REAL PostGIS database (INCIDENTS_TEST_DATABASE_URL, see
 * phpunit.dist.xml).
 *
 * NOTHING HERE IS A COPY ANY MORE, and that is the change. This kernel used to
 * assemble a stand-in: a `layout.html.twig` typed into a fixture directory, a
 * hand-wired WidgetService pointing at classes copied under tests/Fixtures, an
 * account class of this suite's own, and three route definitions standing in for
 * an application's pages. A copy cannot hold a contract — it pins whatever the
 * copyist believed — so each of them is now the published bundle it was
 * imitating.
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
        // A real installation renders every icon through symfony/ux-icons
        // (lucide:*), so the module's templates do too.
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        yield new FundiStadiPostGISBundle();
        yield new SecurityBundle();
        // The frame every incident screen renders in.
        yield new UhifadhiShellBundle();
        // Hard-required: the dashboard is a widget surface, not a page with
        // widgets on it.
        yield new UhifadhiWidgetBundle();
        // The place an incident happens in, the zones its map reads, and the six
        // seams this module contributes to an area's overview.
        yield new UhifadhiAreaBundle();
        // The per-area catalogue this module registers itself in.
        yield new UhifadhiSeamBundle();
        // For the account class every incident, event and stored layout is keyed
        // by — and for the org chart the department figures walk.
        yield new UhifadhiTeamBundle();
        // The platform's Files hub. OPTIONAL for this module — the source is
        // registered only where the storage bundle is in the kernel — and
        // registered here in the order an installation registers it: flysystem
        // first, because the storage bundle PREPENDS a flysystem storage.
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

        // The INSTALLATION's permission voter, played by a fixture: this bundle
        // declares "incidents.record" and "incidents.manage" and grants them to
        // nobody, so something has to decide who holds them. Tagged by hand — a
        // reusable-bundle test kernel does not autoconfigure.
        $container->services()->set(FixedPermissionVoter::class)->tag('security.voter');

        // ANOTHER MODULE, holding the photographs of a record this bundle knows
        // nothing about — the far side of the cross-module file seam the report
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
                // uhifadhi/area-module, the person and the org chart from
                // uhifadhi/team-module — and each maps its own; team prepends the
                // contract's resolution from its own bundle, which is the one
                // line an installation used to have to write. If either ever
                // stopped happening the schema would not build and this whole
                // suite would say so at once.
            ],
        ]);

        // A real installation vendors its icon set (bin/console ux:icons:import).
        // These tests are about the module's markup, not about which glyph an
        // icon resolves to, so a missing one renders as nothing rather than
        // failing the page — and the assertions never depend on an icon.
        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        $services = $container->services();

        // Stands in for the SEAM's module catalogue collector and the area
        // module's department-KPI service: both collect tagged services, and
        // tagged services are private, so these collectors are what make the
        // bundle's contributions observable.
        $services->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])->public();
        $services->set(CollectedKpiProviders::class)
            ->args([tagged_iterator('uhifadhi.department_kpi')])->public();

        // Public aliases so tests can reach private services, keyed by service id
        // for readability (see IntegrationTestCase::service()).
        foreach ([
            'incident.taxonomy_installer',
            'incident.report',
            'incident.dashboard',
            'incident.overview.figures',
            // The six area-overview providers. Tagged by hand in the extension
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

        // Services registered under their CLASS names get their test aliases
        // keyed by hand — this module's repository, and the widget framework by
        // the ids uhifadhi/widget-module publishes plus the registry a surface
        // has to be findable in.
        foreach ([
            'incident.repository' => IncidentRepository::class,
            'widget.service' => \Uhifadhi\Widget\Service\WidgetService::class,
            'widget.endpoint' => \Uhifadhi\Widget\Service\WidgetEndpoint::class,
            'widget.surfaces' => \Uhifadhi\Widget\Registry\WidgetSurfaceRegistry::class,
        ] as $alias => $id) {
            $services->alias('test_public.'.$alias, $id)->public();
        }

        // A throwaway evidence store. Incidents writes no bytes through it yet
        // (see IncidentFileSource); it exists so the bundle boots as it does in
        // an installation, and so the hub's registry has a real storage behind
        // it.
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

        // THE EVIDENCE ROUTE, from the bundle that owns it. A real installation
        // importing uhifadhi/storage-module gets it; the report flow's source
        // card serves the source record's photographs through it, so a kernel
        // without it would prove the card works only where nobody can see one.
        $evidence = (new \ReflectionClass(\Uhifadhi\Storage\Controller\EvidenceController::class))->getFileName();
        if (\is_string($evidence)) {
            $routes->import($evidence, 'attribute');
        }

        // THE SCREENS THIS MODULE'S CRUMB POINTS AT, mounted from the bundles
        // that own them rather than declared as bare paths here. The area
        // register and the area page are uhifadhi/area-module's; the front door
        // is the shell's. `seam_area_modules` is deliberately absent — the
        // per-area module grid is the seam's page and the seam does not ship one
        // yet, which is exactly the case incident_url() answers null for and the
        // crumb prints as plain text.
        $routes->import('@UhifadhiShellBundle/src/Controller/', 'attribute');
        $routes->import('@UhifadhiAreaBundle/src/Controller/', 'attribute');
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
