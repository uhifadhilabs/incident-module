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

/**
 * THE TAXONOMY THE MODULE SHIPS WITH — the design's reference card (IN·09), as
 * data: four kinds of incident and the sixteen sub-categories under them.
 *
 * IT IS A DEFAULT, NOT A LAW. A deployment overrides the whole tree under
 * `incident.taxonomy` and {@see \Uhifadhi\Incident\Service\IncidentTaxonomyInstaller}
 * writes whatever it finds; nothing in this bundle switches on a slug. What is
 * here is what a fresh install gets so that the module is usable the hour it is
 * switched on, rather than being an empty form asking somebody to invent a
 * classification scheme before they can file the first snare.
 *
 * THREE RULINGS ARE WRITTEN INTO THIS TABLE, and each is a decision that would
 * otherwise be argued again every time somebody reads the code:
 *
 *  1. **Roadkill is ONE entry that carries a FINE.** Not a pair of linked
 *     incidents, one ecological and one for the driver. A vehicle killing a zebra
 *     is one event; whether a driver was fined is a fact about that event, and
 *     the money field is how it is recorded. Ecology leads the category and
 *     compliance reads it — which is precisely what "a lens, not a fence" means.
 *  2. **Money is per SUB-category, never per category.** Roadkill carries a fine
 *     while natural mortality beside it carries nothing, so the money block is
 *     absent from one form and present in the other.
 *  3. **Each sub-category promises its OWN term.** A human injury is 72 hours; a
 *     construction notice is 14 days; a compensation claim is 30. One global SLA
 *     would be a lie about all three.
 *
 * The conflict categories deliberately name BOTH departments in `leads`: they sit
 * in both lenses, and the design says so on the reference card.
 */
final class IncidentTaxonomy
{
    /** The department names the shipped taxonomy leads with, in the design's own words. */
    public const string PROTECTION = 'Protection Service';
    public const string ECOLOGY = 'Ecology & Wildlife Mgmt';

    /**
     * The whole tree, in the order every surface draws it.
     *
     * @return array<string, array{
     *     label: string,
     *     colour: string,
     *     leads: list<string>,
     *     subcategories: array<string, array{label: string, money: string|null, term_hours: int, fields: list<string>}>
     * }>
     */
    public static function shipped(): array
    {
        return [
            'poaching' => [
                'label' => 'Poaching & wildlife crime',
                'colour' => 'poach',
                'leads' => [self::PROTECTION],
                'subcategories' => [
                    'snaring' => [
                        'label' => 'snaring',
                        'money' => 'fine',
                        'term_hours' => 72,
                        'fields' => ['Species', 'Snares lifted', 'Method', 'Suspects', 'Seizures'],
                    ],
                    'bushmeat' => [
                        'label' => 'bushmeat',
                        'money' => 'fine',
                        'term_hours' => 72,
                        'fields' => ['Species', 'Quantity', 'Method', 'Suspects', 'Seizures'],
                    ],
                    'ivory-trophy' => [
                        'label' => 'ivory & trophy',
                        'money' => 'fine',
                        'term_hours' => 72,
                        'fields' => ['Species', 'Trophy', 'Method', 'Suspects', 'Seizures'],
                    ],
                    'illegal-fishing' => [
                        'label' => 'illegal fishing',
                        'money' => 'fine',
                        'term_hours' => 168,
                        'fields' => ['Water body', 'Gear', 'Catch', 'Suspects', 'Seizures'],
                    ],
                ],
            ],
            'conflict' => [
                'label' => 'Human–wildlife conflict',
                'colour' => 'hwc',
                // BOTH lenses lead with conflict — the design's reference card
                // prints "leads: Protection · Ecology" against this one row.
                'leads' => [self::PROTECTION, self::ECOLOGY],
                'subcategories' => [
                    'livestock-depredation' => [
                        'label' => 'livestock depredation',
                        'money' => 'compensation',
                        'term_hours' => 720,
                        'fields' => ['Species', 'Livestock lost', 'Enclosure', 'Household', 'Retaliation risk'],
                    ],
                    'crop-raiding' => [
                        'label' => 'crop raiding',
                        'money' => 'compensation',
                        'term_hours' => 72,
                        'fields' => ['Species', 'Crop', 'Area affected', 'Household', 'Deterrents in place'],
                    ],
                    'human-injury' => [
                        'label' => 'human injury',
                        'money' => 'compensation',
                        'term_hours' => 72,
                        'fields' => ['Species', 'Injuries', 'Treatment', 'Household', 'Circumstances'],
                    ],
                    'property-damage' => [
                        'label' => 'property damage',
                        'money' => 'compensation',
                        'term_hours' => 336,
                        'fields' => ['Species', 'Property', 'Extent', 'Household', 'Circumstances'],
                    ],
                ],
            ],
            'compliance' => [
                'label' => 'Compliance & encroachment',
                'colour' => 'comp',
                'leads' => [self::PROTECTION],
                'subcategories' => [
                    'unauthorized-construction' => [
                        'label' => 'unauthorized construction',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'fields' => ['Structures', 'Footprint', 'Permit status', 'Occupier', 'Notice served'],
                    ],
                    'illegal-grazing' => [
                        'label' => 'illegal grazing',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'fields' => ['Herd size', 'Livestock type', 'Owner', 'Duration', 'Notice served'],
                    ],
                    'boundary-encroachment' => [
                        'label' => 'boundary encroachment',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'fields' => ['Extent', 'Land use', 'Occupier', 'Boundary marker', 'Notice served'],
                    ],
                    'unlicensed-operation' => [
                        'label' => 'unlicensed operation',
                        'money' => 'fine',
                        'term_hours' => 336,
                        'fields' => ['Activity', 'Operator', 'Licence status', 'Vehicles', 'Notice served'],
                    ],
                ],
            ],
            'mortality' => [
                'label' => 'Wildlife mortality',
                'colour' => 'mort',
                'leads' => [self::ECOLOGY],
                'subcategories' => [
                    // THE ROADKILL RULING, as one row: one entry, and it may carry
                    // a fine. See the class docblock.
                    'roadkill' => [
                        'label' => 'roadkill',
                        'money' => 'fine',
                        'term_hours' => 168,
                        'fields' => ['Species', 'Sex', 'Age class', 'Road segment', 'Carcass disposition', 'Vehicle'],
                    ],
                    'natural-mortality' => [
                        'label' => 'natural mortality',
                        'money' => null,
                        'term_hours' => 168,
                        'fields' => ['Species', 'Sex', 'Age class', 'Condition', 'Carcass disposition'],
                    ],
                    'disease-die-off' => [
                        'label' => 'disease die-off',
                        'money' => null,
                        'term_hours' => 72,
                        'fields' => ['Species', 'Individuals affected', 'Signs', 'Samples taken', 'Carcass disposition'],
                    ],
                    'poisoning' => [
                        'label' => 'poisoning',
                        'money' => null,
                        'term_hours' => 168,
                        'fields' => ['Species', 'Individuals affected', 'Suspected agent', 'Samples taken', 'Carcass disposition'],
                    ],
                ],
            ],
        ];
    }
}
