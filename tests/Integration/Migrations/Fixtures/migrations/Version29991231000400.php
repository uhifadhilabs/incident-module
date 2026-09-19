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
 * BREAKS THE ROLLBACK A SECOND WAY, on purpose: it changes only DATA and
 * unwinds to nothing, but it never says so. The exemption for a data-only
 * version is a marker somebody had to write, not a hole a version falls
 * through by touching no schema.
 *
 * Never registered: this lives outside the shipped migrations path.
 */
final class Version29991231000400 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE incident SET severity = 'moderate' WHERE severity IS NULL");
    }

    public function down(Schema $schema): void
    {
    }
}
