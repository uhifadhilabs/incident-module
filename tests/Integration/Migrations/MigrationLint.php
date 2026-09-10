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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * THE THREE RULES, READ OFF THE SQL A VERSION PLANS.
 *
 * Not off its source text: a rule about what a migration DOES has to be checked
 * against what it hands the database, and `up()` is the only thing that knows
 * that. So each version is constructed with the real connection and asked to
 * plan — `addSql()` collects, it does not execute — and the statements are what
 * gets read.
 *
 * @see vendor/doctrine/migrations/src/AbstractMigration.php — `addSql()` / `getSql()`
 */
final readonly class MigrationLint
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param class-string<AbstractMigration> $class
     *
     * @return list<string> one message per violation; empty means the version obeys the rules
     */
    public function violations(string $class): array
    {
        $up = $this->plan($class, 'up');
        $down = $this->plan($class, 'down');

        return [
            ...$this->notNullWithoutBackfill($class, $up),
            ...$this->dropWithoutDestructiveMarker($class, $up),
            ...$this->emptyDown($class, $down),
        ];
    }

    /**
     * Rule one — expand, backfill, contract, in ONE version. A `NOT NULL` on a
     * table an earlier version created, with neither a `DEFAULT` beside it nor an
     * `UPDATE` of that table in the same version, fails on the first installation
     * that has rows in it.
     *
     * @param list<string> $up
     *
     * @return list<string>
     */
    private function notNullWithoutBackfill(string $class, array $up): array
    {
        $created = [];
        foreach ($up as $sql) {
            if (1 === preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?(?<table>\w+)"?/i', $sql, $match)) {
                $created[strtolower($match['table'])] = true;
            }
        }

        $backfilled = [];
        foreach ($up as $sql) {
            if (1 === preg_match('/^\s*UPDATE\s+"?(?<table>\w+)"?/i', $sql, $match)) {
                $backfilled[strtolower($match['table'])] = true;
            }
        }

        $violations = [];
        foreach ($up as $sql) {
            if (1 !== preg_match('/^\s*ALTER\s+TABLE\s+"?(?<table>\w+)"?/i', $sql, $match)) {
                continue;
            }
            if (1 !== preg_match('/\bNOT\s+NULL\b/i', $sql)) {
                continue;
            }

            $table = strtolower($match['table']);
            if (isset($created[$table]) || isset($backfilled[$table])) {
                continue;
            }
            if (1 === preg_match('/\bDEFAULT\b(?!\s+NULL\b)/i', $sql)) {
                continue;
            }

            $violations[] = \sprintf(
                '%s: "%s" makes a column NOT NULL on the pre-existing table "%s" with no DEFAULT and no UPDATE of it in the same version. Expand, backfill, contract — in one version.',
                self::shortName($class),
                self::trim($sql),
                $table,
            );
        }

        return $violations;
    }

    /**
     * Rule three — a destructive statement rides a LATER release than the code
     * that stopped using what it drops, and says so in the docblock. `down()` is
     * exempt by nature: undoing a `CREATE TABLE` is a `DROP TABLE`.
     *
     * @param class-string<AbstractMigration> $class
     * @param list<string>                    $up
     *
     * @return list<string>
     */
    private function dropWithoutDestructiveMarker(string $class, array $up): array
    {
        $reflection = new \ReflectionClass($class);
        $docblock = $reflection->getDocComment();
        if (\is_string($docblock) && str_contains($docblock, '@destructive')) {
            return [];
        }

        $violations = [];
        foreach ($up as $sql) {
            if (1 !== preg_match('/\bDROP\s+(TABLE|COLUMN|SCHEMA)\b/i', $sql, $match)) {
                continue;
            }

            $violations[] = \sprintf(
                '%s: "%s" drops data and the docblock carries no "@destructive" marker naming the release it waits for.',
                self::shortName($class),
                self::trim($sql),
            );
        }

        return $violations;
    }

    /**
     * A `down()` that plans nothing is a rollback nobody can run. The schema has
     * to round-trip — that is the rehearsal an installation gets before it
     * upgrades for real.
     *
     * @param list<string> $down
     *
     * @return list<string>
     */
    private function emptyDown(string $class, array $down): array
    {
        if ([] !== $down) {
            return [];
        }

        return [\sprintf(
            '%s: down() plans no statements, so the version cannot be unwound and its up() has never been rehearsed.',
            self::shortName($class),
        )];
    }

    /**
     * @param class-string<AbstractMigration> $class
     *
     * @return list<string>
     */
    private function plan(string $class, string $direction): array
    {
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        $statements = [];
        foreach ($migration->getSql() as $query) {
            $statements[] = $query->getStatement();
        }

        return $statements;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }

    private static function trim(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return mb_strlen($sql) > 90 ? mb_substr($sql, 0, 87).'...' : $sql;
    }
}
