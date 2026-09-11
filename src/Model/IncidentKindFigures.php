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
 * ONE KIND OF INCIDENT THIS AREA FILES, with its own numbers and its words'.
 *
 * A KIND'S TOTALS ARE ITS WORDS' TOTALS. Nothing is filed against a kind
 * directly, so every figure here is the sum of the rows under it — which is why
 * the matrix's first row can say "all of this kind" rather than repeat a heading.
 */
final readonly class IncidentKindFigures
{
    /**
     * @param string                           $label         the name an editor typed
     * @param string                           $code          the wire-code, and this kind's address
     * @param string                           $colourKey     the hue key the stylesheet declares
     * @param bool                             $active        a retired kind is dimmed, never hidden
     * @param list<IncidentSubcategoryFigures> $subcategories the kind's words, in the editor's order
     * @param int                              $thisMonth     filed under any of them in the month on screen
     * @param int                              $lastMonth     filed under any of them in the month before it
     * @param int                              $allTime       filed under any of them, ever
     */
    public function __construct(
        public string $label,
        public string $code,
        public string $colourKey,
        public bool $active,
        public array $subcategories,
        public int $thisMonth,
        public int $lastMonth,
        public int $allTime,
    ) {
    }

    public function subcategoryCount(): int
    {
        return \count($this->subcategories);
    }
}
