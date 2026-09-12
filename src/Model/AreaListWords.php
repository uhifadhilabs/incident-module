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

namespace Uhifadhi\Incident\Model;

use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Enum\AreaListEnum;

/**
 * ONE AREA'S WORDS, AS THE FORM AND THE CASE FILE NEED THEM — which words are
 * still offered, and what every word a record could be holding is called.
 *
 * THE TWO QUESTIONS ARE NOT THE SAME QUESTION, and conflating them is the defect
 * this class exists to prevent:
 *
 *   {@see options()} is what the report form OFFERS, and a retired word is not
 *   in it — retiring a word takes it off the form, which is the whole of what
 *   retiring does to a filer.
 *
 *   {@see label()} is what an ALREADY FILED record says, and it answers for
 *   every word the area ever had, retired ones included. A case file that
 *   printed a stable key where a warden expects an animal would be the cost of
 *   answering it out of the same map as the picker.
 *
 * AN UNKNOWN VALUE IS ITSELF. A record filed before this list had an editor
 * carries the word a filer typed, and the typed `other` name still arrives that
 * way today; neither is a key, and neither may render as a dash.
 */
final readonly class AreaListWords
{
    /**
     * @param array<string, array<string, string>> $active every list's live words, key => label, in the list's own order
     * @param array<string, array<string, string>> $all    every list's words, live and retired, key => label
     */
    private function __construct(
        private array $active,
        private array $all,
    ) {
    }

    /**
     * @param array<string, list<AreaListEntry>> $entries keyed by the list's wire value
     */
    public static function fromEntries(array $entries): self
    {
        $active = [];
        $all = [];

        foreach (AreaListEnum::cases() as $list) {
            $active[$list->value] = [];
            $all[$list->value] = [];

            foreach ($entries[$list->value] ?? [] as $entry) {
                $all[$list->value][$entry->getKey()] = $entry->getLabel();
                if ($entry->isActive()) {
                    $active[$list->value][$entry->getKey()] = $entry->getLabel();
                }
            }
        }

        return new self($active, $all);
    }

    /** An area with no words at all — what every area holds until somebody writes some. */
    public static function none(): self
    {
        return self::fromEntries([]);
    }

    /**
     * The words a select offers: key => label, live only.
     *
     * @return array<string, string>
     */
    public function options(AreaListEnum $list): array
    {
        return $this->active[$list->value] ?? [];
    }

    /** What a stored answer is called — or the answer itself where it is not a key of this list. */
    public function label(AreaListEnum $list, string $answer): string
    {
        return $this->all[$list->value][$answer] ?? $answer;
    }
}
