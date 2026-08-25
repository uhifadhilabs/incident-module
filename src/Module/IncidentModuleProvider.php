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

namespace UhifadhiLabs\Incident\Module;

use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * Declares the one module this bundle contributes — "Incidents": what happened
 * in an area, recorded once and read by every department that needs it.
 *
 * No entryRoute() yet. The module has no screens until the incidents design is
 * ruled on, so the host renders it through its generic module page; the day the
 * first incidents route lands, this returns that route name and the host links
 * straight to it.
 */
final class IncidentModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return 'incidents';
    }

    public function name(): string
    {
        return 'Incidents';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'Field incident reports';
    }

    public function icon(): string
    {
        return 'triangle-alert';
    }
}
