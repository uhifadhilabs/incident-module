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

namespace Uhifadhi\Incident\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * DEVKIT's content collector, played by a fixture — the same shape as
 * {@see CollectedModules}. devkit installs through require-dev and is absent
 * here, so the tag it reads is what a test has to read instead.
 *
 * It collects every declaration in the kernel, this module's and the core's
 * alike, which is what makes it usable for seeding a module that depends on
 * another's content: `dependsOn()` names the key, and the key is what this is
 * indexed by.
 */
final readonly class CollectedContentProviders
{
    /**
     * @param iterable<ContentProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, ContentProviderInterface> keyed by the provider's key
     */
    public function byKey(): array
    {
        $byKey = [];
        foreach ($this->providers as $provider) {
            $byKey[$provider->key()] = $provider;
        }

        return $byKey;
    }
}
