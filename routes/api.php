<?php

use App\Http\Controllers\Api\AcademicWeekController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AssessmentRegistrationController;
use App\Http\Controllers\Api\AssessmentTypeController;
use App\Http\Controllers\Api\AttendanceAlertController;
use App\Http\Controllers\Api\AttendanceLeadershipController;
use App\Http\Controllers\Api\AttendanceLearnerController;
use App\Http\Controllers\Api\AttendanceParentController;
use App\Http\Controllers\Api\AttendanceSessionController;
use App\Http\Controllers\Api\AttendanceStatusController;
use App\Http\Controllers\Api\AttendanceTeacherController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BehaviourLeadershipController;
use App\Http\Controllers\Api\BehaviourLearnerController;
use App\Http\Controllers\Api\BehaviourParentController;
use App\Http\Controllers\Api\BehaviourTeacherController;
use App\Http\Controllers\Api\CurriculumCoverageController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamLearningAreaController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\ExamResultController;
use App\Http\Controllers\Api\FeeCategoryController;
use App\Http\Controllers\Api\FeeInvoiceController;
use App\Http\Controllers\Api\FeeStructureController;
use App\Http\Controllers\Api\FinancePortalController;
use App\Http\Controllers\Api\FinanceSettingController;
use App\Http\Controllers\Api\FinanceWorkflowController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\HomeworkLeadershipController;
use App\Http\Controllers\Api\HomeworkLearnerController;
use App\Http\Controllers\Api\HomeworkParentController;
use App\Http\Controllers\Api\HomeworkTeacherController;
use App\Http\Controllers\Api\LeadershipPortalController;
use App\Http\Controllers\Api\LearnerAttendanceController;
use App\Http\Controllers\Api\LearnerController;
use App\Http\Controllers\Api\LearnerFeeAccountController;
use App\Http\Controllers\Api\LearnerPortalAdminController;
use App\Http\Controllers\Api\LearnerPortalController;
use App\Http\Controllers\Api\LearningAreaAllocationController;
use App\Http\Controllers\Api\LearningAreaController;
use App\Http\Controllers\Api\LearningAreaResultController;
use App\Http\Controllers\Api\LearningResourceAdminController;
use App\Http\Controllers\Api\LearningResourceCategoryController;
use App\Http\Controllers\Api\LearningResourceLearnerController;
use App\Http\Controllers\Api\LearningResourceParentController;
use App\Http\Controllers\Api\LearningResourceTeacherController;
use App\Http\Controllers\Api\LessonNoteController;
use App\Http\Controllers\Api\LessonPlanController;
use App\Http\Controllers\Api\MarkEntryPermissionController;
use App\Http\Controllers\Api\MeritListController;
use App\Http\Controllers\Api\ParentPortalAdminController;
use App\Http\Controllers\Api\ParentPortalController;
use App\Http\Controllers\Api\PaymentAllocationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PaymentPlanController;
use App\Http\Controllers\Api\RecordOfWorkController;
use App\Http\Controllers\Api\ReportCardController;
use App\Http\Controllers\Api\ReportCardPdfController;
use App\Http\Controllers\Api\RoomConstraintController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomTypeController;
use App\Http\Controllers\Api\SchemeLessonController;
use App\Http\Controllers\Api\SchemeOfWorkController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SmartTimetableAutomationController;
use App\Http\Controllers\Api\SmartTimetableController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\StudentElectionAdminController;
use App\Http\Controllers\Api\StudentElectionLearnerController;
use App\Http\Controllers\Api\TeacherAssignmentController;
use App\Http\Controllers\Api\TeacherAvailabilityController;
use App\Http\Controllers\Api\TeacherConstraintController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherPortalController;
use App\Http\Controllers\Api\TermController;
use App\Http\Controllers\Api\TimetableConflictController;
use App\Http\Controllers\Api\TimetableConstraintController;
use App\Http\Controllers\Api\TimetableEntryController;
use App\Http\Controllers\Api\TimetableGenerationRunController;
use App\Http\Controllers\Api\TimetablePeriodController;
use App\Http\Controllers\Api\TimetableProfileController;
use App\Http\Controllers\Api\TimetablePublicationController;
use App\Http\Controllers\Api\TimetableSubstitutionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Global Middleware
|--------------------------------------------------------------------------
*/

$secure = [

    'jwt',

    'tenant',

    'module.permission',

];

Route::prefix('academic-years')->middleware($secure)->group(function () {
    Route::get('/', [AcademicYearController::class, 'index']);
    Route::get('/{id}', [AcademicYearController::class, 'show']);
    Route::post('/', [AcademicYearController::class, 'store']);
    Route::put('/{id}', [AcademicYearController::class, 'update']);
    Route::delete('/{id}', [AcademicYearController::class, 'destroy']);
});

Route::prefix('terms')->middleware($secure)->group(function () {
    Route::get('/', [TermController::class, 'index']);
    Route::get('/{id}', [TermController::class, 'show']);
    Route::post('/', [TermController::class, 'store']);
    Route::put('/{id}', [TermController::class, 'update']);
    Route::delete('/{id}', [TermController::class, 'destroy']);
});
/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('jwt')->group(function () {

        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/refresh', [AuthController::class, 'refresh']);

    });

});

/*
|--------------------------------------------------------------------------
| School Routes
|--------------------------------------------------------------------------
*/

Route::prefix('schools')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [SchoolController::class, 'index']);

        Route::get('/{id}', [SchoolController::class, 'show']);

        Route::post('/', [SchoolController::class, 'store']);

        Route::put('/{id}', [SchoolController::class, 'update']);

        Route::delete('/{id}', [SchoolController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
*/

Route::prefix('users')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [UserController::class, 'index']);

        Route::get('/{id}', [UserController::class, 'show']);

        Route::post('/', [UserController::class, 'store']);

        Route::put('/{id}', [UserController::class, 'update']);

        Route::delete('/{id}', [UserController::class, 'destroy']);

        Route::post(
            '/{id}/reset-password',
            [UserController::class, 'resetPassword']
        );

        Route::post(
            '/{id}/assign-role',
            [UserController::class, 'assignRole']
        );

    });

/*
|--------------------------------------------------------------------------
| Teacher Routes
|--------------------------------------------------------------------------
*/

Route::prefix('teachers')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [TeacherController::class, 'index']);

        Route::get('/{id}', [TeacherController::class, 'show']);

        Route::post('/', [TeacherController::class, 'store']);

        Route::put('/{id}', [TeacherController::class, 'update']);

        Route::delete('/{id}', [TeacherController::class, 'destroy']);

    });

