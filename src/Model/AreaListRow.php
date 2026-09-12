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
 * ONE ROW OF ONE LIST, as the editor draws it — the word, what tells it apart,
 * how often it was answered, and whether it is still offered.
 *
 * THE COUNTS ARE FACTS AND NEVER THRESHOLDS. "0 this month · 18 all time" is not
 * an argument for retiring a word; nothing on the screen sorts by them, colours
 * on them or suggests anything from them, and a retired word keeps the count it
 * earned.
 */
final readonly class AreaListRow
{
    public function __construct(
        public string $uuid,
        public string $key,
        public string $label,
        public ?string $note,
        public ?\DateTimeImmutable $retiredAt,
        public int $thisMonth,
        public int $allTime,
    ) {
    }

    public function isActive(): bool
    {
        return null === $this->retiredAt;
    }

    /** "6 this month · 148 all time" — the row's own figures, in the design's words. */
    public function countsLabel(): string
    {
        return \sprintf('%d this month · %d all time', $this->thisMonth, $this->allTime);
    }
}
