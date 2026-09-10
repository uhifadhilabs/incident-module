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
 * BREAKS THE ROLLBACK, on purpose. `up()` creates a table and `down()` plans
 * nothing, so the history cannot be unwound and the upgrade rehearsal an
 * installation is told to run proves nothing.
 *
 * Never registered: this lives outside the shipped migrations path.
 */
final class Version29991231000300 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE incident_note (id INT NOT NULL, body TEXT NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
    }
}
