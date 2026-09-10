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
 * the shell's design system, the atlas's Leaflet build and map sheet, the shell's
 * widget sheet, and this module's own last, because it is the one allowed to
 * decorate. A sheet left out of this list is a sheet whose classes read as
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
            $atlas.'/leaflet/leaflet.css',
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

    /** @param class-string $bundle */
    private static function publicDirectoryOf(string $bundle): string
    {
        $file = new \ReflectionClass($bundle)->getFileName();
        self::assertIsString($file, $bundle.' must be autoloadable from a file.');

        return \dirname($file).'/public';
    }
}
