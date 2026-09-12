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
use Uhifadhi\Incident\Entity\AreaListEntry;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Service\AreaListService;

/**
 * THE LISTS SECTION, OVER HTTP — the four folds, the rows, the add panel, the
 * three row states, and the `incidents.manage` gate on every write.
 */
final class AreaListsPageTest extends FunctionalTestCase
{
    private function url(AreaOfInterest $area, string $list = ''): string
    {
        return \sprintf('/areas/%s/modules/incidents/lists', $this->uuidOf($area))
            .('' === $list ? '' : '?list='.$list);
    }

    private function lists(): AreaListService
    {
        /** @var AreaListService $service */
        $service = static::getContainer()->get('test_public.incident.area_lists');

        return $service;
    }

    /** @return list<AreaListEntry> */
    private function stored(AreaOfInterest $area, AreaListEnum $list): array
    {
        /** @var list<AreaListEntry> $rows */
        $rows = $this->em->getRepository(AreaListEntry::class)
            ->findBy(['area' => $area, 'list' => $list->value], ['position' => 'ASC']);

        return $rows;
    }

    // ── the screen ────────────────────────────────────────────────────────────

    public function testItDrawsFourFoldsWithTheFirstOpen(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.tx-lists > .fold'));
        self::assertCount(3, $crawler->filter('.tx-lists > .fold.shut'));

        $names = $crawler->filter('.fold > .sum > b')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['Species', 'Method', 'Land use', 'Named places'], $names);