Route::prefix('teacher')->middleware($secure)->group(function () {
    Route::get('/behaviour/categories', [BehaviourTeacherController::class, 'categories'])->middleware('permission:view_assigned_learner_behaviour');
    Route::get('/behaviour/learners/{learner}', [BehaviourTeacherController::class, 'learner'])->middleware('permission:view_assigned_learner_behaviour');
    Route::post('/behaviour/cases', [BehaviourTeacherController::class, 'store'])->middleware('permission:report_behaviour_cases');
    Route::get('/behaviour/cases', [BehaviourTeacherController::class, 'cases'])->middleware('permission:view_assigned_learner_behaviour');
    Route::get('/behaviour/cases/{case}', [BehaviourTeacherController::class, 'show'])->middleware('permission:view_assigned_learner_behaviour');
    Route::post('/behaviour/cases/{case}/actions', [BehaviourTeacherController::class, 'action'])->middleware('permission:assign_basic_behaviour_actions');
    Route::post('/behaviour/recognitions', [BehaviourTeacherController::class, 'recognize'])->middleware('permission:award_behaviour_recognition');
    Route::get('/behaviour/recognitions', [BehaviourTeacherController::class, 'recognitions'])->middleware('permission:view_assigned_learner_behaviour');
    Route::get('/attendance/sessions', [AttendanceTeacherController::class, 'sessions']);
    Route::get('/attendance/registers', [AttendanceTeacherController::class, 'index']);
    Route::post('/attendance/registers', [AttendanceTeacherController::class, 'store']);
    Route::get('/attendance/registers/{register}', [AttendanceTeacherController::class, 'show']);
    Route::put('/attendance/registers/{register}/draft', [AttendanceTeacherController::class, 'draft']);
    Route::post('/attendance/registers/{register}/finalize', [AttendanceTeacherController::class, 'finalize']);
    Route::post('/attendance/registers/{register}/reopen', [AttendanceTeacherController::class, 'reopen']);
    Route::post('/attendance/registers/{register}/correct', [AttendanceTeacherController::class, 'correct']);
    Route::post('/attendance/registers/{register}/cancel', [AttendanceTeacherController::class, 'cancel']);
    Route::get('/homework', [HomeworkTeacherController::class, 'index']);
    Route::post('/homework', [HomeworkTeacherController::class, 'store']);
    Route::get('/homework/{assignment}', [HomeworkTeacherController::class, 'show']);
    Route::put('/homework/{assignment}', [HomeworkTeacherController::class, 'update']);
    Route::post('/homework/{assignment}/resources', [HomeworkTeacherController::class, 'resource']);
    Route::get('/homework/{assignment}/rubric', [HomeworkTeacherController::class, 'rubric']);
    Route::post('/homework/{assignment}/rubric', [HomeworkTeacherController::class, 'rubric']);
    Route::put('/homework/{assignment}/rubric', [HomeworkTeacherController::class, 'rubric']);
    foreach (['scheduled' => 'schedule', 'published' => 'publish', 'closed' => 'close', 'cancelled' => 'cancel', 'archived' => 'archive'] as $status => $path) {
        Route::post('/homework/{assignment}/'.$path, fn (string $assignment) => app(HomeworkTeacherController::class)->transition($assignment, $status));
    }
    Route::get('/homework/{assignment}/learners', [HomeworkTeacherController::class, 'learners']);
    Route::get('/homework/{assignment}/submissions', [HomeworkTeacherController::class, 'submissions']);
    Route::get('/homework/{assignment}/submissions/{submission}', [HomeworkTeacherController::class, 'submission']);
    Route::get('/homework/{assignment}/submissions/{submission}/files/{file}/download', [HomeworkTeacherController::class, 'download']);
    Route::post('/homework/{assignment}/submissions/{submission}/return', fn (Request $r, string $assignment, string $submission) => app(HomeworkTeacherController::class)->returnSubmission($r, $assignment, $submission));
    Route::post('/homework/{assignment}/submissions/{submission}/request-resubmission', fn (Request $r, string $assignment, string $submission) => app(HomeworkTeacherController::class)->returnSubmission($r, $assignment, $submission, true));
    Route::put('/homework/{assignment}/submissions/{submission}/mark', [HomeworkTeacherController::class, 'mark']);
    Route::post('/homework/{assignment}/submissions/{submission}/release', [HomeworkTeacherController::class, 'release']);
    Route::get('/resources', [LearningResourceTeacherController::class, 'index']);
    Route::get('/resources/{resource}', [LearningResourceTeacherController::class, 'show']);
    Route::post('/resources', [LearningResourceTeacherController::class, 'create']);
    Route::post('/resources/upload', [LearningResourceTeacherController::class, 'upload']);
    Route::put('/resources/{resource}', [LearningResourceTeacherController::class, 'update']);
    Route::post('/resources/{resource}/submit', [LearningResourceTeacherController::class, 'submit']);
    Route::post('/resources/{resource}/archive', [LearningResourceTeacherController::class, 'archive']);
    Route::get('/resources/{resource}/download', [LearningResourceTeacherController::class, 'download']);
    Route::get('/resources/{resource}/versions', [LearningResourceTeacherController::class, 'versions']);
    Route::get('/resources/{resource}/versions/{version}/download', [LearningResourceTeacherController::class, 'downloadVersion']);
    Route::post('/resources/{resource}/versions/upload', [LearningResourceTeacherController::class, 'uploadVersion']);
    Route::post('/resources/{resource}/versions/link', [LearningResourceTeacherController::class, 'linkVersion']);
    Route::post('/resources/{resource}/versions/{version}/restore', [LearningResourceTeacherController::class, 'restore']);
    Route::get('/me', [TeacherPortalController::class, 'me']);
    Route::get('/dashboard', [TeacherPortalController::class, 'dashboard']);
    Route::get('/classes', [TeacherPortalController::class, 'classes']);
    Route::get('/learners', [TeacherPortalController::class, 'learners']);
    Route::get('/timetable', [TeacherPortalController::class, 'timetable']);
    Route::get('/timetable/today', [TeacherPortalController::class, 'timetableToday']);
    Route::get('/timetable/week', [TeacherPortalController::class, 'timetableWeek']);
    Route::get('/timetable/next', [TeacherPortalController::class, 'timetableNext']);
    Route::get('/timetable/current-period', [SmartTimetableController::class, 'currentPeriod']);
    Route::get('/lesson-plans', [TeacherPortalController::class, 'lessonPlans']);
    Route::get('/lesson-notes', [TeacherPortalController::class, 'lessonNotes']);
    Route::get('/records-of-work', [TeacherPortalController::class, 'records']);
    Route::get('/curriculum-coverage', [TeacherPortalController::class, 'coverage']);
    Route::get('/assessments', [TeacherPortalController::class, 'assessments']);
    Route::get('/attendance', [TeacherPortalController::class, 'attendance']);
    Route::get('/notifications', [TeacherPortalController::class, 'notifications']);
    Route::get('/announcements', [TeacherPortalController::class, 'announcements']);
    Route::get('/analytics', [TeacherPortalController::class, 'analytics']);
    Route::get('/dashboard-preferences', [TeacherPortalController::class, 'preferences']);
    Route::patch('/dashboard-preferences', [TeacherPortalController::class, 'updatePreferences']);
});

