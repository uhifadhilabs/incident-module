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
use Uhifadhi\Incident\Entity\IncidentEvidence;

/**
 * The query surface over photographs and documents attached to incidents.
 *
 * Deliberately thin: everything a screen asks about an incident it asks through
 * {@see IncidentRepository}, because a dashboard question is always a question
 * about incidents and only incidentally about their parts.
 *
 * @extends ServiceEntityRepository<IncidentEvidence>
 */
final class IncidentEvidenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentEvidence::class);
    }

    /**
     * Every piece of evidence this module holds, each with the chain the Files
     * hub prints on its tile: the incident it belongs to, and that incident's
     * area.
     *
     * The joins are the whole point. The hub reads the owner and the area off
     * EVERY file it draws, so a lazy association here would be one query per
     * photograph.
     *
     * Rows with no stored path are NOT filtered out in SQL, deliberately: what
     * counts as a usable key is the storage seam's rule, and it is applied where
     * that rule lives ({@see \Uhifadhi\Incident\Storage\IncidentFileSource}),
     * not spread into a query nothing else reads.
     *
     * @return list<IncidentEvidence>
     */
    public function findForFilesHub(): array
    {
        /** @var list<IncidentEvidence> $rows */
        $rows = $this->createQueryBuilder('e')
            ->addSelect('i', 'a')
            ->join('e.incident', 'i')
            ->join('i.area', 'a')
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The row that owns a storage key — the lookup the Files hub's guard rests
     * on.
     *
     * Answering NULL is what refuses a key this module does not actually hold,
     * so it must never be widened into a LIKE or a prefix match.
     */
    public function findOneByPath(string $path): ?IncidentEvidence
    {
        return $this->findOneBy(['path' => $path]);
    }
}
