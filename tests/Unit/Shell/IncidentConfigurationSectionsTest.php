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

namespace Uhifadhi\Incident\Tests\Unit\Shell;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Repository\IncidentSettingsRepository;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;
use Uhifadhi\Incident\Service\IncidentSettingsService;
use Uhifadhi\Incident\Shell\IncidentConfigurationSections;

/**
 * WHAT IS ON THE INCIDENTS CONFIGURE PAGE, as a declaration — without the page,
 * the shell or a database behind it.
 */
final class IncidentConfigurationSectionsTest extends TestCase
{
    private function declaration(?Request $request = null): IncidentConfigurationSections
    {
        $requests = new RequestStack();
        if (null !== $request) {
            $requests->push($request);
        }

        // REAL COLLABORATORS OVER AN EMPTY REGISTRY. None of them is reached on
        // the pages this test drives — the declaration answers before it queries.
        $registry = $this->createStub(ManagerRegistry::class);

        return new IncidentConfigurationSections(
            $requests,
            $this->createStub(AreaOfInterestRepository::class),
            new IncidentSettingsService(
                $this->createStub(EntityManagerInterface::class),
                new IncidentSettingsRepository($registry),
                'TZS',
            ),
            new TaxonomyKindRepository($registry),
            null,
        );
    }

    public function testItConfiguresItsOwnModule(): void
    {
        self::assertSame(IncidentModuleProvider::SLUG, $this->declaration()->slug());
    }

    /**
     * THE FOUR SECTIONS, in the words this module chose — and three of them keep
     * an address of their own, exactly as the settled design draws them.
     *
     * THE WORDS COME AFTER THE KINDS, because a list is the words a kind's blocks
     * ask for, and before Settings, which is numbers.
     */
    public function testItDeclaresTheLibraryTheKindsTheListsAndTheSettings(): void
    {
        $sections = $this->declaration()->sections();

        self::assertSame(
            [ConfigurationSection::WIDGETS, 'kinds', 'lists', ConfigurationSection::SETTINGS],
            array_map(static fn (ConfigurationSection $s): string => $s->id, $sections),
        );
        self::assertSame(
            ['Widget library', 'Incident kinds', 'Lists', 'Settings'],
            array_map(static fn (ConfigurationSection $s): string => $s->label, $sections),
        );
        self::assertFalse($sections[0]->isRendered());
        self::assertFalse($sections[1]->isRendered());
        self::assertFalse($sections[2]->isRendered());
        self::assertTrue($sections[3]->isRendered());
    }

    /**
     * A DECLARATION IS CONSULTED ON EVERY PAGE OF THE MODULE, so the one body
     * the shell renders is gathered only where it is drawn.
     */
    public function testTheSettingsBodyIsNotBuiltAwayFromTheConfigurePage(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'incident_dashboard');

        self::assertSame([], $this->declaration($request)->sections()[3]->variables);
    }

    /** A request that names no area gets an empty heading rather than a throw. */
    public function testAHeadingWithoutAnAreaIsEmpty(): void
    {
        self::assertSame('', $this->declaration()->heading());
    }

    /** Nothing this module calls a section says "register". */
    public function testNoSectionSaysRegister(): void
    {
        foreach ($this->declaration()->sections() as $section) {
            self::assertStringNotContainsStringIgnoringCase('register', $section->label);
            self::assertStringNotContainsStringIgnoringCase('register', $section->id);
        }
    }
}
