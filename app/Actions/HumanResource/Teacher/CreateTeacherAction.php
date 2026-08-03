<?php

declare(strict_types=1);

namespace App\Actions\HumanResource\Teacher;

use App\Models\Teacher;
use App\Services\HumanResource\TeacherService;

final readonly class CreateTeacherAction
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
        array $data
    ): Teacher {

        /**
         * Future:
         *
         * - Audit Logging
         * - Events
         * - Notifications
         * - Queue Jobs
         * - AI Analysis
         */

        return $this->teachers->create(

            $data

        );

    }
}
