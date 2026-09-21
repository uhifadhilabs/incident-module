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

namespace Uhifadhi\Incident\Tests\Unit\Access;

use Uhifadhi\Bundle\TeamBundle\Test\AccessConformanceTestCase;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Module\IncidentModuleProvider;

/**
 * THE CORE'S OWN CONFORMANCE, RUN OVER THIS MODULE'S DECLARATIONS.
 *
 * The core holds its routes, doors and concerns together with build tests of
 * its own; a module's are its own business and its own CI, so the rules travel
 * as a base class and this module runs them in `composer check`. What they
 * refuse is in the base class's docblock — one declaration per key, a sentence
 * on every concern, a module concern naming its module, `own` carrying the
 * module's own words, the sensitive concerns named explicitly, every door
 * through the helper, and every route under an area passing that area to its
 * per-area gates.
 */
final class AccessConformanceTest extends AccessConformanceTestCase
{
    protected static function source(): ConcernSourceInterface
    {
        return new IncidentConcerns();
    }

    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function moduleSlug(): string
    {
        return IncidentModuleProvider::SLUG;
    }

    /**
     * NAMED HERE AS WELL AS DECLARED, deliberately — the two statements are
     * held against each other, so a fact that quietly stopped being sensitive
     * fails the build instead of widening in silence.
     *
     * These are the two an organization can withhold from somebody who reads
     * the rest of a case: the people named on it and what it is worth.
     */
    protected static function sensitiveConcerns(): array
    {
        return [IncidentConcerns::CASE_FILES, IncidentConcerns::CASE_MONEY];
    }
}
