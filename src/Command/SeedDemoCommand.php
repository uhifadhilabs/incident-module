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

namespace Uhifadhi\Incident\Command;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Area\Entity\Zone;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Repository\IncidentZoneLocator;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\ModuleContracts\Entity\UserInterface;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * SEEDS THE DESIGN'S SAMPLE MONTH — the forty-seven incidents the gallery talks
 * about, so a fresh host shows the module working and matches the spec.
 *
 * THE NUMBERS ARE THE DESIGN'S, TO THE SHILLING. The preset gallery states them
 * once and every widget repeats them: 47 filed, 31 still open, TZS 8.45M assessed
 * in fines and 9.2M approved in compensation, across four categories and seven
 * zones. {@see DemoMonth} is that month as data; this command writes it.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. Every incident is keyed by its reference, and
 * one that already exists is left exactly as it is — nothing is purged, renamed
 * or re-numbered, so running it twice is a no-op and running it after somebody
 * has worked the demo data does not undo their work.
 *
 * IT TAKES WHAT THE HOST ALREADY HAS. Zones are attached by NAME when the area
 * has one of that name and left null when it does not, because unzoned is a
 * first-class answer everywhere in this module. Recorders are drawn from the
 * accounts that exist; with none, the incidents are recorded by nobody and the
 * department KPI plates honestly report nothing.
 *
 * Dev-only: registered only where `incident.dev_tools` is on, so production never
 * gets a command that writes invented incidents.
 */
#[AsCommand(
    name: 'incidents:seed:demo',
    description: 'Seed the design’s sample month of incidents into one area (idempotent).',
)]
final class SeedDemoCommand extends Command
{
    /**
     * The first segment of every evidence key this seeder writes — the prefix
     * {@see \Uhifadhi\Incident\Storage\IncidentFileSource::PREFIX} claims, which
     * is what makes the incidents file source pick these rows up. Held as a
     * literal here rather than read off that class so the dev-only seeder does not
     * force storage-module to load merely to name one of its own keys.
     */
    private const string PREFIX = 'incident';

    private const int PHOTO_WIDTH = 480;
    private const int PHOTO_HEIGHT = 360;

    /** Photographs whose bytes were written this run — see {@see evidenceSummary()}. */
    private int $photosStored = 0;

    /** Signed documents whose bytes were written this run. */
    private int $documentsStored = 0;

