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

namespace UhifadhiLabs\Incident\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as DoctrineBundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UhifadhiLabs\Incident\UhifadhiLabsIncidentBundle;

/**
 * The smoke test: registering the bundle in a real kernel compiles a real
 * container. Everything else in this repo rides on that.
 */
final class BundleBootTest extends KernelTestCase
{
    public function testTheBundleBootsInAHostKernel(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('UhifadhiLabsIncidentBundle', $kernel->getBundles());
        self::assertInstanceOf(
            UhifadhiLabsIncidentBundle::class,
            $kernel->getBundle('UhifadhiLabsIncidentBundle'),
        );
    }

    /**
     * Config lives under "incident:", not the class-derived
     * "uhifadhi_labs_incident:" — the alias is part of the host contract.
     */
    public function testItsConfigurationIsKeyedByTheIncidentsAlias(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('incident', $kernel->getBundle('UhifadhiLabsIncidentBundle')
            ->getContainerExtension()?->getAlias());
    }

    /**
     * Zero-config persistence: the bundle maps its own entity directory, so a
     * host never writes a doctrine mappings block for incident_* tables — and
     * the table names are part of the host contract, because a host's migration
     * diff is what installs them.
     */
    public function testItMapsItsOwnEntityDirectory(): void
    {
        self::bootKernel();

        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        /** @var EntityManagerInterface $em */
        $em = $doctrine->getManager();
        $driver = $em->getConfiguration()->getMetadataDriverImpl();
        // DoctrineBundle decorates the chain (custom id-generator support);
        // the namespace registry lives on the chain underneath.
        if ($driver instanceof DoctrineBundleMappingDriver) {
            $driver = $driver->getDriver();
        }

        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertArrayHasKey('UhifadhiLabs\Incident\Entity', $driver->getDrivers());

        // The eight tables the module owns, mapped without a line of host config.
        $mapped = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (str_starts_with($metadata->getName(), 'UhifadhiLabs\\Incident\\Entity\\')) {
                $mapped[] = $metadata->getTableName();
            }
        }
        sort($mapped);

        self::assertSame([
            'incident',
            'incident_category',
            'incident_event',
            'incident_evidence',
            'incident_link',
            'incident_money',
            'incident_party',
            'incident_subcategory',
        ], $mapped);
    }

    protected function tearDown(): void
    {
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
}
