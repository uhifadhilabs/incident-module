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
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;
use UhifadhiLabs\Incident\Model\IncidentFilter;
use UhifadhiLabs\Incident\Model\IncidentWidgets;
use UhifadhiLabs\Incident\Repository\IncidentCategoryRepository;
use UhifadhiLabs\Incident\Service\IncidentDashboardService;
use UhifadhiLabs\Incident\Service\IncidentTransitionToken;
use UhifadhiLabs\Incident\Service\IncidentWidgetUrls;

/**
 * THE WIDGET LIBRARY for the incidents surface — the one editing screen.
 *
 * The PAGE is chrome; everything inside it is the host's shared preset component
 * (templates/widgets/_library.html.twig), handed this surface's catalogue, this
 * surface's partial name and this AREA's routes. There are no incident-specific
 * widget mechanics anywhere, which is the whole point of riding the host's
 * framework: adopting a preset here works exactly as it does on departments,
 * team and zones.
 *
 * THE FIVE DIRECTIONS ARE THE HEADED SECTIONS and the presets. A person adopts
 * one, copies it, and mixes a widget from another into the copy — built-ins are
 * immutable and the component offers "make a copy to customize" rather than
 * letting anything fork behind their back.
 *
 * Every write is CSRF-checked and answered by {@see WidgetEndpoint}: this
 * controller validates nothing itself, mints no token and chooses no status code.
 *
 * REGISTERED ONLY WHERE THE HOST RUNS SECURITY. A layout belongs to a PERSON, so
 * without a signed-in user there is nothing to read or write — a host in that
 * state gets no library at all rather than a screen that edits nobody's
 * preferences.
 */
final class IncidentWidgetsController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly IncidentDashboardService $dashboard,
        private readonly IncidentCategoryRepository $categories,
        private readonly WidgetService $widgets,
        private readonly IncidentWidgetUrls $widgetUrls,
        private readonly IncidentTransitionToken $transitionToken,
        private readonly WidgetEndpoint $endpoint,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/incidents/widgets',
        name: 'incident_widgets',
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    public function library(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $catalog = IncidentWidgets::catalog();
        $userId = $this->endpoint->userId();
        $areaUuid = $area->getUuid();
        $now = new \DateTimeImmutable();
        $filter = IncidentFilter::fromRequest($request, $area, $this->categories->allInOrder(), ...IncidentController::monthRange($now));

        return new Response($this->twig->render('@UhifadhiLabsIncident/dashboard/widgets.html.twig', [
            'area' => $area,
            // The preset component, whole, over this surface's catalogue and this
            // AREA's routes.
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId, $areaUuid),
            'active' => $this->widgets->activeRef($catalog, $userId, $areaUuid),
            'widgets' => $this->widgets->resolve($catalog, $userId, $areaUuid),
            'partial' => '@UhifadhiLabsIncident/dashboard/_w_%s.html.twig',
            // EVERY widget partial renders the REAL widget on REAL data here, at
            // full size — the picture of a widget IS the widget, so what you
            // arrange is exactly what you get.
            'widgetContext' => [
                'area' => $area,
                'now' => $now,
                'dashboard' => $this->dashboard->build($filter, $now, $this->viewer()),
                'filter' => $filter,
                'recordScreens' => true,
                'widgetScreens' => true,
                'transitionCsrfToken' => $this->transitionToken->forArea($area),
            ],
            'urls' => $this->widgetUrls->forArea($area),
            'csrfToken' => $this->endpoint->csrfToken($catalog, $areaUuid),
        ]));
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/save', name: 'incident_widgets_save', requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->endpoint->save($request, IncidentWidgets::catalog(), $area->getUuid());
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/reset', name: 'incident_widgets_reset', requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function reset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->reset($request, IncidentWidgets::catalog(), $area->getUuid()),
            \sprintf('This area’s incidents dashboard is back to “%s”.', IncidentWidgets::DEFAULT_LABEL),
        );
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/preset/{presetId}', name: 'incident_widgets_preset', requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 2)]
    public function applyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        $catalog = IncidentWidgets::catalog();
        // A design the surface does not ship is refused by the endpoint below;
        // naming it in the flash is only for the case where it IS shipped.
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s incidents dashboard now follows “%s”.', null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/preset/{presetId}/copy', name: 'incident_widgets_preset_copy', requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 3)]
    public function copyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetId,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->copyPreset($request, IncidentWidgets::catalog(), $presetId, $area->getUuid()),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/presets', name: 'incident_widgets_preset_create', requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function createPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->createCustomPreset($request, IncidentWidgets::catalog(), $area->getUuid()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/presets/{presetUuid}/apply', name: 'incident_widgets_preset_apply', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function applyCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyCustomPreset($request, IncidentWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Your design is on.',
        );
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/presets/{presetUuid}/rename', name: 'incident_widgets_preset_rename', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function renameCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->renameCustomPreset($request, IncidentWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
        );
    }

    #[Route('/areas/{uuid}/modules/incidents/widgets/presets/{presetUuid}/delete', name: 'incident_widgets_preset_delete', requirements: ['uuid' => Requirement::UUID, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function deleteCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->deleteCustomPreset($request, IncidentWidgets::catalog(), Uuid::fromString($presetUuid), $area->getUuid()),
            'Design deleted. Your dashboard is back on the one this module ships with.',
        );
    }

    /**
     * A refused write is returned as it came (the library's fetch() reads the
     * status and the message); a successful one says so and goes back to the
     * library, so the plain-form path works with no JavaScript at all.
     */
    private function afterWrite(Request $request, AreaOfInterest $area, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate('incident_widgets', ['uuid' => $area->getUuidString()]));
    }

    private function viewer(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}
