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
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\IncidentTransitionService;

/**
 * THE CASE FILE, rendered — and the two contracts the design will not bend on.
 *
 * 1. GATED PANELS. A step owns a panel, and the panel does not EXIST until the
 *    step is reached. Not rendered-and-disabled. Absent. These tests read the
 *    DOM, because "absent" is a claim about the document and nothing else can
 *    prove it.
 * 2. THE RAIL, NOT A DROPDOWN. Only the legal moves are offered, and the ones
 *    that are not are shown with the reason printed beside them.
 */
final class CaseFilePageTest extends FunctionalTestCase
{
    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = static::getContainer()->get('test_public.incident.transitions');

        return $transitions;
    }

    public function testTheWholeRecordIsOnOnePage(): void
    {
        $area = $this->anAreaWithKinds();
        $this->aZone($area, 'North Gate');
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', $incident->getReference());
        /*
         * The design's sections, all of them, on one page — named by the words
         * in their tab rather than by the workshop's reference for the frame.
         * The reference lives in the design files and never in the markup, so
         * reading it here would have been reading the one thing a warden cannot
         * see. The heading IS what they see, and it is what has to be there.
         */
        $tabs = $crawler->filter('span.tab')->each(static fn ($tab) => $tab->text());
        foreach (['Where', 'Timeline', 'Involved parties', 'Provenance & links', 'Evidence', 'Narrative'] as $section) {
            self::assertNotEmpty(
                array_filter($tabs, static fn (string $tab) => str_starts_with($tab, $section)),
                \sprintf('Section “%s” is missing.', $section),
            );
        }

        // The record's identity facts live on the shared .factband below the head
        // (the settled page-chrome theme, as every other entity-detail screen wears),
        // not in a sidebar "Incident meta" card.
        self::assertSelectorExists('.factband');
        self::assertSelectorTextContains('.factband', $incident->getReference());
    }

    /**
     * THE WAY BACK IS THE SHELL'S PILL.
     *
     * `.backbtn` is the settled cross-module idiom for the way back off a
     * record — the shell ships it, so every module's case file returns to its
     * list wearing the same control. The rule is `.backbtn`, never a `.tgl` and
     * never a page action; the sheet that defines it is the shell's, so this
     * module's own sheet stays free of a copy.
     */
    public function testTheWayBackWearsTheShellsOwnControl(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $back = $crawler->filter('a.backbtn:contains("All incidents")');
        self::assertCount(1, $back, 'The way back has to carry the shell\'s .backbtn, or it renders as a bare hyperlink.');
        self::assertGreaterThan(0, $back->filter('svg')->count(), 'And the shell\'s own chevron beside the words.');
        self::assertCount(0, $crawler->filter('a.tgl:contains("All incidents")'), 'And it is the pill, not the toggle control it wore before the shell shipped one.');
    }

    /** An incident of another area is a 404 — the same answer as one that never existed. */
    public function testAnIncidentFromAnotherAreaIsNotFound(): void
    {
        $mine = $this->anAreaWithKinds('Mine');
        $theirs = $this->anAreaWithKinds('Theirs');
        $incident = $this->anIncident($theirs);
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($mine),
            $incident->getReference(),
        ));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * THE GATE. A freshly reported incident has step one's panel and NOTHING
     * ELSE — no empty resolution form inviting somebody to fill it in early.
     */
    public function testAnUnreachedStepHasNoPanelAtAll(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $html = $crawler->html();
        self::assertStringContainsString('Step 1 —', $html, 'Step one has been reached and must have its panel.');
        self::assertStringNotContainsString('Step 2 —', $html, 'Verification has not happened; its panel must not exist.');
        self::assertStringNotContainsString('Step 3 —', $html);
        self::assertStringNotContainsString('Step 4 —', $html);
    }

    /** …and the panel APPEARS once the step is reached, because it is the same rule read forwards. */
    public function testAPanelAppearsOnlyWhenItsStepIsReached(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->transitions()->apply($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable(), null, 'S. Laizer');
        $this->em->flush();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $html = $crawler->html();
        self::assertStringContainsString('Step 1 —', $html);
        self::assertStringContainsString('Step 2 —', $html);
        // The next one is still not reached, and so still does not exist.
        self::assertStringNotContainsString('Step 3 —', $html);
    }

    /**
     * ONLY THE LEGAL MOVES ARE OFFERED, and the illegal ones are shown as the
     * reason they are illegal — never as a button that does nothing.
     */
    public function testOnlyTheLegalMovesAreOfferedAndTheRestSayWhyNot(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        // From `reported` there is exactly one move, and it is Verify.
        $buttons = $crawler->filter('.i-trans form button');
        self::assertCount(1, $buttons);
        self::assertStringContainsString('Verify', $buttons->text());

        // And exactly ONE refusal is printed: the move no person may ever make.
        // The rest are only "not that step's turn yet", which the rail above
        // already says by drawing those steps as unreached.
        $blocked = $crawler->filter('.i-trans .i-blocked');
        self::assertCount(1, $blocked);
        // The design's own words for it: "Close — only reachable from resolved".
        self::assertStringContainsString('Close', $blocked->text());
        self::assertStringContainsString('only reachable from resolved', $blocked->text());
    }

    /**
     * …and once it IS resolved, the refusal changes to the real reason: the clock
     * reaches `closed`, and no person ever does.
     */
    public function testOnAResolvedIncidentTheRefusalIsTheClockItself(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $at = new \DateTimeImmutable();
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $blocked = $crawler->filter('.i-trans .i-blocked')->text();
        self::assertStringContainsString('reached by time, not by a person', $blocked);
        self::assertCount(0, $crawler->filter('.i-trans form button'), 'Nobody may close an incident by hand.');
    }

    /**
     * A RESOLVED INCIDENT SAYS WHEN IT WILL CLOSE ITSELF — the computed date, on
     * the rail, as a hint and not a control. Closing is the clock's move; the case
     * file's job is to tell the reader when, so nobody goes hunting for a button.
     */
    public function testAResolvedIncidentShowsItsAutomaticCloseDate(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $at = new \DateTimeImmutable('2026-08-21 10:00:00');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));

        // Resolved 21 aug 13:00, closes 30 days later — the rail prints that date.
        $expected = $incident->getResolvedAt()?->modify('+30 days')->format('j M Y');
        self::assertNotNull($expected);
        $rail = $crawler->filter('.i-wf')->text();
        self::assertStringContainsString('auto-closes '.$expected, $rail);
        self::assertStringContainsString('closing is automatic', $rail);
    }

    /** Somebody without "incidents.manage" sees the rail and is offered no move at all. */
    public function testAReporterSeesTheRailAndIsOfferedNoMoves(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.i-wf .i-wfstep'), 'The rail always draws all five places.');
        self::assertCount(0, $crawler->filter('.i-trans form button'));
    }

    /**
     * THE MONEY CARD APPEARS WHEN THERE IS MONEY — absent otherwise, not empty
     * and not greyed.
     *
     * Note what that is NOT: it is not "the category carries money". A conflict
     * incident nobody has put a figure on yet has no card either, because there
     * is nothing to show — and a roadkill where no driver was identified never
     * grows one at all.
     */
    public function testTheMoneyCardAppearsOnlyWhenThereIsMoney(): void
    {
        $area = $this->anAreaWithKinds();
        $claim = $this->anIncident($area, 'livestock-depredation');
        $mortality = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $this->client->loginUser($this->aManager());

        // Nothing assessed yet: no card, on either of them.
        $before = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $claim->getReference()));
        self::assertCount(0, $before->filter('.i-moneyblock'));

        // Somebody opens a claim — and the card is there.
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)
            ->setClaimed(1_600_000)->setAssessed(1_200_000)->setApproved(1_200_000);
        $this->em->flush();

        $after = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $claim->getReference()));
        self::assertCount(1, $after->filter('.i-moneyblock'));
        self::assertStringContainsString('1,200,000', $after->html());

        // A category that carries no money never grows one.
        $without = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $mortality->getReference()));
        self::assertCount(0, $without->filter('.i-moneyblock'));
    }

    /**
     * THE CATEGORY DECIDES THE FORM. A depredation asks for the enclosure and the
     * household; a roadkill asks for the road segment and the age class. Same
     * page, same component, different questions.
     */
    public function testTheFieldSetIsTheCategorysOwn(): void
    {
        $area = $this->anAreaWithKinds();
        $depredation = $this->anIncident($area, 'livestock-depredation');
        $roadkill = $this->anIncident($area, 'roadkill', 'Zebra roadkill on the C-road, km 12');
        $this->client->loginUser($this->aManager());

        $first = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $depredation->getReference()))
            ->filter('.i-fieldset')->text();
        self::assertStringContainsString('Enclosure', $first);
        self::assertStringNotContainsString('Road segment', $first);

        $second = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $roadkill->getReference()))
            ->filter('.i-fieldset')->text();
        self::assertStringContainsString('Road segment', $second);
        self::assertStringNotContainsString('Enclosure', $second);
    }

    /**
     * THE CASE FILE IS THREE ROWS OF THE RECORD GRID, and the grid is the
     * shell's.
     *
     * A record page's rows share one column template so a card is exactly as
     * wide as the card above it, and each row is the height of its OWN content.
     * The membership is the design's: the plate beside the money, the timeline
     * beside the parties and the provenance, the evidence beside the narrative.
     *
     * THE PLATE IS A DIRECT CHILD OF THE ROW, never wrapped in a column that
     * stacks cards: the card refuses to stretch, and in a flex column that
     * refusal reads across the other axis and shrinks it to its content.
     */
    public function testTheCaseFileIsThreeRowsOfTheRecordGrid(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'livestock-depredation');
        new IncidentMoney($incident, MoneyDirectionEnum::Compensation)->setClaimed(1_600_000)->setApproved(1_200_000);
        $this->em->flush();
        $this->client->loginUser($this->aReporter());

        $rows = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ))->filter('.recgrid');

        self::assertCount(3, $rows, 'The design composes the case file as three rows of the record grid.');

        self::assertSame(['Where'], self::cardsIn($rows->eq(0)->children()->eq(0)));
        self::assertSame(['Money'], self::cardsIn($rows->eq(0)->children()->eq(1)));
        self::assertSame(['Timeline'], self::cardsIn($rows->eq(1)->children()->eq(0)));
        self::assertSame(['Involved parties', 'Provenance & links'], self::cardsIn($rows->eq(1)->children()->eq(1)));
        self::assertSame(['Evidence'], self::cardsIn($rows->eq(2)->children()->eq(0)));
        self::assertSame(['Narrative'], self::cardsIn($rows->eq(2)->children()->eq(1)));

        // The two rows whose right-hand cell stacks more than one card say so;
        // the row that holds one card on each side needs no column at all.
        self::assertCount(2, $rows->filter('.col'));

        self::assertCount(
            1,
            $rows->eq(0)->children('.plate-fill'),
            'The plate card sits straight in the row, so its refusal to stretch stays on the vertical axis.',
        );
    }

    /**
     * A ROW WITH NOTHING BESIDE IT DRAWS NOTHING BESIDE IT. The money cell of the
     * design exists only where there is money to put in it; an empty cell in a
     * stretch row is a box as tall as the plate and as empty as the reason for it.
     */
    public function testARowWhoseSecondCellHasNothingInItDrawsNoSecondCell(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $this->client->loginUser($this->aReporter());

        $rows = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ))->filter('.recgrid');

        self::assertCount(1, $rows->eq(0)->children(), 'A category that carries no money leaves the plate alone in its row.');
        self::assertSame(['Where'], self::cardsIn($rows->eq(0)->children()->eq(0)));
    }

    /**
     * The cards a grid cell holds, named by the words in their tab — a cell that
     * IS a card counts as itself.
     *
     * @return list<string>
     */
    private static function cardsIn(Crawler $cell): array
    {
        $tabs = $cell->filter('span.tab')->each(
            static fn (Crawler $tab) => trim(explode('·', $tab->text())[0]),
        );

        return array_values(array_filter($tabs, static fn (string $tab) => '' !== $tab));
    }

    /** The timeline is the spine, and the filing itself is its first entry. */
    public function testTheTimelineStartsWithTheFiling(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));

        self::assertCount(1, $crawler->filter('.i-tl .i-tl-item'));
        self::assertStringContainsString('Filed as', $crawler->filter('.i-tl')->text());
    }
}
