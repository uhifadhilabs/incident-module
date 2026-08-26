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

namespace UhifadhiLabs\Incident\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\User;
use Uhifadhi\Entity\Zone;
use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Entity\IncidentSubcategory;
use UhifadhiLabs\Incident\Service\IncidentReportService;
use UhifadhiLabs\Incident\Service\IncidentTaxonomyInstaller;
use UhifadhiLabs\Incident\Tests\Integration\Fixtures\FixedPermissionVoter;

/**
 * THE SCREENS, THROUGH A REAL KERNEL. Every page below is fetched over HTTP
 * against a real PostGIS database with real security — the only way to prove a
 * module bundle's routes, templates and permissions actually work when installed,
 * rather than that its services return the right arrays.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var IncidentTaxonomyInstaller $installer */
        $installer = static::getContainer()->get('test_public.incident.taxonomy_installer');
        $installer->install();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * The area's UUID as a route needs it. The host entity's getter is nullable
     * (an unsaved area has none); everything here is persisted, so this is the
     * one place that says so.
     */
    protected function uuidOf(AreaOfInterest $area): string
    {
        $uuid = $area->getUuidString();
        self::assertNotNull($uuid, 'A persisted area always has a uuid.');

        return $uuid;
    }

    protected function anArea(string $name = 'Sample Area'): AreaOfInterest
    {
        $area = new AreaOfInterest();
        $area->setName($name);
        $area->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.6],[36.0,-3.6],[36.0,-2.8],[35.0,-2.8],[35.0,-3.6]]]]}');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    protected function aZone(AreaOfInterest $area, string $name): Zone
    {
        $zone = new Zone($area, $name);
        $zone->setGeom('{"type":"MultiPolygon","coordinates":[[[[35.0,-3.6],[35.5,-3.6],[35.5,-2.8],[35.0,-2.8],[35.0,-3.6]]]]}');
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    /** Somebody who may FILE and may not MOVE — the cheap half of the workflow. */
    protected function aReporter(): User
    {
        return $this->aUser(FixedPermissionVoter::REPORTER_EMAIL, 'Joseph', 'Mollel');
    }

    /** Somebody who may do both — the supervisor. */
    protected function aManager(): User
    {
        return $this->aUser(FixedPermissionVoter::MANAGER_EMAIL, 'Sara', 'Laizer');
    }

    protected function aUser(string $email, string $first, string $last): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            return $existing;
        }

        $user = new User();
        $user->setEmail($email)->setFirstName($first)->setLastName($last);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function subcategory(string $slug): IncidentSubcategory
    {
        $subcategory = $this->em->getRepository(IncidentSubcategory::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($subcategory, \sprintf('No sub-category "%s".', $slug));

        return $subcategory;
    }

    protected function anIncident(
        AreaOfInterest $area,
        string $subcategory = 'livestock-depredation',
        string $title = 'Lion killed four goats at Osinoni',
        ?User $reportedBy = null,
    ): Incident {
        /** @var IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');

        return $reports->file(
            area: $area,
            subcategory: $this->subcategory($subcategory),
            title: $title,
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: new \DateTimeImmutable(),
            reportedBy: $reportedBy,
        );
    }

    /**
     * The CSRF token for one incident's transitions, minted the way the page
     * mints it.
     *
     * Scraped off the RENDERED SURFACE, not minted from the token manager. That is
     * the point: it proves a page actually carries a token a client could use. A
     * test that minted its own would have passed while the status board posted
     * nothing and 403'd in every real browser.
     *
     * It is read from the `.i-trans` row rather than from a form's hidden input
     * because the form is not always there — a case file with no legal move
     * renders no button, and those are exactly the states whose refusals are most
     * worth testing.
     */
    protected function csrfFor(AreaOfInterest $area): string
    {
        $html = $this->client->request('GET', \sprintf('/areas/%s/modules/incidents', $this->uuidOf($area)))->html();
        preg_match('/data-incident-csrf="([^"]+)"/', $html, $matches);
        if (!isset($matches[1])) {
            self::fail('No surface rendered a transition token, so its board could never post one.');
        }

        return $matches[1];
    }

    /** The CSRF token a page rendered, read back out of it. */
    protected function tokenFrom(string $html, string $name = '_token'): string
    {
        preg_match(\sprintf('/name="%s" value="([^"]+)"/', preg_quote($name, '/')), $html, $matches);
        if (!isset($matches[1])) {
            self::fail('The page rendered no CSRF token, so its form could never be submitted.');
        }

        return $matches[1];
    }
}
