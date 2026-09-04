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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Model\IncidentPrefill;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * REPORTING AN INCIDENT — three answers: what kind, what happened, and where.
 *
 * THE CONTAINER FOLLOWS THE ENTRY POINT, and this route serves both.
 *
 *   FILING FROM A RECORD — another module's "file as incident" button, or the
 *   register's report control carrying context — opens the SLIDE-OVER DRAWER,
 *   with the register legible behind it and the source card riding inside it.
 *   Context you never lost in the first place.
 *
 *   STANDALONE FILING — a deep link, a fresh tab, the dashboard's Report button
 *   when it is not anchored to a record — opens the FULL PAGE. There is nothing
 *   to sit over, and a page reloads, deep-links, prints, and cannot be thrown
 *   away by a click beside it.
 *
 * The test is {@see IncidentPrefill::hasProvenance()} and nothing else, which is
 * why deep-linking this address with no record can never open a drawer over
 * nothing: it renders the page instead.
 *
 * ONE SET OF STEPS. Both containers render the SAME step partials at two widths —
 * filing must not feel like two different products just because two surfaces can
 * lead to it — and both post here, to one endpoint, refused for the same three
 * reasons.
 *
 * STEP 2 IS THE CATEGORY'S OWN. The fields are the sub-category's field set, and
 * the money row EXISTS ONLY where the sub-category carries money — choose a
 * natural mortality and the row is not rendered at all. Not disabled. Absent.
 *
 * ARRIVING FROM AN OBSERVATION: {@see IncidentPrefill} reads the seam's query
 * string. Everything it carries is a guess the filer may overrule, except the
 * provenance link, which is written once and never again.
 *
 * The permission is `incidents.record`. The design's IN·R1 card says filing needs
 * "anyone with Modules access · not a permission of its own"; a POST that CREATES
 * a record still has to be guarded by something a host can grant, so the module
 * DECLARES this permission and a deployment that agrees with the design grants it
 * to everyone who can reach the module. See the README's Permissions section.
 */
final class IncidentReportController
{
    /** Filing an incident. Cheap by design — see the class docblock. */
    public const string RECORD_PERMISSION = 'incidents.record';

    /** The token id the report form carries. */
    public const string CSRF_TOKEN_ID = 'incident_report';

    /**
     * How much of the register stays legible behind the drawer. Context, not a
     * listing: enough rows to recognise where you are, and none of them
     * reachable through a backdrop.
     */
    private const int ROWS_BEHIND = 8;

    /** What the register can print: {@see Incident::$title} is varchar(200). */
    private const int TITLE_LIMIT = 200;

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly IncidentReportService $reports,
        private readonly IncidentCategoryRepository $categories,
        private readonly IncidentSubcategoryRepository $subcategories,
        private readonly IncidentRepository $incidents,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TokenStorageInterface $tokenStorage,
        /**
         * THE PLATFORM'S FILE REGISTRY, and null where the host runs no Files
         * hub. It is how the source card shows the observation's photographs
         * without this bundle knowing anything about observations: it hands the
         * registry the source token and the record uuid the seam arrived with,
         * and the module that OWNS that record answers.
         *
         * Optional on purpose. A deployment with incidents and no storage bundle
         * is a real deployment; it gets a source card with no photograph strip,
         * which is the honest drawing of "there are no photographs here", not a
         * broken page.
         */
        private readonly ?FileRegistry $fileRegistry = null,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/new',
        name: 'incident_new',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    public function new(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $this->denyUnlessGranted();
        $prefill = IncidentPrefill::fromRequest($request);

        return new Response($this->render(
            $area,
            $prefill,
            null === $prefill->subcategorySlug ? null : $this->subcategories->findOneBySlug($prefill->subcategorySlug),
            [],
        ));
    }

