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
 * ONE TAXONOMY, AND IT IS THE AREA'S. Incidents were filed against an
 * installation-wide vocabulary every area shared; from here they are filed
 * against the words the area itself keeps, and the two tables behind the old one
 * are read for the last time by this version.
 *
 * EXPAND → BACKFILL → CONTRACT, IN ONE VERSION, in four moves:
 *
 *  1. The area's sub-categories gain the term and the field set the shared ones
 *     carried, and its kinds gain the departments a lens leads with. Added
 *     nullable or with a default, filled, then tightened.
 *  2. Every area that has filed anything gets its OWN copy of exactly the words
 *     its incidents reference — same wire-code as the old slug, same label,
 *     colour, money direction, term and fields. Two areas that shared a slug get
 *     two rows, and from here their vocabularies move independently. An area that
 *     has already written a word with that code keeps its own; nothing is
 *     overwritten.
 *  3. `incident.taxonomy_subcategory_id` is added nullable and filled by
 *     (area, slug) — the area's copy of the word the incident was already filed
 *     against, never another area's.
 *  4. It is made NOT NULL only if every row found one. By construction every row
 *     does: the copies in move 2 are generated FROM the rows the incidents point
 *     at. If a row is somehow left over the column stays nullable, the old
 *     `subcategory_id` still holds the answer, and README's upgrading section has
 *     the one-line UPDATE that finishes the job.
 *
 * NOTHING IS DROPPED HERE. `incident.subcategory_id`, `incident_subcategory` and
 * `incident_category` are kept for a release, still populated, so an installation
 * can read what a record used to say and can roll the code back. The old column
 * loses only its NOT NULL, because incidents filed after this version have no
 * value to put in it. The version that drops all three carries the
 * `@destructive` marker.
 *
 * WHAT AN INSTALLATION SEES UNTIL THAT VERSION SHIPS: `doctrine:migrations:diff`
 * proposes dropping those three things, because the mapping no longer knows them.
 * That proposal is the deferral working, not drift — do not apply it.
 */
final class Version20260911140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incidents are filed against the area\'s own kinds and sub-categories, which now carry the term, the fields and the lens.';
    }

    public function up(Schema $schema): void
    {
        // ── 1 · the per-area rows learn what the shared ones knew ──────────────
        $this->addSql('ALTER TABLE incident_taxonomy_kind ADD leads JSON DEFAULT NULL');
        $this->addSql("UPDATE incident_taxonomy_kind SET leads = '[]' WHERE leads IS NULL");
        $this->addSql('ALTER TABLE incident_taxonomy_kind ALTER leads SET NOT NULL');

        $this->addSql('ALTER TABLE incident_taxonomy_subcategory ADD term_hours INT DEFAULT 72 NOT NULL');
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory ADD field_set JSON DEFAULT NULL');
        $this->addSql("UPDATE incident_taxonomy_subcategory SET field_set = '[]' WHERE field_set IS NULL");
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory ALTER field_set SET NOT NULL');

        // ── 2 · every area that has filed something gets its own vocabulary ────
        // Driven by what the incidents actually reference, so an area is given
        // the words it uses and not a catalogue somebody has to prune. The
        // sub-select is DISTINCT over ids only: `leads` and `field_set` are json,
        // which PostgreSQL cannot compare for equality.
        $this->addSql(<<<'SQL'
            INSERT INTO incident_taxonomy_kind (uuid, area_id, code, label, colour_key, leads, position, active, created_at, updated_at)
            SELECT gen_random_uuid(), filed.area_id, c.slug, c.label, c.colour_key, c.leads, c.position, true, NOW(), NOW()
            FROM (SELECT DISTINCT i.area_id, s.category_id
                  FROM incident i
                  JOIN incident_subcategory s ON s.id = i.subcategory_id) filed
            JOIN incident_category c ON c.id = filed.category_id
            WHERE NOT EXISTS (SELECT 1 FROM incident_taxonomy_kind k
                              WHERE k.area_id = filed.area_id AND k.code = c.slug)
            SQL);

        // The money block is switched on exactly where the shared row carried a
        // direction; the other blocks are the area's to compose, and guessing at
        // them would put questions on a form nobody asked for.
        $this->addSql(<<<'SQL'
            INSERT INTO incident_taxonomy_subcategory (uuid, kind_id, code, label, blocks, money_direction, term_hours, field_set, position, active, created_at, updated_at)
            SELECT gen_random_uuid(), k.id, s.slug, s.label,
                   CASE WHEN s.money_direction IS NULL THEN '[]'::json ELSE '["money"]'::json END,
                   s.money_direction, s.term_hours, s.field_set, s.position, true, NOW(), NOW()
            FROM (SELECT DISTINCT i.area_id, i.subcategory_id FROM incident i) filed
            JOIN incident_subcategory s ON s.id = filed.subcategory_id
            JOIN incident_category c ON c.id = s.category_id
            JOIN incident_taxonomy_kind k ON k.area_id = filed.area_id AND k.code = c.slug
            WHERE NOT EXISTS (SELECT 1 FROM incident_taxonomy_subcategory ts
                              JOIN incident_taxonomy_kind tk ON tk.id = ts.kind_id
                              WHERE tk.area_id = filed.area_id AND ts.code = s.slug)
            SQL);

        // ── 3 · the incident points at its OWN area's word ─────────────────────
        $this->addSql('ALTER TABLE incident ADD taxonomy_subcategory_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE incident i SET taxonomy_subcategory_id = ts.id
            FROM incident_subcategory s
            JOIN incident_taxonomy_subcategory ts ON ts.code = s.slug
            JOIN incident_taxonomy_kind tk ON tk.id = ts.kind_id
            WHERE s.id = i.subcategory_id AND tk.area_id = i.area_id
            SQL);
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11AA493390E FOREIGN KEY (taxonomy_subcategory_id) REFERENCES incident_taxonomy_subcategory (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3D03A11AA493390E ON incident (taxonomy_subcategory_id)');

        // The retired column is kept and populated, but it can no longer be
        // required: an incident filed after this version has nothing to put in it.
        $this->addSql('ALTER TABLE incident ALTER subcategory_id DROP NOT NULL');

        // ── 4 · contract, and only if the data earned it ───────────────────────
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM incident WHERE taxonomy_subcategory_id IS NULL) THEN
                    ALTER TABLE incident ALTER taxonomy_subcategory_id SET NOT NULL;
                ELSE
                    RAISE WARNING 'Some incidents found no sub-category in their own area; incident.taxonomy_subcategory_id stays optional and incident.subcategory_id still holds what they were filed against. See the module README, Upgrading.';
                END IF;
            END $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP CONSTRAINT FK_3D03A11AA493390E');
        $this->addSql('DROP INDEX IDX_3D03A11AA493390E');
        // The rows copied into the per-area tables are deliberately left where
        // they are: they are an area's own vocabulary now, and a rollback of the
        // schema is not a reason to take an area's words away from it.
        $this->addSql('ALTER TABLE incident DROP taxonomy_subcategory_id');
        // `subcategory_id` is deliberately left optional. Incidents filed while
        // this version was applied never had one, and inventing a shared
        // sub-category for them so the old NOT NULL could come back would put a
        // classification on a record that nobody chose.
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory DROP field_set');
        $this->addSql('ALTER TABLE incident_taxonomy_subcategory DROP term_hours');
        $this->addSql('ALTER TABLE incident_taxonomy_kind DROP leads');
    }
}
