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

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Controller\IncidentDetailController;

/**
 * THE TOKEN A SURFACE POSTS A TRANSITION WITH — minted in exactly one place.
 *
 * Two screens need it: the case file, whose buttons are ordinary forms, and the
 * STATUS BOARD, which moves an incident by dragging a card and therefore has to
 * hand its script a token to send. A widget library rendering that board needs
 * one too.
 *
 * Its own service rather than a method on whichever controller happened to have
 * the collaborators, because the failure it prevents is specific and has happened
 * before in this deployment: a controller validated a token, a template rendered
 * one — and the script never sent it. Every server-side test passed, because each
 * built its own; only a browser ever saw the 403. One service, one id
 * ({@see IncidentDetailController::csrfTokenId()}), one answer.
 *
 * NULL FOR SOMEBODY WHO MAY NOT MOVE AN INCIDENT. The board then renders
 * read-only rather than offering a gesture the server would refuse.
 */
final readonly class IncidentTransitionToken
{
    public function __construct(
        /** Null in a host without security; the board is then a board of links. */
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    public function forArea(AreaOfInterest $area): ?string
    {
        if (true !== $this->authorization?->isGranted(IncidentDetailController::MANAGE_PERMISSION)) {
            return null;
        }

        return $this->csrfTokenManager?->getToken(IncidentDetailController::csrfTokenId($area))->getValue();
    }
}
