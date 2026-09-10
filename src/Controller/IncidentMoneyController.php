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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Exception\IncidentMoneyException;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentMoneyService;

/**
 * RECORDING THE MONEY ON ONE INCIDENT — the write surface behind the case file's
 * money panel, and the door the read-only money card has always been waiting for.
 *
 * A THIN DOOR ON THE SERVICE. Every rule about money — that it exists only where
 * the sub-category carries it, that it cannot be recorded before response, that a
 * waiver needs a reason, that the row is born on the first amount — lives in
 * {@see IncidentMoneyService}. This controller reads the form, checks the same two
 * things every writing screen in the module checks (the permission and the token),
 * and hands over; a refusal comes back as the service's own sentence, answered 422
 * exactly as {@see IncidentDetailController::transition()} answers the workflow's.
 *
 * IT RIDES ON `incidents.manage`, and deliberately so: the permission catalogue's
 * own words for that permission are "settle the fines and compensation on it". The
 * money is part of moving an incident on, so there is no separate permission to
 * assess, approve or settle — a host that trusts somebody to manage incidents
 * trusts them with the figures on them. See `docs/permissions.md`.
 *
 * The incident is looked up WITHIN THE AREA in the URL and the token is the SAME
 * per-area token the case file mints for its transitions — one door's worth of
 * proof, honoured by every write on the surface.
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
final class IncidentMoneyController
{
    /** Recording money is part of managing an incident — see the class docblock and `docs/permissions.md`. */
    public const string MANAGE_PERMISSION = 'incidents.manage';

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly IncidentRepository $incidents,
        private readonly IncidentMoneyService $money,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * RECORD THE FOUR AMOUNTS. Each is optional and each save is the whole truth —
     * a blank field is "not set" — because the panel prefills the current figures
     * and posts them back. The row is created on the first amount, which is when
     * the money card appears.
     */
    #[Route(
        '/areas/{uuid}/modules/incidents/{reference}/money',
        name: 'incident_money',
        requirements: ['uuid' => Requirement::UUID, 'reference' => '[A-Z]{2,6}-\d{2,8}'],
        methods: ['POST'],
    )]
    public function record(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $reference,
    ): Response {
        $incident = $this->incidentIn($area, $reference);
        $this->denyUnlessGranted();
        $this->denyUnlessCsrfValid($request, $area);

        try {
            $this->money->record(
                $incident,
                self::amount($request, 'claimed'),
                self::amount($request, 'assessed'),
                self::amount($request, 'approved'),
                self::amount($request, 'settled'),
                new \DateTimeImmutable(),
                $actor = $this->actor(),
                IncidentDetailController::nameOf($actor),
            );
        } catch (IncidentMoneyException $refused) {
            return new Response($refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->backToCaseFile($request, $area, $incident, \sprintf('Money recorded on %s.', $incident->getReference()));
    }

    /** WAIVE THE MONEY, WITH A REASON — the other way its resolve guard is satisfied. */
    #[Route(
        '/areas/{uuid}/modules/incidents/{reference}/money/waive',
        name: 'incident_money_waive',
        requirements: ['uuid' => Requirement::UUID, 'reference' => '[A-Z]{2,6}-\d{2,8}'],
        methods: ['POST'],
    )]
    public function waive(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $reference,
    ): Response {
        $incident = $this->incidentIn($area, $reference);
        $this->denyUnlessGranted();
        $this->denyUnlessCsrfValid($request, $area);

        try {
            $this->money->waive(
                $incident,
                $request->request->getString('reason'),
                new \DateTimeImmutable(),
                $actor = $this->actor(),
                IncidentDetailController::nameOf($actor),
            );
        } catch (IncidentMoneyException $refused) {
            return new Response($refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->backToCaseFile($request, $area, $incident, \sprintf('Money on %s waived.', $incident->getReference()));
    }

    private function backToCaseFile(Request $request, AreaOfInterest $area, Incident $incident, string $message): RedirectResponse
    {
        $this->flash($request, $message);

        return new RedirectResponse($this->router->generate('incident_show', [
            'uuid' => $area->getUuidString(),
            'reference' => $incident->getReference(),
        ]));
    }

    /**
     * A whole-shilling amount from the form, or null where the field was blank or
     * not a number. Never negative — an overpayment is a different problem, and the
     * entity clamps it too.
     */
    private static function amount(Request $request, string $key): ?int
    {
        $raw = trim($request->request->getString($key));
        if ('' === $raw || !is_numeric($raw)) {
            return null;
        }

        return max(0, (int) $raw);
    }

    /** The incident, IN THIS AREA — a 404 otherwise, the same answer as one that never existed. */
    private function incidentIn(AreaOfInterest $area, string $reference): Incident
    {
        $incident = $this->incidents->findOneByReference($reference);
        if (null === $incident || $incident->getArea() !== $area) {
            throw new NotFoundHttpException(\sprintf('No incident %s in this area.', $reference));
        }

        return $incident;
    }

    private function denyUnlessGranted(): void
    {
        if (!($this->authorization?->isGranted(self::MANAGE_PERMISSION) ?? false)) {
            throw new AccessDeniedException('Recording money on an incident needs "'.self::MANAGE_PERMISSION.'".');
        }
    }

    private function denyUnlessCsrfValid(Request $request, AreaOfInterest $area): void
    {
        if (null === $this->csrfTokenManager) {
            return;
        }

        // The SAME per-area token the case file mints for its transitions — see
        // IncidentDetailController::csrfTokenId() for why the scope is the area.
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(IncidentDetailController::csrfTokenId($area), $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for this incident.');
        }
    }

    private function actor(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

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
