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
 * THE CHOSEN COLOUR STOPS BEING WRITTEN.
 *
 * RULED 2026-09-21: a module declares no colour and a user picks none. A kind
 * wears the house hue its PLACE in the area's list points at
 * ({@see \Uhifadhi\Incident\Entity\TaxonomyKind::catIndex()}, and the previous
 * version made those places dense), so `colour_key` — the key an administrator
 * used to choose from a dropdown — is read by nothing from this release on.
 *
 * WHAT THIS VERSION DOES IS THE SMALLEST THING THAT LETS THAT BE TRUE: the
 * column loses its NOT NULL, because the mapping no longer carries it and a new
 * kind is inserted without one. The rows keep every value they had.
 *
 * THE DROP RIDES A LATER RELEASE, as the rule says: for one release the values
 * are still there to read, so an installation can see what each kind used to be
 * set to and can roll the code back. Until that version ships,
 * `doctrine:migrations:diff` proposes dropping the column — that proposal is
 * the deferral working, and the drift lock names `colour_key` as the whole of
 * what it may propose ({@see \Uhifadhi\Incident\Tests\Integration\Migrations\MigrationsCoverSchemaTest}).
 *
 * @see Version20260919220000 — the places the hue is read off
 */
final class Version20260919230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'incident_taxonomy_kind.colour_key is no longer written: a kind wears the hue its place in the list points at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident_taxonomy_kind ALTER colour_key DROP NOT NULL');
    }

    /**
     * Going back means the column is required again, and a kind written while
     * this release was applied has no value in it. It is given the empty
     * string rather than one of the old four keys: a blank says "nobody chose
     * one", and picking `poach` for it would be inventing a decision.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE incident_taxonomy_kind SET colour_key = '' WHERE colour_key IS NULL");
        $this->addSql('ALTER TABLE incident_taxonomy_kind ALTER colour_key SET NOT NULL');
    }
}
