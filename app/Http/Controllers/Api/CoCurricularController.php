<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\CoCurricular\CoCurricularPlacement;
use App\Services\School\SchoolBusinessWriteAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CoCurricularController extends BaseApiController
{
    public function __construct(private CoCurricularPlacement $placements, private SchoolBusinessWriteAccess $writes) {}

    public function groups(Request $request)
    {
        $schoolId = $this->school($request);

        return $this->success(DB::table('co_curricular_groups')->where('school_id', $schoolId)
            ->orderBy('kind')->orderBy('name')->get());
    }

    public function saveGroup(Request $request, ?string $group = null)
    {
        $schoolId = $this->school($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'kind' => $group === null ? ['required', Rule::in(CoCurricularPlacement::KINDS)] : ['prohibited'],
        ]);

        try {
            $id = $this->writes->run($schoolId, function () use ($schoolId, $group, $data) {
                $this->placements->authorize($schoolId);
                if ($group !== null) {
                    $updated = DB::table('co_curricular_groups')->where('school_id', $schoolId)
                        ->where('id', $group)->whereNull('archived_at')->update(['name' => $data['name'], 'updated_at' => now()]);
                    abort_unless($updated, 404);

                    return $group;
                }

                $id = (string) Str::uuid();
                DB::table('co_curricular_groups')->insert($data + [
                    'id' => $id, 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $id;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }
            throw ValidationException::withMessages(['name' => 'An active group of this type already uses that name.']);
        }

        return $this->success(['id' => $id]);
    }

    public function archive(Request $request, string $group)
    {
        $schoolId = $this->school($request);
        $this->writes->run($schoolId, function () use ($schoolId, $group): void {
            $this->placements->authorize($schoolId);
            $query = DB::table('co_curricular_groups')->where('school_id', $schoolId)->where('id', $group);
            abort_unless($query->exists(), 404);
            $query->whereNull('archived_at')->update(['archived_at' => now(), 'updated_at' => now()]);
            DB::table('co_curricular_placements')->where('school_id', $schoolId)
                ->where('group_id', $group)->whereNull('ended_at')->update(['ended_at' => now()]);
        });

        return $this->success(['id' => $group]);
    }

    public function findLearner(Request $request)
    {
        $schoolId = $this->school($request);
        $data = $request->validate(['admission_no' => 'required|string|max:50']);
        $learner = DB::table('learners')->where('school_id', $schoolId)->where('is_deleted', false)
            ->where('admission_no', $data['admission_no'])->first(['id', 'first_name', 'last_name', 'admission_no']);
        abort_unless($learner, 404, 'Learner not found.');

        return $this->success($learner);
    }

    public function history(Request $request, string $learner)
    {
        $schoolId = $this->school($request);
        abort_unless(DB::table('learners')->where('school_id', $schoolId)->where('id', $learner)->exists(), 404);

        return $this->success(DB::table('co_curricular_placements as placement')
            ->join('co_curricular_groups as groups', 'groups.id', '=', 'placement.group_id')
            ->where('placement.school_id', $schoolId)->where('placement.learner_id', $learner)
            ->latest('placement.started_at')->get(['placement.*', 'groups.name']));
    }

    public function place(Request $request, string $learner)
    {
        $schoolId = $this->school($request);
        $data = $request->validate([
            'co_curricular' => 'required|array:club,house,society',
            'co_curricular.*' => 'nullable|uuid',
        ]);
        $this->writes->run($schoolId, fn () => $this->placements->save($schoolId, $learner, $data['co_curricular']));

        return $this->history($request, $learner);
    }

    private function school(Request $request): string
    {
        $schoolId = $request->user()?->school_id;
        abort_unless($schoolId, 403);
        $this->placements->authorize($schoolId);

        return $schoolId;
    }
}
