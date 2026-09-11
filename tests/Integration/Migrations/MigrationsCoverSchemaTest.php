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
 * ONE THING IT MUST TOLERATE, AND ONLY BY NAME. The rule that a destructive
 * statement rides a LATER release than the code that stops using what it drops
 * opens a window: for one release the mapping no longer knows a table or a column
 * the database still holds, so the diff proposes dropping it. That is the
 * deferral working. {@see RETIRED_UNTIL_DROPPED} is the whole of what may appear
 * in such a proposal, each entry naming the thing and the version that drops it;
 * one word outside that list and this fails, which is what keeps the lock a lock
 * rather than a suggestion.
 *
 * @see vendor/doctrine/migrations/src/Generator/DiffGenerator.php
 * @see vendor/doctrine/migrations/src/Tools/Console/Command/DiffCommand.php
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 */
final class MigrationsCoverSchemaTest extends MigrationsTestCase
{
    /**
     * Schema the mapping has already let go of and a later version drops, with
     * the version that drops it. Nothing else may appear in a proposed diff.
     *
     * @var array<string, string>
     */
    private const array RETIRED_UNTIL_DROPPED = [
        // The installation-wide taxonomy, converged into the per-area one by
        // Version20260911140000 and kept one release so an installation can read
        // what a record used to say and can roll the code back.
        'incident_category' => '0.4',
        'incident_subcategory' => '0.4',
        'subcategory_id' => '0.4',
        // The foreign key and index on that column, by the names Doctrine
        // generated for them — the statements that drop those never spell the
        // column out.
        '3d03a11a5dc6fe57' => '0.4',
    ];

    public function testAFreshDatabaseMigratedLeavesNothingButRetiredSchemaToDiff(): void
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

        $unexplained = self::statementsAbout($written, array_keys(self::RETIRED_UNTIL_DROPPED));

        self::assertSame([], $unexplained, \sprintf(
            "A migrated database differs from the mapping in ways nothing has declared. The missing version would be:\n\n%s",
            $written,
        ));
    }

    /**
     * The statements a proposed version's `up()` plans that mention NOTHING on
     * the declared list — read off the generated file, because that file is
     * exactly what an installer would be handed.
     *
     * @param list<string> $retired
     *
     * @return list<string>
     */
    private static function statementsAbout(string $generated, array $retired): array
    {
        $up = explode('public function down(', $generated)[0];

        preg_match_all("/addSql\('(?<sql>.*?)'\)/s", $up, $matches);

        $unexplained = [];
        foreach ($matches['sql'] as $sql) {
            foreach ($retired as $name) {
                if (str_contains($sql, $name)) {
                    continue 2;
                }
            }

            $unexplained[] = $sql;
        }

        return $unexplained;
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
            // Still here, and deliberately: the classes behind these two are
            // retired, the rows are kept for a release, and a later @destructive
            // version drops them. See RETIRED_UNTIL_DROPPED.
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
