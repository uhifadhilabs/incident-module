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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Service\IncidentListService;

/**
 * THE FULL LIST — every incident the window holds, one page at a time, driven by
 * one GET: the filter row's chips, the search box and the pager all carry the
 * same question, and the count in the caption is the count of the rows under it.
 */
final class IncidentListPageTest extends FunctionalTestCase
{
    private function url(AreaOfInterest $area, string $query = ''): string
    {
        return \sprintf('/areas/%s/modules/incidents/incidents%s', $this->uuidOf($area), '' === $query ? '' : '?'.$query);
    }

    public function testItListsEveryIncidentWithTheDesignsColumns(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['id', 'category', 'what happened', 'zone', 'status', 'severity', 'money', 'reported', ''],
            $crawler->filter('table.tbl th')->each(static fn (Crawler $th): string => trim($th->text())),
        );
        self::assertCount(1, $crawler->filter('table.tbl tr a.open-btn'));
    }

    /** The shell draws the strip from this module's declaration, list tab lit. */
    public function testTheShellDrawsTheModulesTwoDataPlaces(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertSame(
            ['Overview', 'Incidents'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Incidents', trim($crawler->filter('.atabs a.on')->text()));
    }

    /** The same filter row the dashboard wears, with the lens among its chips. */
    public function testTheListWearsTheSameFilterRowAsTheDashboard(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        $row = $crawler->filter('.lfilt')->first();
        self::assertCount(5, $row->filter('.i-dd'));
        self::assertCount(1, $row->filter('.i-ddmenu[aria-label="Order by department lens"]'));
        self::assertCount(1, $row->filter('.lsearch input[name="q"]'));
        // Every option re-queries THIS page, never the dashboard.
        self::assertStringContainsString('/modules/incidents/incidents', (string) $row->filter('a.i-ddopt')->first()->attr('href'));
    }

    /** Twenty rows a page, and the pager carries the rest of the answer. */
    public function testAPageHoldsTwentyRowsAndThePagerCarriesTheRest(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        for ($i = 0; $i < IncidentListService::PER_PAGE + 3; ++$i) {
            $this->anIncident($area, 'livestock-depredation', 'Filed report number '.$i, $reporter);
        }
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertCount(IncidentListService::PER_PAGE, $crawler->filter('table.tbl tr a.open-btn'));
        self::assertStringContainsString('1–20 of 23', $crawler->filter('.pgr .cnt')->text());

        $crawler = $this->client->request('GET', $this->url($area, 'page=2'));

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('table.tbl tr a.open-btn'));
    }

    /** The search narrows the list, and the caption counts what it narrowed to. */
    public function testTheSearchNarrowsTheListAndItsCount(): void
    {
        $area = $this->anArea();
        $reporter = $this->aReporter();
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats at Riverside', $reporter);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill on the C-road, km 12', $reporter);
        $this->client->loginUser($reporter);

        $crawler = $this->client->request('GET', $this->url($area, 'q=zebra'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.tbl tr a.open-btn'));
        self::assertStringContainsString('1 incident', $crawler->filter('.c > .tab')->first()->text());
    }

    /** An empty answer says so rather than drawing an empty table. */
    public function testAnAnswerWithNoRowsSaysSo(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area, 'q=nothing-here-at-all'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing matches this filter', $crawler->filter('table.tbl')->text());
    }

    /**
     * THE WORD "REGISTER" IS NOWHERE ON THE SURFACE — not in the address, not in
     * the title, not in a heading.
     */
    public function testNothingOnThePageSaysRegister(): void
    {
        $area = $this->anArea();
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->url($area));

        self::assertStringNotContainsStringIgnoringCase(
            'register',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
