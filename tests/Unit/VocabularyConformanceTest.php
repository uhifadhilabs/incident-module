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

namespace Uhifadhi\Incident\Tests\Unit;

use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

/**
 * The core's conformance rule, applied to this module: every class the templates
 * write is shipped by somebody, this bundle's sheets restate nothing the chain
 * already carries, and every glyph is drawn under a prefix this bundle may use.
 *
 * THE CHAIN IS WHAT `templates/base.html.twig` LINKS, in the order it links it:
 * the shell's design system, the atlas's map sheet, the shell's widget sheet,
 * and this module's own last, because it is the one allowed to decorate. A sheet left out of this list is a sheet whose classes read as
 * shipped-by-nobody; one added that the pages do not link is a sheet whose rules
 * this module would be free to restate without being told.
 *
 * @see VocabularyConformanceTestCase
 */
final class VocabularyConformanceTest extends VocabularyConformanceTestCase
{
    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected static function alias(): string
    {
        return 'incident';
    }

    /**
     * Both sheets this bundle ships. The taxonomy screen links the second one
     * alone, but the classes in it are this bundle's either way.
     *
     * @return list<string>
     */
    protected static function ownStylesheets(): array
    {
        return ['incidents.css', 'taxonomy.css'];
    }

    /**
     * @return list<string>
     */
    protected static function linkedStylesheets(): array
    {
        $shell = self::publicDirectoryOf(ShellBundle::class);
        $atlas = self::publicDirectoryOf(AtlasBundle::class);

        return [
            $shell.'/shell.css',
            $shell.'/widget.css',
            $atlas.'/map.css',
            // The AREA's sheet, because this module renders on the area's
            // overview as well as on its own pages: the overview partials under
            // templates/overview/ are drawn INTO AreaBundle's page, which links
            // area.css beside the sheet this module hands it through
            // ContributesStylesheetInterface. Leaving it out would read the
            // overview vocabulary as shipped by nobody, and — worse — leave this
            // module free to restate it.
            self::publicDirectoryOf(AreaBundle::class).'/area.css',
        ];
    }

