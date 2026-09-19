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

namespace Uhifadhi\Incident\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;

/**
 * The area-scoped taxonomy's second level. Uniqueness here is TWO-SCOPED, as the
 * design rules: a label is unique WITHIN ITS PARENT KIND, while a wire-code is
 * unique WITHIN THE WHOLE AREA — so two kinds may each own a "poisoning" label
 * but never two the same code, because a code is what an export column holds.
 *
 * @extends ServiceEntityRepository<TaxonomySubcategory>
 */
final class TaxonomySubcategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxonomySubcategory::class);
    }

    public function findOneByAreaAndUuid(AreaOfInterest $area, string $uuid): ?TaxonomySubcategory
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        /** @var TaxonomySubcategory|null $subcategory */
        $subcategory = $this->createQueryBuilder('s')
            ->join('s.kind', 'k')
            ->andWhere('k.area = :area')->setParameter('area', $area)
            ->andWhere('s.uuid = :uuid')->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();

        return $subcategory;
    }

    /**
     * One of THIS area's sub-categories by its wire-code — what a filed form, a
     * saved filter and a handset all carry. Another area's word with the same
     * code is a different word and is never answered with here.
     */
    public function findOneByAreaAndCode(AreaOfInterest $area, string $code): ?TaxonomySubcategory
    {
        if ('' === $code) {
            return null;
        }

        /** @var TaxonomySubcategory|null $subcategory */
        $subcategory = $this->createQueryBuilder('s')
            ->join('s.kind', 'k')
            ->andWhere('k.area = :area')->setParameter('area', $area)
            ->andWhere('s.code = :code')->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();

        return $subcategory;
    }

    /** The largest position among a kind's sub-categories, or -1 when it has none. */
    public function maxPositionForKind(TaxonomyKind $kind): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.kind = :kind')->setParameter('kind', $kind)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }

    /** A sub-category with this label already lives under the kind (case-insensitive). */
    public function labelExistsInKind(TaxonomyKind $kind, string $label, ?TaxonomySubcategory $except = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('LOWER(s.label) = :value')->setParameter('value', mb_strtolower($label));

        return $this->countExceeds($qb, $except);
    }

    /** A sub-category with this wire-code already lives anywhere in the area. */
    public function codeExistsInArea(AreaOfInterest $area, string $code, ?TaxonomySubcategory $except = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.kind', 'k')
            ->andWhere('k.area = :area')->setParameter('area', $area)
            ->andWhere('s.code = :value')->setParameter('value', $code);

        return $this->countExceeds($qb, $except);
    }

    /**
     * WHETHER A SCOPE CAN EVER ANSWER A MONEY QUESTION: does any sub-category
     * in it run money this way at all?
     *
     * IT SEPARATES TWO ABSENCES THE PERFORMANCE PAGE MUST NOT COLLAPSE. A
     * department whose areas do carry compensation and claimed nothing scored
     * nought, and says so; a department whose areas have written no
     * compensation-bearing words has not been asked the question, and its cell
     * is {@see \Uhifadhi\Contracts\Performance\MatrixCell::notMine()}. Drawn
     * the same way, the second becomes a department that failed.
     *
     * The scope is one area, named by its uuid, or every area when null.
     */
    public function scopeRunsMoney(?string $areaUuid, MoneyDirectionEnum $direction): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.kind', 'k')
            ->andWhere('s.moneyDirection = :direction')->setParameter('direction', $direction);

        if (null !== $areaUuid) {
            $qb->join('k.area', 'a')
                ->andWhere('a.uuid = :area')
                ->setParameter('area', Uuid::fromString($areaUuid), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function countExceeds(QueryBuilder $qb, ?TaxonomySubcategory $except): bool
    {
        if (null !== $except && null !== $except->getId()) {
            $qb->andWhere('s.id != :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
