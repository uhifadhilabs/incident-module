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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentTransitionToken;
use Uhifadhi\Incident\Service\IncidentWidgetUrls;
use Uhifadhi\Incident\Widget\IncidentWidgets;

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
 * It rides the shell's widget machinery: the catalogue is
 * {@see IncidentWidgets::declaration()} and the layout comes from the host's
 * {@see WidgetService}, so the incidents dashboard arranges itself exactly as
 * departments, team and zones do and this bundle ships no widget mechanics of
 * its own.
 *
 * ONE FILTER DRIVES EVERYTHING. The request's query is read ONCE, into an
 * {@see IncidentFilter}, and every widget on the page reads the one
 * {@see \Uhifadhi\Incident\Model\IncidentDashboard} built from it — so the
 * map, the register and the charts can never be answering different questions.
 *
 * WHICH MODULE THESE ROUTES BELONG TO, said once for the class. RegistryBundle
 * owns the per-area ledger and closes a parked module's pages before any
 * controller is asked — 404, not 403, because a parked module is not withheld:
 * the area is not running it. The class-level default below is how a route tells
 * the gate whose page it is.
 *
 * WITHOUT IT THESE ROUTES ARE NOT EXEMPT, THEY ARE GUESSED AT. The gate falls
 * back to reading `/areas/{uuid}/modules/{slug}/…` and matching the segment
 * against the catalogue, which happens to land here because the segment and the
 * slug are both `incidents`. That is an accident of naming, not a contract, and
 * it would end the moment a path moved. The area's uuid is in a parameter called
 * `uuid`, which is the gate's own default, so there is no
 * `_uhifadhi_module_area` to state.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final class IncidentController
{
    /**
     * THE EXPORT'S HEADER ROW — the register's own meaningful columns, in the
     * order the register reads left to right, with the assignee the screen carries
     * in its rail added at the end.
     *
     * @var list<string>
     */
    private const array EXPORT_COLUMNS = [
        'reference',
        'reported',
        'category',
        'sub-category',
        'what happened',
        'zone',
        'status',
        'severity',
        'money direction',
        'money currency',
        'money payable',
        'money outstanding',
        'assignee',
    ];

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

    /**
     * THE WINDOW A REQUEST OPENS ON — the `month=YYYY-MM` query the month dropdown
     * drives, or the month containing "now" when it is absent or unreadable.
     *
     * Untrusted like every other query field: a hand-edited month that does not
     * parse degrades to the current month rather than throwing.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable} half-open: from is included, to is not
     */
    public static function windowFor(Request $request, \DateTimeImmutable $now): array
    {
        $month = trim($request->query->getString('month'));
        if ('' !== $month) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00');
            if (false !== $parsed) {
                $from = $parsed->setTime(0, 0);

                return [$from, $from->modify('+1 month')];
            }
        }

        return self::monthRange($now);
    }

    /**
     * ONE INCIDENT AS A CSV ROW, in the order {@see self::EXPORT_COLUMNS} names.
     *
     * The money is written as the register shows it — direction, currency, what is
     * payable and what is still outstanding — and is FOUR blank cells where no
     * money row exists, so a "natural mortality" reads as no money rather than
     * zero money. The two amounts are never summed here, exactly as the dashboard
     * refuses to sum a fine owed to the authority with a claim owed by it.
     *
     * @return list<string>
     */
    private static function exportRow(Incident $incident): array
    {
        $money = $incident->getMoney();

        return [
            $incident->getReference(),
            $incident->getReportedAt()->format('Y-m-d'),
            $incident->getKind()->getLabel(),
            $incident->getSubcategory()->getLabel(),
            $incident->headline(),
            $incident->getZone()?->getName() ?? '',
            $incident->getStatus()->label(),
            $incident->getSeverity()->label(),
            $money?->getDirection()->value ?? '',
            $money?->getCurrency() ?? '',
            null === $money ? '' : (string) $money->payable(),
            null === $money ? '' : (string) $money->outstanding(),
            $incident->getAssignedTo()?->getFullName() ?? '',
        ];
    }

    public function __construct(
        private readonly Environment $twig,
        private readonly IncidentDashboardService $dashboard,
        private readonly TaxonomyKindRepository $kinds,
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
        $filter = IncidentFilter::fromRequest($request, $area, $this->kinds->forArea($area), ...self::windowFor($request, $now));

        return new Response($this->twig->render('@UhifadhiIncident/dashboard/show.html.twig', [
            'area' => $area,
            'now' => $now,
            'dashboard' => $this->dashboard->build($filter, $now, $viewer),
            'filter' => $filter,
            'recordScreens' => $this->mayRecord(),
            'manageScreens' => $this->mayManage(),
            'widgetScreens' => $this->widgetScreens,
            // Which widgets this person keeps, how wide, in what order — the
            // module's shipped composition until they adopt one of the five.
            'widgets' => $this->widgets->resolve(IncidentWidgets::declaration(), $viewer, $area->getUuid()),
            'transitionCsrfToken' => $this->transitionToken?->forArea($area),
            'urls' => $this->widgetUrls->forArea($area),
        ]));
    }

    /**
     * THE REGISTER AS A FILE — the same rows the dashboard lists, streamed as CSV.
     *
     * ONE FILTER, ONE READING. The export reads the request through the SAME
     * {@see IncidentFilter} the dashboard does — lens, category, status, zone,
     * search and the month window — so the file a person downloads is exactly the
     * register they were looking at, never a wider or a different set. Change a
     * chip and the export changes with it, because both ask the one query.
     *
     * NO PERMISSION OF ITS OWN, and deliberately so. Reading incidents is reading
     * the module — the module declares no "view" permission, because a view gate is
     * exactly the tool one department would use to hide a row from another (see
     * {@see IncidentModuleProvider::permissions()}). A CSV
     * of the register is the same read as the register on screen, so it is offered
     * on the same terms: whoever can reach the dashboard can download it, and the
     * host's firewall is what stands between the wider world and either one.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents/export.csv',
        name: 'incident_export',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        // Ahead of the case file's "/incidents/{reference}" so the literal
        // export.csv is never read as a reference — belt-and-braces, since the
        // reference pattern would not match it anyway.
        priority: 2,
    )]
    public function export(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $now = new \DateTimeImmutable();
        $filter = IncidentFilter::fromRequest($request, $area, $this->kinds->forArea($area), ...self::windowFor($request, $now));
        // The SAME rows the register lists — findFiltered, read through the one
        // filter — so the file and the screen can never disagree.
        $incidents = $this->dashboard->build($filter, $now, $this->viewer())->recent;

        $response = new StreamedResponse(static function () use ($incidents): void {
            $handle = fopen('php://output', 'w');
            \assert(false !== $handle);

            // The escape argument is given explicitly: PHP 8.4 deprecates relying
            // on its default, and an empty escape is the modern, round-trippable
            // choice (a value is quoted, never backslash-escaped).
            fputcsv($handle, self::EXPORT_COLUMNS, ',', '"', '');
            foreach ($incidents as $incident) {
                fputcsv($handle, self::exportRow($incident), ',', '"', '');
            }

            fclose($handle);
        });

        $filename = \sprintf('incidents-%s.csv', $filter->from?->format('Y-m') ?? $now->format('Y-m'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
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

    /**
     * WHETHER TO OFFER THE TAXONOMY ADMIN — the same two questions as {@see
     * self::mayRecord()}, asked of the other tier.
     *
     * The taxonomy screen is a WRITING screen: it exists only where SecurityBundle
     * does (so `$this->recordScreens`, which is that compile-time fact for every
     * writing screen this bundle ships), and it enforces `incidents.manage` in
     * code. Handing somebody the link who cannot open it would fail them at the
     * click, so the header asks both questions before drawing the door — the
     * fleet's rule that a control the viewer may not use is ABSENT, never greyed.
     */
    private function mayManage(): bool
    {
        return $this->recordScreens
            && null !== $this->authorization
            && $this->authorization->isGranted(IncidentTaxonomyController::MANAGE_PERMISSION);
    }

    /** Null where the installation runs no security, or nobody is signed in: the shipped composition. */
    private function viewer(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
