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
 * BREAKS RULE ONE, on purpose. A required column on a table an earlier version
 * created, with no DEFAULT and no backfill — the failure every installation that
 * has rows would hit and no empty test database ever would.
 *
 * Never registered: this lives outside the shipped migrations path.
 */
final class Version29991231000100 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident ADD district VARCHAR(64) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE incident DROP COLUMN district');
    }
}
