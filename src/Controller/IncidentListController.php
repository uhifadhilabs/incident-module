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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentListService;

/**
 * EVERY INCIDENT FILED IN THIS AREA — the dashboard's list widget without a cap
 * on it, which is the one thing a widget cannot be: a card that grew with the
 * data would make a busy month a page nobody can read.
 *
 * IT IS A DATA PLACE, so it is one of the module's two tabs, and the case file
 * lights it — see {@see \Uhifadhi\Incident\Shell\IncidentModuleTabs}.
 *
 * ONE FILTER, ONE READING. It reads the request through the SAME
 * {@see IncidentFilter} the dashboard and the export read, and it pages the SAME
 * answer the counts are worked out from — so the number in the caption and the
 * rows under it can never be describing different questions.
 *
 * LEAN, and without a base class: a reusable bundle's controller takes what it
 * needs in its constructor and is wired explicitly, exactly as FrameworkBundle's
 * own TemplateController is.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final readonly class IncidentListController
{
    public function __construct(
        private Environment $twig,
        private IncidentDashboardService $dashboard,
        private IncidentCategoryRepository $categories,
        private IncidentListService $list,
        private bool $recordScreens = false,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * Ahead of the case file's `/incidents/{reference}` as belt and braces: the
     * reference pattern could not match the literal segment anyway, and an
     * ordering that depends on that is an ordering nobody can see.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents/incidents',
        name: 'incident_list',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    public function list(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $now = new \DateTimeImmutable();
        $viewer = $this->viewer();
        $filter = IncidentFilter::fromRequest($request, $area, $this->categories->allInOrder(), ...IncidentController::windowFor($request, $now));

        $dashboard = $this->dashboard->build($filter, $now, $viewer);

        return new Response($this->twig->render('@UhifadhiIncident/list/show.html.twig', [
            'area' => $area,
            'now' => $now,
            'filter' => $filter,
            // The filter row's chips read their counts off the dashboard, which
            // is the one place they are worked out.
            'dashboard' => $dashboard,
            'recordScreens' => $this->mayRecord(),
            // Untrusted like every query field: an unreadable page is the first
            // one, and the service clamps a page past the end.
            'list' => $this->list->page($dashboard->recent, max(1, $request->query->getInt('page', 1))),
        ]));
    }

    private function mayRecord(): bool
    {
        return $this->recordScreens
            && null !== $this->authorization
            && $this->authorization->isGranted(IncidentReportController::RECORD_PERMISSION);
    }

    private function viewer(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
