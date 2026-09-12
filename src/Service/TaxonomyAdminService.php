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

namespace Uhifadhi\Incident\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;
use Uhifadhi\Incident\Entity\TaxonomySubcategory;
use Uhifadhi\Incident\Enum\BehaviorBlockEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Exception\TaxonomyConflictException;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;

/**
 * THE AREA-SCOPED TAXONOMY ADMIN, as a service — every write the manager screen
 * makes goes through here, so the two rules the design will not bend are enforced
 * in ONE place rather than re-argued per route:
 *
 *  · UNIQUENESS IS TWO-SCOPED. A kind label is unique within the AREA and a
 *    sub-category label within its parent KIND; a wire-code is unique within the
 *    whole AREA at each level. A collision is refused ({@see TaxonomyConflictException})
 *    rather than quietly producing two rows a saved filter could never separate.
 *
 *  · THE WIRE-CODE IS BORN ONCE AND NEVER CHANGES. It is derived from the first
 *    label if the administrator gives none, made unique in the area, and then
 *    frozen — renaming the label never touches it, which is the whole reason a
 *    saved filter and an offline handset can hold it.
 *
 *  · NOTHING IS DELETED. Retirement flips a flag; the row, its code and every
 *    record filed under it are kept, and one click brings it back.
 *
 * It owns the flush, unlike {@see IncidentReportService}: each call is one
 * discrete admin action behind an HTTP POST, so "did it save?" is the whole
 * question and the caller has nothing to batch with it.
 */
final class TaxonomyAdminService
{
    /**
     * The hues a kind may wear — the keys incidents.css actually declares. An
     * area that picks anything else would paint an invisible mark on the map, so
     * a stray value is clamped to the first rather than stored.
     *
     * @var list<string>
     */
    public const array COLOUR_KEYS = ['poach', 'hwc', 'comp', 'mort'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaxonomyKindRepository $kinds,
        private readonly TaxonomySubcategoryRepository $subcategories,
    ) {
    }

    // ── kinds ────────────────────────────────────────────────────────────────

