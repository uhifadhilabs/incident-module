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

namespace Uhifadhi\Incident\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Model\AreaListWords;

/**
 * WHAT THE FORM OFFERS AND WHAT A RECORD SAYS — two questions, and the whole
 * reason this value object exists is that they have different answers.
 */
final class AreaListWordsTest extends TestCase
{
    public function testAnEmptyAreaOffersNothingAndInventsNothing(): void
    {
        $words = AreaListWords::none();

        foreach (AreaListEnum::cases() as $list) {
            self::assertSame([], $words->options($list));
        }
    }

    public function testTheFormIsOfferedTheLiveWordsKeyedByTheKeyTheRecordWillHold(): void
    {
        $words = $this->words();

        self::assertSame(
            ['african-elephant' => 'African elephant', 'lion' => 'Lion'],
            $words->options(AreaListEnum::Species),
            'A retired word is not offered, and the key is the option value.',
        );
    }

    /** THE PROMISE RETIRING MAKES: off the form, still on the record. */
    public function testARetiredWordStillResolvesToItsOwnLabel(): void
    {
        $words = $this->words();

        self::assertArrayNotHasKey('serval', $words->options(AreaListEnum::Species));
        self::assertSame('Serval', $words->label(AreaListEnum::Species, 'serval'));
    }

    public function testALiveWordResolvesToItsLabel(): void
    {
        self::assertSame('Lion', $this->words()->label(AreaListEnum::Species, 'lion'));
    }

    /**
     * A RECORD FILED BEFORE THE LIST HAD AN EDITOR holds the word a filer typed,
     * and the typed `other` name still arrives that way. Neither is a key of this
     * list, and neither may render as a dash or as somebody else's word.
     */
    public function testAnAnswerThatIsNotOneOfThisListsKeysIsItself(): void
    {
        $words = $this->words();

        self::assertSame('Spotted hyaena', $words->label(AreaListEnum::Species, 'Spotted hyaena'));
        self::assertSame('lion', $words->label(AreaListEnum::Method, 'lion'), 'A species key is not a method.');
    }

    private function words(): AreaListWords
    {
        $area = new AreaOfInterest();
        $serval = new AreaListEntry($area, AreaListEnum::Species, 'serval', 'Serval');
        $serval->retire(new \DateTimeImmutable('2026-08-04'));

        return AreaListWords::fromEntries([
            'species' => [
                new AreaListEntry($area, AreaListEnum::Species, 'african-elephant', 'African elephant'),
                new AreaListEntry($area, AreaListEnum::Species, 'lion', 'Lion'),
                $serval,
            ],
        ]);
    }
}
