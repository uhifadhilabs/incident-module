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

namespace UhifadhiLabs\Incident\Model;

use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Entity\IncidentCategory;
use UhifadhiLabs\Incident\Entity\IncidentSubcategory;
use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;
use UhifadhiLabs\Incident\Enum\MoneyDirectionEnum;

/**
 * WHAT THIS MODULE'S FOUR CARDS ON THE AREA OVERVIEW READ, computed once.
 *
 * The same discipline as {@see IncidentDashboard} and for the same reason: the
 * flow bar, today's card, the latest list and the money card share a reading of
 * the morning, and computing them per widget would let two cards on one page
 * disagree because each asked at a slightly different second. The host's
 * contributor seam is explicit about it — one `context()` per area, per render.
 *
 * IT IS NOT THE DASHBOARD IN MINIATURE. The dashboard loads ONE month and reads
 * it nine ways; this holds four unrelated sets, because the four questions have
 * four different windows and one of them — the money — has no window at all:
 *
 *   the flow bar    every incident in the area, by where it is NOW
 *   today's card    one day, against the same WEEKDAY a week ago
 *   the latest      the newest handful, whenever they were filed
 *   the money       everything still owed, whenever it was assessed
 *
 * None of these reconciles with the module's monthly dashboard, and none of them
 * should: different question, different window, and each card says so.
 *
 * A Twig partial receives this and reads it; it computes nothing.
 */
