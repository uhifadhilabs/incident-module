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
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

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
            // The zone comes with it: every row that prints one prints it, and a
            // lazy association here is one query per row on a list of rows.
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /*
     * ── WHAT THE AREA OVERVIEW ASKS ─────────────────────────────────────────
     *
     * The module's own dashboard loads ONE month and reads it nine ways, because
     * every widget on that screen is a reading of the same window. The overview
     * is the opposite shape: four small cards asking four unrelated questions
     * about four different sets — where the open work is (all of it), what came
     * in today, the newest handful, and what is still owed (which has no window
     * at all). Loading a month would answer none of them, and loading the whole
     * register to count it in PHP would be a table scan for a bar chart.
     *
     * So: aggregates in SQL where the set is unbounded, and rows in PHP where
     * the set is bounded by the question itself (open work, today's filings, an
     * area's outstanding money).
     */

    /** How many incidents this area has ever had — the denominator on "6 of 47". */
    public function countFor(AreaOfInterest $area): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * THE FIVE-STATE BAR: how many incidents are sitting in each place right now.
     *
     * In SQL rather than in PHP because it is the ONE overview figure whose set
     * is the whole register: an area with ten years of filings would otherwise
     * load ten years to draw five numbers.
     *
     * @return array<string, int> status value => count, every place present
     */
    public function statusTallyFor(AreaOfInterest $area): array
    {
        // Doctrine converts an `enumType` column even in a scalar select, so the
        // key comes back as the enum and not as the string it is stored as.
        /** @var list<array{status: IncidentStatusEnum, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS n')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();

        // Every place present, at zero where nothing is there — a bar that
        // dropped an empty segment would redraw itself on a quiet week and the
        // reader would think the workflow had changed.
        $tally = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $tally[$place->value] = 0;
        }
        foreach ($rows as $row) {
            $tally[$row['status']->value] = (int) $row['n'];
        }

        return $tally;
    }

    /**
     * EVERY INCIDENT STILL SOMEBODY'S WORK, with the taxonomy and the zone
     * loaded — the set behind the past-term list, the attention items and the
     * open-incidents map layer.
     *
     * Rows and not a count, because each of those needs the incident itself; the
     * set is bounded by the work being open, which is the number an area manager
     * could in principle read one morning.
     *
     * @return list<Incident>
     */
    public function openFor(AreaOfInterest $area): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', array_map(
                static fn (IncidentStatusEnum $place) => $place->value,
                array_filter(IncidentStatusEnum::ordered(), static fn (IncidentStatusEnum $place) => $place->isOpen()),
            ))
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * What was FILED in a window — one day of it, on the overview.
     *
     * @return list<Incident>
     */
    public function filedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to)
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * HOW MANY WERE FINISHED WITH in a window — resolved or closed IN it, whenever
     * they were filed. A different question from {@see filedBetween()} and
     * deliberately not reconcilable with it: a day's work is not a day's filings.
     */
    public function countClosedOutBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->closedOut($area, $from, $to)
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The same set as rows — what the map's "resolved &amp; closed" layer draws.
     *
     * THE LAYER IS WINDOWED AND THE LEGEND SAYS SO. Every incident an area ever
     * closed is unbounded and, on the morning this page is for, uninteresting: a
     * plate about today does not need last year's roadkill. The window is the
     * layer's own, stated in its label, rather than a silent cap that would make
     * the map disagree with the register.
     *
     * @return list<Incident>
     */
    public function closedOutBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->closedOut($area, $from, $to)
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->orderBy('i.reportedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    private function closedOut(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('(i.resolvedAt >= :from AND i.resolvedAt < :to) OR (i.closedAt >= :from AND i.closedAt < :to)')
            ->setParameter('from', $from)
            ->setParameter('to', $to);
    }

    /**
     * THE MONEY THIS AREA IS STILL OWED OR STILL OWES — every incident whose money
     * record has not been settled and has not been waived, oldest first.
     *
     * The outstanding balance is derived in PHP everywhere else
     * ({@see \Uhifadhi\Incident\Entity\IncidentMoney::outstanding()}), and the
     * SQL here says the same thing in SQL rather than loading every money record
     * an area has ever had to filter four of them out. The COALESCE is that
     * method's own fallback chain — approved, then assessed, then claimed — and if
     * one changes the other must.
     *
     * NO WINDOW. This is the one figure on the overview that is not about today:
     * a claim approved in july is still unpaid in august, and windowing it would
     * quietly forgive it on the first of the month.
     *
     * @return list<Incident>
     */
    public function outstandingMoneyFor(AreaOfInterest $area): array
    {
        /** @var list<Incident> $incidents */
        $incidents = $this->createQueryBuilder('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->leftJoin('i.zone', 'z')->addSelect('z')
            ->join('i.money', 'm')->addSelect('m')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('m.waivedAt IS NULL')
            ->andWhere('COALESCE(m.approved, m.assessed, m.claimed, 0) > m.settled')
            ->orderBy('i.reportedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $incidents;
    }

    /**
     * The money PUT ON THE RECORD in a window, both directions, as the sums the
     * "assessed this month" line prints.
     *
     * Scoped by when the INCIDENT was filed, because a figure has no timestamp of
     * its own — which is exactly why the line says "assessed this month" against a
     * month of filings and not "assessed in the last thirty days".
     *
     * @return array<string, int> money direction value => the sum signed off
     */
    public function moneyAssessedBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{direction: MoneyDirectionEnum, total: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->join('i.money', 'm')
            ->select('m.direction AS direction, SUM(COALESCE(m.approved, m.assessed, m.claimed, 0)) AS total')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to)
            ->groupBy('m.direction')
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach (MoneyDirectionEnum::cases() as $direction) {
            $totals[$direction->value] = 0;
        }
        foreach ($rows as $row) {
            $totals[$row['direction']->value] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * How long each verified incident took to be verified, in hours — the set the
     * MEDIAN is taken over.
     *
     * Two columns rather than the rows, because nothing about this figure needs
     * an incident: it is the one aggregate on the overview whose set really is
     * "everything this area ever verified".
     *
     * @return list<float>
     */
    public function hoursToVerifyFor(AreaOfInterest $area): array
    {
        /** @var list<array{reportedAt: \DateTimeImmutable, verifiedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.reportedAt AS reportedAt, i.verifiedAt AS verifiedAt')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->andWhere('i.verifiedAt IS NOT NULL')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): float => ($row['verifiedAt']->getTimestamp() - $row['reportedAt']->getTimestamp()) / 3600,
            $rows,
        );
    }

    /**
     * THE TERMS THIS AREA ACTUALLY WORKS TO, shortest promise first.
     *
     * Read from the register rather than from the taxonomy as configured: the
     * card names the shortest and the longest term an incident HERE is held to,
     * and a deployment with a two-hour category nobody in this area has ever
     * filed under would otherwise be quoted a term it does not keep.
     *
     * @return list<IncidentSubcategory>
     */
    public function termsInUseFor(AreaOfInterest $area): array
    {
        /** @var list<IncidentSubcategory> $subcategories */
        $subcategories = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(IncidentSubcategory::class, 's')
            ->join(Incident::class, 'i', Join::WITH, 'i.subcategory = s')
            ->andWhere('i.area = :area')->setParameter('area', $area)
            ->groupBy('s.id')
            ->orderBy('s.termHours', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subcategories;
    }

    /**
     * EVERYTHING WAITING ON ONE PERSON, oldest first: an incident they reported
     * that nobody has verified, or one they are the responder on. Closed and
     * resolved work is not waiting on anybody, so it is not here.
     *
     * @return list<Incident>
     */
    public function queueFor(UserInterface $user, ?AreaOfInterest $area = null): array
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
    public function lastTouchedBy(UserInterface $user, ?AreaOfInterest $area = null): ?Incident
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
     * The slice is by the position the RECORDER holds, per the area module's
     * seam. It is reporting and not permission: nothing else in this class
     * filters by department, and no screen may.
     *
     * THE DEPARTMENT ARRIVES AS AN ID, because no package publishes a contract
     * for one — {@see \Uhifadhi\Area\Kpi\DepartmentRef} is the same decision made
     * one layer up, and by the time the question reaches SQL it is one integer
     * anyway.
     *
     * @return list<Incident>
     */
    public function findForDepartment(int $departmentId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $scoped = $this->departmentScoped($departmentId, $from, $to);
        if (null === $scoped) {
            return [];
        }

        /** @var list<Incident> $incidents */
        $incidents = $scoped
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
     * the widget prints and the host's {@see \Uhifadhi\Area\Kpi\DepartmentKpi} keys
     * an area's share by.
     *
     * @return array<string, int>
     */
    public function countForDepartmentByArea(int $departmentId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $scoped = $this->departmentScoped($departmentId, $from, $to);
        if (null === $scoped) {
            return [];
        }

        /** @var list<array{name: string, n: int|string}> $rows */
        $rows = $scoped
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
     * @return list<\Uhifadhi\Incident\Entity\IncidentEvidence>
     */
    public function latestEvidence(IncidentFilter $filter, int $limit): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('ev')
            ->from(\Uhifadhi\Incident\Entity\IncidentEvidence::class, 'ev')
            ->join('ev.incident', 'i')->addSelect('i')
            ->join('i.subcategory', 's')->addSelect('s')
            ->join('s.category', 'c')->addSelect('c')
            ->orderBy('ev.capturedAt', 'DESC')
            ->addOrderBy('ev.id', 'DESC')
            ->setMaxResults($limit);

        /** @var list<\Uhifadhi\Incident\Entity\IncidentEvidence> $evidence */
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
     * this department — the area module's KPI slice, written once.
     *
     * THE CHAIN IS WALKED IN THE MAPPING, NOT ASSUMED. `incident → reportedBy`
     * points at whatever class the installation resolved the person contract to,
     * and only an installation that also has an ORG CHART gives that class a
     * `position`, and the position a `department`. An installation with a bare
     * account class of its own has neither, and asking for `u.position` there is
     * not a wrong answer but a DQL error at query time.
     *
     * So the associations are checked before they are named, and NULL means "this
     * installation cannot answer department questions at all" — which the callers
     * report as no rows rather than as zero. That is the honest reading: nobody
     * here holds a position, so nobody's filings are this department's.
     */
    private function departmentScoped(int $departmentId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?QueryBuilder
    {
        $entityManager = $this->getEntityManager();
        $account = $entityManager->getClassMetadata(Incident::class)->getAssociationTargetClass('reportedBy');
        $accountMeta = $entityManager->getClassMetadata($account);
        if (!$accountMeta->hasAssociation('position')) {
            return null;
        }
        $positionMeta = $entityManager->getClassMetadata($accountMeta->getAssociationTargetClass('position'));
        if (!$positionMeta->hasAssociation('department')) {
            return null;
        }

        return $this->createQueryBuilder('i')
            ->join('i.reportedBy', 'u')
            ->join('u.position', 'p')
            // Compared against the IDENTIFIER, never against an entity: this
            // repository is given an id precisely so it never has to name the
            // class the id belongs to.
            ->andWhere('p.department = :department')->setParameter('department', $departmentId)
            ->andWhere('i.reportedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.reportedAt < :to')->setParameter('to', $to);
    }
}
