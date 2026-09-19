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
 * A KIND'S PLACE IN ITS AREA'S LIST BECOMES A NUMBER THAT MEANS SOMETHING.
 *
 * RULED 2026-09-21: a module declares no colour and a user picks none. The
 * house owns one categorical set of nine hues, and a kind takes the one its
 * POSITION in its area's list points at — first kind, first hue. So from here
 * `incident_taxonomy_kind.position` is read as an index and not only as a sort
 * key, and an index with ties and gaps in it is an index that answers wrong.
 *
 * WHAT WAS THERE BEFORE. `position` was written as "max + 1" on create and
 * never rewritten afterwards, because every screen sorted by `(position, id)`
 * and neither a tie nor a gap costs anything in a sort. A live area therefore
 * holds both. This version renumbers each area's kinds 0, 1, 2 … in exactly
 * that order — the order the editor was already looking at — so nothing moves
 * on screen and the numbers start saying what the order always was.
 *
 * PER AREA, because an area owns its vocabulary: each list counts from zero and
 * the third kind in one area wears the same hue as the third in another.
 *
 * NO SCHEMA CHANGES HERE. The column is the one the first version created; what
 * changes is what is stored in it. `colour_key` is untouched and still
 * populated: the code that reads it goes in the release that ports the palette,
 * and the version that DROPS the column comes after that one, marked
 * `@destructive`. Dropping it here would take an area's stored choice away
 * before anything had replaced it.
 *
 * @irreversible — it changes data only, and the data it overwrote was the ties
 *                 and gaps themselves. Nothing is lost that a rollback would
 *                 want: the previous release reads this column as a sort key,
 *                 and dense numbering sorts identically. Inventing gaps on the
 *                 way down would write an order nobody chose.
 *
 * @see \Uhifadhi\Incident\Entity\TaxonomyKind::catIndex()
 */
final class Version20260919220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Each area\'s kinds are numbered from zero in the order they were already read, so the position can be the hue.';
    }

    public function up(Schema $schema): void
    {
        // ROW_NUMBER over the order every screen already sorted by, per area.
        // Only the rows whose number changes are written, so an installation
        // that is already dense pays nothing and the statement is idempotent.
        $this->addSql(<<<'SQL'
            UPDATE incident_taxonomy_kind k
            SET "position" = ordered.place
            FROM (SELECT id, (ROW_NUMBER() OVER (PARTITION BY area_id ORDER BY "position", id) - 1) AS place
                  FROM incident_taxonomy_kind) ordered
            WHERE ordered.id = k.id AND k."position" <> ordered.place
            SQL);
    }

    /**
     * Nothing, and deliberately — see `@irreversible` above.
     */
    public function down(Schema $schema): void
    {
    }
}
