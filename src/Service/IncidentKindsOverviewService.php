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
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Model\IncidentKindFigures;
use Uhifadhi\Incident\Model\IncidentKinds;
use Uhifadhi\Incident\Model\IncidentSubcategoryFigures;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;

/**
 * THE AREA'S OWN VOCABULARY, WITH WHAT HAS BEEN FILED AGAINST EACH WORD.
 *
 * THE LIST IS THE AREA'S — {@see TaxonomyKind}, the model the kinds editor
 * writes, money direction and retirement included. The COUNTS are the filed
 * register's, which is still organised by the installation-wide
 * {@see IncidentSubcategory}; until the two models converge, a taxonomy
 * sub-category's counts are the incidents whose filed sub-category has the SAME
 * SLUG as the taxonomy sub-category's wire-code, and a word nothing matches
 * counts zero.
 *
 * THREE QUERIES, NOT THREE PER ROW. The month, the month before it and all time
 * are each one grouped count over the area, read here against the vocabulary.
 */
final readonly class IncidentKindsOverviewService
{
    public function __construct(
        private TaxonomyKindRepository $kinds,
        private IncidentSubcategoryRepository $filedSubcategories,
        private IncidentRepository $incidents,
    ) {
    }

    public function build(AreaOfInterest $area, ?string $kind, \DateTimeImmutable $now): IncidentKinds
    {
        $thisMonth = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $thisMonth->modify('+1 month');
        $lastMonth = $thisMonth->modify('-1 month');

        $inMonth = $this->incidents->countsBySubcategorySlug($area, $thisMonth, $nextMonth);
        $inLastMonth = $this->incidents->countsBySubcategorySlug($area, $lastMonth, $thisMonth);
        $ever = $this->incidents->countsBySubcategorySlug($area);

        $terms = $this->termsBySlug();

        $figures = [];
        foreach ($this->kinds->forArea($area) as $entity) {
            $figures[] = $this->figuresFor($entity, $inMonth, $inLastMonth, $ever, $terms);
        }

        return new IncidentKinds($figures, $this->select($figures, $kind));
    }

    /**
     * @param array<string, int>    $inMonth
     * @param array<string, int>    $inLastMonth
     * @param array<string, int>    $ever
     * @param array<string, string> $terms
     */
    private function figuresFor(TaxonomyKind $kind, array $inMonth, array $inLastMonth, array $ever, array $terms): IncidentKindFigures
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
                termLabel: $terms[$code] ?? null,
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
     * The term the matched filed sub-category promises, by its slug — the one
     * property of a word this area's model does not carry itself.
     *
     * @return array<string, string>
     */
    private function termsBySlug(): array
    {
        $terms = [];
        foreach ($this->filedSubcategories->findAll() as $subcategory) {
            $terms[$subcategory->getSlug()] = $subcategory->termLabel();
        }

        return $terms;
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
