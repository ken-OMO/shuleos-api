<?php

namespace Tests\Feature;

use App\Http\Resources\TeacherPortalSafeResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tests\TestCase;

class TeacherPortalSafeResourceSerializationTest extends TestCase
{
    public function test_assignment_models_in_collections_keep_public_relation_names_without_model_internals(): void
    {
        $assignment = new class extends Model
        {
            protected $hidden = ['hidden_note'];

            protected $keyType = 'string';

            public $incrementing = false;
        };
        $assignment->setRawAttributes([
            'id' => 'assignment-test-id', 'school_id' => 'private-school-id',
            'teacher_id' => 'private-teacher-id', 'hidden_note' => 'model-hidden-value',
            'password_hash' => 'private-hash',
        ]);
        $area = new class extends Model {};
        $area->setRawAttributes(['learning_area_name' => 'Demo Mathematics', 'school_id' => 'private-school-id']);
        $grade = new class extends Model {};
        $grade->setRawAttributes(['grade_name' => 'Demo Grade']);
        $stream = new class extends Model {};
        $stream->setRawAttributes(['stream_name' => 'Demo Stream']);
        $assignment->setRelation('learningArea', $area);
        $assignment->setRelation('grade', $grade);
        $assignment->setRelation('stream', $stream);

        $data = (new TeacherPortalSafeResource(['assignments' => collect([$assignment])]))
            ->toArray(Request::create('/'));

        $this->assertSame([
            'id' => 'assignment-test-id',
            'learning_area' => ['learning_area_name' => 'Demo Mathematics'],
            'grade' => ['grade_name' => 'Demo Grade'],
            'stream' => ['stream_name' => 'Demo Stream'],
        ], $data['assignments'][0]);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['private-school-id', 'private-teacher-id', 'model-hidden-value', 'private-hash', 'attributes', 'original', 'fillable', 'connection'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_nested_objects_and_collections_are_sanitized_without_losing_zero_false_or_null(): void
    {
        $value = (object) [
            'name' => 'Safe name', 'user_id' => 'private-user-id',
            'nested' => (object) ['password_reset_token' => 'private-token', 'count' => 0, 'ready' => false],
        ];
        $data = (new TeacherPortalSafeResource([
            'items' => collect([$value, null, 0, false]),
            'time' => CarbonImmutable::parse('2026-01-01T00:00:00Z'),
        ]))->toArray(Request::create('/'));

        $this->assertSame([
            ['name' => 'Safe name', 'nested' => ['count' => 0, 'ready' => false]],
            null, 0, false,
        ], $data['items']);
        $this->assertSame('2026-01-01T00:00:00.000000Z', $data['time']);
    }

    public function test_unknown_objects_are_not_cast_to_internal_properties(): void
    {
        $object = new class
        {
            private string $secret = 'private-internal-value';
        };
        $data = (new TeacherPortalSafeResource(['unknown' => $object]))->toArray(Request::create('/'));
        $this->assertSame(['unknown' => null], $data);
    }
}
