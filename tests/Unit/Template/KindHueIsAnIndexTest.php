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

namespace Uhifadhi\Incident\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * WHEREVER A KIND IS DRAWN IN ITS HUE, THE MARKUP SAYS WHICH OF THE NINE.
 *
 * RULED 2026-09-21: a module declares no colour. The hue comes from the house's
 * categorical set, and the markup reaches it by publishing an INDEX —
 * `data-cat="1".."9"` — which the shell's `[data-cat="n"]` rules resolve.
 * {@see \Uhifadhi\Incident\Entity\TaxonomyKind::catIndex()} is where the number
 * comes from: the kind's place in its area's list.
 *
 * WHY THIS TEST EXISTS NOW, WHILE THE OLD CLASSES ARE STILL THERE. The house
 * tokens have not shipped yet, so this module still paints through its own
 * `colourKey` classes for one release; the index rides beside them. The port
 * that deletes the classes is then a deletion and nothing else — and until it
 * runs, this is what stops a NEW hue-drawing element being added with only the
 * old half of the pair on it.
 *
 * It counts rather than parses: a template that spends a colour key as often
 * as it publishes an index has paired them, and a template that adds one more
 * of the former fails here on the day it is written.
 */
final class KindHueIsAnIndexTest extends TestCase
{
    public function testEveryTemplateThatPaintsAKindAlsoPublishesItsIndex(): void
    {
        $unpaired = [];

        foreach (self::templates() as $path => $body) {
            $spends = self::huesDrawn($body);
            if (0 === $spends) {
                continue;
            }

            // The index reaches the markup as the attribute, or as the `cat:`
            // key of a chart segment the charts macro turns into one.
            $published = preg_match_all('/data-cat=|\bcat:/', $body);

            if ($published < $spends) {
                $unpaired[] = \sprintf('%s — %d hue(s) drawn, %d index(es) published', $path, $spends, $published);
            }
        }

        sort($unpaired);

        self::assertSame([], $unpaired, \sprintf(
            "A kind is drawn in its hue without saying which of the nine it is:\n%s",
            implode("\n", $unpaired),
        ));
    }

    /**
     * HOW OFTEN THIS TEMPLATE PAINTS A KIND. Only what lands in a `class` or a
     * `style` attribute counts: `colourKey` printed as TEXT is the retired
     * picker naming the key it holds, and a word is not a hue. `colourKeys` —
     * that picker's option list — is not a spend either, which is why the
     * match is anchored on the singular.
     */
    private static function huesDrawn(string $body): int
    {
        preg_match_all('/(?:class|style)="[^"]*"/', $body, $attributes);

        // `i-hue-{{ kind.colourKey }}` is ONE hue drawn, not two: the class
        // prefix and the key it interpolates are the same mark, so the longer
        // alternative is tried first and swallows both.
        $drawn = 0;
        foreach ($attributes[0] as $attribute) {
            $drawn += preg_match_all('/i-hue-[^"]*?colourKey\b|i-hue-|colourKey\b/', $attribute);
        }

        return $drawn;
    }

    /**
     * @return array<string, string> template path, relative to the bundle, to its text
     */
    private static function templates(): array
    {
        $root = \dirname(__DIR__, 3).'/templates';

        $templates = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || 'twig' !== $file->getExtension()) {
                continue;
            }

            $templates[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($templates);

        return $templates;
    }
}
