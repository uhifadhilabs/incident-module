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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\QuestionControlEnum;
use Uhifadhi\Incident\Model\BlockQuestionCatalogue;

/**
 * THE CATALOGUE IS THE SPEC, AND THIS IS THE SPEC READ BACK.
 *
 * Every number in here is the design's own: the question count each block's
 * summary line prints, the questions the ruling says hold up a filing, which
 * blocks are a row that repeats, and the fixed option lists. A block that gains
 * or loses a question fails a count, and a gate that moves fails a name — which
 * is the point, because the form is generated from this and nothing else.
 */
final class BlockQuestionCatalogueTest extends TestCase
{
    /** Every one of the twelve is in the catalogue, and no thirteenth is. */
    public function testEveryBlockHasASetAndNothingElseDoes(): void
    {
        self::assertSame(
            array_map(static fn (BehaviorBlockEnum $b): string => $b->value, BehaviorBlockEnum::cases()),
            array_keys(BlockQuestionCatalogue::all()),
        );
    }

    /**
     * The count on the summary line, block by block — the design draws
     * "3 questions" on Species and "1 question" on Money, and a row that repeats
     * counts its row's questions once.
     */
    #[DataProvider('questionCounts')]
    public function testTheSummaryLineCount(BehaviorBlockEnum $block, int $expected): void
    {
        self::assertSame($expected, BlockQuestionCatalogue::forBlock($block)->questionCount());
    }

    /** @return iterable<string, array{BehaviorBlockEnum, int}> */
    public static function questionCounts(): iterable
    {
        yield 'species' => [BehaviorBlockEnum::Species, 3];
        yield 'counts' => [BehaviorBlockEnum::Counts, 2];
        yield 'method' => [BehaviorBlockEnum::Method, 6];
        yield 'parties' => [BehaviorBlockEnum::Parties, 5];
        yield 'seizures' => [BehaviorBlockEnum::Seizures, 4];
        yield 'money' => [BehaviorBlockEnum::Money, 1];
        yield 'condition' => [BehaviorBlockEnum::Condition, 2];
        yield 'samples' => [BehaviorBlockEnum::Samples, 4];
        yield 'casualty' => [BehaviorBlockEnum::Casualty, 5];
        yield 'extent' => [BehaviorBlockEnum::Extent, 3];
        yield 'named place' => [BehaviorBlockEnum::NamedPlace, 3];
        yield 'notice' => [BehaviorBlockEnum::Notice, 5];
    }

    /**
     * THE RULED GATE, question by question. Everything not named here can wait,
     * and the paperwork — contacts, ID numbers, custody references, dates sent,
     * facilities — is never in this list.
     *
     * @param list<string> $singles the keys of the single questions that gate
     * @param list<string> $rows    the keys a started row cannot be without
     */
    #[DataProvider('gatingQuestions')]
    public function testWhatHoldsUpAFiling(BehaviorBlockEnum $block, array $singles, array $rows): void
    {
        $set = BlockQuestionCatalogue::forBlock($block);

        self::assertSame($singles, array_map(
            static fn ($question): string => $question->key,
            array_values(array_filter($set->questions, static fn ($q): bool => $q->gates)),
        ));
        self::assertSame($rows, array_map(
            static fn ($question): string => $question->key,
            array_values(array_filter($set->rowQuestions, static fn ($q): bool => $q->gates)),
        ));
    }

    /** @return iterable<string, array{BehaviorBlockEnum, list<string>, list<string>}> */
    public static function gatingQuestions(): iterable
    {
        yield 'the species' => [BehaviorBlockEnum::Species, ['species'], []];
        yield 'one count row' => [BehaviorBlockEnum::Counts, [], ['quantity', 'how_many']];
        yield 'the method' => [BehaviorBlockEnum::Method, ['method'], []];
        yield 'a role and a name' => [BehaviorBlockEnum::Parties, [], ['role', 'name']];
        yield 'an item and a count' => [BehaviorBlockEnum::Seizures, [], ['item', 'how_many']];
        yield 'the figure' => [BehaviorBlockEnum::Money, ['claimed'], []];
        yield 'the condition' => [BehaviorBlockEnum::Condition, ['condition'], []];
        yield 'a type and a reference' => [BehaviorBlockEnum::Samples, [], ['samples_taken', 'reference']];
        yield 'an injury' => [BehaviorBlockEnum::Casualty, [], ['injuries']];
        yield 'a measure' => [BehaviorBlockEnum::Extent, [], ['measure', 'value']];
        yield 'the place' => [BehaviorBlockEnum::NamedPlace, ['place_kind', 'place_name'], []];
        yield 'permit and licence' => [BehaviorBlockEnum::Notice, ['permit_status', 'licence_status'], []];
    }

