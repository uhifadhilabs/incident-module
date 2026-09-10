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

namespace Uhifadhi\Incident\Tests\Integration\Migrations\Fixtures\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BREAKS RULE THREE, on purpose. It drops a column and says nothing about which
 * release stopped reading it, so nobody can tell whether the code that used it
 * is already gone from every installation that will run this.
 *
 * Never registered: this lives outside the shipped migrations path.
 */
final class Version29991231000200 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP COLUMN legacy_grid');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD legacy_grid VARCHAR(32) DEFAULT NULL');
    }
}
