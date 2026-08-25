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

namespace UhifadhiLabs\Incident;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use UhifadhiLabs\Incident\DependencyInjection\IncidentConfiguration;
use UhifadhiLabs\Incident\Module\IncidentModuleProvider;

/**
 * Incidents — poaching, human–wildlife conflict (with fines and compensation),
 * unauthorized construction and roadkill: one record type, read by Protection
 * and Ecology alike through taxonomy subsets.
 *
 * This is the module's INFRASTRUCTURE only. The domain model (entities,
 * repositories, screens) lands after the incidents design is ruled on — the
 * design drives the data model, so nothing here guesses at it. What exists is
 * the seam: the bundle registers, its config is keyed under "incident:", its
 * entity directory is mapped, and its module reaches the host's catalogue.
 */
final class UhifadhiLabsIncidentBundle extends AbstractBundle
{
    /** Config lives under "incident:", not the class-derived "uhifadhi_labs_incident:". */
    protected string $extensionAlias = 'incident';

    public function configure(DefinitionConfigurator $definition): void
    {
        IncidentConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Zero-config persistence: the bundle maps its own entities, so hosts
        // never write a doctrine mappings block for incident_* tables. Wired
        // now, empty until the domain lands — an attribute driver over a
        // directory with no entities simply contributes no metadata.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiLabsIncident' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'UhifadhiLabs\\Incident\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php for
        // the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the host's
        // catalogue seed + module grid. The host tags every ModuleProviderInterface
        // via registerForAutoconfiguration, but that only fires for autoconfigured
        // services — and a reusable bundle doesn't autoconfigure — so the tag is
        // applied explicitly here.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'pressure';
        $services->set('incident.module_provider', IncidentModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module');

        // Dev tooling (seeders and the like) will hang off this flag, exactly as
        // patrol's does, so production never gets a command that writes invented
        // incidents. Nothing claims it yet — the parameter is here so the switch
        // exists the moment the domain does.
        $builder->setParameter('incident.dev_tools', true === ($config['dev_tools'] ?? false));
    }
}
