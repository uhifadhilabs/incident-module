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

namespace Uhifadhi\Incident\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * A SUITE THAT SURVIVES THE DATABASE THE MIGRATIONS LOCK LEAVES BEHIND.
 *
 * The migrations tests run against an empty database, and "empty" there is the
 * whole `public` schema, PostGIS included, because the core's first version is
 * the one that enables it. A run that ends inside them — a failure, an
 * interrupt, a `--filter` — leaves the database in exactly that state, and the
 * next run's SchemaTool then asks PostgreSQL for a `geometry` column in a
 * database that has no such type. Every test of every class errors, for a
 * reason that has nothing to do with any of them.
 *
 * So this class hands the base the worst database there is and expects the
 * ordinary thing to work.
 */
final class EmptyDatabaseRebuildTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        self::emptyTheDatabase();

        parent::setUp();
    }

    public function testTheSchemaComesBackAndPostgisAnswers(): void
    {
        $area = $this->anAreaWithKinds();
        $zone = $this->aZone($area, 'North Gate');

        $incident = $this->anIncident($area);

        // The point-in-polygon lookup is PostGIS's own work, so an incident that
        // knows its zone proves the extension is back, not merely the tables.
        self::assertSame($zone->getId(), $incident->getZone()?->getId());
    }

    /**
     * The whole `public` schema, the way MigrationsTestCase empties it — through
     * its own connection, because the kernel is not booted yet.
     */
    private static function emptyTheDatabase(): void
    {
        $url = $_ENV['INCIDENTS_TEST_DATABASE_URL'] ?? '';
        self::assertIsString($url);

        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->close();
    }
}
