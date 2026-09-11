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

use Uhifadhi\Incident\Enum\MoneyDirectionEnum;

/**
 * ONE ROW OF A KIND'S MATRIX: one word of the area's vocabulary, what has been
 * filed against it, and the two properties the word itself carries.
 *
 * THE TERM IS NULL WHERE NOTHING HAS EVER BEEN FILED under the key, because the
 * clock is a property of the filed sub-category and the area's word has not been
 * matched to one.
 */
final readonly class IncidentSubcategoryFigures
{
    /**
     * @param string             $label          the word an editor typed
     * @param string             $code           the wire-code, stable across renames
     * @param int                $thisMonth      filed in the month on screen
     * @param int                $lastMonth      filed in the month before it
     * @param int                $allTime        filed against the key, ever
     * @param float              $heat           0..1 against the busiest sub-category of the kind
     * @param MoneyDirectionEnum $moneyDirection which way money runs, or null when it does not
     * @param string             $termLabel      the hours a case is answered against, as a chip writes it
     */
    public function __construct(
        public string $label,
        public string $code,
        public int $thisMonth,
        public int $lastMonth,
        public int $allTime,
        public float $heat,
        public ?MoneyDirectionEnum $moneyDirection,
        public ?string $termLabel,
        public bool $active,
    ) {
    }
}
