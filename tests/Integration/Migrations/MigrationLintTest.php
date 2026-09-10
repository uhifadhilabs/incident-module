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

namespace Uhifadhi\Incident\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Incident\Tests\Integration\Migrations\Fixtures\Migrations\Version29991231000100;
use Uhifadhi\Incident\Tests\Integration\Migrations\Fixtures\Migrations\Version29991231000200;
use Uhifadhi\Incident\Tests\Integration\Migrations\Fixtures\Migrations\Version29991231000300;

/**
 * THE LINT, AND THE PROOF THAT IT CAN FAIL.
 *
 * A rule checker that has only ever been pointed at compliant files is a rule
 * checker nobody has seen working, so every rule has a fixture version that
 * breaks it — under Fixtures/migrations, outside the registered path, so no
 * installation ever meets them.
 *
 * @see MigrationLint
 */
final class MigrationLintTest extends KernelTestCase
{
    private function lint(): MigrationLint
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return new MigrationLint($connection);
    }

    public function testEveryShippedVersionObeysTheRules(): void
    {
        self::bootKernel();

        /** @var DependencyFactory $factory */
        $factory = static::getContainer()->get('test_public.doctrine.migrations.dependency_factory');
        $directories = $factory->getConfiguration()->getMigrationDirectories();
        self::assertArrayHasKey(MigrationPathsAreRegisteredTest::NAMESPACE, $directories);

        $lint = $this->lint();
        $files = (array) glob(rtrim($directories[MigrationPathsAreRegisteredTest::NAMESPACE], '/').'/Version*.php');
        self::assertNotSame([], $files, 'The module owns tables, so it ships at least one version to lint.');

        $violations = [];
        foreach ($files as $file) {
            /** @var class-string<\Doctrine\Migrations\AbstractMigration> $class */
            $class = MigrationPathsAreRegisteredTest::NAMESPACE.'\\'.basename((string) $file, '.php');
            $violations = [...$violations, ...$lint->violations($class)];
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /** Rule one: expand, backfill, contract — in one version. */
    public function testItCatchesANotNullColumnAddedToAPreExistingTableWithNoBackfill(): void
    {
        $violations = $this->lint()->violations(Version29991231000100::class);

        self::assertCount(1, $violations);
        self::assertStringContainsString('NOT NULL on the pre-existing table "incident"', $violations[0]);
        self::assertStringContainsString('Expand, backfill, contract', $violations[0]);
    }

    /** Rule three: a destructive statement says which release it waits for. */
    public function testItCatchesADropWithNoDestructiveMarker(): void
    {
        $violations = $this->lint()->violations(Version29991231000200::class);

        self::assertCount(1, $violations);
        self::assertStringContainsString('drops data', $violations[0]);
        self::assertStringContainsString('@destructive', $violations[0]);
    }

    /** A rollback nobody can run is a rollback nobody rehearsed. */
    public function testItCatchesAVersionThatCannotBeUnwound(): void
    {
        $violations = $this->lint()->violations(Version29991231000300::class);

        self::assertCount(1, $violations);
        self::assertStringContainsString('down() plans no statements', $violations[0]);
    }
}
