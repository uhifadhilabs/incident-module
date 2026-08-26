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

namespace UhifadhiLabs\Incident;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;
use UhifadhiLabs\Incident\Command\SeedDemoCommand;
use UhifadhiLabs\Incident\Command\SyncTaxonomyCommand;
use UhifadhiLabs\Incident\Controller\IncidentDetailController;
use UhifadhiLabs\Incident\Controller\IncidentReportController;
use UhifadhiLabs\Incident\Controller\IncidentWidgetsController;
use UhifadhiLabs\Incident\DependencyInjection\IncidentConfiguration;
use UhifadhiLabs\Incident\Module\IncidentDepartmentKpiProvider;
use UhifadhiLabs\Incident\Module\IncidentModuleProvider;
use UhifadhiLabs\Incident\Repository\IncidentCategoryRepository;
use UhifadhiLabs\Incident\Repository\IncidentRepository;
use UhifadhiLabs\Incident\Repository\IncidentSubcategoryRepository;
use UhifadhiLabs\Incident\Service\IncidentTransitionToken;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Incidents — poaching, human–wildlife conflict (with the fines and compensation
 * that follow), compliance and encroachment, and wildlife mortality: ONE record
 * type, read by Protection and Ecology alike through subsets of one taxonomy.
 *
 * Zero-config: registering the bundle maps its own entities (no host doctrine
 * block needed), registers the dashboard and reaches the host's module catalogue
 * and department-KPI seam. Spatial columns ride on fundistadi/postgis-bundle.
 *
 * ONE HOST STEP IS NOT AUTOMATIC, and cannot honestly be: run
 * `incidents:taxonomy:sync` once, so the deployment has kinds of incident to file
 * against. It is a data decision, and a bundle that wrote rows into a host's
 * database on boot would be making it for them.
 */
final class UhifadhiLabsIncidentBundle extends AbstractBundle
{
    /** Config lives under "incident:", not the class-derived "uhifadhi_labs_incident:". */
    protected string $extensionAlias = 'incident';

