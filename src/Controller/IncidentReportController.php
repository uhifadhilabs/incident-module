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

namespace UhifadhiLabs\Incident\Controller;

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
use Uhifadhi\Entity\User;
use UhifadhiLabs\Incident\Enum\IncidentSeverityEnum;
use UhifadhiLabs\Incident\Enum\IncidentSourceEnum;
use UhifadhiLabs\Incident\Model\IncidentPrefill;
use UhifadhiLabs\Incident\Repository\IncidentCategoryRepository;
use UhifadhiLabs\Incident\Repository\IncidentSubcategoryRepository;
use UhifadhiLabs\Incident\Service\IncidentReportService;

/**
 * REPORTING AN INCIDENT — three steps: what kind, what happened, who was
 * involved.
 *
 * ONE COMPONENT, TWO DOORS. The dashboard opens the same sheet over itself; this
 * route is that sheet as its own page, for a deep link, a fresh tab, or the
 * patrols module's "File as incident" button. Filing must not feel like five
 * different products just because five dashboards can lead to it, so both doors
 * render the SAME partial.
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

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly IncidentReportService $reports,
        private readonly IncidentCategoryRepository $categories,
        private readonly IncidentSubcategoryRepository $subcategories,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TokenStorageInterface $tokenStorage,
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

        return new Response($this->twig->render('@UhifadhiLabsIncident/report/show.html.twig', [
            'area' => $area,
            'now' => new \DateTimeImmutable(),
            'categories' => $this->categories->allInOrder(),
            'prefill' => $prefill,
            'chosen' => null === $prefill->subcategorySlug ? null : $this->subcategories->findOneBySlug($prefill->subcategorySlug),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'errors' => [],
        ]));
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
        $title = trim($request->request->getString('title'));

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
            // 422 and the form back with its answers: the request was understood
            // and simply cannot be stored, which is how every recording screen in
            // this deployment answers a rejected form.
            return new Response($this->twig->render('@UhifadhiLabsIncident/report/show.html.twig', [
                'area' => $area,
                'now' => new \DateTimeImmutable(),
                'categories' => $this->categories->allInOrder(),
                'prefill' => $prefill,
                'chosen' => $subcategory,
                'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
                'errors' => $errors,
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $incident = $this->reports->file(
            area: $area,
            subcategory: $subcategory,
            title: $title,
            position: $position,
            now: new \DateTimeImmutable(),
            severity: IncidentSeverityEnum::tryFrom($request->request->getString('severity')) ?? IncidentSeverityEnum::Medium,
            source: IncidentSourceEnum::tryFrom($request->request->getString('source'))
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

    private function filer(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