Route::prefix('leadership')->middleware($secure)->group(function () {
    Route::get('/me', [LeadershipPortalController::class, 'me']);
    Route::get('/dashboard', [LeadershipPortalController::class, 'dashboard']);
    Route::get('/dashboard-preferences', [LeadershipPortalController::class, 'preferences']);
    Route::patch('/dashboard-preferences', [LeadershipPortalController::class, 'updatePreferences']);
    Route::get('/attendance', [LeadershipPortalController::class, 'attendance']);
    Route::get('/curriculum-coverage', [LeadershipPortalController::class, 'curriculum']);
    Route::get('/approvals', [LeadershipPortalController::class, 'approvals']);
    Route::get('/approvals/{id}', [LeadershipPortalController::class, 'approval']);
    Route::post('/approvals/{id}/approve', [LeadershipPortalController::class, 'approve']);
    Route::post('/approvals/{id}/reject', [LeadershipPortalController::class, 'reject']);
    Route::get('/lesson-plans', [LeadershipPortalController::class, 'lessonPlans']);
    Route::get('/records-of-work', [LeadershipPortalController::class, 'records']);
    Route::get('/teacher-workload', [LeadershipPortalController::class, 'workload']);
    Route::get('/assessments', [LeadershipPortalController::class, 'assessments']);
    Route::get('/report-cards', [LeadershipPortalController::class, 'reports']);
    Route::get('/academic-performance', [LeadershipPortalController::class, 'academic']);
    Route::get('/discipline', [LeadershipPortalController::class, 'discipline']);
    Route::get('/finance', [LeadershipPortalController::class, 'finance']);
    Route::get('/announcements', [LeadershipPortalController::class, 'announcements']);
    Route::get('/notifications', [LeadershipPortalController::class, 'notifications']);
});

/*
|--------------------------------------------------------------------------
| Grade Routes
|--------------------------------------------------------------------------
*/

Route::prefix('grades')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [GradeController::class, 'index']);

        Route::get('/{id}', [GradeController::class, 'show']);

        Route::post('/', [GradeController::class, 'store']);

        Route::put('/{id}', [GradeController::class, 'update']);

        Route::delete('/{id}', [GradeController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Stream Routes
|--------------------------------------------------------------------------
*/

Route::prefix('streams')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [StreamController::class, 'index']);

        Route::get('/{id}', [StreamController::class, 'show']);

        Route::post('/', [StreamController::class, 'store']);

        Route::put('/{id}', [StreamController::class, 'update']);

        Route::delete('/{id}', [StreamController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Learner Routes
|--------------------------------------------------------------------------
*/

Route::prefix('learners')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LearnerController::class, 'index']);

        Route::post('/{learner}/portal-account', [LearnerPortalAdminController::class, 'create']);
        Route::patch('/{learner}/portal-account/status', [LearnerPortalAdminController::class, 'status']);
        Route::post('/{learner}/portal-account/reset-password', [LearnerPortalAdminController::class, 'reset']);
        Route::get('/{id}', [LearnerController::class, 'show']);

        Route::post('/', [LearnerController::class, 'store']);

        Route::put('/{id}', [LearnerController::class, 'update']);

        Route::delete('/{id}', [LearnerController::class, 'destroy']);

    });

Route::prefix('learner')->middleware($secure)->group(function () {
    Route::get('/behaviour', [BehaviourLearnerController::class, 'index'])->middleware('permission:view_own_behaviour');
    Route::get('/behaviour/recognitions', [BehaviourLearnerController::class, 'recognitions'])->middleware('permission:view_own_recognitions');
    Route::get('/behaviour/actions', [BehaviourLearnerController::class, 'actions'])->middleware('permission:view_own_behaviour');
    Route::get('/homework', [HomeworkLearnerController::class, 'index']);
    Route::get('/homework/{assignment}', [HomeworkLearnerController::class, 'show']);
    Route::post('/homework/{assignment}/view', [HomeworkLearnerController::class, 'view']);
    Route::post('/homework/{assignment}/submission', [HomeworkLearnerController::class, 'draft']);
    Route::put('/homework/{assignment}/submission', [HomeworkLearnerController::class, 'draft']);
    Route::post('/homework/{assignment}/submission/files', [HomeworkLearnerController::class, 'upload']);
    Route::get('/homework/{assignment}/submission', [HomeworkLearnerController::class, 'submission']);
    Route::get('/homework/{assignment}/submission/files/{file}/download', [HomeworkLearnerController::class, 'download']);
    Route::delete('/homework/{assignment}/submission/files/{file}', [HomeworkLearnerController::class, 'deleteFile']);
    Route::post('/homework/{assignment}/submit', [HomeworkLearnerController::class, 'submit']);
    Route::get('/homework/{assignment}/feedback', [HomeworkLearnerController::class, 'feedback']);
    Route::get('/resources', [LearningResourceLearnerController::class, 'index']);
    Route::get('/resources/{resource}', [LearningResourceLearnerController::class, 'show']);
    Route::get('/resources/{resource}/download', [LearningResourceLearnerController::class, 'download']);
    Route::get('/resources/{resource}/open', [LearningResourceLearnerController::class, 'open']);
    Route::post('/resources/{resource}/bookmark', [LearningResourceLearnerController::class, 'bookmark']);
    Route::delete('/resources/{resource}/bookmark', [LearningResourceLearnerController::class, 'unbookmark']);
    Route::put('/resources/{resource}/rating', [LearningResourceLearnerController::class, 'rate']);
    Route::get('/elections', [StudentElectionLearnerController::class, 'index']);
    Route::get('/elections/{election}', [StudentElectionLearnerController::class, 'show']);
    Route::get('/elections/{election}/positions/{position}/candidates', [StudentElectionLearnerController::class, 'candidates']);
    Route::post('/elections/{election}/positions/{position}/vote', [StudentElectionLearnerController::class, 'vote']);
    Route::get('/elections/{election}/results', [StudentElectionLearnerController::class, 'results']);
    Route::get('/student-leaders', [StudentElectionLearnerController::class, 'leaders']);
    Route::get('/me', [LearnerPortalController::class, 'me']);
    Route::get('/dashboard', [LearnerPortalController::class, 'dashboard']);
    Route::get('/dashboard-preferences', [LearnerPortalController::class, 'preferences']);
    Route::patch('/dashboard-preferences', [LearnerPortalController::class, 'updatePreferences']);
    Route::get('/timetable', [LearnerPortalController::class, 'timetable']);
    Route::get('/timetable/today', [LearnerPortalController::class, 'timetableToday']);
    Route::get('/timetable/week', [LearnerPortalController::class, 'timetableWeek']);
    Route::get('/timetable/current-period', [SmartTimetableController::class, 'currentPeriod']);
    Route::get('/attendance', [AttendanceLearnerController::class, 'index']);
    Route::get('/attendance/summary', [AttendanceLearnerController::class, 'summary']);
    Route::get('/attendance/history', [AttendanceLearnerController::class, 'history']);
    Route::get('/results', [LearnerPortalController::class, 'results']);
    Route::get('/report-cards', [LearnerPortalController::class, 'reportCards']);
    Route::get('/report-cards/{reportCard}/pdf', [LearnerPortalController::class, 'pdf']);
    Route::get('/report-cards/{reportCard}', [LearnerPortalController::class, 'reportCard']);
    Route::get('/fees', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/summary', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/invoices', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/payments', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/ledger', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/receipts/{payment}', [FinancePortalController::class, 'learnerReceipt'])->middleware('permission:view_own_fees');
    Route::get('/upcoming-exams', [LearnerPortalController::class, 'exams']);
    Route::get('/announcements', [LearnerPortalController::class, 'announcements']);
    Route::get('/notifications', [LearnerPortalController::class, 'notifications']);
});

Route::prefix('learning-resources')->middleware($secure)->group(function () {
    Route::get('/analytics', [LearningResourceAdminController::class, 'analytics'])->middleware('permission:view_learning_resource_analytics');
    Route::get('/', [LearningResourceAdminController::class, 'index']);
    Route::get('/{resource}', [LearningResourceAdminController::class, 'show']);
    Route::get('/{resource}/versions', [LearningResourceAdminController::class, 'versions']);
    Route::post('/{resource}/approve', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'approved'))->middleware('permission:approve_learning_resources');
    Route::post('/{resource}/reject', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'rejected'))->middleware('permission:approve_learning_resources');
    Route::post('/{resource}/publish', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'published'))->middleware('permission:publish_learning_resources');
    Route::post('/{resource}/archive', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'archived'))->middleware('permission:archive_learning_resources');
});