    public function configure(DefinitionConfigurator $definition): void
    {
        IncidentConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhilabsincident` and content-versioned — no
        // config here, no assets:install. That is where incidents.css is served
        // from; see templates/base.html.twig.

        // Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
        // namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
        // The recipe enables them in the host's assets/controllers.json.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => '@uhifadhilabs/incident-module',
                    ],
                ],
            ]);
        }

        // Zero-config persistence: the bundle maps its own entities, so hosts
        // never write a doctrine mappings block for incident_* tables.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiLabsIncident' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'UhifadhiLabs\\Incident\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php for
        // the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the host's
        // catalogue seed + module grid. The host tags every ModuleProviderInterface
        // via registerForAutoconfiguration, but that only fires for autoconfigured
        // services — and a reusable bundle doesn't autoconfigure — so the tag is
        // applied explicitly here.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'pressure';
        $services->set('incident.module_provider', IncidentModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module');

        // The deployment's own vocabulary and money unit.
        $taxonomy = $config['taxonomy'] ?? [];
        $builder->setParameter('incident.taxonomy', \is_array($taxonomy) ? $taxonomy : []);
        $currency = \is_string($config['currency'] ?? null) ? $config['currency'] : 'TZS';
        $builder->setParameter('incident.currency', $currency);

        /*
         * THE WRITING SCREENS AND THE WIDGET LIBRARY are registered ONLY inside
         * this guard.
         *
         * The report flow and the transition endpoint are the only routes that
         * CREATE or MOVE incidents, so they must never exist unprotected: without
         * symfony/security there is no authorization checker to enforce
         * "incidents.record" / "incidents.manage", and a host in that state gets no
         * writing controller at all (the routes fail loudly) rather than an open
         * write endpoint. The widget library edits ONE PERSON's layout and needs a
         * signed-in user for the same reason.
         *
         * The guard asks whether SecurityBundle is actually in the kernel, read
         * from the kernel.bundles parameter. Two other checks look right and are
         * not: hasExtension('security') cannot be used here, because while an
         * extension is loading the builder is a restricted
         * MergeExtensionConfigurationContainerBuilder that does not expose other
         * extensions; and interface_exists() only proves a class is autoloadable —
         * security-core is one of this bundle's DEV dependencies, so it autoloads
         * in our own test runs even when SecurityBundle is absent, and services
         * would then reference security.* ids that do not exist. FrameworkExtension
         * reads kernel.bundles for exactly this reason.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);
        // The dashboard offers "Report incident" and the workflow buttons only
        // where those routes exist, so a host without security shows no link into
        // nowhere.
        $builder->setParameter('incident.record_screens', $hasSecurity);
        $builder->setParameter('incident.widget_screens', $hasSecurity);

        if ($hasSecurity) {
            // The one token both the case file and the status board post with.
            // Registered under the security guard because without SecurityBundle
            // there is nobody to grant the permission it checks.
            $services->set('incident.transition_token', IncidentTransitionToken::class)
                ->args([
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                ]);

            $services->set('incident.controller.widgets', IncidentWidgetsController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('incident.dashboard'),
                    service(IncidentCategoryRepository::class),
                    service(WidgetService::class),
                    service('incident.widget_urls'),
                    service('incident.transition_token'),
                    // The host's own endpoint service answers every widget write:
                    // this module validates no token and chooses no status code.
                    service(WidgetEndpoint::class),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentWidgetsController::class, 'incident.controller.widgets')->public();

            $services->set('incident.controller.detail', IncidentDetailController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('doctrine.orm.entity_manager'),
                    service(IncidentRepository::class),
                    service('incident.dashboard'),
                    service('incident.transitions'),
                    service('security.authorization_checker'),
                    // FrameworkBundle defines this id whenever symfony/security-csrf
                    // is installed, which a host running SecurityBundle already has.
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentDetailController::class, 'incident.controller.detail')->public();

            $services->set('incident.controller.report', IncidentReportController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('incident.report'),
                    service(IncidentCategoryRepository::class),
                    service(IncidentSubcategoryRepository::class),
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->public();
            $services->alias(IncidentReportController::class, 'incident.controller.report')->public();
        }

        // THE TAXONOMY COMMAND IS NOT DEV TOOLING. Without a taxonomy there is
        // nothing to file an incident against, so a production host runs it once
        // on install — see the class docblock.
        $services->set('incident.command.sync_taxonomy', SyncTaxonomyCommand::class)
            ->args([service('incident.taxonomy_installer')])
            ->tag('console.command');

        // Dev tooling: the demo seeder exists only where incident.dev_tools is on
        // (the recipe enables it via when@dev/when@test), so production never gets
        // a command that writes invented incidents.
        if (true === ($config['dev_tools'] ?? false)) {
            $services->set('incident.command.seed_demo', SeedDemoCommand::class)
                ->args([
                    service('doctrine.orm.entity_manager'),
                    service(IncidentRepository::class),
                    service(IncidentSubcategoryRepository::class),
                    service('incident.taxonomy_installer'),
                    service('incident.transitions'),
                    service('incident.zone_locator'),
                    param('incident.currency'),
                ])
                ->tag('console.command');
        }

        /*
         * The department KPI seam. Tagged EXPLICITLY, exactly like
         * 'uhifadhi.module' above and for the same reason: a reusable bundle is not
         * autoconfigured, so the host's autoconfiguration never fires for it.
         *
         * The slug and name are the scalars IncidentModuleProvider::slug()/name()
         * return and they must MATCH, because the host only asks this provider for
         * figures when a department attaches the module of that slug, and captions
         * the plates with that name.
         */
        $services->set('incident.department_kpi_provider', IncidentDepartmentKpiProvider::class)
            ->args([
                service(IncidentRepository::class),
                'incidents',
                'Incidents',
                $currency,
            ])
            ->tag('uhifadhi.department_kpi');
    }
}
