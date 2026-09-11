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

namespace Uhifadhi\Incident\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Incident\Controller\IncidentSettingsController;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Service\IncidentSettingsService;

/**
 * WHAT IS ON THE INCIDENTS CONFIGURE PAGE — three sections, in the order the
 * platform rules and not the order written here: the library, the kinds, the
 * numbers.
 *
 * TWO OF THEM KEEP AN ADDRESS OF THEIR OWN, and the design is why. The widget
 * library and the incident kinds are each a full screen in the settled design —
 * `widgets.html` and `kinds.html`, each with its own URL, wearing the configure
 * page's heading and the configure page's strip — so they are declared as
 * {@see ConfigurationSection::screen()} rather than bodies the shell renders.
 * There is a second reason and it is the harder one: `sections()` is consulted
 * on EVERY page of this module, and the library's body is assembled from the
 * month's incidents, the map, the viewer's presets and a token. Handing that
 * over as a rendered section's variables would build a whole dashboard preview
 * on every request this module serves.
 *
 * ITS ONE RENDERED SECTION IS BUILT ONLY WHERE IT IS DRAWN. For the same reason,
 * the Settings section's variables are gathered only when the request IS the
 * shell's configure page; everywhere else the section is declared with its label
 * alone, which is all the strip needs.
 *
 * IT RESOLVES THE REQUEST ITSELF, like every other source in the frame: the
 * shell passes nothing, because it has a slug and not an area.
 */
final readonly class IncidentConfigurationSections implements ConfigurationSectionsInterface
{
    /** The shell's own configure route, which is where a section body is drawn. */
    private const string CONFIGURE_ROUTE = 'shell_module_configure';

    public function __construct(
        private RequestStack $requests,
        private AreaOfInterestRepository $areas,
        private IncidentSettingsService $settings,
        private TaxonomyKindRepository $kinds,
        /*
         * NULL WHERE THE INSTALLATION RUNS NO SECURITY — and there the Settings
         * form has no route to post to either, so the section renders as a
         * reading of what the area runs on rather than a form that cannot save.
         */
        private ?CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function slug(): string
    {
        return IncidentModuleProvider::SLUG;
    }

    /**
     * The whole heading, to which the shell adds " · configure". Empty when the
     * request names no area — a page the configure route never serves, but a
     * source that answers only on the pages it expects is a source that throws
     * on the one it did not.
     */
    public function heading(): string
    {
        $area = $this->currentArea();

        return null === $area ? '' : $area->getName().' — Incidents';
    }

    public function summary(): string
    {
        return 'Everything this module is set up with in this area, in one place: how its dashboard is composed, '
            .'the kinds a case is filed under, and what this area counts money in.';
    }

    public function sections(): array
    {
        return [
            ConfigurationSection::screen(
                ConfigurationSection::WIDGETS,
                'Widget library',
                'incident_widgets',
            ),
            // THE WORD IS THIS MODULE'S. The shell prints "Incident kinds"
            // because this line says so; the frame has no vocabulary to impose.
            ConfigurationSection::screen(
                'kinds',
                'Incident kinds',
                'incident_kinds',
            ),
            ConfigurationSection::page(
                ConfigurationSection::SETTINGS,
                'Settings',
                '@UhifadhiIncident/configure/_settings.html.twig',
                $this->settingsVariables(),
            ),
        ];
    }

    /**
     * What the Settings body is given — and nothing at all unless the viewer is
     * on the page that draws it.
     *
     * @return array<string, mixed>
     */
    private function settingsVariables(): array
    {
        $request = $this->requests->getCurrentRequest();
        $area = $this->currentArea();

        if (null === $request || null === $area || self::CONFIGURE_ROUTE !== $request->attributes->get('_route')) {
            return [];
        }

        $kinds = $this->kinds->forArea($area);

        return [
            'area' => $area,
            'currency' => $this->settings->currencyFor($area),
            'currencies' => IncidentSettingsService::CURRENCIES,
            'areaChose' => $this->settings->areaChose($area),
            'kinds' => $kinds,
            'subcategoryCount' => array_sum(array_map(
                static fn (TaxonomyKind $kind): int => \count($kind->getSubcategories()),
                $kinds,
            )),
            'csrfToken' => $this->csrfTokenManager?->getToken(IncidentSettingsController::CSRF_TOKEN_ID)->getValue() ?? '',
        ];
    }

    private function currentArea(): ?AreaOfInterest
    {
        $uuid = $this->requests->getCurrentRequest()?->attributes->get('uuid');

        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