Route::prefix('learning-resource-categories')->middleware(['jwt', 'tenant'])->group(function () {
    Route::get('/', [LearningResourceCategoryController::class, 'index']);
    Route::post('/', [LearningResourceCategoryController::class, 'store'])->middleware('permission:manage_learning_resource_categories');
    Route::put('/{category}', [LearningResourceCategoryController::class, 'update'])->middleware('permission:manage_learning_resource_categories');
    Route::delete('/{category}', [LearningResourceCategoryController::class, 'destroy'])->middleware('permission:manage_learning_resource_categories');
});

Route::prefix('student-elections')->middleware($secure)->group(function () {
    Route::get('/', [StudentElectionAdminController::class, 'index']);
    Route::post('/', [StudentElectionAdminController::class, 'create']);
    Route::get('/{election}', [StudentElectionAdminController::class, 'show']);
    Route::post('/{election}/positions', [StudentElectionAdminController::class, 'attach']);
    Route::post('/{election}/generate-voters', [StudentElectionAdminController::class, 'voters']);
    foreach (['nominations_open' => 'open-nominations', 'nominations_closed' => 'close-nominations', 'voting_open' => 'open-voting', 'voting_closed' => 'close-voting', 'cancelled' => 'cancel'] as $status => $path) {
        Route::post('/{election}/'.$path, fn (Request $r, string $election) => app(StudentElectionAdminController::class)->transition($r, $election, $status));
    }Route::post('/{election}/tally', [StudentElectionAdminController::class, 'tally']);
    Route::post('/{election}/publish', [StudentElectionAdminController::class, 'publish']);
    Route::get('/{election}/results', [StudentElectionAdminController::class, 'results']);
});
Route::prefix('student-leadership-positions')->middleware($secure)->group(function () {
    Route::get('/', [StudentElectionAdminController::class, 'positions']);
    Route::post('/', [StudentElectionAdminController::class, 'createPosition']);
});
Route::prefix('student-election-candidates')->middleware($secure)->group(function () {
    Route::patch('/{candidate}/approve', fn (Request $r, string $candidate) => app(StudentElectionAdminController::class)->review($r, $candidate, 'approved'));
    Route::patch('/{candidate}/reject', fn (Request $r, string $candidate) => app(StudentElectionAdminController::class)->review($r, $candidate, 'rejected'));
    Route::patch('/{candidate}/disqualify', fn (Request $r, string $candidate) => app(StudentElectionAdminController::class)->review($r, $candidate, 'disqualified'));
});

/*
|--------------------------------------------------------------------------
| Guardian Routes
|--------------------------------------------------------------------------
*/

Route::prefix('guardians')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [GuardianController::class, 'index']);

        Route::get('/{id}', [GuardianController::class, 'show']);

        Route::post('/', [GuardianController::class, 'store']);

        Route::put('/{id}', [GuardianController::class, 'update']);

        Route::delete('/{id}', [GuardianController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Learning Area Routes
|--------------------------------------------------------------------------
*/

Route::prefix('learning-areas')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LearningAreaController::class, 'index']);

        Route::get('/{id}', [LearningAreaController::class, 'show']);

        Route::post('/', [LearningAreaController::class, 'store']);

        Route::put('/{id}', [LearningAreaController::class, 'update']);

        Route::delete('/{id}', [LearningAreaController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Learning Area Allocation Routes
|--------------------------------------------------------------------------
*/

Route::prefix('learning-area-allocations')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LearningAreaAllocationController::class, 'index']);

        Route::get('/{id}', [LearningAreaAllocationController::class, 'show']);

        Route::post('/', [LearningAreaAllocationController::class, 'store']);

        Route::put('/{id}', [LearningAreaAllocationController::class, 'update']);

        Route::delete('/{id}', [LearningAreaAllocationController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
/*
|--------------------------------------------------------------------------
| Academic Week Routes
|--------------------------------------------------------------------------
*/

Route::prefix('academic-weeks')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AcademicWeekController::class, 'index']);

        Route::get('/{id}', [AcademicWeekController::class, 'show']);

        Route::post('/', [AcademicWeekController::class, 'store']);

        Route::put('/{id}', [AcademicWeekController::class, 'update']);

        Route::delete('/{id}', [AcademicWeekController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Teaching Engine Routes
|--------------------------------------------------------------------------
*/

Route::prefix('teacher-assignments')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [TeacherAssignmentController::class, 'index']);

        Route::get('/{id}', [TeacherAssignmentController::class, 'show']);

        Route::post('/', [TeacherAssignmentController::class, 'store']);

        Route::put('/{id}', [TeacherAssignmentController::class, 'update']);

        Route::delete('/{id}', [TeacherAssignmentController::class, 'destroy']);

    });

Route::prefix('schemes-of-work')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [SchemeOfWorkController::class, 'index']);

        Route::get('/{id}', [SchemeOfWorkController::class, 'show']);

        Route::post('/', [SchemeOfWorkController::class, 'store']);

        Route::put('/{id}', [SchemeOfWorkController::class, 'update']);

        Route::delete('/{id}', [SchemeOfWorkController::class, 'destroy']);

    });

Route::prefix('scheme-lessons')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [SchemeLessonController::class, 'index']);

        Route::get('/{id}', [SchemeLessonController::class, 'show']);

        Route::post('/', [SchemeLessonController::class, 'store']);

        Route::put('/{id}', [SchemeLessonController::class, 'update']);

        Route::delete('/{id}', [SchemeLessonController::class, 'destroy']);

    });

Route::prefix('lesson-plans')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LessonPlanController::class, 'index']);

        Route::get('/{id}', [LessonPlanController::class, 'show']);

        Route::post('/', [LessonPlanController::class, 'store']);

        Route::put('/{id}', [LessonPlanController::class, 'update']);

        Route::delete('/{id}', [LessonPlanController::class, 'destroy']);

    });

Route::prefix('lesson-notes')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LessonNoteController::class, 'index']);

        Route::get('/{id}', [LessonNoteController::class, 'show']);

        Route::post('/', [LessonNoteController::class, 'store']);

        Route::put('/{id}', [LessonNoteController::class, 'update']);

        Route::delete('/{id}', [LessonNoteController::class, 'destroy']);

    });

Route::prefix('records-of-work')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [RecordOfWorkController::class, 'index']);

        Route::get('/{id}', [RecordOfWorkController::class, 'show']);

        Route::post('/', [RecordOfWorkController::class, 'store']);

        Route::put('/{id}', [RecordOfWorkController::class, 'update']);

        Route::delete('/{id}', [RecordOfWorkController::class, 'destroy']);

    });

