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

namespace UhifadhiLabs\Incident\Tests\Integration\Repository;

use UhifadhiLabs\Incident\Repository\IncidentRepository;
use UhifadhiLabs\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE REGISTER BEHIND THE DRAWER. The slide-over container is only worth having
 * because the register stays legible behind it, so those rows are a real query
 * against real records — never a drawing of one.
 *
 * It is deliberately NOT the dashboard's filtered register: the drawer's backdrop
 * answers "what am I filing next to", which a month window would silently empty
 * on the first of the month.
 */
final class IncidentRegisterBehindTest extends IntegrationTestCase
{
    private function incidents(): IncidentRepository
    {
        /** @var IncidentRepository $incidents */
        $incidents = $this->service('incident.repository');

        return $incidents;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTaxonomy();
    }

    /** Newest first, capped, and this area's only. */
    public function testTheRowsBehindAreThisAreasNewestFirst(): void
    {
        $area = $this->anArea();
        $elsewhere = $this->anArea('Another Area');

        $this->anIncident($area, title: 'Oldest', at: new \DateTimeImmutable('2026-08-01 06:00:00'));
        $this->anIncident($area, title: 'Newest', at: new \DateTimeImmutable('2026-08-20 06:00:00'));
        $this->anIncident($area, title: 'Middle', at: new \DateTimeImmutable('2026-08-10 06:00:00'));
        $this->anIncident($elsewhere, title: 'Not this area', at: new \DateTimeImmutable('2026-08-21 06:00:00'));

        $rows = $this->incidents()->recentForArea($area, 10);

        self::assertSame(
            ['Newest', 'Middle', 'Oldest'],
            array_map(static fn ($incident) => $incident->getTitle(), $rows),
        );
    }

    /** A backdrop is context, not a listing: it takes as many rows as it is given room for. */
    public function testTheRowsBehindAreCapped(): void
    {
        $area = $this->anArea();
        foreach (range(1, 6) as $n) {
            $this->anIncident($area, title: 'Filing '.$n, at: new \DateTimeImmutable(\sprintf('2026-08-%02d 06:00:00', $n)));
        }

        self::assertCount(4, $this->incidents()->recentForArea($area, 4));
    }

    /** A first filing has nothing behind it, and that is a fact, not a failure. */
    public function testAnAreaWithNothingFiledHasNothingBehind(): void
    {
        self::assertSame([], $this->incidents()->recentForArea($this->anArea(), 5));
    }
}
