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
 * categorical set, and the markup reaches it by publishing a POSITION —
 * `data-cat="1".."9"` — which the shell's `[data-cat="n"]` rules resolve.
 * {@see \Uhifadhi\Incident\Entity\TaxonomyKind::catIndex()} is where the number
 * comes from: the kind's place in its area's list.
 *
 * SO A MARK IS NEVER NAMED, ONLY PLACED. The classes a template writes say what
 * the mark IS — a chip, a dot, an arc, a square — and the attribute beside them
 * says whose it is. Nothing in a template may name a hue: not as a key it
 * interpolates, not as a class built out of one, not as a token of this
 * module's own.
 *
 * AND A MARK THAT WEARS NO KIND SAYS THAT TOO. `data-cat=""` is the shell's
 * own spelling for an unknown position and resolves to the muted mark, so the
 * "All categories" option draws a grey dot rather than an invisible one. The
 * attribute is therefore on every mark, and its VALUE is the whole of what
 * varies — which is why the rule below can be "no exceptions" rather than a
 * count that happens to balance.
 *
 * @see ModuleDeclaresNoColourTest the same rule read off the stylesheets
 */
final class KindHueIsAnIndexTest extends TestCase
{
    /**
     * The classes that draw a kind's mark — every one of them a shape whose
     * fill is `var(--cat)`, in this module's sheets or in the shell's.
     *
     * @var list<string>
     */
    private const array MARKS = [
        'i-cat',  // the register chip, with its dot and its tinted border
        'i-dot',  // the dot inside a filter option
        'i-hue',  // a bare square drawn for a kind and nothing else
        'i-card', // the board card, whose id row carries the mark
        'catsw',  // the shell's read-only swatch: the hue, shown, never picked
        'arc',    // a segment of the category donut
    ];

    public function testEveryMarkThatWearsAKindsHueSaysWhichOfTheNine(): void
    {
        $unpaired = [];

        foreach (self::templates() as $path => $body) {
            foreach (self::markTags($body) as $tag) {
                // The position reaches the markup as the attribute, or — for a
                // donut arc, which the charts macro writes — as the `cat:` key
                // of the segment it is handed.
                if (1 === preg_match('/data-cat=|\bcat:/', $tag)) {
                    continue;
                }

                $unpaired[] = $path.' — '.$tag;
            }
        }

        sort($unpaired);

        self::assertSame([], $unpaired, \sprintf(
            "A kind is drawn in its hue without saying which of the nine it is:\n%s",
            implode("\n", $unpaired),
        ));
    }

    /**
     * AND NO TEMPLATE NAMES A HUE. The retired spellings are named one by one
     * because each is a way the old answer could come back: the colour key an
     * administrator used to pick, a class built by interpolating it, and the
     * five tokens this sheet used to declare.
     */
    public function testNoTemplateNamesAHue(): void
    {
        $offenders = [];

        foreach (self::templates() as $path => $body) {
            foreach (['colourKey', 'colourKeys', 'i-hue-', 'var(--i-'] as $spelling) {
                if (str_contains($body, $spelling)) {
                    $offenders[] = $path.' — '.$spelling;
                }
            }

            // A hue set straight onto the element, which is how the manager's
            // dot and the kinds strip's dot were drawn before the position was
            // a thing the markup could say.
            if (1 === preg_match('/style="[^"]*(#[0-9A-Fa-f]{3,8}|--cat-[1-9])/', $body)) {
                $offenders[] = $path.' — a hue in a style attribute';
            }
        }

        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            "These name a hue instead of publishing a position:\n%s",
            implode("\n", $offenders),
        ));
    }

    /**
     * THE TAGS THIS TEMPLATE DRAWS A MARK WITH. Only what lands in a `class`
     * counts, and a `ghost` is not a mark: the inert sketch and the board's
     * "and N more" placeholder stand for a row rather than for a kind, and
     * neither draws a hue at all.
     *
     * @return list<string>
     */
    private static function markTags(string $body): array
    {
        preg_match_all('/<[a-z][^>]*>/i', $body, $tags);

        $marks = [];
        foreach ($tags[0] as $tag) {
            if (1 !== preg_match('/class="(?<classes>[^"]*)"/', $tag, $attribute)) {
                continue;
            }

            $classes = $attribute['classes'];
            if (1 === preg_match('/(?<![\w-])ghost(?![\w-])/', $classes)) {
                continue;
            }

            foreach (self::MARKS as $mark) {
                if (1 === preg_match('/(?<![\w-])'.preg_quote($mark, '/').'(?![\w-])/', $classes)) {
                    $marks[] = preg_replace('/\s+/', ' ', trim($tag)) ?? $tag;

                    break;
                }
            }
        }

        return $marks;
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
