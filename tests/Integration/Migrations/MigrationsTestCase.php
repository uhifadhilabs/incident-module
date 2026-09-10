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
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * WHAT AN INSTALLATION DOES, DONE HERE FIRST.
 *
 * The rest of this suite builds its tables with SchemaTool, which is the right
 * tool for a test that is about the module's behaviour. These four are about the
 * SHIPPED HISTORY instead, so nothing here may touch SchemaTool: the database
 * starts empty and every table that appears was created by a version somebody
 * will run with `doctrine:migrations:migrate`.
 *
 * "Empty" is the whole public schema, PostGIS included, because the core's first
 * version is the one that enables it — see the core's
 * AreaBundle/migrations/Version20260101000000. A fresh installation is a database
 * with nothing in it, and a lock that quietly kept the extension would not be
 * testing the first thing that runs.
 *
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 * @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php
 */
abstract class MigrationsTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $this->emptyTheDatabase();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    protected function connection(): Connection
    {
        return $this->em->getConnection();
    }

    protected function reboot(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
        }

        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    protected function dependencyFactory(): DependencyFactory
    {
        /** @var DependencyFactory $factory */
        $factory = static::getContainer()->get('test_public.doctrine.migrations.dependency_factory');

        return $factory;
    }

    /** Every version there is, in the order the platform's comparator puts them. */
    protected function migrateToLatest(): void
    {
        $this->migrateTo('latest');
    }

    /**
     * `doctrine:migrations:migrate <alias>`, without the console: the alias
     * resolver, the plan calculator and the migrator the command itself uses.
     *
     * @see vendor/doctrine/migrations/src/Tools/Console/Command/MigrateCommand.php
     */
    protected function migrateTo(string $alias): void
    {
        // EACH RUN IS ITS OWN COMMAND. A version object is frozen once it has
        // been executed, and the repository hands the same object back for the
        // rest of the process — so a test that migrates twice has to get the
        // fresh objects a second `migrations:migrate` would have got.
        //
        // @see vendor/doctrine/migrations/src/AbstractMigration.php — `freeze()`
        $this->reboot();

        $factory = $this->dependencyFactory();
        // What the command does before anything else: the version log is itself a
        // table, and on a database this test just emptied it does not exist yet.
        $factory->getMetadataStorage()->ensureInitialized();

        $version = $factory->getVersionAliasResolver()->resolveVersionAlias($alias);
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);

        $this->runPlan($plan);
    }

    /**
     * Down to nothing: the whole history unwound, the way a rehearsal ends.
     *
     * `first` is the alias for "before anything ran" — the resolver answers it
     * with `Version('0')`, not with the first version there is.
     *
     * @see vendor/doctrine/migrations/src/Version/DefaultAliasResolver.php
     */
    protected function migrateToEmpty(): void
    {
        $this->migrateTo('first');
    }

    private function runPlan(MigrationPlanList $plan): void
    {
        if (0 === \count($plan)) {
            return;
        }

        $configuration = new MigratorConfiguration();
        $configuration->setAllOrNothing(false);

        $this->dependencyFactory()->getMigrator()->migrate($plan, $configuration);
    }

    /**
     * A database with nothing in it — no tables, no PostGIS, no version log.
     *
     * `DROP SCHEMA public CASCADE` is the one statement that says that without
     * enumerating what happens to be there, and the extension goes with it,
     * which is the point: the core's first version is `CREATE EXTENSION`.
     */
    private function emptyTheDatabase(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
    }
}
