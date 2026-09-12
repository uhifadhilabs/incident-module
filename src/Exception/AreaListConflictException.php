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

namespace Uhifadhi\Incident\Exception;

/**
 * A WORD THAT WOULD COLLIDE INSIDE ONE OF AN AREA'S LISTS — a species already in
 * the species list, a place already among the named places. The write is refused
 * rather than quietly producing two words nothing could tell apart: the point of
 * a list is that two records about the same animal are countable as one animal,
 * and two rows reading "Lion" would end that.
 *
 * Its own type rather than the taxonomy's, because a list entry is not a kind of
 * incident and the words may one day be the area's rather than this module's.
 */
final class AreaListConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function label(string $message): self
    {
        return new self('label', $message);
    }
}
