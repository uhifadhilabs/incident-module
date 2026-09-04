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

namespace Uhifadhi\Incident\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Model\IncidentPrefill;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentZoneLocator;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * FILING AN INCIDENT — the only door in.
 *
 * THE DESIGN'S OWN RULE ABOUT COST: a report is cheap and a verification is
 * expensive, and the form says so. Filing takes three short steps and almost
 * nothing is required, because a half-remembered report that EXISTS beats a
 * perfect one that was never filed. The cost is moved to verification, where
 * somebody competent is looking anyway.
 *
 * So this service asks for very little and refuses almost nothing. What it DOES
 * enforce is the handful of facts that cannot be added later without lying:
 *
 *  - **It starts at `reported`.** Every source, including an SMS from a village.
 *    There is no way to file something already verified.
 *  - **Provenance is written here or never.** A report arriving from a patrol
 *    observation is tied to it at creation
 *    ({@see Incident::recordProvenance()}), and nothing can rewrite that.
 *  - **The money record exists only where the sub-category carries money.**
 *    Absent, not empty — the detail page has no money card at all on a natural
 *    mortality.
 *  - **The first timeline event is the filing itself.** A record whose history
 *    begins after it was created cannot say who started it.
 */
final readonly class IncidentReportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentRepository $incidents,
        private IncidentZoneLocator $zones,
    ) {
    }

    /**
     * @param array<string, string> $details the answers to the sub-category's own field set
     */
    public function file(
        AreaOfInterest $area,
        IncidentSubcategory $subcategory,
        string $title,
        string $position,
        \DateTimeImmutable $now,
        IncidentSeverityEnum $severity = IncidentSeverityEnum::Medium,
        IncidentSourceEnum $source = IncidentSourceEnum::Direct,
        ?\DateTimeImmutable $occurredAt = null,
        ?string $narrative = null,
        ?UserInterface $reportedBy = null,
        ?IncidentPrefill $prefill = null,
        array $details = [],
    ): Incident {
        $incident = new Incident(
            $area,
            $subcategory,
            $this->incidents->nextReference(),
            $title,
            $position,
            $now,
        );

        $incident
            ->setSeverity($severity)
            ->setSource($source)
            ->setOccurredAt($occurredAt)
            ->setNarrative($narrative)
            ->setReportedBy($reportedBy)
            // Which zone the point falls in is a QUESTION FOR POSTGIS, asked once
            // at filing rather than on every read: an incident does not move, and
            // recomputing it per render would be a spatial join on every widget.
            ->setZone($this->zones->locate($area, $position))
            ->setDetails($details);

        // PROVENANCE, WRITTEN ONCE. Whatever the filer changed on the way through
        // the form, the record it came from is the record it came from.
        if (null !== $prefill && null !== $prefill->record && null !== $prefill->label) {
            $incident->recordProvenance($prefill->record, $prefill->label, $prefill->backUrl);
        }

        // NO MONEY RECORD IS OPENED HERE, deliberately. A sub-category that
        // CARRIES money is one whose form offers the fields; it is not a promise
        // that this particular incident involves any. A roadkill where no driver
        // was ever identified owes nothing, and an empty money row would make it
        // unresolvable — the resolve guard would sit waiting for an assessment
        // that is never coming. The row is created the moment somebody records an
        // amount, which is also when the case file's money card appears.
        // The person who filed it is a party to it, in the reporter's role — the
        // design's parties table names them beside the claimant and the witness,
        // because they are the same shape of record.
        $reporterName = self::nameOf($reportedBy);
        if (null !== $reporterName) {
            new IncidentParty($incident, PartyRoleEnum::Reporter, $reporterName)->setUser($reportedBy);
        }

        // THE FIRST EVENT IS THE FILING. A timeline that starts later cannot say
        // who began the record.
        new IncidentEvent(
            $incident,
            IncidentEventKindEnum::Note,
            $now,
            \sprintf('Filed as %s.', $subcategory->path()),
        )
            ->withActor($reportedBy, $reporterName)
            ->withDetail(\sprintf(
                'source: %s%s',
                $source->badge(),
                $incident->hasProvenance() ? ' · '.(string) $incident->getSourceRecordLabel() : '',
            ));

        $this->entityManager->persist($incident);
        $this->entityManager->flush();

        return $incident;
    }

    /** "J. Mollel" — the design's form for a person's name on a record. */
    private static function nameOf(?UserInterface $user): ?string
    {
        if (null === $user) {
            return null;
        }

        $first = (string) $user->getFirstName();
        $last = (string) $user->getLastName();
        $name = trim(('' !== $first ? mb_substr($first, 0, 1).'. ' : '').$last);

        return '' !== $name ? $name : null;
    }
}
