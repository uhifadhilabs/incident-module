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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Upload\IncidentEvidenceTarget;

/**
 * ATTACHING EVIDENCE TO A CASE FILE — through the platform's one upload
 * component, over real HTTP.
 *
 * WHAT THIS MODULE HAD TO WRITE TO GET THIS: one class and one Twig line. No
 * controller, no route, no JavaScript, no stylesheet. So what is asserted here
 * is almost entirely the ANSWERS this module gives — which case, who may, what
 * the file becomes, and what the record says afterwards — because the questions
 * and the drawing are storage's and are proved in storage's own suite.
 *
 * THE BYTES ARE SYNTHETIC AND THE FIXTURE IS A REAL FILE. A photograph attached
 * to a case file is validated from its BYTES, so a suite that posted a made-up
 * string would be exercising the refusal path and calling it the happy one.
 */
#[CoversClass(IncidentEvidenceTarget::class)]
final class CaseFileEvidenceUploadTest extends FunctionalTestCase
{
    public function testTheCaseFileDrawsTheAddTileInTheEvidenceGrid(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aManager());

        $crawler = $this->caseFile($area, $incident);

        self::assertResponseIsSuccessful();
        $tile = $crawler->filter('.i-evgrid .upl-tile.idle');

        self::assertCount(1, $tile, 'the add tile is one cell of the grid it belongs to');
        self::assertSame('incident:'.$incident->getUuid()->toRfc4122(), $tile->attr('data-upl-target'));
        self::assertSame('uhifadhi--storage-module--upload', $tile->attr('data-controller'));
        self::assertSame('Add evidence', $tile->filter('.lbl')->text());
    }

    public function testAPhotographIsAttachedToTheCase(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aManager());

        $this->upload($area, $incident, 'IMG_1204.png');

        self::assertResponseIsSuccessful();
        $body = $this->json();

        self::assertSame('IMG_1204.png', $body['label']);
        // The chip's word: what THIS module made of the file.
        self::assertSame('evidence', $body['kind']);
        self::assertIsString($body['key']);
        self::assertStringStartsWith('incident/'.$incident->getUuid()->toRfc4122().'/', $body['key']);

        $evidence = $this->em->getRepository(IncidentEvidence::class)->findOneBy(['path' => $body['key']]);
        self::assertInstanceOf(IncidentEvidence::class, $evidence);
        self::assertSame('IMG_1204.png', $evidence->getFilename());
        self::assertSame('image/png', $evidence->getMimeType());
        self::assertGreaterThan(0, (int) $evidence->getByteSize());
    }

    /** Nothing happens on a case file without a line on its timeline saying so. */
    public function testAttachingWritesTheCaseAnEvidenceEvent(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aManager());

        $this->upload($area, $incident, 'IMG_1204.png');
        self::assertResponseIsSuccessful();

        self::assertContains(
            'Photograph attached: IMG_1204.png.',
            $this->timelineOf($incident),
        );
    }

    /**
     * A VIEWER WITHOUT "incidents.manage" MAY NOT. Evidence is what a claim rests
     * on, so putting something onto a case file is the same tier of decision as
     * moving it through its workflow — and the reporter, who may file, may not.
     */
    public function testSomebodyWithoutManageMayNotAttachAnything(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());

        // The token has to come from somebody the card was drawn for — the
        // reporter is never offered one, which is the assertion in the test
        // below. Handing theirs to the reporter is the strongest form of the
        // question: what stops them is the RECORD's answer, not a missing token.
        $this->client->loginUser($this->aManager());
        $token = $this->uploadToken($area, $incident);
        $this->client->loginUser($this->aReporter());

        $this->post($incident, 'IMG_1204.png', $token);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(
            'You may not attach a file to this record. Nothing was written.',
            $this->json()['error'],
        );
        self::assertSame([], $this->em->getRepository(IncidentEvidence::class)->findAll());
    }

    /** The add tile is not drawn for somebody who may not use it. */
    public function testTheCaseFileOffersNoAddTileToSomebodyWhoMayNotAttach(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aReporter());

        self::assertCount(0, $this->caseFile($area, $incident)->filter('.i-evgrid .upl-tile.idle'));
    }

    public function testEvidenceIsTakenBackOffTheCaseAndTheTrailSaysSo(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aManager());
        $this->upload($area, $incident, 'IMG_1204.png');
        $key = $this->json()['key'];
        self::assertIsString($key);

        $this->client->request('DELETE', '/files/'.$key, [], [], [
            'HTTP_X-CSRF-Token' => $this->uploadToken($area, $incident),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(IncidentEvidence::class)->findOneBy(['path' => $key]));
        self::assertContains('Photograph removed: IMG_1204.png.', $this->timelineOf($incident));
    }

    public function testSomebodyWithoutManageMayNotTakeEvidenceOff(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aManager());
        $this->upload($area, $incident, 'IMG_1204.png');
        $key = $this->json()['key'];
        self::assertIsString($key);
        $token = $this->uploadToken($area, $incident);

        $this->client->loginUser($this->aReporter());
        $this->client->request('DELETE', '/files/'.$key, [], [], ['HTTP_X-CSRF-Token' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->em->getRepository(IncidentEvidence::class)->findOneBy(['path' => $key]));
    }

    /** A tile the page drew itself carries the key its removal will name. */
    public function testAKeptTileCarriesAWorkingRemove(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, reportedBy: $this->aReporter());
        $this->client->loginUser($this->aManager());
        $this->upload($area, $incident, 'IMG_1204.png');
        $key = $this->json()['key'];

        $remove = $this->caseFile($area, $incident)->filter('.i-evgrid .upl-tile.done .rm');

        self::assertCount(1, $remove);
        self::assertSame($key, $remove->attr('data-upl-key'));
    }

    private function caseFile(AreaOfInterest $area, Incident $incident): Crawler
    {
        return $this->client->request('GET', \sprintf(
            '/areas/%s/modules/incidents/%s',
            $this->uuidOf($area),
            $incident->getReference(),
        ));
    }

    private function upload(AreaOfInterest $area, Incident $incident, string $name): void
    {
        $this->post($incident, $name, $this->uploadToken($area, $incident));
    }

    private function post(Incident $incident, string $name, string $token): void
    {
        $this->client->request(
            'POST',
            '/files/upload',
            ['target' => 'incident:'.$incident->getUuid()->toRfc4122()],
            ['file' => new UploadedFile(self::aPhotograph(), $name, test: true)],
            ['HTTP_X-CSRF-Token' => $token],
        );
    }

    /**
     * The token the COMPONENT minted, scraped off the case file. A token minted
     * from the manager here would prove only that the endpoint accepts this
     * suite's own arithmetic; reading it off the page is what proves the case
     * file carries one a browser could use.
     */
    private function uploadToken(AreaOfInterest $area, Incident $incident): string
    {
        $html = $this->caseFile($area, $incident)->html();
        preg_match('/data-upl-token="([^"]+)"/', $html, $matches);
        if (!isset($matches[1])) {
            self::fail('The case file drew no upload token, so its evidence card could never post one.');
        }

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function timelineOf(Incident $incident): array
    {
        $this->em->clear();
        $fresh = $this->em->getRepository(Incident::class)->find($incident->getId());
        self::assertInstanceOf(Incident::class, $fresh);

        $lines = [];
        foreach ($fresh->getEvents() as $event) {
            if (IncidentEventKindEnum::Evidence === $event->getKind()) {
                $lines[] = $event->getBody();
            }
        }

        return $lines;
    }

    /**
     * A REAL PNG ON DISK — one pixel, but genuinely decodable, so the storage
     * reads a real type off the bytes and genuinely makes a preview. Synthetic,
     * and at nobody's coordinates: what is asserted is the plumbing, not a
     * photograph.
     */
    private static function aPhotograph(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'incident-upload').'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '');

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
