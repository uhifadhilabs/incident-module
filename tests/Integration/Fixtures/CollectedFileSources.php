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

namespace Uhifadhi\Incident\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Storage\FileSourceInterface;

/**
 * THE PLATFORM'S SIDE OF THE FILE-SOURCE TAG, played by a fixture.
 *
 * It collects what the CORE's contract collects — a module declaring that it
 * stores files and what it calls one — rather than what the hub collects, and
 * the two are deliberately different questions. The hub's registry wants the
 * files as well; this wants only the declaration, which is the half any part
 * of the platform may read.
 *
 * The kernel wires it on the LITERAL tag name on purpose (see TestKernel): a
 * constant compares equal to itself wherever it moves, and the failure this
 * guards against is precisely the tag moving without the module following.
 *
 * @see FileSourceInterface
 */
final readonly class CollectedFileSources
{
    /**
     * @param iterable<FileSourceInterface> $sources
     */
    public function __construct(
        private iterable $sources,
    ) {
    }

    /**
     * @return array<string, string> module slug => the word that module uses for one file
     */
    public function wordBySlug(): array
    {
        $words = [];
        foreach ($this->sources as $source) {
            $words[$source->moduleSlug()] = $source->fileWord();
        }

        return $words;
    }
}
