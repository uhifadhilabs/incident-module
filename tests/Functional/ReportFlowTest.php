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

namespace UhifadhiLabs\Incident\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Enum\IncidentSourceEnum;
use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;

/**
 * FILING AN INCIDENT, over HTTP.
 *
 * The design's own economics are the thing under test: a report is CHEAP and a
 * verification is EXPENSIVE. Filing takes three short steps, almost nothing is
 * required, and what little is required is required because an incident cannot
 * be without it.
 */
final class ReportFlowTest extends FunctionalTestCase
{
    private function reportUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents/new', $areaUuid);
    }

    private function createUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents', $areaUuid);
    }

    public function testTheSheetRendersItsThreeStepsAndEveryKindOfIncident(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.i-steps span:not(.arr)'));
        // One chooser per kind of incident, all four.
        self::assertCount(4, $crawler->filter('.i-catpick .i-catopt'));
        // …and a field set per sub-category, so choosing one swaps the questions
        // without a round trip.
        self::assertCount(16, $crawler->filter('[data-uhifadhilabs--incident-module--incident-report-target="fieldset"]'));
    }

    /**
     * THE MONEY ROW IS ABSENT, NOT DISABLED, on a category that carries none.
     * The field sets are in the document, so this is a claim about which of them
     * has a money row at all.
     */
    public function testOnlyCategoriesThatCarryMoneyHaveAMoneyRow(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        $depredation = $crawler->filter('[data-uhifadhilabs--incident-module--incident-report-target="fieldset"][data-subcategory="livestock-depredation"]')->html();
        self::assertStringContainsString('Loss claimed', $depredation);

        $natural = $crawler->filter('[data-uhifadhilabs--incident-module--incident-report-target="fieldset"][data-subcategory="natural-mortality"]')->html();
        self::assertStringNotContainsString('Loss claimed', $natural);
        self::assertStringNotContainsString('Fine to assess', $natural);

        // Roadkill sits beside natural mortality under the same kind and DOES
        // carry money — which is why the money row is a sub-category's business
        // and never a category's.
        $roadkill = $crawler->filter('[data-uhifadhilabs--incident-module--incident-report-target="fieldset"][data-subcategory="roadkill"]')->html();
        self::assertStringContainsString('Fine to assess', $roadkill);
    }

    /**
     * STEP 2 OFFERS THE SIBLINGS. Choosing a kind in step 1 picks its first
     * sub-category; the chips inside step 2 move between the others, and each one
     * swaps the whole field set — because the sub-category is what decides the
     * questions.
     */
    public function testStepTwoOffersTheSiblingsUnderTheChosenKind(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        $mortality = $crawler->filter('[data-uhifadhilabs--incident-module--incident-report-target="fieldset"][data-subcategory="roadkill"]');
        $siblings = $mortality->filter('.i-taxsub a')->each(static fn ($node) => $node->attr('data-subcategory'));

        self::assertSame(['roadkill', 'natural-mortality', 'disease-die-off', 'poisoning'], $siblings);
    }

    /** Filing writes an incident, at `reported`, and lands on its case file. */
    public function testFilingCreatesAReportedIncidentAndOpensIt(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Endulen');
        $reporter = $this->aReporter();
        $this->client->loginUser($reporter);

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Osinoni',
            'lat' => '-3.21',
            'lng' => '35.25',
            'severity' => 'high',
            'narrative' => 'They came in the night.',
            'details_species' => 'Lion',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $incident = $this->em->getRepository(Incident::class)->findOneBy(['title' => 'Lion killed four goats at Osinoni']);
        self::assertNotNull($incident);
        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame('high', $incident->getSeverity()->value);
        self::assertSame('They came in the night.', $incident->getNarrative());
        self::assertSame(['species' => 'Lion'], $incident->getDetails());
        // The point was resolved to a zone in PostGIS, once, at filing.
        self::assertSame('Endulen', $incident->zoneLabel());
        self::assertSame($reporter->getId(), $incident->getReportedBy()?->getId());
    }

    /**
     * ALMOST NOTHING IS REQUIRED — but the two facts an incident cannot be
     * without are: what kind of thing happened, and where.
     */
    public function testAReportWithNoCategoryOrPlaceIsRefusedWithItsFormBack(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $crawler = $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => '',
            'title' => '',
        ]);

        // 422: understood perfectly, and simply cannot be stored.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose what kind of incident this was', $crawler->filter('.i-errors')->text());
        self::assertStringContainsString('pick the place on the map', $crawler->filter('.i-errors')->text());
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * THE PICKER IS WIRED TO THE CONTROLLER THAT IS ACTUALLY REGISTERED.
     *
     * A controller shipped by a bundle is registered under its PACKAGE-QUALIFIED
     * identifier — `uhifadhilabs--incident-module--incident-report`, not
     * `incident-report` — so a target or action written with the short name is
     * inert: the category cards do not light, the field set never swaps and the
     * gate never opens. Nothing about that failure is visible in the markup,
     * which is why it is pinned here.
     *
     * The test asks the page itself which identifier it registered and then
     * demands the sheet speak that one, so it cannot drift again.
     */
    public function testTheSheetIsWiredToTheIdentifierThePageActuallyRegisters(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        $identifier = $crawler->filter('[data-controller*="incident-report"]')->attr('data-controller');
        self::assertNotNull($identifier);

        self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="sheet"]', $identifier)));
        self::assertCount(4, $crawler->filter(\sprintf('.i-catpick [data-%s-target="category"]', $identifier)));
        self::assertCount(16, $crawler->filter(\sprintf('[data-%s-target="fieldset"]', $identifier)));
        self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="gate"]', $identifier)));
        self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="file"]', $identifier)));

        // …and the actions name it too, or a click reaches nothing.
        self::assertStringContainsString(
            $identifier.'#choose',
            (string) $crawler->filter('.i-catpick .i-catopt')->first()->attr('data-action'),
        );
    }

    /**
     * THE GATE. Steps 1 and 2 are required and step 3 is not, so the File control
     * ships DEAD — the markup itself carries `disabled`, and only the controller
     * opens it once the required answers are there. A quiet line names what is
     * still missing rather than making anybody press a button to find out.
     */
    public function testTheFileControlShipsDisabledAndSaysWhatIsMissing(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        $file = $crawler->filter('.i-sheetfoot button.cta[type="submit"]')->first();
        self::assertNotNull($file->attr('disabled'));
        self::assertStringContainsString('choose a category', $crawler->filter('.i-gate')->text());
        self::assertStringContainsString('describe what happened', $crawler->filter('.i-gate')->text());
    }

    /**
     * AND THE SERVER AGREES. The gate is not a decoration in front of a
     * permissive endpoint: a filing missing one of the required steps is refused
     * with the same reason the quiet line gave.
     */
    public function testAReportWithAPlaceButNothingSaidIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $crawler = $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => '   ',
            'lat' => '-3.21',
            'lng' => '35.25',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('One line saying what happened', $crawler->filter('.i-errors')->text());
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * THE SOURCE CARD. "How is the filing user going to remember the context of
     * what happened?" By looking at it: the observation is pinned above the steps
     * with its own words, its position in the same degrees-minutes-seconds the
     * observation page prints, its time, and a way back to it.
     */
    public function testTheSourceCardShowsTheObservationThisFilingCameFrom(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $query = http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'OBS-02 · lion tracks',
            'back' => '/areas/x/modules/patrols/observation/2',
            // In the source's own zone — East Africa Time, where the observation
            // was made.
            'at' => '2026-08-22T08:15:00+03:00',
            'lat' => '-3.2014',
            'lng' => '35.4622',
            'note' => 'Fresh lion tracks 400 m from the bomas.',
        ]);

        $card = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.$query)->filter('.i-src');

        self::assertCount(1, $card);
        self::assertStringContainsString('OBS-02 · lion tracks', $card->text());
        // The note, verbatim.
        self::assertStringContainsString('Fresh lion tracks 400 m from the bomas.', $card->filter('.note')->text());
        // The position, in the observation page's own notation.
        self::assertStringContainsString('3°12\'05"S 35°27\'44"E', $card->filter('.facts')->text());
        self::assertStringContainsString('patrol observation', $card->filter('.facts')->text());
        // THE TIME AS THE OBSERVER WROTE IT — 08:15 in the field, not 05:15 in
        // UTC. A card meant to be recognised must not restate the moment in a
        // zone nobody there was standing in.
        self::assertStringContainsString('08:15', $card->filter('.facts')->text());
        // …and the way back to the record it came from.
        self::assertSame('/areas/x/modules/patrols/observation/2', $card->filter('.hd a.go')->attr('href'));
    }

    /** Nothing came from anywhere, so there is no card claiming otherwise. */
    public function testThereIsNoSourceCardOnAFilingThatCameFromNowhere(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertSelectorNotExists('.i-src');
    }

    /**
     * ARRIVING FROM A PATROL OBSERVATION. The seam is a query string, because the
     * two modules are separate bundles and neither may name the other's classes.
     * Everything it carries is a guess the filer may overrule — except the link,
     * which is written once and never again.
     */
    public function testAReportArrivingFromAnObservationCarriesItsProvenance(): void
    {
        $area = $this->anArea();
        $observation = Uuid::v7();
        $this->client->loginUser($this->aReporter());

        $query = http_build_query([
            'source' => 'patrol_observation',
            'record' => $observation->toRfc4122(),
            'label' => 'observation 2 of patrol P-0142',
            'back' => '/areas/x/modules/patrols/observation/2',
            'at' => '2026-08-22T08:15:00+00:00',
            'lat' => '-3.2014',
            'lng' => '35.4622',
            'category' => 'livestock-depredation',
            'note' => 'Fresh lion tracks 400 m from the bomas.',
        ]);

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.$query);

        self::assertResponseIsSuccessful();
        // The page says where it came from, and the note travelled with it.
        self::assertStringContainsString('observation 2 of patrol P-0142', $crawler->filter('.i-src')->text());
        self::assertStringContainsString('Fresh lion tracks', $crawler->html());

        $this->client->request('POST', $this->createUrl($this->uuidOf($area)).'?'.$query, [
            '_token' => $this->tokenFrom($crawler->html()),
            'subcategory' => 'livestock-depredation',
            'title' => 'Fresh lion tracks 400 m from Endulen bomas',
            'lat' => '-3.2014',
            'lng' => '35.4622',
        ]);

        self::assertResponseRedirects();
        $incident = $this->em->getRepository(Incident::class)->findOneBy(['title' => 'Fresh lion tracks 400 m from Endulen bomas']);
        self::assertNotNull($incident);
        self::assertTrue($incident->hasProvenance());
        self::assertSame($observation->toRfc4122(), $incident->getSourceRecordUuid()?->toRfc4122());
        self::assertSame('observation 2 of patrol P-0142', $incident->getSourceRecordLabel());
        self::assertSame(IncidentSourceEnum::PatrolObservation, $incident->getSource());
    }

    /** A bad link opens an EMPTY form. It must never be an error page. */
    public function testAnUnreadablePrefillJustOpensAnEmptyForm(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?record=not-a-uuid&lat=999&at=nonsense');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.i-src');
    }

    /** Filing needs "incidents.record". Signed in is not the same as permitted. */
    public function testFilingIsRefusedWithoutTheRecordPermission(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aUser('bystander@example.test', 'No', 'Rights'));

        $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertResponseStatusCodeSame(403);
    }

    /** And a write with no token is refused, whoever is signed in. */
    public function testAWriteWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Osinoni',
            'lat' => '-3.21',
            'lng' => '35.25',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * THE SAME COMPONENT, TWO DOORS: the dashboard mounts the identical sheet
     * over itself, so filing does not feel like a different product depending on
     * where you started.
     */
    public function testTheDashboardMountsTheVerySameSheet(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $dashboard = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)));

        self::assertCount(1, $dashboard->filter('[data-uhifadhilabs--incident-module--incident-report-target="sheet"]'));
        self::assertCount(4, $dashboard->filter('.i-catpick .i-catopt'));
        // Closed until asked for — the dedicated page is the one that opens with
        // it already open.
        self::assertStringNotContainsString('i-sheetbd open', $dashboard->html());
    }
}
