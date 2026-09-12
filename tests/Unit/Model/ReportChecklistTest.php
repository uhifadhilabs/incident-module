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

namespace Uhifadhi\Incident\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\BlockQuestionCatalogue;
use Uhifadhi\Incident\Model\ReportChecklist;

/**
 * WHAT THIS KIND ASKS, as a model the rail renders and nothing else computes.
 *
 * The rail beside the report form names every block the chosen sub-category
 * switched on, each one's defining question, and how many questions are inside
 * it — and then says, in words, that the blocks it did NOT switch on are absent
 * from the form rather than greyed out. All of that is derivable from the same
 * {@see BlockQuestionCatalogue} sets the form itself is drawn from, which is the
 * point: the rail cannot describe a form the page did not render.
 *
 * It informs and never gates. Whether a question has an ANSWER yet is the gate's
 * business, read in the browser from the gate's own state, so nothing here
 * carries a mark.
 */
final class ReportChecklistTest extends TestCase
{
    /**
     * The four blocks a livestock-depredation word switches on.
     *
     * @return list<BehaviorBlockEnum>
     */
    private static function depredationBlocks(): array
    {
        return [
            BehaviorBlockEnum::Species,
            BehaviorBlockEnum::Counts,
            BehaviorBlockEnum::Parties,
            BehaviorBlockEnum::Money,
        ];
    }

    public function testItNamesEveryBlockTheSubCategorySwitchedOnInTheFormsOwnOrder(): void
    {
        $checklist = self::depredation();

        self::assertSame(
            ['Species', 'Counts', 'Parties', 'Money · compensation'],
            array_map(static fn ($row): string => $row->title, $checklist->rows),
        );
    }

    /**
     * EACH ROW CARRIES THE BLOCK'S DEFINING QUESTION — the one the block records
     * nothing without, in the words the gate misses it by. The rail and the File
     * control name the same thing or they disagree about the same form.
     */
    public function testEachRowCarriesTheDefiningAnswerTheGateMissesItBy(): void
    {
        $checklist = self::depredation();

        self::assertSame(
            ['the species', 'one count row', 'a party with a role and a name', 'the loss claimed'],
            array_map(static fn ($row): ?string => $row->definingAnswer, $checklist->rows),
        );
        self::assertSame(
            ['which animal, and what is known about it', 'how many of what'],
            array_map(static fn ($row): string => $row->caption, \array_slice($checklist->rows, 0, 2)),
        );
    }

    /**
     * AND HOW THE FORM DREW IT: the first fold is open and the rest are shut, so
     * a rail row says whether the questions behind it are already on screen.
     */
    public function testTheFirstRowIsOpenAndTheRestAreFolded(): void
    {
        $checklist = self::depredation();

        self::assertSame([true, false, false, false], array_map(static fn ($row): bool => $row->open, $checklist->rows));
        self::assertSame(
            ['3 questions', '2 questions', '5 questions', '1 question'],
            array_map(static fn ($row): string => $row->questionCountLabel, $checklist->rows),
        );
    }

    /** The card's own one-line heading: the word, its blocks and its questions. */
    public function testItCountsItsBlocksAndItsQuestions(): void
    {
        $checklist = self::depredation();

        self::assertSame(4, $checklist->blockCount());
        self::assertSame(11, $checklist->questionCount());
        self::assertSame('4 blocks · 11 questions', $checklist->blocksAndQuestions());
        self::assertSame('livestock depredation', $checklist->subcategoryLabel);
    }

    /**
     * THE OTHER BLOCKS ARE ABSENT, NOT GREYED — and the rail says which ones and
     * says it in words, because "absent" is a promise about the form and a reader
     * cannot check a promise about things they cannot see.
     */
    public function testItNamesTheBlocksTheSubCategoryLeftOffAsAbsentRatherThanGreyed(): void
    {
        $checklist = self::depredation();

        self::assertSame(
            ['method & means', 'seizures', 'condition & disposition', 'samples', 'casualty & treatment', 'extent', 'named place', 'notice & licence'],
            $checklist->absentBlocks,
        );
        self::assertSame('eight', $checklist->absentCountWord());
    }

    /** A word that switched every block on has nothing to call absent. */
    public function testAWordThatSwitchedEveryBlockOnHasNoAbsentNote(): void
    {
        $checklist = ReportChecklist::for('everything', BlockQuestionCatalogue::forBlocks(
            BehaviorBlockEnum::inPickerOrder(),
            MoneyDirectionEnum::Fine,
        ));

        self::assertSame([], $checklist->absentBlocks);
        self::assertNull($checklist->absentCountWord());
    }

    /**
     * A BLOCK THAT HOLDS UP NOTHING IS STILL NAMED, and carries no defining
     * answer — the row informs, and only the rows the gate can miss are counted
     * against it.
     */
    public function testOnlyTheBlocksThatGateAreCountedAgainstTheGate(): void
    {
        $checklist = self::depredation();

        self::assertSame(4, $checklist->gatingCount());
    }

    private static function depredation(): ReportChecklist
    {
        return ReportChecklist::for(
            'livestock depredation',
            BlockQuestionCatalogue::forBlocks(self::depredationBlocks(), MoneyDirectionEnum::Compensation),
        );
    }
}
