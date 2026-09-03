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
use Uhifadhi\Incident\Entity\IncidentSubcategory;

/**
 * The taxonomy's leaves — the sixteen the reference card lists, and whatever a
 * deployment added to them.
 *
 * @extends ServiceEntityRepository<IncidentSubcategory>
 */
final class IncidentSubcategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentSubcategory::class);
    }

    /**
     * The one a slug names, or null. Null rather than a throw: the slug arrives in
     * a form post, so it is untrusted, and a report flow answers a bad one with a
     * rejected form rather than a stack trace.
     */
    public function findOneBySlug(string $slug): ?IncidentSubcategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
