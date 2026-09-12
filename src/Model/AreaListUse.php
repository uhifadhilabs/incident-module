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

use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Enum\AreaListEnum;

/**
 * WHO READS ONE LIST — the line the Lists screen prints under a fold, derived and
 * never written down.
 *
 * TWO HALVES, BOTH READ RATHER THAN STATED. The block and the question come from
 * {@see BlockQuestionCatalogue}, which is the one place that knows a question
 * reads a per-area list and whether its answer holds up a filing; the kinds and
 * sub-categories come from THIS AREA's own taxonomy. So a list that gains a
 * reader gains it here the moment the catalogue says so, and an area that ticks
 * the Species block on one more word sees the count move without anything being
 * edited.
 *
 * A HARDCODED LINE WOULD BE THE BUG THIS CLASS EXISTS TO PREVENT. "Used by
 * Species in 10 sub-categories" is true of one area on one day.
 */
final readonly class AreaListUse
{
    /**
     * @param string                      $blockLabel    the behaviour block that asks — "Species", "Method & means"
     * @param string                      $questionKey   the key that question keeps its answer under
     * @param string                      $questionLabel the question inside it — "Species", "Land use", "Which one"
     * @param bool                        $gates         whether the answer holds up a filing
     * @param array<string, list<string>> $kinds         kind label => the sub-category labels under it that read this list
     * @param array<string, int>          $kindTotals    kind label => how many live sub-categories that kind has in all
     */
    public function __construct(
        public AreaListEnum $list,
        public string $blockValue,
        public string $blockLabel,
        public string $questionKey,
        public string $questionLabel,
        public bool $gates,
        public array $kinds = [],
        public array $kindTotals = [],
    ) {
    }

    /**
     * Every list, with the readers this area's taxonomy gives it. A list no
     * question reads is absent — which cannot happen while all four are in the
     * catalogue, and is the honest answer if one ever leaves it.
     *
     * @param list<TaxonomyKind> $kinds this area's own, retired ones included
     *
     * @return array<string, self> keyed by the list's wire value, in the enum's order
     */
    public static function all(array $kinds): array
    {
        $uses = [];

        foreach (AreaListEnum::cases() as $list) {
            $asking = self::questionAsking($list);
            if (null === $asking) {
                continue;
            }

            [$set, $question] = $asking;
            $block = $set->block;

            $readers = [];
            $totals = [];
            foreach ($kinds as $kind) {
                if (!$kind->isActive()) {
                    continue;
                }

                foreach ($kind->getSubcategories() as $subcategory) {
                    if (!$subcategory->isActive()) {
                        continue;
                    }

                    $totals[$kind->getLabel()] = ($totals[$kind->getLabel()] ?? 0) + 1;

                    if (\in_array($block, $subcategory->getBlocks(), true)) {
                        $readers[$kind->getLabel()][] = $subcategory->getLabel();
                    }
                }
            }

            $uses[$list->value] = new self(
                list: $list,
                blockValue: $block->value,
                blockLabel: $block->label(),
                questionKey: $question->key,
                questionLabel: $question->label,
                gates: $question->gates,
                kinds: $readers,
                kindTotals: array_intersect_key($totals, $readers),
            );
        }

        return $uses;
    }

    /** How many of this area's sub-categories ask this list's question. */
    public function subcategoryCount(): int
    {
        $count = 0;
        foreach ($this->kinds as $subcategories) {
            $count += \count($subcategories);
        }

        return $count;
    }

    /**
     * What stands in the brackets after a kind's name: the words themselves, or
     * "all N" where every live sub-category of that kind asks for one and naming
     * them would be a list nobody reads.
     */
    public function parenthetical(string $kindLabel): string
    {
        $subcategories = $this->kinds[$kindLabel] ?? [];
        $total = $this->kindTotals[$kindLabel] ?? 0;

        if (\count($subcategories) >= 3 && \count($subcategories) === $total) {
            return 'all '.$total;
        }

        return implode(', ', $subcategories);
    }

    /**
     * The block question that reads one list, and the set it lives in — asked of
     * the catalogue, so nothing here holds a second opinion about which question
     * that is.
     *
     * @return array{BlockQuestionSet, BlockQuestion}|null
     */
    private static function questionAsking(AreaListEnum $list): ?array
    {
        foreach (BlockQuestionCatalogue::all() as $set) {
            foreach ([...$set->questions, ...$set->rowQuestions] as $question) {
                if ($list === $question->areaList) {
                    return [$set, $question];
                }
            }
        }

        return null;
    }
}