    /**
     * SIX BLOCKS ARE A ROW THAT REPEATS, and the other six are not — decisions 3,
     * 4, 6 and 8, read back in one assertion.
     */
    public function testWhichBlocksRepeat(): void
    {
        $repeating = [];
        foreach (BlockQuestionCatalogue::all() as $block => $set) {
            if ($set->repeats()) {
                $repeating[] = $block;
            }
        }

        self::assertSame(['counts', 'parties', 'seizures', 'samples', 'casualty', 'extent'], $repeating);
    }

    /**
     * The fixed lists, verbatim from the catalogue table.
     *
     * @param list<string> $expected
     */
    #[DataProvider('fixedOptions')]
    public function testTheFixedOptionLists(BehaviorBlockEnum $block, string $key, array $expected): void
    {
        $set = BlockQuestionCatalogue::forBlock($block);
        $question = $set->question($key);

        self::assertNotNull($question, $block->value.' has no question '.$key);
        self::assertSame(QuestionControlEnum::SelectFixed, $question->control);
        self::assertSame($expected, $question->options);
    }

    /** @return iterable<string, array{BehaviorBlockEnum, string, list<string>}> */
    public static function fixedOptions(): iterable
    {
        yield 'sex' => [BehaviorBlockEnum::Species, 'sex', ['unknown', 'male', 'female']];
        yield 'age class' => [BehaviorBlockEnum::Species, 'age_class', ['unknown', 'adult', 'subadult', 'juvenile']];
        yield 'what was counted' => [BehaviorBlockEnum::Counts, 'quantity', ['animals', 'snares lifted', 'head of stock', 'structures', 'individuals affected', 'vehicles']];
        yield 'suspected agent' => [BehaviorBlockEnum::Method, 'suspected_agent', ['unknown', 'person', 'livestock', 'wildlife', 'vehicle']];
        yield 'activity' => [BehaviorBlockEnum::Method, 'activity', ['unknown', 'hunting', 'grazing', 'fishing', 'cultivation', 'construction', 'transport']];
        yield 'role' => [BehaviorBlockEnum::Parties, 'role', ['reporter', 'claimant', 'suspect', 'witness', 'responder']];
        yield 'condition' => [BehaviorBlockEnum::Condition, 'condition', ['alive', 'injured', 'dead, fresh', 'dead, decomposed', 'remains only']];
        yield 'disposition' => [BehaviorBlockEnum::Condition, 'carcass_disposition', ['left in situ', 'buried', 'burned', 'removed to store', 'handed over']];
        yield 'sample type' => [BehaviorBlockEnum::Samples, 'samples_taken', ['tissue', 'blood', 'bone', 'scat', 'water', 'soil']];
        yield 'injury' => [BehaviorBlockEnum::Casualty, 'injuries', ['minor', 'serious', 'fatal']];
        yield 'treatment' => [BehaviorBlockEnum::Casualty, 'treatment', ['none', 'first aid on site', 'dispensary', 'hospital']];
        yield 'the measure' => [BehaviorBlockEnum::Extent, 'measure', ['area affected · ha', 'footprint · m²', 'length of boundary · m', 'how long it went on · hours', 'how long it went on · days']];
        yield 'kind of place' => [BehaviorBlockEnum::NamedPlace, 'place_kind', ['water body', 'road segment', 'boundary marker', 'household or boma', 'other']];
        yield 'permit status' => [BehaviorBlockEnum::Notice, 'permit_status', ['not applicable', 'none', 'valid', 'expired', 'not produced']];
        yield 'licence status' => [BehaviorBlockEnum::Notice, 'licence_status', ['not applicable', 'none', 'valid', 'expired', 'not produced']];
    }

    /**
     * FOUR QUESTIONS READ A PER-AREA LIST, and none of them holds a list of its
     * own: an animal is never a typed name and a method is never a typed word.
     */
    #[DataProvider('areaListQuestions')]
    public function testTheQuestionsThatReadAPerAreaList(BehaviorBlockEnum $block, string $key, AreaListEnum $list): void
    {
        $question = BlockQuestionCatalogue::forBlock($block)->question($key);

        self::assertNotNull($question);
        self::assertSame(QuestionControlEnum::SelectAreaList, $question->control);
        self::assertSame($list, $question->areaList);
        self::assertSame([], $question->options, 'A per-area list is never hardcoded in the catalogue.');
    }