    /**
     * @param EvidenceStorage|null    $evidence           the platform's evidence API, where uhifadhi/storage-module is installed —
     *                                                    photographs are stored through it exactly as a field upload is, so the
     *                                                    sample month's evidence carries a real size and a generated preview. Null
     *                                                    where storage is absent; the seeder then keys evidence without a blob,
     *                                                    which is the honest key-only row the Files hub already knows how to show.
     * @param FilesystemOperator|null $evidenceFilesystem the same private evidence storage, for the ONE thing store() will not do:
     *                                                    write a signed document. store() validates uploads against an image
     *                                                    allowlist, so a PDF is refused there by design; the document blob is
     *                                                    therefore written straight to the storage, the way the thumbnail backfill
     *                                                    writes a preview beside a key. Null where storage is absent.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentRepository $incidents,
        private readonly IncidentSubcategoryRepository $subcategories,
        private readonly IncidentTaxonomyInstaller $taxonomy,
        private readonly IncidentTransitionService $transitions,
        private readonly IncidentZoneLocator $zones,
        private readonly string $currency,
        private readonly ?EvidenceStorage $evidence = null,
        private readonly ?FilesystemOperator $evidenceFilesystem = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('area', InputArgument::OPTIONAL, 'The area’s name or UUID. Defaults to the only area, where there is only one.')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'The month to file them in, as YYYY-MM.', DemoMonth::MONTH)
            // The seeder is idempotent by reference, so a second run over a month
            // already seeded skips every incident — and evidence keyed on an
            // earlier run without a blob would stay blob-less. --fresh clears the
            // area's demo incidents first so the reseed writes the bytes.
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Delete the area’s existing incidents first, then reseed (so evidence blobs are rewritten).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Incidents — the design’s sample month');

        // The taxonomy first: there is nothing to file an incident against
        // without it, and a seeder that failed for that reason would send
        // somebody hunting for a bug that is really a missing install step.
        $this->taxonomy->install();

        $area = $this->area($input->getArgument('area'));
        if (null === $area) {
            $io->error('No area to file incidents in. Seed an area first, or name one.');

            return Command::FAILURE;
        }

        $month = $this->month($io, $input->getOption('month'));
        if (null === $month) {
            return Command::FAILURE;
        }

        $cleared = 0;
        if (true === $input->getOption('fresh')) {
            $cleared = $this->deleteExisting($area);
            $io->note(\sprintf('Cleared %d existing incident(s) for this area before reseeding.', $cleared));
        }

        $zones = $this->zonesOf($area);
        $recorders = $this->recorders();
        $filed = 0;
        $skipped = 0;
        $this->photosStored = 0;
        $this->documentsStored = 0;

        foreach (DemoMonth::incidents() as $index => $row) {
            if (null !== $this->incidents->findOneByReference($row['reference'])) {
                ++$skipped;
                continue;
            }

            $subcategory = $this->subcategories->findOneBySlug($row['subcategory']);
            if (null === $subcategory) {
                $io->warning(\sprintf('No sub-category "%s" in this deployment — %s skipped.', $row['subcategory'], $row['reference']));
                continue;
            }

            $this->file($area, $subcategory, $row, $month, $zones, $recorders, $index);
            ++$filed;
        }

        $this->entityManager->flush();

        $io->definitionList(
            ['area' => (string) $area->getName()],
            ['month' => $month->format('F Y')],
            ['filed' => (string) $filed],
            ['already there' => (string) $skipped],
            ['zones matched' => \sprintf('%d of %d', \count($zones), \count(DemoMonth::ZONES))],
            ['recorders' => 0 === \count($recorders) ? 'none — the incidents are recorded by nobody' : (string) \count($recorders)],
            ['evidence stored' => $this->evidenceSummary()],
        );

        $io->success(
            $cleared > 0
                ? 'The sample month is in — the area was reseeded fresh.'
                : 'The sample month is in. Nothing existing was touched.',
        );

        return Command::SUCCESS;
    }

    /**
     * A one-line account of the bytes actually written, so a run that quietly
     * wrote no blobs (no storage installed, or no image support for photographs)
     * says so rather than looking the same as one that stored everything.
     */
    private function evidenceSummary(): string
    {
        if (null === $this->evidence && null === $this->evidenceFilesystem) {
            return 'none — no storage installed, evidence keyed without bytes';
        }

        $parts = [\sprintf('%d photograph(s)', $this->photosStored), \sprintf('%d document(s)', $this->documentsStored)];
        if (0 === $this->photosStored && !self::canRenderImages()) {
            $parts[] = 'no image support — photographs keyed without bytes';
        }

        return implode(', ', $parts);
    }

    /**
     * @param array{reference: string, day: int, hour: int, minute: int, subcategory: string, status: string, severity: string, zone: string, title: string, narrative: string|null, source: string, money: array{claimed: int|null, assessed: int|null, approved: int|null, settled: int|null}|null, evidence: int, parties: list<array{role: string, name: string, described: string|null}>} $row
     * @param array<string, Zone>                                                                                                                                                                                                                                                                                                                                                              $zones
     * @param list<UserInterface>                                                                                                                                                                                                                                                                                                                                                              $recorders
     */
    private function file(
        AreaOfInterest $area,
        IncidentSubcategory $subcategory,
        array $row,
        \DateTimeImmutable $month,
        array $zones,
        array $recorders,
        int $index,
    ): void {
        $reportedAt = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $row['day'])->setTime($row['hour'], $row['minute']);
        $position = DemoMonth::positionFor($index);

        $incident = new Incident($area, $subcategory, $row['reference'], $row['title'], $position, $reportedAt);
        $recorder = [] === $recorders ? null : $recorders[$index % \count($recorders)];
        $recorderName = self::nameOf($recorder);

        $incident
            ->setSeverity(IncidentSeverityEnum::from($row['severity']))
            ->setSource(IncidentSourceEnum::from($row['source']))
            ->setOccurredAt($reportedAt->modify('-2 hours'))
            ->setNarrative($row['narrative'])
            ->setReportedBy($recorder)
            ->setAssignedTo($recorder)
            // The zone by NAME where the host has one, and by geometry otherwise —
            // a demo area with real zones drawn on it gets the real answer.
            ->setZone($zones[$row['zone']] ?? $this->zones->locate($area, $position))
            ->setDetails(DemoMonth::detailsFor($subcategory, $index));

        new IncidentEvent($incident, IncidentEventKindEnum::Note, $reportedAt, \sprintf('Filed as %s.', $subcategory->path()))
            ->withActor($recorder, $recorderName)
            ->withDetail('source: '.$incident->getSource()->badge());

