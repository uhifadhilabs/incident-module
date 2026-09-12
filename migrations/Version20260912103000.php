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

namespace Uhifadhi\Incident\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE ANSWERS TAKE THE SHAPE THE BLOCKS ASK IN.
 *
 * A sub-category's questions come from the behaviour blocks it switched on. Each
 * block keeps its own answers — the singles in its object, the lists as ROWS,
 * because "twelve snares, two carcasses, one bicycle" is one record and a flat
 * name per answer could never hold it. So `incident.block_answers` is added, and
 * every answer a record already carries that has a block to go to arrives in it.
 *
 * AND THE FIGURE ASKED AT FILING GETS ITS OWN HOME. The money block asks the
 * claimant's own figure before anybody has judged it;
 * `incident.claimed_at_filing` is where it goes. It is NOT the money record: that
 * one is still opened in the state the money flow names, by whoever assesses or
 * approves, and this column is never read as an assessment.
 *
 * EXPAND → BACKFILL → CONTRACT, and the contract is a LATER release:
 *
 *  1. Both columns are added — the object nullable, filled, then tightened; the
 *     figure stays nullable, because a record filed before the question existed
 *     has no answer to it and inventing one would be a lie about a number.
 *  2. Each block is filled from the keys whose NAME says what they answered:
 *     `snares_lifted` says what was counted, `road_segment` says what kind of
 *     place it was, `suspects` says the role. The measures carry their unit in the
 *     option the row holds, the way the form asks for them.
 *  3. TWO KINDS OF KEY ARE DELIBERATELY LEFT WHERE THEY ARE, and the column they
 *     are in is kept, so nothing is lost by this version:
 *       · a key whose name does not say what it counted — `quantity` answered
 *         "3 sacks, dried" under one word and "2 animals" under another, and
 *         choosing a counted thing for it would be writing the record;
 *       · a key no block asks for at all — `enclosure`, `crop`, `circumstances`,
 *         `signs` and their like came from a free-text field list, and the
 *         product asks nothing of the sort now.
 *     `owner` and `occupier` arrive as a party row with the NAME and no role: the
 *     five roles the block names have no word for them, and calling an occupier a
 *     suspect would put an accusation in a record that never made one.
 *
 * WHAT THE NEXT RELEASE DROPS, and it is `@destructive` when it does:
 * `incident.details` and `incident_taxonomy_subcategory.field_set`. Nothing
 * writes either from this version on; they are kept one release so an
 * installation can read what a record used to say and can roll the code back.
 * Until that version ships, `doctrine:migrations:diff` proposes dropping them
 * both — that proposal is the deferral working, not drift, and it is not applied.
 */
