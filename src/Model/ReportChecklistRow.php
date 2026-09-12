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

/**
 * ONE BLOCK, AS THE RAIL BESIDE THE FORM NAMES IT.
 *
 * The form folds every block but the first, which is the right answer to length
 * and the wrong answer to surprise: a filer cannot see what is coming. So the
 * rail states the same run of blocks flat — the block's name, what it is for, the
 * one answer it records nothing without, how many questions are behind it, and
 * whether the fold holding them is already open.
 *
 * IT CARRIES NO MARK. Whether the answer has ARRIVED is the gate's state, read in
 * the browser from the list the gate just handed the footer; a second computation
 * here would be a second opinion, and the day the two disagreed the rail would be
 * telling somebody they may file while the File control refused to.
 */
final readonly class ReportChecklistRow
{
    /**
     * @param string      $blockValue     the block's wire value, the same one the form's fold posts under
     * @param string      $title          the fold's own name — "Money · compensation" where the direction is set
     * @param string      $caption        the one line the fold's summary carries, verbatim
     * @param string|null $definingAnswer how the gate names this block's defining answer when it is
     *                                    missing, or null where the block holds up nothing
     */
    public function __construct(
        public string $blockValue,
        public string $title,
        public string $caption,
        public ?string $definingAnswer,
        public int $questionCount,
        public string $questionCountLabel,
        public bool $open,
    ) {
    }

    /** Whether the gate can be held up by this block. */
    public function gates(): bool
    {
        return null !== $this->definingAnswer;
    }

    /** "open" or "folded" — how the form drew this block, said in the rail. */
    public function foldLabel(): string
    {
        return $this->open ? 'open' : 'folded';
    }
}
