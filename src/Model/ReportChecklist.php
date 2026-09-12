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

use Uhifadhi\Incident\Enum\BehaviorBlockEnum;

/**
 * WHAT THIS KIND ASKS — the rail beside the report form, as a model.
 *
 * Step 2 is the sub-category's own, and its questions come from its blocks. The
 * form draws one fold per block and shuts all but the first, so the questions a
 * filer still owes are behind summary lines they have to open one at a time. The
 * rail states the same run flat: every switched-on block, its defining question,
 * and how much is inside it — and then names the blocks the word did NOT switch
 * on, because "absent, not greyed" is a promise about a form and a reader cannot
 * check a promise about things they cannot see.
 *
 * BUILT FROM THE SAME SETS THE FORM IS. It takes the {@see BlockQuestionSet} list
 * the page already renders step 2 from, so the rail cannot describe a form the
 * page did not draw: change the blocks a sub-category carries in the kinds editor
 * and both move together, with nothing here typing a question or a count.
 *
 * IT INFORMS, IT NEVER GATES. Nothing here says whether an answer has arrived —
 * that is the gate's state, read in the browser from the one list the gate hands
 * the footer.
 */
final readonly class ReportChecklist
{
    /**
     * @param list<ReportChecklistRow> $rows         one per block the sub-category switched on, in the form's order
     * @param list<string>             $absentBlocks the blocks it did not, in the picker's order
     */
    public function __construct(
        public string $subcategoryLabel,
        public array $rows,
        public array $absentBlocks,
    ) {
    }

    /**
     * @param list<BlockQuestionSet> $sets the sets the form renders step 2 from
     */
    public static function for(string $subcategoryLabel, array $sets): self
    {
        $rows = [];
        $on = [];
        foreach ($sets as $index => $set) {
            $on[] = $set->block;
            $rows[] = new ReportChecklistRow(
                blockValue: $set->block->value,
                title: $set->title,
                caption: $set->caption,
                // The gate's own words for the missing answer, so the rail is
                // marked from the gate's list rather than from a second reading
                // of the same form.
                definingAnswer: $set->gates() ? $set->missingLabel : null,
                questionCount: $set->questionCount(),
                questionCountLabel: $set->questionCountLabel(),
                // The form opens the first fold and shuts the rest
                // (templates/report/_blocks.html.twig), and the rail says which.
                open: 0 === $index,
            );
        }

        $absent = [];
        foreach (BehaviorBlockEnum::inPickerOrder() as $block) {
            if (!\in_array($block, $on, true)) {
                // Lower case: this is prose inside a sentence, not a heading.
                $absent[] = mb_strtolower($block->label());
            }
        }

        return new self($subcategoryLabel, $rows, $absent);
    }

    public function blockCount(): int
    {
        return \count($this->rows);
    }

    /** Every question the chosen word asks, a repeating row's counted once. */
    public function questionCount(): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            $count += $row->questionCount;
        }

        return $count;
    }

    /** How many of these blocks the gate can hold a filing up on. */
    public function gatingCount(): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            if ($row->gates()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * WHAT THE CARD'S TAB PRICES THE WORD AT — "4 blocks · 11 questions". A filer
     * reads it before opening a single fold, which is the whole reason the rail
     * exists.
     */
    public function blocksAndQuestions(): string
    {
        return \sprintf(
            '%d %s · %d %s',
            $this->blockCount(),
            1 === $this->blockCount() ? 'block' : 'blocks',
            $this->questionCount(),
            1 === $this->questionCount() ? 'question' : 'questions',
        );
    }

    /**
     * HOW MANY BLOCKS THIS WORD LEFT OFF, spelled — "The other eight blocks … are
     * absent from this form, not greyed out". Null where it switched every block
     * on and there is nothing to say.
     *
     * The sentence itself is the rail's copy and lives in the rail's template,
     * because one clause of it is emphasised: the promise being made is about the
     * word ABSENT, and a sentence assembled in PHP could not carry that mark
     * without this model rendering markup.
     */
    public function absentCountWord(): ?string
    {
        return [] === $this->absentBlocks ? null : self::spelled(\count($this->absentBlocks));
    }

    /**
     * A SMALL COUNT IS SPELLED, because it is read in a sentence. The set of
     * blocks is closed in {@see BehaviorBlockEnum}, so the range is too.
     */
    private static function spelled(int $count): string
    {
        return [
            1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven',
        ][$count] ?? (string) $count;
    }
}
