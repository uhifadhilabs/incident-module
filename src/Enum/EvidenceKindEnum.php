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

namespace UhifadhiLabs\Incident\Enum;

/**
 * WHAT A PIECE OF EVIDENCE IS. A photograph carries a position and the time the
 * handset recorded; a document carries neither and is usually signed.
 */
enum EvidenceKindEnum: string
{
    case Photo = 'photo';
    case Document = 'document';

    /** The modifier the `.i-ph` tile wears; a photograph is the tile's default. */
    public function cssClass(): string
    {
        return match ($this) {
            self::Photo => '',
            self::Document => 'doc',
        };
    }
}