Route::prefix('curriculum-coverage')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [CurriculumCoverageController::class, 'index']);

        Route::get('/{id}', [CurriculumCoverageController::class, 'show']);

        Route::post('/', [CurriculumCoverageController::class, 'store']);

        Route::put('/{id}', [CurriculumCoverageController::class, 'update']);

        Route::delete('/{id}', [CurriculumCoverageController::class, 'destroy']);

    });
/*
|--------------------------------------------------------------------------
| Exams Engine Routes
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Exam Engine Routes
|--------------------------------------------------------------------------
*/

Route::prefix('assessment-types')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AssessmentTypeController::class, 'index']);

        Route::get('/{id}', [AssessmentTypeController::class, 'show']);

        Route::post('/', [AssessmentTypeController::class, 'store']);

        Route::put('/{id}', [AssessmentTypeController::class, 'update']);

        Route::delete('/{id}', [AssessmentTypeController::class, 'destroy']);

    });

Route::prefix('assessment-registrations')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AssessmentRegistrationController::class, 'index']);

        Route::get('/{id}', [AssessmentRegistrationController::class, 'show']);

        Route::post('/', [AssessmentRegistrationController::class, 'store']);

        Route::put('/{id}', [AssessmentRegistrationController::class, 'update']);

        Route::delete('/{id}', [AssessmentRegistrationController::class, 'destroy']);

    });

Route::prefix('exams')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamController::class, 'index']);

        Route::get('/{id}', [ExamController::class, 'show']);

        Route::post('/', [ExamController::class, 'store']);

        Route::put('/{id}', [ExamController::class, 'update']);

        Route::delete('/{id}', [ExamController::class, 'destroy']);

    });

Route::prefix('exam-learning-areas')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamLearningAreaController::class, 'index']);

        Route::get('/{id}', [ExamLearningAreaController::class, 'show']);

        Route::post('/', [ExamLearningAreaController::class, 'store']);

        Route::put('/{id}', [ExamLearningAreaController::class, 'update']);

        Route::delete('/{id}', [ExamLearningAreaController::class, 'destroy']);

    });

Route::prefix('exam-papers')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamPaperController::class, 'index']);

        Route::get('/{id}', [ExamPaperController::class, 'show']);

        Route::post('/', [ExamPaperController::class, 'store']);

        Route::put('/{id}', [ExamPaperController::class, 'update']);

        Route::delete('/{id}', [ExamPaperController::class, 'destroy']);

    });

Route::prefix('exam-results')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamResultController::class, 'index']);

        Route::get('/{id}', [ExamResultController::class, 'show']);

        Route::post('/', [ExamResultController::class, 'store']);

        Route::put('/{id}', [ExamResultController::class, 'update']);

        Route::delete('/{id}', [ExamResultController::class, 'destroy']);

    });

Route::prefix('learning-area-results')
    ->middleware($secure)
    ->group(function () {
        Route::get('/', [LearningAreaResultController::class, 'index']);
        Route::post('/process', [LearningAreaResultController::class, 'process']);
        Route::get('/{id}', [LearningAreaResultController::class, 'show']);
    });

Route::prefix('mark-entry-permissions')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [MarkEntryPermissionController::class, 'index']);

        Route::get('/{id}', [MarkEntryPermissionController::class, 'show']);

        Route::post('/', [MarkEntryPermissionController::class, 'store']);

        Route::put('/{id}', [MarkEntryPermissionController::class, 'update']);

        Route::delete('/{id}', [MarkEntryPermissionController::class, 'destroy']);

    });

Route::prefix('merit-lists')
    ->middleware($secure)
    ->group(function () {
        Route::get('/', [MeritListController::class, 'index']);
        Route::post('/generate', [MeritListController::class, 'generate']);
        Route::post('/publish', [MeritListController::class, 'publish']);
        Route::get('/{id}', [MeritListController::class, 'show']);
    });

Route::prefix('report-cards')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ReportCardController::class, 'index']);
        Route::post('/generate', [ReportCardController::class, 'generate']);
        Route::post('/publish', [ReportCardController::class, 'publish']);
        Route::patch('/{id}/comments', [ReportCardController::class, 'updateComments']);
        Route::get('/{id}/pdf/download', [ReportCardPdfController::class, 'download']);
        Route::get('/{id}/pdf', [ReportCardPdfController::class, 'stream']);
        Route::get('/{id}', [ReportCardController::class, 'show']);

    });

