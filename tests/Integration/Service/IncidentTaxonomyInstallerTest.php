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

use Uhifadhi\Incident\Entity\IncidentCategory;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE ONE HOST INSTALL STEP. Without a taxonomy there is nothing to file an
 * incident against, so this has to work on a bare database, has to be runnable
 * again tomorrow, and must never delete a category somebody's case files are
 * filed under.
 */
final class IncidentTaxonomyInstallerTest extends IntegrationTestCase
{
    private function installer(): IncidentTaxonomyInstaller
    {
        /** @var IncidentTaxonomyInstaller $installer */
        $installer = $this->service('incident.taxonomy_installer');

        return $installer;
    }

    public function testItInstallsTheDesignsFourKindsAndSixteenSubcategories(): void
    {
        $tally = $this->installer()->install();

        self::assertSame(4, $tally['categories_created']);
        self::assertSame(16, $tally['subcategories_created']);

        /** @var IncidentCategoryRepository $categories */
        $categories = static::getContainer()->get(IncidentCategoryRepository::class);
        self::assertSame(
            ['poaching', 'conflict', 'compliance', 'mortality'],
            array_map(static fn (IncidentCategory $c) => $c->getSlug(), $categories->allInOrder()),
        );
    }

    /** Running it again changes nothing — a host may re-run it after any edit. */
    public function testASecondRunCreatesNothing(): void
    {
        $this->installer()->install();
        $this->em->clear();
        $second = $this->installer()->install();

        self::assertSame(0, $second['categories_created']);
        self::assertSame(0, $second['subcategories_created']);
        self::assertSame(4, $second['categories_updated']);
        self::assertSame(16, $second['subcategories_updated']);
    }

    /**
     * THE ROADKILL RULING, in the database: ONE entry, and it carries a FINE. Not
     * a pair of linked incidents, one ecological and one for the driver.
     */
    public function testRoadkillIsOneEntryThatCanCarryAFine(): void
    {
        $this->installer()->install();

        $roadkill = $this->subcategory('roadkill');
        self::assertSame('mortality', $roadkill->getCategory()->getSlug());
        self::assertSame(MoneyDirectionEnum::Fine, $roadkill->getMoneyDirection());

        // And its neighbour under the same kind carries nothing at all, which is
        // why money is a sub-category's business and never a category's.
        self::assertNull($this->subcategory('natural-mortality')->getMoneyDirection());
        self::assertFalse($this->subcategory('natural-mortality')->carriesMoney());
    }

    /** Each kind promises its OWN term — one global SLA would be a lie about all of them. */
    public function testEachSubcategoryPromisesItsOwnTerm(): void
    {
        $this->installer()->install();

        self::assertSame(72, $this->subcategory('human-injury')->getTermHours());
        self::assertSame('72 h', $this->subcategory('human-injury')->termLabel());
        self::assertSame(336, $this->subcategory('unauthorized-construction')->getTermHours());
        self::assertSame('14 d', $this->subcategory('unauthorized-construction')->termLabel());
        self::assertSame(720, $this->subcategory('livestock-depredation')->getTermHours());
        self::assertSame('30 d', $this->subcategory('livestock-depredation')->termLabel());
    }

    /**
     * "Leads" is emphasis, and conflict sits in BOTH lenses — the design's
     * reference card prints "leads: Protection · Ecology" against that one row.
     */
    public function testConflictIsLedByBothDepartments(): void
    {
        $this->installer()->install();

        /** @var IncidentCategoryRepository $categories */
        $categories = static::getContainer()->get(IncidentCategoryRepository::class);
        $conflict = $categories->findOneBySlug('conflict');

        self::assertNotNull($conflict);
        self::assertCount(2, $conflict->getLeads());
        self::assertStringContainsString('·', $conflict->leadsLine());
    }

    /** Every sub-category arrives with the fields its own form asks for. */
    public function testEverySubcategoryBringsItsOwnFieldSet(): void
    {
        $this->installer()->install();

        $depredation = $this->subcategory('livestock-depredation');
        self::assertSame(
            ['species', 'livestock_lost', 'enclosure', 'household', 'retaliation_risk'],
            array_column($depredation->getFieldSet(), 'key'),
        );

        // A roadkill asks for something else entirely — one incident table, one
        // form component, a field set per sub-category.
        self::assertSame(
            ['species', 'sex', 'age_class', 'road_segment', 'carcass_disposition', 'vehicle'],
            array_column($this->subcategory('roadkill')->getFieldSet(), 'key'),
        );
    }

    /**
     * NON-DESTRUCTIVE. A category that has left the configuration is left alone,
     * never deleted: case files are filed against it, and retiring a kind of
     * incident is an admin decision with consequences.
     */
    public function testACategoryNobodyConfiguresAnyMoreIsLeftAlone(): void
    {
        $this->installer()->install();

        $local = new IncidentCategory('local-custom', 'Something this deployment added', 'comp');
        $this->em->persist($local);
        $this->em->persist(new IncidentSubcategory($local, 'local-thing', 'a local thing'));
        $this->em->flush();
        $this->em->clear();

        $this->installer()->install();

        self::assertNotNull($this->em->getRepository(IncidentCategory::class)->findOneBy(['slug' => 'local-custom']));
        self::assertNotNull($this->em->getRepository(IncidentSubcategory::class)->findOneBy(['slug' => 'local-thing']));
    }
}
