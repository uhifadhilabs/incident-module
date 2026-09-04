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

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentLink;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Tests\Fixtures\Account\User;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * EVERY PERSON ON AN INCIDENT IS POINTED AT THROUGH THE CONTRACT.
 *
 * Five columns name somebody: who reported it, who it is assigned to, who acted
 * on the event, who linked it to another, and the team member standing behind a
 * party to it. None of them may name an account CLASS. The account belongs to
 * whichever module an installation gets its team from, and a module that
 * type-hinted that class would be a module you cannot install without it — so
 * the association is declared against {@see UserInterface} and the installation
 * resolves it with one line of doctrine config.
 *
 * The test asserts both halves, because either alone proves nothing: the
 * ATTRIBUTE says the module declared the contract, and the METADATA says the
 * resolution the installation wrote actually fired and produced a real table to
 * key against.
 */
final class UserContractTest extends IntegrationTestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function personAssociations(): iterable
    {
        yield 'reporter' => [Incident::class, 'reportedBy'];
        yield 'responder' => [Incident::class, 'assignedTo'];
        yield 'event actor' => [IncidentEvent::class, 'actor'];
        yield 'link author' => [IncidentLink::class, 'linkedBy'];
        yield 'party account' => [IncidentParty::class, 'user'];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('personAssociations')]
    public function testThePersonIsDeclaredAgainstTheContract(string $entity, string $property): void
    {
        $attributes = new \ReflectionProperty($entity, $property)->getAttributes(ORM\ManyToOne::class);

        self::assertCount(1, $attributes, $entity.'::$'.$property.' is not a ManyToOne.');
        self::assertSame(UserInterface::class, $attributes[0]->newInstance()->targetEntity);
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('personAssociations')]
    public function testTheContractIsResolvedToTheInstallationsAccountClass(string $entity, string $property): void
    {
        $association = $this->em->getClassMetadata($entity)->getAssociationMapping($property);

        self::assertSame(User::class, $association->targetEntity);
        self::assertNotSame(UserInterface::class, $association->targetEntity);
    }

    /**
     * AN INCIDENT OUTLIVES THE ACCOUNTS ON IT. Removing somebody from the team
     * does not un-happen what they reported, so the foreign key sets the column
     * null and the row stays — which is also why every one of these records
     * keeps the person's NAME beside the relation. The guarantee is the
     * database's, so it holds for a DELETE written by hand.
     */
    public function testRemovingAnAccountLeavesTheRecordsThatNamedIt(): void
    {
        $sql = implode("\n", new SchemaTool($this->em)->getCreateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()));

        self::assertSame(
            5,
            substr_count($sql, 'REFERENCES "user" (id) ON DELETE SET NULL'),
            'Every person column must survive the account being deleted.',
        );
    }
}
