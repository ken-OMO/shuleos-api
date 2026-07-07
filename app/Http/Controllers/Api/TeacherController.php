<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\HumanResource\Teacher\ArchiveTeacherAction;
use App\Actions\HumanResource\Teacher\CreateTeacherAction;
use App\Actions\HumanResource\Teacher\UpdateTeacherAction;
use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\Teacher\StoreTeacherRequest;
use App\Http\Requests\Teacher\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Services\HumanResource\TeacherService;

final class TeacherController extends BaseCrudController
{
    /**
     * Constructor.
     */
    public function __construct(
        private readonly TeacherService $teachers,
        private readonly CreateTeacherAction $createTeacher,
        private readonly UpdateTeacherAction $updateTeacher,
        private readonly ArchiveTeacherAction $archiveTeacher
    ) {
    }

    /**
     * Display teachers.
     */
    public function index()
    {
        return $this->success(

            TeacherResource::collection(

                $this->teachers->list()

            ),

            'Teachers retrieved successfully.'

        );
    }

    /**
     * Display teacher.
     */
    public function show(
        string $id
    )
    {
        return $this->success(

            new TeacherResource(

                $this->teachers->find(

                    $id

                )

            ),

            'Teacher retrieved successfully.'

        );
    }

    /**
     * Store teacher.
     */
    public function store(
        StoreTeacherRequest $request
    )
    {
        $teacher = $this->createTeacher->execute(

            $request->validated()

        );

        /**
         * Future:
         *
         * $this->audit(...)
         */

        return $this->created(

            new TeacherResource(

                $teacher

            ),

            'Teacher created successfully.'

        );
    }

    /**
     * Update teacher.
     */
    public function update(
        UpdateTeacherRequest $request,
        string $id
    )
    {
        $teacher = $this->updateTeacher->execute(

            $id,

            $request->validated()

        );

        /**
         * Future:
         *
         * $this->audit(...)
         */

        return $this->success(

            new TeacherResource(

                $teacher

            ),

            'Teacher updated successfully.'

        );
    }

    /**
     * Archive teacher.
     */
    public function destroy(
        string $id
    )
    {
        $teacher = $this->archiveTeacher->execute(

            $id

        );

        /**
         * Future:
         *
         * $this->audit(...)
         */

        return $this->success(

            new TeacherResource(

                $teacher

            ),

            'Teacher archived successfully.'

        );
    }
}
