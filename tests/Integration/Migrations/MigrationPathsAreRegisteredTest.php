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

use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * THE INSTALLATION CONFIGURES NOTHING, so this is where "nothing" is checked.
 *
 * The bundle prepends its own `migrations_paths` entry, which is the shape the
 * migrations bundle documents for a bundle-shipped history:
 *
 * > migrations_paths:
 * >     'SomeBundle\Migrations': '@SomeBundle/Migrations'
 *
 * A typo in the namespace or a path that does not exist costs nothing at compile
 * time and everything at `migrations:migrate`, so both are asserted off a booted
 * container rather than read out of the source file.
 *
 * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
 * @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/Configuration.php
 *      — `migrations_paths`, "A list of namespace/path pairs where to look for migrations."
 */
final class MigrationPathsAreRegisteredTest extends KernelTestCase
{
    public const NAMESPACE = 'Uhifadhi\Incident\Migrations';

    /**
     * @return array<string, string>
     */
    private function directories(): array
    {
        self::bootKernel();

        /** @var DependencyFactory $factory */
        $factory = static::getContainer()->get('test_public.doctrine.migrations.dependency_factory');

        return $factory->getConfiguration()->getMigrationDirectories();
    }

    public function testTheModulesNamespaceIsRegistered(): void
    {
        self::assertArrayHasKey(
            self::NAMESPACE,
            $this->directories(),
            'The bundle must prepend its own migrations_paths entry, guarded on hasExtension("doctrine_migrations").',
        );
    }

    public function testItPointsAtADirectoryThatExists(): void
    {
        $directories = $this->directories();

        self::assertArrayHasKey(self::NAMESPACE, $directories);
        self::assertDirectoryExists($directories[self::NAMESPACE]);
    }

    /**
     * THE VERSIONS RESOLVE, which is the psr-4 half of the same promise. An
     * explicit `Uhifadhi\Incident\Migrations\` prefix is required in
     * composer.json: a lowercase `migrations/` directory does not resolve under
     * the package's root prefix on a case-sensitive filesystem, so a package
     * that omitted it would pass every test on macOS and find no versions in
     * production.
     */
    public function testEveryShippedVersionResolvesToAClass(): void
    {
        $directories = $this->directories();
        self::assertArrayHasKey(self::NAMESPACE, $directories);

        $files = glob(rtrim($directories[self::NAMESPACE], '/').'/Version*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The module owns tables, so it ships at least one version.');

        foreach ($files as $file) {
            $class = self::NAMESPACE.'\\'.basename($file, '.php');
            self::assertTrue(
                class_exists($class),
                \sprintf('%s does not autoload — check the psr-4 prefix in composer.json.', $class),
            );
        }
    }
}
