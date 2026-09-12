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

namespace Uhifadhi\Incident\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * THE RAIL'S CHECKLIST AND THE GATE SHARE ONE STATE, and this is the seam.
 *
 * The rail says whether each answer the filing owes has arrived. It must never
 * work that out for itself: a second computation of "is this answered" is a
 * second opinion, and the day the two disagree the rail is telling somebody they
 * may file while the File control refuses to. So each row names the missing
 * answer THE GATE names, on one attribute, and the controller marks the rows from
 * the list it just handed the footer.
 *
 * Nothing that talks HTTP can catch a broken hook: the markup renders, the
 * controller runs, and the marks simply never change. So the names on both sides
 * of the seam are compared AS TEXT — if one has to change it changes in both
 * places at once, which is the point.
 */
final class ReportRailSeamTest extends TestCase
{
    /** The attribute a rail row carries the gate's own missing-answer label on. */
    private const string NEED = 'data-incident-report-need';

    public function testTheRowsNameTheAttributeTheControllerReads(): void
    {
        self::assertStringContainsString(self::NEED, self::template('_checklist'));
        self::assertStringContainsString(self::NEED, self::script());
    }

    /**
     * AND THE THREE SHARED ANSWERS ARE NAMED WITH THE GATE'S OWN WORDS. The gate
     * pushes these strings into the footer's line; the rail's rows are found by
     * the same strings, so a reworded gate cannot leave a row permanently
     * unmarked.
     */
    public function testTheSharedAnswersUseTheGatesOwnWording(): void
    {
        $checklist = self::template('_checklist');
        $script = self::script();

        foreach (['choose a category', 'describe what happened', 'mark where it happened'] as $label) {
            self::assertStringContainsString($label, $checklist, 'The rail must miss an answer by the gate\'s name for it.');
            self::assertStringContainsString($label, $script, 'The gate must miss that answer by the same name.');
        }
    }

    /**
     * THE MARKS, THE PROGRESS AND THE SWAPPED GROUP are the three things the
     * controller writes into the rail, so it names all three.
     */
    public function testTheControllerKnowsEveryHookTheRailShips(): void
    {
        $script = self::script();

        foreach (['rr-q', 'rr-prog', 'still needed', 'asks'] as $hook) {
            self::assertStringContainsString($hook, $script);
        }
        foreach (['rr-q', 'rr-prog', 'still needed'] as $hook) {
            self::assertStringContainsString($hook, self::template('_checklist'));
        }
    }

    /**
     * AND THE RAIL NEVER GATES. It carries no submit control and no disabled
     * attribute: the one File control lives in the footer, and a rail that could
     * refuse a filing would be a second gate.
     */
    public function testTheRailCarriesNoControlThatCouldRefuseAFiling(): void
    {
        foreach (['_rail', '_checklist', '_observation'] as $partial) {
            $markup = self::template($partial);
            self::assertStringNotContainsString('type="submit"', $markup);
            self::assertStringNotContainsString('disabled', $markup);
        }
    }

    private static function template(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/templates/report/'.$name.'.html.twig');
    }

    private static function script(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/incident_report_controller.js');
    }
}