    /**
     * FILE IT. Almost nothing is required, because a half-remembered report that
     * exists beats a perfect one that was never filed — the two things that ARE
     * required are the only two an incident cannot be without: what kind of thing
     * happened, and where.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents',
        name: 'incident_create',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function create(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $this->denyUnlessGranted();
        $this->denyUnlessCsrfValid($request);

        $prefill = IncidentPrefill::fromRequest($request);
        $subcategory = $this->subcategories->findOneBySlug($request->request->getString('subcategory'));
        $position = self::positionFrom($request) ?? $prefill->position();
        // CLAMPED, NEVER REFUSED. The line arrives prefilled from the source
        // record's note, which is written to be read rather than to fit a column,
        // so a long one is stored short instead of costing somebody their report.
        // Nothing is lost: the note travels verbatim into the narrative and the
        // provenance link keeps the original reachable forever.
        $title = mb_substr(trim($request->request->getString('title')), 0, self::TITLE_LIMIT);

        $errors = [];
        if (null === $subcategory) {
            $errors['subcategory'] = 'Choose what kind of incident this was.';
        }
        if (null === $position) {
            $errors['position'] = 'An incident happened somewhere — pick the place on the map.';
        }
        if ('' === $title) {
            $errors['title'] = 'One line saying what happened.';
        }

        if (null === $subcategory || null === $position || '' === $title) {
            // 422 and the form back: the request was understood and simply cannot
            // be stored, which is how every recording screen in this deployment
            // answers a rejected form.
            //
            // IN THE CONTAINER IT WAS MADE IN. Being refused is not a new entry
            // point, so a filing from a record is answered in the drawer over the
            // register and a standalone one on its own page — which the prefill
            // decides here exactly as it did on the way in.
            return new Response(
                $this->render($area, $prefill, $subcategory, $errors),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $incident = $this->reports->file(
            area: $area,
            subcategory: $subcategory,
            title: $title,
            position: $position,
            now: new \DateTimeImmutable(),
            severity: IncidentSeverityEnum::tryFrom($request->request->getString('severity')) ?? IncidentSeverityEnum::Medium,
            // The wire token, mapped once and deliberately — never a fallback
            // that happens to land on the right case.
            source: IncidentSourceEnum::forToken($request->request->getString('source'))
                ?? ($prefill->hasProvenance() ? IncidentSourceEnum::PatrolObservation : IncidentSourceEnum::Direct),
            occurredAt: self::occurredAtFrom($request) ?? $prefill->occurredAt,
            narrative: self::narrativeFrom($request) ?? $prefill->note,
            reportedBy: $this->filer(),
            prefill: $prefill,
            details: self::detailsFrom($request, $subcategory->getFieldSet()),
        );

        $this->flash($request, \sprintf('%s filed. It starts at reported and cannot skip verification.', $incident->getReference()));

        return new RedirectResponse($this->router->generate('incident_show', [
            'uuid' => $area->getUuidString(),
            'reference' => $incident->getReference(),
        ]));
    }

    /**
     * THE FLOW, IN WHICHEVER CONTAINER ITS ENTRY POINT EARNED — rendered in one
     * place so a fresh form and a refused one can never disagree about which.
     *
     * @param array<string, string> $errors
     */
    private function render(AreaOfInterest $area, IncidentPrefill $prefill, ?IncidentSubcategory $chosen, array $errors): string
    {
        $fromARecord = $prefill->hasProvenance();
        $query = $prefill->toQuery();

        return $this->twig->render('@UhifadhiIncident/report/show.html.twig', [
            'area' => $area,
            'now' => new \DateTimeImmutable(),
            'categories' => $this->categories->allInOrder(),
            'prefill' => $prefill,
            'chosen' => $chosen,
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'errors' => $errors,
            // THE FORM POSTS BACK TO ITS OWN ENTRY POINT. Without the seam on the
            // action, pressing File would drop the provenance, the source card and
            // the container all at once.
            'createUrl' => $this->router->generate('incident_create', ['uuid' => $area->getUuidString()])
                .([] === $query ? '' : '?'.http_build_query($query)),
            // ONE WAY OUT, AND IT SAYS WHERE IT GOES: back to the record this
            // filing came from, or to the register it was started from. Never a
            // dismissal.
            'cancelUrl' => $fromARecord && null !== $prefill->backUrl
                ? $prefill->backUrl
                : $this->router->generate('incident_dashboard', ['uuid' => $area->getUuidString()]),
            // The register behind the drawer — real rows, because "the page you
            // came from is still on screen" is the entire reason that container
            // exists. The page needs none.
            'behind' => $fromARecord ? $this->incidents->recentForArea($area, self::ROWS_BEHIND) : [],
            // THE SOURCE RECORD'S PHOTOGRAPHS, asked of the module that owns them.
            'sourceFiles' => $this->filesOf($prefill),
        ]);
    }

    /**
     * THE PHOTOGRAPHS OF THE RECORD THIS FILING CAME FROM.
     *
     * Asked of the platform's registry, which asks the module that owns the
     * record — this bundle never names the patrols module, its routes or its key
     * prefix, because a host may install either without the other. All it has is
     * the token and the uuid the seam's query string carried, and that is exactly
     * what {@see FileRegistry::forRecord()} takes.
     *
     * EVERY WAY OF HAVING NONE ANSWERS THE SAME. No storage bundle, no
     * photographs, a token naming a module this deployment does not have, a
     * registry having a bad day — all of them are an empty list and a card with
     * no strip. None of them is an error, and none of them may cost anybody a
     * report.
     *
     * @return list<FileEntry>
     */
    private function filesOf(IncidentPrefill $prefill): array
    {
        if (null === $this->fileRegistry || null === $prefill->source || null === $prefill->record) {
            return [];
        }

        try {
            return $this->fileRegistry->forRecord($prefill->source, $prefill->record->toRfc4122());
        } catch (\Throwable) {
            return [];
        }
    }

    /** The point the form carries, as GeoJSON text, or null where it carried none. */
    private static function positionFrom(Request $request): ?string
    {
        $lat = $request->request->getString('lat');
        $lng = $request->request->getString('lng');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return \sprintf('{"type":"Point","coordinates":[%.6F,%.6F]}', (float) $lng, (float) $lat);
    }

    private static function occurredAtFrom(Request $request): ?\DateTimeImmutable
    {
        $raw = trim($request->request->getString('occurred_at'));
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            // "I do not know when" is a legitimate answer to that question, and a
            // typo in a date must never stand between a ranger and a filed report.
            return null;
        }
    }

    private static function narrativeFrom(Request $request): ?string
    {
        $narrative = trim($request->request->getString('narrative'));

        return '' !== $narrative ? $narrative : null;
    }

    /**
     * The answers to THIS sub-category's own questions, and only those: a field
     * the sub-category does not ask for is not stored, so re-categorising an
     * incident never carries a stale answer into its new form.
     *
     * @param list<array{key: string, label: string}> $fieldSet
     *
     * @return array<string, string>
     */
    private static function detailsFrom(Request $request, array $fieldSet): array
    {
        $details = [];
        foreach ($fieldSet as $field) {
            $value = trim($request->request->getString('details_'.$field['key']));
            if ('' !== $value) {
                $details[$field['key']] = $value;
            }
        }

        return $details;
    }

    private function denyUnlessGranted(): void
    {
        if (!$this->authorization->isGranted(self::RECORD_PERMISSION)) {
            throw new AccessDeniedException('Filing an incident needs "'.self::RECORD_PERMISSION.'".');
        }
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the report form.');
        }
    }

    private function filer(): ?UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
