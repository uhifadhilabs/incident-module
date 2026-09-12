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

namespace Uhifadhi\Incident\Model;

use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\QuestionControlEnum;

/**
 * WHAT EACH OF THE TWELVE BLOCKS ASKS — the whole vocabulary of the report form,
 * in one table.
 *
 * A SUB-CATEGORY'S QUESTIONS COME FROM THE BLOCKS IT SWITCHED ON, AND FROM
 * NOWHERE ELSE. Tick a block in the kinds editor and its questions join step 2;
 * untick it and they are gone. Nothing in the product invents a field, there is no
 * form builder, and no screen types a question of its own.
 *
 * WHY IT IS CODE AND NOT DATA: a question is behaviour the form has to know how to
 * draw, an answer has to be worth counting across areas, and a block's DEFINING
 * question has to be the same one in every deployment or "needed to file" means
 * nothing. A per-deployment list of question names could promise none of that.
 *
 * WHAT IS NOT HERE. The four questions every incident answers whatever its blocks
 * — the sub-category, when, where, how bad and what happened — belong to the form
 * itself, not to a block. The case file's own figures (approved, paid, waived) are
 * written by whoever judges and pays, in the states the money flow names, and are
 * never asked at filing.
 *
 * @see BlockQuestionSet  one block's worth
 * @see \Uhifadhi\Incident\Service\IncidentBlockAnswerService  the answers, read and gated
 */
final class BlockQuestionCatalogue
{
    /**
     * Every block's set, in the picker's order, keyed by the block's wire value —
     * and every set still carries its own block, so a caller that keeps only the
     * values has lost nothing.
     *
     * @return array<string, BlockQuestionSet>
     */
    public static function all(?MoneyDirectionEnum $moneyDirection = null): array
    {
        $sets = [];
        foreach (BehaviorBlockEnum::inPickerOrder() as $block) {
            $sets[$block->value] = self::forBlock($block, $moneyDirection);
        }

        return $sets;
    }

    public static function forBlock(BehaviorBlockEnum $block, ?MoneyDirectionEnum $moneyDirection = null): BlockQuestionSet
    {
        return match ($block) {
            BehaviorBlockEnum::Species => self::species(),
            BehaviorBlockEnum::Counts => self::counts(),
            BehaviorBlockEnum::Method => self::method(),
            BehaviorBlockEnum::Parties => self::parties(),
            BehaviorBlockEnum::Seizures => self::seizures(),
            BehaviorBlockEnum::Money => self::money($moneyDirection),
            BehaviorBlockEnum::Condition => self::condition(),
            BehaviorBlockEnum::Samples => self::samples(),
            BehaviorBlockEnum::Casualty => self::casualty(),
            BehaviorBlockEnum::Extent => self::extent(),
            BehaviorBlockEnum::NamedPlace => self::namedPlace(),
            BehaviorBlockEnum::Notice => self::notice(),
        };
    }

    /**
     * The sets a sub-category's chosen blocks produce, in the order the kinds
     * editor holds them.
     *
     * @param list<BehaviorBlockEnum> $blocks
     *
     * @return list<BlockQuestionSet>
     */
    public static function forBlocks(array $blocks, ?MoneyDirectionEnum $moneyDirection = null): array
    {
        $sets = [];
        foreach ($blocks as $block) {
            $sets[] = self::forBlock($block, $moneyDirection);
        }

        return $sets;
    }

