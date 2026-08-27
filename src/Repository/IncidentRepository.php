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

namespace UhifadhiLabs\Incident\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Model\IncidentFilter;

/**
 * EVERY QUESTION THE INCIDENTS SURFACES ASK, in one place.
 *
 * ONE FILTER DRIVES EVERYTHING. The design is explicit that the map, the
 * register and the charts read the SAME query — so every counting method below
 * takes the same {@see IncidentFilter} the list does, and applies it the same
 * way ({@see applyFilter()}). A chart that quietly ignored the category chips
 * would make the dashboard lie about which numbers belong to which rows.
 *
 * THE DEPARTMENT SLICE IS REPORTING, NEVER PERMISSION. {@see findForDepartment()}
 * slices by the DEPARTMENT THE RECORDING PERSON'S POSITION IS FILED UNDER,
 * exactly as the host's KPI seam specifies. Two departments reading the same area
 * get two different numbers from the same rows — and neither is fenced out of the
 * other's rows, because nothing here ever filters what a screen may SEE by
 * department.
 *
 * @extends ServiceEntityRepository<Incident>
 */
final class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Incident
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByReference(string $reference): ?Incident
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * THE NEXT NUMBER PEOPLE WILL SAY OUT LOUD. Deployment-wide rather than
     * per-area, because a reference is quoted on radio and on paper where nobody
     * repeats which area they meant.
     *
     * Derived from the highest reference rather than from a count: incidents are
     * never deleted in practice, but a count would silently start reusing numbers
     * if one ever were, and two case files with the same number is the one
     * failure this record type cannot survive.
     */
    public function nextReference(string $prefix = 'INC-'): string
    {
        /** @var string|null $highest */
        $highest = $this->createQueryBuilder('i')
            ->select('MAX(i.reference)')
            ->andWhere('i.reference LIKE :prefix')
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $next = null === $highest ? 1 : ((int) substr($highest, \strlen($prefix))) + 1;

        return \sprintf('%s%04d', $prefix, $next);
    }

    /**
     * The register, the feed and the map all read this — one query, one filter,
     * newest first.
     *
     * @return list<Incident>
     */
    public function findFiltered(IncidentFilter $filter, ?int $limit = null): array
    {
        $qb = $this->loaded($filter)
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Incident> $incidents */
        $incidents = $qb->getQuery()->getResult();

        return $incidents;
    }

    /**
     * THE REGISTER BEHIND THE REPORT DRAWER — this area's newest filings, capped.
     *
     * Deliberately NOT {@see findFiltered()}: the drawer's backdrop answers "what
     * am I filing next to", and a month window would empty it on the first of the
     * month, leaving a drawer over nothing. Unfiltered and unpaged, because it is
     * context rather than a listing nobody can act on through a backdrop.
     *
     * @return list<Incident>
     */
    public function recentForArea(AreaOfInterest $area, int $limit): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * EVERYTHING WAITING ON ONE PERSON, oldest first: an incident they reported
     * that nobody has verified, or one they are the responder on. Closed and
     * resolved work is not waiting on anybody, so it is not here.
     *
     * @return list<Incident>
     */
    public function queueFor(User $user, ?AreaOfInterest $area = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', ['reported', 'verified', 'in_progress'])
            ->andWhere('i.assignedTo = :user OR (i.reportedBy = :user AND i.status = :reported)')
            ->setParameter('user', $user)
            ->setParameter('reported', 'reported')
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        if (null !== $area) {
            $qb->andWhere('i.area = :area')->setParameter('area', $area);
        }

        /** @var list<Incident> $incidents */
        $incidents = $qb->getQuery()->getResult();

        return $incidents;
    }

    /**
     * THE ONE THIS PERSON LAST TOUCHED — what the "where things stand" rail is
     * scoped to. "Touched" means appearing as the actor on the most recent event,
     * which is precisely the set of things a person did: a transition, a note, an
     * attachment, a change to the money.
     *
     * Null when they have touched nothing; the rail then falls back to the head of
     * {@see queueFor()} and says so in its own header, rather than rendering an
     * empty card.
     */
    public function lastTouchedBy(User $user, ?AreaOfInterest $area = null): ?Incident
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.events', 'e')
            ->andWhere('e.actor = :user')
            ->setParameter('user', $user)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1);

        if (null !== $area) {
            $qb->andWhere('i.area = :area')->setParameter('area', $area);
        }

        /** @var Incident|null $incident */
        $incident = $qb->getQuery()->getOneOrNullResult();

        return $incident;
    }

    /**
     * EVERY INCIDENT THIS DEPARTMENT'S PEOPLE RECORDED in a window, with the
     * taxonomy and the money already loaded — the rows behind every department
     * KPI plate.
     *
     * ONE query rather than one per figure, and the arithmetic in PHP: the four
     * plates are four readings of the same month's work, and asking four times
     * would let them disagree. The set is one department's filings in one month,
     * which is the same order of magnitude as the register the module already
     * draws.
     *
     * The slice is by the position the RECORDER holds, per the host's seam. It is
     * reporting and not permission: nothing else in this class filters by
     * department, and no screen may.
     *
     * @return list<Incident>
     */
    public function findForDepartment(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->departmentScoped($department, $from, $to)
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->leftJoin('i.money', 'm')->addSelect('m')
            ->orderBy('i.reportedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * How many incidents this department's people recorded, per area — what the
     * per-area performance widget reads. Keyed by area name because that is what
     * the widget prints and the host's {@see \Uhifadhi\Module\DepartmentKpi} keys
     * an area's share by.
     *
     * @return array<string, int>
     */
    public function countForDepartmentByArea(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{name: string, n: int|string}> $rows */
        $rows = $this->departmentScoped($department, $from, $to)
            ->join('i.area', 'a')
            ->select('a.name AS name, COUNT(i.id) AS n')
            ->groupBy('a.name')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['name']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The most recent evidence across every incident the filter matches — the
     * "latest evidence" widget.
     *
     * Rooted on the EVIDENCE and joined back to the incident, not the other way
     * round: DQL cannot select a joined entity without its root, and the widget
     * wants the twelve newest photographs in the area, never the photographs of
     * the twelve newest incidents. Those are different lists.
     *
     * @return list<\UhifadhiLabs\Incident\Entity\IncidentEvidence>
     */
    public function latestEvidence(IncidentFilter $filter, int $limit): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('ev')
            ->from(\UhifadhiLabs\Incident\Entity\IncidentEvidence::class, 'ev')
            ->join('ev.incident', 'i')->addSelect('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->orderBy('ev.capturedAt', 'DESC')
            ->addOrderBy('ev.id', 'DESC')
            ->setMaxResults($limit);

        /** @var list<\UhifadhiLabs\Incident\Entity\IncidentEvidence> $evidence */
        $evidence = $this->applyFilter($qb, $filter)->getQuery()->getResult();

        return $evidence;
    }

    /**
     * THE ONE QUERY EVERYTHING ELSE IS BUILT ON: the filter, applied once, with
     * the taxonomy joined because every screen prints the category beside the row.
     */
    private function filtered(IncidentFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')
            ->join('s.category', 'c');

        return $this->applyFilter($qb, $filter);
    }

    /**
     * The same query with the taxonomy and the money ALREADY LOADED — what the
     * dashboard reads. Every figure on that screen is computed from these rows,
     * so a lazy association here would be a query per widget per incident.
     */
    private function loaded(IncidentFilter $filter): QueryBuilder
    {
        return $this->filtered($filter)
            ->addSelect('s')
            ->addSelect('c')
            ->leftJoin('i.money', 'm')->addSelect('m')
            ->leftJoin('i.zone', 'lz')->addSelect('lz');
    }

    private function applyFilter(QueryBuilder $qb, IncidentFilter $filter): QueryBuilder
    {
        $qb->andWhere('i.area = :area')->setParameter('area', $filter->area);

        if (null !== $filter->from) {
            $qb->andWhere('i.reportedAt >= :from')->setParameter('from', $filter->from);
        }
        if (null !== $filter->to) {
            $qb->andWhere('i.reportedAt < :to')->setParameter('to', $filter->to);
        }
        if ([] !== $filter->categorySlugs) {
            $qb->andWhere('c.slug IN (:categorySlugs)')->setParameter('categorySlugs', $filter->categorySlugs);
        }
        if ([] !== $filter->statuses) {
            $qb->andWhere('i.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn ($s) => $s->value, $filter->statuses));
        }
        if (null !== $filter->zoneName) {
            $qb->join('i.zone', 'fz')->andWhere('fz.name = :zoneName')->setParameter('zoneName', $filter->zoneName);
        }
        if (null !== $filter->search && '' !== $filter->search) {
            $qb->andWhere('LOWER(i.reference) LIKE :search OR LOWER(i.title) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($filter->search).'%');
        }

        return $qb;
    }

    /**
     * Incidents recorded in a window by somebody holding a position filed under
     * this department — the host KPI seam's slice, written once.
     */
    private function departmentScoped(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->join('i.reportedBy', 'u')
            ->join('u.position', 'p')
            ->andWhere('p.department = :department')->setParameter('department', $department)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to);
    }
}
