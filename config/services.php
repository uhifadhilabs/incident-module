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

use Uhifadhi\Incident\Controller\IncidentController;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Repository\IncidentEventRepository;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Repository\IncidentLinkRepository;
use Uhifadhi\Incident\Repository\IncidentMoneyRepository;
use Uhifadhi\Incident\Repository\IncidentPartyRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Repository\IncidentZoneLocator;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentOverviewFigures;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Service\IncidentWidgetUrls;
use Uhifadhi\Incident\Twig\IncidentTrailExtension;
use Uhifadhi\Widget\Service\WidgetService;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiIncidentBundle::loadExtension(), which keeps only the config-DRIVEN
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

    /*
     * THE MODULE'S READING OF ONE AREA'S MORNING — what it contributes to the
     * HOST's area overview: four cards, two right-now tiles, its attention items,
     * its map layer. All five ask this one service, and it memoises per (area,
     * instant), so a page that draws several of them measures the register once.
     */
    $services->set('incident.overview.figures', IncidentOverviewFigures::class)
        ->args([
            service(IncidentRepository::class),
            service(IncidentCategoryRepository::class),
            service('router'),
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
     * THE CRUMB'S ONE HELPER — `incident_url()`, which answers null for a screen
     * the installation did not mount instead of throwing the page away. See
     * IncidentTrailExtension for why a module's breadcrumb cannot use path().
     */
    $services->set('incident.twig.trail', IncidentTrailExtension::class)
        ->args([service('router')])
        ->tag('twig.extension');

    /*
     * The dashboard. Routes reference "IncidentController::dashboard" and
     * Symfony's controller resolver asks the container for that class name, so it
     * gets the alias the best practices prescribe: "For public services, aliases
     * should be created from the interface/class to the service id."
     *
     * The writing screens (the report flow, the transition endpoint) and the
     * widget library are registered in the bundle's SecurityBundle guard instead —
     * see UhifadhiIncidentBundle::loadExtension().
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
            // Registered only under the bundle's SecurityBundle guard, so it is
            // genuinely absent in an installation without security — and the
            // board then renders as a board of links.
            service('incident.transition_token')->nullOnInvalid(),
            // Whether THIS VIEWER may file — a different question from whether
            // the filing screen exists, and the dashboard has to ask both before
            // it offers a door. Null under the same condition as the token
            // storage, and the answer is then "no door", which is right: an
            // installation with no authorization checker cannot enforce
            // incidents.record either, so the filing route does not exist.
            service('security.authorization_checker')->nullOnInvalid(),
        ])
        ->public();

    $services->alias(IncidentController::class, 'incident.controller.dashboard')->public();
};
