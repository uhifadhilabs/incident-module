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
 * WHAT ONE BLOCK ASKS, AS THE FORM DRAWS IT — one fold, its summary line, and the
 * questions inside it.
 *
 * TWO KINDS OF QUESTION LIVE HERE, because two kinds exist. {@see $questions} are
 * asked once: the species, the method, the condition. {@see $rowQuestions} are a
 * ROW THAT REPEATS — "twelve snares, two carcasses, one bicycle" is one record,
 * and a list that cannot grow is a different question. Extent carries both: the
 * measures are a list, and what the affected ground is used for is asked once.
 *
 * THE SUMMARY LINE KEEPS A SHUT FOLD HONEST. It names the block, says in one line
 * what it is for, prints how many questions are inside and carries the mark, so a
 * folded block can never hide a held-up filing.
 */
final readonly class BlockQuestionSet
{
    /**
     * @param string              $title        the fold's own name — "Money · fine" where the direction is set
     * @param list<BlockQuestion> $questions    asked once
     * @param list<BlockQuestion> $rowQuestions one row's worth, or empty where the block does not repeat
     * @param string|null         $addLabel     the add control's words, or null where the block does not repeat
     * @param string|null         $rowMark      what the row mark says beside the add control
     * @param string              $missingLabel how the footer names this block when its gate is unanswered
     */
    public function __construct(
        public BehaviorBlockEnum $block,
        public string $title,
        public string $caption,
        public array $questions,
        public array $rowQuestions = [],
        public ?string $addLabel = null,
        public ?string $rowMark = null,
        public string $missingLabel = '',
    ) {
    }

    /** Whether this block is a row the filer adds to. */
    public function repeats(): bool
    {
        return [] !== $this->rowQuestions;
    }

    /** The count the summary line prints — a repeating row's questions counted once. */
    public function questionCount(): int
    {
        return \count($this->questions) + \count($this->rowQuestions);
    }

    /** "3 questions", "1 question" — the summary line's own words. */
    public function questionCountLabel(): string
    {
        $count = $this->questionCount();

        return \sprintf('%d %s', $count, 1 === $count ? 'question' : 'questions');
    }

    /** Whether anything in this block holds up a filing. */
    public function gates(): bool
    {
        foreach ([...$this->questions, ...$this->rowQuestions] as $question) {
            if ($question->gates) {
                return true;
            }
        }

        return false;
    }

    /** One question by its key, wherever it sits, or null where the block has none. */
    public function question(string $key): ?BlockQuestion
    {
        foreach ([...$this->questions, ...$this->rowQuestions] as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        return null;
    }

    /**
     * The keys a STARTED row cannot be without: a seizure with no item is not a
     * seizure, and a row with none of them is not a row at all.
     *
     * @return list<string>
     */
    public function rowGateKeys(): array
    {
        $keys = [];
        foreach ($this->rowQuestions as $question) {
            if ($question->gates) {
                $keys[] = $question->key;
            }
        }

        return $keys;
    }

    /**
     * The single questions that hold up a filing.
     *
     * @return list<string>
     */
    public function gateKeys(): array
    {
        $keys = [];
        foreach ($this->questions as $question) {
            if ($question->gates) {
                $keys[] = $question->key;
            }
        }

        return $keys;
    }
}
