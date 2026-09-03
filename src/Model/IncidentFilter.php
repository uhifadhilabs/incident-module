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

namespace Uhifadhi\Incident\Model;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\IncidentCategory;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;

/**
 * THE ONE FILTER. The design says it in the markup of three separate widgets:
 * "one filter drives everything on the page — map, list and charts read the same
 * query". This object IS that query, so there is exactly one place a question
 * about which incidents are on screen can be answered.
 *
 * IT IS BUILT FROM A REQUEST, so every field is UNTRUSTED and every unreadable
 * value degrades to "no filter" rather than throwing: a hand-edited query string
 * must return a wider list, never a stack trace.
 *
 * THE LENS IS PART OF IT, AND IS ONLY EVER AN ORDERING. `lens=protection` selects
 * the category slugs that department leads; `lens=all` selects none, which is the
 * whole register. A lens can therefore be widened to everything with one click
 * and can never hide a row from anybody — which is the module's charter, enforced
 * by the shape of this object rather than by a promise in a docblock.
 */
final readonly class IncidentFilter
{
    /** The lens value that selects nothing and therefore shows everything. */
    public const string LENS_ALL = 'all';

    /**
     * @param list<string>             $categorySlugs empty means every kind
     * @param list<IncidentStatusEnum> $statuses      empty means every place
     */
    public function __construct(
        public AreaOfInterest $area,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public array $categorySlugs = [],
        public array $statuses = [],
        public ?string $zoneName = null,
        public ?string $search = null,
        public string $lens = self::LENS_ALL,
    ) {
    }

    /**
     * The same filter over a different window — how a widget asks "and what about
     * the month before?" without rebuilding every other choice the person made.
     */
    public function inWindow(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): self
    {
        return new self($this->area, $from, $to, $this->categorySlugs, $this->statuses, $this->zoneName, $this->search, $this->lens);
    }

    /** The same filter with the category chips cleared — what an "all · 47" chip links to. */
    public function withoutCategories(): self
    {
        return new self($this->area, $this->from, $this->to, [], $this->statuses, $this->zoneName, $this->search, $this->lens);
    }

    /** The same filter narrowed to one kind — what a category chip links to. */
    public function onlyCategory(string $slug): self
    {
        return new self($this->area, $this->from, $this->to, [$slug], $this->statuses, $this->zoneName, $this->search, $this->lens);
    }

    /** The same filter narrowed to one place — what a matrix cell links to. */
    public function onlyStatus(IncidentStatusEnum $status): self
    {
        return new self($this->area, $this->from, $this->to, $this->categorySlugs, [$status], $this->zoneName, $this->search, $this->lens);
    }

    /** Whether anything at all is narrowing the register beyond its window. */
    public function isNarrowed(): bool
    {
        return [] !== $this->categorySlugs
            || [] !== $this->statuses
            || null !== $this->zoneName
            || (null !== $this->search && '' !== $this->search);
    }

    /**
     * The query parameters that reproduce this filter — what every chip, cell and
     * legend entry links to. The window is left out: it is the page's, not the
     * chip's, and a link that silently re-pinned the month would be a different
     * question than the one clicked.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if ([] !== $this->categorySlugs) {
            $query['category'] = implode(',', $this->categorySlugs);
        }
        if ([] !== $this->statuses) {
            $query['status'] = implode(',', array_map(static fn (IncidentStatusEnum $s) => $s->value, $this->statuses));
        }
        if (null !== $this->zoneName) {
            $query['zone'] = $this->zoneName;
        }
        if (null !== $this->search && '' !== $this->search) {
            $query['q'] = $this->search;
        }
        if (self::LENS_ALL !== $this->lens) {
            $query['lens'] = $this->lens;
        }

        return $query;
    }

    /**
     * READ A REQUEST. Everything here is untrusted, so an unknown status is
     * dropped rather than refused and an unknown lens simply shows everything.
     *
     * @param list<IncidentCategory> $categories the taxonomy, for resolving a lens to the slugs it leads
     */
    public static function fromRequest(
        Request $request,
        AreaOfInterest $area,
        array $categories,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): self {
        $lens = $request->query->getString('lens', self::LENS_ALL);
        $lens = '' === $lens ? self::LENS_ALL : $lens;

        $slugs = self::splitSlugs($request->query->getString('category'));
        if ([] === $slugs && self::LENS_ALL !== $lens) {
            $slugs = self::categoriesLedBy($categories, $lens);
        }

        $statuses = [];
        foreach (self::splitSlugs($request->query->getString('status')) as $value) {
            $status = IncidentStatusEnum::tryFrom($value);
            if (null !== $status) {
                $statuses[] = $status;
            }
        }

        $zone = $request->query->getString('zone');
        $search = $request->query->getString('q');

        return new self(
            $area,
            $from,
            $to,
            $slugs,
            $statuses,
            '' !== $zone ? $zone : null,
            '' !== $search ? $search : null,
            $lens,
        );
    }

    /**
     * The kinds of incident a lens puts first. A lens naming a department nobody
     * leads for selects NOTHING — which, by the rule above, is the whole register
     * rather than an empty screen.
     *
     * The match is on the department name the taxonomy records, case-insensitively
     * and on a prefix, because the lens in the URL is a short word ("protection")
     * and the taxonomy's own words are the full ones ("Protection Service").
     *
     * @param list<IncidentCategory> $categories
     *
     * @return list<string>
     */
    public static function categoriesLedBy(array $categories, string $lens): array
    {
        $needle = mb_strtolower(trim($lens));
        if ('' === $needle || self::LENS_ALL === $needle) {
            return [];
        }

        $slugs = [];
        foreach ($categories as $category) {
            foreach ($category->getLeads() as $lead) {
                if (str_starts_with(mb_strtolower($lead), $needle)) {
                    $slugs[] = $category->getSlug();
                    break;
                }
            }
        }

        return $slugs;
    }

    /** @return list<string> */
    private static function splitSlugs(string $raw): array
    {
        $parts = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ('' !== $part && !\in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts;
    }
}
