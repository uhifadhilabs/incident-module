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

namespace UhifadhiLabs\Incident\Service;

use Doctrine\ORM\EntityManagerInterface;
use UhifadhiLabs\Incident\Entity\IncidentCategory;
use UhifadhiLabs\Incident\Entity\IncidentSubcategory;
use UhifadhiLabs\Incident\Enum\MoneyDirectionEnum;
use UhifadhiLabs\Incident\Model\IncidentTaxonomy;
use UhifadhiLabs\Incident\Repository\IncidentCategoryRepository;
use UhifadhiLabs\Incident\Repository\IncidentSubcategoryRepository;

/**
 * PUTS THE TAXONOMY IN THE DATABASE, and can be run again tomorrow.
 *
 * The classification is DATA — a deployment configures its own tree under
 * `incident.taxonomy`, and this writes whatever it finds. What it must never do
 * is fight the people using it, so:
 *
 *  - **Idempotent.** Running it twice changes nothing the second time.
 *  - **Non-destructive.** A category or sub-category that has left the
 *    configuration is LEFT ALONE, never deleted — incidents are filed against it
 *    and deleting the row would orphan case files. Retiring a kind of incident is
 *    an admin decision with consequences, not a side effect of editing a YAML
 *    file.
 *  - **It re-states, it does not re-invent.** Labels, colours, leads, terms and
 *    field sets are brought back into line with the configuration on every run,
 *    because those ARE the configuration. Slugs are the identity and are never
 *    rewritten.
 *
 * A HOST MUST RUN THIS ONCE (`incidents:taxonomy:sync`). It is not dev tooling:
 * without a taxonomy there is nothing to file an incident against, so the command
 * is registered in every environment, unlike the demo seeder.
 */
final readonly class IncidentTaxonomyInstaller
{
    /**
     * @param array<string, mixed> $configured the deployment's tree, or [] to install
     *                                         the one the module ships with
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentCategoryRepository $categories,
        private IncidentSubcategoryRepository $subcategories,
        private array $configured = [],
    ) {
    }

    /**
     * Write the tree. Answers what it did, so a command can print it and a test
     * can prove the second run was a no-op.
     *
     * @return array{categories_created: int, categories_updated: int, subcategories_created: int, subcategories_updated: int}
     */
    public function install(): array
    {
        $tally = ['categories_created' => 0, 'categories_updated' => 0, 'subcategories_created' => 0, 'subcategories_updated' => 0];

        $position = 0;
        foreach ($this->tree() as $slug => $definition) {
            $category = $this->categories->findOneBySlug($slug);
            if (null === $category) {
                $category = new IncidentCategory($slug, $definition['label'], $definition['colour']);
                $this->entityManager->persist($category);
                ++$tally['categories_created'];
            } else {
                ++$tally['categories_updated'];
            }

            $category
                ->setLabel($definition['label'])
                ->setColourKey($definition['colour'])
                ->setLeads($definition['leads'])
                ->setPosition($position++);

            $subPosition = 0;
            foreach ($definition['subcategories'] as $subSlug => $sub) {
                $subcategory = $this->subcategories->findOneBySlug($subSlug);
                if (null === $subcategory) {
                    $subcategory = new IncidentSubcategory($category, $subSlug, $sub['label']);
                    $this->entityManager->persist($subcategory);
                    ++$tally['subcategories_created'];
                } else {
                    ++$tally['subcategories_updated'];
                }

                $subcategory
                    ->setLabel($sub['label'])
                    ->setMoneyDirection(null === $sub['money'] ? null : MoneyDirectionEnum::from($sub['money']))
                    ->setTermHours($sub['term_hours'])
                    ->setFieldSet(self::fieldSet($sub['fields']))
                    ->setPosition($subPosition++);
            }
        }

        $this->entityManager->flush();

        return $tally;
    }

    /**
     * The deployment's tree, or the shipped one. A configured tree REPLACES the
     * default rather than merging with it: a half-overridden classification scheme
     * is nobody's scheme, and an admin who removes a category from their config
     * means it.
     *
     * @return array<string, array{
     *     label: string,
     *     colour: string,
     *     leads: list<string>,
     *     subcategories: array<string, array{label: string, money: string|null, term_hours: int, fields: list<string>}>
     * }>
     */
    private function tree(): array
    {
        if ([] === $this->configured) {
            return IncidentTaxonomy::shipped();
        }

        /**
         * The configuration tree is validated by
         * {@see \UhifadhiLabs\Incident\DependencyInjection\IncidentConfiguration},
         * so by the time it reaches here it has the shape below.
         *
         * @var array<string, array{label: string, colour: string, leads: list<string>, subcategories: array<string, array{label: string, money: string|null, term_hours: int, fields: list<string>}>}> $configured
         */
        $configured = $this->configured;

        return $configured;
    }

    /**
     * A field set as the entity stores it: the label a form prints, and the key
     * an answer is stored under.
     *
     * THE KEY IS THE LABEL, SLUGGED — so renaming a field IS renaming the field,
     * and answers recorded under the old wording stop being asked for. That is the
     * honest behaviour: "Livestock lost" becoming "Animals lost" is a change of
     * question, and silently carrying the old answers under the new one would put
     * words in a witness's mouth. A deployment that wants a pure re-wording keeps
     * the label and edits the printed copy nowhere else.
     *
     * @param list<string> $labels
     *
     * @return list<array{key: string, label: string}>
     */
    private static function fieldSet(array $labels): array
    {
        $fields = [];
        foreach ($labels as $label) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $label));
            $fields[] = ['key' => trim($key, '_'), 'label' => $label];
        }

        return $fields;
    }
}
