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

use Uhifadhi\Incident\Enum\IncidentSeverityEnum;

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
            'kpis', 'register', 'queue', 'report', 'maplist', 'map', 'zones', 'trend', 'bycat',
            'severity', 'spark', 'feed', 'evidence', 'categories', 'matrix', 'money', 'board',
            'sla', 'funnel', 'rail',
        ] as $widget) {
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('[data-w="%s"]', $widget))->count(),
                \sprintf('The "%s" widget did not render in the library.', $widget),
            );
        }
    }

    /**
     * THE PORTED WIDGET MARKUP MATCHES THE DESIGN. Each of these is a design
     * element the app had drifted from — the KPI strip's own grid, the matrix's
     * heat cells / expander / grand-total row, the map+results docked .i-hit list,
     * and the status board's card footer. Rendered through the library, which
     * draws every widget at full size on real rows.
     */
    public function testThePortedWidgetMarkupMatchesTheDesign(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate');
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // The KPI strip carries its own equal-columns grid class.
        self::assertGreaterThan(0, $crawler->filter('[data-w="kpis"].kstrip')->count());

        // The matrix draws heat cells with a status caption, a sub-category
        // expander on each row header, and a grand-total row.
        self::assertGreaterThan(0, $crawler->filter('[data-w="matrix"] a.cell em')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="matrix"] .i-mxrow .i-mxexp')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="matrix"] tr.tot .cell')->count());

        // The map+results list is a docked .i-hit list under an "In this view" head.
        self::assertGreaterThan(0, $crawler->filter('[data-w="maplist"] .i-listhd')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="maplist"] a.i-hit .r1 .id')->count());

        // The status board's card footer is the .ft row with the zone chip.
        self::assertGreaterThan(0, $crawler->filter('[data-w="board"] .i-card .ft .i-zone')->count());
    }

    /**
     * AN OVERVIEW CARD NEVER GROWS WITH THE DATA. The list widgets cap to the
     * latest that fits and say "N of M" — the feed at 14, the queue at 8 — and the
     * status board closes a deep column with a "… and N more" ghost rather than
     * running down the page. (Register and map+results already cap at 14; evidence
     * and SLA are capped in the service.).
     */
    public function testOverviewListWidgetsDoNotGrowWithTheData(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        // Sixteen reported-by-me incidents: past every cap (feed 14, queue 8,
        // board column 6) and all in the one "reported" column.
        for ($i = 0; $i < 16; ++$i) {
            $this->anIncident($area, 'snaring', \sprintf('Snare line %d lifted at the forest edge', $i), $reporter);
        }
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // Feed: the latest 14, grouped by day, and it says "latest 14 of 16".
        $feed = $crawler->filter('[data-w="feed"]')->first();
        self::assertCount(14, $feed->filter('a.i-feed'));
        self::assertStringContainsString('latest 14 of 16', $feed->filter('.tab')->text());

        // Queue: the oldest 8, and it says "8 of 16".
        $queue = $crawler->filter('[data-w="queue"]')->first();
        self::assertCount(8, $queue->filter('.i-queuerow'));
        self::assertStringContainsString('8 of 16', $queue->filter('.tab')->text());

        // Board: the reported column shows 6 cards and a "… and 10 more" ghost;
        // the header count stays the true total.
        $board = $crawler->filter('[data-w="board"]')->first();
        self::assertGreaterThan(0, $board->filter('.i-card.ghost')->count());
        self::assertStringContainsString('and 10 more', $board->text());
    }

    /**
     * THE MAP IS THE ATLAS'S PLATE, AND ITS LEGEND SWITCHES ITS LAYERS.
     *
     * The plate — the control stack, the floating legend, fullscreen — is drawn
     * by the atlas and styled by its map.css, which this module's base template
     * must LINK or the controls render invisible. This module states the layers:
     * one per category, plus the area's zones, each row naming what it switches.
     */
    public function testTheMapIsThePlatesAndItsLegendSwitchesItsLayers(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // The atlas's one map stylesheet is linked, so the plate, its chrome and
        // its legend are styled rather than invisible. The stem, not the
        // filename: AssetMapper content-digests what a bundle's public/ dir
        // serves, exactly as it does in an installation.
        self::assertGreaterThan(0, $crawler->filter('link[href*="atlas/map"]')->count());

        // The plate itself, wearing the atlas's one map controller.
        $plate = $crawler->filter('[data-w="map"] .map-plate');
        self::assertCount(1, $plate);
        self::assertSame('uhifadhi--atlas-bundle--map-plate', $plate->attr('data-controller'));

        // The legend the plate rendered: a row per layer, each a real switch.
        self::assertGreaterThan(0, $crawler->filter('[data-w="map"] .map-legend .lay')->count());
        self::assertStringContainsString('Zones', $crawler->filter('[data-w="map"] .map-legend')->text());
    }

    /**
     * THE THREE MAP-FIRST CHARTS DRAW THEIR SVG FROM REAL ROWS. Each is data-
     * driven — the trend line plots a point, the category donut draws an arc, and
     * the severity bars draw a rectangle — so a widget that hard-coded the design's
     * numbers, or referenced a figure the model does not carry, fails here.
     */
    public function testTheMapFirstChartsDrawFromData(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/widgets', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // The trend line is a chart card holding a line path.
        self::assertGreaterThan(0, $crawler->filter('[data-w="trend"] svg.ch path.ln')->count());
        // The category donut draws one arc per kind filed this month, over a total.
        self::assertGreaterThan(0, $crawler->filter('[data-w="bycat"] svg.ch circle.arc')->count());
        self::assertStringContainsString('2', $crawler->filter('[data-w="bycat"] text.big')->text());
        // The severity bars draw one rectangle per level, and the model now has
        // four — every level present even at zero, so the bars never collapse.
        // (The widgets page renders the widget twice: library preview + grid.)
        self::assertCount(4, $crawler->filter('[data-w="severity"]')->first()->filter('svg.ch rect'));
        // Every chart is an equal-height card in the Map first grid.
        self::assertGreaterThan(0, $crawler->filter('[data-w="trend"].chartcard')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="bycat"].chartcard')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-w="severity"].chartcard')->count());
    }

    /**
     * THE SEVERITY CHIP WEARS ITS LEVEL'S CLASS. A critical incident renders the
     * new top step — `.i-sev.crit` labelled "critical" — proving the register
     * reads all four levels, not the old three.
     */
    public function testTheRegisterChipShowsTheCriticalLevel(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter, IncidentSeverityEnum::Critical);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-w="register"] .i-sev.crit'));
        self::assertSame('critical', $crawler->filter('[data-w="register"] .i-sev.crit')->text());
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
     * THE SEARCH BOX DRIVES THE SAME ONE QUERY. Typing a word narrows the
     * register the way a category does — the box is server-side, wired through
     * the filter, not a client-side hide.
     */
    public function testTheSearchBoxNarrowsTheWholePage(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->anIncident($area, 'snaring', 'Snare line lifted at the Acacia Wood forest edge', $reporter);
        $this->client->loginUser($reporter);

        // The box itself is on the page, once per reading of the register.
        $all = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertGreaterThan(0, $all->filter('.i-filters input[name="q"]')->count());

        $narrowed = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?q=goats', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $narrowed->filter('[data-w="register"] .i-id'));
        self::assertStringContainsString('goats', $narrowed->filter('[data-w="register"]')->text());
        // The box keeps what was typed, so a person sees their own query.
        self::assertSame('goats', $narrowed->filter('.i-filters input[name="q"]')->first()->attr('value'));
    }

    /**
     * THE FILTER BAR IS FOUR DROPDOWNS — category, status, zone and month — plus
     * the search box. The category dropdown collapses the all/kind chips (with hue
     * dots and counts) into one; each option is a REAL link driving the one query.
     */
    public function testTheFilterBarIsFourDropdowns(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();

        // No bare select — the bar is dropdowns. Four of them (register instance).
        self::assertCount(0, $crawler->filter('.i-filters select[name="category"]'));
        $register = $crawler->filter('[data-w="register"] .i-filters')->first();
        self::assertCount(4, $register->filter('.i-dd'));

        // Category dropdown: an "all" option plus one hue-dot option per kind, each
        // a real link carrying its count.
        self::assertGreaterThan(0, $register->filter('.i-dd .i-ddmenu a.i-ddopt .i-dot.poach')->count());
        self::assertGreaterThan(0, $register->filter('.i-dd a.i-ddopt[href*="category=poaching"]')->count());
        // Status and month options drive their own params.
        self::assertGreaterThan(0, $register->filter('.i-dd a.i-ddopt[href*="status="]')->count());
        self::assertGreaterThan(0, $register->filter('.i-dd a.i-ddopt[href*="month="]')->count());
        // A zone dropdown is present (its options are the zones that have incidents).
        self::assertCount(1, $register->filter('.i-ddmenu[aria-label="Filter by zone"]'));
        // The search box is preserved.
        self::assertGreaterThan(0, $register->filter('.i-search input[name="q"]')->count());
    }

    /**
     * THE DROPDOWNS DRIVE REAL FILTERING. Selecting a status, a zone or a month is
     * an ordinary link to the one query — the register re-queries server-side, the
     * same as the category dropdown, and the trigger then shows the active choice.
     */
    public function testTheDropdownsFilterForReal(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->client->loginUser($reporter);

        // The "reported" status option's own link narrows the register — the seeded
        // incident is reported, so it stays; the trigger then reads "reported".
        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        $statusHref = $crawler->filter('[data-w="register"] .i-dd a.i-ddopt[href*="status=reported"]')->first()->attr('href');
        $byStatus = $this->client->request('GET', (string) $statusHref);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $byStatus->filter('[data-w="register"] .i-id')->count());
        self::assertStringContainsString('reported', $byStatus->filter('[data-w="register"] .i-filters')->first()->text());

        // A month with nothing filed shows an empty register — the month param is real.
        $empty = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?month=2020-01', $this->uuidOf($area)));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $empty->filter('[data-w="register"] .i-id'));
        self::assertStringContainsString('january 2020', $empty->filter('[data-w="register"] .i-filters')->first()->text());

        // The zone param is wired: the trigger reflects it even where geometry left
        // the register empty, proving the dropdown drives ?zone=.
        $byZone = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?zone=%s', $this->uuidOf($area), rawurlencode('Highland Ward')));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Highland Ward', $byZone->filter('[data-w="register"] .i-filters')->first()->text());
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
        // "Every category" is present whatever the lens, so nothing is fenced off.
        self::assertGreaterThan(0, $crawler->filter('.i-lensbar a[data-lens="all"]')->count());
        self::assertSame('Every category', $crawler->filter('.i-lensbar a[data-lens="all"]')->text());
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

    /**
     * THE GRADUATED HEADER — four actions, in the design's order: Kinds &
     * sub-categories · Widget library · Export · Report incident.
     *
     * A manager holds both tiers, so every door is drawn; the test pins the ORDER,
     * because the design reads left to right and a header that had them all but
     * shuffled would not be this design.
     */
    public function testTheHeaderRendersAllFourGraduatedActionsInOrder(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('.pgact');
        self::assertCount(1, $actions->filter('a[href$="/incidents/taxonomy"]'));
        self::assertCount(1, $actions->filter('a[href$="/incidents/widgets"]'));
        self::assertGreaterThan(0, $actions->filter('a[href*="/incidents/export.csv"]')->count());
        self::assertCount(1, $actions->filter('a[href$="/incidents/new"]'));

        $html = $actions->html();
        $order = [
            strpos($html, '/incidents/taxonomy'),
            strpos($html, '/incidents/widgets'),
            strpos($html, '/incidents/export.csv'),
            strpos($html, '/incidents/new'),
        ];
        self::assertSame($order, array_values(array_filter($order, static fn ($p) => false !== $p)));
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'The four header actions are not in the design order.');
    }

    /**
     * THE TAXONOMY DOOR IS THE MANAGE TIER'S. It rides on incidents.manage, so a
     * reporter — who may file but not manage — is handed no link into it, exactly
     * as the Report door is absent for somebody who may not file. The Export and
     * Widget-library doors stay, because neither is the manage tier's.
     */
    public function testTheTaxonomyLinkIsOfferedOnlyToSomebodyWhoMayManage(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);

        $this->client->loginUser($this->aReporter());
        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pgact a[href$="/incidents/taxonomy"]'));
        // …but the ungated doors are still there.
        self::assertGreaterThan(0, $crawler->filter('.pgact a[href*="/incidents/export.csv"]')->count());
        self::assertCount(1, $crawler->filter('.pgact a[href$="/incidents/widgets"]'));

        // …and the manager, who may, is handed it.
        $this->client->loginUser($this->aManager());
        $offered = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));
        self::assertCount(1, $offered->filter('.pgact a[href$="/incidents/taxonomy"]'));
    }

    /**
     * THE EXPORT DOOR CARRIES THE CURRENT FILTER. Narrow the register and the
     * download link narrows with it, so the file a person gets is the register they
     * were looking at — one filter, on the page and in the file alike.
     */
    public function testTheExportLinkCarriesTheCurrentFilter(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents?category=poaching&q=snare', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        $href = (string) $crawler->filter('.pgact a[href*="/incidents/export.csv"]')->first()->attr('href');
        self::assertStringContainsString('category=poaching', $href);
        self::assertStringContainsString('q=snare', $href);
    }
}
