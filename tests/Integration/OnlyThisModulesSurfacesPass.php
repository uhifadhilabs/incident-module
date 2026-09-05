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

namespace Uhifadhi\Incident\Tests\Integration;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Uhifadhi\Incident\Widget\IncidentWidgets;
use Uhifadhi\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE SUITE DECLARES THIS MODULE'S SURFACES AND NOBODY ELSE'S.
 *
 * {@see TestKernel} boots uhifadhi/team-module for the account class every
 * stored layout is keyed by, and uhifadhi/area-module for the place an incident
 * happens in. Both are modules with dashboards of their own, so booting them
 * tags their surfaces into the widget registry — and every surface either adds
 * or renames would otherwise rewrite the expected value of a test about THIS
 * bundle. That is a dependency's release notes deciding whether this suite is
 * green.
 *
 * So the tag is cleared off everything outside this module's own namespace. The
 * rule is by namespace, not by service id: a surface team or area ships tomorrow
 * is excluded for the same reason as the ones they ship today, and the assertion
 * that the registry holds the incidents dashboard stays an assertion about
 * incidents rather than about a version number.
 *
 * Copied in discipline from uhifadhi/widget-module's own suite, which needs the
 * same isolation for the same reason and names it OnlyThisSuitesSurfacesPass.
 */
final class OnlyThisModulesSurfacesPass implements CompilerPassInterface
{
    /** Everything this module declares lives beside {@see IncidentWidgets}. */
    private const string OURS = 'Uhifadhi\\Incident\\Widget\\';

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds(WidgetSurfaceInterface::TAG)) as $id) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();
            $resolved = null === $class ? null : $container->getParameterBag()->resolveValue($class);

            if (!\is_string($resolved) || !str_starts_with($resolved, self::OURS)) {
                $definition->clearTag(WidgetSurfaceInterface::TAG);
            }
        }
    }
}
