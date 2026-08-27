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
use UhifadhiLabs\Incident\Tests\Integration\Fixtures\StubRecordFileSource;

/**
 * FILING AN INCIDENT, over HTTP.
 *
 * The design's own economics are the thing under test: a report is CHEAP and a
 * verification is EXPENSIVE. Three answers file an incident — a kind, one line
 * saying what happened, and a place — and everything else is offered on the
 * record afterwards.
 *
 * THE CONTAINER FOLLOWS THE ENTRY POINT, and this file pins that rule:
 * a filing that arrives carrying a source record opens as the SLIDE-OVER DRAWER
 * over the register it came from; a filing that arrives from nowhere opens as
 * the FULL PAGE. Both render the same step partials, gate the same three
 * answers, and post to the same endpoint.
 */
final class ReportFlowTest extends FunctionalTestCase
{
    /** The provenance a filing arriving from a patrol observation carries. */
    private const array FROM_A_RECORD = [
        'source' => 'patrol_observation',
        'label' => 'observation 2 of patrol P-0142',
        'back' => '/areas/x/modules/patrols/observation/2',
        'at' => '2026-08-22T08:15:00+03:00',
        'lat' => '-3.2014',
        'lng' => '35.4622',
        'note' => 'Fresh lion tracks 400 m from the bomas.',
    ];

