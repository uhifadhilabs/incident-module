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

namespace Uhifadhi\Incident\Tests\Integration\Migrations;

/**
 * THE LIST BECOMES AN INDEX, ON A DATABASE THAT WAS EDITED FOR A YEAR.
 *
 * From 0.4 a kind's hue is its PLACE in its area's list — the first kind takes
 * the first house hue — so the place has to be a number that means something.
 * It did not have to be before: `position` was only ever read as a sort key, so
 * ties broke on id and gaps cost nothing. Two areas upgrading with the rows
 * they really have is the case this rehearses:
 *
 *   TIES AND GAPS BECOME 0,1,2… in the order the editor was already seeing,
 *   which is `(position, id)` — the same order every screen sorted by. Nothing
 *   is reordered; the numbers are made to say what the order already was.
 *
 *   EACH AREA COUNTS FROM ZERO. An area owns its vocabulary, so the third kind
 *   in one area and the third in another wear the same hue, and neither is
 *   affected by how many kinds the other has.
 *
 * @see \Uhifadhi\Incident\Migrations\Version20260919220000
 * @see \Uhifadhi\Incident\Entity\TaxonomyKind::catIndex()
 */
final class KindOrderBecomesTheHueTest extends MigrationsTestCase
{
    /** The version the old, unordered shape is written at. */
    private const string BEFORE_THE_INDEX = 'Uhifadhi\Incident\Migrations\Version20260919210000';

    public function testEveryAreasKindsAreNumberedFromZeroInTheOrderTheyWereAlreadyRead(): void
    {
        $this->migrateTo(self::BEFORE_THE_INDEX);
        $this->seedTwoAreasWithMessyPositions();

        $this->migrateToLatest();

        /** @var list<array{area: string, code: string, position: int}> $rows */
        $rows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT a.name AS area, k.code, k."position"
            FROM incident_taxonomy_kind k
            JOIN area_of_interest a ON a.id = k.area_id
            ORDER BY a.name, k."position", k.id
            SQL);

        $byArea = [];
        foreach ($rows as $row) {
            $byArea[$row['area']][] = $row['code'].'='.$row['position'];
        }

        self::assertSame([
            // Two kinds tied on 0 and one parked at 5: the order the editor saw
            // is kept, and the numbers become the places in it.
            'Northern Reserve' => ['poaching=0', 'conflict=1', 'mortality=2'],
            // The second area starts again at zero, whatever the first holds.
            'Southern Reserve' => ['compliance=0', 'roadkill=1'],
        ], $byArea);
    }

    /**
     * WHAT A KIND EDITOR REALLY LEAVES BEHIND. `position` was written by
     * "max + 1" and never rewritten on a delete, so a live area holds ties (two
     * kinds created before the column was maintained), gaps (the kind between
     * them was retired and removed) and a second area with numbers of its own.
     */
    private function seedTwoAreasWithMessyPositions(): void
    {
        $connection = $this->connection();

        $connection->executeStatement(<<<'SQL'
            INSERT INTO area_of_interest (uuid, name, source, geom) VALUES
                (gen_random_uuid(), 'Northern Reserve', 'test fixture', ST_GeomFromGeoJSON('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}')),
                (gen_random_uuid(), 'Southern Reserve', 'test fixture', ST_GeomFromGeoJSON('{"type":"MultiPolygon","coordinates":[[[[-28.0,-3.6],[-27.0,-3.6],[-27.0,-2.8],[-28.0,-2.8],[-28.0,-3.6]]]]}'))
            SQL);

        foreach ([
            ['Northern Reserve', 'poaching', 'Poaching', 'poach', 0],
            ['Northern Reserve', 'conflict', 'Human-wildlife conflict', 'hwc', 0],
            ['Northern Reserve', 'mortality', 'Wildlife mortality', 'mort', 5],
            ['Southern Reserve', 'compliance', 'Compliance', 'comp', 3],
            ['Southern Reserve', 'roadkill', 'Roadkill', 'mort', 7],
        ] as [$area, $code, $label, $colour, $position]) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO incident_taxonomy_kind (uuid, area_id, code, label, colour_key, leads, "position", active, created_at, updated_at)
                    SELECT gen_random_uuid(), a.id, :code, :label, :colour, '[]', :position, true, NOW(), NOW()
                    FROM area_of_interest a WHERE a.name = :area
                    SQL,
                ['area' => $area, 'code' => $code, 'label' => $label, 'colour' => $colour, 'position' => $position],
            );
        }
    }
}
