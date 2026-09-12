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

use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Exception\TaxonomyConflictException;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Service\TaxonomyAdminService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE AREA-SCOPED TAXONOMY, PROVEN AGAINST THE REAL DATABASE. The schema for the
 * two new tables is built by IntegrationTestCase from the entity metadata, so
 * every assertion below is also proof the columns persist — this module ships no
 * migrations, exactly as it ships none for its other entities.
 */
final class TaxonomyAdminServiceTest extends IntegrationTestCase
{
    private function admin(): TaxonomyAdminService
    {
        /** @var TaxonomyAdminService $admin */
        $admin = $this->service('incident.taxonomy_admin');

        return $admin;
    }

    private function kinds(): TaxonomyKindRepository
    {
        $repository = $this->em->getRepository(TaxonomyKind::class);
        self::assertInstanceOf(TaxonomyKindRepository::class, $repository);

        return $repository;
    }

    // ── area scope ─────────────────────────────────────────────────────────────

    /** Each area owns its own list; the two never merge, even with the same words. */
    public function testKindsAreScopedToTheirArea(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');

        $this->admin()->createKind($northern, 'Poaching & wildlife crime', 'poach');
        $this->admin()->createKind($southern, 'Fire', 'mort');

        $here = $this->kinds()->forArea($northern);
        $there = $this->kinds()->forArea($southern);

        self::assertCount(1, $here);
        self::assertCount(1, $there);
        self::assertSame('Poaching & wildlife crime', $here[0]->getLabel());
        self::assertSame('Fire', $there[0]->getLabel());
    }

    /** The same label is allowed in two different areas — it is unique per area only. */
    public function testTheSameLabelMayLiveInTwoAreas(): void
    {
        $a = $this->anArea('Area A');
        $b = $this->anArea('Area B');

        $this->admin()->createKind($a, 'Fire', 'mort');
        $this->admin()->createKind($b, 'Fire', 'mort');

        self::assertCount(1, $this->kinds()->forArea($a));
        self::assertCount(1, $this->kinds()->forArea($b));
    }

    /** An area starts empty — no seed, no starter pack. */
    public function testAnAreaStartsWithNoKinds(): void
    {
        $area = $this->anArea();

        self::assertFalse($this->kinds()->areaHasAny($area));
        self::assertSame([], $this->kinds()->forArea($area));
    }

    // ── wire-codes ─────────────────────────────────────────────────────────────

    /** A wire-code is derived from the label when none is given, and it is a slug. */
    public function testAWireCodeIsSluggedFromTheLabel(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Human–wildlife conflict', 'hwc');

        self::assertSame('human-wildlife-conflict', $kind->getCode());
    }

    /** Two labels that slug the same get distinct, area-unique codes. */
    public function testWireCodesAreMadeUniqueWithinTheArea(): void
    {
        $area = $this->anArea();
        $first = $this->admin()->createKind($area, 'Fire', 'mort');
        $second = $this->admin()->createKind($area, 'FIRE!', 'mort');

        self::assertSame('fire', $first->getCode());
        self::assertSame('fire-2', $second->getCode());
    }

    /** Renaming a kind never touches its wire-code — the whole point of a code. */
    public function testRenamingAKindKeepsItsWireCode(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching', 'poach');
        $code = $kind->getCode();

        $this->admin()->renameKind($kind, 'Poaching & wildlife crime');

        self::assertSame($code, $kind->getCode());
        self::assertSame('Poaching & wildlife crime', $kind->getLabel());
    }

    // ── uniqueness ─────────────────────────────────────────────────────────────

    public function testADuplicateKindLabelInTheSameAreaIsRefused(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Fire', 'mort');

        $this->expectException(TaxonomyConflictException::class);
        $this->admin()->createKind($area, 'fire', 'mort');
    }

    public function testADuplicateSubLabelUnderTheSameKindIsRefused(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching', 'poach');
        $this->admin()->createSubcategory($kind, 'Snaring');

        $this->expectException(TaxonomyConflictException::class);
        $this->admin()->createSubcategory($kind, 'snaring');
    }

    /** A sub label unique WITHIN ITS PARENT may repeat under a different kind. */
    public function testTheSameSubLabelMayLiveUnderTwoKinds(): void
    {
        $area = $this->anArea();
        $poaching = $this->admin()->createKind($area, 'Poaching', 'poach');
        $mortality = $this->admin()->createKind($area, 'Wildlife mortality', 'mort');

        $this->admin()->createSubcategory($poaching, 'Poisoning');
        $second = $this->admin()->createSubcategory($mortality, 'Poisoning');

        // Same label, different parent — allowed — but the wire-code is area-unique.
        $firstSub = $poaching->getSubcategories()->first();
        self::assertNotFalse($firstSub);
        self::assertSame('poisoning', $firstSub->getCode());
        self::assertSame('poisoning-2', $second->getCode());
    }