    private function reportUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents/new', $areaUuid);
    }

    private function createUrl(string $areaUuid): string
    {
        return \sprintf('/areas/%s/modules/incidents', $areaUuid);
    }

    /** A filing from the record the fixture module actually holds photographs for. */
    private function fromAStubbedRecordUrl(string $areaUuid): string
    {
        // array_merge, not `+`: a union keeps the LEFT side's keys, so the
        // source token below would be silently ignored.
        return $this->reportUrl($areaUuid).'?'.http_build_query(array_merge(
            self::FROM_A_RECORD,
            [
                'record' => StubRecordFileSource::RECORD,
                'source' => StubRecordFileSource::TOKEN,
            ],
        ));
    }

    /** The same route, carrying a record — which is what opens the drawer. */
    private function fromARecordUrl(string $areaUuid, ?string $record = null): string
    {
        return $this->reportUrl($areaUuid).'?'.http_build_query(
            self::FROM_A_RECORD + ['record' => $record ?? Uuid::v7()->toRfc4122()],
        );
    }

    // ── THE FULL PAGE — standalone filing ────────────────────────────────────

    /**
     * A FILING THAT CAME FROM NOWHERE IS A PAGE. It has an address, it survives a
     * reload, and nothing beside it can throw it away — which is the whole reason
     * the centred sheet was retired as the container.
     */
    public function testStandaloneFilingRendersTheFullPageAtItsOwnRoute(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ro-form'));
        // Not an overlay: no drawer, no stage, and nothing left of the sheet.
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        self::assertCount(0, $crawler->filter('.ro-stage'));
        self::assertStringNotContainsString('i-sheet', $crawler->html());
        // Three headed sections down one column: kind, what happened, and the
        // one that can wait.
        self::assertCount(3, $crawler->filter('form.ro-form .ro-sect'));
        // One chooser per kind of incident, all four, drawn as cards.
        self::assertCount(4, $crawler->filter('.i-catpick .i-catopt'));
        // …and a field set per sub-category, so choosing one swaps the questions
        // without a round trip.
        self::assertCount(16, $crawler->filter('[data-uhifadhilabs--incident-module--incident-report-target="fieldset"]'));
    }

    /** An honest back link, not a dismissal: it says where it goes. */
    public function testTheFullPageOffersAWayBackToTheRegister(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));

        self::assertSame(
            $this->createUrl($this->uuidOf($area)),
            $crawler->filter('.pghead .pgact a')->first()->attr('href'),
        );
        self::assertSame(
            $this->createUrl($this->uuidOf($area)),
            $crawler->filter('.ro-filebar a.tgl')->attr('href'),
        );
    }

    // ── THE DRAWER — filing from a record ────────────────────────────────────

    /**
     * A FILING THAT ARRIVES FROM A RECORD IS A SLIDE-OVER, and the register it
     * came from is still on screen behind it. That is the entire point of the
     * container: filing about something you can still see never means having to
     * remember it.
     */
    public function testFilingFromARecordRendersTheSlideOverWithThePageBehindIt(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, title: 'Lion killed four goats at Osinoni');
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // The panel, and the dimmed pane it sits in.
        self::assertCount(1, $crawler->filter('.ro-slideover'));
        self::assertCount(1, $crawler->filter('.ro-slideover .ro-slideback'));
        self::assertCount(1, $crawler->filter('.ro-slideover aside.ro-drawer'));
        // It is a dialog, and it is modal — the register behind it is to be read,
        // not worked.
        self::assertSame('dialog', $crawler->filter('aside.ro-drawer')->attr('role'));
        self::assertSame('true', $crawler->filter('aside.ro-drawer')->attr('aria-modal'));
        // The page behind is the REGISTER, in the page's own flow, with real rows
        // in it — not a picture of one.
        self::assertCount(1, $crawler->filter('.ro-behind'));
        self::assertStringContainsString(
            'Lion killed four goats at Osinoni',
            $crawler->filter('.ro-behind')->text(),
        );
        // …and it is context for the filer, so it is not read out twice.
        self::assertSame('true', $crawler->filter('.ro-behind')->attr('aria-hidden'));
        // The full page's container is not also on the screen.
        self::assertCount(0, $crawler->filter('form.ro-form'));
    }

    /**
     * CLOSING IS EXPLICIT, AND ONLY EXPLICIT. The X closes the panel and says
     * where it goes — back to the record the filing came from. THE BACKDROP
     * CLOSES NOTHING: a click that missed the panel may not discard a report in
     * progress.
     */
    public function testTheSlideOverClosesByTheXAndNeverByTheBackdrop(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        $identifier = $crawler->filter('[data-controller*="incident-report"]')->attr('data-controller');
        self::assertNotNull($identifier);

        // The X: a real link, so it works with no JavaScript at all, taken over
        // by the controller to play the panel out and to ask before discarding.
        self::assertSame('/areas/x/modules/patrols/observation/2', $crawler->filter('.ro-dhd a.x')->attr('href'));
        self::assertStringContainsString(
            $identifier.'#close',
            (string) $crawler->filter('.ro-dhd a.x')->attr('data-action'),
        );
        // Cancel goes to the same place.
        self::assertSame('/areas/x/modules/patrols/observation/2', $crawler->filter('.ro-dfoot a.tgl')->attr('href'));

        // THE BACKDROP IS INERT. Nothing is wired to it, in any form.
        self::assertNull($crawler->filter('.ro-slideback')->attr('data-action'));
        self::assertNull($crawler->filter('.ro-slideback')->attr('onclick'));
    }

    /** The source card rides INSIDE the panel, above the questions. */
    public function testTheSourceCardRidesInsideTheSlideOver(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        self::assertCount(1, $crawler->filter('.ro-drawer .ro-dbody .i-src'));
    }

    /**
     * THE PANEL SHIPS OUT. Nothing in the markup hides it — the controller marks
     * it animated and the stylesheet only then takes over the slide, so a filer
     * with no JavaScript meets the form rather than an empty pane.
     */
    public function testTheSlideOverIsNotHiddenByTheMarkupItShipsWith(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        $slideover = $crawler->filter('.ro-slideover');
        self::assertNull($slideover->attr('hidden'));
        self::assertNull($slideover->attr('data-ro-animated'));
        self::assertStringNotContainsString('open', (string) $slideover->attr('class'));
    }

    /**
     * THE LINE ARRIVES ANSWERED. The observation already said what happened, and
     * asking somebody to write it a second time is the surest way to get a report
     * that never exists. It is editable — and the SOURCE CARD still shows the
     * note verbatim, so trimming the line for the register never touches the
     * original.
     */
    public function testTheLineIsPrefilledFromTheRecordsNoteAndTheCardKeepsTheOriginal(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        // A TEXTAREA, in the same grammar as the verbatim note below it: what a
        // person writes at the roadside is a sentence, not a database field.
        $line = $crawler->filter('form textarea[name="title"]');
        self::assertCount(1, $line);
        self::assertSame('Fresh lion tracks 400 m from the bomas.', $line->text());
        // Verbatim, above the questions, untouched by anything done to the line.
        self::assertStringContainsString(
            'Fresh lion tracks 400 m from the bomas.',
            $crawler->filter('.i-src .note')->text(),
        );
        // …and a prefilled line COUNTS AS FILLED: the gate does not ask for it
        // again, and with the category still to choose the File control is still
        // dead.
        self::assertStringNotContainsString('describe what happened', $crawler->filter('.ro-gate')->text());
        self::assertNotNull($crawler->filter('button.ro-file')->attr('disabled'));
    }

    /**
     * A FILING THAT ARRIVED COMPLETE IS FILEABLE ON SIGHT. Category, line and
     * place all came with the record, so nothing is missing, the gate says
     * nothing, and the control is alive before a single keystroke.
     */
    public function testAFilingThatArrivedCompleteShipsWithTheControlAlive(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $url = $this->fromARecordUrl($this->uuidOf($area)).'&category=livestock-depredation';
        $crawler = $this->client->request('GET', $url);

        self::assertNull($crawler->filter('button.ro-file')->attr('disabled'));
        self::assertNotNull($crawler->filter('.ro-gate')->attr('hidden'));
        self::assertNull($crawler->filter('.ro-dfoot .hint')->attr('hidden'));
    }

    /**
     * A NOTE LONGER THAN THE REGISTER'S LINE IS CLAMPED, NEVER REFUSED. The note
     * is written to be read, not to fit a column; nothing is lost, because the
     * provenance link keeps the original reachable forever.
     */
    public function testALineLongerThanTheRegisterCanPrintIsStoredShortRatherThanRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => str_repeat('a', 260),
            'lat' => '-3.21',
            'lng' => '35.25',
        ]);

        self::assertResponseRedirects();
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        self::assertNotNull($incident);
        self::assertSame(200, mb_strlen($incident->getTitle()));
    }

    /**
     * DEEP-LINKING THE DRAWER WITHOUT A RECORD FALLS BACK TO THE PAGE. A drawer
     * needs something to be over; an address that opens one over nothing is a
     * broken promise, so the same route renders the full page whenever no record
     * arrived with it.
     */
    public function testTheDrawerRouteWithoutARecordFallsBackToTheFullPage(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        // Everything the seam sends EXCEPT the record — a truncated link, a
        // hand-typed URL, a bookmark from a deleted observation.
        $query = self::FROM_A_RECORD;
        unset($query['label']);

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.http_build_query($query));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ro-form'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        // No record, so nothing claims one.
        self::assertCount(0, $crawler->filter('.i-src'));
    }

    // ── ONE SET OF STEPS, TWO CONTAINERS ─────────────────────────────────────

    /**
     * BOTH CONTAINERS GATE IDENTICALLY. The File control ships DEAD in each, the
     * same quiet line names the same missing answers, and the same three targets
     * are wired — because they are the same partials, rendered twice.
     */
    public function testBothContainersShipTheSameDeadFileControlAndTheSameGate(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        // A record that carried nothing but its identity, so the two containers
        // are asked for exactly the same three answers.
        $bare = $this->reportUrl($this->uuidOf($area)).'?'.http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'observation 2 of patrol P-0142',
        ]);

        $page = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $drawer = $this->client->request('GET', $bare);

        foreach ([$page, $drawer] as $crawler) {
            $file = $crawler->filter('button.ro-file[type="submit"]');
            self::assertCount(1, $file);
            self::assertNotNull($file->attr('disabled'));
            self::assertCount(1, $crawler->filter('.ro-gate'));
            self::assertSame(
                'choose a category · describe what happened · mark where it happened',
                trim($crawler->filter('.ro-gate')->text()),
            );
        }

        // And what a record DID carry, it is not asked for twice: this one came
        // with a place and a line.
        $prefilled = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));
        self::assertSame('choose a category', trim($prefilled->filter('.ro-gate')->text()));
    }

    /**
     * ONE SET OF STEP PARTIALS, NEVER TWO COPIES. Both containers render every
     * sub-category's field set and every category chooser, wired to the same
     * targets — a drift between them would show here first.
     */
    public function testBothContainersRenderTheSameStepsWiredToTheSameController(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $page = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $drawer = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        foreach ([$page, $drawer] as $crawler) {
            $identifier = $crawler->filter('[data-controller*="incident-report"]')->attr('data-controller');
            self::assertNotNull($identifier);

            self::assertCount(4, $crawler->filter(\sprintf('[data-%s-target="category"][data-subcategories*=","]', $identifier)));
            self::assertCount(16, $crawler->filter(\sprintf('[data-%s-target="fieldset"]', $identifier)));
            self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="gate"]', $identifier)));
            self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="file"]', $identifier)));
            self::assertCount(1, $crawler->filter(\sprintf('[data-%s-target="hint"]', $identifier)));
            // One line, one place, one form — the fields are hoisted out of the
            // swapped field sets, so there is exactly one of each to answer.
            self::assertCount(1, $crawler->filter('form textarea[name="title"]'));
            self::assertCount(1, $crawler->filter('form input[name="lat"]'));
        }

        // The kind is drawn as cards on the page and as chips in the drawer —
        // the same partial, at two widths.
        self::assertCount(4, $page->filter('.i-catpick .i-catopt'));
        self::assertCount(4, $drawer->filter('.ro-chips .ro-chip'));
    }

    /**
     * D'S QUICK-FILE DISCIPLINE, IN BOTH. Three answers file an incident; how
     * bad, people, evidence and money are present but secondary, in the "add now
     * or later on the record" grammar the append-only timeline makes honest.
     */
    public function testBothContainersOfferTheOptionalWorkAsSomethingThatCanWait(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $page = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $drawer = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        foreach ([$page, $drawer] as $crawler) {
            $later = $crawler->filter('.ro-later');
            self::assertCount(1, $later);
            foreach (['How bad', 'People', 'Evidence', 'Money'] as $offered) {
                self::assertStringContainsString($offered, $later->text());
            }
            // Every one of them marked as something that can wait.
            self::assertCount(4, $later->filter('.lrow .ro-opt'));
            // …and the reason it is honest to move them: the append-only
            // timeline keeps who added what and when.
            self::assertStringContainsString('timeline keeps who added it and when', $later->text());

            // Exactly the three ruled requirements are marked needed: the kind,
            // the line, and the place.
            self::assertCount(3, $crawler->filter('.ro-req'));
        }
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

    // ── THE SERVER, UNCHANGED ────────────────────────────────────────────────

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
     * A REFUSED FILING COMES BACK IN THE CONTAINER IT WAS MADE IN. Entry point
     * decides the container, and being refused is not a new entry point: a
     * filing from a record is answered in the drawer, over the register, with
     * everything that was typed still there.
     */
    public function testARefusedFilingFromARecordComesBackInTheDrawer(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $query = http_build_query(self::FROM_A_RECORD + ['record' => Uuid::v7()->toRfc4122()]);
        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.$query)->html();

        $crawler = $this->client->request('POST', $this->createUrl($this->uuidOf($area)).'?'.$query, [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => '',
            'lat' => '-3.2014',
            'lng' => '35.4622',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('.ro-drawer'));
        self::assertCount(0, $crawler->filter('form.ro-form'));
        self::assertStringContainsString('One line saying what happened', $crawler->filter('.i-errors')->text());
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

    /**
     * THE SOURCE CARD SHOWS THE RECORD'S PHOTOGRAPHS — through the cross-module
     * seam, and without this bundle knowing what an observation is.
     *
     * It has a record uuid and a source token from a query string, and it hands
     * both straight to the platform's file registry, which asks the module that
     * OWNS the record. Here that module is a fixture, which is the point: nothing
     * in the report flow names it.
     */
    public function testTheSourceCardShowsTheRecordsPhotographs(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromAStubbedRecordUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // A BUTTON, not a link: the shared preview reads a click inside an <a>
        // as navigation and stands aside, so a thumbnail wrapped in one would
        // leave the flow for a raw image file.
        $shots = $crawler->filter('.i-src .shots button[type="button"]');
        self::assertCount(2, $shots);
        self::assertCount(0, $crawler->filter('.i-src .shots a'));

        // Each one is drawn from the storage route by its THUMBNAIL key — the
        // small picture, never the original, on a card.
        self::assertStringContainsString(
            'fieldwork/rec-1/first.jpg.thumb.jpg',
            (string) $crawler->filter('.i-src .shots img')->first()->attr('src'),
        );
        self::assertCount(2, $crawler->filter('.i-src .shots img'));
        // …and the strip says so in the card's own words.
        self::assertStringContainsString('2 photographs', $crawler->filter('.i-src .seam')->text());
    }

    /**
     * THEY OPEN IN THE SHARED PREVIEW — the one component every surface opens a
     * file in, so a photograph looks the same and says the same things whether it
     * is opened from the Files hub, from its own record, or from here. This module
     * draws none of that overlay: it includes the component and puts the
     * component's own data contract on each thumbnail.
     */
    public function testThePhotographsOpenInTheSharedPreviewComponent(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromAStubbedRecordUrl($this->uuidOf($area)));

        $first = $crawler->filter('.i-src .shots button')->first();
        // The trigger contract, filled from the FileEntry the owning module gave.
        self::assertNotNull($first->attr('data-f-preview'));
        self::assertSame('first.jpg', $first->attr('data-f-name'));
        self::assertSame('REC-0001', $first->attr('data-f-rec'));
        self::assertSame('Fieldwork', $first->attr('data-f-modlabel'));
        self::assertStringContainsString('fieldwork/rec-1/first.jpg', (string) $first->attr('data-f-original'));

        // The overlay itself, included once — this module ships no copy of it.
        self::assertGreaterThan(0, $crawler->filter('[data-controller*="preview"]')->count());
        // …and its stylesheet, loaded only where there is something to open.
        self::assertStringContainsString('uhifadhilabsstorage/preview', $crawler->html());
    }

    /**
     * NO PHOTOGRAPHS IS A FACT, NOT A FAILURE. A record nobody photographed, a
     * token naming a module this deployment does not have, a storage bundle that
     * is not installed — all of them draw a source card with no strip, and none of
     * them costs anybody a report.
     */
    public function testACardWithNoPhotographsSimplyHasNoStrip(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        // Same shape, a record the owning module has never heard of.
        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.i-src'));
        self::assertCount(0, $crawler->filter('.i-src .shots'));
        self::assertStringNotContainsString('photograph', $crawler->filter('.i-src .seam')->text());
        // Nothing to open, so the overlay's stylesheet is not asked for either.
        self::assertStringNotContainsString('uhifadhilabsstorage/preview', $crawler->html());
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

    /** A bad link opens an EMPTY form — as a page, since nothing came with it. */
    public function testAnUnreadablePrefillJustOpensAnEmptyForm(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?record=not-a-uuid&lat=999&at=nonsense');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.i-src'));
        self::assertCount(1, $crawler->filter('form.ro-form'));
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
     * THE DASHBOARD'S REPORT BUTTON IS NOT ANCHORED TO A RECORD, so it is
     * STANDALONE filing and it navigates to the full page. Nothing is mounted
     * over the dashboard any more — the centred sheet is retired as a container.
     */
    public function testTheDashboardReportButtonNavigatesToTheFullPage(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $dashboard = $this->client->request('GET', $this->createUrl($this->uuidOf($area)));

        self::assertSame($this->reportUrl($this->uuidOf($area)), $dashboard->filter('.pgact a.cta')->attr('href'));
        // A plain link, not a trigger: nothing opens over this page.
        self::assertNull($dashboard->filter('.pgact a.cta')->attr('data-action'));
        self::assertStringNotContainsString('i-sheet', $dashboard->html());
        self::assertCount(0, $dashboard->filter('.ro-drawer'));
        self::assertCount(0, $dashboard->filter('form.ro-form'));
    }
}
