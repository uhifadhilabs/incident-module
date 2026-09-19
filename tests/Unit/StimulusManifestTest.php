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
 * THE CONTROLLERS THIS PACKAGE PROMISES ARE THE CONTROLLERS IT SHIPS.
 *
 * `assets/package.json` is not documentation: Flex copies its
 * `symfony.controllers` map into every installation's
 * `assets/controllers.json` on `composer require`/`update`, and the host's
 * Stimulus loader then imports each entry BY PATH. A name in the map with no
 * file behind it is therefore not a dead line in a manifest — it is
 * "Controller … does not exist in the package" thrown while a page renders,
 * which is a 500 on every screen of the installation and not a build error
 * anybody would have seen here.
 *
 * WORSE, IT SURVIVES THE FIX. Flex keeps an installation's own `enabled` flag
 * when a package it already has is updated, so a controller deleted from this
 * package stays enabled in every host that had it and breaks on the next
 * `composer update` — which is why a shipped controller is retired over TWO
 * releases: a no-op first, disabled by default, and the entry removed only in
 * the release after that.
 *
 * @see https://github.com/symfony/flex/blob/2.x/src/PackageJsonSynchronizer.php — registerWebpackResources()
 */
final class StimulusManifestTest extends TestCase
{
    public function testEveryControllerTheManifestNamesExistsOnDisk(): void
    {
        $missing = [];
        foreach (self::controllers() as $name => $config) {
            self::assertIsArray($config);
            self::assertIsString($config['main'] ?? null, \sprintf('The "%s" controller must name the file it ships in.', $name));

            if (!is_file(self::assetsPath().'/'.$config['main'])) {
                $missing[] = $name.' → '.$config['main'];
            }
        }

        self::assertSame([], $missing, 'These controllers are promised to every installation and shipped to none.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function controllers(): array
    {
        $manifest = json_decode((string) file_get_contents(self::assetsPath().'/package.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $symfony = $manifest['symfony'] ?? null;
        self::assertIsArray($symfony, 'This package declares the symfony-ux keyword; its manifest must carry a symfony block.');
        $controllers = $symfony['controllers'] ?? null;
        self::assertIsArray($controllers);
        self::assertNotSame([], $controllers, 'This package declares the symfony-ux keyword; its manifest must name its controllers.');

        /** @var array<string, mixed> $controllers */
        return $controllers;
    }

    private static function assetsPath(): string
    {
        return \dirname(__DIR__, 2).'/assets';
    }
}
