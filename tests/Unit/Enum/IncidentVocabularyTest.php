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

namespace Uhifadhi\Incident\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;

/**
 * The small vocabularies the design draws chips and badges from. Each one is
 * pinned to the stylesheet class it wears, because a value with no class renders
 * as an unstyled word and nobody notices until it is in front of a warden.
 */
final class IncidentVocabularyTest extends TestCase
{
    public function testSeverityIsThreeStepsWithTheStylesheetsClasses(): void
    {
        self::assertSame(['low', 'medium', 'high'], array_map(
            static fn (IncidentSeverityEnum $s) => $s->value,
            IncidentSeverityEnum::ordered(),
        ));
        self::assertSame('lo', IncidentSeverityEnum::Low->cssClass());
        self::assertSame('md', IncidentSeverityEnum::Medium->cssClass());
        self::assertSame('hi', IncidentSeverityEnum::High->cssClass());
    }

    /**
     * THE COMMUNITY-REPORT RULING, as a value: an SMS from a village is a source,
     * not a state. Every source lands an incident on `reported`; what differs is
     * the badge and whether the module presents it as trusted.
     */
    public function testASourceIsABadgeAndNeverAState(): void
    {
        self::assertSame('direct', IncidentSourceEnum::Direct->value);
        self::assertSame('walk-in or desk report', IncidentSourceEnum::Direct->label());
        self::assertSame('SMS from the community', IncidentSourceEnum::Sms->label());

        // Only a report the authority took itself is trusted on arrival; the rest
        // are ordinary reported incidents whose badge says where they came from.
        self::assertTrue(IncidentSourceEnum::Direct->isFirstParty());
        self::assertTrue(IncidentSourceEnum::PatrolObservation->isFirstParty());
        self::assertFalse(IncidentSourceEnum::Sms->isFirstParty());
        self::assertFalse(IncidentSourceEnum::Community->isFirstParty());
    }

    public function testMoneyRunsInExactlyTwoDirectionsAndTheyAreNeverAdded(): void
    {
        self::assertSame('fine', MoneyDirectionEnum::Fine->value);
        self::assertSame('compensation', MoneyDirectionEnum::Compensation->value);

        // The design refuses one "amount" field: a fine is owed TO the authority,
        // a claim is owed BY it, and the words for the money that moved differ.
        self::assertSame('Fines — owed TO the authority', MoneyDirectionEnum::Fine->heading());
        self::assertSame('Compensation — owed BY the authority', MoneyDirectionEnum::Compensation->heading());
        self::assertSame('collected', MoneyDirectionEnum::Fine->settledWord());
        self::assertSame('paid', MoneyDirectionEnum::Compensation->settledWord());
    }

    public function testEveryPartyIsTheSameRecordWearingADifferentRole(): void
    {
        $roles = array_map(static fn (PartyRoleEnum $r) => $r->value, PartyRoleEnum::cases());

        // The design refuses four tables: a suspect, a claimant, a witness and the
        // ranger who filed it are one shape with a role — and the animal is a
        // party too, so a repeat offender is recognisable across incidents.
        self::assertContains('claimant', $roles);
        self::assertContains('suspect', $roles);
        self::assertContains('witness', $roles);
        self::assertContains('reporter', $roles);
        self::assertContains('verifier', $roles);
        self::assertContains('animal', $roles);

        // Only two roles are coloured by the stylesheet; the rest wear the plain chip.
        self::assertSame('claimant', PartyRoleEnum::Claimant->cssClass());
        self::assertSame('reporter', PartyRoleEnum::Reporter->cssClass());
        self::assertSame('', PartyRoleEnum::Witness->cssClass());
    }

    public function testEvidenceIsEitherAPhotographOrADocument(): void
    {
        self::assertSame(['photo', 'document'], array_map(
            static fn (EvidenceKindEnum $k) => $k->value,
            EvidenceKindEnum::cases(),
        ));
        self::assertSame('', EvidenceKindEnum::Photo->cssClass());
        self::assertSame('doc', EvidenceKindEnum::Document->cssClass());
    }

    public function testTimelineEntriesWearTheKindTheStylesheetColours(): void
    {
        self::assertSame('tr', IncidentEventKindEnum::Transition->cssClass());
        self::assertSame('ev', IncidentEventKindEnum::Evidence->cssClass());
        self::assertSame('mo', IncidentEventKindEnum::Money->cssClass());
        // A plain note has no colour of its own — it is the timeline's default.
        self::assertSame('', IncidentEventKindEnum::Note->cssClass());
    }
}
