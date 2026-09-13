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

namespace Uhifadhi\Incident\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Incident\Entity\Incident;

/**
 * EVERY MOMENT THIS MODULE PRINTS IS READ IN THE READER'S OWN ZONE.
 *
 * A moment is stored as UTC and rendered once, on a server, in whatever single
 * zone that server runs in. So a ranger in the field and an analyst three
 * timezones away read the same wall-clock off the same page and one of them reads
 * it wrong — which on a case file is a claim about when something happened.
 *
 * THE FIX IS THE SHELL'S AND THE MODULE NAMES NO CONTROLLER: a printed instant is
 * `<time datetime="<the instant>">` with a readable fallback as its text, and the
 * frame's own scanner rewrites the text to the reader's locale and zone. A module
 * that named a controller for it would stop rendering in a host with no shell.
 *
 * AND A FORM IS THE HARD HALF. `datetime-local` has no zone in it: its value is a
 * wall clock, and the same string means a different instant to every reader. So
 * the input carries the instant it was filled from as a machine attribute, the
 * form carries the zone the reader's browser is in, and the server reads the wall
 * clock IN THAT ZONE — falling back to UTC, never to the server's own zone, so a
 * reader with no JavaScript gets exactly what the page showed them.
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/assets/controllers/localtime_controller.js
 */
final class ReaderZoneTest extends FunctionalTestCase
{
    /** The instant every case in this file is written against. */
    private const string INSTANT = '2026-08-22T18:32:00+00:00';

    /** Its wall clock in UTC, which is what the server may print. */
    private const string UTC_WALL_CLOCK = '2026-08-22T18:32';

