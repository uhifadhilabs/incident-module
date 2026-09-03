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
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Model\IncidentWidgets;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentTransitionToken;
use Uhifadhi\Incident\Service\IncidentWidgetUrls;
use Uhifadhi\Service\WidgetService;

/**
 * THE INCIDENTS DASHBOARD for one area — the widget surface.
 *
 * A plain class, not a Symfony AbstractController subclass: a reusable bundle
 * defines its services explicitly ("Services should not use autowiring or
 * autoconfiguration" — https://symfony.com/doc/current/bundles/best_practices.html),
 * and without autoconfiguration AbstractController's #[Required] setContainer is
 * never called. FrameworkBundle's own TemplateController is written exactly this
 * way — see vendor/symfony/framework-bundle/Controller/TemplateController.php.
 *
 * It rides the HOST's widget framework: the catalogue is
 * {@see IncidentWidgets::catalog()} and the layout comes from the host's
 * {@see WidgetService}, so the incidents dashboard arranges itself exactly as
 * departments, team and zones do and this bundle ships no widget mechanics of
 * its own.
 *
 * ONE FILTER DRIVES EVERYTHING. The request's query is read ONCE, into an
 * {@see IncidentFilter}, and every widget on the page reads the one
 * {@see \Uhifadhi\Incident\Model\IncidentDashboard} built from it — so the
 * map, the register and the charts can never be answering different questions.
 */
final class IncidentController
{
    /**
     * The window every incidents surface opens on: the calendar month containing
     * "now" — the design's "august 2026", stated once.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable} half-open: from is included, to is not
     */
    public static function monthRange(\DateTimeImmutable $now): array
    {
        $from = $now->modify('first day of this month')->setTime(0, 0);

        return [$from, $from->modify('+1 month')];
    }

    public function __construct(
        private readonly Environment $twig,
        private readonly IncidentDashboardService $dashboard,
        private readonly IncidentCategoryRepository $categories,
        private readonly WidgetService $widgets,
        private readonly IncidentWidgetUrls $widgetUrls,
        /** Whether the writing screens exist in this host — they need SecurityBundle. */
        private readonly bool $recordScreens = false,
        /** Whether the widget library exists in this host — it edits ONE person's layout. */
        private readonly bool $widgetScreens = false,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        /** Minted in one place — see the service's own docblock for why. */
        private readonly ?IncidentTransitionToken $transitionToken = null,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents',
        name: 'incident_dashboard',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function dashboard(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        // "now" is handed to the pure dashboard service and to the template, so
        // every figure on the page is stated relative to the SAME instant.
        $now = new \DateTimeImmutable();
        $viewer = $this->viewer();
        $filter = IncidentFilter::fromRequest($request, $area, $this->categories->allInOrder(), ...self::monthRange($now));

        return new Response($this->twig->render('@UhifadhiIncident/dashboard/show.html.twig', [
            'area' => $area,
            'now' => $now,
            'dashboard' => $this->dashboard->build($filter, $now, $viewer),
            'filter' => $filter,
            'recordScreens' => $this->recordScreens,
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // module's shipped composition until they adopt one of the five.
            'widgets' => $this->widgets->resolve(IncidentWidgets::catalog(), $this->userId(), $area->getUuid()),
            'transitionCsrfToken' => $this->transitionToken?->forArea($area),
            'urls' => $this->widgetUrls->forArea($area),
        ]));
    }

    /** Null where the host runs no security, or nobody is signed in: the shipped composition. */
    private function viewer(): ?User
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function userId(): ?int
    {
        return $this->viewer()?->getId();
    }
}
