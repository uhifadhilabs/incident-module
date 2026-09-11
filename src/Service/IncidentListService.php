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

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Model\IncidentList;

/**
 * WHICH SLICE OF THE FILTERED ANSWER IS ON SCREEN.
 *
 * IT TAKES THE ANSWER, NOT THE QUESTION. The filtered set is already worked out
 * once, for the counts, the map and the charts; paging it here rather than
 * asking a second query is what keeps the caption's total and the rows under it
 * two readings of one thing.
 *
 * IT IS PURE — no clock, no repository, no request.
 */
final readonly class IncidentListService
{
    /** How many rows a page of the full list shows, as the design's pager says. */
    public const int PER_PAGE = 20;

    /** @param list<Incident> $incidents the filtered set, newest first */
    public function page(array $incidents, int $page): IncidentList
    {
        $total = \count($incidents);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pageCount));
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = \array_slice($incidents, $offset, self::PER_PAGE);

        return new IncidentList(
            incidents: $rows,
            total: $total,
            page: $page,
            pageCount: $pageCount,
            firstIndex: 0 === $total ? 0 : $offset + 1,
            lastIndex: $offset + \count($rows),
        );
    }
}
