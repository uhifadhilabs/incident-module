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

namespace Uhifadhi\Incident\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\VariableNodeDefinition;

/**
 * The bundle's semantic configuration — how a host configures the module in
 * config/packages/incident.yaml:
 *
 *   incident:
 *     module_category: operations   # catalogue category for the tile
 *     currency: TZS                 # what money on an incident is denominated in
 *
 * THE CLASSIFICATION IS NOT CONFIGURATION. It was once a `taxonomy` tree here,
 * installed installation-wide by a command; it is now each AREA's own, written in
 * the Incident kinds editor and stored per area. A configuration file that still
 * carries `taxonomy` is REFUSED rather than ignored, with a sentence saying where
 * those words live now — because silently dropping somebody's classification
 * scheme is how an installation discovers on the first filing screen that its
 * vocabulary is gone.
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
                    // OPERATIONS: filing the incident register under 'pressure'
                    // said the module MEASURES human pressure on the ecosystem.
                    // It records the work of answering it, which is a different
                    // thing. A deployment may still override it.
                    ->info('Catalogue category the Incidents module is filed under in each area.')
                    ->defaultValue('operations')->cannotBeEmpty()
                ->end()
                ->scalarNode('currency')
                    ->info('ISO code the money on an incident is denominated in. One currency per deployment: an area does not collect fines in two.')
                    ->defaultValue('TZS')->cannotBeEmpty()
                ->end()
                ->append(self::retiredTaxonomyNode())
            ->end()
        ;
    }

    /**
     * THE RETIRED TREE, KEPT ONLY TO REFUSE IT. Dropping the key outright would
     * make an installation that still carries one fail with "Unrecognized option
     * \"taxonomy\"", which says what is wrong and not one word about what to do.
     * This says both, once, at container-build time — before anybody reaches a
     * filing screen and finds their vocabulary gone.
     */
    private static function retiredTaxonomyNode(): VariableNodeDefinition
    {
        $taxonomy = new VariableNodeDefinition('taxonomy');

        $taxonomy
            ->info('Retired. Kinds of incident are now each area\'s own and are written in the Incident kinds editor.')
            ->defaultNull()
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => null !== $value && [] !== $value)
                ->thenInvalid('The "incident.taxonomy" tree has been retired: kinds of incident are each AREA\'s own now, not the installation\'s. Remove the key and write this area\'s kinds and sub-categories in the Incident kinds editor (Modules → Incidents → Incident kinds), where their colour, money direction, term and fields are edited too. Areas that had filed incidents were given their own copy of the words they were using by the module\'s migration.')
            ->end()
        ;

        return $taxonomy;
    }
}
