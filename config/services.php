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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Service\WidgetService;
use UhifadhiLabs\Incident\Controller\IncidentController;
use UhifadhiLabs\Incident\Repository\IncidentCategoryRepository;
use UhifadhiLabs\Incident\Repository\IncidentEventRepository;
use UhifadhiLabs\Incident\Repository\IncidentEvidenceRepository;
use UhifadhiLabs\Incident\Repository\IncidentLinkRepository;
use UhifadhiLabs\Incident\Repository\IncidentMoneyRepository;
use UhifadhiLabs\Incident\Repository\IncidentPartyRepository;
use UhifadhiLabs\Incident\Repository\IncidentRepository;
use UhifadhiLabs\Incident\Repository\IncidentSubcategoryRepository;
use UhifadhiLabs\Incident\Repository\IncidentZoneLocator;
use UhifadhiLabs\Incident\Service\IncidentDashboardService;
use UhifadhiLabs\Incident\Service\IncidentReportService;
use UhifadhiLabs\Incident\Service\IncidentTaxonomyInstaller;
use UhifadhiLabs\Incident\Service\IncidentTransitionService;
use UhifadhiLabs\Incident\Service\IncidentWidgetUrls;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiLabsIncidentBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions (module category, taxonomy, currency, dev tooling).
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(), and
 * ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * Controllers extend nothing and take their collaborators explicitly, patterned
 * on FrameworkBundle's own TemplateController (see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * THE STATE MACHINE. No entity manager, deliberately: it mutates the object
     * graph and the caller flushes, which is what keeps the whole workflow
     * unit-testable without a database.
     */
    $services->set('incident.transitions', IncidentTransitionService::class);

    // "Which zone is this point in?" — asked once, when an incident is filed.
    $services->set('incident.zone_locator', IncidentZoneLocator::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set('incident.dashboard', IncidentDashboardService::class)
        ->args([
            service(IncidentRepository::class),
            service(IncidentCategoryRepository::class),
            service('incident.transitions'),
            param('incident.currency'),
        ]);

    $services->set('incident.report', IncidentReportService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(IncidentRepository::class),
            service('incident.zone_locator'),
        ]);

    // The taxonomy the deployment records against. Registered unconditionally:
    // without it there is nothing to file an incident against, so it is not dev
    // tooling — see IncidentTaxonomyInstaller.
    $services->set('incident.taxonomy_installer', IncidentTaxonomyInstaller::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(IncidentCategoryRepository::class),
            service(IncidentSubcategoryRepository::class),
            param('incident.taxonomy'),
        ]);

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    foreach ([
        IncidentRepository::class,
        IncidentCategoryRepository::class,
        IncidentSubcategoryRepository::class,
        IncidentEventRepository::class,
        IncidentEvidenceRepository::class,
        IncidentPartyRepository::class,
        IncidentMoneyRepository::class,
        IncidentLinkRepository::class,
    ] as $repository) {
        $services->set($repository)
            ->args([service('doctrine')])
            ->tag('doctrine.repository_service');
    }

    /*
     * The dashboard. Routes reference "IncidentController::dashboard" and
     * Symfony's controller resolver asks the container for that class name, so it
     * gets the alias the best practices prescribe: "For public services, aliases
     * should be created from the interface/class to the service id."
     *
     * The writing screens (the report flow, the transition endpoint) and the
     * widget library are registered in the bundle's SecurityBundle guard instead —
     * see UhifadhiLabsIncidentBundle::loadExtension().
     */
    // The library's URL map, shared by the dashboard and the library itself.
    $services->set('incident.widget_urls', IncidentWidgetUrls::class)
        ->args([service('router')]);

    $services->set('incident.controller.dashboard', IncidentController::class)
        ->args([
            service('twig'),
            service('incident.dashboard'),
            service(IncidentCategoryRepository::class),
            // The HOST's widget framework, by its own service id: the module
            // ships a catalogue, never a copy of the algebra that resolves it.
            service(WidgetService::class),
            service('incident.widget_urls'),
            param('incident.record_screens'),
            param('incident.widget_screens'),
            // Null where the host runs no security: nobody is signed in, so the
            // dashboard renders the module's shipped composition for everyone.
            service('security.token_storage')->nullOnInvalid(),
            // Likewise null without SecurityBundle: no token, no report sheet.
            service('security.csrf.token_manager')->nullOnInvalid(),
            // Registered only under the bundle's SecurityBundle guard, so it is
            // genuinely absent in a host without security — and the board then
            // renders as a board of links.
            service('incident.transition_token')->nullOnInvalid(),
        ])
        ->public();

    $services->alias(IncidentController::class, 'incident.controller.dashboard')->public();
};
