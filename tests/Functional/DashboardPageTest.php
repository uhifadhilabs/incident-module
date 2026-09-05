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

/**
 * THE DASHBOARD, rendered. Every widget the module ships is drawn against real
 * rows here — a template that referenced a variable the model does not carry
 * fails on this test rather than in front of a warden.
 */
final class DashboardPageTest extends FunctionalTestCase
{
    public function testTheShippedCompositionRenders(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // The composition the module ships with: the counts, then where, then
        // what, then the money.
        self::assertCount(1, $crawler->filter('[data-w="kpis"]'));
        self::assertCount(1, $crawler->filter('[data-w="register"]'));
        self::assertCount(1, $crawler->filter('[data-w="map"]'));
        self::assertCount(1, $crawler->filter('[data-w="money"]'));
        // …and nothing else, because a widget that is off is ABSENT.
        self::assertCount(0, $crawler->filter('[data-w="board"]'));
        self::assertSelectorTextContains('h1.pg', 'Incidents');

        /*
         * THE TAB TITLE NAMES THE AREA EXACTLY ONCE.
         *
         * The shell's document composes it as page — place — brand, where the
         * place is the area the request is in, so a page that names the area
         * itself prints it twice ("Sample Area — Incidents — Sample Area —
         * Uhifadhi"). Every screen of this module did, which is what a
         * `layout.html.twig` that composed nothing left behind.
         *
         * The rule is pinned rather than the string, so it holds for any area in
         * any installation.
         */
        self::assertSame(1, substr_count($crawler->filter('title')->first()->text(), $area->getName() ?? ''));
    }

    /**
     * EVERY WIDGET THE MODULE SHIPS renders on real data. The dashboard only
     * draws four of them by default, so this walks the whole catalogue through
     * the widget library, which renders every one at full size.
     */
    public function testEveryWidgetInTheCatalogueRenders(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate');
        $reporter = $this->aReporter();
        $this->anIncident($area, reportedBy: $reporter);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill on the C-road, km 12', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        foreach ([
            'kpis', 'register', 'queue', 'report', 'maplist', 'map', 'zones', 'spark', 'feed',
            'evidence', 'categories', 'matrix', 'money', 'board', 'sla', 'funnel', 'rail',
        ] as $widget) {
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('[data-w="%s"]', $widget))->count(),
                \sprintf('The "%s" widget did not render in the library.', $widget),
            );
        }
    }

    /** An area with nothing filed still gets a whole dashboard, not an error. */
    public function testAnEmptyAreaRendersAWholeDashboard(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-w="kpis"]', '0');
    }

    /**
     * ONE FILTER DRIVES EVERYTHING. Narrowing to a category narrows the register
     * AND the counts, because they are one query read twice.
     */
    public function testACategoryChipNarrowsTheWholePage(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->client->loginUser($reporter);

        $all = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertCount(2, $all->filter('[data-w="register"] table tr')->reduce(
            static fn ($node) => str_contains((string) $node->attr('class'), '') && $node->filter('.i-id')->count() > 0,
        ));

        $narrowed = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?category=poaching', $this->uuidOf($area)));
        self::assertCount(1, $narrowed->filter('[data-w="register"] .i-id'));
        self::assertStringContainsString('Snare line', $narrowed->filter('[data-w="register"]')->text());
    }

    /**
     * THE LENS IS A LENS, NOT A FENCE. Whatever it selects, "Every category" is
     * always one click away and shows the whole register to anybody.
     */
    public function testTheLensBarAlwaysOffersTheWholeRegister(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?lens=ecology', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.i-lensbar a[data-lens="all"]')->count());
        self::assertStringContainsString('A lens, not a fence', $crawler->filter('.i-lensnote')->text());
    }

    /**
     * A DOOR THE VIEWER CANNOT OPEN IS NOT DRAWN.
     *
     * "Report incident" opens the screen that CREATES an incident, and that
     * screen enforces incidents.record in code. The dashboard was deciding
     * whether to draw the control from `incident.record_screens` — a
     * compile-time parameter answering a different question: whether the route
     * EXISTS in this installation, which it does wherever SecurityBundle is
     * registered. So the page asked about the installation and printed the
     * answer as if it were about the person.
     *
     * A control the viewer may not have is ABSENT rather than greyed out: a
     * disabled button tells somebody a screen exists and they are not trusted
     * with it, while a live link that fails tells them nothing until the click
     * is gone.
     */
    public function testTheReportControlIsOfferedOnlyToSomebodyWhoMayFile(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);

        // Staff, signed in, holding neither permission — the shape of the very
        // first person an installation adds after the administrator.
        $this->client->loginUser($this->aUser('bystander@example.test', 'Neema', 'Kimaro'));
        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pgact a[href$="/incidents/new"]'));

        // …and the reporter, who may, is handed it.
        $this->client->loginUser($this->aReporter());
        $offered = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertCount(1, $offered->filter('.pgact a[href$="/incidents/new"]'));
    }

    /**
     * THE SAME QUESTION INSIDE A WIDGET. The report entry card carries the same
     * door, and it may not answer differently from the header above it.
     */
    public function testTheReportCardsControlAsksTheSameQuestion(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aUser('bystander@example.test', 'Neema', 'Kimaro'));

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/incidents/new"]'));
    }
}
