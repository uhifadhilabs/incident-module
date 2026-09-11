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

namespace Uhifadhi\Incident\Upload;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface as PersonInterface;
use Uhifadhi\Incident\Controller\IncidentDetailController;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentEvidenceKey;
use Uhifadhi\Incident\Service\IncidentEvidenceService;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Model\EvidenceConstraints;
use Uhifadhi\Storage\Model\StoredFile;
use Uhifadhi\Storage\Model\UploadConstraints;
use Uhifadhi\Storage\Model\UploadReceipt;
use Uhifadhi\Storage\Upload\UploadTargetInterface;

/**
 * HOW A FILE GETS ONTO A CASE FILE — this module's half of the platform's one
 * upload component.
 *
 * It is the whole of what incidents wrote to gain uploads. No controller, no
 * route, no JavaScript, no stylesheet: storage owns the component, the endpoint,
 * the progress, the refusal sentences and the removal question, and this class
 * answers the four things storage cannot know.
 *
 *   WHICH CASE — by uuid, deliberately, and not by reference. A reference is
 *   presentation minted from the register; evidence filed under a label would be
 *   evidence that moved when the label did. It is the same choice
 *   {@see IncidentEvidenceKey::prefixFor()} already made for the key.
 *
 *   WHO MAY — `incidents.manage`, the same permission as moving a case through
 *   its workflow, and NOT the cheaper `incidents.record`. Evidence is what a
 *   claim rests on: filing a report is a cheap act and putting a photograph onto
 *   somebody else's case file is not.
 *
 *   WHAT AND HOW BIG — the deployment's cap, and the deployment's allowlist
 *   NARROWED TO THE TWO KINDS THE EVIDENCE CARD IS. The design names them:
 *   "photographs and documents". The installation's own list is wider than that
 *   — it carries GPX, and the XML a GPX arrives as, for the modules that import
 *   boundaries — and a case file that inherited it whole would take a track and
 *   file it as a document. Narrowing is a target's to do
 *   ({@see UploadConstraints}); widening is not, so which photographs and which
 *   documents stays the deployment's decision and only the KINDS are this
 *   module's.
 *
 *   WHAT IT BECAME — evidence, and the chip on the finished tile says so.
 *
 * THE KIND IS THE KEY PREFIX, and it is {@see IncidentEvidenceKey::PREFIX} —
 * the same string the voter claims and the file source lists on. It is named
 * there, once, and read here, so an upload, a read and a removal can never
 * disagree about who owns a file.
 *
 * TWO USER TYPES MEET HERE, and that is not an accident to tidy away. The
 * platform's contract speaks Symfony's `UserInterface`, because storage
 * authorises a request; this module's records point at
 * `Uhifadhi\Contracts\Entity\UserInterface`, because a timeline event names a
 * PERSON on the team. Narrowing between them is this class's job, and where the
 * signed-in account is not one of the platform's people the event is written
 * without an actor rather than not written at all.
 */
final readonly class IncidentEvidenceTarget implements UploadTargetInterface
{
    /** What the chip on a finished tile says this module made of the file. */
    public const string KIND_WORD = 'evidence';

    public function __construct(
        private IncidentRepository $incidents,
        private IncidentEvidenceRepository $evidenceRows,
        private IncidentEvidenceService $evidence,
        private AuthorizationCheckerInterface $authorization,
        private EvidenceConstraints $deployment,
    ) {
    }

    public function kind(): string
    {
        return IncidentEvidenceKey::PREFIX;
    }

    public function accepts(string $targetId): ?object
    {
        // Attacker-controlled text. Uuid::fromString() throws on anything that
        // is not one, and "not a uuid" is the same fact as "no such case".
        return Uuid::isValid($targetId) ? $this->incidents->findOneBy(['uuid' => Uuid::fromString($targetId)]) : null;
    }

    public function mayUpload(object $record, UserInterface $user): bool
    {
        return $record instanceof Incident
            && $this->authorization->isGranted(IncidentDetailController::MANAGE_PERMISSION);
    }

    /**
     * The card's own rule, stated once and read by both halves of the platform's
     * component: the zone prints it before anybody drops anything, and the
     * endpoint refuses against it in storage's own words.
     */
    public function constraints(object $record): UploadConstraints
    {
        return new UploadConstraints($this->photographsAndDocuments(), $this->deployment->maxBytes);
    }

    /**
     * The deployment's allowlist with everything that is not a photograph or a
     * document taken out of it.
     *
     * IT ASKS {@see FileKindEnum::recognise()}, NOT `fromMimeType()`. The second
     * answers Document for anything it cannot place, which would keep
     * `application/xml` and `text/xml` — the types a GPX's bytes actually detect
     * as — and the card would be back to accepting tracks under another name.
     * `recognise()` names only what the platform names positively, so a carrier
     * falls out of the list on its own.
     *
     * @return list<string>
     *
     * @throws \LogicException when the deployment accepts neither kind, which is
     *                         an installation whose case files could take no evidence at all
     */
    private function photographsAndDocuments(): array
    {
        $allowed = [];
        foreach ($this->deployment->allowedMimeTypes as $mimeType) {
            if (\in_array(FileKindEnum::recognise($mimeType), [FileKindEnum::Photo, FileKindEnum::Document], true)) {
                $allowed[] = $mimeType;
            }
        }

        if ([] === $allowed) {
            // Loud, because the alternative is a card that silently offers a
            // door nothing can come through, or one that widens past what the
            // storage would accept and promises a refusal.
            throw new \LogicException('This installation accepts no photographs and no documents, so a case file has nothing it could take as evidence. Widen the storage evidence allowlist.');
        }

        return $allowed;
    }

    public function received(object $record, StoredFile $file, UserInterface $user): UploadReceipt
    {
        if (!$record instanceof Incident) {
            throw new \LogicException('An upload reached the incidents target for something that is not a case file.');
        }

        $person = $user instanceof PersonInterface ? $user : null;

        $evidence = $this->evidence->attach(
            $record,
            $file,
            actor: $person,
            actorName: $person?->getFullName(),
        );

        // No href: the file's own page is the Files hub's, which an installation
        // may not run, and the case file it is already on is the page the person
        // is looking at. A link to where they already are is noise.
        return UploadReceipt::stored($evidence->getFilename(), null, self::KIND_WORD);
    }

    /**
     * A DIFFERENT QUESTION FROM mayUpload(), even though today it has the same
     * answer. Taking evidence off a case is at least as serious as putting it
     * on, so it is asked separately and can tighten without touching the other.
     *
     * A key under this module's prefix that no row holds answers false — the
     * same reading the hub's guard gives an unclaimed key. Nothing here can
     * authorise removing bytes nothing here admits to owning.
     */
    public function mayRemove(string $key, UserInterface $user): bool
    {
        return null !== $this->evidenceRows->findOneByPath($key)
            && $this->authorization->isGranted(IncidentDetailController::MANAGE_PERMISSION);
    }

    public function removed(string $key, UserInterface $user): void
    {
        $person = $user instanceof PersonInterface ? $user : null;

        $this->evidence->detach($key, actor: $person, actorName: $person?->getFullName());
    }
}
