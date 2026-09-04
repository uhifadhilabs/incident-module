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

namespace Uhifadhi\Incident\Module;

use Uhifadhi\Incident\Controller\IncidentDetailController;
use Uhifadhi\Incident\Controller\IncidentReportController;
use Uhifadhi\ModuleContracts\ModulePermission;
use Uhifadhi\ModuleContracts\ModuleProviderInterface;
use Uhifadhi\ModuleContracts\ModuleProviderTrait;

/**
 * Declares the one module this bundle contributes — "Incidents": what happened
 * in an area, recorded once and read by every department that needs it.
 *
 * It owns its screens ({@see entryRoute()}), so the host links straight to the
 * incidents dashboard rather than rendering the module through its generic page.
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

    public function entryRoute(): string
    {
        return 'incident_dashboard';
    }

    /**
     * DECLARED, NEVER GRANTED: the host folds these into its permission catalogue
     * for admins to assign, and they vanish with the module on uninstall. Each
     * value is the exact attribute a route checks.
     *
     * TWO TIERS, and the split is the design's own economics — "a report is cheap
     * and a verification is expensive":
     *
     *  - **Record** files an incident. The design's IN·R1 card says filing should
     *    need no permission of its own, so a deployment that agrees grants this to
     *    everyone who can reach the module; it exists because a POST that creates
     *    a record must be guarded by SOMETHING a host can see and assign.
     *  - **Manage** moves an incident through the workflow and settles its money.
     *    This is the expensive half, and it is the one worth withholding.
     *
     * There is deliberately NO "view" permission. Reading incidents is reading the
     * module, and the module's charter forbids anything that lets one department
     * hide a row from another — a view permission is exactly the tool somebody
     * would eventually use to do it.
     *
     * EACH TIER CARRIES A SENTENCE, printed under the name in the host's matrix.
     * The two names differ by one word, and the words alone do not tell an
     * administrator that the second one settles money; the sentences do.
     *
     * @return list<ModulePermission>
     */
    public function permissions(): array
    {
        return [
            new ModulePermission(
                IncidentReportController::RECORD_PERMISSION,
                'Incidents',
                'Record',
                'File an incident: what happened, where, and the evidence for it.',
            ),
            new ModulePermission(
                IncidentDetailController::MANAGE_PERMISSION,
                'Incidents',
                'Manage',
                'Move an incident through verification, response and closure, and settle the fines and compensation on it.',
            ),
        ];
    }
}
