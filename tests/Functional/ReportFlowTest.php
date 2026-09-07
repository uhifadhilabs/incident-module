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

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Tests\Integration\Fixtures\StubRecordFileSource;

/**
 * FILING AN INCIDENT, over HTTP.
 *
 * The design's own economics are the thing under test: a report is CHEAP and a
 * verification is EXPENSIVE. Three answers file an incident — a kind, one line
 * saying what happened, and a place — and everything else is offered on the
 * record afterwards.
 *
 * ONE CONTAINER, WHATEVER THE ENTRY POINT — the ruled direction (A, the full
 * page, with D's quick-file discipline inside it). A filing gets an ADDRESS: it
 * survives a reload, a dropped connection, a second tab and a pasted link, it
 * prints, and no click beside it can throw a half-written report away. The
 * slide-over drawer the module used to open when a filing arrived from a record
 * is retired; a filing FROM a record renders the same full page, with the source
 * card riding at its head. Both entry points render the same step partials, gate
 * the same three answers, and post to the same endpoint.
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
        // TWO headed sections down one column: what kind, and what happened.
        // There was a third — "People & evidence — can wait" — and it asked
        // nothing; the promise it carried is one line beside the File control
        // now. A headed step must have a question in it.
        self::assertCount(2, $crawler->filter('form.ro-form .ro-sect'));
        // One chooser per kind of incident, all four, drawn as cards.
        self::assertCount(4, $crawler->filter('.i-catpick .i-catopt'));
        // …and a field set per sub-category, so choosing one swaps the questions
        // without a round trip.
        self::assertCount(16, $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"]'));
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

    // ── FILING FROM A RECORD — the same full page ─────────────────────────────

    /**
     * A FILING THAT ARRIVES FROM A RECORD IS THE SAME FULL PAGE, never a
     * slide-over. The drawer is retired: the filing gets an address it can be
     * reloaded, deep-linked and printed from, and the source card rides at the
     * head of the page rather than inside a panel that a click could dismiss.
     */
    public function testFilingFromARecordRendersTheSameFullPageAndNeverADrawer(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        self::assertResponseIsSuccessful();
        // The one container: the full page's form, exactly as a standalone filing.
        self::assertCount(1, $crawler->filter('form.ro-form'));
        // Nothing of the retired drawer is left in the document.
        self::assertCount(0, $crawler->filter('.ro-slideover'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        self::assertCount(0, $crawler->filter('.ro-behind'));
        self::assertCount(0, $crawler->filter('.ro-dhd'));
        self::assertCount(0, $crawler->filter('.ro-dfoot'));
    }

    /** The source card rides at the head of the full page, above the questions. */
    public function testTheSourceCardRidesAtTheHeadOfTheFullPage(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        // At the head of the page, above the form — exactly as design A draws it,
        // and not tucked inside the form or any panel.
        self::assertCount(1, $crawler->filter('.i-src'));
        self::assertCount(0, $crawler->filter('form.ro-form .i-src'));
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
        self::assertNull($crawler->filter('.ro-filebar .hint')->attr('hidden'));
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
     * A PARTIAL PREFILL STILL RENDERS THE FULL PAGE. A truncated link, a
     * hand-typed URL, a bookmark from a deleted observation — anything that does
     * not carry a whole record — renders the same page, with no source card
     * claiming a provenance it does not have.
     */
    public function testAPartialPrefillRendersTheFullPageWithNoSourceCard(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        // Everything the seam sends EXCEPT the record.
        $query = self::FROM_A_RECORD;
        unset($query['label']);

        $crawler = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)).'?'.http_build_query($query));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.ro-form'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        // No record, so nothing claims one.
        self::assertCount(0, $crawler->filter('.i-src'));
    }

    // ── ONE SET OF STEPS, ONE CONTAINER ──────────────────────────────────────

    /**
     * EVERY ENTRY POINT GATES IDENTICALLY. The File control ships DEAD, the same
     * quiet line names the same missing answers, and the same three targets are
     * wired — because it is one page rendered by one set of partials, whether the
     * filing came from a record or from nowhere.
     */
    public function testEveryEntryPointShipsTheSameDeadFileControlAndGate(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        // A record that carried nothing but its identity, so it is asked for
        // exactly the same three answers a standalone filing is.
        $bare = $this->reportUrl($this->uuidOf($area)).'?'.http_build_query([
            'source' => 'patrol_observation',
            'record' => Uuid::v7()->toRfc4122(),
            'label' => 'observation 2 of patrol P-0142',
        ]);

        $standalone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $fromABareRecord = $this->client->request('GET', $bare);

        foreach ([$standalone, $fromABareRecord] as $crawler) {
            self::assertCount(1, $crawler->filter('form.ro-form'));
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
     * ONE SET OF STEP PARTIALS, ONE CONTAINER. Whatever the entry point, the page
     * renders every sub-category's field set and every category chooser, wired to
     * the same targets, and drawn as the design's cards.
     */
    public function testTheFullPageRendersEveryStepWiredToTheController(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $standalone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $fromARecord = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        foreach ([$standalone, $fromARecord] as $crawler) {
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
            // The kind is drawn as the design's cards, and there are no drawer
            // chips anywhere.
            self::assertCount(4, $crawler->filter('.i-catpick .i-catopt'));
            self::assertCount(0, $crawler->filter('.ro-chips'));
        }
    }

    /**
     * THE QUICK-FILE DISCIPLINE IS ONE SENTENCE, NOT A SECTION.
     *
     * Section 3 was "People & evidence — can wait": a headed section containing
     * four rows of prose, each with a "can wait" pill, and an explainer listing
     * how bad / people / evidence / money. It had NO INPUTS. A form section with
     * nothing to fill in is teaching copy dressed as UI — the reader counts three
     * steps, finds the third asks nothing, and learns that a heading here does
     * not mean a question.
     *
     * The promise survives, because the promise was the point: one quiet line
     * beside the File control, saying what is needed now and what is added on the
     * record afterwards. Two steps, two questions, and the discipline stated once
     * where the decision to file is actually made.
     */
    public function testTheQuickFileDisciplineIsOneLineAndNotASection(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aReporter());

        $standalone = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)));
        $fromARecord = $this->client->request('GET', $this->fromARecordUrl($this->uuidOf($area)));

        foreach ([$standalone, $fromARecord] as $crawler) {
            // The section, its rows and its pills are gone from the document.
            self::assertCount(0, $crawler->filter('.ro-later'));
            self::assertStringNotContainsString('Then, on the record', $crawler->text());
            self::assertStringNotContainsString('People & evidence', $crawler->text());

            // …and the one line stands in their place, beside the File control.
            $promise = $crawler->filter('.ro-promise');
            self::assertCount(1, $promise);
            self::assertStringContainsString(
                'Only the kind, what happened and where are needed now. Severity, people, evidence and money are added on the record afterwards.',
                $promise->text(),
            );

            // Exactly the three ruled requirements are still marked needed: the
            // kind, the line, and the place.
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

        $depredation = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="livestock-depredation"]')->html();
        self::assertStringContainsString('Loss claimed', $depredation);

        $natural = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="natural-mortality"]')->html();
        self::assertStringNotContainsString('Loss claimed', $natural);
        self::assertStringNotContainsString('Fine to assess', $natural);

        // Roadkill sits beside natural mortality under the same kind and DOES
        // carry money — which is why the money row is a sub-category's business
        // and never a category's.
        $roadkill = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="roadkill"]')->html();
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

        $mortality = $crawler->filter('[data-uhifadhi--incident-module--incident-report-target="fieldset"][data-subcategory="roadkill"]');
        $siblings = $mortality->filter('.i-taxsub a')->each(static fn ($node) => $node->attr('data-subcategory'));

        self::assertSame(['roadkill', 'natural-mortality', 'disease-die-off', 'poisoning'], $siblings);
    }

    // ── THE SERVER, UNCHANGED ────────────────────────────────────────────────

    /** Filing writes an incident, at `reported`, and lands on its case file. */
    public function testFilingCreatesAReportedIncidentAndOpensIt(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate');
        $reporter = $this->aReporter();
        $this->client->loginUser($reporter);

        $html = $this->client->request('GET', $this->reportUrl($this->uuidOf($area)))->html();
        $this->client->request('POST', $this->createUrl($this->uuidOf($area)), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Riverside',
            'lat' => '-3.21',
            'lng' => '35.25',
            'severity' => 'high',
            'narrative' => 'They came in the night.',
            'details_species' => 'Lion',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $incident = $this->em->getRepository(Incident::class)->findOneBy(['title' => 'Lion killed four goats at Riverside']);
        self::assertNotNull($incident);
        self::assertSame(IncidentStatusEnum::Reported, $incident->getStatus());
        self::assertSame('high', $incident->getSeverity()->value);
        self::assertSame('They came in the night.', $incident->getNarrative());
        self::assertSame(['species' => 'Lion'], $incident->getDetails());
        // The point was resolved to a zone in PostGIS, once, at filing.
        self::assertSame('North Gate', $incident->zoneLabel());
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
     * A REFUSED FILING FROM A RECORD COMES BACK ON THE SAME FULL PAGE, with the
     * source card still at its head and everything that was typed still there.
     * Being refused is not a new entry point, and there is one container either
     * way — so it is answered where it was made.
     */
    public function testARefusedFilingFromARecordComesBackOnTheFullPage(): void
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
        self::assertCount(1, $crawler->filter('form.ro-form'));
        self::assertCount(0, $crawler->filter('.ro-drawer'));
        // The source card is still there — the provenance survived the refusal.
        self::assertCount(1, $crawler->filter('.i-src'));
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
        self::assertStringContainsString('uhifadhistorage/preview', $crawler->html());
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
        self::assertStringNotContainsString('uhifadhistorage/preview', $crawler->html());
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
            'title' => 'Fresh lion tracks 400 m from North Gate bomas',
            'lat' => '-3.2014',
            'lng' => '35.4622',
        ]);

        self::assertResponseRedirects();
        $incident = $this->em->getRepository(Incident::class)->findOneBy(['title' => 'Fresh lion tracks 400 m from North Gate bomas']);
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
            'title' => 'Lion killed four goats at Riverside',
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
