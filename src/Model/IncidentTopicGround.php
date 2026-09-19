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

use Uhifadhi\Contracts\Kpi\FigurePeriod;

/**
 * THE GROUND A SLICE OF THE PERFORMANCE PAGE IS ACTUALLY MEASURED OVER — the
 * areas of it that RUN this module, and since when.
 *
 * READ FROM THE AREA × MODULE LEDGER, never from the incidents table. A matrix
 * row has to tell "this ground runs Incidents and filed nothing" from "this
 * ground does not run Incidents at all", and only the ledger knows the second:
 * an area with no incidents looks identical to an area that has never had the
 * module, and the two are drawn differently on purpose.
 *
 * WHY "SINCE WHEN" TRAVELS WITH IT. Every period before the module was
 * installed over this ground is a period nobody was recording, and a nought
 * drawn there is a collapse the organisation never had. The distinction has to
 * be made wherever a run of periods is built, so the fact that decides it
 * rides along with the ground rather than being fetched again at each call
 * site.
 */
final readonly class IncidentTopicGround
{
    /**
     * @param list<string>            $areaUuids    every area of the slice that runs the module
     * @param \DateTimeImmutable|null $measuredFrom the earliest this module was installed over the ground, null where the ledger does not say
     */
    public function __construct(
        public array $areaUuids,
        public ?\DateTimeImmutable $measuredFrom = null,
    ) {
    }

    /** No area here runs the module: the columns are nobody's to answer. */
    public function isUnrun(): bool
    {
        return [] === $this->areaUuids;
    }

    /** Whether this module was recording over the ground at any instant of a period. */
    public function measured(FigurePeriod $period): bool
    {
        return null === $this->measuredFrom || $this->measuredFrom < $period->until;
    }
}