    private static function species(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Species,
            title: BehaviorBlockEnum::Species->label(),
            caption: BehaviorBlockEnum::Species->caption(),
            questions: [
                new BlockQuestion(
                    key: 'species',
                    label: 'Species',
                    control: QuestionControlEnum::SelectAreaList,
                    gates: true,
                    areaList: AreaListEnum::Species,
                ),
                new BlockQuestion('sex', 'Sex', QuestionControlEnum::SelectFixed, options: ['unknown', 'male', 'female']),
                new BlockQuestion('age_class', 'Age class', QuestionControlEnum::SelectFixed, options: ['unknown', 'adult', 'subadult', 'juvenile']),
            ],
            missingLabel: 'the species',
        );
    }

    private static function counts(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Counts,
            title: BehaviorBlockEnum::Counts->label(),
            caption: BehaviorBlockEnum::Counts->caption(),
            questions: [],
            rowQuestions: [
                new BlockQuestion(
                    key: 'quantity',
                    label: 'What was counted',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['animals', 'snares lifted', 'head of stock', 'structures', 'individuals affected', 'vehicles'],
                    placeholder: '— what was counted —',
                ),
                new BlockQuestion('how_many', 'How many', QuestionControlEnum::Number, gates: true, unit: 'counted'),
            ],
            addLabel: '+ Add a quantity',
            rowMark: 'one row needed to file',
            missingLabel: 'one count row',
        );
    }

    private static function method(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Method,
            title: BehaviorBlockEnum::Method->label(),
            caption: BehaviorBlockEnum::Method->caption(),
            questions: [
                new BlockQuestion(
                    key: 'method',
                    label: 'Method',
                    control: QuestionControlEnum::SelectAreaList,
                    gates: true,
                    areaList: AreaListEnum::Method,
                ),
                new BlockQuestion('gear', 'Gear', QuestionControlEnum::Text, placeholder: 'what it was done with — cable, bow, torch…'),
                new BlockQuestion('vehicle', 'Vehicle', QuestionControlEnum::Text, placeholder: 'registration or description'),
                new BlockQuestion('suspected_agent', 'Suspected agent', QuestionControlEnum::SelectFixed, options: ['unknown', 'person', 'livestock', 'wildlife', 'vehicle']),
                new BlockQuestion('activity', 'Activity', QuestionControlEnum::SelectFixed, options: ['unknown', 'hunting', 'grazing', 'fishing', 'cultivation', 'construction', 'transport']),
                new BlockQuestion('operator', 'Operator', QuestionControlEnum::Text, placeholder: 'the named operator or company'),
            ],
            missingLabel: 'the method',
        );
    }

    private static function parties(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Parties,
            title: BehaviorBlockEnum::Parties->label(),
            caption: BehaviorBlockEnum::Parties->caption(),
            questions: [],
            rowQuestions: [
                new BlockQuestion(
                    key: 'role',
                    label: 'Role',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['reporter', 'claimant', 'suspect', 'witness', 'responder'],
                    placeholder: '— role —',
                ),
                new BlockQuestion('name', 'Name', QuestionControlEnum::Text, gates: true, placeholder: 'name'),
                new BlockQuestion('contact', 'Contact', QuestionControlEnum::Text, placeholder: 'contact — a phone number, usually'),
                new BlockQuestion('household', 'Household or boma', QuestionControlEnum::Text, placeholder: 'household or boma'),
                new BlockQuestion('document_number', 'ID or document number', QuestionControlEnum::Text, placeholder: 'ID or document number'),
            ],
            addLabel: '+ Add a person',
            rowMark: 'a role and a name needed to file',
            missingLabel: 'a party with a role and a name',
        );
    }

    private static function seizures(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Seizures,
            title: BehaviorBlockEnum::Seizures->label(),
            caption: BehaviorBlockEnum::Seizures->caption(),
            questions: [],
            rowQuestions: [
                new BlockQuestion('item', 'Item', QuestionControlEnum::Text, gates: true, placeholder: 'item'),
                new BlockQuestion('how_many', 'How many', QuestionControlEnum::Number, gates: true, unit: 'items'),
                new BlockQuestion('description', 'Description', QuestionControlEnum::Text, placeholder: 'description — make, condition, marks'),
                new BlockQuestion('custody_reference', 'Custody reference', QuestionControlEnum::Text, placeholder: 'custody reference — when it reaches a store'),
            ],
            addLabel: '+ Add a seizure',
            rowMark: 'an item and a count needed to file',
            missingLabel: 'a seizure item and count',
        );
    }

    /**
     * ONE QUESTION, TWO NAMES. The direction is the sub-category's and a filer
     * never sees it — only the question it makes. The figure is the claimant's own,
     * before anybody has judged it, and it is kept ON THE INCIDENT: it does not
     * open the money record, which still appears in the state the money flow names.
     */
    private static function money(?MoneyDirectionEnum $direction): BlockQuestionSet
    {
        $isFine = MoneyDirectionEnum::Fine === $direction;
        $label = $isFine ? 'Fine assessed' : 'Loss claimed';

        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Money,
            title: null === $direction
                ? BehaviorBlockEnum::Money->label()
                : BehaviorBlockEnum::Money->label().' · '.$direction->value,
            caption: BehaviorBlockEnum::Money->caption(),
            questions: [
                new BlockQuestion(
                    key: 'claimed',
                    label: $label,
                    control: QuestionControlEnum::Number,
                    gates: true,
                    unit: 'TZS',
                    note: $isFine
                        ? 'the figure the officer writes at the roadside, before anybody has approved it'
                        : "the claimant's own figure, before anybody has judged it",
                ),
            ],
            missingLabel: 'the '.mb_strtolower($label),
        );
    }

    private static function condition(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Condition,
            title: BehaviorBlockEnum::Condition->label(),
            caption: BehaviorBlockEnum::Condition->caption(),
            questions: [
                new BlockQuestion(
                    key: 'condition',
                    label: 'Condition',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['alive', 'injured', 'dead, fresh', 'dead, decomposed', 'remains only'],
                    placeholder: '— the state it was in —',
                ),
                new BlockQuestion('carcass_disposition', 'Disposition', QuestionControlEnum::SelectFixed, options: ['left in situ', 'buried', 'burned', 'removed to store', 'handed over']),
            ],
            missingLabel: 'the condition',
        );
    }

    private static function samples(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Samples,
            title: BehaviorBlockEnum::Samples->label(),
            caption: BehaviorBlockEnum::Samples->caption(),
            questions: [],
            rowQuestions: [
                new BlockQuestion(
                    key: 'samples_taken',
                    label: 'Sample type',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['tissue', 'blood', 'bone', 'scat', 'water', 'soil'],
                    placeholder: '— sample type —',
                ),
                new BlockQuestion('reference', 'Reference', QuestionControlEnum::Text, gates: true, placeholder: 'reference — what is written on the tube'),
                new BlockQuestion('sent_to', 'Sent to', QuestionControlEnum::Text, placeholder: 'sent to — laboratory or store'),
                new BlockQuestion('date_sent', 'Date sent', QuestionControlEnum::Date),
            ],
            addLabel: '+ Add a sample',
            rowMark: 'a type and a reference needed to file',
            missingLabel: 'a sample type and reference',
        );
    }

    private static function casualty(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Casualty,
            title: BehaviorBlockEnum::Casualty->label(),
            caption: BehaviorBlockEnum::Casualty->caption(),
            questions: [],
            rowQuestions: [
                new BlockQuestion(
                    key: 'injuries',
                    label: 'Injury',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['minor', 'serious', 'fatal'],
                    placeholder: '— injury —',
                ),
                new BlockQuestion('people', 'How many people', QuestionControlEnum::Number, unit: 'people'),
                new BlockQuestion(
                    key: 'treatment',
                    label: 'Treatment given',
                    control: QuestionControlEnum::SelectFixed,
                    options: ['none', 'first aid on site', 'dispensary', 'hospital'],
                    placeholder: '— treatment given —',
                ),
                new BlockQuestion('facility', 'Facility', QuestionControlEnum::Text, placeholder: 'facility — which dispensary or hospital'),
                new BlockQuestion('date_treated', 'Date treated', QuestionControlEnum::Date),
            ],
            addLabel: '+ Add an injury',
            rowMark: 'one row needed to file',
            missingLabel: 'an injury row',
        );
    }

    /**
     * THE MEASURES ARE A LIST AND THE LAND USE IS NOT. An encroachment is
     * hectares, a boundary breach is metres and a blockade is hours: asking all
     * five at once gets four blanks every time, so the measure is chosen WITH ITS
     * UNIT and the row repeats. What the affected ground is used for is one answer.
     */
    private static function extent(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Extent,
            title: BehaviorBlockEnum::Extent->label(),
            caption: BehaviorBlockEnum::Extent->caption(),
            questions: [
                new BlockQuestion(
                    key: 'land_use',
                    label: 'Land use',
                    control: QuestionControlEnum::SelectAreaList,
                    areaList: AreaListEnum::LandUse,
                ),
            ],
            rowQuestions: [
                new BlockQuestion(
                    key: 'measure',
                    label: 'The measure, with its unit',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['area affected · ha', 'footprint · m²', 'length of boundary · m', 'how long it went on · hours', 'how long it went on · days'],
                    placeholder: '— the measure, with its unit —',
                ),
                new BlockQuestion('value', 'Value', QuestionControlEnum::Number, gates: true, unit: 'value'),
            ],
            addLabel: '+ Add a measure',
            rowMark: 'one row needed to file',
            missingLabel: 'a measure row',
        );
    }

    /**
     * ONE PAIR OF QUESTIONS, not a field per kind of place. Four separate keys
     * could never tell anybody how many incidents happened at one named place; a
     * kind and a name from the area's own list can. The typed name is the answer a
     * filer can always give, and it is read only where the list was no help.
     */
    private static function namedPlace(): BlockQuestionSet
    {
        return new BlockQuestionSet(
            block: BehaviorBlockEnum::NamedPlace,
            title: BehaviorBlockEnum::NamedPlace->label(),
            caption: BehaviorBlockEnum::NamedPlace->caption(),
            questions: [
                new BlockQuestion(
                    key: 'place_kind',
                    label: 'Kind of place',
                    control: QuestionControlEnum::SelectFixed,
                    gates: true,
                    options: ['water body', 'road segment', 'boundary marker', 'household or boma', 'other'],
                    placeholder: '— kind of place —',
                ),
                new BlockQuestion(
                    key: 'place_name',
                    label: 'Which one',
                    control: QuestionControlEnum::SelectAreaList,
                    gates: true,
                    areaList: AreaListEnum::NamedPlace,
                    note: "the area's own list, filtered by the kind above",
                ),
                new BlockQuestion(
                    key: 'place_other',
                    label: 'Or its name',
                    control: QuestionControlEnum::Text,
                    placeholder: 'typed — only when the one above is other',
                ),
            ],
            missingLabel: 'the kind of place and which one',
        );
    }

    private static function notice(): BlockQuestionSet
    {
        $statuses = ['not applicable', 'none', 'valid', 'expired', 'not produced'];

        return new BlockQuestionSet(
            block: BehaviorBlockEnum::Notice,
            title: BehaviorBlockEnum::Notice->label(),
            caption: BehaviorBlockEnum::Notice->caption(),
            questions: [
                new BlockQuestion('permit_status', 'Permit status', QuestionControlEnum::SelectFixed, gates: true, options: $statuses, placeholder: '— permit status —'),
                new BlockQuestion('licence_status', 'Licence status', QuestionControlEnum::SelectFixed, gates: true, options: $statuses, placeholder: '— licence status —'),
                new BlockQuestion('notice_served', 'Notice served', QuestionControlEnum::YesNo),
                new BlockQuestion('notice_reference', 'Notice reference', QuestionControlEnum::Text, placeholder: 'the number on the paper that was handed over'),
                new BlockQuestion('date_served', 'Date served', QuestionControlEnum::Date),
            ],
            missingLabel: 'the permit and licence status',
        );
    }
}