    private function reportUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents/new', $areaUuid);
    }

    /**
     * THE FORM'S `When` IS FILLED IN UTC AND SAYS WHICH INSTANT THAT IS.
     *
     * The value is a wall clock and the attribute is the instant, so the browser
     * has something unambiguous to convert from. Rendering the wall clock in the
     * SOURCE's zone instead would post a wall clock nobody could resolve: with no
     * JavaScript the server would read 08:15 as 08:15Z when the observation
     * happened at 05:15Z.
     */
    public function testTheWhenInputCarriesTheInstantItWasFilledFrom(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $input = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'OBS-02',
            'at' => self::INSTANT,
        ]))->filter('input[name="occurred_at"]');

        self::assertCount(1, $input);
        self::assertSame(self::UTC_WALL_CLOCK, $input->attr('value'));
        self::assertSame(self::INSTANT, $input->attr('data-instant'));
    }

    /**
     * AND THE FORM CARRIES A FIELD FOR THE READER'S ZONE, empty until a browser
     * fills it. Empty means UTC, which is what the input was printed in.
     */
    public function testTheFormCarriesAnEmptyZoneFieldForTheBrowserToFill(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $zone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))
            ->filter('form.ro-form input[name="occurred_at_zone"]');

        self::assertCount(1, $zone);
        self::assertSame('hidden', $zone->attr('type'));
        self::assertSame('', (string) $zone->attr('value'));
    }

    /**
     * A FILING WITH NO ZONE STORES THE WALL CLOCK AS UTC — the JavaScript-free
     * round trip, and the reason the input is printed in UTC. 18:32 in, 18:32Z
     * stored.
     */
    public function testAFilingWithNoZoneReadsTheWallClockAsUtc(): void
    {
        self::assertSame('2026-08-22 18:32:00 UTC', $this->fileAndReadBackOccurredAt(null));
    }

    /**
     * AND A FILING THAT SAYS WHICH ZONE ITS CLOCK IS IN IS READ IN THAT ZONE,
     * never in the server's. A browser in East Africa converts the printed 18:32Z
     * to 21:32 before anybody looks at it, and posts the zone with it — so the
     * instant that comes back is the one that went in.
     */
    public function testAFilingReadsItsWallClockInTheZoneItNames(): void
    {
        self::assertSame(
            '2026-08-22 18:32:00 UTC',
            $this->fileAndReadBackOccurredAt('Africa/Nairobi', '2026-08-22T21:32'),
        );
    }

    /** A zone nobody can resolve is not a reason to lose a report: it reads as UTC. */
    public function testAnUnreadableZoneFallsBackToUtcRatherThanRefusingTheFiling(): void
    {
        self::assertSame('2026-08-22 18:32:00 UTC', $this->fileAndReadBackOccurredAt('Mars/Olympus_Mons'));
    }

    /**
     * THE CASE FILE PRINTS ITS MOMENTS AS `<time>`, with the instant on the
     * element and the shape it wants beside it — so the frame can localise a tight
     * cell to a clock without blowing it out to a full date.
     */
    public function testTheCaseFilePrintsEveryMomentAsATimeElement(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $page = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertResponseIsSuccessful();
        // The identity band's Filed row: a date and a clock, each its own element.
        $filed = $page->filter('.factband .f')->reduce(
            static fn (Crawler $f): bool => 'Filed' === trim($f->filter('.k')->text()),
        );
        self::assertCount(1, $filed);
        self::assertSame(
            $incident->getReportedAt()->format('c'),
            $filed->filter('time')->first()->attr('datetime'),
        );
        self::assertSame(
            ['day', 'clock'],
            $filed->filter('time')->each(static fn (Crawler $t): string => (string) $t->attr('data-localtime-format')),
        );

        // And the timeline, which is the record of when each move was made.
        $first = $page->filter('.i-tl .i-tl-t time')->first();
        self::assertSame('stamp', $first->attr('data-localtime-format'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T[\d:]{8}\+\d{2}:\d{2}$/', (string) $first->attr('datetime'));
    }

    /** And so does the rail beside the report form. */
    public function testTheRailsWhenRowPrintsTheInstantAsATimeElement(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $when = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'OBS-02',
            'at' => '2026-08-22T08:15:00+03:00',
        ]))->filter('[data-incident-observation] .rln time');

        self::assertCount(1, $when);
        // THE SAME INSTANT THE HAND-OFF STATED, offset-qualified: an instant
        // without an offset is one the browser resolves in its own zone, which is
        // the defect again. 08:15+03:00 is 05:15Z, and the register holds instants
        // in one zone so that a naive column cannot lose the offset.
        self::assertSame('2026-08-22T05:15:00+00:00', $when->attr('datetime'));
        self::assertSame('daystamp', $when->attr('data-localtime-format'));
        // …and the fallback text is what a reader with no JavaScript sees.
        self::assertStringContainsString('05:15', $when->text());
    }

    /**
     * File a report the way a browser would, then read back the instant the
     * register actually holds.
     */
    private function fileAndReadBackOccurredAt(?string $zone, string $wallClock = self::UTC_WALL_CLOCK): string
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());
        $uuid = $this->uuidOf($area);

        $form = [
            '_token' => $this->tokenFrom($this->client->request('GET', $this->reportUrl($uuid))->html()),
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Riverside',
            'lat' => '-3.2014',
            'lng' => '-29.5378',
            'occurred_at' => $wallClock,
            'blocks' => [
                'species' => ['species' => 'Lion', 'sex' => 'unknown'],
                'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '4']]],
                'parties' => ['rows' => [['role' => 'claimant', 'name' => 'A stock owner']]],
                'money' => ['claimed' => '900000'],
            ],
        ];
        if (null !== $zone) {
            $form['occurred_at_zone'] = $zone;
        }

        $this->client->request('POST', \sprintf('/areas/%s/modules/incidents', $uuid), $form);
        self::assertResponseRedirects();

        $filed = $this->em->getRepository(Incident::class)->findOneBy(['area' => $area], ['id' => 'DESC']);
        self::assertNotNull($filed, 'The filing was refused, so there is no instant to read back.');
        $occurredAt = $filed->getOccurredAt();
        self::assertNotNull($occurredAt);

        return $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s T');
    }
}
