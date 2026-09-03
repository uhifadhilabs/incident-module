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

namespace Uhifadhi\Incident\Tests\Functional;

use Uhifadhi\Incident\Model\IncidentWidgets;
use Uhifadhi\Service\WidgetEndpoint;

/**
 * THE WIDGET LIBRARY, on the HOST's framework.
 *
 * The module ships a catalogue and nothing else — no save endpoint, no merge
 * algebra, no preset mechanics. These tests exercise the real host component end
 * to end through the module's routes, because "it rides the host framework" is a
 * claim that is either demonstrable over HTTP or is not true.
 */
final class WidgetLibraryFlowTest extends FunctionalTestCase
{
    private function url(string $areaUuid, string $suffix = ''): string
    {
        return \sprintf('/areas/%s/modules/incidents/widgets%s', $areaUuid, $suffix);
    }

    /**
     * The token the host's component rendered — scraped off the library page, so
     * the test proves the page carries one a client could actually use.
     */
    private function token(string $areaUuid): string
    {
        $html = $this->client->request('GET', $this->url($areaUuid))->html();
        preg_match('/data-widget-csrf-token="([^"]+)"/', $html, $matches);
        if (!isset($matches[1])) {
            self::fail('The library rendered no CSRF token, so none of its writes could ever be made.');
        }

        return $matches[1];
    }

    public function testTheLibraryDrawsTheFiveDirectionsAsSectionsAndPresets(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        // The five directions incidents was drawn in, as headed sections…
        foreach (['Case files', 'Map first', 'Live feed', 'Category board', 'Status board'] as $direction) {
            self::assertStringContainsString($direction, $html, \sprintf('The "%s" direction is missing.', $direction));
        }
        // …and the composition the module ships with, named rather than left as a
        // generic "Default layout".
        self::assertStringContainsString(IncidentWidgets::DEFAULT_LABEL, $html);
    }

    /**
     * ADOPTING A DIRECTION IS ONE CLICK, and it changes what the dashboard draws.
     * This is the whole point of presets, so it is asserted on the DASHBOARD, not
     * on the library's own state.
     */
    public function testAdoptingADirectionRecomposesTheDashboard(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $before = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertCount(0, $before->filter('[data-w="board"]'), 'The shipped composition has no status board.');

        $this->client->request('POST', $this->url($this->uuidOf($area), '/preset/e'), [
            '_token' => $this->token($this->uuidOf($area)),
        ]);
        self::assertResponseRedirects();

        $after = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        // "Status board": the KPI strip, the rail, the board, ageing and the funnel.
        self::assertCount(1, $after->filter('[data-w="board"]'));
        self::assertCount(1, $after->filter('[data-w="rail"]'));
        self::assertCount(1, $after->filter('[data-w="sla"]'));
        self::assertCount(1, $after->filter('[data-w="funnel"]'));
        // …and the register, which "Status board" does not show, is now absent.
        self::assertCount(0, $after->filter('[data-w="register"]'));
    }

    /** Reset puts the module's own composition back. */
    public function testResettingRestoresTheCompositionTheModuleShipsWith(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->url($this->uuidOf($area), '/preset/c'), [
            '_token' => $this->token($this->uuidOf($area)),
        ]);
        $this->client->request('POST', $this->url($this->uuidOf($area), '/reset'), [
            '_token' => $this->token($this->uuidOf($area)),
        ]);
        self::assertResponseRedirects();

        $dashboard = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertCount(1, $dashboard->filter('[data-w="register"]'));
        self::assertCount(1, $dashboard->filter('[data-w="money"]'));
        self::assertCount(0, $dashboard->filter('[data-w="feed"]'));
    }

    /**
     * ARRANGING ONE AREA LEAVES EVERY OTHER ALONE. The surface is area-scoped and
     * it is stated in the URLs rather than trusted to a check.
     */
    public function testArrangingOneAreaLeavesAnotherUntouched(): void
    {
        $first = $this->anArea('First');
        $second = $this->anArea('Second');
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->url($this->uuidOf($first), '/preset/e'), [
            '_token' => $this->token($this->uuidOf($first)),
        ]);

        $other = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($second)));
        self::assertCount(0, $other->filter('[data-w="board"]'));
        self::assertCount(1, $other->filter('[data-w="register"]'));
    }

    /** A design this surface does not ship is refused, not silently ignored. */
    public function testADesignThisSurfaceDoesNotShipIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->url($this->uuidOf($area), '/preset/nonsense'), [
            '_token' => $this->token($this->uuidOf($area)),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** Every widget write carries a token; without one the host's endpoint refuses. */
    public function testAWriteWithoutATokenIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->url($this->uuidOf($area), '/preset/a'));

        self::assertResponseStatusCodeSame(403);
    }

    /** The library is one person's, so it needs one — anonymous gets nothing. */
    public function testTheLibraryNeedsSomebodySignedIn(): void
    {
        $area = $this->anArea();

        $this->client->request('GET', $this->url($this->uuidOf($area)));

        self::assertResponseStatusCodeSame(401);
    }

    /** The token id is the host's, scoped per surface AND per area. */
    public function testTheTokenIsScopedToThisSurfaceAndThisArea(): void
    {
        $area = $this->anArea();

        self::assertSame(
            'widgets_incidents_'.$this->uuidOf($area),
            WidgetEndpoint::csrfTokenId(IncidentWidgets::SURFACE, $area->getUuid()),
        );
    }
}
