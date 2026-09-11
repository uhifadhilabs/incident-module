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
 * THE CONVERGENCE, REHEARSED ON THE SHAPE IT HAS TO SURVIVE.
 *
 * {@see MigrationsUpgradeKeepsDataTest} seeds through this module's services,
 * which can only produce the CURRENT shape — so it can never rehearse the one
 * upgrade that matters here: a database that was filled by the OLD model, where
 * every area shared one installation-wide vocabulary.
 *
 * So this one writes that shape in raw SQL, at the version before the converging
 * one, and then runs it. The question it answers is the only question an
 * installation has:
 *
 *   TWO AREAS THAT SHARED A WORD MUST END UP WITH TWO WORDS. They were filing
 *   `livestock-depredation` against ONE row; afterwards each has its own, and an
 *   incident points at ITS area's copy. An incident that ended up pointing across
 *   the boundary would have moved a case file into another area's vocabulary,
 *   which is the failure this whole model exists to prevent.
 *
 *   WHAT THE WORD CARRIED COMES WITH IT. The money direction and the term are
 *   what the money panel and the ageing widget read; a convergence that kept the
 *   labels and lost the behaviour would leave every screen drawing the right
 *   words about the wrong rules.
 *
 * @see \Uhifadhi\Incident\Migrations\Version20260911140000
 */
final class TaxonomyConvergenceKeepsDataTest extends MigrationsTestCase
{
    /** The version the old shape is written at — the last one before the convergence. */
    private const string BEFORE_THE_CONVERGENCE = 'Uhifadhi\Incident\Migrations\Version20260911090500';

    public function testEveryIncidentEndsUpOnItsOwnAreasWordAndTheTwoVocabulariesStayApart(): void
    {
        $this->migrateTo(self::BEFORE_THE_CONVERGENCE);
        $this->seedTheOldShape();

        $this->migrateToLatest();

        /** @var list<array{reference: string, area: string, code: string, kind: string, money_direction: string|null, term_hours: int, word_area_id: int, incident_area_id: int}> $rows */
        $rows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT i.reference, a.name AS area, ts.code, tk.code AS kind, ts.money_direction, ts.term_hours,
                   tk.area_id AS word_area_id, i.area_id AS incident_area_id
            FROM incident i
            JOIN area_of_interest a ON a.id = i.area_id
            JOIN incident_taxonomy_subcategory ts ON ts.id = i.taxonomy_subcategory_id
            JOIN incident_taxonomy_kind tk ON tk.id = ts.kind_id
            ORDER BY i.reference
            SQL);

        self::assertCount(4, $rows, 'Every seeded incident kept a sub-category, and it is one of the new ones.');

        foreach ($rows as $row) {
            self::assertSame(
                $row['incident_area_id'],
                $row['word_area_id'],
                \sprintf('%s was filed in "%s" and must point at THAT area\'s word.', $row['reference'], $row['area']),
            );
        }

        // The two areas shared both slugs before; afterwards each holds its own
        // pair, and no row is reachable from the other area.
        $byReference = [];
        foreach ($rows as $row) {
            $byReference[$row['reference']] = $row;
        }

        self::assertSame('livestock-depredation', $byReference['INC-0001']['code']);
        self::assertSame('livestock-depredation', $byReference['INC-0003']['code']);
        self::assertNotSame(
            $byReference['INC-0001']['word_area_id'],
            $byReference['INC-0003']['word_area_id'],
            'Two areas filing the same word now hold two rows, not one shared row.',
        );

        self::assertSame(
            [4, 2],
            [$this->rowsIn('incident_taxonomy_subcategory'), $this->rowsIn('incident_taxonomy_kind')],
            'Each area got a copy of exactly the two words it was using, under a copy of the kind above them.',
        );

        // What the word carried came with it, per area and per word.
        self::assertSame('compensation', $byReference['INC-0001']['money_direction']);
        self::assertSame(720, $byReference['INC-0001']['term_hours']);
        self::assertSame('fine', $byReference['INC-0002']['money_direction']);
        self::assertSame(168, $byReference['INC-0002']['term_hours']);

