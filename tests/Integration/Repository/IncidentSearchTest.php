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

namespace Uhifadhi\Incident\Tests\Integration\Repository;

use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE SEARCH BOX ON THE REGISTER. The filter row carries a free-text query the
 * way the patrols module's does; it narrows the SAME one query the map, the
 * register and the charts all read. What a person types is matched against the
 * things they can see on a row — the reference (its id), the title (its name),
 * the narrative (what was reported) and the category it was filed under — so a
 * search that finds nothing is an honest empty register, never a wrong one.
 */
final class IncidentSearchTest extends IntegrationTestCase
{
    private function incidents(): IncidentRepository
    {
        /** @var IncidentRepository $incidents */
        $incidents = $this->service('incident.repository');

        return $incidents;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTaxonomy();
    }

    /** @return list<string> the titles the search returned, so order and set are both asserted */
    private function titlesMatching(string $query, \Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest $area): array
    {
        $filter = new IncidentFilter(area: $area, search: $query);

        return array_map(
            static fn ($incident) => $incident->getTitle(),
            $this->incidents()->findFiltered($filter),
        );
    }

    /** A search on the TITLE narrows to the incidents whose name carries the words. */
    public function testSearchMatchesTheTitle(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, title: 'Lion took four goats at Riverside');
        $this->anIncident($area, subcategory: 'snaring', title: 'Wire trap by the ford');

        self::assertSame(['Lion took four goats at Riverside'], $this->titlesMatching('goats', $area));
    }

    /** A search on the REFERENCE narrows to the one incident that carries that id. */
    public function testSearchMatchesTheReference(): void
    {
        $area = $this->anArea();
        $one = $this->anIncident($area, title: 'First filing');
        $this->anIncident($area, subcategory: 'snaring', title: 'Second filing');

        self::assertSame(['First filing'], $this->titlesMatching($one->getReference(), $area));
    }

    /** A search on the CATEGORY it was filed under finds every incident of that kind. */
    public function testSearchMatchesTheCategoryLabel(): void
    {
        $area = $this->anArea();
        // 'snaring' lives under the "Poaching & wildlife crime" category.
        $this->anIncident($area, subcategory: 'snaring', title: 'Snare line lifted');
        // 'livestock-depredation' lives under "Human–wildlife conflict".
        $this->anIncident($area, subcategory: 'livestock-depredation', title: 'Goats taken overnight');

        self::assertSame(['Snare line lifted'], $this->titlesMatching('poaching', $area));
    }

    /** A search on the NARRATIVE — what was actually reported — finds it too. */
    public function testSearchMatchesTheNarrative(): void
    {
        $area = $this->anArea();
        /** @var IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');
        $reports->file(
            area: $area,
            subcategory: $this->subcategory('snaring'),
            title: 'Report with detail',
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: new \DateTimeImmutable('2026-08-20 05:41:00'),
            narrative: 'Fresh footprints found near the eastern fence line.',
        );
        $this->anIncident($area, subcategory: 'livestock-depredation', title: 'Unrelated filing');

        self::assertSame(['Report with detail'], $this->titlesMatching('footprints', $area));
    }

    /** Search is case-insensitive: a ranger types however they type. */
    public function testSearchIsCaseInsensitive(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, subcategory: 'snaring', title: 'Snare line lifted');

        self::assertSame(['Snare line lifted'], $this->titlesMatching('POACHING', $area));
    }

    /** A query that matches nothing returns an empty register, not everything. */
    public function testSearchThatMatchesNothingReturnsNothing(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, title: 'Lion took four goats');

        self::assertSame([], $this->titlesMatching('helicopter', $area));
    }
}
