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

namespace UhifadhiLabs\Incident\Overview;

use Uhifadhi\Overview\OverviewCopyProviderInterface;
use UhifadhiLabs\Incident\Model\IncidentOverviewWidgets;

/**
 * THE MODULE'S WORDS INSIDE THE HOST'S SENTENCES.
 *
 * The host's line about its operational map used to read "…today's tracks and
 * open incidents", written out in the host's own copy from the design. It was
 * correct on the design's page and wrong everywhere else: an area without this
 * module was still promised open incidents, and the host was naming a subject it
 * is not allowed to know.
 *
 * So the phrase lives here, beside the layer it describes.
 *
 * PHRASES, NOT SENTENCES: lower case, unpunctuated. Where the conjunction goes
 * is the host's business.
 */
final readonly class IncidentOverviewCopy implements OverviewCopyProviderInterface
{
    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function copyFragments(string $slot): array
    {
        return match ($slot) {
            // OPEN ONES ONLY, because that is the layer. A resolved or closed
            // incident is not drawn on the overview at all — the module's own map
            // draws those, because that page is about the record and this one is
            // about the day — so naming them here would describe a plate nobody
            // sees.
            self::SLOT_MAP_LAYERS => ['open incidents'],

            // WHAT A MAP-LED PAGE IS WORTH ADOPTING FOR is a claim this module
            // does not make. A cluster of pins is something one reads off any
            // map, and the host already says so; the module has nothing to add
            // that is not already the host's own sentence. Silence is an answer.
            default => [],
        };
    }
}