    /**
     * NO MODULE DRAWS ITS OWN MAP. Maps come from the atlas: a module states
     * what is on one in PHP and calls render_map(). A template that names a map
     * controller of its own has a second opinion about imagery, chrome and
     * fullscreen, and two maps in the product then read differently.
     */
    public function testNoTemplateMountsAMapControllerOfItsOwn(): void
    {
        $offenders = [];
        foreach (self::templateFiles() as $file) {
            $markup = (string) file_get_contents($file);
            if (preg_match('/(data-controller|stimulus_controller)[^
]*map/i', $markup)) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'These templates mount a map controller: the atlas ships the only one.');
    }

    /**
     * AND NONE OF THEM NAMES LEAFLET. There is one Leaflet in an installation
     * and the UX Map bridge brings it; a module that links or names a second
     * gives the page a second module namespace, and layers built against one
     * are refused by the other.
     */
    public function testNoTemplateNamesLeaflet(): void
    {
        $offenders = [];
        foreach (self::templateFiles() as $file) {
            if (false !== stripos((string) file_get_contents($file), 'leaflet')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'These templates name Leaflet: the atlas and its UX Map bridge own it.');
    }

    /**
     * EVERY SELECT IS A `.fld` — the shell's field, the class the designs put on
     * all 194 of the selects they draw.
     *
     * A select with no house class on it, or with one styled only somewhere else
     * on the page, renders as the browser's own control: a white box with a
     * #767676 hairline in Arial, two thirds the height of the fields beside it,
     * ignoring the theme entirely. Nothing about that is visible in a template and
     * nothing about it fails a functional test, so it is asserted here instead.
     *
     * A row may still SIZE a field to its own rhythm — `.tx-form .fld` and
     * `.fsel>select.fld` do — because that is decoration over the shell's box and
     * not a second box.
     */
    public function testEverySelectTheTemplatesWriteWearsTheShellsFieldClass(): void
    {
        $offenders = [];
        foreach (self::templateFiles() as $file) {
            preg_match_all('/<select\b[^>]*>/', (string) file_get_contents($file), $matches);
            foreach ($matches[0] as $tag) {
                preg_match('/\bclass="([^"]*)"/', $tag, $class);
                if (!\in_array('fld', preg_split('/\s+/', trim($class[1] ?? '')) ?: [], true)) {
                    $offenders[] = basename($file).': '.$tag;
                }
            }
        }

        self::assertSame([], $offenders, 'These selects render as raw browser controls: a select is a .fld.');
    }

    /**
     * EVERY INSTANT THE TEMPLATES PRINT IS A `<time datetime=…>`.
     *
     * A moment is stored as UTC and rendered once, on a server, in whatever single
     * zone that server runs in — so a ranger in the field and an analyst three
     * timezones away read the same wall clock off the same page and one of them
     * reads it wrong. The frame fixes it for every module at once by rewriting the
     * text of a `<time datetime>` to the reader's own zone, and a module's whole
     * contribution is to emit the element. Nothing about a bare printed clock looks
     * wrong on the page it was rendered on, which is why it is asserted here.
     *
     * TWO SIGNALS SAY A PRINT IS AN INSTANT, and both are checked:
     *
     *   IT PRINTS A CLOCK. A time of day is what a zone visibly moves.
     *
     *   IT PRINTS SOMETHING NAMED `…At`. `reportedAt`, `occurredAt`, `capturedAt`,
     *   `retiredAt`, `closesAt` are moments by name even at day precision.
     *
     * A WINDOW IS NOT AN INSTANT AND MUST NOT BE LOCALISED. The month a filter is
     * set to, a day a feed groups by, a bucket a chart is keyed on — those are
     * boundaries the server chose and the reader asked for, and a browser three
     * hours away rewriting one would put a row under the wrong heading or a filter
     * under the wrong month.
     *
     * AND A MACHINE VALUE IN AN ATTRIBUTE IS NOT PRINTED TEXT: `datetime="…"`
     * itself, and the wall clock a `datetime-local` field is filled with, are read
     * by code, so they are exempt wherever they sit inside a tag.
     */
    public function testEveryInstantTheTemplatesPrintIsATimeElement(): void
    {
        $offenders = [];
        foreach (self::templateFiles() as $file) {
            $markup = (string) file_get_contents($file);
            $localised = self::timeElementsIn($markup);

            preg_match_all('/(\S{0,60}?)\|\s*date\(\s*\'([^\']*)\'/', $markup, $calls, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
            foreach ($calls as $call) {
                [$whole, $at] = [$call[0][0], $call[0][1]];
                $subject = $call[1][0];
                $format = $call[2][0];

                $isInstant = preg_match('/[His]/', $format) || preg_match('/At$/', $subject);
                if (!$isInstant || self::insideATag($markup, $at) || self::inAnyRange($localised, $at)) {
                    continue;
                }

                $offenders[] = basename($file).':'.(1 + substr_count(substr($markup, 0, $at), "\n")).' '.$whole;
            }
        }

        self::assertSame([], $offenders, 'These print a stored moment in the server\'s zone: an instant is a <time datetime>.');
    }

    /**
     * Where each `<time datetime=…>` element begins and ends. A `<time>` with no
     * `datetime` is not localised by anything, so it does not count as cover.
     *
     * @return list<array{int, int}>
     */
    private static function timeElementsIn(string $markup): array
    {
        preg_match_all('/<time\b[^>]*\bdatetime=.*?<\/time>/s', $markup, $matches, \PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $m): array => [$m[1], $m[1] + \strlen($m[0])],
            $matches[0],
        );
    }

    /** @param list<array{int, int}> $ranges */
    private static function inAnyRange(array $ranges, int $at): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($at >= $from && $at < $to) {
                return true;
            }
        }

        return false;
    }

    /** Whether an offset sits inside an HTML tag, which is to say in an attribute. */
    private static function insideATag(string $markup, int $at): bool
    {
        $open = strrpos(substr($markup, 0, $at), '<');
        $close = strrpos(substr($markup, 0, $at), '>');

        return false !== $open && ($open > ($close ?: -1));
    }

    /**
     * @return list<string>
     */
    private static function templateFiles(): array
    {
        $directory = self::bundlePath().'/templates';
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && 'twig' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @param class-string $bundle */
    private static function publicDirectoryOf(string $bundle): string
    {
        $file = new \ReflectionClass($bundle)->getFileName();
        self::assertIsString($file, $bundle.' must be autoloadable from a file.');

        return \dirname($file).'/public';
    }
}
