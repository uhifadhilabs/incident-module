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

namespace UhifadhiLabs\Incident\Workflow;

use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;
use UhifadhiLabs\Incident\Enum\IncidentTransitionEnum;

/**
 * THE DEFINITION — places, transitions, and the two rules that are not simply
 * "which place follows which".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THIS CLASS IS THE SEAM.
 * ══════════════════════════════════════════════════════════════════════════════
 * A platform WORKFLOW MODULE is on the roadmap: state machines and an audit trail
 * for jobs, areas, alerts — and incidents. When it lands, THIS class is what it
 * replaces, and nothing else in the bundle has to move. That is only true because
 * of three deliberate constraints, and a later change that breaks any of them
 * makes the swap a rewrite instead of a substitution:
 *
 *  1. **The definition is DATA, expressed here and nowhere else.** No other class
 *     in this bundle names a place-to-place move. Twig asks the incident for its
 *     status and asks {@see \UhifadhiLabs\Incident\Service\IncidentTransitionService}
 *     what is available; controllers name a transition by its enum value from the
 *     URL. Grep for `IncidentStatusEnum::Verified` outside this namespace and the
 *     entity's own columns and you will find rendering, never routing.
 *  2. **Guards are named, and each answers with a REASON rather than a boolean.**
 *     A workflow engine's guard-listener returns a blocked message; so does
 *     {@see guardFor()}. The UI needs the sentence anyway — the design's toolbar
 *     prints "Close — only reachable from resolved" — so the reason is not extra
 *     work done for a hypothetical future.
 *  3. **The marking is a single column** ({@see \UhifadhiLabs\Incident\Entity\Incident::getStatus()}),
 *     which is what Symfony's Workflow calls a single-state marking store. An
 *     incident is in exactly one place; nothing here needs a multiple-state
 *     workflow, and adopting one later would be a data migration, not a swap.
 *
 * The mapping to a Symfony `state_machine` is one-to-one: places are
 * {@see IncidentStatusEnum} values, transitions are {@see IncidentTransitionEnum}
 * values with `from`/`to` as declared, the marking store is the `status` property,
 * and {@see guardFor()} becomes two guard listeners.
 *
 * ── THE TWO RULES THAT ARE NOT ADJACENCY ──────────────────────────────────────
 *
 *  * **Nothing skips verification.** It falls out of the chain rather than being
 *    checked: there is simply no transition from `reported` to anywhere but
 *    `verified`.
 *  * **`resolve` requires the money settled or waived**, and only where the
 *    category carries money at all. An incident whose claim is still outstanding
 *    is not resolved, it is unpaid.
 *  * **`close` is reached BY TIME, never by a person** — {@see CLOSE_AFTER_DAYS}
 *    after resolution. It is a transition all the same, because the record must
 *    be able to say it is finished, and it is refused to every actor: the only
 *    caller that may make it is the clock.
 */
final class IncidentWorkflow
{
    /** The name a platform workflow module would register this definition under. */
    public const string NAME = 'incident';

    /** How long after resolution the clock closes an incident. The design states it on the rail. */
    public const int CLOSE_AFTER_DAYS = 30;

    /** Where a freshly filed incident starts. Every source lands here — there is no sixth place. */
    public static function initialPlace(): IncidentStatusEnum
    {
        return IncidentStatusEnum::Reported;
    }

    /**
     * @return list<IncidentStatusEnum>
     */
    public static function places(): array
    {
        return IncidentStatusEnum::ordered();
    }

    /**
     * @return list<IncidentTransitionEnum>
     */
    public static function transitions(): array
    {
        return IncidentTransitionEnum::cases();
    }

    /**
     * The moves whose `from` is this place — legality by ADJACENCY only. Whether a
     * guard lets one through is {@see guardFor()}'s question, and whether a person
     * may make it at all is the transition's own
     * ({@see IncidentTransitionEnum::isOfferedToPeople()}).
     *
     * @return list<IncidentTransitionEnum>
     */
    public static function transitionsFrom(IncidentStatusEnum $place): array
    {
        return array_values(array_filter(
            IncidentTransitionEnum::cases(),
            static fn (IncidentTransitionEnum $transition) => $transition->fromPlace() === $place,
        ));
    }

    /**
     * WHICH GUARD, IF ANY, GUARDS THIS MOVE — as a value, so the definition names
     * its guards the way a workflow configuration does and the service that runs
     * them holds no list of its own.
     */
    public static function guardFor(IncidentTransitionEnum $transition): ?IncidentGuardEnum
    {
        return match ($transition) {
            IncidentTransitionEnum::Resolve => IncidentGuardEnum::MoneySettledOrWaived,
            IncidentTransitionEnum::Close => IncidentGuardEnum::ClockOnly,
            IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond => null,
        };
    }
}