Route::prefix('parent')->middleware($secure)->group(function () {
    Route::get('/learners/{learner}/timetable', [ParentPortalController::class, 'timetable'])->middleware('permission:view_linked_learner_timetable');
    Route::get('/learners/{learner}/timetable/today', [ParentPortalController::class, 'timetableToday'])->middleware('permission:view_linked_learner_timetable');
    Route::get('/learners/{learner}/behaviour', [BehaviourParentController::class, 'index'])->middleware('permission:view_linked_learner_behaviour');
    Route::get('/learners/{learner}/behaviour/recognitions', [BehaviourParentController::class, 'recognitions'])->middleware('permission:view_linked_learner_recognitions');
    Route::get('/learners/{learner}/behaviour/actions', [BehaviourParentController::class, 'actions'])->middleware('permission:view_linked_learner_behaviour');
    Route::get('/learners/{learner}/homework', [HomeworkParentController::class, 'index']);
    Route::get('/learners/{learner}/homework/{assignment}', [HomeworkParentController::class, 'show']);
    Route::get('/learners/{learner}/resources', [LearningResourceParentController::class, 'index']);
    Route::get('/learners/{learner}/resources/{resource}', [LearningResourceParentController::class, 'show']);
    Route::get('/learners/{learner}/resources/{resource}/download', [LearningResourceParentController::class, 'download']);
    Route::get('/learners/{learner}/resources/{resource}/open', [LearningResourceParentController::class, 'open']);
    Route::get('/me', [ParentPortalController::class, 'me']);
    Route::get('/learners', [ParentPortalController::class, 'learners']);
    Route::get('/learners/{learner}/dashboard', [ParentPortalController::class, 'dashboard']);
    Route::get('/learners/{learner}/report-cards', [ParentPortalController::class, 'reportCards']);
    Route::get('/learners/{learner}/report-cards/{reportCard}/pdf', [ParentPortalController::class, 'reportCardPdf']);
    Route::get('/learners/{learner}/report-cards/{reportCard}', [ParentPortalController::class, 'reportCard']);
    Route::get('/learners/{learner}/attendance', [AttendanceParentController::class, 'index']);
    Route::get('/learners/{learner}/attendance/summary', [AttendanceParentController::class, 'summary']);
    Route::get('/learners/{learner}/attendance/history', [AttendanceParentController::class, 'history']);
    Route::get('/learners/{learner}/fees', [FinancePortalController::class, 'parent'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/summary', [FinancePortalController::class, 'parent'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/invoices', [FinancePortalController::class, 'parent'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/payments', [FinancePortalController::class, 'parent'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/ledger', [FinancePortalController::class, 'parent'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/receipts/{payment}', [FinancePortalController::class, 'parentReceipt'])->middleware('permission:view_linked_learner_fees');
    Route::get('/announcements', [ParentPortalController::class, 'announcements']);
    Route::get('/notifications', [ParentPortalController::class, 'notifications']);
});

Route::prefix('homework')->middleware($secure)->group(function () {
    Route::get('/analytics', [HomeworkLeadershipController::class, 'analytics']);
    Route::post('/submissions/{submission}/request-moderation', fn (Request $r, string $submission) => app(HomeworkLeadershipController::class)->moderation($r, $submission));
    Route::post('/submissions/{submission}/moderate', fn (Request $r, string $submission) => app(HomeworkLeadershipController::class)->moderation($r, $submission, true));
    Route::get('/', [HomeworkLeadershipController::class, 'index']);
    Route::get('/{assignment}/completion', [HomeworkLeadershipController::class, 'completion']);
    Route::get('/{assignment}', [HomeworkLeadershipController::class, 'show']);
});
Route::prefix('parent-access-policy')->middleware($secure)->group(function () {
    Route::get('/', [ParentPortalAdminController::class, 'policy']);
    Route::put('/', [ParentPortalAdminController::class, 'updatePolicy']);
});
Route::prefix('parent-access-overrides')->middleware($secure)->group(function () {
    Route::get('/', [ParentPortalAdminController::class, 'overrides']);
    Route::post('/', [ParentPortalAdminController::class, 'createOverride']);
    Route::delete('/{id}', [ParentPortalAdminController::class, 'revokeOverride']);
});

/*
|--------------------------------------------------------------------------
| Attendance Engine Routes
|--------------------------------------------------------------------------
*/

Route::prefix('attendance')->middleware($secure)->group(function () {
    Route::get('/risk-flags', [BehaviourLeadershipController::class, 'risks'])->middleware('permission:view_attendance_risk_flags');
    Route::post('/risk-flags/{flag}/acknowledge', fn (Request $request, string $flag) => app(BehaviourLeadershipController::class)->riskUpdate($request, $flag, 'acknowledged'))->middleware('permission:resolve_attendance_risk_flags');
    Route::post('/risk-flags/{flag}/resolve', fn (Request $request, string $flag) => app(BehaviourLeadershipController::class)->riskUpdate($request, $flag, 'resolved'))->middleware('permission:resolve_attendance_risk_flags');
    Route::post('/{register}/correct', [AttendanceLeadershipController::class, 'correct'])->middleware('permission:correct_finalized_attendance');
    Route::get('/analytics', [AttendanceLeadershipController::class, 'analytics']);
    Route::get('/absentees', [AttendanceLeadershipController::class, 'absentees']);
    Route::get('/late', [AttendanceLeadershipController::class, 'absentees']);
    Route::get('/register-completion', [AttendanceLeadershipController::class, 'completion']);
    Route::get('/chronic-absence', [AttendanceLeadershipController::class, 'absentees']);
    Route::get('/', [AttendanceLeadershipController::class, 'index']);
    Route::get('/{register}', [AttendanceLeadershipController::class, 'show']);
});

Route::prefix('behaviour')->middleware($secure)->group(function () {
    Route::get('/', [BehaviourLeadershipController::class, 'index'])->middleware('permission:view_behaviour_analytics');
    Route::get('/analytics', [BehaviourLeadershipController::class, 'analytics'])->middleware('permission:view_behaviour_analytics');
    Route::get('/risk-indicators', [BehaviourLeadershipController::class, 'indicators'])->middleware('permission:view_behaviour_analytics');
    Route::get('/cases', [BehaviourLeadershipController::class, 'cases'])->middleware('permission:review_behaviour_cases');
    Route::get('/cases/{case}', [BehaviourLeadershipController::class, 'show'])->middleware('permission:review_behaviour_cases');
    Route::post('/cases/{case}/review', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'under_review'))->middleware('permission:review_behaviour_cases');
    Route::post('/cases/{case}/resolve', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'resolved'))->middleware('permission:resolve_behaviour_cases');
    Route::post('/cases/{case}/close', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'closed'))->middleware('permission:resolve_behaviour_cases');
    Route::post('/cases/{case}/reopen', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'reopened'))->middleware('permission:resolve_behaviour_cases');
    Route::post('/cases/{case}/actions', [BehaviourLeadershipController::class, 'action'])->middleware('permission:assign_restricted_behaviour_actions');
    Route::post('/recognitions/{recognition}/approve', [BehaviourLeadershipController::class, 'approve'])->middleware('permission:approve_behaviour_recognitions');
    Route::post('/referrals', [BehaviourLeadershipController::class, 'referral'])->middleware('permission:manage_counselling_referrals');
});

Route::prefix('attendance-statuses')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AttendanceStatusController::class, 'index']);

        Route::get('/{id}', [AttendanceStatusController::class, 'show']);

        Route::post('/', [AttendanceStatusController::class, 'store']);

        Route::put('/{id}', [AttendanceStatusController::class, 'update']);

        Route::delete('/{id}', [AttendanceStatusController::class, 'destroy']);

    });

Route::prefix('attendance-sessions')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [AttendanceSessionController::class, 'index']);

        Route::get('/{id}', [AttendanceSessionController::class, 'show']);

        Route::post('/', [AttendanceSessionController::class, 'store']);

        Route::put('/{id}', [AttendanceSessionController::class, 'update']);

        Route::delete('/{id}', [AttendanceSessionController::class, 'destroy']);

    });

Route::prefix('learner-attendance')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [LearnerAttendanceController::class, 'index']);

        Route::get('/{id}', [LearnerAttendanceController::class, 'show']);

        // Legacy direct writes are intentionally disabled; use attendance registers.

    });

Route::prefix('attendance-alerts')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [AttendanceAlertController::class, 'index']);

        Route::get('/{id}', [AttendanceAlertController::class, 'show']);

        // Alerts are system-generated only after register finalization.

    });

/*
|--------------------------------------------------------------------------
| Room Type Routes
|--------------------------------------------------------------------------
*/

Route::prefix('room-types')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [RoomTypeController::class, 'index']);

        Route::get('/{id}', [RoomTypeController::class, 'show']);

        Route::post('/', [RoomTypeController::class, 'store']);

        Route::put('/{id}', [RoomTypeController::class, 'update']);

        Route::delete('/{id}', [RoomTypeController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Room Routes
|--------------------------------------------------------------------------
*/

Route::prefix('rooms')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [RoomController::class, 'index']);

        Route::get('/{id}', [RoomController::class, 'show']);

        Route::post('/', [RoomController::class, 'store']);

        Route::put('/{id}', [RoomController::class, 'update']);

        Route::delete('/{id}', [RoomController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Timetable Routes
|--------------------------------------------------------------------------
*/

$middleware = $secure;

Route::prefix('timetable-profiles')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableProfileController::class, 'index']);

        Route::get('/{id}', [TimetableProfileController::class, 'show']);

        Route::post('/', [TimetableProfileController::class, 'store']);

        Route::put('/{id}', [TimetableProfileController::class, 'update']);

        Route::delete('/{id}', [TimetableProfileController::class, 'destroy']);

    });

Route::prefix('timetable-periods')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetablePeriodController::class, 'index']);

        Route::get('/{id}', [TimetablePeriodController::class, 'show']);

        Route::post('/', [TimetablePeriodController::class, 'store']);

        Route::put('/{id}', [TimetablePeriodController::class, 'update']);

        Route::delete('/{id}', [TimetablePeriodController::class, 'destroy']);

    });

