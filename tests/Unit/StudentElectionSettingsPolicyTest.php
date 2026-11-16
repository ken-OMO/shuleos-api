<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StudentElection\StudentElectionSettingsPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentElectionSettingsPolicyTest extends TestCase
{
    public function test_default_and_hidden_settings_remain_supported(): void
    {
        $policy = new StudentElectionSettingsPolicy;
        $policy->validate([]);
        $policy->validate(['results_visibility' => 'hidden']);
        $this->addToAssertionCount(2);
    }

    public function test_unsupported_and_null_legacy_inputs_are_rejected_explicitly(): void
    {
        $policy = new StudentElectionSettingsPolicy;
        foreach (['allow_self_nomination', 'require_candidate_approval', 'show_live_turnout'] as $field) {
            foreach ([true, false, null] as $value) {
                try {
                    $policy->validate([$field => $value]);
                    $this->fail('Legacy setting accepted.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey($field, $error->errors());
                }
            }
        }
        foreach (['after_close', 'after_approval', 'live_turnout_only', null] as $value) {
            try {
                $policy->validate(['results_visibility' => $value]);
                $this->fail('Unsupported visibility accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('results_visibility', $error->errors());
            }
        }
    }
}
