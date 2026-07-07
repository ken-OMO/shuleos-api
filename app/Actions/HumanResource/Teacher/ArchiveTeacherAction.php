<?php

declare(strict_types=1);

namespace App\Actions\HumanResource\Teacher;

use App\Models\Teacher;
use App\Services\HumanResource\TeacherService;

final readonly class ArchiveTeacherAction
{
    /**
     * Constructor.
     */
    public function __construct(
        private TeacherService $teachers
    ) {
    }

    /**
     * Archive a teacher.
     */
    public function execute(
        string $id
    ): Teacher {

        $teacher = $this->teachers->find($id);

        $teacher->update([

            'active' => false,

            'is_deleted' => true,

            'deleted_at' => now(),

        ]);

        /**
         * Future
         *
         * - Revoke login
         * - Remove active sessions
         * - Remove timetable allocations
         * - Disable attendance
         * - Disable payroll
         * - Audit
         * - Events
         */

        return $teacher->fresh();

    }
}
