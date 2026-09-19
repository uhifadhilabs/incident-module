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

use Uhifadhi\Contracts\Performance\PerformanceGeoProviderInterface;

/**
 * The core's atlas plate, played by a fixture: it receives every service
 * tagged {@see PerformanceGeoProviderInterface::TAG} exactly as the core's own
 * collector does, so a test can see what this bundle actually published.
 *
 * The tag is applied BY HAND in the bundle's extension (a reusable bundle is
 * not autoconfigured), and this is what proves it stuck — ground figures that
 * silently failed to register show up as the plate saying nobody publishes
 * any, and nowhere else.
 */
final readonly class CollectedGeoProviders
{
    /**
     * @param iterable<PerformanceGeoProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return array<string, PerformanceGeoProviderInterface> keyed by the module's slug
     */
    public function bySlug(): array
    {
        $bySlug = [];
        foreach ($this->providers as $provider) {
            $bySlug[$provider->moduleSlug()] = $provider;
        }

        return $bySlug;
    }
}
