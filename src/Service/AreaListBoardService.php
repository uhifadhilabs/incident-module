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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Model\AreaListPanel;
use Uhifadhi\Incident\Model\AreaListRow;
use Uhifadhi\Incident\Model\AreaListUse;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;

/**
 * THE LISTS SECTION, ASSEMBLED — four folds, each with its summary, who reads it,
 * its rows and their two figures.
 *
 * THE COUNTS COME OFF THE REGISTER, not off a counter. Each list's question keeps
 * its answer at one place in an incident's block answers, and that place is asked
 * of {@see \Uhifadhi\Incident\Model\BlockQuestionCatalogue} rather than spelled
 * out here — so the figures cannot drift from where the form actually writes.
 *
 * A WORD IS COUNTED BY ITS KEY AND BY ITS OWN LABEL. Records filed before this
 * list had an editor hold the word a filer typed, so a row's figure is the sum of
 * both readings; after a rename the label no longer matches and the key does,
 * which is precisely the reliability the key was introduced for.
 *
 * "THIS MONTH" IS THE CALENDAR MONTH THE READER IS IN, filed rather than
 * occurred: the editor is answering "how much is this word being used", and the
 * day a report reached the register is when it started being used.
 */
final readonly class AreaListBoardService
{
    public function __construct(
        private AreaListEntryRepository $entries,
        private TaxonomyKindRepository $kinds,
    ) {
    }

    /**
     * @param string $open the wire value of the list whose fold is open; the first when it names none
     *
     * @return list<AreaListPanel> in the enum's order, which is the order the form asks them in
     */
    public function forArea(AreaOfInterest $area, string $open = '', ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $since = $now->modify('first day of this month')->setTime(0, 0);

        $uses = AreaListUse::all($this->kinds->forArea($area));
        $entries = $this->entries->forArea($area);
        $opened = null !== AreaListEnum::tryFrom($open) ? $open : AreaListEnum::cases()[0]->value;

        $panels = [];
        foreach (AreaListEnum::cases() as $list) {
            $use = $uses[$list->value] ?? null;
            if (null === $use) {
                continue;
            }

            $counts = $this->entries->countsByAnswer($area, $use->blockValue, $use->questionKey, $since);

            $rows = [];
            foreach ($entries[$list->value] ?? [] as $entry) {
                $month = 0;
                $all = 0;
                // BY KEY AND BY LABEL — see the class docblock.
                foreach (array_unique([$entry->getKey(), $entry->getLabel()]) as $reading) {
                    $month += $counts[$reading]['month'] ?? 0;
                    $all += $counts[$reading]['all'] ?? 0;
                }

                $rows[] = new AreaListRow(
                    uuid: $entry->getUuid()->toRfc4122(),
                    key: $entry->getKey(),
                    label: $entry->getLabel(),
                    note: $entry->getNote(),
                    retiredAt: $entry->getRetiredAt(),
                    thisMonth: $month,
                    allTime: $all,
                );
            }

            $panels[] = new AreaListPanel($list, $use, $rows, $opened === $list->value);
        }

        return $panels;
    }
}