Route::prefix('timetable-entries')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableEntryController::class, 'index']);

        Route::get('/{id}', [TimetableEntryController::class, 'show']);

        // Writes must use /timetables/{timetable}/entries so assignment scope is derived safely.

    });

Route::prefix('timetables')
    ->middleware($middleware)
    ->group(function () {
        Route::get('/', [SmartTimetableController::class, 'index'])->middleware('permission:view_school_timetable');
        Route::post('/', [SmartTimetableController::class, 'store'])->middleware('permission:create_timetable');
        Route::post('/{timetable}/generate', [SmartTimetableAutomationController::class, 'generate'])->middleware('permission:generate_timetable');
        Route::post('/{timetable}/repair', fn (Request $request, string $timetable) => app(SmartTimetableAutomationController::class)->repair($request, $timetable, 'repair'))->middleware('permission:repair_timetable');
        Route::post('/{timetable}/rebalance', fn (Request $request, string $timetable) => app(SmartTimetableAutomationController::class)->repair($request, $timetable, 'rebalance'))->middleware('permission:rebalance_timetable');
        Route::post('/{timetable}/regenerate-unallocated', fn (Request $request, string $timetable) => app(SmartTimetableAutomationController::class)->repair($request, $timetable, 'regenerate_unallocated'))->middleware('permission:repair_timetable');
        Route::post('/{timetable}/create-version', [SmartTimetableAutomationController::class, 'createVersion'])->middleware('permission:create_timetable_versions');
        Route::post('/{timetable}/entries/{entry}/lock', fn (Request $request, string $timetable, string $entry) => app(SmartTimetableAutomationController::class)->lock($request, $timetable, $entry, true))->middleware('permission:lock_timetable_entries');
        Route::post('/{timetable}/entries/{entry}/unlock', fn (Request $request, string $timetable, string $entry) => app(SmartTimetableAutomationController::class)->lock($request, $timetable, $entry, false))->middleware('permission:lock_timetable_entries');
        Route::get('/{timetable}/generation-runs', [SmartTimetableAutomationController::class, 'runs'])->middleware('permission:view_timetable_generation_runs');
        Route::get('/{timetable}/generation-runs/{run}', [SmartTimetableAutomationController::class, 'runs'])->middleware('permission:view_timetable_generation_runs');
        Route::post('/{timetable}/unpublish', [SmartTimetableAutomationController::class, 'unpublish'])->middleware('permission:unpublish_timetable');
        Route::post('/{timetable}/supersede', [SmartTimetableAutomationController::class, 'supersede'])->middleware('permission:supersede_timetable');
        Route::post('/{timetable}/validate', [SmartTimetableController::class, 'validateTimetable'])->middleware('permission:validate_timetable');
        Route::get('/{timetable}/conflicts', [SmartTimetableController::class, 'conflicts'])->middleware('permission:view_school_timetable');
        Route::get('/{timetable}/allocation-summary', [SmartTimetableController::class, 'conflicts'])->middleware('permission:view_timetable_analytics');
        Route::get('/{timetable}/grid', [SmartTimetableController::class, 'grid'])->middleware('permission:view_school_timetable');
        Route::post('/{timetable}/entries', [SmartTimetableController::class, 'entry'])->middleware('permission:edit_timetable');
        Route::put('/{timetable}/entries/{entry}', [SmartTimetableController::class, 'entry'])->middleware('permission:edit_timetable');
        Route::delete('/{timetable}/entries/{entry}', [SmartTimetableController::class, 'deleteEntry'])->middleware('permission:edit_timetable');
        Route::post('/{timetable}/approve', [SmartTimetableController::class, 'approve'])->middleware('permission:approve_timetable');
        Route::post('/{timetable}/publish', [SmartTimetableController::class, 'publish'])->middleware('permission:publish_timetable');
        Route::post('/{timetable}/archive', [SmartTimetableController::class, 'archive'])->middleware('permission:archive_timetable');
        Route::get('/{timetable}', [SmartTimetableController::class, 'show'])->middleware('permission:view_school_timetable');
        Route::put('/{timetable}', [SmartTimetableController::class, 'update'])->middleware('permission:edit_timetable');
    });

Route::prefix('timetable')->middleware($secure)->group(function () {
    Route::get('/substitutions/suggestions', [SmartTimetableAutomationController::class, 'suggestions'])->middleware('permission:manage_timetable_substitutions');
    Route::post('/substitutions', [SmartTimetableAutomationController::class, 'createSubstitution'])->middleware('permission:manage_timetable_substitutions');
    Route::get('/substitutions', [SmartTimetableAutomationController::class, 'substitutions'])->middleware('permission:manage_timetable_substitutions');
    Route::get('/substitutions/{substitution}', [SmartTimetableAutomationController::class, 'substitutions'])->middleware('permission:manage_timetable_substitutions');
    Route::post('/substitutions/{substitution}/approve', fn (Request $request, string $substitution) => app(SmartTimetableAutomationController::class)->substitutionAction($request, $substitution, 'approve'))->middleware('permission:approve_timetable_substitutions');
    Route::post('/substitutions/{substitution}/cancel', fn (Request $request, string $substitution) => app(SmartTimetableAutomationController::class)->substitutionAction($request, $substitution, 'cancel'))->middleware('permission:manage_timetable_substitutions');
    Route::get('/current-period', [SmartTimetableController::class, 'currentPeriod']);
    Route::get('/overview', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_school_timetable');
    Route::get('/analytics', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/teacher-workload', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/room-utilization', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/unallocated', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/conflicts', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
});

Route::prefix('timetable-constraints')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableConstraintController::class, 'index']);

        Route::get('/{id}', [TimetableConstraintController::class, 'show']);

        Route::post('/', [TimetableConstraintController::class, 'store']);

        Route::put('/{id}', [TimetableConstraintController::class, 'update']);

        Route::delete('/{id}', [TimetableConstraintController::class, 'destroy']);

    });

Route::prefix('teacher-constraints')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TeacherConstraintController::class, 'index']);

        Route::get('/{id}', [TeacherConstraintController::class, 'show']);

        Route::post('/', [TeacherConstraintController::class, 'store']);

        Route::put('/{id}', [TeacherConstraintController::class, 'update']);

        Route::delete('/{id}', [TeacherConstraintController::class, 'destroy']);

    });

Route::prefix('teacher-availability')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TeacherAvailabilityController::class, 'index']);

        Route::get('/{id}', [TeacherAvailabilityController::class, 'show']);

        Route::post('/', [TeacherAvailabilityController::class, 'store']);

        Route::put('/{id}', [TeacherAvailabilityController::class, 'update']);

        Route::delete('/{id}', [TeacherAvailabilityController::class, 'destroy']);

    });

Route::prefix('room-constraints')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [RoomConstraintController::class, 'index']);

        Route::get('/{id}', [RoomConstraintController::class, 'show']);

        Route::post('/', [RoomConstraintController::class, 'store']);

        Route::put('/{id}', [RoomConstraintController::class, 'update']);

        Route::delete('/{id}', [RoomConstraintController::class, 'destroy']);

    });

