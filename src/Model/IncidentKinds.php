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
 * WHAT THE KINDS PAGE READS: every kind this area files, and the one whose
 * matrix is open.
 *
 * THE SELECTION IS A KIND, NOT AN INDEX, so an address that names a kind the
 * area does not have opens the first one rather than nothing at all — and an
 * area with no kinds selects none.
 */
final readonly class IncidentKinds
{
    /**
     * @param list<IncidentKindFigures> $kinds    every kind, retired ones included
     * @param IncidentKindFigures|null  $selected the kind whose matrix is on screen
     */
    public function __construct(
        public array $kinds,
        public ?IncidentKindFigures $selected,
    ) {
    }

    public function retiredCount(): int
    {
        return \count(array_filter($this->kinds, static fn (IncidentKindFigures $kind): bool => !$kind->active));
    }

    public function activeCount(): int
    {
        return \count($this->kinds) - $this->retiredCount();
    }
}
