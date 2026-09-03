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
        $area = $this->anArea();
        $this->aZone($area, 'Endulen');
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', $incident->getReference());
        // The design's numbered sections, all of them, on one page.
        foreach (['IN·01', 'IN·02', 'IN·04', 'IN·05', 'IN·06', 'IN·07', 'IN·08'] as $section) {
            self::assertStringContainsString($section, $crawler->html(), \sprintf('Section %s is missing.', $section));
        }
    }

    /** An incident of another area is a 404 — the same answer as one that never existed. */
    public function testAnIncidentFromAnotherAreaIsNotFound(): void
    {
        $mine = $this->anArea('Mine');
        $theirs = $this->anArea('Theirs');
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
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));

        $html = $crawler->html();
        self::assertStringContainsString('IN·S1', $html, 'Step one has been reached and must have its panel.');
        self::assertStringNotContainsString('IN·S2', $html, 'Verification has not happened; its panel must not exist.');
        self::assertStringNotContainsString('IN·S3', $html);
        self::assertStringNotContainsString('IN·S4', $html);
    }

    /** …and the panel APPEARS once the step is reached, because it is the same rule read forwards. */
    public function testAPanelAppearsOnlyWhenItsStepIsReached(): void
    {
        $area = $this->anArea();
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
        self::assertStringContainsString('IN·S1', $html);
        self::assertStringContainsString('IN·S2', $html);
        // The next one is still not reached, and so still does not exist.
        self::assertStringNotContainsString('IN·S3', $html);
    }

    /**
     * ONLY THE LEGAL MOVES ARE OFFERED, and the illegal ones are shown as the
     * reason they are illegal — never as a button that does nothing.
     */
    public function testOnlyTheLegalMovesAreOfferedAndTheRestSayWhyNot(): void
    {
        $area = $this->anArea();
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
        $area = $this->anArea();
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

    /** Somebody without "incidents.manage" sees the rail and is offered no move at all. */
    public function testAReporterSeesTheRailAndIsOfferedNoMoves(): void
    {
        $area = $this->anArea();
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
        $area = $this->anArea();
        $claim = $this->anIncident($area, 'livestock-depredation');
        $mortality = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $this->client->loginUser($this->aManager());

        // Nothing assessed yet: no card, on either of them.
        $before = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $claim->getReference()));
        self::assertStringNotContainsString('IN·03', $before->html());

        // Somebody opens a claim — and the card is there.
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)
            ->setClaimed(1_600_000)->setAssessed(1_200_000)->setApproved(1_200_000);
        $this->em->flush();

        $after = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $claim->getReference()));
        self::assertStringContainsString('IN·03', $after->html());
        self::assertStringContainsString('1,200,000', $after->html());

        // A category that carries no money never grows one.
        $without = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $mortality->getReference()));
        self::assertStringNotContainsString('IN·03', $without->html());
    }

    /**
     * THE CATEGORY DECIDES THE FORM. A depredation asks for the enclosure and the
     * household; a roadkill asks for the road segment and the age class. Same
     * page, same component, different questions.
     */
    public function testTheFieldSetIsTheCategorysOwn(): void
    {
        $area = $this->anArea();
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

    /** The timeline is the spine, and the filing itself is its first entry. */
    public function testTheTimelineStartsWithTheFiling(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents/%s', $this->uuidOf($area), $incident->getReference()));

        self::assertCount(1, $crawler->filter('.i-tl .i-tl-item'));
        self::assertStringContainsString('Filed as', $crawler->filter('.i-tl')->text());
    }
}