        // The money rows are untouched and still hang off the same incidents.
        self::assertSame(2, $this->rowsIn('incident_money'));
        self::assertSame(
            [900_000, 250_000],
            $this->connection()->fetchFirstColumn(
                'SELECT m.claimed FROM incident_money m JOIN incident i ON i.id = m.incident_id ORDER BY i.reference',
            ),
        );
    }

    /**
     * THE OLD SHAPE, WRITTEN THE WAY IT REALLY WAS: one installation-wide kind
     * and two sub-categories under it, two areas, and four incidents — each area
     * filing both words, which is exactly the collision the per-area model has to
     * take apart.
     *
     * Raw SQL, deliberately. There is no code left that can write these rows, and
     * a fixture built out of the current entities would be rehearsing the
     * migration against a database that could never have existed.
     */
    private function seedTheOldShape(): void
    {
        $connection = $this->connection();

        $connection->executeStatement(<<<'SQL'
            INSERT INTO area_of_interest (uuid, name, source, geom) VALUES
                (gen_random_uuid(), 'Northern Reserve', 'test fixture', ST_GeomFromGeoJSON('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}')),
                (gen_random_uuid(), 'Southern Reserve', 'test fixture', ST_GeomFromGeoJSON('{"type":"MultiPolygon","coordinates":[[[[-28.0,-3.6],[-27.0,-3.6],[-27.0,-2.8],[-28.0,-2.8],[-28.0,-3.6]]]]}'))
            SQL);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO incident_category (uuid, slug, label, colour_key, leads, position, created_at, updated_at)
            VALUES (gen_random_uuid(), 'conflict', 'Human–wildlife conflict', 'hwc', '["Protection Service"]', 0, NOW(), NOW())
            SQL);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO incident_subcategory (uuid, slug, label, money_direction, term_hours, field_set, position, category_id, created_at, updated_at)
            SELECT gen_random_uuid(), v.slug, v.label, v.money, v.term, v.fields::json, v.pos, c.id, NOW(), NOW()
            FROM incident_category c,
                 (VALUES ('livestock-depredation', 'livestock depredation', 'compensation', 720, '[{"key":"species","label":"Species"}]', 0),
                         ('roadkill', 'roadkill', 'fine', 168, '[{"key":"species","label":"Species"}]', 1))
                 AS v(slug, label, money, term, fields, pos)
            WHERE c.slug = 'conflict'
            SQL);

        // Four incidents: both areas filing both words, so every slug collides
        // across the boundary.
        foreach ([
            ['INC-0001', 'Northern Reserve', 'livestock-depredation'],
            ['INC-0002', 'Northern Reserve', 'roadkill'],
            ['INC-0003', 'Southern Reserve', 'livestock-depredation'],
            ['INC-0004', 'Southern Reserve', 'roadkill'],
        ] as [$reference, $area, $slug]) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO incident (uuid, reference, title, position, status, severity, source, reported_at, details, area_id, subcategory_id, created_at, updated_at)
                    SELECT gen_random_uuid(), :reference, :reference, ST_SetSRID(ST_MakePoint(-29.5, -3.2), 4326),
                           'reported', 'moderate', 'direct', NOW(), '{}', a.id, s.id, NOW(), NOW()
                    FROM area_of_interest a, incident_subcategory s
                    WHERE a.name = :area AND s.slug = :slug
                    SQL,
                ['reference' => $reference, 'area' => $area, 'slug' => $slug],
            );
        }

        // Money on one incident per direction, because the direction is the thing
        // the convergence could most easily drop.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO incident_money (uuid, direction, currency, claimed, settled, incident_id, created_at, updated_at)
            SELECT gen_random_uuid(), 'compensation', 'TZS', 900000, 0, i.id, NOW(), NOW()
            FROM incident i WHERE i.reference = 'INC-0001'
            SQL);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO incident_money (uuid, direction, currency, claimed, settled, incident_id, created_at, updated_at)
            SELECT gen_random_uuid(), 'fine', 'TZS', 250000, 0, i.id, NOW(), NOW()
            FROM incident i WHERE i.reference = 'INC-0002'
            SQL);
    }

    private function rowsIn(string $table): int
    {
        $count = $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));

        return is_numeric($count) ? (int) $count : -1;
    }
}
