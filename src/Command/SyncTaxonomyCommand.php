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

namespace UhifadhiLabs\Incident\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UhifadhiLabs\Incident\Service\IncidentTaxonomyInstaller;

/**
 * PUTS THE KINDS OF INCIDENT IN THE DATABASE — the one host step that is not
 * automatic.
 *
 * NOT DEV TOOLING. Without a taxonomy there is nothing to file an incident
 * against, so this is registered in every environment, unlike the demo seeder.
 * A host runs it once on install, and again whenever it edits
 * `incident.taxonomy`.
 *
 * Idempotent and non-destructive: running it twice changes nothing the second
 * time, and a kind of incident that has left the configuration is LEFT ALONE
 * rather than deleted — case files are filed against it, and retiring a category
 * is an admin decision with consequences, not a side effect of editing YAML.
 */
#[AsCommand(
    name: 'incidents:taxonomy:sync',
    description: 'Install or re-state the incident categories and sub-categories this deployment records against (idempotent).',
)]
final class SyncTaxonomyCommand extends Command
{
    public function __construct(
        private readonly IncidentTaxonomyInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Incident taxonomy');

        $tally = $this->installer->install();

        $io->definitionList(
            ['kinds added' => (string) $tally['categories_created']],
            ['kinds re-stated' => (string) $tally['categories_updated']],
            ['sub-categories added' => (string) $tally['subcategories_created']],
            ['sub-categories re-stated' => (string) $tally['subcategories_updated']],
        );

        $io->success('The taxonomy matches the configuration. Nothing was deleted.');

        return Command::SUCCESS;
    }
}
