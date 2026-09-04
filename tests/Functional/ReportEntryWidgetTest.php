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

use Uhifadhi\Model\WidgetDom;

/**
 * THE REPORT ENTRY CARD — the dashboard widget whose whole job is starting a
 * filing fast.
 *
 * It is the static twin of the design's `report` widget
 * (incidents.widgets.js → @UhifadhiIncident/dashboard/_w_report.html.twig),
 * so the copy asserted below is quoted from that declaration. The design app is
 * NOT a dependency of this package — CI checks out this repo alone — so fidelity
 * is asserted against the twin's words rather than by reading its file, and a
 * change to one of them is meant to fail here until the other follows.
 *
 * THE CONTAINER RULING is what the last test defends: the dashboard is
 * standalone context, so this card opens the FULL PAGE, never the drawer.
 */
final class ReportEntryWidgetTest extends FunctionalTestCase
{
    private function dashboardUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents', $areaUuid);
    }

    /**
     * Put the card on this person's dashboard the way the library does: a preset
     * of their own, composed and made active in one write. The shipped designs
     * are immutable, which is exactly why a widget that no design composes needs
     * this door.
     *
     * @param array<string, int> $layout widget id => the width it is drawn at
     */
    private function compose(string $areaUuid, array $layout): void
    {
        $library = $this->client->request('GET', $this->dashboardUrl($areaUuid).'/widgets')->html();
        preg_match('/data-widget-csrf-token="([^"]+)"/', $library, $matches);
        if (!isset($matches[1])) {
            self::fail('The library rendered no CSRF token, so this composition could never be written.');
        }

        $widgets = [];
        foreach ($layout as $id => $cols) {
            $widgets[$id] = ['on' => true, 'cols' => $cols];
        }

        $this->client->request(
            'POST',
            $this->dashboardUrl($areaUuid).'/widgets/presets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_'.str_replace('-', '_', strtoupper(WidgetDom::CSRF_HEADER)) => $matches[1]],
            (string) json_encode(['name' => 'Front desk', 'order' => array_keys($layout), 'widgets' => $widgets]),
        );

        // The module's controller turns the framework's 204 into a flash and a
        // redirect back to the library, so the plain-form path works with no
        // JavaScript; a refusal comes back as its own status instead.
        self::assertResponseRedirects(null, null, 'The composition was refused, so nothing below would be testing the widget.');
    }

    /** OFF BY DEFAULT: the header already carries a Report control. */
    public function testTheShippedCompositionDoesNotCarryIt(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->dashboardUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-w="report"]'));
    }

    /** Switched on, it is on the dashboard, at the width the composition names. */
    public function testItRendersOnTheDashboardOnceSomebodyAddsIt(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());
        $this->compose($this->uuidOf($area), ['report' => 6, 'register' => 12]);

        $crawler = $this->client->request('GET', $this->dashboardUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-w="report"]'));
        self::assertSame('6', $crawler->filter('[data-widget-id="report"]')->attr('data-widget-cols'));
    }

    /**
     * THE STATIC TWIN, ELEMENT BY ELEMENT: the index, the heading, the three
     * answers a report cannot be without, and the button's own words.
     */
    public function testItDrawsWhatItsStaticTwinDraws(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());
        $this->compose($this->uuidOf($area), ['report' => 6]);

        $card = $this->client->request('GET', $this->dashboardUrl($this->uuidOf($area)))->filter('[data-w="report"]');

        // The workshop’s own reference for this frame stays in the design files:
        // the tab carries the card’s name and nothing the reader cannot follow.
        self::assertCount(0, $card->filter('.tab .idx'));
        self::assertStringContainsString('File an incident', $card->filter('.tab')->text());
        self::assertStringContainsString(
            'A report is cheap and a verification is expensive.',
            $card->filter('.use')->text(),
        );
        // The ruled quick-file discipline, in the order the flow asks for it.
        $rows = $card->filter('.rln');
        self::assertStringContainsString('What kind', $rows->eq(0)->text());
        self::assertStringContainsString('What happened', $rows->eq(1)->text());
        self::assertStringContainsString('Where', $rows->eq(2)->text());
        self::assertStringContainsString('Report an incident', $card->filter('a.cta')->text());
    }

    /**
     * THE LAST FILING AS CONTEXT — "did the one I just filed land?" is the
     * question somebody filing in a run actually has.
     */
    public function testItNamesTheLastFilingAndOpensIt(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());
        $incident = $this->anIncident($area);
        $this->compose($this->uuidOf($area), ['report' => 6]);

        $card = $this->client->request('GET', $this->dashboardUrl($this->uuidOf($area)))->filter('[data-w="report"]');

        $last = $card->filter('.rln a.mono');
        self::assertCount(1, $last);
        self::assertStringContainsString($incident->getReference(), $last->text());
        self::assertSame(
            \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()),
            $last->attr('href'),
        );
    }

    /** Nothing filed is a fact about the window, not an empty line naming nothing. */
    public function testWithNothingFiledItSaysSoRatherThanNamingNothing(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());
        $this->compose($this->uuidOf($area), ['report' => 6]);

        $card = $this->client->request('GET', $this->dashboardUrl($this->uuidOf($area)))->filter('[data-w="report"]');

        self::assertCount(0, $card->filter('.rln a.mono'));
        self::assertStringContainsString('nothing filed here yet', $card->text());
    }

    /**
     * THE CONTAINER RULING. Filing from the dashboard is NOT filing from a
     * record: nothing on that page is the thing being filed about, so there is
     * nothing for a drawer to keep on screen. The button opens the full page.
     */
    public function testItsButtonOpensTheFullPageAndNeverTheDrawer(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());
        $this->anIncident($area);
        $this->compose($this->uuidOf($area), ['report' => 6]);

        $card = $this->client->request('GET', $this->dashboardUrl($this->uuidOf($area)))->filter('[data-w="report"]');
        self::assertSame(
            \sprintf('/areas/%s/modules/incidents/new', $this->uuidOf($area)),
            $card->filter('a.cta')->attr('href'),
        );

        $flow = $this->client->click($card->filter('a.cta')->link());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $flow->filter('form.ro-form'));
        self::assertCount(0, $flow->filter('.ro-slideover'));
        self::assertCount(0, $flow->filter('.ro-drawer'));
    }
}