Route::prefix('timetable-conflicts')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableConflictController::class, 'index']);

        Route::get('/{id}', [TimetableConflictController::class, 'show']);

        // Conflicts are generated and resolved by timetable validation.

    });

Route::prefix('timetable-generation-runs')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableGenerationRunController::class, 'index']);

        Route::get('/{id}', [TimetableGenerationRunController::class, 'show']);

        // Automatic generation belongs to Smart Timetable Phase 2.

    });

Route::prefix('timetable-publications')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetablePublicationController::class, 'index']);

        Route::get('/{id}', [TimetablePublicationController::class, 'show']);

        // Publication history is system-managed through the lifecycle service.

    });

Route::prefix('timetable-substitutions')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableSubstitutionController::class, 'index']);

        Route::get('/{id}', [TimetableSubstitutionController::class, 'show']);

        // Phase 2 substitution writes use /timetable/substitutions and its approval workflow.

    });

/*
|--------------------------------------------------------------------------
| FINANCE MASTER DATA
|--------------------------------------------------------------------------
*/

Route::prefix('finance')->middleware($secure)->group(function () {
    Route::get('/dashboard', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/analytics', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/collections', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/outstanding', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/invoice-summary', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/payment-summary', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/ledger-integrity', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:reconcile_fee_ledger');
    Route::get('/settings', [FinanceWorkflowController::class, 'settings'])->middleware('permission:manage_finance_settings');
    Route::put('/settings', [FinanceWorkflowController::class, 'updateSettings'])->middleware('permission:manage_finance_settings');
    Route::get('/fee-categories', [FinanceWorkflowController::class, 'categories'])->middleware('permission:manage_fee_categories');
    Route::post('/fee-categories', [FinanceWorkflowController::class, 'saveCategory'])->middleware('permission:manage_fee_categories');
    Route::get('/fee-categories/{category}', [FinanceWorkflowController::class, 'category'])->middleware('permission:manage_fee_categories');
    Route::put('/fee-categories/{category}', [FinanceWorkflowController::class, 'saveCategory'])->middleware('permission:manage_fee_categories');
    Route::delete('/fee-categories/{category}', [FinanceWorkflowController::class, 'deactivateCategory'])->middleware('permission:manage_fee_categories');
    Route::get('/fee-structures', [FinanceWorkflowController::class, 'structures'])->middleware('permission:manage_fee_structures');
    Route::post('/fee-structures', [FinanceWorkflowController::class, 'saveStructure'])->middleware('permission:manage_fee_structures');
    Route::get('/fee-structures/{structure}', [FinanceWorkflowController::class, 'structure'])->middleware('permission:manage_fee_structures');
    Route::put('/fee-structures/{structure}', [FinanceWorkflowController::class, 'saveStructure'])->middleware('permission:manage_fee_structures');
    Route::post('/fee-structures/{structure}/approve', fn (string $structure) => app(FinanceWorkflowController::class)->structureTransition($structure, 'approved'))->middleware('permission:approve_fee_structures');
    Route::post('/fee-structures/{structure}/activate', fn (string $structure) => app(FinanceWorkflowController::class)->structureTransition($structure, 'active'))->middleware('permission:approve_fee_structures');
    Route::post('/fee-structures/{structure}/archive', fn (string $structure) => app(FinanceWorkflowController::class)->structureTransition($structure, 'archived'))->middleware('permission:manage_fee_structures');
    Route::get('/accounts', [FinanceWorkflowController::class, 'accounts'])->middleware('permission:provision_fee_accounts');
    Route::post('/accounts/provision', [FinanceWorkflowController::class, 'accountProvision'])->middleware('permission:provision_fee_accounts');
    Route::post('/accounts/provision-bulk', [FinanceWorkflowController::class, 'accountProvisionBulk'])->middleware('permission:provision_fee_accounts');
    Route::get('/accounts/{account}/ledger', [FinanceWorkflowController::class, 'ledger'])->middleware('permission:reconcile_fee_ledger');
    Route::post('/accounts/{account}/recalculate', [FinanceWorkflowController::class, 'recalculate'])->middleware('permission:reconcile_fee_ledger');
    Route::get('/accounts/{account}', [FinanceWorkflowController::class, 'account'])->middleware('permission:provision_fee_accounts');
    Route::post('/invoices/generate', [FinanceWorkflowController::class, 'generateInvoice'])->middleware('permission:generate_fee_invoices');
    Route::post('/invoices/generate-bulk', [FinanceWorkflowController::class, 'generateInvoicesBulk'])->middleware('permission:generate_fee_invoices');
    Route::get('/invoices', [FinanceWorkflowController::class, 'invoices'])->middleware('permission:generate_fee_invoices');
    Route::post('/invoices/{invoice}/post', [FinanceWorkflowController::class, 'postInvoice'])->middleware('permission:post_fee_invoices');
    Route::post('/invoices/{invoice}/cancel', [FinanceWorkflowController::class, 'cancelInvoice'])->middleware('permission:cancel_fee_invoices');
    Route::get('/invoices/{invoice}', [FinanceWorkflowController::class, 'invoice'])->middleware('permission:generate_fee_invoices');
    Route::get('/payments', [FinanceWorkflowController::class, 'payments'])->middleware('permission:record_fee_payments');
    Route::get('/payment-methods', [FinanceWorkflowController::class, 'paymentMethods'])->middleware('permission:record_fee_payments');
    Route::post('/payments', [FinanceWorkflowController::class, 'recordPayment'])->middleware('permission:record_fee_payments');
    Route::post('/payments/{payment}/confirm', [FinanceWorkflowController::class, 'confirmPayment'])->middleware('permission:confirm_fee_payments');
    Route::post('/payments/{payment}/reverse', [FinanceWorkflowController::class, 'reversePayment'])->middleware('permission:reverse_fee_payments');
    Route::post('/payments/{payment}/allocate', [FinanceWorkflowController::class, 'allocate'])->middleware('permission:allocate_fee_payments');
    Route::post('/payments/{payment}/auto-allocate', [FinanceWorkflowController::class, 'autoAllocate'])->middleware('permission:allocate_fee_payments');
    Route::get('/payments/{payment}/allocations', [FinanceWorkflowController::class, 'allocations'])->middleware('permission:allocate_fee_payments');
    Route::get('/payments/{payment}/receipt', [FinanceWorkflowController::class, 'receipt'])->middleware('permission:view_finance_receipts');
    Route::get('/payments/{payment}', [FinanceWorkflowController::class, 'payment'])->middleware('permission:record_fee_payments');
    Route::get('/receipts/{receipt}', [FinanceWorkflowController::class, 'receiptNumber'])->middleware('permission:view_finance_receipts');
});

Route::middleware($secure)->group(function () {

    Route::apiResource(

        'fee-categories',

        FeeCategoryController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'payment-plans',

        PaymentPlanController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'payment-methods',

        PaymentMethodController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'finance-settings',

        FinanceSettingController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'fee-structures',

        FeeStructureController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'fee-invoices',

        FeeInvoiceController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'payments',

        PaymentController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'payment-allocations',

        PaymentAllocationController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'learner-fee-accounts',

        LearnerFeeAccountController::class

    )->only(['index', 'show']);

});
