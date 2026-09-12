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
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Exception\AreaListConflictException;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;
use Uhifadhi\Incident\Service\AreaListBoardService;
use Uhifadhi\Incident\Service\AreaListService;

/**
 * THE WORDS THIS AREA'S GATING QUESTIONS OFFER — the design's lists.html, ported.
 *
 * IT IS A SECTION OF THE CONFIGURE PAGE that kept an address of its own, beside
 * Incident kinds: it wears the configure page's heading and the configure page's
 * strip, the Configure action stays lit on it, and the shell draws all three. The
 * strip reads Widget library · Incident kinds · Lists · Settings, and the section
 * is declared as a {@see \Uhifadhi\Contracts\Shell\ConfigurationSection::screen()}
 * in {@see \Uhifadhi\Incident\Shell\IncidentConfigurationSections}.
 *
 * ONE ROUTE, FOUR FOLDS, ONE OPEN. Which one is open is a query parameter and not
 * a second address, which is what lets a write return to the list it happened in
 * — and what lets a row being renamed survive the round trip. A list with nothing
 * in it is a data condition of the same fold, not another screen.
 *
 * THERE IS NO DELETE ROUTE, and its absence is the design. Retiring takes a word
 * off the form and off the handsets and leaves it on every record filed under it;
 * nothing here may orphan a case file.
 *
 * FOUR LISTS AND AN AREA CANNOT ADD A FIFTH, so there is no "create list" route
 * either: a list is a question a behaviour block asks, and one arrives with a
 * block, in code.
 *
 * AREA SCOPE IS THE ONE FACT. Every read and every write is confined to the area
 * in the URL: an entry whose uuid belongs to another area is a 404, so no request
 * can reach across into somebody else's vocabulary.
 *
 * A plain class, not AbstractController — a reusable bundle defines its services
 * explicitly, patterned on FrameworkBundle's TemplateController. Registered only
 * under the SecurityBundle guard (see UhifadhiIncidentBundle), because every
 * write rides on the same `incidents.manage` the kinds editor rides on and there
 * is nobody to grant it without a firewall.
 *
 * WHICH MODULE THESE ROUTES BELONG TO, said once for the class. RegistryBundle
 * owns the per-area ledger and closes a parked module's pages before any
 * controller is asked — 404, not 403, because a parked module is not withheld:
 * the area is not running it.
 */
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
final class IncidentAreaListController
{
    /** Naming the words everybody picks from is the same authority as naming the kinds. */
    public const string MANAGE_PERMISSION = IncidentTaxonomyController::MANAGE_PERMISSION;

    /** The token id every write on this screen carries. */
    public const string CSRF_TOKEN_ID = 'incident_area_lists';

    /** The four wire values a route may name, as a route requirement. */
    private const string LIST_PATTERN = 'species|method|land-use|named-place';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly AreaListService $lists,
        private readonly AreaListBoardService $board,
        private readonly AreaListEntryRepository $entries,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/lists',
        name: 'incident_lists',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $this->denyUnlessGranted();

        return new Response($this->twig->render('@UhifadhiIncident/lists/show.html.twig', [
            'area' => $area,
            'panels' => $this->board->forArea($area, $request->query->getString('list')),
            // WHICH ROW IS MID-RENAME, if any — the kinds page's idiom: the label
            // becomes a field in the label's own place, no dialogue.
            'editing' => $this->editingUuid($area, $request->query->getString('rename')),
            // The one page action this screen draws. The way back is the strip,
            // the lit Configure and the crumb — never a button of its own.
            'recordScreens' => $this->authorization->isGranted(IncidentReportController::RECORD_PERMISSION),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/lists/{list}/entries',
        name: 'incident_lists_add',
        requirements: ['uuid' => Requirement::UUID, 'list' => self::LIST_PATTERN],
        methods: ['POST'],
    )]
    public function add(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $list): Response
    {
        $this->guardWrite($request);
        $which = AreaListEnum::from($list);

        try {
            $this->lists->add($area, $which, $request->request->getString('label'), $request->request->getString('note'));
        } catch (AreaListConflictException $e) {
            return $this->refused($request, $area, $which, $e);
        }

        return $this->backToList($area, $which);
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/lists/entries/{entry}/rename',
        name: 'incident_lists_rename',
        requirements: ['uuid' => Requirement::UUID, 'entry' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function rename(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $entry): Response
    {
        $this->guardWrite($request);
        $row = $this->entry($area, $entry);

        try {
            $this->lists->rename($row, $request->request->getString('label'));
        } catch (AreaListConflictException $e) {
            return $this->refused($request, $area, $row->getList(), $e, $row);
        }

        return $this->backToList($area, $row->getList());
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/lists/entries/{entry}/retire',
        name: 'incident_lists_retire',
        requirements: ['uuid' => Requirement::UUID, 'entry' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function retire(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $entry): Response
    {
        $this->guardWrite($request);
        $row = $this->entry($area, $entry);
        $this->lists->retire($row);

        return $this->backToList($area, $row->getList());
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/lists/entries/{entry}/reactivate',
        name: 'incident_lists_reactivate',
        requirements: ['uuid' => Requirement::UUID, 'entry' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function reactivate(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area, string $entry): Response
    {
        $this->guardWrite($request);
        $row = $this->entry($area, $entry);
        $this->lists->reactivate($row);

        return $this->backToList($area, $row->getList());
    }

    // ── the shared machinery ───────────────────────────────────────────────────

    private function entry(AreaOfInterest $area, string $uuid): AreaListEntry
    {
        $entry = $this->entries->findOneByAreaAndUuid($area, $uuid);
        if (null === $entry) {
            throw new NotFoundHttpException('No such word in any of this area\'s lists.');
        }

        return $entry;
    }

    /** The row the query asked to rename, if it is one of this area's. */
    private function editingUuid(AreaOfInterest $area, string $uuid): ?string
    {
        if ('' === $uuid) {
            return null;
        }

        return $this->entries->findOneByAreaAndUuid($area, $uuid)?->getUuid()->toRfc4122();
    }

    /** Post/redirect/get back to the fold the write happened in. */
    private function backToList(AreaOfInterest $area, AreaListEnum $list): RedirectResponse
    {
        return new RedirectResponse($this->router->generate('incident_lists', [
            'uuid' => $area->getUuidString(),
            'list' => $list->value,
        ]));
    }

    /**
     * A refused write flashes why and returns to the fold it happened in, with
     * the row it was about still open for correcting.
     */
    private function refused(
        Request $request,
        AreaOfInterest $area,
        AreaListEnum $list,
        AreaListConflictException $e,
        ?AreaListEntry $entry = null,
    ): RedirectResponse {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $e->getMessage());
        }

        $parameters = ['uuid' => $area->getUuidString(), 'list' => $list->value];
        if (null !== $entry) {
            $parameters['rename'] = $entry->getUuid()->toRfc4122();
        }

        return new RedirectResponse($this->router->generate('incident_lists', $parameters));
    }

    private function guardWrite(Request $request): void
    {
        $this->denyUnlessGranted();
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token for the lists editor.');
        }
    }

    private function denyUnlessGranted(): void
    {
        if (!$this->authorization->isGranted(self::MANAGE_PERMISSION)) {
            throw new AccessDeniedException('Editing this area\'s lists needs "'.self::MANAGE_PERMISSION.'".');
        }
    }
}
