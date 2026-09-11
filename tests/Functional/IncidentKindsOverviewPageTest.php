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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\TaxonomyAdminService;

/**
 * THE KINDS THIS AREA FILES, AND WHAT HAS BEEN FILED AGAINST EACH WORD — every
 * kind down the left, one kind's numbers on the right, and nothing on the page
 * that writes.
 */
final class IncidentKindsOverviewPageTest extends FunctionalTestCase
{
    private function url(AreaOfInterest $area, string $kind = ''): string
    {
        return \sprintf('/areas/%s/modules/incidents/incident-kinds%s', $this->uuidOf($area), '' === $kind ? '' : '/'.$kind);
    }

    private function admin(): TaxonomyAdminService
    {
        /** @var TaxonomyAdminService $admin */
        $admin = static::getContainer()->get('test_public.incident.taxonomy_admin');

        return $admin;
    }

    /** The area's own vocabulary: two live kinds and one retired. */
    private function aTaxonomy(AreaOfInterest $area): TaxonomyKind
    {
        $conflict = $this->admin()->createKind($area, 'Human–wildlife conflict', 'hwc');
        $depredation = $this->admin()->createSubcategory($conflict, 'Livestock depredation');
        $this->admin()->setBlocks($depredation, [BehaviorBlockEnum::Money], MoneyDirectionEnum::Compensation);
        $this->admin()->createSubcategory($conflict, 'Crop raiding');

        $mortality = $this->admin()->createKind($area, 'Wildlife mortality', 'mort');
        $this->admin()->createSubcategory($mortality, 'Roadkill');

        $retired = $this->admin()->createKind($area, 'Fisheries', 'mort');
        $this->admin()->deactivateKind($retired);

        return $conflict;
    }

    public function testItListsEveryKindTheAreaFilesRetiredOnesDimmed(): void
    {
        $area = $this->anArea();
        $this->aTaxonomy($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Human–wildlife conflict', 'Wildlife mortality', 'Fisheries'],
            $crawler->filter('.kpane .krow .nm')->each(
                static fn (Crawler $nm): string => trim($nm->getNode(0)?->firstChild->textContent ?? ''),
            ),
        );
        self::assertSame(1, $crawler->filter('.kpane .krow.gone')->count());
        self::assertStringContainsString('fisheries', $crawler->filter('.kpane .krow.gone .nm em')->text());
    }

    public function testTheFirstKindOpensByDefault(): void
    {
        $area = $this->anArea();
        $this->aTaxonomy($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertSame(1, $crawler->filter('.kpane .krow.on')->count());
        self::assertStringContainsString('Human–wildlife conflict', $crawler->filter('.kpane .krow.on .nm')->text());
        self::assertSame(1, $crawler->filter('.kmatrix')->count());
        self::assertStringContainsString(
            'all of Human–wildlife conflict',
            $crawler->filter('.kmatrix tr.all td')->first()->text(),
        );
        self::assertSame(
            ['livestock depredation', 'crop raiding'],
            $crawler->filter('.kmatrix tr:not(.all) td:first-child')->each(
                static fn (Crawler $td): string => trim($td->getNode(0)?->firstChild->textContent ?? ''),
            ),
        );
    }

    public function testTheKindInTheAddressIsTheOneThatOpens(): void
    {
        $area = $this->anArea();
        $this->aTaxonomy($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area, 'wildlife-mortality'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Wildlife mortality', $crawler->filter('.kpane .krow.on .nm')->text());
        self::assertStringContainsString(
            'all of Wildlife mortality',
            $crawler->filter('.kmatrix tr.all td')->first()->text(),
        );
    }

    /**
     * The numbers are the incidents actually filed: a sub-category's key matched
     * against the filed sub-category's slug, and a kind nobody has filed against
     * counts zero.
     */
    public function testTheCountsAreTheIncidentsFiledAgainstTheKey(): void
    {
        $area = $this->anArea();
        $this->aTaxonomy($area);
        $this->anIncident($area, 'livestock-depredation', 'Lion killed four goats');
        $this->anIncident($area, 'livestock-depredation', 'Hyena took a calf');
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        $row = $crawler->filter('.kmatrix tr.all + tr');
        self::assertSame('2', trim($row->filter('td')->eq(1)->text()));
        self::assertSame('2', trim($row->filter('td')->eq(3)->text()));
        self::assertStringContainsString('compensation', $row->filter('td')->eq(4)->text());
        self::assertSame('2', trim($crawler->filter('.kmatrix tr.all td')->eq(1)->text()));
        self::assertStringContainsString('2', $crawler->filter('.kpane .krow.on .n')->first()->text());

        $empty = $this->client->request('GET', $this->url($area, 'wildlife-mortality'));
        self::assertSame('0', trim($empty->filter('.kmatrix tr.all td')->eq(1)->text()));
        self::assertSame('0', trim($empty->filter('.kmatrix tr.all td')->eq(3)->text()));
    }

    /** The shell draws the strip from this module's declaration, third tab lit. */
    public function testTheShellDrawsTheModulesThreeDataPlaces(): void
    {
        $area = $this->anArea();
        $this->aTaxonomy($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertSame(
            ['Overview', 'Incidents', 'Incident kinds'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())),
        );
        self::assertSame('Incident kinds', trim($crawler->filter('.atabs a.on')->text()));
    }

    /** Read-only: nothing posts, and the one way to edit leaves for Configure. */
    public function testThePageWritesNothing(): void
    {
        $area = $this->anArea();
        $this->aTaxonomy($area);
        $this->client->loginUser($this->aReporter());

        $crawler = $this->client->request('GET', $this->url($area));

        self::assertSame(0, $crawler->filter('.kpane form')->count());
        self::assertSame(0, $crawler->filter('.kpane button')->count());
        self::assertStringContainsString(
            \sprintf('/areas/%s/modules/incidents/kinds', $this->uuidOf($area)),
            (string) $crawler->filter('.kp-foot a.open-btn')->attr('href'),
        );
    }

    /** An area this module is not switched on for has no such page. */
    public function testTheModuleHasToBeSwitchedOnForTheArea(): void
    {
        $area = $this->anAreaWithoutTheModule();
        $this->client->loginUser($this->aReporter());

        $this->client->request('GET', $this->url($area));

        self::assertResponseStatusCodeSame(404);
    }
}
