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
 * THE ARITHMETIC BEHIND THE INCIDENTS TOPIC, over one window's records and
 * nothing else.
 *
 * IT IS PURE, AND THAT IS THE POINT. Every figure the performance page prints
 * — filed, open past target, the median time to close, compensation claims,
 * resolved, and the age distribution behind the second chart — is a reading of
 * a list of {@see IncidentReading}s. Written here rather than inside the topic,
 * each one is provable without a database, and the topic is left doing what a
 * provider is supposed to do: naming figures and handing them over.
 *
 * ONE LIST READ MANY WAYS, never one query per figure. The plates of a period
 * are readings of the same rows, and asking the database once per plate is how
 * two figures on one card come to disagree.
 *
 * NOTHING RECORDED IS NOT NOUGHT. {@see isEmpty()} is what a caller turns into
 * a hole in a six-period history; a median over no finished work is null, not
 * zero; and a count of nothing that happened IS zero, because somebody looked.
 */
final readonly class PerformanceReadings
{
    /** The four buckets the age chart draws, as the upper bound of each in hours. */
    private const array AGE_BUCKET_HOURS = [168, 336, 504];

    /** @param list<IncidentReading> $readings */
    public function __construct(public array $readings)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->readings;
    }

    /** How many were filed in the window. */
    public function filed(): int
    {
        return \count($this->readings);
    }

    /**
     * How many are still open AND have already run past what their
     * sub-category promised, as at an instant.
     */
    public function openPastTarget(\DateTimeImmutable $at): int
    {
        $past = 0;
        foreach ($this->readings as $reading) {
            if ($reading->open && $reading->pastTerm($at)) {
                ++$past;
            }
        }

        return $past;
    }

    /**
     * The middle time to close, in days — NULL while nothing has been
     * finished, because a median of no finished work is not nought days.
     */
    public function medianDaysToClose(): ?float
    {
        $days = [];
        foreach ($this->readings as $reading) {
            $closed = $reading->daysToClose();
            if (null !== $closed) {
                $days[] = $closed;
            }
        }

        if ([] === $days) {
            return null;
        }

        sort($days);
        $count = \count($days);
        $middle = intdiv($count, 2);

        return 0 === $count % 2
            ? ($days[$middle - 1] + $days[$middle]) / 2.0
            : $days[$middle];
    }

    /**
     * How many filed a compensation claim at all — the matrix column, which
     * counts the work that arrived rather than the work still outstanding.
     */
    public function claimsFiled(): int
    {
        $claims = 0;
        foreach ($this->readings as $reading) {
            if ($reading->claimed) {
                ++$claims;
            }
        }

        return $claims;
    }

    /**
     * How many of those claims nobody has settled or waived — the headline
     * figure, which is about the queue and not about the intake.
     */
    public function claimsOpen(): int
    {
        $open = 0;
        foreach ($this->readings as $reading) {
            if ($reading->claimOutstanding) {
                ++$open;
            }
        }

        return $open;
    }

    /** How many were finished — of the rows in this window, whatever the window means. */
    public function resolved(): int
    {
        $resolved = 0;
        foreach ($this->readings as $reading) {
            if (null !== $reading->resolvedAt) {
                ++$resolved;
            }
        }

        return $resolved;
    }

    /**
     * THE SAME WINDOW THROUGH ONE DEPARTMENT'S LENS: the records whose
     * recording position sits in it. A record filed by nobody seated belongs
     * to no department and is in no slice — it is still in the headline
     * figures, which is why a topic's total may be larger than its rows.
     */
    public function forDepartment(int $departmentId): self
    {
        $mine = [];
        foreach ($this->readings as $reading) {
            if ($departmentId === $reading->departmentId) {
                $mine[] = $reading;
            }
        }

        return new self($mine);
    }

    /**
     * WHAT THE HEADLINE'S SPLIT IS MADE OF: how many each department recorded,
     * keyed by department id. A record nobody seated filed is absent rather
     * than filed under a department called "none".
     *
     * @return array<int, int>
     */
    public function filedByDepartment(): array
    {
        $counts = [];
        foreach ($this->readings as $reading) {
            if (null === $reading->departmentId) {
                continue;
            }
            $counts[$reading->departmentId] = ($counts[$reading->departmentId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * THE DISTINCT TERMS THESE RECORDS WERE JUDGED AGAINST, in days, smallest
     * first — what the median figure's caption names, because a median printed
     * without saying what it was promised against is unreadable.
     *
     * @return list<float>
     */
    public function termsInDays(): array
    {
        $terms = [];
        foreach ($this->readings as $reading) {
            $terms[] = $reading->termHours / 24.0;
        }

        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }

    /**
     * HOW LONG THE OPEN ONES HAVE BEEN OPEN: four buckets, 0–7 days, 8–14,
     * 15–21 and over three weeks.
     *
     * @return list<int>
     */
    public function openAgeBuckets(\DateTimeImmutable $at): array
    {
        $buckets = [0, 0, 0, 0];
        foreach ($this->readings as $reading) {
            if (!$reading->open) {
                continue;
            }

            $hours = $reading->ageInHours($at);
            $bucket = \count(self::AGE_BUCKET_HOURS);
            foreach (self::AGE_BUCKET_HOURS as $index => $upper) {
                if ($hours < $upper) {
                    $bucket = $index;

                    break;
                }
            }
            ++$buckets[$bucket];
        }

        return $buckets;
    }
}
