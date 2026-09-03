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

namespace Uhifadhi\Incident\Overview;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentAge;
use Uhifadhi\Incident\Model\IncidentOverview;
use Uhifadhi\Incident\Model\IncidentOverviewWidgets;
use Uhifadhi\Incident\Service\IncidentOverviewFigures;
use Uhifadhi\Overview\AttentionItem;
use Uhifadhi\Overview\AttentionProviderInterface;
use Uhifadhi\Overview\AttentionSeverity;

/**
 * WHAT THIS MODULE PUTS IN THE HOST'S "NEEDS ATTENTION" LIST — two kinds of row,
 * and nothing else.
 *
 * A BROKEN PROMISE, AND AN UNPAID CLAIM. Everything else the module knows is a
 * count, and a count is not something anybody can act on. These two are:
 *
 *  · PAST TERM — open work that has outrun ITS OWN category's promise. Not the
 *    oldest work: a nine-day claim against a thirty-day term is fine and a
 *    four-day injury against a 72-hour one is not, and one global SLA would call
 *    them the same. `Now` when it has run past twice over, `Soon` otherwise —
 *    the words are a promise about TIME, not about importance.
 *  · MONEY — approved compensation nobody has paid. `Watch`, always: it is
 *    being carried rather than going wrong today, and it is here because an
 *    unpaid claim is what a community remembers.
 *
 * EVERY LATE ITEM IS RAISED, not the worst five. The host sorts by urgency and
 * nobody dismisses a row by hand — an item leaves when the thing that raised it
 * is dealt with. A module that capped its list would be deciding, silently, that
 * the sixth-latest incident does not need anybody; that is the area manager's
 * call and it cannot be made here.
 */
final readonly class IncidentAttention implements AttentionProviderInterface
{
    /** What the host prints after the module's name on a past-term row. */
    private const string KIND_TERM = 'past term';

    /** …and on the money row. */
    private const string KIND_MONEY = 'money';

    public function __construct(
        private IncidentOverviewFigures $figures,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function attentionFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $overview = $this->figures->for($area, $now);

        $items = [];
        foreach ($overview->pastTerm as $incident) {
            $items[] = $this->lateItem($area, $incident, $now);
        }

        $money = $this->moneyItem($overview, $now);
        if (null !== $money) {
            $items[] = $money;
        }

        return $items;
    }

    private function lateItem(AreaOfInterest $area, Incident $incident, \DateTimeImmutable $now): AttentionItem
    {
        $hours = $incident->ageInHours($now);
        $term = $incident->getSubcategory()->getTermHours();

        return new AttentionItem(
            // TWICE THE TERM IS SOMEBODY'S DAY; once over is somebody's week.
            // The module says which, in its own vocabulary of time, and the host
            // only sorts by it.
            severity: $hours > 2 * $term ? AttentionSeverity::Now : AttentionSeverity::Soon,
            moduleSlug: $this->moduleSlug(),
            moduleLabel: 'incidents',
            // The first clause is what the host emphasises, so the first clause
            // is the fact: which record, how long, against what promise.
            headline: \sprintf(
                '%s is %s open against a %s term.',
                $incident->getReference(),
                IncidentAge::words($hours),
                $incident->getSubcategory()->termLabel(),
            ),
            kind: self::KIND_TERM,
            ageLabel: IncidentAge::label($hours),
            ageSeconds: $hours * 3600,
            url: $this->caseUrl($area, $incident),
            detail: \sprintf('%s — %s.', $incident->headline(), $incident->getStatus()->label()),
            meta: [$incident->zoneLabel(), \sprintf('filed %s', $incident->getReportedAt()->format('j M'))],
        );
    }

    /**
     * THE ONE ROW ON THIS PAGE THAT IS NOT ABOUT TODAY. Null where the area owes
     * nobody anything, which is a good day and is allowed to look like one.
     */
    private function moneyItem(IncidentOverview $overview, \DateTimeImmutable $now): ?AttentionItem
    {
        $claims = $overview->openClaims();
        $oldest = $overview->oldestUnpaid();
        if (0 === $claims || null === $oldest) {
            return null;
        }

        $hours = max(0, intdiv($now->getTimestamp() - $oldest->getReportedAt()->getTimestamp(), 3600));

        return new AttentionItem(
            // BEING CARRIED, not going wrong today — and it would be missed if it
            // were not written down, which is exactly what `Watch` means.
            severity: AttentionSeverity::Watch,
            moduleSlug: $this->moduleSlug(),
            moduleLabel: 'incidents',
            headline: \sprintf(
                '%s %s of approved compensation is unpaid across %d %s, the oldest %s old.',
                $overview->currency,
                number_format($overview->outstanding(MoneyDirectionEnum::Compensation), 0, '.', ','),
                $claims,
                1 === $claims ? 'claim' : 'claims',
                IncidentAge::words($hours),
            ),
            kind: self::KIND_MONEY,
            ageLabel: IncidentAge::label($hours),
            ageSeconds: $hours * 3600,
            // The module's money board, never an edit — the overview is a place
            // to find out, not a place to pay.
            url: $overview->dashboardUrl,
            meta: [
                \sprintf('%d %s', $overview->claimZones(), 1 === $overview->claimZones() ? 'zone' : 'zones'),
                \sprintf('oldest %s', $oldest->getReportedAt()->format('j M')),
            ],
        );
    }

    private function caseUrl(AreaOfInterest $area, Incident $incident): string
    {
        return $this->router->generate('incident_show', [
            'uuid' => $area->getUuidString(),
            'reference' => $incident->getReference(),
        ]);
    }
}