    /** @return iterable<string, array{BehaviorBlockEnum, string, AreaListEnum}> */
    public static function areaListQuestions(): iterable
    {
        yield 'species' => [BehaviorBlockEnum::Species, 'species', AreaListEnum::Species];
        yield 'method' => [BehaviorBlockEnum::Method, 'method', AreaListEnum::Method];
        yield 'land use' => [BehaviorBlockEnum::Extent, 'land_use', AreaListEnum::LandUse];
        yield 'which named place' => [BehaviorBlockEnum::NamedPlace, 'place_name', AreaListEnum::NamedPlace];
    }

    /**
     * ONE QUESTION, TWO NAMES. A filer never sees the direction, only the
     * question it makes — and the fold's own title says which way it runs.
     */
    public function testTheMoneyQuestionIsNamedByTheDirection(): void
    {
        $compensation = BlockQuestionCatalogue::forBlock(BehaviorBlockEnum::Money, MoneyDirectionEnum::Compensation);
        $fine = BlockQuestionCatalogue::forBlock(BehaviorBlockEnum::Money, MoneyDirectionEnum::Fine);
        $undecided = BlockQuestionCatalogue::forBlock(BehaviorBlockEnum::Money);

        self::assertSame('Loss claimed', $compensation->questions[0]->label);
        self::assertSame('Fine assessed', $fine->questions[0]->label);
        self::assertSame('Loss claimed', $undecided->questions[0]->label);

        self::assertSame('Money · compensation', $compensation->title);
        self::assertSame('Money · fine', $fine->title);
        self::assertSame('Money', $undecided->title);

        self::assertSame('the loss claimed', $compensation->missingLabel);
        self::assertSame('the fine assessed', $fine->missingLabel);
    }

    /** The figure is counted in the deployment's own unit, and it is a number. */
    public function testTheMoneyQuestionIsANumberWithAUnit(): void
    {
        $question = BlockQuestionCatalogue::forBlock(BehaviorBlockEnum::Money)->questions[0];

        self::assertSame(QuestionControlEnum::Number, $question->control);
        self::assertSame('claimed', $question->key);
        self::assertNotNull($question->unit);
    }

    /**
     * THE FOOTER NAMES WHAT IS MISSING, and it names it in the design's words —
     * the line under the sheet reads "the species · one count row · a party with
     * a role and a name · the loss claimed".
     */
    public function testEachBlockSaysHowItIsMissed(): void
    {
        $labels = [];
        foreach (BlockQuestionCatalogue::all() as $block => $set) {
            $labels[$block] = $set->missingLabel;
        }

        self::assertSame([
            'species' => 'the species',
            'counts' => 'one count row',
            'method' => 'the method',
            'parties' => 'a party with a role and a name',
            'seizures' => 'a seizure item and count',
            'money' => 'the loss claimed',
            'condition' => 'the condition',
            'samples' => 'a sample type and reference',
            'casualty' => 'an injury row',
            'extent' => 'a measure row',
            'named-place' => 'the kind of place and which one',
            'notice' => 'the permit and licence status',
        ], $labels);
    }

    /**
     * A ROW THAT REPEATS SAYS HOW TO GROW IT, and says a row is wanted on the
     * same line — the design's `.repfoot`.
     */
    public function testEveryRepeatingBlockCarriesItsAddControlAndItsRowMark(): void
    {
        foreach (BlockQuestionCatalogue::all() as $block => $set) {
            if (!$set->repeats()) {
                self::assertNull($set->addLabel, $block.' does not repeat and must offer nothing to add.');
                self::assertNull($set->rowMark, $block.' does not repeat and has no row mark.');

                continue;
            }

            self::assertIsString($set->addLabel, $block.' repeats and must say what a row is.');
            self::assertIsString($set->rowMark, $block.' repeats and must say a row is needed.');
        }
    }

    /** Nothing is invented: every question answers to a key, and keys are unique. */
    public function testEveryQuestionKeyIsUniqueWithinItsBlock(): void
    {
        foreach (BlockQuestionCatalogue::all() as $block => $set) {
            $keys = array_map(
                static fn ($question): string => $question->key,
                [...$set->questions, ...$set->rowQuestions],
            );

            self::assertSame(array_values(array_unique($keys)), $keys, $block.' repeats a key.');
            foreach ($keys as $key) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key, $block.' has a key the wire cannot carry.');
            }
        }
    }

    /** The fold's summary line: the block's name, and one line saying what it is for. */
    public function testEveryBlockCarriesTheCaptionItsSummaryLinePrints(): void
    {
        foreach (BehaviorBlockEnum::cases() as $block) {
            self::assertNotSame('', $block->caption(), $block->value.' has no summary line.');
            self::assertNotSame($block->description(), $block->caption());
        }
    }
}
