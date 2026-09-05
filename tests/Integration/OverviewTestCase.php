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

namespace Uhifadhi\Incident\Tests\Integration;

use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\IncidentTransitionService;

/**
 * ONE REGISTER, READ BY EVERY OVERVIEW TEST.
 *
 * The area-overview cards, the right-now tiles, the attention items, the map
 * layers and the pulse are five readings of the SAME eight incidents, and the
 * whole claim of the seam is that they agree. So they are built once, here, and
 * every test in tests/Integration/Overview asserts against the same morning.
 *
 * THE MORNING IS SATURDAY 22 AUGUST 2026, 11:42 — the design's own sample
 * instant, and a saturday on purpose: the card compares today with the same
 * WEEKDAY a week ago, which is a claim nothing can check on a wednesday.
 *
 * Every row goes in through the module's own door — filed by the report service,
 * moved by the transition service — so no test can assert against a record the
 * product itself could not produce.
 */
abstract class OverviewTestCase extends IntegrationTestCase
{
    /** The instant every overview assertion is made at. */
    public const string NOW_STRING = '2026-08-22 11:42:00';

    /** The same instant as a value. A method, because a class constant cannot be an object. */
    protected static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW_STRING);
    }

    /**
     * Eight incidents in two zones, spread across four places of the workflow,
     * two of them past their own category's term and two of them carrying money
     * in opposite directions.
     */
    protected function aRegister(): AreaOfInterest
    {
        $area = $this->anArea('Ngorongoro');
        $this->aZone($area, 'Endulen', 35.0, 35.3);
        $this->aZone($area, 'Kakesio', 35.3, 35.5);
        $this->installTaxonomy();

        $endulen = '{"type":"Point","coordinates":[35.25,-3.21]}';
        $kakesio = '{"type":"Point","coordinates":[35.40,-3.21]}';

        // INC-0001 · filed today, verified this morning.
        $this->moved(
            $this->filed($area, 'snaring', 'Snare line lifted at the Lerai forest edge', '2026-08-22 07:40:00', $endulen),
            ['2026-08-22 09:10:00' => IncidentTransitionEnum::Verify],
        );

        // INC-0002 · filed today, nobody has looked yet.
        $this->filed($area, 'livestock-depredation', 'Fresh lion tracks 400 m from Endulen bomas', '2026-08-22 08:15:00', $endulen);

        // INC-0003 · the newest thing on the register.
        $this->moved(
            $this->filed($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern', '2026-08-22 10:52:00', $kakesio),
            ['2026-08-22 11:20:00' => IncidentTransitionEnum::Verify],
        );

        // INC-0004 · yesterday, work started, well inside its 14-day term.
        $this->moved(
            $this->filed($area, 'unauthorized-construction', 'Three new structures outside the agreed boma footprint', '2026-08-21 11:05:00', $kakesio),
            ['2026-08-21 14:00:00' => IncidentTransitionEnum::Verify, '2026-08-21 15:30:00' => IncidentTransitionEnum::Respond],
        );

        // INC-0005 · filed the SAME WEEKDAY A WEEK AGO and closed out this
        // morning: what the "closed out" figure counts and the "filed" one does not.
        $this->moved(
            $this->filed($area, 'crop-raiding', 'Elephants through the Kakesio gardens overnight', '2026-08-15 09:00:00', $kakesio),
            [
                '2026-08-15 13:00:00' => IncidentTransitionEnum::Verify,
                '2026-08-16 08:00:00' => IncidentTransitionEnum::Respond,
                '2026-08-22 09:00:00' => IncidentTransitionEnum::Resolve,
            ],
        );

        // INC-0006 · twelve days open against a 72-hour term.
        $this->moved(
            $this->filed($area, 'crop-raiding', 'Maize lost along the Kakesio boundary', '2026-08-10 09:00:00', $kakesio),
            ['2026-08-11 09:00:00' => IncidentTransitionEnum::Verify],
        );

        // INC-0007 · a compensation claim approved in july and still unpaid. Its
        // 30-day term has NOT run out, which one global SLA would have got wrong.
        $claim = $this->moved(
            $this->filed($area, 'livestock-depredation', 'Lion killed four goats at Osinoni', '2026-07-26 09:00:00', $endulen),
            ['2026-07-26 15:00:00' => IncidentTransitionEnum::Verify, '2026-07-27 08:00:00' => IncidentTransitionEnum::Respond],
        );
        $this->money($claim, MoneyDirectionEnum::Compensation, approved: 4_500_000);

        // INC-0008 · a fine assessed and uncollected, seventeen days past a
        // 72-hour term: the worst thing on the register.
        $fine = $this->moved(
            $this->filed($area, 'snaring', 'Wire snares recovered, offender identified', '2026-08-05 09:00:00', $endulen),
            ['2026-08-05 12:00:00' => IncidentTransitionEnum::Verify, '2026-08-06 08:00:00' => IncidentTransitionEnum::Respond],
        );
        $this->money($fine, MoneyDirectionEnum::Fine, approved: 2_550_000);

        // Cleared on purpose: every figure below is then read back out of the
        // database rather than out of the identity map, which is the only way the
        // queries behind these cards are actually exercised.
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $reloaded);

        return $reloaded;
    }

    private function filed(AreaOfInterest $area, string $subcategory, string $title, string $at, string $position): Incident
    {
        $reports = $this->service('incident.report');
        self::assertInstanceOf(IncidentReportService::class, $reports);

        return $reports->file(
            area: $area,
            subcategory: $this->subcategory($subcategory),
            title: $title,
            position: $position,
            now: new \DateTimeImmutable($at),
        );
    }

    /**
     * @param array<string, IncidentTransitionEnum> $moves when => which move
     */
    private function moved(Incident $incident, array $moves): Incident
    {
        $transitions = $this->service('incident.transitions');
        self::assertInstanceOf(IncidentTransitionService::class, $transitions);

        foreach ($moves as $at => $move) {
            $event = $transitions->apply($incident, $move, new \DateTimeImmutable($at), actorName: 'S. Laizer');
            $this->em->persist($event);
        }
        $this->em->flush();

        return $incident;
    }

    private function money(Incident $incident, MoneyDirectionEnum $direction, int $approved): void
    {
        $money = new IncidentMoney($incident, $direction);
        $money->setApproved($approved);
        $incident->setMoney($money);
        $this->em->persist($money);
        $this->em->flush();
    }
}
