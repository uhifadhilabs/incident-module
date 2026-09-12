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
use Uhifadhi\Incident\Enum\QuestionControlEnum;

/**
 * ONE QUESTION A BEHAVIOUR BLOCK ASKS — as the filer reads it, as the form draws
 * it, and under the key the answer is kept.
 *
 * WHETHER IT HOLDS UP A FILING is {@see $gates}. A block's DEFINING question does:
 * without the species a Species block records nothing, and an empty block is
 * worse than an absent one. Everything else is paperwork — a contact, an ID
 * number, a custody reference, a date, a facility — and paperwork can wait,
 * because a half-remembered report that exists beats a perfect one that was never
 * filed.
 *
 * {@see $unit} is the word beside a number, not a conversion: "counted",
 * "people", "items". A measure that carries its own unit says so in its option
 * ("footprint · m²"), because the unit is part of which measure was chosen.
 */
final readonly class BlockQuestion
{
    /**
     * @param string       $key         the key the answer is kept under, and the one the wire carries
     * @param list<string> $options     the fixed words this question offers, or empty for every other control
     * @param bool         $gates       whether the File control is dead until this is answered
     * @param string|null  $placeholder the line an empty control shows, in the design's words
     * @param string|null  $note        the quiet caption under the control, where the design draws one
     * @param bool         $wide        whether the select needs the room its options ask for
     */
    public function __construct(
        public string $key,
        public string $label,
        public QuestionControlEnum $control,
        public bool $gates = false,
        public array $options = [],
        public ?AreaListEnum $areaList = null,
        public ?string $unit = null,
        public ?string $placeholder = null,
        public ?string $note = null,
        public bool $wide = false,
    ) {
    }

    /** The mark beside the control: the accent one, or the quiet one. */
    public function whenLabel(): string
    {
        return $this->gates ? 'needed to file' : 'can wait';
    }
}
