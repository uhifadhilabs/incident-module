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
 * A STATUS IS JUDGED; A CATEGORY IS ONLY TOLD APART.
 *
 * RULED 2026-09-21. The categorical set — `--cat-1` and its siblings — exists
 * to say that one thing is not another: a patrol type, a zone, a department.
 * It carries no opinion, which is exactly what makes it wrong for a status,
 * because every status on this module MEANS something. Reported needs a look,
 * resolved went well, closed is spent, and in progress is HAPPENING — and the
 * accent is the platform's word for live.
 *
 * `.i-st.wip` borrowed `--cat-3` for exactly as long as nobody had ruled on
 * it, with a FLAGGED note in the sheet saying so. The ruling came: a running
 * state wears the filled accent, and verified beside it went neutral so the
 * accent points at one row and no other.
 *
 * THIS TEST IS THE RULING, NOT THE FIX. Changing the two rules would have
 * been a one-line edit that the next person could undo without noticing;
 * what keeps a status out of the categorical set is a check that fails when
 * one reaches for it again.
 */
final class StatusWearsNoCategoryTest extends TestCase
{
    /** Every status this module draws, as a CSS class suffix. */
    private const array STATUSES = ['rep', 'ver', 'wip', 'res', 'cls'];

    /**
     * AND EVERY PLACE IT DRAWS THEM.
     *
     * `.i-st` is the pill on a case file; `.ao-move` is the same five keys on
     * the AREA's pulse row, where the host prints the move and this module
     * supplies the state class. A status that read one colour on the case
     * file and another in the stream would be two statuses, so both families
     * are held to one ruling — which is the thing a check on `.i-st` alone
     * would have missed.
     *
     * @var list<string>
     */
    private const array FAMILIES = ['.i-st', '.ao-move'];

    public function testNoStatusRuleNamesACategoryToken(): void
    {
        $offenders = [];
        foreach (self::statusRules() as $selector => $body) {
            if (1 === preg_match('/var\(\s*--cat-/', $body)) {
                $offenders[] = $selector.' { '.trim($body).' }';
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "A status rule spends a category token. The categorical set says only that one thing is NOT another; a status means something, so it takes a semantic token or the accent.\n%s",
            implode("\n", $offenders),
        ));
    }

    /**
     * THE RUNNING STATE IS THE FILLED ACCENT. Stated positively as well as
     * negatively: a sheet that simply dropped the colour would pass the rule
     * above and draw an unstyled pill.
     */
    public function testInProgressIsTheFilledAccent(): void
    {
        $wip = self::statusRules()['.i-st.wip'] ?? null;

        self::assertNotNull($wip, 'the running state has a rule of its own');
        self::assertStringContainsString('background:var(--acc)', self::squashed($wip));
        self::assertStringContainsString('color:var(--accT)', self::squashed($wip));
    }

    /**
     * AND IT IS THE ONLY ONE. Two accented states would point at two rows and
     * mean neither, which is why verified went to the neutral outline in the
     * same ruling.
     */
    public function testVerifiedIsTheNeutralOutlineSoTheAccentPointsAtOneRow(): void
    {
        $ver = self::squashed(self::statusRules()['.i-st.ver'] ?? '');

        self::assertStringContainsString('color:var(--tx)', $ver);
        self::assertStringContainsString('border-color:var(--ln2)', $ver);
        self::assertStringContainsString('background:none', $ver, 'a fact carries no tint');

        // The same two states on the area's pulse row, where a status is ink
        // rather than a pill: same meaning, same tokens.
        self::assertStringContainsString('color:var(--acc)', self::squashed(self::statusRules()['.ao-move.wip'] ?? ''));
        self::assertStringContainsString('color:var(--tx)', self::squashed(self::statusRules()['.ao-move.ver'] ?? ''));
    }

    /**
     * Every status rule in the shipped sheet, keyed by selector.
     *
     * Read out of the sheet rather than listed here, so a status added later
     * is held to the same rule without anybody remembering to add it.
     *
     * @return array<string, string>
     */
    private static function statusRules(): array
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $rules = [];
        foreach (self::FAMILIES as $family) {
            foreach (self::STATUSES as $status) {
                $selector = $family.'.'.$status;
                if (1 === preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/s', $css, $match)) {
                    $rules[$selector] = $match[1];
                }
            }
        }

        self::assertNotSame([], $rules, 'the sheet still draws status pills');

        return $rules;
    }

    private static function squashed(string $body): string
    {
        return (string) preg_replace('/\s+/', '', $body);
    }
}
