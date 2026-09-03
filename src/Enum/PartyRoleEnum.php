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

namespace Uhifadhi\Incident\Enum;

/**
 * WHAT SOMEBODY WAS TO THIS INCIDENT.
 *
 * The design refuses to build four tables: a suspect, a claimant, a witness and
 * the ranger who filed it are the SAME shape of record wearing different roles.
 * The animal is a party too — that is what lets a repeat offender be recognised
 * across incidents rather than being retyped into a note each time.
 */
enum PartyRoleEnum: string
{
    case Reporter = 'reporter';
    case Claimant = 'claimant';
    case Suspect = 'suspect';
    case Witness = 'witness';
    case Verifier = 'verifier';
    case Responder = 'responder';
    case Animal = 'animal';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * The modifier the `.i-role` chip wears. Only the two roles the design gives
     * a colour have one; every other role is the plain chip, and inventing a
     * class here would style nothing.
     */
    public function cssClass(): string
    {
        return match ($this) {
            self::Claimant => 'claimant',
            self::Reporter => 'reporter',
            default => '',
        };
    }
}
