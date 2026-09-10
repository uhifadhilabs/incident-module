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

namespace Uhifadhi\Incident\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AN INCIDENT FILED BY HAND CARRIES PHOTOGRAPHS, so the bundle that stores them
 * is a requirement and not a suggestion.
 *
 * The distinction is not bookkeeping. A suggestion has to be guarded at every
 * point it is used — a nullable collaborator, a `kernel.bundles` check, a
 * parameter templates read to hide a door — and every one of those guards is a
 * sentence saying "this product also works without evidence", which it does not.
 * A requirement deletes all of them at once.
 */
final class StorageIsRequiredTest extends TestCase
{
    public function testStorageIsAHardRequirement(): void
    {
        self::assertArrayHasKey('uhifadhi/storage-module', self::composer()['require']);
    }

    public function testStorageIsNotADevDependency(): void
    {
        self::assertArrayNotHasKey('uhifadhi/storage-module', self::composer()['require-dev']);
    }

    /**
     * SUGGEST STAYS HONEST. Suggesting what is already required prints a line in
     * `composer suggests` for a package the resolver has just installed, which
     * reads as an unmet option and is simply false.
     */
    public function testStorageIsNoLongerSuggested(): void
    {
        self::assertArrayNotHasKey('uhifadhi/storage-module', self::composer()['suggest'] ?? []);
    }

    /**
     * @return array{require: array<string, string>, require-dev: array<string, string>, suggest?: array<string, string>}
     */
    private static function composer(): array
    {
        $raw = file_get_contents(\dirname(__DIR__, 2).'/composer.json');
        self::assertIsString($raw);

        /** @var array{require: array<string, string>, require-dev: array<string, string>, suggest?: array<string, string>} $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
