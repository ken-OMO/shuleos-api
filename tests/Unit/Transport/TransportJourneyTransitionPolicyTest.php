<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Services\Transport\TransportJourneyTransitionPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

class TransportJourneyTransitionPolicyTest extends TestCase
{
    public function test_inbound_pickup_then_school_arrival_preserves_custody_boundaries(): void
    {
        $policy = new TransportJourneyTransitionPolicy;

        $pickup = $policy->transition(
            'to_school', 'expected', 'pickup', 'verified', 'authenticated_receiver'
        );
        $this->assertSame('approved_releasing_person', $pickup['from_role']);
        $this->assertSame('transport_crew', $pickup['to_role']);
        $this->assertSame('transport_crew', $pickup['custody_role']);
        $this->assertSame('on_board', $pickup['state']);

        $arrival = $policy->transition(
            'to_school', $pickup['state'], 'school_arrival',
            'verified', 'authenticated_receiver'
        );
        $this->assertSame('transport_crew', $arrival['from_role']);
        $this->assertSame('school_staff', $arrival['to_role']);
        $this->assertSame('school_staff', $arrival['custody_role']);
        $this->assertSame('at_school', $arrival['state']);
    }

    public function test_outbound_departure_then_dropoff_preserves_custody_boundaries(): void
    {
        $policy = new TransportJourneyTransitionPolicy;

        $departure = $policy->transition(
            'from_school', 'expected', 'school_departure',
            'verified', 'authenticated_receiver'
        );
        $this->assertSame('school_staff', $departure['from_role']);
        $this->assertSame('transport_crew', $departure['to_role']);
        $this->assertSame('on_board', $departure['state']);

        $dropoff = $policy->transition(
            'from_school', $departure['state'], 'destination_dropoff',
            'verified', 'witnessed_verbal'
        );
        $this->assertSame('transport_crew', $dropoff['from_role']);
        $this->assertSame('approved_recipient', $dropoff['to_role']);
        $this->assertSame('approved_recipient', $dropoff['custody_role']);
        $this->assertSame('at_destination', $dropoff['state']);
        $this->assertSame('verbally_reported', $dropoff['receipt_state']);
        $this->assertFalse($dropoff['independently_confirmed']);
    }

    public function test_all_failed_boundaries_retain_state_and_previous_custody(): void
    {
        $policy = new TransportJourneyTransitionPolicy;

        foreach ([
            ['to_school', 'expected', 'pickup', 'approved_releasing_person'],
            ['to_school', 'on_board', 'school_arrival', 'transport_crew'],
            ['from_school', 'expected', 'school_departure', 'school_staff'],
            ['from_school', 'on_board', 'destination_dropoff', 'transport_crew'],
        ] as [$direction, $state, $boundary, $custodian]) {
            foreach ([
                'person_unavailable',
                'person_unauthorized',
                'identity_not_verified',
                'receipt_not_obtained',
                'handover_refused',
            ] as $reason) {
                $result = $policy->transition(
                    $direction, $state, $boundary, 'unverified', 'none', $reason
                );

                $this->assertSame($state, $result['state']);
                $this->assertSame($custodian, $result['custody_role']);
                $this->assertFalse($result['custody_transferred']);
                $this->assertTrue($result['exception_required']);
                $this->assertSame($reason, $result['failure_reason']);
                $this->assertSame('not_confirmed', $result['receipt_state']);
                $this->assertFalse($result['independently_confirmed']);
            }
        }
    }

    public function test_failed_destination_handover_allows_later_valid_handover(): void
    {
        $policy = new TransportJourneyTransitionPolicy;

        $failed = $policy->transition(
            'from_school', 'on_board', 'destination_dropoff',
            'unverified', 'none', 'person_unavailable'
        );
        $this->assertSame('on_board', $failed['state']);

        $completed = $policy->transition(
            'from_school', $failed['state'], 'destination_dropoff',
            'verified', 'witnessed_verbal'
        );
        $this->assertSame('at_destination', $completed['state']);

        // Resolving the earlier exception requires a separate preserved record.
        $this->assertTrue($failed['exception_required']);
        $this->assertSame('person_unavailable', $failed['failure_reason']);
    }

    public function test_cross_direction_boundaries_are_rejected(): void
    {
        foreach ([
            ['to_school', 'school_departure'],
            ['to_school', 'destination_dropoff'],
            ['from_school', 'pickup'],
            ['from_school', 'school_arrival'],
            ['unknown', 'pickup'],
            ['to_school', 'unknown'],
        ] as [$direction, $boundary]) {
            $this->rejected(fn () => (new TransportJourneyTransitionPolicy)->transition(
                $direction, 'expected', $boundary, 'verified', 'authenticated_receiver'
            ));
        }
    }

    public function test_skipped_duplicate_and_terminal_transitions_are_rejected(): void
    {
        foreach ([
            ['to_school', 'expected', 'school_arrival'],
            ['to_school', 'on_board', 'pickup'],
            ['to_school', 'at_school', 'school_arrival'],
            ['from_school', 'expected', 'destination_dropoff'],
            ['from_school', 'on_board', 'school_departure'],
            ['from_school', 'at_destination', 'destination_dropoff'],
        ] as [$direction, $state, $boundary]) {
            $this->rejected(fn () => (new TransportJourneyTransitionPolicy)->transition(
                $direction, $state, $boundary, 'verified', 'authenticated_receiver'
            ));
        }
    }

    public function test_verification_and_receipt_are_both_required_for_success(): void
    {
        foreach ([
            ['unverified', 'authenticated_receiver'],
            ['verified', 'none'],
            ['verified', 'selected_name'],
            ['verified', 'signature_claim'],
        ] as [$verification, $receipt]) {
            $this->rejected(fn () => (new TransportJourneyTransitionPolicy)->transition(
                'from_school', 'on_board', 'destination_dropoff',
                $verification, $receipt
            ));
        }
    }

    public function test_reported_receipt_does_not_claim_authenticated_confirmation(): void
    {
        $policy = new TransportJourneyTransitionPolicy;

        $reported = $policy->transition(
            'to_school', 'on_board', 'school_arrival',
            'verified', 'witnessed_verbal'
        );
        $authenticated = $policy->transition(
            'to_school', 'on_board', 'school_arrival',
            'verified', 'authenticated_receiver'
        );

        $this->assertSame('verbally_reported', $reported['receipt_state']);
        $this->assertFalse($reported['independently_confirmed']);
        $this->assertSame('authenticated', $authenticated['receipt_state']);
        $this->assertTrue($authenticated['independently_confirmed']);
    }

    public function test_unknown_failure_reason_is_rejected(): void
    {
        $this->rejected(fn () => (new TransportJourneyTransitionPolicy)->transition(
            'from_school', 'on_board', 'destination_dropoff',
            'unverified', 'none', 'silently_delivered'
        ));
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid journey transition was accepted.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