        $states = $crawler->filter('.fold > .sum > .fstate')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['open', 'closed', 'closed', 'closed'], $states);
    }

    public function testTheOpenFoldIsTheOneTheUrlNames(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area, 'land-use'));

        self::assertResponseIsSuccessful();
        $states = $crawler->filter('.fold > .sum > .fstate')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['closed', 'closed', 'open', 'closed'], $states);
    }

    /**
     * THE LINE IS DERIVED, and this is the assertion that says so: it names the
     * block, the question and this area's own kinds, and no template types any of
     * the three.
     */
    public function testEachFoldSaysWhoReadsItsList(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $used = $this->client->request('GET', $this->url($area))->filter('.fold > .bd > .use')->first()->text();

        self::assertStringContainsString('used by', $used);
        self::assertStringContainsString('Species', $used);
        self::assertStringContainsString('sub-categories', $used);
        self::assertStringContainsString('gates filing', $used);
        self::assertStringContainsString("— pick from the area's species list —", $used);
    }

    /** The land use does not gate a filing, and its line says exactly that. */
    public function testTheLandUseLineSaysItDoesNotGateAFiling(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $used = $this->client->request('GET', $this->url($area))->filter('.fold > .bd > .use')->eq(2)->text();

        self::assertStringContainsString('does not gate filing', $used);
    }

    /** THE POINT IS SAID ONCE FOR THE WHOLE LIST, never on a row, and is no control. */
    public function testThePointOnANamedPlaceIsSaidOnceAndIsNotAControl(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::NamedPlace, 'Lake Magadi');
        $this->lists()->add($area, AreaListEnum::NamedPlace, 'Munge Stream');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area, 'named-place'));
        $fold = $crawler->filter('.tx-lists > .fold')->eq(3);

        self::assertSame(1, substr_count($fold->text(), 'A point comes later'));
        self::assertCount(0, $fold->filter('.srow .spoint'), 'A point is a fact here, not a row control.');
    }

    public function testAnEmptyListSaysWhatTheFormWillDo(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertCount(4, $crawler->filter('.fold .sout'));
        self::assertStringContainsString(
            'No entries yet — the form offers only “other” until this list has words.',
            $crawler->filter('.fold .sout > .bd')->first()->text(),
        );
        self::assertSame('empty', trim($crawler->filter('.fold > .sum > .n')->first()->text()));
    }

    public function testEveryFoldCarriesItsOwnAddPanel(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area));

        $headings = $crawler->filter('.saddcard > .hd')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['Add a species', 'Add a method', 'Add a land use', 'Add a named place'], $headings);

        $acts = $crawler->filter('.saddcard .sadd')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['+ Add species', '+ Add method', '+ Add land use', '+ Add place'], $acts);
    }

    /** A row is the word, its note, its two figures and its actions — and nothing else. */
    public function testARowCarriesTheWordItsNoteAndItsFigures(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'African elephant', 'Loxodonta africana');
        $this->client->loginUser($this->aManager());

        $row = $this->client->request('GET', $this->url($area))->filter('.fold .srow')->first();

        self::assertSame('African elephant', trim($row->filter('.nm')->text()));
        self::assertSame('Loxodonta africana', trim($row->filter('.note')->text()));
        self::assertSame('0 this month · 0 all time', trim($row->filter('.n')->text()));
        self::assertSame(['Rename', 'Retire'], $row->filter('.acts .sact, .acts a')->each(static fn ($n): string => trim($n->text())));
    }

    /** NO DELETE CONTROL ANYWHERE ON THE PAGE. */
    public function testNothingOnThePageDeletesAWord(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->url($area))->html();

        self::assertStringNotContainsStringIgnoringCase('>Delete<', $html);
        self::assertStringNotContainsString('/delete', $html);
    }

    public function testThePageExplainsRetiringAndWhoseListsTheseAre(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $text = $this->client->request('GET', $this->url($area))->filter('.tx-lists')->text();

        self::assertStringContainsString('Retired leaves the picker and stays on the record.', $text);
        self::assertStringContainsString('There is no delete control on this page', $text);
        self::assertStringContainsString('Whose lists these are.', $text);
        self::assertStringContainsString('There are exactly four lists, and an area cannot add a fifth.', $text);
        self::assertStringContainsString('These lists are '.$area->getName().'’s own.', $text);
    }

    /** No workshop index chip reaches a reader, whatever the design draws in its own frame. */
    public function testTheRetireCardCarriesNoWorkshopIndex(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertCount(0, $crawler->filter('.tx-lists .idx'));
    }

    // ── writing ───────────────────────────────────────────────────────────────

    public function testAWordIsAdded(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($area))->html());

        $this->client->request('POST', $this->url($area).'/species/entries', [
            '_token' => $token,
            'label' => 'African elephant',
            'note' => 'Loxodonta africana',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $rows = $this->stored($area, AreaListEnum::Species);
        self::assertCount(1, $rows);
        self::assertSame('african-elephant', $rows[0]->getKey());
        self::assertSame('Loxodonta africana', $rows[0]->getNote());

        // And the redirect comes back to the fold it happened in.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('list=species', (string) $this->client->getRequest()->getRequestUri());
    }

    public function testADuplicateWordIsRefusedAndSaysWhy(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($area))->html());

        $this->client->request('POST', $this->url($area).'/species/entries', ['_token' => $token, 'label' => 'lion']);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(1, $this->stored($area, AreaListEnum::Species));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already has "lion"', $crawler->html());
    }

    /** RENAMING IS INLINE, and the field stands where the label stood. */
    public function testRenamingOpensAFieldInTheLabelsOwnPlace(): void
    {
        $area = $this->anAreaWithKinds();
        $entry = $this->lists()->add($area, AreaListEnum::Species, 'Spotted hyena');
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request(
            'GET',
            $this->url($area, 'species').'&rename='.$entry->getUuid()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.fold form.srow')->first();
        self::assertSame('Spotted hyena', $row->filter('input.sren')->attr('value'));
        self::assertSame(['Save', 'Cancel'], $row->filter('.acts .sact, .acts a')->each(static fn ($n): string => trim($n->text())));
        self::assertCount(0, $row->filter('.nm'), 'The label is the field while it is being corrected.');
    }

    public function testARenameKeepsTheKeyTheRecordsPointAt(): void
    {
        $area = $this->anAreaWithKinds();
        $entry = $this->lists()->add($area, AreaListEnum::Species, 'Spotted hyena');
        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($area))->html());

        $this->client->request(
            'POST',
            $this->url($area).'/entries/'.$entry->getUuid()->toRfc4122().'/rename',
            ['_token' => $token, 'label' => 'Spotted hyaena'],
        );

        self::assertResponseRedirects();
        $this->em->clear();
        $rows = $this->stored($area, AreaListEnum::Species);
        self::assertSame('Spotted hyaena', $rows[0]->getLabel());
        self::assertSame('spotted-hyena', $rows[0]->getKey());
    }

    public function testRetiringDimsTheRowDatesItAndLeavesOnlyReactivate(): void
    {
        $area = $this->anAreaWithKinds();
        $entry = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($area))->html());

        $this->client->request(
            'POST',
            $this->url($area).'/entries/'.$entry->getUuid()->toRfc4122().'/retire',
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $row = $crawler->filter('.fold .srow')->first();
        self::assertStringContainsString('gone', (string) $row->attr('class'));
        self::assertStringContainsString('retired', trim($row->filter('.chip.idle')->text()));
        self::assertSame(['Reactivate'], $row->filter('.acts .sact, .acts a')->each(static fn ($n): string => trim($n->text())));

        // The row is still there, and so is the word.
        $this->em->clear();
        self::assertCount(1, $this->stored($area, AreaListEnum::Species));
    }

    public function testReactivatingBringsAWordBack(): void
    {
        $area = $this->anAreaWithKinds();
        $entry = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->lists()->retire($entry);
        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($area))->html());

        $this->client->request(
            'POST',
            $this->url($area).'/entries/'.$entry->getUuid()->toRfc4122().'/reactivate',
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->stored($area, AreaListEnum::Species)[0]->isActive());
    }

    // ── the gate ──────────────────────────────────────────────────────────────

    public function testSomebodyWhoMayOnlyFileCannotOpenTheEditor(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->url($area));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSomebodyWhoMayOnlyFileCannotAddAWord(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($area))->html());

        $this->client->loginUser($this->aReporter());
        $this->client->request('POST', $this->url($area).'/species/entries', ['_token' => $token, 'label' => 'Lion']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(0, $this->stored($area, AreaListEnum::Species));
    }

    public function testAWriteWithoutTheTokenIsRefused(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->url($area).'/species/entries', ['label' => 'Lion']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(0, $this->stored($area, AreaListEnum::Species));
    }

    // ── area scope ────────────────────────────────────────────────────────────

    public function testAnotherAreasWordCannotBeReachedThroughThisArea(): void
    {
        $northern = $this->anAreaWithKinds('Northern Reserve');
        $southern = $this->anAreaWithKinds('Southern Reserve');
        $entry = $this->lists()->add($southern, AreaListEnum::Species, 'Lion');

        $this->client->loginUser($this->aManager());
        $token = $this->tokenFrom($this->client->request('GET', $this->url($northern))->html());

        $this->client->request(
            'POST',
            $this->url($northern).'/entries/'.$entry->getUuid()->toRfc4122().'/retire',
            ['_token' => $token],
        );

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertTrue($this->stored($southern, AreaListEnum::Species)[0]->isActive());
    }

    public function testOneAreasWordsAreNotShownOnAnother(): void
    {
        $northern = $this->anAreaWithKinds('Northern Reserve');
        $southern = $this->anAreaWithKinds('Southern Reserve');
        $this->lists()->add($southern, AreaListEnum::Species, 'Leopard');
        $this->client->loginUser($this->aManager());

        $html = $this->client->request('GET', $this->url($northern))->html();

        self::assertStringNotContainsString('Leopard', $html);
    }

    /** Where the area is not running this module, the page is not withheld — it is not there. */
    public function testTheEditorIs404WhereTheAreaDoesNotRunTheModule(): void
    {
        $area = $this->anAreaWithoutTheModule();
        $this->client->loginUser($this->aManager());

        $this->client->request('GET', $this->url($area));

        self::assertResponseStatusCodeSame(404);
    }

    // ── the strip ─────────────────────────────────────────────────────────────

    /** THE SECTION IS IN THE STRIP, in the ruled order, and lit on its own screen. */
    public function testTheConfigureStripCarriesListsBetweenTheKindsAndTheSettings(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aManager());

        $crawler = $this->client->request('GET', $this->url($area));

        $strip = $crawler->filter('.atabs a')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['Widget library', 'Incident kinds', 'Lists', 'Settings'], $strip);
        self::assertStringContainsString('on', (string) $crawler->filter('.atabs a')->eq(2)->attr('class'));
    }
}
