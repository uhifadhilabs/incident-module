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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;
use Uhifadhi\Incident\Tests\Fixtures\Account\User;

/**
 * Symfony-standard kernel testing: KernelTestCase + KERNEL_CLASS (phpunit.dist.xml)
 * booting TestKernel with debug=true, so the container self-invalidates when test
 * config changes. It talks to the REAL PostGIS database and rebuilds the schema
 * per test, so every assertion is about what was actually stored — a module whose
 * spatial columns were only ever asserted against a mock would be a module nobody
 * has proved persists.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /** An area to file incidents in, with a boundary the map can draw. */
    protected function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name);
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.6],[36.0,-3.6],[36.0,-2.8],[35.0,-2.8],[35.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /**
     * A zone inside that area — a real polygon, so the point-in-polygon lookup is
     * exercised against PostGIS rather than assumed.
     */
    protected function aZone(AreaOfInterest $area, string $name, float $west = 35.0, float $east = 35.5): Zone
    {
        $zone = new Zone($area, $name);
        $zone->setGeom(\sprintf(
            '{"type":"MultiPolygon","coordinates":[[[[%1$F,-3.6],[%2$F,-3.6],[%2$F,-2.8],[%1$F,-2.8],[%1$F,-3.6]]]]}',
            $west,
            $east,
        ));
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    protected function aUser(string $email, string $first = 'J', string $last = 'Mollel', ?Department $department = null): User
    {
        $user = new User();
        $user->setEmail($email)->setFirstName($first)->setLastName($last);

        if (null !== $department) {
            $position = new Position();
            $position->setName('Ranger')->setDepartment($department);
            $this->em->persist($position);
            $user->setPosition($position);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function aDepartment(string $name = 'Protection Service'): Department
    {
        $department = new Department();
        $department->setName($name);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /** The shipped taxonomy, installed — most tests need something to file against. */
    protected function installTaxonomy(): void
    {
        /** @var IncidentTaxonomyInstaller $installer */
        $installer = static::getContainer()->get('test_public.incident.taxonomy_installer');
        $installer->install();
    }

    protected function subcategory(string $slug): IncidentSubcategory
    {
        $subcategory = $this->em->getRepository(IncidentSubcategory::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($subcategory, \sprintf('No sub-category "%s" — was the taxonomy installed?', $slug));

        return $subcategory;
    }

    /**
     * A filed incident, through the module's own door — never by writing the
     * columns, so the tests can never produce a record the product could not.
     */
    protected function anIncident(
        AreaOfInterest $area,
        string $subcategory = 'livestock-depredation',
        string $title = 'Lion killed four goats at Osinoni',
        ?\DateTimeImmutable $at = null,
        ?User $reportedBy = null,
    ): Incident {
        /** @var \Uhifadhi\Incident\Service\IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');

        return $reports->file(
            area: $area,
            subcategory: $this->subcategory($subcategory),
            title: $title,
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: $at ?? new \DateTimeImmutable('2026-08-20 05:41:00'),
            reportedBy: $reportedBy,
        );
    }

    /** Fetch a private bundle service through the test kernel's public aliases. */
    protected function service(string $id): object
    {
        return static::getContainer()->get('test_public.'.$id);
    }
}
