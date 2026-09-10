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

namespace Uhifadhi\Incident\Tests\Functional;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Service\TaxonomyAdminService;

/**
 * THE AREA-SCOPED TAXONOMY ADMIN, over HTTP. One route, two data conditions —
 * the empty first-run start and the populated two-pane manager — plus the ruled
 * invariants: area scope, deactivate-never-delete, per-area uniqueness, and the
 * "incidents.manage" gate on every write.
 */
final class TaxonomyAdminPageTest extends FunctionalTestCase
{
    private function taxonomyUrl(AreaOfInterest $area): string
    {
        return \sprintf('/areas/%s/modules/incidents/taxonomy', $this->uuidOf($area));
    }

    private function admin(): TaxonomyAdminService
    {
        /** @var TaxonomyAdminService $admin */
        $admin = static::getContainer()->get('test_public.incident.taxonomy_admin');

        return $admin;
    }

    private function kindCount(AreaOfInterest $area): int
    {
        return \count($this->em->getRepository(TaxonomyKind::class)->findBy(['area' => $area]));
    }

    // ── the empty start ──────────────────────────────────────────────────────

    public function testTheEmptyStartRendersTheGhostAndTheWriteFirstKindPath(): void
    {
        $area = $this->anArea('Southern Reserve');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->taxonomyUrl($area));

