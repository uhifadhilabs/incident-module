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
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\IncidentEvent;

/**
 * The query surface over events on one incident, oldest first — the append-only timeline.
 *
 * Deliberately thin: everything a screen asks about an incident it asks through
 * {@see IncidentRepository}, because a dashboard question is always a question
 * about incidents and only incidentally about their parts.
 *
 * @extends ServiceEntityRepository<IncidentEvent>
 */
final class IncidentEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentEvent::class);
    }

    /**
     * EVERY MOVE THIS MODULE MADE IN ONE AREA IN A WINDOW — what the host's area
     * pulse merges with every other module's moves.
     *
     * The one question a screen asks of events rather than of incidents, and the
     * reason is the seam: the pulse is a log of MOVES, not of records, so the
     * event IS the row and the incident is what it happened to. Newest first,
     * because that is the order the pulse draws and sorting it twice would be
     * the host re-deciding something the query already knows.
     *
     * @return list<IncidentEvent>
     */
    public function movesIn(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        /** @var list<IncidentEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->join('e.incident', 'i')->addSelect('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('e.occurredAt >= :since')->setParameter('since', $since)
            ->andWhere('e.occurredAt <= :until')->setParameter('until', $until)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $events;
    }
}
