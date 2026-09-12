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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\AreaListEnum;

/**
 * THE WORDS ONE AREA'S QUESTIONS OFFER. Every read here is confined to one area
 * and one list: a query that forgot either would hand a filer the neighbouring
 * area's animals, which is the one thing the per-area list exists to prevent.
 *
 * @extends ServiceEntityRepository<AreaListEntry>
 */
final class AreaListEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaListEntry::class);
    }

    /**
     * One list's words in their own order, RETIRED ONES INCLUDED — the editor
     * dims a retired row, it does not hide it.
     *
     * @return list<AreaListEntry>
     */
    public function forAreaAndList(AreaOfInterest $area, AreaListEnum $list): array
    {
        /** @var list<AreaListEntry> $entries */
        $entries = $this->ordered($area)
            ->andWhere('e.list = :list')->setParameter('list', $list->value)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * Every word this area holds, in every list, keyed by the list's own wire
     * value — one query for the four, because every page that needs one list's
     * words needs all four.
     *
     * @return array<string, list<AreaListEntry>>
     */
    public function forArea(AreaOfInterest $area): array
    {
        $byList = [];
        foreach (AreaListEnum::cases() as $list) {
            $byList[$list->value] = [];
        }

        /** @var list<AreaListEntry> $entries */
        $entries = $this->ordered($area)->getQuery()->getResult();
        foreach ($entries as $entry) {
            $byList[$entry->getList()->value][] = $entry;
        }

        return $byList;
    }

    public function findOneByAreaAndUuid(AreaOfInterest $area, string $uuid): ?AreaListEntry
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findOneBy(['area' => $area, 'uuid' => $uuid]);
    }

    /** A word with this label already lives in this list (case-insensitive). */
    public function labelExistsInList(AreaOfInterest $area, AreaListEnum $list, string $label, ?AreaListEntry $except = null): bool
    {
        return $this->matchExists($area, $list, 'LOWER(e.label)', mb_strtolower($label), $except);
    }

    /** A word with this key already lives in this list. */
    public function keyExistsInList(AreaOfInterest $area, AreaListEnum $list, string $key): bool
    {
        return $this->matchExists($area, $list, 'e.key', $key, null);
    }

    /** The largest position in this list, or -1 when it holds nothing yet. */
    public function maxPositionForList(AreaOfInterest $area, AreaListEnum $list): int
    {
        $max = $this->createQueryBuilder('e')
            ->select('MAX(e.position)')
            ->andWhere('e.area = :area')->setParameter('area', $area)
            ->andWhere('e.list = :list')->setParameter('list', $list->value)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }

    /**
     * HOW OFTEN EACH WORD WAS ANSWERED — this month, and all time.
     *
     * Counted off the answers themselves, at the one place in an incident's
     * block answers where this list's question keeps its answer: the block's
     * object, under the question's key. So the figure is a fact about the
     * register rather than a counter something has to remember to increment, and
     * a retired word keeps the count it earned.
     *
     * A word that was never answered is absent from the result, not zero — the
     * caller knows its own list and reads a missing key as none.
     *
     * Raw SQL because DQL cannot walk into a JSON document, and every table and
     * column name is read from Doctrine's metadata rather than spelled out: an
     * installation may name its columns with a different naming strategy than
     * this suite does. The path is a bound parameter and not interpolated,
     * `#>>` with a `text[]`, which is how Postgres reads a nested value out.
     *
     * @param string $blockValue  the behaviour block's wire value, whose object holds the answer
     * @param string $questionKey the key the question keeps its answer under
     *
     * @return array<string, array{month: int, all: int}> keyed by the answer as stored
     */
    public function countsByAnswer(
        AreaOfInterest $area,
        string $blockValue,
        string $questionKey,
        \DateTimeImmutable $since,
    ): array {
        $incident = $this->getEntityManager()->getClassMetadata(Incident::class);

        $sql = \sprintf(
            <<<'SQL'
                SELECT CAST(i.%1$s AS jsonb) #>> CAST(:path AS text[]) AS answer,
                       COUNT(*) AS all_time,
                       COUNT(*) FILTER (WHERE i.%2$s >= :since) AS this_month
                FROM %3$s i
                WHERE i.%4$s = :area
                  AND CAST(i.%1$s AS jsonb) #>> CAST(:path AS text[]) IS NOT NULL
                GROUP BY 1
                SQL,
            $incident->getColumnName('blockAnswers'),
            $incident->getColumnName('reportedAt'),
            $incident->getTableName(),
            $incident->getSingleAssociationJoinColumnName('area'),
        );

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'path' => '{'.$blockValue.','.$questionKey.'}',
            'since' => $since,
            'area' => $area->getId(),
        ], [
            'path' => Types::STRING,
            'since' => Types::DATETIME_IMMUTABLE,
            'area' => Types::INTEGER,
        ]);

        $counts = [];
        foreach ($rows as $row) {
            $answer = $row['answer'];
            if (!\is_string($answer) || '' === $answer) {
                continue;
            }

            $counts[$answer] = [
                'month' => is_numeric($row['this_month']) ? (int) $row['this_month'] : 0,
                'all' => is_numeric($row['all_time']) ? (int) $row['all_time'] : 0,
            ];
        }

        return $counts;
    }

    private function ordered(AreaOfInterest $area): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.area = :area')->setParameter('area', $area)
            ->orderBy('e.list', 'ASC')
            ->addOrderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC');
    }

    private function matchExists(AreaOfInterest $area, AreaListEnum $list, string $expr, string $value, ?AreaListEntry $except): bool
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.area = :area')->setParameter('area', $area)
            ->andWhere('e.list = :list')->setParameter('list', $list->value)
            ->andWhere($expr.' = :value')->setParameter('value', $value);

        if (null !== $except && null !== $except->getId()) {
            $qb->andWhere('e.id != :except')->setParameter('except', $except->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