        self::assertResponseIsSuccessful();
        // The empty condition, not the manager.
        self::assertCount(1, $crawler->filter('.tx-empty'));
        self::assertCount(0, $crawler->filter('.tx-mgr'));
        // The inert ghost sketch of a taxonomy's shape.
        self::assertCount(1, $crawler->filter('.tx-sketch'));
        self::assertStringContainsString('Southern Reserve has no kinds of incident yet', $crawler->text());
        // One honest way in: a real form that writes the first kind.
        self::assertCount(1, $crawler->filter('.tx-empty form[action$="/taxonomy/kinds"]'));
    }

    /** The copy-from-area picker is deferred, so the empty start offers no such live control. */
    public function testTheEmptyStartDoesNotShipACopyFromAreaPicker(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->taxonomyUrl($area));

        self::assertCount(0, $crawler->filter('.tx-copy'));
        self::assertCount(0, $crawler->filter('.tx-area'));
    }

    // ── the manage gate ────────────────────────────────────────────────────────

    public function testManagingTheTaxonomyNeedsTheManagePermission(): void
    {
        $area = $this->anArea();
        // A reporter may file, and may NOT manage — the split the module rests on.
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->taxonomyUrl($area));

        self::assertResponseStatusCodeSame(403);
    }

    // ── the populated manager ────────────────────────────────────────────────

    public function testCreatingTheFirstKindMovesTheScreenToTheManager(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->taxonomyUrl($area))->html();
        $this->client->request('POST', $this->taxonomyUrl($area).'/kinds', [
            '_token' => $this->tokenFrom($html),
            'label' => 'Poaching & wildlife crime',
            'colour' => 'poach',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('.tx-mgr'));
        self::assertCount(0, $crawler->filter('.tx-empty'));
        self::assertStringContainsString('Poaching & wildlife crime', $crawler->filter('.tx-kinds')->text());
        // The wire-code chip the register and exports hold.
        self::assertStringContainsString('poaching-wildlife-crime', $crawler->filter('.tx-detail')->text());
    }

    public function testTheManagerRendersSubsTheirBlocksAndTheToggleEditor(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching', 'poach');
        $sub = $this->admin()->createSubcategory($kind, 'Snaring');
        $this->admin()->setBlocks($sub, [BehaviorBlockEnum::Species, BehaviorBlockEnum::Counts]);
        $this->client->loginUser($this->aManager());

        // The sub row and its two block chips.
        $crawler = $this->client->request('GET', $this->taxonomyUrl($area).'?kind='.$kind->getUuid()->toRfc4122());
        self::assertStringContainsString('Snaring', $crawler->filter('.tx-sub')->text());
        self::assertGreaterThanOrEqual(2, $crawler->filter('.tx-sub .tx-blk.on')->count());

        // Opening the block editor shows all twelve composable toggles.
        $editor = $this->client->request('GET', $this->taxonomyUrl($area).'?kind='.$kind->getUuid()->toRfc4122().'&blocks='.$sub->getUuid()->toRfc4122());
        self::assertCount(1, $editor->filter('.tx-blockedit'));
        self::assertCount(\count(BehaviorBlockEnum::cases()), $editor->filter('.tx-toggles .tx-tog input[type="checkbox"]'));
        // The two already-on blocks are checked.
        self::assertCount(2, $editor->filter('.tx-toggles input[checked]'));
    }

    public function testComposingBlocksThroughTheEditorPersists(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Conflict', 'hwc');
        $sub = $this->admin()->createSubcategory($kind, 'Livestock depredation');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->taxonomyUrl($area).'?kind='.$kind->getUuid()->toRfc4122().'&blocks='.$sub->getUuid()->toRfc4122())->html();
        $this->client->request('POST', $this->taxonomyUrl($area).'/subcategories/'.$sub->getUuid()->toRfc4122().'/blocks', [
            '_token' => $this->tokenFrom($html),
            'blocks' => ['species', 'money'],
            'money_direction' => 'compensation',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(TaxonomySubcategory::class)->findOneBy(['uuid' => $sub->getUuid()]);
        self::assertNotNull($stored);
        self::assertTrue($stored->hasBlock(BehaviorBlockEnum::Species));
        self::assertTrue($stored->carriesMoney());
        self::assertSame('compensation', $stored->getMoneyDirection()?->value);
    }

    // ── deactivate never deletes ─────────────────────────────────────────────

    public function testDeactivatingAKindDimsItInPlaceAndReactivateBringsItBack(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Fire', 'mort');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->taxonomyUrl($area))->html();
        $this->client->request('POST', $this->taxonomyUrl($area).'/kinds/'.$kind->getUuid()->toRfc4122().'/deactivate', [
            '_token' => $this->tokenFrom($html),
        ]);
        $crawler = $this->client->followRedirect();

        // Still there, dimmed — never hidden, never deleted.
        self::assertCount(1, $crawler->filter('.tx-kind.off'));
        self::assertSame(1, $this->kindCount($area));

        // And it reactivates in one click.
        $this->client->request('POST', $this->taxonomyUrl($area).'/kinds/'.$kind->getUuid()->toRfc4122().'/reactivate', [
            '_token' => $this->tokenFrom($crawler->html()),
        ]);
        $back = $this->client->followRedirect();
        self::assertCount(0, $back->filter('.tx-kind.off'));
    }

    /** There is no delete control anywhere on the page. */
    public function testThereIsNoDeleteControlAnywhere(): void
    {
        $area = $this->anArea();
        $kind = $this->admin()->createKind($area, 'Poaching', 'poach');
        $this->admin()->createSubcategory($kind, 'Snaring');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->taxonomyUrl($area))->html();

        self::assertStringNotContainsStringIgnoringCase('/delete', $html);
        self::assertStringNotContainsStringIgnoringCase('>Delete<', $html);
    }

    // ── area scope ───────────────────────────────────────────────────────────

    public function testOneAreasKindsNeverAppearInAnother(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $this->admin()->createKind($northern, 'Poaching', 'poach');
        $this->client->loginUser($this->aManager());

        // Southern Reserve sees its own (empty) list, not Northern Reserve's kind.
        $crawler = $this->client->request('GET', $this->taxonomyUrl($southern));
        self::assertCount(1, $crawler->filter('.tx-empty'));
        self::assertStringNotContainsString('Poaching', $crawler->filter('.tx-empty')->text());
    }

    /** A sub-category of another area is a 404 on this area's write route — no reach across. */
    public function testWritingToAnotherAreasRowIs404(): void
    {
        $northern = $this->anArea('Northern Reserve');
        $southern = $this->anArea('Southern Reserve');
        $kind = $this->admin()->createKind($northern, 'Poaching', 'poach');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->taxonomyUrl($southern))->html();
        // Northern Reserve's kind uuid, posted at Southern Reserve's URL.
        $this->client->request('POST', $this->taxonomyUrl($southern).'/kinds/'.$kind->getUuid()->toRfc4122().'/deactivate', [
            '_token' => $this->tokenFrom($html),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── per-area uniqueness, at the door ─────────────────────────────────────

    public function testADuplicateKindLabelIsRefusedWithoutCreatingASecondRow(): void
    {
        $area = $this->anArea();
        $this->admin()->createKind($area, 'Fire', 'mort');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->taxonomyUrl($area))->html();
        $this->client->request('POST', $this->taxonomyUrl($area).'/kinds', [
            '_token' => $this->tokenFrom($html),
            'label' => 'fire',
            'colour' => 'mort',
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        // The refusal is flashed, and no second row was written.
        self::assertStringContainsString('already has a kind called', $crawler->text());
        self::assertSame(1, $this->kindCount($area));
    }

    // ── CSRF ───────────────────────────────────────────────────────────────────

    public function testAWriteWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anArea();
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->taxonomyUrl($area).'/kinds', [
            'label' => 'Poaching',
            'colour' => 'poach',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->kindCount($area));
    }
}
