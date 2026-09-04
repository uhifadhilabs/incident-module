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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Exception\IncidentTransitionException;
use Uhifadhi\Incident\Model\IncidentMapPayload;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * ONE CASE FILE — the whole record on one page, and the one place an incident is
 * moved on.
 *
 * TWO THINGS THE DESIGN INSISTS ON, and both live here:
 *
 *  1. **The rail, not a dropdown.** A status is never a select box. The page
 *     draws where the incident is, what it passed and what is left, and offers
 *     ONLY the legal transitions — with the refusal printed beside them for the
 *     ones it cannot make. {@see IncidentTransitionService} decides all of it;
 *     this controller decides nothing about the workflow.
 *  2. **Gated panels.** A step owns a panel, and the panel DOES NOT EXIST until
 *     the step is reached — never rendered-and-disabled. There is no empty
 *     "resolution" form sitting on a freshly reported incident inviting somebody
 *     to fill it in early. The template asks
 *     {@see \Uhifadhi\Incident\Model\IncidentRail::hasReached()} and renders
 *     nothing when the answer is no.
 *
 * The incident is looked up WITHIN THE AREA in the URL: an incident from another
 * area answers 404, the same answer as one that never existed, because a case
 * reference is the kind of thing people guess at.
 */
final class IncidentDetailController
{
    /** Moving an incident on is the expensive half of the workflow — see the module's README. */
    public const string MANAGE_PERMISSION = 'incidents.manage';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentRepository $incidents,
        private readonly IncidentDashboardService $dashboard,
        private readonly IncidentTransitionService $transitions,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/{reference}',
        name: 'incident_show',
        requirements: ['uuid' => Requirement::UUID, 'reference' => '[A-Z]{2,6}-\d{2,8}'],
        methods: ['GET'],
    )]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $reference,
    ): Response {
        $incident = $this->incidentIn($area, $reference);
        $now = new \DateTimeImmutable();

        return new Response($this->twig->render('@UhifadhiIncident/incident/show.html.twig', [
            'area' => $area,
            'now' => $now,
            'incident' => $incident,
            'rail' => $this->dashboard->railFor($incident, $now),
            // The SAME builder the dashboard's maps use: one marker, one meaning,
            // wherever it is drawn.
            'mapPayload' => IncidentMapPayload::of([$incident]),
            'canManage' => $this->canManage(),
            'csrfToken' => $this->csrfTokenManager?->getToken(self::csrfTokenId($area))->getValue(),
        ]));
    }

    /**
     * MOVE THE INCIDENT ON. The transition is named in the URL because a
     * transition IS the whole instruction — which is also why the status board's
     * drag-and-drop and this page's buttons post to the same endpoint.
     *
     * The workflow decides, not this method: an illegal move or a guard's refusal
     * comes back as {@see IncidentTransitionException} and is answered 422 with
     * the guard's own sentence, which is exactly what the toolbar prints.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents/{reference}/transition/{transition}',
        name: 'incident_transition',
        requirements: ['uuid' => Requirement::UUID, 'reference' => '[A-Z]{2,6}-\d{2,8}', 'transition' => '[a-z_]+'],
        methods: ['POST'],
    )]
    public function transition(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $reference,
        string $transition,
    ): Response {
        $incident = $this->incidentIn($area, $reference);
        $this->denyUnlessGranted();
        $this->denyUnlessCsrfValid($request, $area);

        $move = IncidentTransitionEnum::tryFrom($transition);
        if (null === $move) {
            throw new NotFoundHttpException(\sprintf('The incident workflow has no transition "%s".', $transition));
        }

        $actor = $this->actor();
        try {
            $this->transitions->apply(
                $incident,
                $move,
                new \DateTimeImmutable(),
                $actor,
                self::nameOf($actor),
                self::noteFrom($request),
            );
        } catch (IncidentTransitionException $refused) {
            // 422, with the guard's own words: the move was understood perfectly
            // and is simply not allowed from here.
            return new Response($refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();
        $this->flash($request, \sprintf('%s is now %s.', $incident->getReference(), $incident->getStatus()->label()));

        return new RedirectResponse($this->router->generate('incident_show', [
            'uuid' => $area->getUuidString(),
            'reference' => $incident->getReference(),
        ]));
    }

    /**
     * Scoped per AREA, not per incident: a token minted for one area's incidents
     * cannot move another area's.
     *
     * Per-area rather than per-case-file for a concrete reason. The STATUS BOARD
     * moves incidents by dragging a card, and one board holds every open incident
     * on the surface — a per-incident token would mean minting one token per card
     * and hoping the script picked the right one. One token for the surface is
     * what both doors can honestly carry, and the area is a real scope.
     */
    public static function csrfTokenId(AreaOfInterest $area): string
    {
        return 'incident_transition_'.$area->getUuidString();
    }

    /**
     * The incident, IN THIS AREA. An incident of another area is a 404 rather
     * than a redirect: the same answer as one that never existed is the only
     * answer that leaks nothing about what other areas hold.
     */
    private function incidentIn(AreaOfInterest $area, string $reference): Incident
    {
        $incident = $this->incidents->findOneByReference($reference);
        if (null === $incident || $incident->getArea() !== $area) {
            throw new NotFoundHttpException(\sprintf('No incident %s in this area.', $reference));
        }

        return $incident;
    }

    private function canManage(): bool
    {
        return $this->authorization?->isGranted(self::MANAGE_PERMISSION) ?? false;
    }

    private function denyUnlessGranted(): void
    {
        if (!$this->canManage()) {
            throw new AccessDeniedException('Moving an incident through its workflow needs "'.self::MANAGE_PERMISSION.'".');
        }
    }

    private function denyUnlessCsrfValid(Request $request, AreaOfInterest $area): void
    {
        if (null === $this->csrfTokenManager) {
            return;
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($area), $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for this incident.');
        }
    }

    /** Who is making the move. Null in a host without security — the timeline says so honestly. */
    private function actor(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * What the timeline prints the actor's name as — "J. Mollel", the design's own
     * form. Kept BESIDE the account on the event, so the record still names them
     * if the account is later removed.
     */
    public static function nameOf(?UserInterface $user): ?string
    {
        if (null === $user) {
            return null;
        }

        $first = (string) $user->getFirstName();
        $last = (string) $user->getLastName();
        $name = trim(('' !== $first ? mb_substr($first, 0, 1).'. ' : '').$last);

        return '' !== $name ? $name : null;
    }

    /** The optional line somebody typed with the move. */
    private static function noteFrom(Request $request): ?string
    {
        $note = trim($request->request->getString('note'));

        return '' !== $note ? $note : null;
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
