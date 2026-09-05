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

namespace Uhifadhi\Incident\Tests\Integration\Overview;

use Twig\Environment;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Overview\IncidentOverviewContributor;
use Uhifadhi\Incident\Tests\Integration\OverviewTestCase;

/**
 * THE FIVE PLATES, RENDERED THE WAY THE HOST RENDERS THEM.
 *
 * Each partial is handed ONE map with `with_context: false` — this module's
 * figures under `by.incidents`, beside the shared keys the host puts there —
 * because that is the contract, and a template that quietly relied on an
 * ambient variable would work in a test and render blank on the real page.
 *
 * The assertions are about the DESIGN: the plate's own index, the contributor
 * tag, the five segments of the flow bar, the chips on a row. Where a figure is
 * absent the assertion is that the plate says so rather than printing a zero.
 */
final class OverviewPartialsTest extends OverviewTestCase
{
    public function testTheFlowBarIsGeneratedFromTheStateMachineAndNotWrittenOut(): void
    {
        $html = $this->render('in_flow', $this->aRegister());

        self::assertStringContainsString('data-w="in_flow"', $html);
        // Provenance survives a screenshot: the index prefix and the tag.
        // The workshop’s own reference for this frame stays in the design files.
        self::assertStringNotContainsString("IN\u{00B7}A1", $html);
        self::assertStringContainsString('<span class="ao-by incidents"><i></i>incidents</span>', $html);

        // FIVE SEGMENTS, in workflow order, each numbered by its position — so
        // adding a place to the state machine adds a segment and nothing else.
        foreach (IncidentStatusEnum::ordered() as $index => $place) {
            self::assertStringContainsString(
                \sprintf('class="s%d"', $index + 1),
                $html,
            );
            self::assertStringContainsString(\sprintf('<span class="s">%s</span>', $place->label()), $html);
        }
        self::assertStringContainsString('<span class="n">1</span><span class="s">reported</span>', $html);
        self::assertStringContainsString('<span class="n">0</span><span class="s">closed</span>', $html);
    }

    public function testTheFlowBarNamesEachIncidentPastItsOwnTerm(): void
    {
        $html = $this->render('in_flow', $this->aRegister());

        self::assertStringContainsString('Past their term', $html);
        self::assertStringContainsString('2 · INC-0008 (17 d), INC-0006 (12 d)', $html);
        // The taxonomy's OWN terms, read off the register — never one global SLA.
        self::assertStringContainsString('the term is 72 h for snaring', $html);
    }

    /**
     * ABSENT IS NOT ZERO, on the plate as well as in the figures: an area where
     * nothing has been verified prints an em dash and says what is missing.
     */
    public function testAnUnverifiedAreaDrawsAnEmDashAndSaysWhy(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->installTaxonomy();
        $this->anIncident($area, 'snaring', 'Snare line at the forest edge', self::now()->modify('-2 hours'));

        $html = $this->render('in_flow', $area);

        self::assertStringContainsString('— · nothing in this area has been verified yet', $html);
        self::assertStringNotContainsString('0 h', $html);
    }

    public function testTodaysCardComparesWithTheSameWeekdayAWeekAgo(): void
    {
        $html = $this->render('in_today', $this->aRegister());

        // The workshop’s own reference for this frame stays in the design files.
        self::assertStringNotContainsString("IN\u{00B7}A2", $html);
        self::assertStringContainsString('· sat 22 aug · 3 filed', $html);
        self::assertStringContainsString('<span class="sub">1 on sat 15 aug</span>', $html);
        self::assertStringContainsString('<b class="disp">1<em>closed out</em></b>', $html);
        // A kind nothing was filed under today says so, in the quiet tone — the
        // design draws the empty row rather than dropping it.
        self::assertStringContainsString('none today', $html);
        self::assertStringContainsString('North Gate 2 · West Plains 1', $html);
        // The month is not on this card, and the card says where it is.
        self::assertStringContainsString('This month’s totals live on the module’s own dashboard', $html);
    }

    public function testTheLatestCardDrawsTheModulesOwnChipsOnTheHostsRow(): void
    {
        $html = $this->render('in_recent', $this->aRegister());

        // The workshop’s own reference for this frame stays in the design files.
        self::assertStringNotContainsString("IN\u{00B7}A3", $html);
        self::assertStringContainsString('class="i-hit"', $html);
        self::assertStringContainsString('<span class="id">INC-0003</span>', $html);
        self::assertStringContainsString('class="i-cat mort"', $html);
        self::assertStringContainsString('class="i-st ver"', $html);
        // Open AND past its own term earns the tag; finished work does not.
        self::assertStringContainsString('<span class="i-mtag due">past term</span>', $html);
    }

    public function testTheMoneyCardKeepsTheTwoDirectionsApart(): void
    {
        $html = $this->render('in_money', $this->aRegister());

        // The workshop’s own reference for this frame stays in the design files.
        self::assertStringNotContainsString("IN\u{00B7}A4", $html);
        self::assertStringContainsString('<b class="disp">2.55<em>M</em></b>', $html);
        self::assertStringContainsString('<b class="disp">4.5<em>M</em></b>', $html);
        // Nowhere on the plate are they added: 7.05 would be a number about
        // nothing, since one is owed to the authority and one by it.
        self::assertStringNotContainsString('7.05', $html);
        self::assertStringContainsString('27 d · INC-0007 · North Gate', $html);
        self::assertStringContainsString('1 claim · 1 zone', $html);
    }

    public function testTheMoneyCardDrawsAnEmDashWhereNothingIsOwed(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->installTaxonomy();

        $html = $this->render('in_money', $area);

        self::assertStringContainsString('— · no compensation is outstanding', $html);
    }

    /**
     * A COLUMN MAY ONLY INCLUDE WIDGETS THE MODULE ALREADY CONTRIBUTES ON THEIR
     * OWN — so the column's copy of a card is not a copy at all. The assertion is
     * literally that: the three cards rendered alone, character for character,
     * are inside the column.
     */
    public function testTheColumnIncludesItsCardsRatherThanRestatingThem(): void
    {
        $area = $this->aRegister();
        $column = $this->render('in_column', $area);

        self::assertStringContainsString('<div class="ao-col">', $column);
        self::assertStringContainsString('<i class="incidents"></i>', $column);
        self::assertStringContainsString('7 open · 3 filed today', $column);

        foreach (['in_flow', 'in_today', 'in_money'] as $widget) {
            self::assertStringContainsString(trim($this->render($widget, $area)), $column, $widget);
        }
        // And nothing else: the latest list is a widget of its own and is not
        // silently duplicated into the column.
        self::assertStringNotContainsString('data-w="in_recent"', $column);
    }

    /** The one map the host hands a partial — this module's figures, plus the shared keys. */
    private function render(string $widget, AreaOfInterest $area): string
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $contributor = $this->service('incident.overview.contributor');
        self::assertInstanceOf(IncidentOverviewContributor::class, $contributor);

        $now = self::now();

        return $twig->render(
            \sprintf($contributor->partialPattern(), $widget),
            [
                'area' => $area,
                'now' => $now,
                'by' => ['incidents' => $contributor->context($area, $now)],
            ],
        );
    }
}
