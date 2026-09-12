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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Exception\AreaListConflictException;
use Uhifadhi\Incident\Model\AreaListWords;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;

/**
 * EVERY WRITE THAT REACHES ONE OF AN AREA'S FOUR LISTS, in one place — so the
 * three rules the design will not bend are enforced once rather than re-argued
 * per route:
 *
 *  · A WORD IS UNIQUE WITHIN ITS LIST, and the list within the area. Two rows
 *    reading "Lion" would end the one promise a list makes, so a collision is
 *    refused ({@see AreaListConflictException}).
 *
 *  · THE KEY IS BORN ONCE AND NEVER CHANGES. It is derived from the first word,
 *    made unique inside the list, and then frozen — renaming never touches it,
 *    which is exactly why a rename is safe on a record already filed and why an
 *    offline handset can hold it.
 *
 *  · NOTHING IS DELETED. Retiring stamps a date; the row, its key and every
 *    record filed under it are kept, and one call brings it back. There is no
 *    delete method here because there is no delete control on the screen.
 *
 * It owns the flush, as {@see TaxonomyAdminService} does and for the same reason:
 * each call is one discrete admin action behind an HTTP POST, so "did it save?"
 * is the whole question.
 */
final class AreaListService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AreaListEntryRepository $entries,
    ) {
    }

    /**
     * Write a word into one of an area's lists.
     *
     * @throws AreaListConflictException on an empty or duplicate word
     */
    public function add(AreaOfInterest $area, AreaListEnum $list, string $label, string $note = ''): AreaListEntry
    {
        $label = self::clean($label);
        if ('' === $label) {
            throw AreaListConflictException::label('A word needs something to say.');
        }
        if ($this->entries->labelExistsInList($area, $list, $label)) {
            throw AreaListConflictException::label(\sprintf('This area\'s %s list already has "%s".', $list->label(), $label));
        }

        $entry = new AreaListEntry($area, $list, $this->resolveKey($area, $list, $label), $label);
        $entry->setNote(self::note($note));
        $entry->setPosition($this->entries->maxPositionForList($area, $list) + 1);

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * Correct a word. THE KEY IS DELIBERATELY UNTOUCHED: the entry keeps the
     * identity every filed record points at, and only what a person reads changes.
     *
     * @throws AreaListConflictException on an empty or duplicate word
     */
    public function rename(AreaListEntry $entry, string $label): AreaListEntry
    {
        $label = self::clean($label);
        if ('' === $label) {
            throw AreaListConflictException::label('A word needs something to say.');
        }
        if ($this->entries->labelExistsInList($entry->getArea(), $entry->getList(), $label, $entry)) {
            throw AreaListConflictException::label(\sprintf('This area\'s %s list already has "%s".', $entry->getList()->label(), $label));
        }

        $entry->setLabel($label);
        $this->em->flush();

        return $entry;
    }

    public function setNote(AreaListEntry $entry, string $note): AreaListEntry
    {
        $entry->setNote(self::note($note));
        $this->em->flush();

        return $entry;
    }

    /** Off the form and off the handsets; still on every record filed under it. */
    public function retire(AreaListEntry $entry, ?\DateTimeImmutable $now = null): AreaListEntry
    {
        $entry->retire($now ?? new \DateTimeImmutable());
        $this->em->flush();

        return $entry;
    }

    public function reactivate(AreaListEntry $entry): AreaListEntry
    {
        $entry->reactivate();
        $this->em->flush();

        return $entry;
    }

    /**
     * WHAT THE REPORT FORM AND THE CASE FILE READ — one area's words, offered and
     * resolvable, in one query.
     */
    public function wordsFor(AreaOfInterest $area): AreaListWords
    {
        return AreaListWords::fromEntries($this->entries->forArea($area));
    }

    /**
     * A key from a word: lowercase, hyphen-joined, ascii-safe, unique inside the
     * list. A word with nothing ascii in it still gets a key, because the key is
     * what records point at and a row without one could not be pointed at.
     */
    private function resolveKey(AreaOfInterest $area, AreaListEnum $list, string $label): string
    {
        $base = self::slug($label);
        $key = $base;
        $n = 2;
        while ($this->entries->keyExistsInList($area, $list, $key)) {
            $key = self::slug($base.'-'.$n);
            ++$n;
        }

        return $key;
    }

    private static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = mb_substr(trim($value, '-'), 0, 80);
        $value = trim($value, '-');

        return '' !== $value ? $value : 'word';
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private static function note(string $note): ?string
    {
        $note = self::clean($note);

        return '' === $note ? null : mb_substr($note, 0, 160);
    }
}