        foreach ($row['parties'] as $party) {
            new IncidentParty($incident, PartyRoleEnum::from($party['role']), $party['name'])
                ->setDescribedAs($party['described']);
        }

        for ($photo = 1; $photo <= $row['evidence']; ++$photo) {
            // Evidence keeps the moment the handset recorded, never the moment it
            // was uploaded — which here means the site visit, not the seed run.
            $filename = \sprintf('IMG_%04d.jpg', 1200 + $index * 4 + $photo);
            $evidence = new IncidentEvidence($incident, EvidenceKindEnum::Photo, $filename)
                ->setCapturedAt($reportedAt->modify(\sprintf('+%d hours', 5 + $photo)))
                ->setPosition($position);
            // Real bytes through the platform's evidence storage where it is
            // installed — a small generated JPEG the thumbnailer can shrink, so
            // the /files hub shows a preview and a size; a key alone otherwise.
            $this->storePhoto($evidence, $row['reference'], $filename);
        }

        // A SIGNED DOCUMENT wherever the category carries money — the claim form or
        // the penalty notice a money case always generates, the design's "1 document"
        // beside the photographs. A document has no handset moment, so on the hub it
        // sits under the day it was filed rather than a capture time.
        if (null !== $row['money'] && $subcategory->carriesMoney()) {
            $isFine = MoneyDirectionEnum::Fine === ($subcategory->getMoneyDirection() ?? MoneyDirectionEnum::Fine);
            $document = $isFine ? 'penalty_notice_signed.pdf' : 'claim_form_signed.pdf';
            $evidence = new IncidentEvidence($incident, EvidenceKindEnum::Document, $document)
                ->setCaption($isFine ? 'Served penalty notice' : 'Signed compensation claim form');
            // A tiny valid PDF, written straight to the evidence storage: store()
            // is for image uploads and refuses a PDF by design, so the document
            // blob goes to the same private store beside the key it is filed at.
            $this->storeDocument($evidence, $row['reference'], $document);
        }

        // The money BEFORE the transitions, because the resolve guard reads it:
        // an incident cannot be resolved while its claim is outstanding, and the
        // seeder is not allowed a shortcut past its own rules.
        if (null !== $row['money'] && $subcategory->carriesMoney()) {
            $direction = $subcategory->getMoneyDirection() ?? MoneyDirectionEnum::Fine;
            new IncidentMoney($incident, $direction)
                ->setCurrency($this->currency)
                ->setClaimed($row['money']['claimed'])
                ->setAssessed($row['money']['assessed'])
                ->setApproved($row['money']['approved'])
                ->setSettled($row['money']['settled'] ?? 0);
        }

