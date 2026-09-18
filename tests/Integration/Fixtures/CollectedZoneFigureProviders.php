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

use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;

/**
 * The core's zone surfaces, played by a fixture: they receive every service
 * tagged {@see ZoneFigureProviderInterface::TAG} exactly as the core's own
 * collector does, so a test can see what this bundle actually contributed.
 *
 * The tag is applied BY HAND in the bundle's extension (a reusable bundle is not
 * autoconfigured), and this collector is what proves it stuck — a provider that
 * silently failed to register would show up as every incidents card quietly
 * vanishing from every zone surface, and nowhere else.
 */
final readonly class CollectedZoneFigureProviders
{
    /**
     * @param iterable<ZoneFigureProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, ZoneFigureProviderInterface> keyed by module slug
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
