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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Storage\IncidentFileSource;

/**
 * WHAT A PIECE OF EVIDENCE LOOKS LIKE ON A PAGE OF THIS MODULE.
 *
 * A photograph's tile shows the ONE small picture the storage made for it and
 * opens the file; a photograph without one says which of the reasons applies, in
 * the storage's own word, and is deliberately not openable; a document keeps its
 * own glyph and says nothing about pictures, because there was never one to make.
 *
 * THE TILE IS NOT THIS MODULE'S MARKUP and is asserted here anyway. It comes from
 * `@UhifadhiStorage/upload/_tile.html.twig`, so that a file attached a second ago
 * and a file attached last season are the same box — but WHICH state each file is
 * in is this module's answer, given through {@see IncidentFileSource}, and a
 * mapping that mislabels a photograph shows a queued spinner over a picture that
 * has been sitting there for a month.
 */
#[CoversClass(IncidentFileSource::class)]
final class EvidenceTileTest extends FunctionalTestCase
{
    public function testAPhotographWithAThumbnailShowsItAndOpensTheFile(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->aPhotograph($incident, 'IMG_1204.jpg', thumb: true);
        $this->client->loginUser($this->aManager());

        $tile = $this->caseFile($area, $incident)->filter('.i-evgrid .upl-tile.done.shot');

        self::assertCount(1, $tile, 'a photograph with a picture is the flat photo tile');

        $picture = $tile->filter('.sh img');
        self::assertCount(1, $picture);
        self::assertSame('/storage/evidence/incident/photo.jpg.thumb.jpg', $picture->attr('src'));
        self::assertSame('IMG_1204.jpg', $picture->attr('alt'));
        self::assertSame('lazy', $picture->attr('loading'));

        // Only a made thumbnail links, and it links at the file's own page.
        self::assertSame('/files/f/incident/photo.jpg', $tile->filter('.sh')->attr('href'));
        self::assertSame('IMG_1204.jpg', $tile->filter('.fn')->text());
        self::assertCount(1, $tile->filter('.rm'), 'evidence on a case file can still be taken off it');
    }

    public function testAPhotographWithoutOneSaysSoAndDoesNotOpen(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->aPhotograph($incident, 'IMG_1206.jpg', thumb: false);
        $this->client->loginUser($this->aManager());

        $tile = $this->caseFile($area, $incident)->filter('.i-evgrid .upl-tile.done.making');

        self::assertCount(1, $tile, 'a photograph still without a picture wears the waiting shape');
        self::assertSame('making', $tile->filter('.th')->text(), "the storage's own word for the state");
        self::assertCount(0, $tile->filter('img'));
        self::assertCount(0, $tile->filter('a'), 'a tile with nothing to look at is not openable');
    }

    public function testADocumentKeepsItsOwnTileAndSaysNothingAboutPictures(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->aDocumentOn($incident);
        $this->client->loginUser($this->aManager());

        $tile = $this->caseFile($area, $incident)->filter('.i-evgrid .upl-tile.done.doc');

        self::assertCount(1, $tile);
        self::assertCount(0, $tile->filter('.th'), 'there was never a picture to make');
        self::assertSame('claim_form_signed.pdf', $tile->filter('.fn')->text());
    }

    /**
     * THE MODULE DRAWS NO TILE OF ITS OWN ANY MORE. One box for a kept file, from
     * one template — so a camera glyph typed into an incidents template is the
     * drift this test exists to catch.
     */
    public function testNoTemplateDrawsATileOfItsOwn(): void
    {
        $offenders = [];
        foreach (glob(\dirname(__DIR__, 2).'/templates/{,*/,*/*/}*.twig', \GLOB_BRACE) ?: [] as $file) {
            $markup = (string) file_get_contents($file);
            if (str_contains($markup, 'upl-tile') || str_contains($markup, 'i-ph')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, "These draw an evidence tile themselves: the tile is the storage's.");
    }

    /**
     * THE DASHBOARD'S LATEST EVIDENCE IS THE SAME TILE, in the shape a read-only
     * listing wants: no removal, so the whole cell is the link — and it opens the
     * CASE, because a tile there is there to say which incident this turned up on.
     */
    public function testTheLatestEvidenceWidgetDrawsTheSameTileLinkedToTheCase(): void
    {
        $area = $this->anAreaWithKinds();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->aPhotograph($incident, 'IMG_1204.jpg', thumb: true);
        $this->aDocumentOn($incident);
        $this->client->loginUser($this->aReporter());

        $widget = $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/widgets',
            $this->uuidOf($area),
        ))->filter('[data-w="evidence"]');

        self::assertGreaterThan(0, $widget->count());
        $widget = $widget->first();

        $shot = $widget->filter('.i-evgrid a.upl-tile.done.shot');
        self::assertCount(1, $shot, 'with nothing to remove, the whole cell is the link');
        self::assertSame(\sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ), $shot->attr('href'));
        self::assertSame('/storage/evidence/incident/photo.jpg.thumb.jpg', $shot->filter('.sh img')->attr('src'));
        // The case it turned up on leads the label: a tile on a dashboard is
        // answering "where did this come from".
        self::assertSame($incident->getReference().' · IMG_1204.jpg', $shot->filter('.fn')->text());
        self::assertCount(0, $shot->filter('.rm'), 'nothing is removed from a dashboard');

        $doc = $widget->filter('.i-evgrid .upl-tile.done.doc');
        self::assertCount(1, $doc);
        self::assertSame($incident->getReference().' · claim_form_signed.pdf', $doc->filter('.fn')->text());
    }

    private function aPhotograph(Incident $incident, string $filename, bool $thumb): IncidentEvidence
    {
        $evidence = new IncidentEvidence($incident, EvidenceKindEnum::Photo, $filename)
            ->setPath('incident/photo.jpg')
            ->setMimeType('image/jpeg')
            ->setByteSize(2048)
            ->setCapturedAt(new \DateTimeImmutable('-1 hour'))
            ->setThumbKey($thumb ? 'incident/photo.jpg.thumb.jpg' : null);

        $this->em->persist($evidence);
        $this->em->flush();

        return $evidence;
    }

    private function aDocumentOn(Incident $incident): IncidentEvidence
    {
        $evidence = new IncidentEvidence($incident, EvidenceKindEnum::Document, 'claim_form_signed.pdf')
            ->setPath('incident/claim.pdf')
            ->setMimeType('application/pdf')
            ->setByteSize(4096);

        $this->em->persist($evidence);
        $this->em->flush();

        return $evidence;
    }

    private function caseFile(AreaOfInterest $area, Incident $incident): Crawler
    {
        return $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));
    }
}
