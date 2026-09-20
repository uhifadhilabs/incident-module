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
 * THE CHOSEN COLOUR GOES.
 *
 * @destructive
 *
 * {@see Version20260919230000} stopped writing `colour_key` and left the
 * column behind on purpose, because the rule is that a destructive statement
 * rides a LATER release than the code that stops using the column — so an
 * installation can roll the code back and still read what each kind used to
 * be set to.
 *
 * THAT DEFERRAL HAS NOTHING LEFT TO PROTECT, and this version is the reading
 * of it rather than a reversal. The release it was waiting for never shipped:
 * no tag contains Version20260919230000, so no installation has ever run it,
 * and there is no deployment anywhere holding a `colour_key` value that a
 * rollback could want. Keeping the column would not preserve anybody's data;
 * it would only leave every installation's `doctrine:migrations:diff`
 * proposing this same drop for ever, which is the noise the rule about
 * shipped migrations exists to prevent.
 *
 * WHAT IS LOST. Nothing any code reads: a kind wears the house hue its PLACE
 * in the area's list points at ({@see \Uhifadhi\Incident\Entity\TaxonomyKind::catIndex()}),
 * and the mapping has not carried this column since 0.4.
 */
final class Version20260921000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'incident_taxonomy_kind.colour_key is dropped: a kind wears the hue its place in the list points at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident_taxonomy_kind DROP colour_key');
    }

    /**
     * Going back re-creates the column, empty and nullable. The values are
     * not restored because they are gone — a down() that invented one of the
     * old four keys per row would be inventing a decision an administrator
     * made once and nobody recorded.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident_taxonomy_kind ADD colour_key VARCHAR(16) DEFAULT NULL');
    }
}
