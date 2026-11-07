<?php

declare(strict_types=1);

namespace App\Services\Transport;

use DomainException;

/**
 * Journey ordering only.
 *
 * The event service must independently authenticate actors, check permissions,
 * lock authoritative journey state and validate current person authorizations.
 * This policy never establishes identity, permission or recipient eligibility.
 */
class TransportJourneyTransitionPolicy
{
    /**
     * Evidence arguments must be derived from validated server-side records.
     *
     * Successful receipt methods:
     * - authenticated_receiver: actual receiving User confirms their receipt;
     * - witnessed_verbal: recorder reports observing the recipient's receipt.
     *
     * A witnessed verbal report is never independent recipient authentication.
     */
    public function transition(
        string $direction,
        string $state,
        string $boundary,
        string $verification,
        string $receipt,
        ?string $failureReason = null,
    ): array {
        $rules = [
            'to_school' => [
                'pickup' => [
                    'expected', 'on_board', 'approved_releasing_person', 'transport_crew',
                ],
                'school_arrival' => [
                    'on_board', 'at_school', 'transport_crew', 'school_staff',
                ],
            ],
            'from_school' => [
                'school_departure' => [
                    'expected', 'on_board', 'school_staff', 'transport_crew',
                ],
                'destination_dropoff' => [
                    'on_board', 'at_destination', 'transport_crew', 'approved_recipient',
                ],
            ],
        ];

        if (! isset($rules[$direction][$boundary])) {
            throw new DomainException('Boundary does not match journey direction.');
        }

        [$requiredState, $nextState, $fromRole, $toRole] = $rules[$direction][$boundary];

        if ($state !== $requiredState) {
            throw new DomainException('Journey transition is out of sequence.');
        }

        if ($failureReason !== null) {
            if (! in_array($failureReason, [
                'person_unavailable',
                'person_unauthorized',
                'identity_not_verified',
                'receipt_not_obtained',
                'handover_refused',
            ], true)) {
                throw new DomainException('Unknown handover failure reason.');
            }

            // Failure never advances journey state or transfers custody.
            return [
                'boundary' => $boundary,
                'outcome' => 'failed',
                'previous_state' => $state,
                'state' => $state,
                'from_role' => $fromRole,
                'to_role' => $toRole,
                'custody_role' => $fromRole,
                'custody_transferred' => false,
                'receipt_state' => 'not_confirmed',
                'independently_confirmed' => false,
                'exception_required' => true,
                'failure_reason' => $failureReason,
            ];
        }

        if ($verification !== 'verified') {
            throw new DomainException('Successful handover requires verified people.');
        }

        if (! in_array($receipt, [
            'authenticated_receiver',
            'witnessed_verbal',
        ], true)) {
            throw new DomainException('Successful handover requires explicit receipt evidence.');
        }

        $authenticated = $receipt === 'authenticated_receiver';

        return [
            'boundary' => $boundary,
            'outcome' => 'recorded',
            'previous_state' => $state,
            'state' => $nextState,
            'from_role' => $fromRole,
            'to_role' => $toRole,
            'custody_role' => $toRole,
            'custody_transferred' => true,
            'receipt_state' => $authenticated ? 'authenticated' : 'verbally_reported',
            'independently_confirmed' => $authenticated,
            'exception_required' => false,
            'failure_reason' => null,
        ];
    }
}
