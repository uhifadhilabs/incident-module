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

namespace Uhifadhi\Incident\Service;

use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Model\BlockAnswers;
use Uhifadhi\Incident\Model\BlockQuestionCatalogue;

/**
 * THE ANSWERS TO STEP 2'S BLOCKS, READ AGAINST THE CATALOGUE — and the gate the
 * File control obeys.
 *
 * WHAT IT KEEPS is what the chosen sub-category's blocks actually ask: a block
 * that is off has no questions on the form, so it has no answers either, whatever
 * arrived under its name; a key no block asks for is not stored. That is the same
 * rule the form draws by, applied again where it cannot be walked around.
 *
 * WHAT HOLDS UP A FILING is each switched-on block's DEFINING answer — the
 * species, one count row, the method, a party with a role and a name, a seizure
 * item and count, the figure, the condition, a sample type and reference, an
 * injury row, a measure row, the kind of place and which one, the permit and
 * licence status. Paperwork never does. THE BROWSER SAYS THE SAME THING SOONER
 * and is not the authority: the endpoint refuses a filing missing any of them
 * whatever the page did.
 *
 * @see BlockQuestionCatalogue  the questions themselves
 */
final readonly class IncidentBlockAnswerService
{
    /**
     * The answers as this sub-category's blocks ask for them.
     *
     * @param array<mixed> $posted what the form's `blocks` field carried
     */
    public function read(TaxonomySubcategory $subcategory, array $posted): BlockAnswers
    {
        $values = [];
        $claimed = null;

        foreach ($subcategory->getBlocks() as $block) {
            $set = BlockQuestionCatalogue::forBlock($block, $subcategory->getMoneyDirection());
            $answered = $posted[$block->value] ?? null;
            if (!\is_array($answered)) {
                continue;
            }

            // THE FIGURE HAS ITS OWN HOME. It is the claimed figure on the
            // incident, never a block answer and never the money record.
            if (BehaviorBlockEnum::Money === $block) {
                $claimed = self::wholeNumber($answered['claimed'] ?? null);

                continue;
            }

            $kept = [];
            foreach ($set->questions as $question) {
                $answer = self::text($answered[$question->key] ?? null);
                if (null !== $answer) {
                    $kept[$question->key] = $answer;
                }
            }

            $rows = self::rows($set->rowQuestions, $answered['rows'] ?? null);
            if ([] !== $rows) {
                $kept['rows'] = $rows;
            }

            if (BehaviorBlockEnum::NamedPlace === $block) {
                $kept = self::onePlace($kept);
            }

            if ([] !== $kept) {
                $values[$block->value] = $kept;
            }
        }

        return new BlockAnswers($values, $claimed);
    }

    /**
     * What is still missing, named the way the footer names it — one line per
     * block, in the order the kinds editor holds them.
     *
     * @return list<string>
     */
    public function missing(TaxonomySubcategory $subcategory, BlockAnswers $answers): array
    {
        $missing = [];

        foreach ($subcategory->getBlocks() as $block) {
            $set = BlockQuestionCatalogue::forBlock($block, $subcategory->getMoneyDirection());
            if (!$set->gates()) {
                continue;
            }

            if (BehaviorBlockEnum::Money === $block) {
                if (null === $answers->claimed) {
                    $missing[] = $set->missingLabel;
                }

                continue;
            }

            if (self::satisfied($set->gateKeys(), $set->rowGateKeys(), $answers->forBlock($block), $answers->rows($block))) {
                continue;
            }

            $missing[] = $set->missingLabel;
        }

        return $missing;
    }

    /**
     * @param list<string>                $gateKeys
     * @param list<string>                $rowGateKeys
     * @param array<string, mixed>        $single
     * @param list<array<string, string>> $rows
     */
    private static function satisfied(array $gateKeys, array $rowGateKeys, array $single, array $rows): bool
    {
        foreach ($gateKeys as $key) {
            if (null === self::text($single[$key] ?? null)) {
                return false;
            }
        }

        if ([] === $rowGateKeys) {
            return true;
        }

        // ONE WHOLE ROW, not one answered cell across several: a seizure with no
        // item is not a seizure, and half a row is no row at all.
        foreach ($rows as $row) {
            $whole = true;
            foreach ($rowGateKeys as $key) {
                if (null === self::text($row[$key] ?? null)) {
                    $whole = false;
                    break;
                }
            }
            if ($whole) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows a repeating block carried: only the keys it asks, only the rows
     * that answered something, in the order they were built.
     *
     * @param list<\Uhifadhi\Incident\Model\BlockQuestion> $questions
     *
     * @return list<array<string, string>>
     */
    private static function rows(array $questions, mixed $posted): array
    {
        if ([] === $questions || !\is_array($posted)) {
            return [];
        }

        $rows = [];
        foreach ($posted as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $cells = [];
            foreach ($questions as $question) {
                $answer = self::text($row[$question->key] ?? null);
                if (null !== $answer) {
                    $cells[$question->key] = $answer;
                }
            }

            if ([] !== $cells) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * ONE PLACE, AS A KIND AND A NAME. The typed name is read only where the
     * area's list was no help, and it becomes THE name rather than a third answer
     * beside it — otherwise two records about one place could never be counted as
     * one place.
     *
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    private static function onePlace(array $answers): array
    {
        $typed = self::text($answers['place_other'] ?? null);
        unset($answers['place_other']);

        $named = self::text($answers['place_name'] ?? null);
        if (null !== $typed && (null === $named || 'other' === mb_strtolower($named) || str_starts_with(mb_strtolower($named), 'other '))) {
            $answers['place_name'] = $typed;
        }

        return $answers;
    }

    /** A trimmed answer, or null where nothing was said. */
    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * A FIGURE IS A WHOLE NUMBER, whatever was typed around it. A filer writing
     * "900,000" at the roadside has given the number; refusing it over a comma
     * would cost a claim its figure.
     */
    private static function wholeNumber(mixed $value): ?int
    {
        $raw = self::text($value);
        if (null === $raw) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return '' === $digits ? null : (int) $digits;
    }
}
