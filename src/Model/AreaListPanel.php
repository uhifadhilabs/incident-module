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

/**
 * ONE LIST AS ONE FOLD — its summary line, who reads it, its rows and whether it
 * is the open one.
 *
 * THE FOLD IS A SPACE SAVER AND SAYS SO. Four full lists on one page would not
 * fit, so three are shut; a shut fold here never means "you may leave this
 * alone", which is why its summary counts the sub-categories that READ the list
 * and carries OPEN / CLOSED in a word rather than a caret alone. That is
 * deliberately not the report form's fold wording, where a shut block genuinely
 * may be optional.
 *
 * @see AreaListUse  the "used by" line under the summary
 */
final readonly class AreaListPanel
{
    /**
     * @param list<AreaListRow> $rows live and retired, in the list's own order
     */
    public function __construct(
        public AreaListEnum $list,
        public AreaListUse $use,
        public array $rows,
        public bool $open,
    ) {
    }

    /** "42 entries", "1 entry", "empty" — what the summary line prints on the right. */
    public function sizeLabel(): string
    {
        $count = \count($this->rows);

        return match ($count) {
            0 => 'empty',
            1 => '1 entry',
            default => $count.' entries',
        };
    }

    /** "used by 10 sub-categories — the species question". */
    public function summaryLine(): string
    {
        $count = $this->use->subcategoryCount();

        return \sprintf(
            'used by %d %s — the %s question',
            $count,
            1 === $count ? 'sub-category' : 'sub-categories',
            $this->list->value,
        );
    }

    /** OPEN / CLOSED, in the word the summary carries beside the caret. */
    public function stateLabel(): string
    {
        return $this->open ? 'open' : 'closed';
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }
}
