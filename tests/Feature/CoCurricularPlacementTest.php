<?php

namespace Tests\Feature;

use App\Services\CoCurricular\CoCurricularPlacement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\TestCase;

class CoCurricularPlacementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_placement_is_school_scoped_and_replacement_preserves_history(): void
    {
        $school = SchoolBuilder::create(['lifecycle_state' => 'active']);
        $other = SchoolBuilder::create();
        $subscription = MainSubscriptionBuilder::activeFor($school->id);
        DB::table('subscription_packages')->where('id', $subscription->package_id)->update(['package_name' => 'Enterprise']);
        $grade = GradeBuilder::create($school);
        $learner = LearnerBuilder::create($school, $grade, StreamBuilder::create($school, $grade));
        $first = $this->group($school->id, 'Simba');
        $second = $this->group($school->id, 'Twiga');
        $foreign = $this->group($other->id, 'Blue');
        $service = app(CoCurricularPlacement::class);

        DB::transaction(fn () => $service->save($school->id, $learner->id, ['house' => $first]));
        try {
            DB::transaction(fn () => $service->save($school->id, $learner->id, ['house' => $foreign]));
            $this->fail('A different school’s group must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('co_curricular.house', $exception->errors());
        }

        $this->assertDatabaseHas('co_curricular_placements', ['learner_id' => $learner->id, 'group_id' => $first, 'ended_at' => null]);
        DB::transaction(fn () => $service->save($school->id, $learner->id, ['house' => $second]));
        $this->assertDatabaseHas('co_curricular_placements', ['learner_id' => $learner->id, 'group_id' => $second, 'ended_at' => null]);
        $this->assertTrue(DB::table('co_curricular_placements')->where('learner_id', $learner->id)
            ->where('group_id', $first)->whereNotNull('ended_at')->exists());
    }

    private function group(string $school, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('co_curricular_groups')->insert(['id' => $id, 'school_id' => $school, 'kind' => 'house', 'name' => $name]);

        return $id;
    }
}
