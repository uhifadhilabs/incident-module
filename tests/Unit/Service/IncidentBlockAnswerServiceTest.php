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

namespace Uhifadhi\Incident\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\IncidentBlockAnswerService;

/**
 * WHAT THE FORM POSTED, READ AGAINST THE CATALOGUE — and the gate the File
 * control obeys, decided here rather than in the browser.
 *
 * The browser says the same thing sooner. It is not the authority: a filing that
 * reaches the endpoint without a block's defining answer is refused whatever the
 * page did, which is the only version of the rule that cannot be walked around.
 */
final class IncidentBlockAnswerServiceTest extends TestCase
{
    /** Only the blocks the sub-category switched on, and only the keys they ask. */
    public function testItKeepsWhatTheBlocksAskAndDropsEverythingElse(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Species]);

        $answers = self::service()->read($subcategory, [
            'species' => ['species' => ' Lion ', 'sex' => 'female', 'invented' => 'nothing asks this'],
            // A block that is OFF has no questions on this form, so it has no
            // answers either — whatever was posted under its name.
            'notice' => ['permit_status' => 'valid'],
        ]);

        self::assertSame(['species' => ['species' => 'Lion', 'sex' => 'female']], $answers->values);
        self::assertSame('Lion', $answers->single(BehaviorBlockEnum::Species, 'species'));
        self::assertSame([], $answers->forBlock(BehaviorBlockEnum::Notice));
    }

    /** An empty answer is not an answer: it is not stored at all. */
    public function testAnEmptyAnswerIsNotStored(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Species]);

        $answers = self::service()->read($subcategory, [
            'species' => ['species' => '', 'sex' => '   ', 'age_class' => 'adult'],
        ]);

        self::assertSame(['species' => ['age_class' => 'adult']], $answers->values);
    }

    /** A row that repeats keeps its rows, in the order the filer built them. */
    public function testItKeepsTheRowsInOrderAndDropsTheEmptyOnes(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Counts]);

        $answers = self::service()->read($subcategory, [
            'counts' => ['rows' => [
                ['quantity' => 'snares lifted', 'how_many' => '12'],
                ['quantity' => '', 'how_many' => ''],
                ['quantity' => 'animals', 'how_many' => '2'],
            ]],
        ]);

        self::assertSame([
            ['quantity' => 'snares lifted', 'how_many' => '12'],
            ['quantity' => 'animals', 'how_many' => '2'],
        ], $answers->rows(BehaviorBlockEnum::Counts));
    }

    /**
     * THE FIGURE IS NOT A BLOCK ANSWER, it is the claimed figure on the incident —
     * and it is a whole number whatever was typed around it.
     */
    public function testTheMoneyFigureComesBackAsTheClaimedFigureAndNotAsABlockAnswer(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Money], MoneyDirectionEnum::Compensation);

        $answers = self::service()->read($subcategory, ['money' => ['claimed' => ' 900,000 ']]);

        self::assertSame(900000, $answers->claimed);
        self::assertSame([], $answers->values);
    }

    public function testAMoneyBlockWithNoFigureClaimsNothing(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Money], MoneyDirectionEnum::Fine);

        self::assertNull(self::service()->read($subcategory, ['money' => ['claimed' => '']])->claimed);
    }

    /**
     * THE TYPED NAME IS READ ONLY WHERE THE LIST WAS NO HELP, and the place is
     * stored as the pair it is: a kind, and a name.
     */
    public function testATypedPlaceNameIsFoldedIntoTheOneName(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::NamedPlace]);

        $typed = self::service()->read($subcategory, [
            'named-place' => ['place_kind' => 'water body', 'place_name' => 'other', 'place_other' => 'The seasonal pool'],
        ]);
        self::assertSame(
            ['named-place' => ['place_kind' => 'water body', 'place_name' => 'The seasonal pool']],
            $typed->values,
        );

        $listed = self::service()->read($subcategory, [
            'named-place' => ['place_kind' => 'water body', 'place_name' => 'A named pool', 'place_other' => 'ignored'],
        ]);
        self::assertSame(
            ['named-place' => ['place_kind' => 'water body', 'place_name' => 'A named pool']],
            $listed->values,
        );
    }

    /** Nothing posted at all is an empty set, not an error. */
    public function testNothingPostedIsAnEmptySet(): void
    {
        $answers = self::service()->read(self::subcategoryWith([BehaviorBlockEnum::Species]), []);

        self::assertTrue($answers->isEmpty());
        self::assertSame([], $answers->values);
    }

    /** A sub-category with no blocks holds up nothing. */
    public function testASubcategoryWithNoBlocksGatesNothing(): void
    {
        $subcategory = self::subcategoryWith([]);

        self::assertSame([], self::service()->missing($subcategory, self::service()->read($subcategory, [])));
    }

    /**
     * THE FOOTER'S LINE, in the order the blocks are switched on — the design's
     * "the species · one count row · a party with a role and a name · the loss
     * claimed".
     */
    public function testItNamesEveryBlockWhoseDefiningAnswerIsMissing(): void
    {
        $subcategory = self::subcategoryWith(
            [BehaviorBlockEnum::Species, BehaviorBlockEnum::Counts, BehaviorBlockEnum::Parties, BehaviorBlockEnum::Money],
            MoneyDirectionEnum::Compensation,
        );

        self::assertSame(
            ['the species', 'one count row', 'a party with a role and a name', 'the loss claimed'],
            self::service()->missing($subcategory, self::service()->read($subcategory, [])),
        );
    }

    /** Answer them all and nothing holds the filing up. */
    public function testEveryDefiningAnswerGivenLeavesNothingMissing(): void
    {
        $subcategory = self::subcategoryWith(
            [BehaviorBlockEnum::Species, BehaviorBlockEnum::Counts, BehaviorBlockEnum::Parties, BehaviorBlockEnum::Money],
            MoneyDirectionEnum::Compensation,
        );

        $answers = self::service()->read($subcategory, [
            'species' => ['species' => 'Lion'],
            'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '3']]],
            'parties' => ['rows' => [['role' => 'claimant', 'name' => 'A. Person']]],
            'money' => ['claimed' => '900000'],
        ]);

        self::assertSame([], self::service()->missing($subcategory, $answers));
    }

    /**
     * A HALF-STARTED ROW SATISFIES NOTHING. A seizure with no item is not a
     * seizure, so a row set holding only half a row is a row set holding none.
     */
    public function testARowMissingHalfOfItselfDoesNotSatisfyTheBlock(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Seizures]);

        $answers = self::service()->read($subcategory, [
            'seizures' => ['rows' => [['item' => 'A bicycle', 'description' => 'blue']]],
        ]);

        self::assertSame(['a seizure item and count'], self::service()->missing($subcategory, $answers));
    }

    /** Paperwork never gates: a party's contact, a sample's laboratory, a date. */
    public function testPaperworkNeverHoldsUpAFiling(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::Samples, BehaviorBlockEnum::Notice]);

        $answers = self::service()->read($subcategory, [
            'samples' => ['rows' => [['samples_taken' => 'blood', 'reference' => 'B-1']]],
            'notice' => ['permit_status' => 'none', 'licence_status' => 'valid'],
        ]);

        self::assertSame([], self::service()->missing($subcategory, $answers));
    }

    /** BOTH halves of the place are wanted, and one of them alone is not the place. */
    public function testHalfAPlaceIsStillMissingThePlace(): void
    {
        $subcategory = self::subcategoryWith([BehaviorBlockEnum::NamedPlace]);

        $answers = self::service()->read($subcategory, ['named-place' => ['place_kind' => 'water body']]);

        self::assertSame(['the kind of place and which one'], self::service()->missing($subcategory, $answers));
    }

    private static function service(): IncidentBlockAnswerService
    {
        return new IncidentBlockAnswerService();
    }

    /** @param list<BehaviorBlockEnum> $blocks */
    private static function subcategoryWith(array $blocks, ?MoneyDirectionEnum $direction = null): TaxonomySubcategory
    {
        $kind = new TaxonomyKind(new AreaOfInterest(), 'conflict', 'Conflict', 'hwc');
        $subcategory = new TaxonomySubcategory($kind, 'depredation', 'depredation');
        $subcategory->setBlocks($blocks);
        if (null !== $direction) {
            $subcategory->setMoneyDirection($direction);
        }

        return $subcategory;
    }
}
