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
     * A CLASS THE CHAIN NO LONGER SHIPS IS FORBIDDEN BY NAME. `w-addtile` is
     * the shell's tile for "Add widgets — open the library", the door at the
     * foot of a surface that the designs dropped: the library is reached only
     * through the page head's quiet `Configure` action. The shell is dropping
     * the rule, so a template that still writes the class would render an
     * unstyled anchor spanning the grid — and the base conformance check only
     * notices once the shell's release actually lands. Naming it here fails the
     * day somebody reintroduces it, in this repository, where the fix is.
     */
    public function testNoTemplateWritesAClassTheDesignsRetired(): void
    {
        $retired = ['w-addtile'];

        $offenders = [];
        foreach (self::templateFiles() as $file) {
            $markup = (string) file_get_contents($file);
            foreach ($retired as $class) {
                if (\in_array($class, self::literalClassesIn($markup), true)) {
                    $offenders[] = basename($file).': .'.$class;
                }
            }
        }

        self::assertSame([], $offenders, 'These write a retired class: the widget library is reached from the page head, not from a tile at the foot of the grid.');
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
     * A CONTRIBUTED CELL IS DRAWN ON SOMEBODY ELSE'S PAGE, so the names it
     * writes are either this module's own or the area overview's.
     *
     * The base check asks only whether SOMETHING in the chain ships a class, and
     * that is too generous here for one reason: this module's own sheet paints
     * its colour into the surface's slots — `.ao-by.incidents i`, `.ao-col
     * i.incidents` — and a decoration like that mentions the host's class
     * without defining it. So `.ao-flow` reads as shipped the moment anybody
     * qualifies it, and the cell that forgot the wrapper renders five browser
     * links with the suite green. Which is what happened.
     *
     * SO OWNERSHIP IS READ FROM THE SUBJECT OF A SELECTOR, not from anywhere in
     * it. `.ao-col i.incidents` defines `i.incidents`; `.ao-col` is the ground it
     * stands on, and the sheet that must carry it is the AREA's. A class an
     * overview cell writes therefore passes only when the core's own sheets
     * define it, or when this module's sheet is the subject-side author of it.
     *
     * The core publishes the list as `AreaBundle\Overview\OverviewVocabulary`
     * and will print it in the contracts' module-development.md §7 ("Contributing
     * to the overview"); until that release is tagged, the core's shipped sheets
     * ARE the list, which is what this reads.
     */
    public function testEveryClassTheOverviewCellsWriteIsThisModulesOwnOrTheSurfacesOwn(): void
    {
        $surface = self::classesAnywhereIn(array_map(self::readSheet(...), self::linkedStylesheets()));
        $own = self::classesSubjectedIn(array_map(
            static fn (string $name): string => self::readSheet(self::bundlePath().'/public/'.$name),
            self::ownStylesheets(),
        ));

        $offenders = [];
        foreach (self::templateFiles() as $file) {
            if (!str_contains($file, '/templates/overview/')) {
                continue;
            }

            foreach (self::literalClassesIn((string) file_get_contents($file)) as $class) {
                if (!\in_array($class, $surface, true) && !\in_array($class, $own, true)) {
                    $offenders[] = basename($file).': .'.$class;
                }
            }
        }

        $offenders = array_values(array_unique($offenders));
        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            "These overview cells write a class neither the area overview defines nor this module owns:\n  %s\n"
            .'A cell drawn on the area\'s page wears the surface\'s vocabulary or its own; it may not assume a rule '
            ."nobody wrote, and it may not answer the gap by writing that rule into this module's sheet.",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * EVERY CLASS ANYWHERE IN A SELECTOR. For the core's sheets that is the
     * right reading: whoever authored the file authored every name in it.
     *
     * @param list<string> $sheets
     *
     * @return list<string>
     */
    private static function classesAnywhereIn(array $sheets): array
    {
        $classes = [];
        foreach ($sheets as $css) {
            foreach (self::selectorsIn($css) as $selector) {
                preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $selector, $matches);
                $classes = [...$classes, ...$matches[1]];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * THE CLASSES A SHEET IS THE AUTHOR OF — the ones in the SUBJECT of each of
     * its selectors, which is the last compound. Everything to the left of the
     * subject is ground somebody else laid.
     *
     * @param list<string> $sheets
     *
     * @return list<string>
     */
    private static function classesSubjectedIn(array $sheets): array
    {
        $classes = [];
        foreach ($sheets as $css) {
            foreach (self::selectorsIn($css) as $selector) {
                $compounds = preg_split('/\s*[>+~]\s*|\s+/', trim($selector)) ?: [];
                $subject = (string) end($compounds);
                preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $subject, $matches);
                $classes = [...$classes, ...$matches[1]];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return list<string>
     */
    private static function selectorsIn(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/(^|\})([^{}@]+)\{/m', $css, $matches);

        $selectors = [];
        foreach ($matches[2] as $group) {
            foreach (explode(',', $group) as $selector) {
                $selector = (string) preg_replace('/\s+/', ' ', trim($selector));
                if ('' !== $selector) {
                    $selectors[] = $selector;
                }
            }
        }

        return array_values(array_unique($selectors));
    }

    /**
     * Every class LITERAL a template writes. An interpolated name is somebody
     * else's to check — `class="s{{ loop.index }}"` is five names this file
     * cannot see — so it is dropped rather than guessed at.
     *
     * @return list<string>
     */
    private static function literalClassesIn(string $twig): array
    {
        $twig = (string) preg_replace('/\{#.*?#\}/s', '', $twig);

        $classes = [];
        preg_match_all('/class="([^"]*)"/', $twig, $attributes);
        foreach ($attributes[1] as $attribute) {
            $literal = (string) preg_replace('/\{[{%].*?[}%]\}/s', ' ', $attribute);
            foreach (preg_split('/\s+/', trim($literal)) ?: [] as $class) {
                if (1 === preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*[a-zA-Z0-9_]$/', $class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    private static function readSheet(string $path): string
    {
        $css = file_get_contents($path);
        self::assertIsString($css, $path.' must ship.');

        return $css;
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
