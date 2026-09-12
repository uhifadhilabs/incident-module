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
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Service\AreaListService;

/**
 * WHAT THE REPORT FORM OFFERS FOR A LIST QUESTION, and what the case file says
 * afterwards — the two ends of the promise the Lists editor makes.
 *
 * A WORD IS OFFERED BY ITS KEY AND SHOWN BY ITS WORD, so the record keeps
 * something a rename cannot break; a retired word leaves the picker and stays
 * legible on every record filed under it; and `other` is still there when the
 * list is no help, with the typed name it always had.
 */
final class ReportFormAreaListsTest extends FunctionalTestCase
{
    private function reportUrl(AreaOfInterest $area): string
    {
        return \sprintf('/areas/%s/modules/incidents/new', $this->uuidOf($area));
    }

    private function createUrl(AreaOfInterest $area): string
    {
        return \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area));
    }

    private function lists(): AreaListService
    {
        /** @var AreaListService $service */
        $service = static::getContainer()->get('test_public.incident.area_lists');

        return $service;
    }

    /** @return array<string, mixed> */
    private static function theBlocksDepredationAsks(string $species): array
    {
        return [
            'blocks' => [
                'species' => ['species' => $species, 'sex' => 'unknown'],
                'counts' => ['rows' => [['quantity' => 'head of stock', 'how_many' => '4']]],
                'parties' => ['rows' => [['role' => 'claimant', 'name' => 'A stock owner']]],
                'money' => ['claimed' => '900000'],
            ],
        ];
    }

    /**
     * The options of the species select, as value => text.
     *
     * @return array<string, string>
     */
    private function speciesOptions(AreaOfInterest $area): array
    {
        $crawler = $this->client->request('GET', $this->reportUrl($area));
        self::assertResponseIsSuccessful();

        $options = [];
        foreach ($crawler->filter('select[name="blocks[species][species]"] option') as $option) {
            \assert($option instanceof \DOMElement);
            $options[$option->getAttribute('value')] = trim($option->textContent);
        }

        return $options;
    }

    // ── what the form offers ──────────────────────────────────────────────────

    public function testAnAreaWithNoWordsStillDrawsTheSelectWithItsPlaceholderAndOther(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        self::assertSame(
            ['' => "— pick from the area's species list —", 'other' => 'other — not on the list'],
            $this->speciesOptions($area),
            'An empty list is a legitimate state: the placeholder and the typed way out, and no invented choice.',
        );
    }

    public function testTheSelectOffersTheAreasLiveWordsKeyedByTheirStableKey(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'African elephant');
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $this->client->loginUser($this->aReporter());

        self::assertSame(
            [
                '' => "— pick from the area's species list —",
                'african-elephant' => 'African elephant',
                'lion' => 'Lion',
                'other' => 'other — not on the list',
            ],
            $this->speciesOptions($area),
        );
    }

    /** THE PROMISE RETIRING MAKES, at the picker's end. */
    public function testARetiredWordIsNotOffered(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $serval = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $this->lists()->retire($serval);
        $this->client->loginUser($this->aReporter());

        $options = $this->speciesOptions($area);
        self::assertArrayHasKey('lion', $options);
        self::assertArrayNotHasKey('serval', $options);
    }

    /** ONE AREA'S ANIMALS ARE ITS OWN, and never reach the neighbour's form. */
    public function testAnotherAreasWordsAreNotOffered(): void
    {
        $northern = $this->anAreaWithKinds('Northern Reserve');
        $southern = $this->anAreaWithKinds('Southern Reserve');
        $this->lists()->add($southern, AreaListEnum::Species, 'Leopard');
        $this->client->loginUser($this->aReporter());

        self::assertArrayNotHasKey('leopard', $this->speciesOptions($northern));
    }

    // ── what a filing stores ──────────────────────────────────────────────────

    public function testFilingStoresTheKeyTheOptionCarried(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'Lion');
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($area))->html();
        $this->client->request('POST', $this->createUrl($area), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => 'Lion killed four goats at Riverside',
            'lat' => '-3.21',
            'lng' => '-29.75',
        ] + self::theBlocksDepredationAsks('lion'));

        self::assertResponseRedirects();
        $this->em->clear();
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        self::assertNotNull($incident);
        self::assertSame('lion', $incident->getBlockAnswers()['species']['species'] ?? null);
    }

    /** `OTHER` IS STILL A WAY THROUGH, and it files. */
    public function testFilingWithOtherStillWorks(): void
    {
        $area = $this->anAreaWithKinds();
        $this->client->loginUser($this->aReporter());

        $html = $this->client->request('GET', $this->reportUrl($area))->html();
        $this->client->request('POST', $this->createUrl($area), [
            '_token' => $this->tokenFrom($html),
            'subcategory' => 'livestock-depredation',
            'title' => 'Something took four goats at Riverside',
            'lat' => '-3.21',
            'lng' => '-29.75',
        ] + self::theBlocksDepredationAsks('other'));

        self::assertResponseRedirects();
        $this->em->clear();
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        self::assertNotNull($incident);
        self::assertSame('other', $incident->getBlockAnswers()['species']['species'] ?? null);
    }

    // ── what the case file says ───────────────────────────────────────────────

    public function testTheCaseFilePrintsTheWordAndNotTheKey(): void
    {
        $area = $this->anAreaWithKinds();
        $this->lists()->add($area, AreaListEnum::Species, 'African elephant');
        $incident = $this->anIncidentAnsweringSpecies($area, 'african-elephant');
        $this->client->loginUser($this->aManager());

        $text = $this->caseFileSpeciesAnswer($area, $incident);

        self::assertSame('African elephant', $text);
    }

    /** THE OTHER END OF THE PROMISE: retired, and still legible on the record. */
    public function testTheCaseFileStillNamesARetiredWord(): void
    {
        $area = $this->anAreaWithKinds();
        $serval = $this->lists()->add($area, AreaListEnum::Species, 'Serval');
        $incident = $this->anIncidentAnsweringSpecies($area, 'serval');
        $this->lists()->retire($serval);
        $this->client->loginUser($this->aManager());

        self::assertSame('Serval', $this->caseFileSpeciesAnswer($area, $incident));
    }

    /**
     * A RECORD FILED BEFORE THE LIST HAD AN EDITOR holds the word a filer typed,
     * and it still reads as itself rather than as a dash or as somebody's key.
     */
    public function testTheCaseFilePrintsATypedAnswerAsItself(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncidentAnsweringSpecies($area, 'Spotted hyaena');
        $this->client->loginUser($this->aManager());

        self::assertSame('Spotted hyaena', $this->caseFileSpeciesAnswer($area, $incident));
    }

    private function anIncidentAnsweringSpecies(AreaOfInterest $area, string $answer): Incident
    {
        /** @var \Uhifadhi\Incident\Service\IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');

        return $reports->file(
            area: $area,
            subcategory: $this->subcategory($area, 'livestock-depredation'),
            title: 'Four goats taken at Riverside',
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
            now: new \DateTimeImmutable(),
            blockAnswers: new \Uhifadhi\Incident\Model\BlockAnswers(['species' => ['species' => $answer]]),
        );
    }

    /** What the case file prints under the Species block's own question. */
    private function caseFileSpeciesAnswer(AreaOfInterest $area, Incident $incident): string
    {
        $crawler = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));
        self::assertResponseIsSuccessful();

        foreach ($crawler->filter('.i-fieldset .i-fld') as $field) {
            $node = new \Symfony\Component\DomCrawler\Crawler($field);
            if ('Species' === trim($node->filter('label')->text())) {
                return trim($node->filter('span')->text());
            }
        }

        self::fail('The case file printed no Species answer at all.');
    }
}