        $this->entityManager->persist($incident);
        $this->walkTo($incident, IncidentStatusEnum::from($row['status']), $reportedAt, $recorder, $recorderName);
    }

    /**
     * Store a photograph's bytes and record what the store reported — size, type
     * and, where the thumbnailer could read it, a preview. A small generated JPEG
     * goes through the platform's own EvidenceStorage exactly as a field upload
     * does, so the /files hub shows the sample month's evidence with a real weight
     * and a thumbnail.
     *
     * Degrades to a KEY-ONLY row where storage is absent or no image can be drawn
     * on this machine — the same honest "we have that and it is empty" the hub was
     * already built to show, never a failed seed.
     */
    private function storePhoto(IncidentEvidence $evidence, string $reference, string $filename): void
    {
        $key = self::evidenceKey($reference, $filename);

        if (null === $this->evidence || !self::canRenderImages()) {
            $evidence->setPath($key);

            return;
        }

        $source = $this->makeJpeg($reference, $filename);
        if (null === $source) {
            $evidence->setPath($key);

            return;
        }

        try {
            $stored = $this->evidence->store(
                new \SplFileInfo($source),
                self::PREFIX.'/'.$reference,
                self::clientKey($filename),
            );
            $evidence
                ->setPath($stored->key)
                ->setMimeType($stored->mimeType)
                ->setByteSize($stored->byteSize)
                ->setThumbKey($stored->thumbKey);
            ++$this->photosStored;
        } catch (\Throwable) {
            // A demo photograph that will not store is not worth failing the whole
            // seed over — keep the key so it still appears on the hub, empty.
            $evidence->setPath($key);
        } finally {
            @unlink($source);
        }
    }

    /**
     * Store a signed document's bytes beside its key. store() is the wrong door —
     * it validates uploads against the image allowlist and would refuse a PDF —
     * so the blob is written straight to the private evidence storage, the way the
     * patrol thumbnail backfill writes a preview beside an existing key. A
     * key-only row where no storage is installed.
     */
    private function storeDocument(IncidentEvidence $evidence, string $reference, string $filename): void
    {
        $key = self::evidenceKey($reference, $filename);
        $evidence->setPath($key);

        if (null === $this->evidenceFilesystem) {
            return;
        }

        $bytes = self::onePagePdf($reference, $filename);
        try {
            $this->evidenceFilesystem->write($key, $bytes);
            $evidence
                ->setMimeType('application/pdf')
                ->setByteSize(\strlen($bytes));
            ++$this->documentsStored;
        } catch (\Throwable) {
            // The row keeps its key and shows on the hub; only the blob is missing.
        }
    }

    /**
     * A small real JPEG to a temp file: a deterministic earthy fill from the
     * reference, a lighter horizon band so a tile reads as a photograph rather
     * than a swatch, and the reference stamped on it so a /files grid does not
     * read as one repeated tile. Null where GD cannot write one.
     */
    private function makeJpeg(string $reference, string $filename): ?string
    {
        $image = imagecreatetruecolor(self::PHOTO_WIDTH, self::PHOTO_HEIGHT);
        [$r, $g, $b] = self::fillFor($reference.$filename);
        $fill = imagecolorallocate($image, $r, $g, $b);
        $band = imagecolorallocate($image, self::lighten($r), self::lighten($g), self::lighten($b));
        $ink = imagecolorallocate($image, 235, 235, 235);
        if (false !== $fill) {
            imagefilledrectangle($image, 0, 0, self::PHOTO_WIDTH, self::PHOTO_HEIGHT, $fill);
        }
        if (false !== $band) {
            imagefilledrectangle($image, 0, (int) (self::PHOTO_HEIGHT * 0.62), self::PHOTO_WIDTH, self::PHOTO_HEIGHT, $band);
        }
        if (false !== $ink) {
            imagestring($image, 5, 18, 16, $reference, $ink);
        }

        $path = tempnam(sys_get_temp_dir(), 'incident_seed_photo_');
        if (false === $path || false === imagejpeg($image, $path, 82)) {
            if (false !== $path) {
                @unlink($path);
            }

            return null;
        }

        return $path;
    }

    /**
     * A deterministic earthy fill derived from a string, so the same evidence
     * always draws the same colour and the grid still varies tile to tile.
     *
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private static function fillFor(string $seed): array
    {
        $hash = crc32($seed);

        return [
            60 + ($hash & 0x3F),
            72 + (($hash >> 6) & 0x3F),
            60 + (($hash >> 12) & 0x3F),
        ];
    }

    /**
     * A channel nudged towards white for the horizon band, kept in range.
     *
     * @return int<0, 255>
     */
    private static function lighten(int $channel): int
    {
        return max(0, min(255, $channel + 26));
    }

    /**
     * A minimal but valid one-page PDF as a byte string — a real file with a real
     * size, so the hub shows a document tile with a weight and its PDF icon. It is
     * never rendered as an image, so the single line of text (the reference) is
     * enough; the cross-reference offsets are computed so the file is well-formed.
     */
    private static function onePagePdf(string $reference, string $filename): string
    {
        $text = 'BT /F1 16 Tf 40 130 Td ('.self::pdfEscape($reference.'  —  '.$filename).') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 320 200] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.\strlen($text)." >>\nstream\n".$text."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = \strlen($pdf);
            $pdf .= ($i + 1).' 0 obj'."\n".$body."\n".'endobj'."\n";
        }

        $xref = \strlen($pdf);
        $count = \count($objects) + 1;
        $pdf .= 'xref'."\n".'0 '.$count."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.$count.' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF';

        return $pdf;
    }

    /** Escape the three characters a PDF literal string cares about. */
    private static function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * The client half of an evidence key: the filename without its extension,
     * which is already a single plain segment (IMG_1204, penalty_notice_signed).
     * EvidenceStorage appends the extension it derives from the DETECTED type, so
     * the stored key matches the one this seeder has always written.
     */
    private static function clientKey(string $filename): string
    {
        return pathinfo($filename, \PATHINFO_FILENAME);
    }

    /** GD, enough of it to draw and encode a JPEG. */
    private static function canRenderImages(): bool
    {
        return \function_exists('imagecreatetruecolor') && \function_exists('imagejpeg');
    }

    /**
     * Clear the area's incidents so a reseed writes fresh evidence blobs. The
     * children (events, evidence, parties, links, money) all carry ON DELETE
     * CASCADE, so one bulk delete takes the lot. Returns how many incidents went.
     */
    private function deleteExisting(AreaOfInterest $area): int
    {
        $deleted = $this->entityManager
            ->createQuery('DELETE FROM '.Incident::class.' i WHERE i.area = :area')
            ->setParameter('area', $area)
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }

    /**
     * Move a freshly filed incident to where the sample month says it is — one
     * legal transition at a time, through the real service. A seeder that wrote
     * the status column directly would be able to produce states the product
     * cannot, and the first bug report would be about the seeded data.
     */
    private function walkTo(Incident $incident, IncidentStatusEnum $target, \DateTimeImmutable $reportedAt, ?UserInterface $actor, ?string $actorName): void
    {
        $at = $reportedAt;
        foreach ([
            IncidentTransitionEnum::Verify,
            IncidentTransitionEnum::Respond,
            IncidentTransitionEnum::Resolve,
        ] as $step) {
            if (!$target->hasReached($step->toPlace())) {
                return;
            }
            $at = $at->modify('+7 hours');
            $this->transitions->apply($incident, $step, $at, $actor, $actorName);
        }

        if (IncidentStatusEnum::Closed === $target) {
            // The clock's own move, and it is made by the clock: thirty days after
            // resolution, with no actor. Even in a seeder.
            $this->transitions->closeIfDue($incident, $at->modify('+31 days'));
        }
    }

    private function area(mixed $named): ?AreaOfInterest
    {
        $repository = $this->entityManager->getRepository(AreaOfInterest::class);
        if (\is_string($named) && '' !== $named) {
            return $repository->findOneBy(['name' => $named]) ?? $repository->findOneBy(['uuid' => $named]);
        }

        $areas = $repository->findBy([], ['id' => 'ASC'], 1);

        return $areas[0] ?? null;
    }

    private function month(SymfonyStyle $io, mixed $raw): ?\DateTimeImmutable
    {
        $value = \is_string($raw) && '' !== $raw ? $raw : DemoMonth::MONTH;
        $month = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value.'-01 00:00:00');
        if (false === $month) {
            $io->error(\sprintf('"%s" is not a month. Write it as YYYY-MM.', $value));

            return null;
        }

        return $month;
    }

    /**
     * The area's zones, by name — only the ones the host actually has. A demo
     * area with no zones drawn gets an empty map, and every incident is unzoned,
     * which is a first-class answer rather than a failure.
     *
     * @return array<string, Zone>
     */
    private function zonesOf(AreaOfInterest $area): array
    {
        $found = [];
        foreach ($this->entityManager->getRepository(Zone::class)->findBy(['area' => $area]) as $zone) {
            if (\in_array($zone->getName(), DemoMonth::ZONES, true)) {
                $found[(string) $zone->getName()] = $zone;
            }
        }

        return $found;
    }

    /**
     * Whoever the host already has accounts for, oldest first. The seeder creates
     * no people: accounts are the host's business (seeder:accounts), and inventing
     * users here would put names on a performance page that nobody recognises.
     *
     * @return list<UserInterface>
     */
    private function recorders(): array
    {
        /** @var list<UserInterface> $users */
        $users = $this->entityManager->getRepository(UserInterface::class)->findBy([], ['id' => 'ASC'], 6);

        return $users;
    }

    /**
     * The storage key the Files hub lists a piece of evidence under. Its ROOT
     * SEGMENT is "incident" — the prefix {@see \Uhifadhi\Incident\Storage\IncidentFileSource::PREFIX}
     * claims — which is what makes the incidents file source pick this row up. It
     * is written as a literal here rather than read off that class so the dev-only
     * seeder never has to load storage-module (an optional dependency) to name one
     * of its own keys.
     */
    private static function evidenceKey(string $reference, string $filename): string
    {
        return self::PREFIX.'/'.$reference.'/'.$filename;
    }

    private static function nameOf(?UserInterface $user): ?string
    {
        if (null === $user) {
            return null;
        }

        $first = (string) $user->getFirstName();
        $last = (string) $user->getLastName();
        $name = trim(('' !== $first ? mb_substr($first, 0, 1).'. ' : '').$last);

        return '' !== $name ? $name : null;
    }
}
