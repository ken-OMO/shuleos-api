<?php

declare(strict_types=1);

namespace App\Actions\HumanResource\Teacher;

use App\Models\Teacher;
use App\Services\HumanResource\TeacherService;

final readonly class UpdateTeacherAction
{
    /**
     * Constructor.
     */
    public function __construct(
        private TeacherService $teachers
    ) {}

    /**
     * Execute the action.
     */
    public function execute(
        string $id,
        array $data
    ): Teacher {

        /**
         * Future:
         *
         * - Audit Changes
         * - Notify User
         * - Fire Events
         * - Synchronize External Systems
         */

        return $this->teachers->update(

            $id,

            $data

        );

    }
}