    // ── behaviour blocks compose ────────────────────────────────────────────────

    public function testBlocksComposeFreelyAndAreReplacedWhole(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching', 'poach');
        $sub = $this->admin()->createSubcategory($kind, 'Snaring');

        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Species, BehaviorBlockEnum::Counts, BehaviorBlockEnum::Parties]);
        self::assertTrue($sub->hasBlock(BehaviorBlockEnum::Species));
        self::assertTrue($sub->hasBlock(BehaviorBlockEnum::Counts));
        self::assertFalse($sub->hasBlock(BehaviorBlockEnum::Money));

        // Replaced whole, never merged.
        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Method]);
        self::assertFalse($sub->hasBlock(BehaviorBlockEnum::Species));
        self::assertTrue($sub->hasBlock(BehaviorBlockEnum::Method));
    }

    public function testMoneyBlockCarriesADirectionAndClearsWhenRemoved(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Conflict', 'hwc');
        $sub = $this->admin()->createSubcategory($kind, 'Livestock depredation');

        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Money], MoneyDirectionEnum::Compensation);
        self::assertTrue($sub->carriesMoney());
        self::assertSame(MoneyDirectionEnum::Compensation, $sub->getMoneyDirection());

        // Drop the money block and the direction cannot linger.
        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Counts], MoneyDirectionEnum::Fine);
        self::assertFalse($sub->carriesMoney());
        self::assertNull($sub->getMoneyDirection());
    }

    // ── what a word promises, and what it asks ───────────────────────────────────

    /**
     * THE TERM IS THIS WORD'S OWN. A human injury is 72 hours and a construction
     * notice is 14 days; one term for the whole area would be a lie about both,
     * which is why the ageing widget reads it off the row.
     */
    public function testTheTermIsThisWordsOwnAndCannotBeLessThanAnHour(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Compliance', 'comp');
        $notice = $this->admin()->createSubcategory($kind, 'Unauthorized construction');
        $injury = $this->admin()->createSubcategory($kind, 'Human injury');

        $this->admin()->setTermHours($notice, 336);
        $this->admin()->setTermHours($injury, 72);

        self::assertSame('14 d', $notice->termLabel());
        self::assertSame('72 h', $injury->termLabel());

        // Zero is not a promise, so it is clamped rather than stored.
        $this->admin()->setTermHours($injury, 0);
        self::assertSame(1, $injury->getTermHours());
    }

    /**
     * NOTHING IN THE ADMIN WRITES A LIST OF QUESTIONS. A word's questions are the
     * questions of the blocks it switches on, so the service that used to take a
     * typed field list no longer offers one — there is no form builder, and a
     * screen that could invent a field is a screen that could ask anything.
     */
    public function testTheAdminOffersNoWayToInventAQuestion(): void
    {
        self::assertNotContains(
            'setFieldSet',
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                new \ReflectionClass(TaxonomyAdminService::class)->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            'The retired field list has no door back in.',
        );
    }

    /**
     * THE LENS IS ORDERING, NOT ACCESS — and it is the AREA's, so two areas can
     * put the same kind in front of different departments.
     */
    public function testTheDepartmentsAKindLeadsWithAreTheAreasOwn(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Conflict', 'hwc');

        $this->admin()->setKindLeads($kind, ['Protection Service', '  ', 'Ecology & Wildlife Mgmt', 'Protection Service']);

        self::assertSame(['Protection Service', 'Ecology & Wildlife Mgmt'], $kind->getLeads());
        self::assertSame('Protection Service · Ecology & Wildlife Mgmt', $kind->leadsLine());
    }

    // ── deactivate hides but keeps ───────────────────────────────────────────────

    public function testDeactivatingAKindDimsItButKeepsItAndItsSubs(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Fire', 'mort');
        $sub = $this->admin()->createSubcategory($kind, 'Wildfire');

        $this->admin()->deactivateKind($kind);
        $this->admin()->deactivateSubcategory($sub);

        // Still returned by the area read — dimmed, not hidden.
        $kinds = $this->kinds()->forArea($area);
        self::assertCount(1, $kinds);
        self::assertFalse($kinds[0]->isActive());
        $storedSub = $kinds[0]->getSubcategories()->first();
        self::assertNotFalse($storedSub);
        self::assertFalse($storedSub->isActive());

        // …and reactivation returns it exactly as it was.
        $this->admin()->reactivateKind($kind);
        self::assertTrue($this->kinds()->forArea($area)[0]->isActive());
    }
}
