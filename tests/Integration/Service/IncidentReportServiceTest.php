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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentPrefill;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * FILING, against a real database and a real PostGIS.
 *
 * The two facts that cannot be added later without lying are the ones asserted
 * hardest here: WHERE it happened (a real point, in a real zone) and WHAT IT CAME
 * FROM (provenance, written once).
 */
final class IncidentReportServiceTest extends IntegrationTestCase
{
    private function reports(): IncidentReportService
    {
        /** @var IncidentReportService $reports */
        $reports = $this->service('incident.report');

        return $reports;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTaxonomy();
    }

    /** EVERY source lands on `reported`. There is no way to file something verified. */
    public function testAFiledIncidentStartsAtReported(): void
    {
        $incident = $this->anIncident($this->anArea());

        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame('INC-0001', $incident->getReference());
        // The first event IS the filing: a record whose history begins after it
        // was created cannot say who started it.
        self::assertCount(1, $incident->getEvents());
    }

    /** Case numbers are issued in order and never reused. */
    public function testCaseNumbersRunInOrder(): void
    {
        $area = $this->anArea();

        self::assertSame('INC-0001', $this->anIncident($area)->getReference());
        self::assertSame('INC-0002', $this->anIncident($area)->getReference());
        self::assertSame('INC-0003', $this->anIncident($area)->getReference());
    }

    /**
     * THE POINT IS RESOLVED TO A ZONE, in PostGIS, once — at filing. An incident
     * does not move, so recomputing it per read would be a spatial join behind
     * every widget.
     */
    public function testTheZoneIsResolvedFromTheRealGeometry(): void
    {
        $area = $this->anArea();
        $west = $this->aZone($area, 'Endulen', 35.0, 35.5);
        $this->aZone($area, 'Naabi', 35.5, 36.0);

        // 35.25 is inside Endulen and outside Naabi.
        $incident = $this->anIncident($area);

        self::assertSame($west->getId(), $incident->getZone()?->getId());
        self::assertSame('Endulen', $incident->zoneLabel());
    }

    /** UNZONED IS A FIRST-CLASS ANSWER — an org with no zones is the normal state. */
    public function testAnAreaWithNoZonesFilesPerfectlyWell(): void
    {
        $incident = $this->anIncident($this->anArea());

        self::assertNull($incident->getZone());
        self::assertSame('unzoned', $incident->zoneLabel());
    }

    /**
     * FILING OPENS NO MONEY RECORD, whatever the category.
     *
     * A sub-category that CARRIES money is one whose form offers the fields; it
     * is not a promise that this incident involves any. The row appears when
     * somebody records an amount — which is also when the case file's money card
     * appears, and why a roadkill with no fine can still be resolved.
     */
    public function testFilingOpensNoMoneyRecord(): void
    {
        $area = $this->anArea();

        $conflict = $this->anIncident($area, 'livestock-depredation');
        self::assertNull($conflict->getMoney());
        // …but the FORM will offer the fields, because the category carries them.
        self::assertTrue($conflict->carriesMoney());
        self::assertSame(MoneyDirectionEnum::Compensation, $conflict->getSubcategory()->getMoneyDirection());

        $mortality = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        self::assertNull($mortality->getMoney());
        self::assertFalse($mortality->carriesMoney());
    }

    /** Roadkill is the ruling made real: one entry, and it MAY carry a FINE. */
    public function testRoadkillMayCarryAFineRatherThanBeingTwoLinkedIncidents(): void
    {
        $roadkill = $this->anIncident($this->anArea(), 'roadkill', 'Zebra roadkill on the C-road, km 12');

        self::assertTrue($roadkill->carriesMoney());
        self::assertSame(MoneyDirectionEnum::Fine, $roadkill->getSubcategory()->getMoneyDirection());
        // Nobody has been fined, so there is nothing on the record — and that is
        // an incident that can be resolved.
        self::assertNull($roadkill->getMoney());
    }

    /**
     * PROVENANCE IS WRITTEN ONCE. An incident filed from a patrol observation
     * stays linked to it forever, and nothing can rewrite it.
     */
    public function testAnIncidentFiledFromAnObservationKeepsThatLinkForever(): void
    {
        $observation = Uuid::v7();
        $incident = $this->reports()->file(
            area: $this->anArea(),
            subcategory: $this->subcategory('livestock-depredation'),
            title: 'Fresh lion tracks 400 m from Endulen bomas',
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: new \DateTimeImmutable('2026-08-22 08:15:00'),
            source: IncidentSourceEnum::PatrolObservation,
            prefill: new IncidentPrefill(
                record: $observation,
                label: 'observation 2 of patrol P-0142',
                backUrl: '/areas/x/modules/patrols/observation/2',
            ),
        );

        self::assertTrue($incident->hasProvenance());
        self::assertSame($observation->toRfc4122(), $incident->getSourceRecordUuid()?->toRfc4122());
        self::assertSame('observation 2 of patrol P-0142', $incident->getSourceRecordLabel());

        $this->expectException(\LogicException::class);
        $incident->recordProvenance(Uuid::v7(), 'somewhere else entirely');
    }

    /**
     * A COMMUNITY REPORT IS AN ORDINARY REPORTED INCIDENT WEARING A BADGE. The
     * ruling, as a stored row: no sixth place, and no permission changes.
     */
    public function testAnSmsFromAVillageEntersLikeEverythingElse(): void
    {
        $incident = $this->reports()->file(
            area: $this->anArea(),
            subcategory: $this->subcategory('crop-raiding'),
            title: 'Elephants in the gardens at Ngoitokitok',
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: new \DateTimeImmutable('2026-08-22 06:00:00'),
            source: IncidentSourceEnum::Sms,
        );

        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame(IncidentSourceEnum::Sms, $incident->getSource());
        self::assertFalse($incident->getSource()->isFirstParty());
        self::assertSame('SMS', $incident->getSource()->badge());
    }

    /**
     * Only the fields THIS sub-category asks for are stored, so re-categorising
     * never carries a stale answer into a new form.
     */
    public function testOnlyTheFieldsTheCategoryAsksForAreStored(): void
    {
        $incident = $this->reports()->file(
            area: $this->anArea(),
            subcategory: $this->subcategory('roadkill'),
            title: 'Zebra roadkill on the C-road',
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: new \DateTimeImmutable('2026-08-21 16:20:00'),
            details: ['species' => 'Zebra', 'enclosure' => 'thorn boma', 'road_segment' => 'C-road, km 12'],
        );

        self::assertSame(['species' => 'Zebra', 'road_segment' => 'C-road, km 12'], $incident->getDetails());
    }

    /** The person who filed it is a party to it, in the reporter's role. */
    public function testTheFilerIsNamedAsAPartyToTheirOwnReport(): void
    {
        $user = $this->aUser('ranger@example.test', 'Joseph', 'Mollel');
        $incident = $this->anIncident($this->anArea(), reportedBy: $user);

        $parties = $incident->getParties()->toArray();
        self::assertCount(1, $parties);
        self::assertSame('J. Mollel', $parties[0]->getName());
        self::assertSame('reporter', $parties[0]->getRole()->value);
    }
}
