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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Model\AreaListPanel;
use Uhifadhi\Incident\Model\BlockAnswers;
use Uhifadhi\Incident\Service\AreaListBoardService;
use Uhifadhi\Incident\Service\AreaListService;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE LISTS EDITOR'S FOUR FOLDS, ASSEMBLED — and above all the two figures on a
 * row, counted off the register rather than off a counter something has to
 * remember to increment.
 */
final class AreaListBoardServiceTest extends IntegrationTestCase
{
    private const string NOW = '2026-09-12 10:00';

    private function board(): AreaListBoardService
    {
        /** @var AreaListBoardService $service */
        $service = static::getContainer()->get('test_public.incident.area_list_board');

        return $service;
    }

    private function lists(): AreaListService
    {
        /** @var AreaListService $service */
        $service = static::getContainer()->get('test_public.incident.area_lists');

        return $service;
    }

    /** @return list<AreaListPanel> */
    private function panels(AreaOfInterest $area, string $open = ''): array
    {
        return $this->board()->forArea($area, $open, new \DateTimeImmutable(self::NOW));
    }

    private function panel(AreaOfInterest $area, AreaListEnum $list, string $open = ''): AreaListPanel
    {
        foreach ($this->panels($area, $open) as $panel) {
            if ($list === $panel->list) {
                return $panel;
            }
        }

        self::fail('No fold for the '.$list->value.' list.');
    }

