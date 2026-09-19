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
 * ONE INCIDENT, REDUCED TO THE FIVE FACTS A PERFORMANCE FIGURE IS MADE OF.
 *
 * NOT THE ENTITY, DELIBERATELY. A topic's arithmetic — a median, a count past
 * a term, a slice by department — is decided entirely by when a record was
 * filed, when it was finished, what it promised, whether it is still open and
 * whose desk it was filed from. Everything else on {@see \Uhifadhi\Incident\Entity\Incident}
 * is irrelevant to it, and a calculation written against the entity is a
 * calculation that cannot be tested without a database, a taxonomy and an
 * area.
 *
 * THE DEPARTMENT IS THE RECORDER'S, AND IT IS OFTEN NULL. A department's
 * figures are the incidents whose recording position sits in that department;
 * a seeded or imported row, or one filed by somebody holding no position,
 * belongs to no department and is counted in the topic's headline figures and
 * in nobody's row. That is the honest answer, and it is why null here is not a
 * department called "none".
 */
final readonly class IncidentReading
{
    public function __construct(
        public \DateTimeImmutable $reportedAt,
        /** Null while nobody has finished it. */
        public ?\DateTimeImmutable $resolvedAt,
        /** What its sub-category promised, in hours. */
        public int $termHours,
        /** Whether it is still somewhere in the working half of the workflow. */
        public bool $open,
        /** Whether its sub-category runs compensation, so filing it filed a claim. */
        public bool $claimed = false,
        /** Whether that claim is neither settled nor waived. */
        public bool $claimOutstanding = false,
        /** The department of the position the recorder holds, where there is one. */
        public ?int $departmentId = null,
    ) {
    }

    /**
     * How long it ran, in hours — to resolution, or to the instant asked
     * about for one still open.
     */
    public function ageInHours(\DateTimeImmutable $at): int
    {
        $end = $this->resolvedAt ?? $at;

        return max(0, intdiv($end->getTimestamp() - $this->reportedAt->getTimestamp(), 3600));
    }

    /** Whether it has run longer than its sub-category promised, as at an instant. */
    public function pastTerm(\DateTimeImmutable $at): bool
    {
        return $this->ageInHours($at) > $this->termHours;
    }

    /** How long it took to close, in days — null while it is still open. */
    public function daysToClose(): ?float
    {
        if (null === $this->resolvedAt) {
            return null;
        }

        return max(0, $this->resolvedAt->getTimestamp() - $this->reportedAt->getTimestamp()) / 86400.0;
    }
}
