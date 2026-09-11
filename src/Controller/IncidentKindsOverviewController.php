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

namespace Uhifadhi\Incident\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Service\IncidentKindsOverviewService;

/**
 * THE KINDS THIS AREA FILES, READ-ONLY — what each word switches on and what has
 * been filed against it. It is a DATA PLACE, so it is one of the module's tabs;
 * the editing of the same words is a section of the configure page, one link
 * away from here.
 *
 * EACH KIND IS ITS OWN ADDRESS, by its wire-code: the browser's back button, a
 * shared link and the left pane's rows are then the same mechanism.
 *
 * LEAN, and without a base class: a reusable bundle's controller takes what it
 * needs in its constructor and is wired explicitly, exactly as FrameworkBundle's
 * own TemplateController is.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final readonly class IncidentKindsOverviewController
{
    public function __construct(
        private Environment $twig,
        private IncidentKindsOverviewService $kinds,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/incident-kinds/{kind}',
        name: 'incident_kinds_overview',
        requirements: ['uuid' => Requirement::UUID, 'kind' => '[a-z0-9-]+'],
        defaults: ['kind' => null],
        methods: ['GET'],
    )]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        ?string $kind = null,
    ): Response {
        return new Response($this->twig->render('@UhifadhiIncident/kinds/overview.html.twig', [
            'area' => $area,
            'kinds' => $this->kinds->build($area, $kind, new \DateTimeImmutable()),
        ]));
    }
}