final readonly class IncidentOverview
{
    /**
     * @param list<IncidentCategory>   $categories    the taxonomy, in its own order
     * @param array<string, int>       $statusTally   status value => count, every place present
     * @param list<Incident>           $pastTerm      open work past its OWN category's term, worst first
     * @param list<Incident>           $today         filed today, oldest first
     * @param list<Incident>           $lastWeek      filed the same weekday a week ago
     * @param list<Incident>           $latest        the newest handful, newest first
     * @param list<Incident>           $unpaid        money neither settled nor waived, oldest first
     * @param array<string, int>       $outstanding   money direction value => what is still owed
     * @param array<string, int>       $assessedMonth money direction value => what was signed off this month
     * @param IncidentSubcategory|null $shortestTerm  the tightest promise this area is held to
     * @param IncidentSubcategory|null $longestTerm   the loosest one
     * @param array<string, string>    $statusUrls    status value => the module list filtered to it
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public \DateTimeImmutable $day,
        public \DateTimeImmutable $lastWeekDay,
        public string $currency,
        public string $dashboardUrl,
        public int $total,
        public array $categories,
        public array $statusTally,
        public array $pastTerm,
        public ?float $medianHoursToVerify,
        public ?IncidentSubcategory $shortestTerm,
        public ?IncidentSubcategory $longestTerm,
        public array $today,
        public array $lastWeek,
        public int $closedOutToday,
        public array $latest,
        public array $unpaid,
        public array $outstanding,
        public array $assessedMonth,
        public array $statusUrls,
    ) {
    }

    /**
     * The five places, in workflow order — so a template iterates the ENUM and
     * never a bare string key. THE BAR IS GENERATED FROM THE STATE MACHINE: a
     * status may not read one way here and another on the module's own funnel,
     * so neither of them writes the places out.
     *
     * @return list<IncidentStatusEnum>
     */
    public function places(): array
    {
        return IncidentStatusEnum::ordered();
    }

    /** How many are sitting in one place right now. */
    public function count(IncidentStatusEnum $place): int
    {
        return $this->statusTally[$place->value] ?? 0;
    }

    /** Where the module's list goes when a segment of the bar is clicked. */
    public function statusUrl(IncidentStatusEnum $place): string
    {
        return $this->statusUrls[$place->value] ?? $this->dashboardUrl;
    }

    /** How many are still somebody's work — the first three places and no others. */
    public function openCount(): int
    {
        $open = 0;
        foreach (IncidentStatusEnum::ordered() as $place) {
            if ($place->isOpen()) {
                $open += $this->count($place);
            }
        }

        return $open;
    }

    /**
     * How long one incident has been open, said the way this module says it —
     * so a card and an attention item raised about the same record print the
     * same words. The rule lives in {@see IncidentAge}; this is the reading of
     * it against THIS page's one instant.
     */
    public function ageLabel(Incident $incident): string
    {
        return IncidentAge::label($incident->ageInHours($this->now));
    }

    /** Whether this area has a register at all. An area with none says nothing. */
    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }

    /**
     * Today's filings of one kind — the card prints the count AND the references,
     * because on a four-incident morning the references ARE the answer.
     *
     * @return list<Incident>
     */
    public function todayFor(IncidentCategory $category): array
    {
        return array_values(array_filter(
            $this->today,
            static fn (Incident $incident) => $incident->getCategory()->getSlug() === $category->getSlug(),
        ));
    }

    /**
     * Where today's filings were, busiest first. An incident in none of the
     * area's zones counts under the module's own word for that, because an org
     * that has drawn no zones is the normal state.
     *
     * @return array<string, int>
     */
    public function todayZones(): array
    {
        $zones = [];
        foreach ($this->today as $incident) {
            $name = $incident->zoneLabel();
            $zones[$name] = ($zones[$name] ?? 0) + 1;
        }
        arsort($zones);

        return $zones;
    }

    /**
     * How many of today's filings came from another module's record — a patrol
     * observation, filed on and keeping its observation id for good.
     */
    public function todayFromRecords(): int
    {
        return \count(array_filter($this->today, static fn (Incident $incident) => $incident->hasProvenance()));
    }

    /** The places today's filings are sitting in, in workflow order — "all still in reported or verified". */
    public function todayPlaces(): string
    {
        $places = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            foreach ($this->today as $incident) {
                if ($incident->getStatus() === $place) {
                    $places[] = $place->label();
                    break;
                }
            }
        }

        return implode(' or ', $places);
    }

    /** What is still owed in one direction. THE TWO ARE NEVER SUMMED — see the money card. */
    public function outstanding(MoneyDirectionEnum $direction): int
    {
        return $this->outstanding[$direction->value] ?? 0;
    }

    /** What was signed off this month in one direction. */
    public function assessedThisMonth(MoneyDirectionEnum $direction): int
    {
        return $this->assessedMonth[$direction->value] ?? 0;
    }

    /*
     * The four figures the money card prints, named. A template must be able to
     * ask for them without naming an enum case — a Twig file reaching for
     * `constant('…\MoneyDirectionEnum::Fine')` is the module's vocabulary
     * spelled out in a place nobody would think to grep.
     */

    /** Fines assessed and uncollected — owed TO the authority. */
    public function finesDue(): int
    {
        return $this->outstanding(MoneyDirectionEnum::Fine);
    }

    /** Compensation approved and unpaid — owed BY it. Never added to the above. */
    public function compensationUnpaid(): int
    {
        return $this->outstanding(MoneyDirectionEnum::Compensation);
    }

    public function finesAssessedThisMonth(): int
    {
        return $this->assessedThisMonth(MoneyDirectionEnum::Fine);
    }

    public function compensationApprovedThisMonth(): int
    {
        return $this->assessedThisMonth(MoneyDirectionEnum::Compensation);
    }

    /**
     * The unpaid records in one direction, oldest first.
     *
     * @return list<Incident>
     */
    public function unpaidIn(MoneyDirectionEnum $direction): array
    {
        return array_values(array_filter(
            $this->unpaid,
            static fn (Incident $incident) => $incident->getMoney()?->getDirection() === $direction,
        ));
    }

    /**
     * THE OLDEST UNPAID CLAIM — the compensation this area owes somebody and has
     * owed them longest. Null when it owes nobody anything, which is a real
     * answer and is drawn as one.
     */
    public function oldestUnpaid(): ?Incident
    {
        return $this->unpaidIn(MoneyDirectionEnum::Compensation)[0] ?? null;
    }

    /** How many compensation claims are waiting on a payment. */
    public function openClaims(): int
    {
        return \count($this->unpaidIn(MoneyDirectionEnum::Compensation));
    }

    /** How many zones those claims are spread across. */
    public function claimZones(): int
    {
        $zones = [];
        foreach ($this->unpaidIn(MoneyDirectionEnum::Compensation) as $incident) {
            $zones[$incident->zoneLabel()] = true;
        }

        return \count($zones);
    }
}
