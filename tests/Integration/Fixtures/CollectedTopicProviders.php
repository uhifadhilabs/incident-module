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

use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;

/**
 * The core's performance page, played by a fixture: it receives every service
 * tagged {@see PerformanceTopicProviderInterface::TAG} exactly as the core's
 * own collector does, so a test can see what this bundle actually published.
 *
 * The tag is applied BY HAND in the bundle's extension (a reusable bundle is
 * not autoconfigured), and this is what proves it stuck — a topic that
 * silently failed to register shows up as the performance page having no
 * Incidents section at all, and nowhere else.
 */
final readonly class CollectedTopicProviders
{
    /**
     * @param iterable<PerformanceTopicProviderInterface> $topics
     */
    public function __construct(private iterable $topics)
    {
    }

    /**
     * @return array<string, PerformanceTopicProviderInterface> keyed by the topic's own key
     */
    public function byKey(): array
    {
        $byKey = [];
        foreach ($this->topics as $topic) {
            $byKey[$topic->key()] = $topic;
        }

        return $byKey;
    }
}
