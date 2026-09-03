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
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Incident\Entity\IncidentCategory;

/**
 * The taxonomy's top level. It is SEEDED DATA, so every read here is ordered by
 * the position a deployment gave it — the reference card, the report flow's
 * chooser and the register's filter chips all draw the same four in the same
 * order, and an order that varied per screen would read as four different
 * taxonomies.
 *
 * @extends ServiceEntityRepository<IncidentCategory>
 */
final class IncidentCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentCategory::class);
    }

    /**
     * Every kind of incident this deployment knows, in its own order, with the
     * sub-categories already loaded — the reference card prints all sixteen, and
     * a lazy collection there would be four extra queries to draw one card.
     *
     * @return list<IncidentCategory>
     */
    public function allInOrder(): array
    {
        /** @var list<IncidentCategory> $categories */
        $categories = $this->createQueryBuilder('c')
            ->leftJoin('c.subcategories', 's')->addSelect('s')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    public function findOneBySlug(string $slug): ?IncidentCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
