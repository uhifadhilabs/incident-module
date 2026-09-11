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
use Uhifadhi\Incident\Entity\IncidentSettings;
use Uhifadhi\Incident\Repository\IncidentSettingsRepository;

/**
 * WHAT ONE AREA RUNS INCIDENTS ON — read here, written here, and nowhere else.
 *
 * THE INSTALLATION IS THE FALLBACK, NOT THE ANSWER. The currency was one
 * `incident:` block for a whole deployment, which is wrong the moment a
 * deployment reaches across a border: two areas can collect fines in two
 * currencies and neither is the installation's. So an area keeps its own row,
 * and an area that has never chosen reads the installation's configuration.
 *
 * THE CHOICES ARE THE DESIGN'S. The Settings section draws a select, and a
 * select is not a security boundary, so the same list is enforced here on the
 * way in: a hand-posted code that is not offered leaves the area on what it had.
 */
final readonly class IncidentSettingsService
{
    /**
     * The currencies the design's select offers, code → what it is called. The
     * list is the design's own and lives in one place, so the section and the
     * write agree about what may be chosen.
     *
     * @var array<string, string>
     */
    public const array CURRENCIES = [
        'TZS' => 'Tanzanian shilling',
        'KES' => 'Kenyan shilling',
        'UGX' => 'Ugandan shilling',
        'RWF' => 'Rwandan franc',
        'USD' => 'US dollar',
        'EUR' => 'euro',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentSettingsRepository $settings,
        private string $installationCurrency,
    ) {
    }

    /** What this area counts money in: its own choice, or the installation's. */
    public function currencyFor(AreaOfInterest $area): string
    {
        return $this->settings->findOneByArea($area)?->getCurrency() ?? $this->installationCurrency;
    }

    /** Whether this area has chosen for itself, or is still reading the installation's. */
    public function areaChose(AreaOfInterest $area): bool
    {
        return null !== $this->settings->findOneByArea($area);
    }

    /**
     * SAVE WHAT THE AREA CHOSE. The row is created on first save, which is what
     * makes "this area has never been configured" a fact the database holds
     * rather than a guess. A code the design does not offer is refused by
     * leaving the area on what it had, because a currency nobody can read is
     * worse than a currency somebody did not mean to keep.
     */
    public function save(AreaOfInterest $area, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!isset(self::CURRENCIES[$currency])) {
            return $this->currencyFor($area);
        }

        $row = $this->settings->findOneByArea($area);
        if (null === $row) {
            $row = new IncidentSettings($area, $currency);
            $this->entityManager->persist($row);
        } else {
            $row->setCurrency($currency);
        }

        $this->entityManager->flush();

        return $currency;
    }
}
