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

namespace UhifadhiLabs\Incident\Model;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Incident\Enum\IncidentSourceEnum;

/**
 * A REPORT ARRIVING FROM SOMEWHERE ELSE — the seam behind the patrols module's
 * "File as incident" button.
 *
 * THE SEAM IS A QUERY STRING, deliberately. The patrols module and this one are
 * separate bundles and a host may install either without the other, so neither
 * may name the other's classes or routes. A module that has something worth
 * filing sends a person to `incident_new` carrying what it knows:
 *
 *   /areas/{uuid}/modules/incidents/new
 *       ?source=patrol_observation
 *       &record=<uuid of the observation>
 *       &label=observation 2 of patrol P-0142
 *       &back=<url of that observation's page>
 *       &at=2026-08-22T08:15:00+03:00
 *       &lat=-3.2014&lng=35.4622
 *       &category=<sub-category slug it guesses>
 *       &note=<the field note, verbatim>
 *
 * Everything is a GUESS except the provenance. The category is a suggestion the
 * filer can overrule; the note is copied in and stays editable until the incident
 * is verified. What is NOT negotiable is the link: whatever the filer changes,
 * {@see record} and {@see label} are written once onto the incident and never
 * again ({@see \UhifadhiLabs\Incident\Entity\Incident::recordProvenance()}), so
 * the observation and the incident stay tied together forever.
 *
 * Every field is untrusted, so an unreadable one is simply absent and the form
 * opens empty in that place — a bad link must produce a blank form, never an
 * error page between a ranger and filing a report.
 */
final readonly class IncidentPrefill
{
    public function __construct(
        public ?Uuid $record = null,
        public ?string $label = null,
        public ?string $backUrl = null,
        public ?string $source = null,
        public ?\DateTimeImmutable $occurredAt = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $subcategorySlug = null,
        public ?string $note = null,
    ) {
    }

    /** Whether anything at all arrived — the page shows its provenance card only then. */
    public function isEmpty(): bool
    {
        return null === $this->record
            && null === $this->occurredAt
            && null === $this->latitude
            && null === $this->subcategorySlug
            && null === $this->note;
    }

    /** Whether there is a record to tie this incident to forever. */
    public function hasProvenance(): bool
    {
        return null !== $this->record && null !== $this->label;
    }

    /** The point, as GeoJSON text, or null where no usable coordinates arrived. */
    public function position(): ?string
    {
        if (null === $this->latitude || null === $this->longitude) {
            return null;
        }

        return \sprintf('{"type":"Point","coordinates":[%.6F,%.6F]}', $this->longitude, $this->latitude);
    }

    /**
     * THE POSITION AS A PERSON READS IT — "3°12'05"S 35°27'44"E", the same
     * degrees-minutes-seconds the observation page prints.
     *
     * The source card exists so the filer can recognise the thing they are
     * filing, and a place written one way on one page and another way on the
     * next is not recognisable. Null where no usable coordinates arrived: no
     * position row is better than half of one.
     */
    public function positionLabel(): ?string
    {
        if (null === $this->latitude || null === $this->longitude) {
            return null;
        }

        return self::dms($this->latitude, 'N', 'S').' '.self::dms($this->longitude, 'E', 'W');
    }

    /**
     * WHERE THIS CAME FROM, IN WORDS. The seam is a query string and the module
     * on the other side chooses the token, so one this module knows is named
     * properly and one it does not is printed as it arrived — the card must say
     * where a report came from even when the source is a kind nobody here has
     * heard of yet.
     */
    public function sourceLabel(): string
    {
        if (null === $this->source) {
            return 'linked record';
        }

        // The BADGE, not the label: the card prints a fact in a row of facts, and
        // the register already prints provenance with these same short words.
        return IncidentSourceEnum::tryFrom($this->source)?->badge()
            ?? str_replace(['_', '-'], ' ', $this->source);
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::uuid($request->query->getString('record')),
            self::text($request->query->getString('label'), 160),
            // Kept as a URL and rendered as a link, never followed by the server:
            // it is another module's address and this bundle does not resolve it.
            self::text($request->query->getString('back'), 512),
            self::text($request->query->getString('source'), 40),
            self::moment($request->query->getString('at')),
            self::coordinate($request->query->getString('lat'), 90.0),
            self::coordinate($request->query->getString('lng'), 180.0),
            self::text($request->query->getString('category'), 60),
            self::text($request->query->getString('note'), 2000),
        );
    }

    /**
     * One coordinate, in degrees, minutes and whole seconds with its hemisphere.
     * The seconds are ROUNDED and the carry is honoured, so a coordinate a
     * whisker under the next minute prints as that minute rather than as an
     * impossible 60".
     */
    private static function dms(float $value, string $positive, string $negative): string
    {
        $seconds = (int) round(abs($value) * 3600);

        return \sprintf(
            '%d°%02d\'%02d"%s',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
            $value < 0 ? $negative : $positive,
        );
    }

    private static function uuid(string $raw): ?Uuid
    {
        return Uuid::isValid($raw) ? Uuid::fromString($raw) : null;
    }

    private static function text(string $raw, int $max): ?string
    {
        $trimmed = trim($raw);

        return '' === $trimmed ? null : mb_substr($trimmed, 0, $max);
    }

    private static function moment(string $raw): ?\DateTimeImmutable
    {
        if ('' === trim($raw)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            // An unreadable timestamp means "we do not know when", which the form
            // already has a way to say. It is never worth a 500.
            return null;
        }
    }

    private static function coordinate(string $raw, float $limit): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return abs($value) <= $limit ? $value : null;
    }
}
