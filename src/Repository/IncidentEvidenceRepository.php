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

namespace UhifadhiLabs\Incident\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use UhifadhiLabs\Incident\Entity\IncidentEvidence;

/**
 * The query surface over photographs and documents attached to incidents.
 *
 * Deliberately thin: everything a screen asks about an incident it asks through
 * {@see IncidentRepository}, because a dashboard question is always a question
 * about incidents and only incidentally about their parts.
 *
 * @extends ServiceEntityRepository<IncidentEvidence>
 */
final class IncidentEvidenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentEvidence::class);
    }
}
