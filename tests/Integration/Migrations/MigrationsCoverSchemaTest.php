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
     * EMPTY, AND THAT IS THE POINT. The installation-wide taxonomy that used to
     * be listed here — `incident_category`, `incident_subcategory` and
     * `incident.subcategory_id` — is dropped by
     * {@see \Uhifadhi\Incident\Migrations\Version20260919210000}, so the diff
     * an installation runs has nothing left to propose. A deferral that is never
     * collected is how an installer ends up generating the drop itself, in an
     * order PostgreSQL refuses.
     *
     * @var array<string, string>
     */
    private const array RETIRED_UNTIL_DROPPED = [];

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

    /**
     * AND THE SHARED TAXONOMY IS GONE. It was kept one release so an
     * installation could read what a record used to say; the version that
     * collects the deferral drops both tables and the column that pointed at
     * them, in the one order PostgreSQL accepts — the referencing key first.
     *
     * @see \Uhifadhi\Incident\Migrations\Version20260919210000
     */
    public function testTheRetiredInstallationWideTaxonomyIsDropped(): void
    {
        $this->migrateToLatest();

        $schema = $this->connection()->createSchemaManager();
        $tables = $schema->listTableNames();

        self::assertNotContains('incident_subcategory', $tables);
        self::assertNotContains('incident_category', $tables);

        $columns = array_map(
            static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getName(),
            $schema->listTableColumns('incident'),
        );
        self::assertNotContains('subcategory_id', $columns);
    }
}
