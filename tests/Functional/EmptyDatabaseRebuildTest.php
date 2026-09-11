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

namespace Uhifadhi\Incident\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * THE SAME WORST DATABASE, THROUGH THE SCREENS.
 *
 * The migrations tests leave `public` with no PostGIS in it, so the run after
 * them starts where this one starts. The dashboard rendering from here is the
 * proof that a functional class rebuilds its own database rather than inheriting
 * one from whatever ran before.
 *
 * @see \Uhifadhi\Incident\Tests\Integration\EmptyDatabaseRebuildTest
 */
final class EmptyDatabaseRebuildTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::emptyTheDatabase();

        parent::setUp();
    }

    public function testTheDashboardRendersOnARebuiltDatabase(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-w="map"]'));
    }

    /**
     * The whole `public` schema, the way MigrationsTestCase empties it — through
     * its own connection, because a WebTestCase may not boot a kernel before
     * createClient().
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
