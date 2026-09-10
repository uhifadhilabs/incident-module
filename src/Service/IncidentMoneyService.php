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

namespace Uhifadhi\Incident\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Exception\IncidentMoneyException;

/**
 * THE ONLY WAY MONEY GETS ONTO AN INCIDENT — the write surface behind the case
 * file's money panel, and the one place the four amounts and the waiver are
 * recorded.
 *
 * THE ROW IS BORN THE MOMENT AN AMOUNT IS RECORDED, never before. Filing opens no
 * money record ({@see IncidentReportService} says why), so this service creates it
 * lazily on the first save — which is exactly when the case file's money card
 * appears. A save with nothing on it creates nothing: an empty row would make an
 * incident unresolvable, its resolve guard waiting on an assessment nobody is
 * doing.
 *
 * TWO RULES IT WILL NOT BEND, both refused with the sentence the panel prints:
 *
 *  1. **Money only where the sub-category carries it.** A natural mortality has no
 *     money and never grows any — the direction is the sub-category's, inherited,
 *     never chosen here.
 *  2. **Not before response has started.** The money panel is a gated panel: it
 *     does not exist until `in progress`, and a POST that jumps the gate is refused
 *     the same way, because the gate is a rule and not only a rendering.
 *
 * EVERY WRITE LEAVES A TIMELINE EVENT. Money is a fact about the record, and a
 * record whose figures changed with no trace could not be relied on in a hearing —
 * the same reason a transition leaves one. The event is appended to the object
 * graph and this service flushes, mirroring {@see IncidentReportService}.
 */
final readonly class IncidentMoneyService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Record the four amounts, creating the money row on the first one. Each save
     * is the whole truth: an amount left blank is stored as "not set", so the panel
     * that prefills the current figures and posts them back is authoritative.
     *
     * @throws IncidentMoneyException when the incident carries no money, has not
     *                                reached response, or nothing was entered on a first save
     */
    public function record(
        Incident $incident,
        ?int $claimed,
        ?int $assessed,
        ?int $approved,
        ?int $settled,
        \DateTimeImmutable $now,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentMoney {
        $direction = $this->ready($incident);
        $money = $incident->getMoney();

        if (null === $money && null === $claimed && null === $assessed && null === $approved && null === $settled) {
            // Nothing to record, and no row yet — do not open an empty one. An
            // untouched money record is not "nothing is owed", it is an assessment
            // nobody has done, and it would sit blocking resolution forever.
            throw new IncidentMoneyException('Enter at least one amount to record money on this incident.');
        }

        $money ??= new IncidentMoney($incident, $direction);
        $money
            ->setClaimed($claimed)
            ->setAssessed($assessed)
            ->setApproved($approved)
            ->setSettled($settled ?? 0);

        $this->note($incident, $now, $actor, $actorName, \sprintf(
            'Money recorded — claimed %s, assessed %s, approved %s, %s %s. Outstanding %s.',
            self::figure($money->getClaimed()),
            self::figure($money->getAssessed()),
            self::figure($money->getApproved()),
            self::figure($money->getSettled()),
            $direction->settledWord(),
            self::figure($money->outstanding()),
        ));

        $this->entityManager->flush();

        return $money;
    }

    /**
     * Give up on the money, on the record, with a reason — the other way an
     * incident's money is "settled". Creates the row if none exists, because a
     * waiver is itself a statement that there was money to give up on.
     *
     * @throws IncidentMoneyException when the incident carries no money, has not
     *                                reached response, or the reason is blank
     */
    public function waive(
        Incident $incident,
        string $reason,
        \DateTimeImmutable $now,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentMoney {
        $direction = $this->ready($incident);

        $reason = trim($reason);
        if ('' === $reason) {
            throw new IncidentMoneyException('A waiver must say why; a waiver without a reason is a deletion.');
        }

        $money = $incident->getMoney() ?? new IncidentMoney($incident, $direction);
        $money->waive($now, $reason);

        $this->note($incident, $now, $actor, $actorName, \sprintf('Money waived — %s.', $reason));

        $this->entityManager->flush();

        return $money;
    }

    /**
     * The two invariants, checked before any row is touched, returning the
     * sub-category's inherited direction so the caller never re-derives it.
     */
    private function ready(Incident $incident): MoneyDirectionEnum
    {
        $direction = $incident->getSubcategory()->getMoneyDirection();
        if (null === $direction) {
            throw new IncidentMoneyException('This kind of incident carries no money, so there is nothing to record on it.');
        }

        if (!$incident->getStatus()->hasReached(IncidentStatusEnum::InProgress)) {
            throw new IncidentMoneyException('Money is recorded once response has started — this incident has not reached in progress yet.');
        }

        return $direction;
    }

    private function note(Incident $incident, \DateTimeImmutable $now, ?UserInterface $actor, ?string $actorName, string $body): void
    {
        new IncidentEvent($incident, IncidentEventKindEnum::Money, $now, $body)
            ->withActor($actor, $actorName);
    }

    private static function figure(?int $amount): string
    {
        return null === $amount ? '—' : number_format($amount, 0, '.', ',');
    }
}
