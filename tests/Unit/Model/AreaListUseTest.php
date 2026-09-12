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
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Model\AreaListUse;

/**
 * WHO READS A LIST, DERIVED — no database, no page, no kernel.
 *
 * The point of every assertion below is that NOTHING here is written down: the
 * block and the question come from the catalogue, and the kinds and
 * sub-categories from the taxonomy handed in. A line that was hardcoded would
 * pass this suite on one area and lie about every other.
 */
final class AreaListUseTest extends TestCase
{
    public function testEveryListIsReadByExactlyTheQuestionTheCatalogueSaysReadsIt(): void
    {
        $uses = AreaListUse::all([]);

        self::assertSame(
            ['species', 'method', 'land-use', 'named-place'],
            array_keys($uses),
            'The four lists are the four list questions, in the enum\'s order.',
        );

        self::assertSame('species', $uses['species']->blockValue);
        self::assertSame('Species', $uses['species']->questionLabel);
        self::assertSame('species', $uses['species']->questionKey);

        self::assertSame('method', $uses['method']->blockValue);
        self::assertSame('Method', $uses['method']->questionLabel);

        // The land use is asked by the EXTENT block, which is the one pairing
        // easy to get wrong from the list's name alone.
        self::assertSame('extent', $uses['land-use']->blockValue);
        self::assertSame('land_use', $uses['land-use']->questionKey);

        self::assertSame('named-place', $uses['named-place']->blockValue);
        self::assertSame('Which one', $uses['named-place']->questionLabel);
        self::assertSame('place_name', $uses['named-place']->questionKey);
    }

    /** Three of the four hold up a filing; the land use does not, and the line says so. */
    public function testWhetherTheAnswerGatesAFilingIsTheCataloguesAnswer(): void
    {
        $uses = AreaListUse::all([]);

        self::assertTrue($uses['species']->gates);
        self::assertTrue($uses['method']->gates);
        self::assertTrue($uses['named-place']->gates);
        self::assertFalse($uses['land-use']->gates, 'The measures beside it are what the Extent block needs.');
    }

    public function testAnAreaWithNoKindsHasNobodyReadingAnyList(): void
    {
        foreach (AreaListUse::all([]) as $use) {
            self::assertSame([], $use->kinds);
            self::assertSame(0, $use->subcategoryCount());
        }
    }

    /** Only the sub-categories that switched the block on, and only the live ones. */
    public function testItCountsTheSubcategoriesThatSwitchedTheBlockOn(): void
    {
        $area = new AreaOfInterest();
        $poaching = new TaxonomyKind($area, 'poaching', 'Poaching', 'poach');
        $snaring = self::sub($poaching, 'snaring', 'snaring', [BehaviorBlockEnum::Species, BehaviorBlockEnum::Method]);
        self::sub($poaching, 'bushmeat', 'bushmeat', [BehaviorBlockEnum::Species]);
        // A word that asks for neither.
        self::sub($poaching, 'paperwork', 'paperwork', [BehaviorBlockEnum::Notice]);

        $uses = AreaListUse::all([$poaching]);

        self::assertSame(['Poaching' => ['snaring', 'bushmeat']], $uses['species']->kinds);
        self::assertSame(2, $uses['species']->subcategoryCount());
        self::assertSame(['Poaching' => ['snaring']], $uses['method']->kinds);
        self::assertSame(1, $uses['method']->subcategoryCount());
        self::assertSame([], $uses['land-use']->kinds);

        // Retire the one that asks for a method and it stops asking.
        $snaring->deactivate();
        self::assertSame([], AreaListUse::all([$poaching])['method']->kinds);
    }

    /** A retired KIND takes its whole list of words' readers with it. */
    public function testARetiredKindReadsNothing(): void
    {
        $area = new AreaOfInterest();
        $kind = new TaxonomyKind($area, 'mortality', 'Mortality', 'mort');
        self::sub($kind, 'roadkill', 'roadkill', [BehaviorBlockEnum::Species]);
        $kind->deactivate();

        self::assertSame([], AreaListUse::all([$kind])['species']->kinds);
    }

    /**
     * "all 4" rather than four names, where every live word of a kind asks — the
     * design's own abbreviation, and the only place the line stops naming rows.
     */
    public function testAKindWhoseEveryWordAsksIsAbbreviated(): void
    {
        $area = new AreaOfInterest();
        $kind = new TaxonomyKind($area, 'mortality', 'Mortality', 'mort');
        foreach (['roadkill', 'poisoning', 'natural', 'unknown'] as $code) {
            self::sub($kind, $code, $code, [BehaviorBlockEnum::Species]);
        }

        self::assertSame('all 4', AreaListUse::all([$kind])['species']->parenthetical('Mortality'));
    }

    /** Two words out of three are named, because naming two is shorter than "all". */
    public function testAKindWhereOnlySomeWordsAskNamesThem(): void
    {
        $area = new AreaOfInterest();
        $kind = new TaxonomyKind($area, 'conflict', 'Conflict', 'hwc');
        self::sub($kind, 'depredation', 'depredation', [BehaviorBlockEnum::Species]);
        self::sub($kind, 'injury', 'human injury', [BehaviorBlockEnum::Species]);
        self::sub($kind, 'damage', 'property damage', [BehaviorBlockEnum::Extent]);

        $uses = AreaListUse::all([$kind]);
        self::assertSame('depredation, human injury', $uses['species']->parenthetical('Conflict'));
        self::assertSame('property damage', $uses['land-use']->parenthetical('Conflict'));
    }

    /** The block label is the block's own word, so a line never invents one. */
    public function testTheBlockLabelIsTheBlocksOwn(): void
    {
        $uses = AreaListUse::all([]);

        self::assertSame(BehaviorBlockEnum::Method->label(), $uses['method']->blockLabel);
        self::assertSame(AreaListEnum::Method, $uses['method']->list);
    }

    /** @param list<BehaviorBlockEnum> $blocks */
    private static function sub(TaxonomyKind $kind, string $code, string $label, array $blocks): TaxonomySubcategory
    {
        $subcategory = new TaxonomySubcategory($kind, $code, $label);
        $subcategory->setBlocks($blocks);
        $kind->addSubcategory($subcategory);

        return $subcategory;
    }
}
