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

use Doctrine\Migrations\Generator\Exception\NoChangesDetected;

/**
 * THE DRIFT LOCK: an empty database, every version run, and then the diff the
 * installation would run has nothing left to say.
 *
 * This is the test that makes "the installation never writes SQL for our tables"
 * true rather than intended. An entity changed without its migration passes every
 * other test in this suite — the rest of the suite builds its tables with
 * SchemaTool, straight from the same metadata — and fails only here.
 *
 * It runs the REAL generator `doctrine:migrations:diff` runs, so what it compares
 * is what an installer would see. On a clean module that generator throws
 * NoChangesDetected; on a dirty one it writes a file, and this reads that file
 * back into the failure message and removes it, because the SQL is the answer to
 * "what did I forget".
 *
 * @see vendor/doctrine/migrations/src/Generator/DiffGenerator.php
 * @see vendor/doctrine/migrations/src/Tools/Console/Command/DiffCommand.php
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 */
final class MigrationsCoverSchemaTest extends MigrationsTestCase
{
    public function testAFreshDatabaseMigratedLeavesNothingToDiff(): void
    {
        $this->migrateToLatest();

        $factory = $this->dependencyFactory();
        $fqcn = $factory->getClassNameGenerator()->generateClassName(MigrationPathsAreRegisteredTest::NAMESPACE);

        try {
            $path = $factory->getDiffGenerator()->generate($fqcn, null);
        } catch (NoChangesDetected) {
            $this->addToAssertionCount(1);

            return;
        }

        $written = (string) file_get_contents($path);
        unlink($path);

        self::fail(\sprintf(
            "A migrated database still differs from the mapping. The missing version would be:\n\n%s",
            $written,
        ));
    }

    /**
     * EVERY TABLE THIS MODULE OWNS IS CREATED BY A VERSION — named, so that a
     * table added to the model and forgotten in the history is reported as the
     * table it is rather than as a wall of diff SQL.
     */
    public function testEveryTableTheModuleOwnsExistsAfterMigrating(): void
    {
        $this->migrateToLatest();

        $tables = $this->connection()->createSchemaManager()->listTableNames();

        foreach ([
            'incident',
            'incident_category',
            'incident_subcategory',
            'incident_event',
            'incident_evidence',
            'incident_link',
            'incident_money',
            'incident_party',
            'incident_taxonomy_kind',
            'incident_taxonomy_subcategory',
            'incident_settings',
        ] as $table) {
            self::assertContains($table, $tables, \sprintf('No shipped version creates "%s".', $table));
        }
    }
}
