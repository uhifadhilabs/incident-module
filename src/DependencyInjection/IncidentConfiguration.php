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

namespace UhifadhiLabs\Incident\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * The bundle's semantic configuration — how a host configures the module in
 * config/packages/incident.yaml:
 *
 *   incident:
 *     module_category: pressure   # catalogue category for the tile
 *     dev_tools: false            # dev-only commands (when@dev / when@test)
 *     currency: TZS               # what money on an incident is denominated in
 *     taxonomy:                   # OPTIONAL — omit to install the shipped four × sixteen
 *       poaching:
 *         label: 'Poaching & wildlife crime'
 *         colour: poach
 *         leads: ['Protection Service']
 *         subcategories:
 *           snaring:
 *             label: snaring
 *             money: fine         # fine | compensation | ~ (this kind carries none)
 *             term_hours: 72
 *             fields: ['Species', 'Snares lifted']
 *
 * THE TAXONOMY IS DEPLOYMENT VOCABULARY. It is validated here and installed by
 * `incidents:taxonomy:sync`; leaving it out installs the tree the design's
 * reference card draws ({@see \UhifadhiLabs\Incident\Model\IncidentTaxonomy}).
 * A configured tree REPLACES the default rather than merging with it — a
 * half-overridden classification scheme is nobody's scheme.
 *
 * The tree is closed, so an invented key fails loudly rather than being ignored.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure().
 */
final class IncidentConfiguration
{
    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The incident root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('module_category')
                    ->info('Catalogue category the Incidents module is filed under in each area.')
                    ->defaultValue('pressure')->cannotBeEmpty()
                ->end()
                ->booleanNode('dev_tools')
                    ->info('Register dev-only tooling (seeders, fixtures). The recipe enables this via when@dev/when@test.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('currency')
                    ->info('ISO code the money on an incident is denominated in. One currency per deployment: an area does not collect fines in two.')
                    ->defaultValue('TZS')->cannotBeEmpty()
                ->end()
                ->append(self::taxonomyNode())
            ->end()
        ;
    }

    /**
     * The classification tree. Empty by default and meaning "install the one the
     * module ships with" — which is why it is not `->defaultValue()`d to the
     * shipped tree here: a default that large in the container would be dumped
     * into every cached configuration for no gain, and the installer already knows
     * where to find it.
     */
    private static function taxonomyNode(): ArrayNodeDefinition
    {
        $taxonomy = new ArrayNodeDefinition('taxonomy');

        $taxonomy
            ->info('The kinds of incident this deployment records. Omit to install the shipped four kinds and sixteen sub-categories.')
            ->useAttributeAsKey('slug')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('colour')
                        ->info('One of the hues incidents.css declares: poach, hwc, comp, mort. A colour the stylesheet has never heard of renders as an invisible mark on a map.')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->arrayNode('leads')
                        ->info('The departments whose lens puts this kind first. ORDERING ONLY — it can never gate who may read a row.')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->arrayNode('subcategories')
                        ->useAttributeAsKey('slug')
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                                ->enumNode('money')
                                    ->info('Which way money runs on this kind, or null for one that carries none — in which case the money block is ABSENT from the form, not greyed out.')
                                    ->values(['fine', 'compensation', null])
                                    ->defaultNull()
                                ->end()
                                ->integerNode('term_hours')
                                    ->info('The term THIS kind promises. A human injury is 72 h and a construction notice is 14 days; one global SLA would be a lie about both.')
                                    ->min(1)->defaultValue(72)
                                ->end()
                                ->arrayNode('fields')
                                    ->info('The fields this kind of incident asks for, in the order the form draws them.')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $taxonomy;
    }
}
