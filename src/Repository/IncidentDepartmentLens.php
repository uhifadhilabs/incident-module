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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * WHICH DEPARTMENT A RECORDER WAS SITTING IN — the one lookup department-as-a-lens
 * needs, and the only place this module asks it.
 *
 * DEPARTMENT IS RECORDED, NEVER RESTRICTING. An incident belongs to its AREA;
 * a department view is a READING of the area's incidents, and the reading is
 * "the records whose recording position sits in this department". The position
 * is the join: a person holds at most one, a position is filed under at most
 * one department, and somebody holding none belongs to none.
 *
 * WHY IT NAMES TeamBundle'S CLASS. No package publishes a department contract —
 * {@see \Uhifadhi\Contracts\Kpi\DepartmentRef} exists precisely because there
 * is no `DepartmentInterface` to type against — and
 * {@see \Uhifadhi\Contracts\People\PersonFacetProviderInterface} answers with a
 * department's NAME, which is not an identity a matrix row can be keyed by. So
 * the walk is made here, once, against the core this module already requires,
 * rather than smeared through a topic's arithmetic.
 *
 * ASKED FOR THE SET, NEVER PER ROW. A topic hands over every recorder it is
 * about to draw and gets one map back; a lookup per incident would be a query
 * per row behind one table.
 */
final readonly class IncidentDepartmentLens
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    /**
     * The department each of these people is seated in, keyed by the person's
     * id. Somebody holding no position, or a position filed under no
     * department, is ABSENT from the answer rather than present as null: the
     * caller reads an absence as "this record is in nobody's row".
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function departmentByUser(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{uid: int, did: int|null}> $rows */
        $rows = $this->entities
            ->createQuery(\sprintf(
                'SELECT u.id AS uid, IDENTITY(p.department) AS did FROM %s u JOIN u.position p WHERE u.id IN (:ids)',
                User::class,
            ))
            ->setParameter('ids', array_values(array_unique($userIds)))
            ->getArrayResult();

        $byUser = [];
        foreach ($rows as $row) {
            if (null !== $row['did']) {
                $byUser[$row['uid']] = $row['did'];
            }
        }

        return $byUser;
    }
}