final class Version20260912103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An incident keeps its answers per behaviour block, and the figure asked at filing in its own column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD block_answers JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE incident ADD claimed_at_filing INT DEFAULT NULL');
        $this->addSql("UPDATE incident SET block_answers = '{}' WHERE block_answers IS NULL");

        // ── the blocks whose questions are asked once ──────────────────────────
        $this->mergeSingles('species', ['species', 'sex', 'age_class']);
        $this->mergeSingles('method', ['method', 'gear', 'vehicle', 'suspected_agent', 'activity', 'operator']);
        $this->mergeSingles('condition', ['condition', 'carcass_disposition']);
        $this->mergeSingles('notice', ['permit_status', 'licence_status', 'notice_served']);

        // ── the blocks that are a row the filer adds to ────────────────────────
        // What was counted is the key's own word; how many is whatever was
        // recorded, verbatim, because these were typed answers and not numbers.
        $this->mergeRows('counts', 'quantity', 'how_many', [
            'snares_lifted' => 'snares lifted',
            'livestock_lost' => 'head of stock',
            'herd_size' => 'head of stock',
            'individuals_affected' => 'individuals affected',
            'structures' => 'structures',
            'vehicles' => 'vehicles',
        ]);
        $this->mergeRows('parties', 'role', 'name', [
            'suspects' => 'suspect',
            'owner' => null,
            'occupier' => null,
        ]);
        $this->mergeRows('seizures', null, 'item', [
            'seizures' => null,
            'trophy' => null,
        ]);
        $this->mergeRows('samples', null, 'samples_taken', [
            'samples_taken' => null,
        ]);
        $this->mergeRows('extent', 'measure', 'value', [
            'area_affected' => 'area affected · ha',
            'footprint' => 'footprint · m²',
            'extent' => 'length of boundary · m',
            'duration' => 'how long it went on · hours',
        ]);

        // Casualty is one row per record: the severity and the treatment given
        // were one answer each, so they are the one row they describe.
        $this->addSql(<<<'SQL'
            UPDATE incident
            SET block_answers = CAST(
                    CAST(block_answers AS jsonb)
                    || jsonb_build_object('casualty', jsonb_build_object('rows', jsonb_build_array(
                        jsonb_strip_nulls(jsonb_build_object(
                            'injuries', CAST(details AS jsonb)->>'injuries',
                            'treatment', CAST(details AS jsonb)->>'treatment'
                        ))
                    )))
                AS json)
            WHERE jsonb_exists_any(CAST(details AS jsonb), ARRAY['injuries', 'treatment'])
            SQL);

        // THE PLACE IS ONE PAIR OF QUESTIONS, so four keys become one answer and
        // the first one a record carries is the one it is about.
        $this->addSql(<<<'SQL'
            UPDATE incident i
            SET block_answers = CAST(
                    CAST(i.block_answers AS jsonb)
                    || jsonb_build_object('named-place', jsonb_build_object('place_kind', p.kind, 'place_name', p.name))
                AS json)
            FROM (
                SELECT x.id,
                       (array_agg(m.kind ORDER BY m.ord))[1] AS kind,
                       (array_agg(CAST(x.details AS jsonb)->>m.key ORDER BY m.ord))[1] AS name
                FROM incident x
                JOIN (VALUES (1, 'water_body', 'water body'),
                             (2, 'road_segment', 'road segment'),
                             (3, 'boundary_marker', 'boundary marker'),
                             (4, 'household', 'household or boma')) AS m(ord, key, kind)
                  ON jsonb_exists(CAST(x.details AS jsonb), m.key)
                GROUP BY x.id
            ) p
            WHERE p.id = i.id
            SQL);

        // What the affected ground is used for is asked once, beside the measures
        // rather than inside them — so the block's object has to exist first.
        $this->addSql(<<<'SQL'
            UPDATE incident
            SET block_answers = CAST(CAST(block_answers AS jsonb) || jsonb_build_object('extent', CAST('{}' AS jsonb)) AS json)
            WHERE jsonb_exists(CAST(details AS jsonb), 'land_use')
              AND NOT jsonb_exists(CAST(block_answers AS jsonb), 'extent')
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE incident
            SET block_answers = CAST(jsonb_set(
                    CAST(block_answers AS jsonb),
                    '{extent,land_use}',
                    to_jsonb(CAST(details AS jsonb)->>'land_use'),
                    true
                ) AS json)
            WHERE jsonb_exists(CAST(details AS jsonb), 'land_use')
            SQL);

        $this->addSql('ALTER TABLE incident ALTER block_answers SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP claimed_at_filing');
        $this->addSql('ALTER TABLE incident DROP block_answers');
    }

    /**
     * A block whose questions are asked once: every key that is also a question
     * of that block, under the key the block keeps it.
     *
     * @param list<string> $keys
     */
    private function mergeSingles(string $block, array $keys): void
    {
        $pairs = [];
        foreach ($keys as $key) {
            $pairs[] = \sprintf("'%s', CAST(details AS jsonb)->>'%s'", $key, $key);
        }

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE incident
                SET block_answers = CAST(
                        CAST(block_answers AS jsonb)
                        || jsonb_build_object('%s', jsonb_strip_nulls(jsonb_build_object(%s)))
                    AS json)
                WHERE jsonb_exists_any(CAST(details AS jsonb), ARRAY[%s])
                SQL,
            $block,
            implode(', ', $pairs),
            implode(', ', array_map(static fn (string $key): string => "'".$key."'", $keys)),
        ));
    }

    /**
     * A block that is a row the filer adds to: one row per key the record
     * carries, in the order this table names them, so two installations upgrade
     * to the same rows in the same order.
     *
     * @param string|null                $namedBy the row key holding what the source key SAYS it is, where it says one
     * @param string                     $valueBy the row key holding what was recorded
     * @param array<string, string|null> $sources source key => the word it says, or null where it says none
     */
    private function mergeRows(string $block, ?string $namedBy, string $valueBy, array $sources): void
    {
        $rows = [];
        $ordinal = 0;
        foreach ($sources as $key => $word) {
            $rows[] = \sprintf(
                "(%d, '%s', %s)",
                ++$ordinal,
                $key,
                null === $word ? 'NULL' : "'".str_replace("'", "''", $word)."'",
            );
        }

        $row = null === $namedBy
            ? \sprintf("jsonb_build_object('%s', CAST(x.details AS jsonb)->>m.key)", $valueBy)
            : \sprintf(
                "jsonb_strip_nulls(jsonb_build_object('%s', m.word, '%s', CAST(x.details AS jsonb)->>m.key))",
                $namedBy,
                $valueBy,
            );

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE incident i
                SET block_answers = CAST(
                        CAST(i.block_answers AS jsonb)
                        || jsonb_build_object('%s', jsonb_build_object('rows', r.rows))
                    AS json)
                FROM (
                    SELECT x.id, jsonb_agg(%s ORDER BY m.ord) AS rows
                    FROM incident x
                    JOIN (VALUES %s) AS m(ord, key, word)
                      ON jsonb_exists(CAST(x.details AS jsonb), m.key)
                    GROUP BY x.id
                ) r
                WHERE r.id = i.id
                SQL,
            $block,
            $row,
            implode(', ', $rows),
        ));
    }
}
