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

namespace Uhifadhi\Incident\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Model\IncidentKindFigures;
use Uhifadhi\Incident\Model\IncidentKinds;
use Uhifadhi\Incident\Model\IncidentSubcategoryFigures;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;

/**
 * THE AREA'S OWN VOCABULARY, WITH WHAT HAS BEEN FILED AGAINST EACH WORD.
 *
 * THE LIST IS THE AREA'S, AND SO ARE THE COUNTS. Both sides are
 * {@see TaxonomyKind} and {@see \Uhifadhi\Incident\Entity\TaxonomySubcategory}
 * now: an incident is filed against one of these rows, so a word's count is the
 * incidents filed against it and a word nothing has been filed against counts
 * zero.
 *
 * THREE QUERIES, NOT THREE PER ROW. The month, the month before it and all time
 * are each one grouped count over the area, read here against the vocabulary.
 */
final readonly class IncidentKindsOverviewService
{
    public function __construct(
        private TaxonomyKindRepository $kinds,
        private IncidentRepository $incidents,
    ) {
    }

    public function build(AreaOfInterest $area, ?string $kind, \DateTimeImmutable $now): IncidentKinds
    {
        $thisMonth = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $thisMonth->modify('+1 month');
        $lastMonth = $thisMonth->modify('-1 month');

        $inMonth = $this->incidents->countsBySubcategoryCode($area, $thisMonth, $nextMonth);
        $inLastMonth = $this->incidents->countsBySubcategoryCode($area, $lastMonth, $thisMonth);
        $ever = $this->incidents->countsBySubcategoryCode($area);

        $figures = [];
        foreach ($this->kinds->forArea($area) as $entity) {
            $figures[] = $this->figuresFor($entity, $inMonth, $inLastMonth, $ever);
        }

        return new IncidentKinds($figures, $this->select($figures, $kind));
    }

    /**
     * @param array<string, int> $inMonth
     * @param array<string, int> $inLastMonth
     * @param array<string, int> $ever
     */
    private function figuresFor(TaxonomyKind $kind, array $inMonth, array $inLastMonth, array $ever): IncidentKindFigures
    {
        $busiest = 0;
        foreach ($kind->getSubcategories() as $subcategory) {
            $busiest = max($busiest, $inMonth[$subcategory->getCode()] ?? 0);
        }

        $rows = [];
        $month = 0;
        $previous = 0;
        $allTime = 0;
        foreach ($kind->getSubcategories() as $subcategory) {
            $code = $subcategory->getCode();
            $rows[] = new IncidentSubcategoryFigures(
                label: $subcategory->getLabel(),
                code: $code,
                thisMonth: $inMonth[$code] ?? 0,
                lastMonth: $inLastMonth[$code] ?? 0,
                allTime: $ever[$code] ?? 0,
                heat: 0 === $busiest ? 0.0 : round(($inMonth[$code] ?? 0) / $busiest, 2),
                moneyDirection: $subcategory->getMoneyDirection(),
                termLabel: $subcategory->termLabel(),
                active: $subcategory->isActive(),
            );
            $month += $inMonth[$code] ?? 0;
            $previous += $inLastMonth[$code] ?? 0;
            $allTime += $ever[$code] ?? 0;
        }

        return new IncidentKindFigures(
            label: $kind->getLabel(),
            code: $kind->getCode(),
            colourKey: $kind->getColourKey(),
            active: $kind->isActive(),
            subcategories: $rows,
            thisMonth: $month,
            lastMonth: $previous,
            allTime: $allTime,
        );
    }

    /**
     * @param list<IncidentKindFigures> $kinds
     */
    private function select(array $kinds, ?string $code): ?IncidentKindFigures
    {
        foreach ($kinds as $kind) {
            if (null !== $code && $kind->code === $code) {
                return $kind;
            }
        }

        return $kinds[0] ?? null;
    }
}