    /**
     * Write the first — or the next — kind of incident for an area. Empty areas
     * start here; there is no seed and no starter pack.
     *
     * @throws TaxonomyConflictException on a duplicate label or wire-code
     */
    public function createKind(AreaOfInterest $area, string $label, string $colourKey = '', string $code = ''): TaxonomyKind
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A kind needs a name.');
        }
        if ($this->kinds->labelExistsInArea($area, $label)) {
            throw TaxonomyConflictException::label(\sprintf('This area already has a kind called "%s".', $label));
        }

        $code = $this->resolveKindCode($area, '' !== $code ? $code : $label);

        $kind = new TaxonomyKind($area, $code, $label, $this->clampColour($colourKey));
        $kind->setPosition($this->kinds->maxPositionForArea($area) + 1);

        $this->em->persist($kind);
        $this->em->flush();

        return $kind;
    }

    /** @throws TaxonomyConflictException on a duplicate label */
    public function renameKind(TaxonomyKind $kind, string $label): TaxonomyKind
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A kind needs a name.');
        }
        if ($this->kinds->labelExistsInArea($kind->getArea(), $label, $kind)) {
            throw TaxonomyConflictException::label(\sprintf('This area already has a kind called "%s".', $label));
        }

        $kind->setLabel($label); // the wire-code is deliberately untouched
        $this->em->flush();

        return $kind;
    }

    public function setKindColour(TaxonomyKind $kind, string $colourKey): TaxonomyKind
    {
        $kind->setColourKey($this->clampColour($colourKey));
        $this->em->flush();

        return $kind;
    }

    /**
     * The departments whose lens puts this kind first. ORDERING ONLY — it can
     * never gate who may read a row; see {@see TaxonomyKind}.
     *
     * @param list<string> $leads
     */
    public function setKindLeads(TaxonomyKind $kind, array $leads): TaxonomyKind
    {
        $cleaned = [];
        foreach ($leads as $lead) {
            $lead = $this->cleanLabel($lead);
            if ('' !== $lead && !\in_array($lead, $cleaned, true)) {
                $cleaned[] = $lead;
            }
        }

        $kind->setLeads($cleaned);
        $this->em->flush();

        return $kind;
    }

    public function deactivateKind(TaxonomyKind $kind): TaxonomyKind
    {
        $kind->deactivate();
        $this->em->flush();

        return $kind;
    }

    public function reactivateKind(TaxonomyKind $kind): TaxonomyKind
    {
        $kind->reactivate();
        $this->em->flush();

        return $kind;
    }

    // ── sub-categories ─────────────────────────────────────────────────────────

    /** @throws TaxonomyConflictException on a duplicate label or wire-code */
    public function createSubcategory(TaxonomyKind $kind, string $label, string $code = ''): TaxonomySubcategory
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A sub-category needs a name.');
        }
        if ($this->subcategories->labelExistsInKind($kind, $label)) {
            throw TaxonomyConflictException::label(\sprintf('"%s" already has a sub-category called "%s".', $kind->getLabel(), $label));
        }

        $code = $this->resolveSubCode($kind->getArea(), '' !== $code ? $code : $label);

        $subcategory = new TaxonomySubcategory($kind, $code, $label);
        $subcategory->setPosition($this->subcategories->maxPositionForKind($kind) + 1);

        $this->em->persist($subcategory);
        $this->em->flush();

        return $subcategory;
    }

    /** @throws TaxonomyConflictException on a duplicate label */
    public function renameSubcategory(TaxonomySubcategory $subcategory, string $label): TaxonomySubcategory
    {
        $label = $this->cleanLabel($label);
        if ('' === $label) {
            throw TaxonomyConflictException::label('A sub-category needs a name.');
        }
        if ($this->subcategories->labelExistsInKind($subcategory->getKind(), $label, $subcategory)) {
            throw TaxonomyConflictException::label(\sprintf('"%s" already has a sub-category called "%s".', $subcategory->getKind()->getLabel(), $label));
        }

        $subcategory->setLabel($label);
        $this->em->flush();

        return $subcategory;
    }

    /**
     * Switch a sub-category's behaviour blocks to exactly this set. The blocks
     * compose freely; the money direction is kept only when the money block is in
     * the set (the entity clears it otherwise).
     *
     * @param list<BehaviorBlockEnum> $blocks
     */
    public function setBlocks(TaxonomySubcategory $subcategory, array $blocks, ?MoneyDirectionEnum $moneyDirection = null): TaxonomySubcategory
    {
        $subcategory->setBlocks($blocks);
        if ($subcategory->carriesMoney()) {
            $subcategory->setMoneyDirection($moneyDirection);
        }
        $this->em->flush();

        return $subcategory;
    }

    /**
     * What this word promises, in hours — the clock the ageing widget and the
     * case file read. Below an hour is not a promise; the entity clamps it.
     */
    public function setTermHours(TaxonomySubcategory $subcategory, int $hours): TaxonomySubcategory
    {
        $subcategory->setTermHours($hours);
        $this->em->flush();

        return $subcategory;
    }

    public function deactivateSubcategory(TaxonomySubcategory $subcategory): TaxonomySubcategory
    {
        $subcategory->deactivate();
        $this->em->flush();

        return $subcategory;
    }

    public function reactivateSubcategory(TaxonomySubcategory $subcategory): TaxonomySubcategory
    {
        $subcategory->reactivate();
        $this->em->flush();

        return $subcategory;
    }

    // ── wire-codes ─────────────────────────────────────────────────────────────

    private function resolveKindCode(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 40);
        $code = $base;
        $n = 2;
        while ($this->kinds->codeExistsInArea($area, $code)) {
            $code = $this->slug($base.'-'.$n, 40);
            ++$n;
        }

        return $code;
    }

    private function resolveSubCode(AreaOfInterest $area, string $source): string
    {
        $base = $this->slug($source, 60);
        $code = $base;
        $n = 2;
        while ($this->subcategories->codeExistsInArea($area, $code)) {
            $code = $this->slug($base.'-'.$n, 60);
            ++$n;
        }

        return $code;
    }

    /** A wire-code from a label: lowercase, hyphen-joined, ascii-safe. */
    private function slug(string $value, int $limit): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        $value = mb_substr($value, 0, $limit);
        $value = trim($value, '-');

        return '' !== $value ? $value : 'kind';
    }

    private function cleanLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $label) ?? '');
    }

    private function clampColour(string $colourKey): string
    {
        return \in_array($colourKey, self::COLOUR_KEYS, true) ? $colourKey : self::COLOUR_KEYS[0];
    }
}
