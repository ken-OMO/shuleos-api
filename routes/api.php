<?php

use App\Http\Controllers\Api\AcademicWeekController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AssessmentRegistrationController;
use App\Http\Controllers\Api\AssessmentTypeController;
use App\Http\Controllers\Api\AttendanceAlertController;
use App\Http\Controllers\Api\AttendanceSessionController;
use App\Http\Controllers\Api\AttendanceStatusController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CurriculumCoverageController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamLearningAreaController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\ExamResultController;
use App\Http\Controllers\Api\FeeCategoryController;
use App\Http\Controllers\Api\FeeInvoiceController;
use App\Http\Controllers\Api\FeeStructureController;
use App\Http\Controllers\Api\FinanceSettingController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\LearnerAttendanceController;
use App\Http\Controllers\Api\LearnerController;
use App\Http\Controllers\Api\LearnerFeeAccountController;
use App\Http\Controllers\Api\LearningAreaAllocationController;
use App\Http\Controllers\Api\LearningAreaController;
use App\Http\Controllers\Api\LearningAreaResultController;
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
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\TeacherAssignmentController;
use App\Http\Controllers\Api\TeacherAvailabilityController;
use App\Http\Controllers\Api\TeacherConstraintController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherPortalController;
use App\Http\Controllers\Api\TermController;
use App\Http\Controllers\Api\TimetableConflictController;
use App\Http\Controllers\Api\TimetableConstraintController;
use App\Http\Controllers\Api\TimetableController;
use App\Http\Controllers\Api\TimetableEntryController;
use App\Http\Controllers\Api\TimetableGenerationRunController;
use App\Http\Controllers\Api\TimetablePeriodController;
use App\Http\Controllers\Api\TimetableProfileController;
use App\Http\Controllers\Api\TimetablePublicationController;
use App\Http\Controllers\Api\TimetableSubstitutionController;
use App\Http\Controllers\Api\UserController;
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
    Route::get('/me', [TeacherPortalController::class, 'me']);
    Route::get('/dashboard', [TeacherPortalController::class, 'dashboard']);
    Route::get('/classes', [TeacherPortalController::class, 'classes']);
    Route::get('/learners', [TeacherPortalController::class, 'learners']);
    Route::get('/timetable', [TeacherPortalController::class, 'timetable']);
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

        Route::get('/{id}', [LearnerController::class, 'show']);

        Route::post('/', [LearnerController::class, 'store']);

        Route::put('/{id}', [LearnerController::class, 'update']);

        Route::delete('/{id}', [LearnerController::class, 'destroy']);

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
    Route::get('/me', [ParentPortalController::class, 'me']);
    Route::get('/learners', [ParentPortalController::class, 'learners']);
    Route::get('/learners/{learner}/dashboard', [ParentPortalController::class, 'dashboard']);
    Route::get('/learners/{learner}/report-cards', [ParentPortalController::class, 'reportCards']);
    Route::get('/learners/{learner}/report-cards/{reportCard}/pdf', [ParentPortalController::class, 'reportCardPdf']);
    Route::get('/learners/{learner}/report-cards/{reportCard}', [ParentPortalController::class, 'reportCard']);
    Route::get('/learners/{learner}/attendance', [ParentPortalController::class, 'attendance']);
    Route::get('/learners/{learner}/fees', [ParentPortalController::class, 'fees']);
    Route::get('/announcements', [ParentPortalController::class, 'announcements']);
    Route::get('/notifications', [ParentPortalController::class, 'notifications']);
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

        Route::post('/', [LearnerAttendanceController::class, 'store']);

        Route::put('/{id}', [LearnerAttendanceController::class, 'update']);

        Route::delete('/{id}', [LearnerAttendanceController::class, 'destroy']);

    });

Route::prefix('attendance-alerts')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [AttendanceAlertController::class, 'index']);

        Route::get('/{id}', [AttendanceAlertController::class, 'show']);

        Route::post('/', [AttendanceAlertController::class, 'store']);

        Route::put('/{id}', [AttendanceAlertController::class, 'update']);

        Route::delete('/{id}', [AttendanceAlertController::class, 'destroy']);

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

        Route::post('/', [TimetableEntryController::class, 'store']);

        Route::put('/{id}', [TimetableEntryController::class, 'update']);

        Route::delete('/{id}', [TimetableEntryController::class, 'destroy']);

    });

Route::prefix('timetables')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableController::class, 'index']);

        Route::get('/{id}', [TimetableController::class, 'show']);

        Route::post('/', [TimetableController::class, 'store']);

        Route::put('/{id}', [TimetableController::class, 'update']);

        Route::delete('/{id}', [TimetableController::class, 'destroy']);

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

        Route::post('/', [TimetableConflictController::class, 'store']);

        Route::put('/{id}', [TimetableConflictController::class, 'update']);

        Route::delete('/{id}', [TimetableConflictController::class, 'destroy']);

    });

Route::prefix('timetable-generation-runs')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableGenerationRunController::class, 'index']);

        Route::get('/{id}', [TimetableGenerationRunController::class, 'show']);

        Route::post('/', [TimetableGenerationRunController::class, 'store']);

        Route::put('/{id}', [TimetableGenerationRunController::class, 'update']);

        Route::delete('/{id}', [TimetableGenerationRunController::class, 'destroy']);

    });

Route::prefix('timetable-publications')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetablePublicationController::class, 'index']);

        Route::get('/{id}', [TimetablePublicationController::class, 'show']);

        Route::post('/', [TimetablePublicationController::class, 'store']);

        Route::put('/{id}', [TimetablePublicationController::class, 'update']);

        Route::delete('/{id}', [TimetablePublicationController::class, 'destroy']);

    });

Route::prefix('timetable-substitutions')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableSubstitutionController::class, 'index']);

        Route::get('/{id}', [TimetableSubstitutionController::class, 'show']);

        Route::post('/', [TimetableSubstitutionController::class, 'store']);

        Route::put('/{id}', [TimetableSubstitutionController::class, 'update']);

        Route::delete('/{id}', [TimetableSubstitutionController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| FINANCE MASTER DATA
|--------------------------------------------------------------------------
*/

Route::middleware($secure)->group(function () {

    Route::apiResource(

        'fee-categories',

        FeeCategoryController::class

    );

    Route::apiResource(

        'payment-plans',

        PaymentPlanController::class

    );

    Route::apiResource(

        'payment-methods',

        PaymentMethodController::class

    );

    Route::apiResource(

        'finance-settings',

        FinanceSettingController::class

    );
    Route::apiResource(

        'fee-structures',

        FeeStructureController::class

    );
    Route::apiResource(

        'fee-invoices',

        FeeInvoiceController::class

    );
    Route::apiResource(

        'payments',

        PaymentController::class

    );
    Route::apiResource(

        'payment-allocations',

        PaymentAllocationController::class

    );

    Route::apiResource(

        'learner-fee-accounts',

        LearnerFeeAccountController::class

    );

});
