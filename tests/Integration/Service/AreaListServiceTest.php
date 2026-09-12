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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Exception\AreaListConflictException;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;
use Uhifadhi\Incident\Service\AreaListService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * EVERY WRITE THAT REACHES ONE OF AN AREA'S LISTS, against a real database — the
 * three rules that make a list worth having, checked on what was actually stored.
 */
final class AreaListServiceTest extends IntegrationTestCase
{
    private function lists(): AreaListService
    {
        /** @var AreaListService $service */
        $service = static::getContainer()->get('test_public.incident.area_lists');

        return $service;
    }

    private function entries(): AreaListEntryRepository
    {
        /** @var AreaListEntryRepository $repository */
        $repository = static::getContainer()->get('test_public.incident.area_list_entries');

        return $repository;
    }

    public function testAWordIsStoredWithAKeyDerivedFromIt(): void
    {
        $entry = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, '  African   elephant ', 'Loxodonta africana');

        self::assertSame('african-elephant', $entry->getKey());
        self::assertSame('African elephant', $entry->getLabel(), 'The word is tidied, never mangled.');
        self::assertSame('Loxodonta africana', $entry->getNote());
        self::assertTrue($entry->isActive());
        self::assertSame(0, $entry->getPosition());
    }

    public function testAWordWithNoNoteKeepsNoneRatherThanAnEmptyString(): void
    {
        $entry = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Method, 'Wire snare', '   ');

        self::assertNull($entry->getNote());
    }

    public function testAnEmptyWordIsRefused(): void
    {
        $this->expectException(AreaListConflictException::class);
        $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, '   ');
    }

    public function testTheSameWordTwiceInOneListIsRefused(): void
    {
        $area = $this->anArea('Northern Reserve');
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');

        $this->expectException(AreaListConflictException::class);
        $this->lists()->add($area, AreaListEnum::Species, 'lion');
    }

    /** TWO LISTS ARE TWO VOCABULARIES: the same word may live in both. */
    public function testTheSameWordInTwoDifferentListsIsFine(): void
    {
        $area = $this->anArea('Northern Reserve');
        $place = $this->lists()->add($area, AreaListEnum::NamedPlace, 'Water body');
        $use = $this->lists()->add($area, AreaListEnum::LandUse, 'Water body');

        self::assertSame('water-body', $place->getKey());
        self::assertSame('water-body', $use->getKey());
        self::assertNotSame($place->getId(), $use->getId());
    }

    /** AND TWO AREAS ARE TWO VOCABULARIES: one area's animals are its own. */
    public function testTwoAreasMayEachHoldTheSameWord(): void
    {
        $north = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, 'Lion');
        $south = $this->lists()->add($this->anArea('Southern Reserve'), AreaListEnum::Species, 'Lion');

        self::assertSame('lion', $north->getKey());
        self::assertSame('lion', $south->getKey());
    }

    /** A word whose key is already taken gets the next one, and never the same. */
    public function testAKeyIsMadeUniqueInsideItsList(): void
    {
        $area = $this->anArea('Northern Reserve');
        $first = $this->lists()->add($area, AreaListEnum::NamedPlace, 'Lake Magadi');
        $second = $this->lists()->add($area, AreaListEnum::NamedPlace, 'Lake  Magadi!');

        self::assertSame('lake-magadi', $first->getKey());
        self::assertSame('lake-magadi-2', $second->getKey());
    }

    /** A word in a script with nothing ascii in it still gets a key to be pointed at by. */
    public function testAWordWithNoAsciiStillGetsAKey(): void
    {
        $entry = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, '简');

        self::assertSame('word', $entry->getKey());
    }

    /** THE ONE RULE A RENAME MUST OBEY: the identity records point at is untouched. */
    public function testARenameLeavesTheKeyAlone(): void
    {
        $entry = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, 'Spotted hyena');
        $this->lists()->rename($entry, 'Spotted hyaena');

        $this->em->clear();
        $stored = $this->em->find(AreaListEntry::class, $entry->getId());
        self::assertNotNull($stored);
        self::assertSame('spotted-hyena', $stored->getKey());
        self::assertSame('Spotted hyaena', $stored->getLabel());
    }

    public function testARenameOntoAnotherWordInTheSameListIsRefused(): void
    {
        $area = $this->anArea('Northern Reserve');
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $leopard = $this->lists()->add($area, AreaListEnum::Species, 'Leopard');

        $this->expectException(AreaListConflictException::class);
        $this->lists()->rename($leopard, 'Lion');
    }

    public function testRenamingAWordToWhatItAlreadySaysIsAllowed(): void
    {
        $entry = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, 'Lion');

        self::assertSame('Lion', $this->lists()->rename($entry, 'Lion')->getLabel());
    }

    public function testRetiringStampsADateAndReactivatingClearsIt(): void
    {
        $entry = $this->lists()->add($this->anArea('Northern Reserve'), AreaListEnum::Species, 'Serval');

        $this->lists()->retire($entry, new \DateTimeImmutable('2026-08-04 09:00'));
        $this->em->clear();
        $stored = $this->em->find(AreaListEntry::class, $entry->getId());
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive());
        self::assertSame('2026-08-04', $stored->getRetiredAt()?->format('Y-m-d'));

        $this->lists()->reactivate($stored);
        $this->em->clear();
        $again = $this->em->find(AreaListEntry::class, $entry->getId());
        self::assertNotNull($again);
        self::assertTrue($again->isActive());
        self::assertNull($again->getRetiredAt());
    }

    /** RETIRING NEVER DELETES. The row, the key and the word are all still there. */
    public function testRetiringKeepsTheRow(): void
    {
        $area = $this->anArea('Northern Reserve');
        $entry = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->lists()->retire($entry);
        $this->em->clear();

        $rows = $this->entries()->forAreaAndList($area, AreaListEnum::Species);
        self::assertCount(1, $rows, 'The editor dims a retired row; it does not hide it.');
        self::assertSame('serval', $rows[0]->getKey());
    }

    /** THE SERVICE HAS NO DELETE, because the screen has no delete control. */
    public function testThereIsNoWayToDeleteAWord(): void
    {
        $methods = get_class_methods(AreaListService::class);

        foreach (['delete', 'remove', 'purge', 'drop'] as $forbidden) {
            self::assertNotContains($forbidden, $methods);
        }
    }

    public function testWordsForAnAreaOfferOnlyItsLiveWords(): void
    {
        $area = $this->anArea('Northern Reserve');
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $serval = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->lists()->retire($serval);
        // Another area's animal must never reach this one's form.
        $this->lists()->add($this->anArea('Southern Reserve'), AreaListEnum::Species, 'Leopard');
        $this->em->clear();

        $words = $this->lists()->wordsFor($area);

        self::assertSame(['lion' => 'Lion'], $words->options(AreaListEnum::Species));
        self::assertSame('Serval', $words->label(AreaListEnum::Species, 'serval'));
    }

    public function testEachListIsOrderedByWhenItsWordsWereWritten(): void
    {
        $area = $this->anArea('Northern Reserve');
        foreach (['Wire snare', 'Foot snare', 'Gillnet'] as $word) {
            $this->lists()->add($area, AreaListEnum::Method, $word);
        }
        $this->em->clear();

        self::assertSame(
            ['Wire snare', 'Foot snare', 'Gillnet'],
            array_map(
                static fn (AreaListEntry $e): string => $e->getLabel(),
                $this->entries()->forAreaAndList($area, AreaListEnum::Method),
            ),
        );
    }
}
