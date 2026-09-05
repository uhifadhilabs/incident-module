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
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentTransitionToken;
use Uhifadhi\Incident\Service\IncidentWidgetUrls;
use Uhifadhi\Incident\Widget\IncidentWidgets;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Widget\Service\WidgetService;

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
 * It rides uhifadhi/widget-module: the catalogue is
 * {@see IncidentWidgets::declaration()} and the layout comes from the host's
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
        /**
         * Whether the writing screens EXIST in this installation — they need
         * SecurityBundle. A question about the installation, never about the
         * viewer; {@see self::mayRecord()} asks the other one.
         */
        private readonly bool $recordScreens = false,
        /** Whether the widget library exists in this host — it edits ONE person's layout. */
        private readonly bool $widgetScreens = false,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        /** Minted in one place — see the service's own docblock for why. */
        private readonly ?IncidentTransitionToken $transitionToken = null,
        /** Null without security — see {@see self::mayRecord()}. */
        private readonly ?AuthorizationCheckerInterface $authorization = null,
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
            'recordScreens' => $this->mayRecord(),
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // module's shipped composition until they adopt one of the five.
            'widgets' => $this->widgets->resolve(IncidentWidgets::declaration(), $viewer, $area->getUuid()),
            'transitionCsrfToken' => $this->transitionToken?->forArea($area),
            'urls' => $this->widgetUrls->forArea($area),
        ]));
    }

    /**
     * WHETHER TO OFFER THE FILING SCREEN — and it is TWO questions, not one,
     * which is the bug this method exists to fix.
     *
     * The first is about the INSTALLATION: the screen that creates an incident is
     * registered only where SecurityBundle is, so where it is absent there is no
     * route to link at. That is `$this->recordScreens`, decided at compile time.
     *
     * The second is about THE VIEWER: that screen enforces `incidents.record` in
     * code, so somebody without it who follows the link gets a 403. Asking only
     * the first question meant every signed-in person was handed the door, and
     * the ones who could not open it found out by being refused.
     *
     * A CONTROL THE VIEWER MAY NOT HAVE IS ABSENT, never greyed out — the fleet's
     * rule, and the stronger reading here: a disabled button tells somebody a
     * screen exists and they are not trusted with it, and a live link that fails
     * tells them nothing until they have lost the click.
     *
     * Null checker means no door, which is correct rather than defensive: an
     * installation with no authorization checker has no filing route either,
     * because the bundle registers none without SecurityBundle.
     */
    private function mayRecord(): bool
    {
        return $this->recordScreens
            && null !== $this->authorization
            && $this->authorization->isGranted(IncidentReportController::RECORD_PERMISSION);
    }

    /** Null where the installation runs no security, or nobody is signed in: the shipped composition. */
    private function viewer(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
