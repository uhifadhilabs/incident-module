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

use Uhifadhi\Incident\Entity\Incident;

/**
 * ONE PAGE OF THE FULL LIST, and where in the answer the reader is.
 *
 * THE COUNTS ARE NOT HERE. Every count the page prints — the chips, their
 * numbers, the caption's total — is the dashboard's reading of the SAME filter,
 * so there is one place they are worked out and this only says which slice of
 * that answer is on screen.
 */
final readonly class IncidentList
{
    /**
     * @param list<Incident> $incidents  the rows of the page on screen
     * @param int            $total      incidents matching the filter, across all pages
     * @param int            $page       the 1-based page on screen
     * @param int            $pageCount  how many pages the answer fills, at least one
     * @param int            $firstIndex the 1-based position of the first row on screen, 0 when there are none
     * @param int            $lastIndex  the 1-based position of the last row on screen, 0 when there are none
     */
    public function __construct(
        public array $incidents,
        public int $total,
        public int $page,
        public int $pageCount,
        public int $firstIndex,
        public int $lastIndex,
    ) {
    }
}
