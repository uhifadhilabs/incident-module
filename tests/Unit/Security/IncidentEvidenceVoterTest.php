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

namespace Uhifadhi\Incident\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Security\IncidentEvidenceVoter;

/**
 * The permission half of storage-module's seam for incident evidence. Until this
 * voter existed no module claimed `incident/…` keys, so storage denied them by
 * default and every incident photograph, document and preview 404'd on the hub.
 *
 * The signed-in GRANT path (findOneByPath !== null) needs a live repository, so
 * it is proven where it belongs: by the storage decider test (which also covers
 * a preview resolving to its original before this voter is asked) and by
 * rendering the Files hub. These unit cases pin the parts that stand alone — the
 * keys it claims, and its refusal of an anonymous visitor before any lookup.
 */
final class IncidentEvidenceVoterTest extends TestCase
{
    public function testItClaimsIncidentKeysIncludingPreviewsAndIgnoresOthers(): void
    {
        $voter = $this->voter();

        self::assertTrue($voter->claimsKey('incident/INC-0001/IMG_1.jpg'));
        self::assertTrue($voter->claimsKey('incident/INC-0001/IMG_1.jpg.thumb.jpg'));
        self::assertFalse($voter->claimsKey('observation/x/k.jpg'));
    }

    public function testAnAnonymousVisitorIsRefusedBeforeAnyRecordLookup(): void
    {
        // A null user short-circuits before the repository is consulted, so a
        // voter built without a live repository still answers this correctly.
        self::assertFalse($this->voter()->mayRead('incident/INC-0001/IMG_1.jpg', null));
    }

    /**
     * A voter whose repository is never reached by the cases above — built
     * without its constructor so no database stands behind the unit test.
     */
    private function voter(): IncidentEvidenceVoter
    {
        $repository = new \ReflectionClass(IncidentEvidenceRepository::class)->newInstanceWithoutConstructor();

        return new IncidentEvidenceVoter($repository);
    }
}