    /** An incident answering the species question with one word, on one day. */
    private function fileSpecies(AreaOfInterest $area, string $answer, string $when): void
    {
        /** @var IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');

        $reports->file(
            area: $area,
            subcategory: $this->subcategory($area, 'snaring'),
            title: 'Snare line lifted',
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: new \DateTimeImmutable($when),
            severity: IncidentSeverityEnum::Moderate,
            blockAnswers: new BlockAnswers(['species' => ['species' => $answer]]),
        );
    }

    // ── the four folds ────────────────────────────────────────────────────────

    public function testThereAreFourFoldsInTheOrderTheFormAsksThem(): void
    {
        $panels = $this->panels($this->anAreaWithKinds());

        self::assertSame(
            ['species', 'method', 'land-use', 'named-place'],
            array_map(static fn (AreaListPanel $p): string => $p->list->value, $panels),
        );
    }

    public function testTheFirstFoldIsOpenWhenNothingNamesOne(): void
    {
        $panels = $this->panels($this->anAreaWithKinds());

        self::assertTrue($panels[0]->open);
        self::assertSame('open', $panels[0]->stateLabel());
        self::assertFalse($panels[1]->open);
        self::assertSame('closed', $panels[1]->stateLabel());
    }

    public function testTheOpenFoldIsTheOneNamed(): void
    {
        $area = $this->anAreaWithKinds();

        self::assertTrue($this->panel($area, AreaListEnum::LandUse, 'land-use')->open);
        self::assertFalse($this->panel($area, AreaListEnum::Species, 'land-use')->open);
        // A name no list answers to falls back to the first rather than opening none.
        self::assertTrue($this->panel($area, AreaListEnum::Species, 'nonsense')->open);
    }

    public function testAListWithNoWordsSaysSoAndTheOneWithWordsCountsThem(): void
    {
        $area = $this->anAreaWithKinds();
        self::assertSame('empty', $this->panel($area, AreaListEnum::Species)->sizeLabel());

        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        self::assertSame('1 entry', $this->panel($area, AreaListEnum::Species)->sizeLabel());

        $this->lists()->add($area, AreaListEnum::Species, 'Leopard');
        self::assertSame('2 entries', $this->panel($area, AreaListEnum::Species)->sizeLabel());
    }

    public function testTheSummaryLineNamesTheQuestionAndCountsItsReaders(): void
    {
        $panel = $this->panel($this->anAreaWithKinds(), AreaListEnum::Species);

        self::assertStringContainsString('the species question', $panel->summaryLine());
        self::assertStringContainsString('sub-categories', $panel->summaryLine());
        self::assertGreaterThan(0, $panel->use->subcategoryCount());
    }

    // ── the two figures on a row ───────────────────────────────────────────────

    public function testARowCountsTheIncidentsThatAnsweredWithIt(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $this->lists()->add($area, AreaListEnum::Species, 'Leopard');

        $this->fileSpecies($area, 'lion', '2026-09-02 08:00');
        $this->fileSpecies($area, 'lion', '2026-09-11 08:00');
        // Last month: all time counts it, this month does not.
        $this->fileSpecies($area, 'lion', '2026-08-20 08:00');
        $this->fileSpecies($area, 'leopard', '2026-09-04 08:00');
        $this->em->clear();

        $rows = $this->panel($area, AreaListEnum::Species)->rows;
        self::assertSame('lion', $rows[0]->key);
        self::assertSame(2, $rows[0]->thisMonth);
        self::assertSame(3, $rows[0]->allTime);
        self::assertSame('2 this month · 3 all time', $rows[0]->countsLabel());

        self::assertSame('leopard', $rows[1]->key);
        self::assertSame(1, $rows[1]->thisMonth);
        self::assertSame(1, $rows[1]->allTime);
    }

    public function testAWordNothingHasAnsweredWithCountsZeroRatherThanNothing(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->em->clear();

        $rows = $this->panel($area, AreaListEnum::Species)->rows;
        self::assertSame(0, $rows[0]->thisMonth);
        self::assertSame(0, $rows[0]->allTime);
    }

    /**
     * A RECORD FILED BEFORE THE LIST HAD AN EDITOR holds the word itself, so a
     * row counts both readings of it — otherwise an area that starts using the
     * editor would watch every figure it already had drop to zero.
     */
    public function testARowAlsoCountsRecordsThatHoldTheWordRatherThanTheKey(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');

        $this->fileSpecies($area, 'Lion', '2026-09-02 08:00');
        $this->fileSpecies($area, 'lion', '2026-09-03 08:00');
        $this->em->clear();

        self::assertSame(2, $this->panel($area, AreaListEnum::Species)->rows[0]->allTime);
    }

    /** A RETIRED WORD KEEPS THE COUNT IT EARNED, and its date. */
    public function testARetiredRowKeepsItsFiguresAndItsDate(): void
    {
        $area = $this->anAreaWithKinds();
        $serval = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->fileSpecies($area, 'serval', '2026-09-05 08:00');
        $this->lists()->retire($serval, new \DateTimeImmutable('2026-09-10 12:00'));
        $this->em->clear();

        $row = $this->panel($area, AreaListEnum::Species)->rows[0];
        self::assertFalse($row->isActive());
        self::assertSame('2026-09-10', $row->retiredAt?->format('Y-m-d'));
        self::assertSame(1, $row->allTime);
    }

    /** ONE AREA'S FIGURES ARE ITS OWN: a neighbour's incidents never reach them. */
    public function testAnotherAreasIncidentsAreNotCounted(): void
    {
        $area = $this->anAreaWithKinds('Northern Reserve');
        $other = $this->anAreaWithKinds('Southern Reserve');
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $this->fileSpecies($other, 'lion', '2026-09-02 08:00');
        $this->em->clear();

        self::assertSame(0, $this->panel($area, AreaListEnum::Species)->rows[0]->allTime);
    }

    /**
     * AND AN ANSWER TO ANOTHER QUESTION IS NOT AN ANSWER TO THIS ONE. The count
     * reads the block's object at the question's own key, so a method called the
     * same thing as a species never inflates it.
     */
    public function testAnAnswerToAnotherQuestionIsNotCounted(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Method, 'Lion');
        $this->fileSpecies($area, 'lion', '2026-09-02 08:00');
        $this->em->clear();

        self::assertSame(0, $this->panel($area, AreaListEnum::Method)->rows[0]->allTime);
    }
}
