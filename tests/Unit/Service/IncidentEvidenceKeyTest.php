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

namespace Uhifadhi\Incident\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentCategory;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Service\IncidentEvidenceKey;

/**
 * WHICH EVIDENCE KEYS ARE THIS MODULE'S, ASKED IN ONE PLACE.
 *
 * The writer, the voter and the Files hub source all need the same answer, and
 * the failure mode of disagreeing is silent — a photograph nobody is allowed to
 * look at, on a page the reader is entitled to read. These assertions pin the
 * shape of a key so the three cannot drift apart without something going red.
 */
final class IncidentEvidenceKeyTest extends TestCase
{
    public function testAKeyIsFiledUnderTheModulesPrefixAndTheCaseFilesUuid(): void
    {
        $incident = self::incident();

        self::assertSame(
            'incident/'.$incident->getUuid()->toRfc4122(),
            IncidentEvidenceKey::prefixFor($incident),
        );
    }

    /**
     * THE UUID, NEVER THE REFERENCE. A reference is presentation — it is minted
     * from the register and a deployment could renumber it — and evidence filed
     * under one would be evidence that moved when the label did.
     */
    public function testTheCaseFileIsNamedByItsUuidAndNotByItsReference(): void
    {
        $incident = self::incident();

        self::assertStringNotContainsString('INC-0313', IncidentEvidenceKey::prefixFor($incident));
    }

    #[DataProvider('keys')]
    public function testWhichKeysThisModuleClaims(string $key, bool $claimed): void
    {
        self::assertSame($claimed, IncidentEvidenceKey::claims($key));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function keys(): iterable
    {
        yield 'ours' => ['incident/0199a/e77c.jpg', true];
        yield 'a preview of ours' => ['incident/0199a/e77c.jpg.thumb.jpg', true];
        yield 'patrol' => ['patrol/0199a/e77c.jpg', false];
        yield 'a lookalike' => ['incidents/0199a/e77c.jpg', false];
        yield 'nothing at all' => ['', false];
    }

    private static function incident(): Incident
    {
        $area = new AreaOfInterest()->setSource('test fixture');
        $area->setName('Kifaru Sector');

        $category = new IncidentCategory('conflict', 'Human–wildlife conflict', 'hwc');
        $subcategory = new IncidentSubcategory($category, 'livestock-depredation', 'livestock depredation');

        return new Incident(
            $area,
            $subcategory,
            'INC-0313',
            'Lion killed four goats at Riverside',
            '{"type":"Point","coordinates":[-29.55,-3.21]}',
            new \DateTimeImmutable('2026-08-19 07:10:00'),
        );
    }
}
