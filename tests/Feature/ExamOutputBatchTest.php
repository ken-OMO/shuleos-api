<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\ReportCard;
use App\Models\User;
use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\ExamMembershipService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\ExamBenchmarkFixture;
use Tests\TestCase;

class ExamOutputBatchTest extends TestCase
{
    use DatabaseTransactions;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = ExamBenchmarkFixture::create(251);
    }

    public function test_completeness_detects_missing_paper_in_second_batch(): void
    {
        $service = app(ExamCompletenessService::class);
        $before = $service->report(
            $this->fixture['school_id'], $this->fixture['exam_id']
        );

        $this->assertTrue($before['complete']);
        $this->assertSame(251, $before['learner_count']);

        // Completeness orders participants by learner ID.
        $lastLearner = DB::table('exam_roster_learners')
            ->where('exam_id', $this->fixture['exam_id'])
            ->orderBy('learner_id')->offset(250)->value('learner_id');

        $paperId = DB::table('exam_results')
            ->where('exam_id', $this->fixture['exam_id'])
            ->where('learner_id', $lastLearner)->orderBy('id')->value('id');

        DB::table('exam_results')->where('id', $paperId)
            ->update(['is_deleted' => true]);

        $after = $service->report(
            $this->fixture['school_id'], $this->fixture['exam_id']
        );

        $this->assertFalse($after['complete']);
        $this->assertSame(251, $after['learner_count']);
        $this->assertSame(250, $after['complete_learner_count']);

        $last = collect($after['learners'])->firstWhere('learner_id', $lastLearner);
        $this->assertFalse($last['complete']);
        $this->assertTrue(
            collect($last['learning_areas'])->contains(
                fn ($area) => in_array('missing_papers', $area['issues'], true)
            )
        );

        try {
            $service->assertComplete(
                $this->fixture['school_id'], $this->fixture['exam_id']
            );
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());

            return;
        }

        $this->fail('An incomplete second batch must block output generation.');
    }

    public function test_generation_and_regeneration_preserve_all_cards_across_batches(): void
    {
        $this->generateMerits();
        $cards = $this->generateCards();

        $expectedIds = collect($this->fixture['learner_ids'])->sort()->values()->all();
        $this->assertCount(251, $cards);
        $this->assertSame(
            $expectedIds, $cards->pluck('learner_id')->sort()->values()->all()
        );
        $this->assertSame(251, $cards->pluck('id')->unique()->count());

        foreach ($cards as $card) {
            $this->assertTrue($card->relationLoaded('learningAreas'));
            $this->assertCount(6, $card->learningAreas);
            $this->assertSame(6, $card->learningAreas->pluck('learning_area_id')->unique()->count());
            $this->assertSame(480.0, (float) $card->overall_score);
            $this->assertSame(600.0, (float) $card->maximum_marks);

            foreach ($card->learningAreas as $area) {
                $this->assertSame(80.0, (float) $area->marks_obtained);
                $this->assertNotNull($area->grading_snapshot);
            }
        }

        $identities = $cards->pluck('id', 'learner_id')->sortKeys()->all();
        $commented = $cards->last();

        app(ReportCardService::class)->updateComments(
            $this->fixture['school_id'], $commented->id,
            ['class_teacher_comment' => 'Preserve this comment across batches.']
        );

        unset($cards);
        $regenerated = $this->generateCards();

        $this->assertSame(
            $identities,
            $regenerated->pluck('id', 'learner_id')->sortKeys()->all()
        );
        $this->assertSame(
            'Preserve this comment across batches.',
            $regenerated->firstWhere('id', $commented->id)->class_teacher_comment
        );

        $cardIds = array_values($identities);
        $this->assertSame(
            1506,
            DB::table('report_card_learning_areas')
                ->whereIn('report_card_id', $cardIds)
                ->where('is_deleted', false)->count()
        );
    }

    public function test_failure_on_251st_card_rolls_back_first_batch(): void
    {
        $this->generateMerits();

        $dispatcher = ReportCard::getEventDispatcher();
        $isolated = clone $dispatcher;
        $writes = 0;
        $sawEarlierCards = false;
        $schoolId = $this->fixture['school_id'];
        $examId = $this->fixture['exam_id'];

        $isolated->listen(
            'eloquent.saving: '.ReportCard::class,
            function (ReportCard $card) use (
                &$writes, &$sawEarlierCards, $schoolId, $examId
            ): void {
                if ($card->school_id !== $schoolId || $card->exam_id !== $examId) {
                    return;
                }

                $writes++;
                if ($writes === 251) {
                    $sawEarlierCards = DB::table('report_cards')
                        ->where('school_id', $schoolId)
                        ->where('exam_id', $examId)->count() === 250;

                    throw new RuntimeException('Injected second-batch failure.');
                }
            }
        );

        $caught = false;
        ReportCard::setEventDispatcher($isolated);

        try {
            $this->generateCards();
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Injected second-batch failure.', $exception->getMessage()
            );
            $caught = true;
        } finally {
            ReportCard::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($caught);
        $this->assertSame(251, $writes);
        $this->assertTrue($sawEarlierCards);

        $this->assertSame(
            0,
            DB::table('report_cards')
                ->where('school_id', $schoolId)->where('exam_id', $examId)->count()
        );

        $this->assertSame(
            0,
            DB::table('report_card_learning_areas as details')
                ->join('learning_area_results as results', 'results.id', '=', 'details.learning_area_result_id')
                ->where('results.school_id', $schoolId)
                ->where('results.exam_id', $examId)->count()
        );

        // The failure must not leave the operation unusable.
        $this->assertCount(251, $this->generateCards());
    }

    public function test_bulk_membership_preserves_archived_exam_membership_without_mutating_source(): void
    {
        $schoolId = $this->fixture['school_id'];
        $examId = $this->fixture['exam_id'];
        $id = $this->fixture['learner_ids'][0];

        $participant = DB::table('exam_roster_learners')
            ->where('exam_id', $examId)->where('learner_id', $id)->first();

        $otherStream = DB::table('exam_roster_learners')
            ->where('exam_id', $examId)
            ->where('stream_id', '!=', $participant->stream_id)
            ->value('stream_id');

        DB::table('learners')->where('id', $id)->update([
            'stream_id' => $otherStream,
            'active' => false,
            'is_deleted' => true,
        ]);

        $source = Learner::withoutGlobalScopes()->findOrFail($id);
        $before = $source->getAttributes();

        $resolved = app(ExamMembershipService::class)
            ->learnersAtExam($schoolId, $examId, [$source])->get($id);

        $this->assertNotSame($source, $resolved);
        $this->assertSame($participant->grade_id, $resolved->grade_id);
        $this->assertSame($participant->stream_id, $resolved->stream_id);
        $this->assertFalse($resolved->active);
        $this->assertTrue($resolved->is_deleted);
        $this->assertSame($before, $source->getAttributes());
        $this->assertSame(
            $before,
            Learner::withoutGlobalScopes()->findOrFail($id)->getAttributes()
        );
    }

    public function test_bulk_membership_rejects_mixed_school_and_unrostered_inputs(): void
    {
        $schoolId = $this->fixture['school_id'];
        $examId = $this->fixture['exam_id'];
        $source = Learner::withoutGlobalScopes()
            ->findOrFail($this->fixture['learner_ids'][0]);

        $foreign = clone $source;
        $foreign->school_id = (string) Str::uuid();

        $unrostered = clone $source;
        $unrostered->id = (string) Str::uuid();

        foreach ([$foreign, $unrostered] as $invalid) {
            $caught = false;
            try {
                app(ExamMembershipService::class)
                    ->learnersAtExam($schoolId, $examId, [$source, $invalid]);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('learner_id', $exception->errors());
                $caught = true;
            }

            $this->assertTrue($caught, 'Mixed invalid membership must be rejected.');
        }

        $this->assertSame(
            251,
            DB::table('exam_roster_learners')
                ->where('school_id', $schoolId)->where('exam_id', $examId)->count()
        );
    }

    public function test_bulk_membership_queries_do_not_grow_per_learner(): void
    {
        $sources = Learner::withoutGlobalScopes()
            ->where('school_id', $this->fixture['school_id'])
            ->whereIn('id', $this->fixture['learner_ids'])->get();

        $service = app(ExamMembershipService::class);
        $connection = DB::connection();
        $wasLogging = $connection->logging();

        try {
            $connection->enableQueryLog();
            $connection->flushQueryLog();

            $service->learnersAtExam(
                $this->fixture['school_id'], $this->fixture['exam_id'],
                [$sources->first()]
            );
            $singleQueries = count($connection->getQueryLog());

            $connection->flushQueryLog();

            $resolved = $service->learnersAtExam(
                $this->fixture['school_id'], $this->fixture['exam_id'], $sources
            );
            $bulkQueries = count($connection->getQueryLog());
        } finally {
            $connection->flushQueryLog();
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }

        $this->assertCount(251, $resolved);
        $this->assertSame(
            $sources->pluck('id')->sort()->values()->all(),
            $resolved->keys()->sort()->values()->all()
        );
        $this->assertLessThanOrEqual(6, $singleQueries);
        $this->assertLessThanOrEqual(6, $bulkQueries);

        foreach ($sources as $source) {
            $this->assertNotSame($source, $resolved->get($source->id));
        }
    }

    public function test_summary_generation_persists_all_cards_and_details_across_batches(): void
    {
        $this->generateMerits();

        $summary = app(ReportCardService::class)->generateSummary(
            $this->fixture['school_id'],
            $this->fixture['exam_id'],
            null,
            null,
            null,
            $this->fixture['actor_id']
        );

        $this->assertSame([
            'exam_id' => $this->fixture['exam_id'],
            'generated_count' => 251,
            'status' => 'generated',
        ], $summary);

        $cards = ReportCard::query()
            ->where('school_id', $this->fixture['school_id'])
            ->where('exam_id', $this->fixture['exam_id'])
            ->with('learningAreas')->get();

        $this->assertCount(251, $cards);
        $this->assertSame(
            collect($this->fixture['learner_ids'])->sort()->values()->all(),
            $cards->pluck('learner_id')->sort()->values()->all()
        );

        $details = 0;
        foreach ($cards as $card) {
            $this->assertSame('generated', $card->status);
            $this->assertNotNull($card->grading_snapshot);
            $this->assertCount(6, $card->learningAreas);

            foreach ($card->learningAreas as $area) {
                $this->assertNotNull($area->grading_snapshot);
            }

            $details += $card->learningAreas->count();
        }

        $this->assertSame(1506, $details);

        $ids = $cards->pluck('id', 'learner_id')->sortKeys()->all();
        unset($cards);

        $again = app(ReportCardService::class)->generateSummary(
            $this->fixture['school_id'],
            $this->fixture['exam_id'],
            null,
            null,
            null,
            $this->fixture['actor_id']
        );

        $this->assertSame($summary, $again);
        $this->assertSame(
            $ids,
            ReportCard::query()
                ->where('school_id', $this->fixture['school_id'])
                ->where('exam_id', $this->fixture['exam_id'])
                ->pluck('id', 'learner_id')->sortKeys()->all()
        );
    }

    public function test_summary_api_returns_counts_and_keeps_details_available(): void
    {
        $this->generateMerits();
        $this->withoutMiddleware();

        $actor = User::withoutGlobalScopes()
            ->findOrFail($this->fixture['actor_id']);
        Auth::setUser($actor);

        $response = $this->postJson('/api/report-cards/generate', [
            'school_id' => $this->fixture['school_id'],
            'exam_id' => $this->fixture['exam_id'],
            'response_mode' => 'summary',
        ])->assertOk();

        $this->assertSame([
            'exam_id' => $this->fixture['exam_id'],
            'generated_count' => 251,
            'status' => 'generated',
        ], $response->json('data'));

        $card = ReportCard::query()
            ->where('school_id', $this->fixture['school_id'])
            ->where('exam_id', $this->fixture['exam_id'])
            ->firstOrFail();

        $this->getJson(
            '/api/report-cards/'.$card->id.'?school_id='.$this->fixture['school_id']
        )->assertOk()
            ->assertJsonCount(6, 'data.learning_areas');
    }

    public function test_generation_api_rejects_invalid_response_mode_without_writes(): void
    {
        $this->generateMerits();
        $this->withoutMiddleware();

        $actor = User::withoutGlobalScopes()
            ->findOrFail($this->fixture['actor_id']);
        Auth::setUser($actor);

        $this->postJson('/api/report-cards/generate', [
            'school_id' => $this->fixture['school_id'],
            'exam_id' => $this->fixture['exam_id'],
            'response_mode' => 'unsupported',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('response_mode');

        $this->assertSame(
            0,
            DB::table('report_cards')
                ->where('school_id', $this->fixture['school_id'])
                ->where('exam_id', $this->fixture['exam_id'])->count()
        );
    }

    public function test_summary_failure_on_251st_card_rolls_back_all_output_writes(): void
    {
        $this->generateMerits();

        $dispatcher = ReportCard::getEventDispatcher();
        ReportCard::setEventDispatcher(clone $dispatcher);

        $saved = 0;
        $sawFirstBatch = false;
        $caught = false;

        try {
            ReportCard::getEventDispatcher()->listen(
                'eloquent.saving: '.ReportCard::class,
                function (ReportCard $card) use (&$saved, &$sawFirstBatch) {
                    if ($card->school_id !== $this->fixture['school_id']
                        || $card->exam_id !== $this->fixture['exam_id']) {
                        return;
                    }

                    $saved++;
                    if ($saved === 251) {
                        $sawFirstBatch = DB::table('report_cards')
                            ->where('school_id', $this->fixture['school_id'])
                            ->where('exam_id', $this->fixture['exam_id'])
                            ->count() === 250;

                        throw new RuntimeException('Injected summary batch failure.');
                    }
                }
            );

            app(ReportCardService::class)->generateSummary(
                $this->fixture['school_id'],
                $this->fixture['exam_id'],
                null, null, null,
                $this->fixture['actor_id']
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Injected summary batch failure.', $exception->getMessage()
            );
            $caught = true;
        } finally {
            ReportCard::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($caught);
        $this->assertSame(251, $saved);
        $this->assertTrue($sawFirstBatch);

        $this->assertSame(
            0,
            DB::table('report_cards')
                ->where('school_id', $this->fixture['school_id'])
                ->where('exam_id', $this->fixture['exam_id'])->count()
        );

        $this->assertSame(
            0,
            DB::table('report_card_learning_areas as details')
                ->join('learning_area_results as results',
                    'results.id', '=', 'details.learning_area_result_id')
                ->where('results.school_id', $this->fixture['school_id'])
                ->where('results.exam_id', $this->fixture['exam_id'])->count()
        );

        $retry = app(ReportCardService::class)->generateSummary(
            $this->fixture['school_id'],
            $this->fixture['exam_id'],
            null, null, null,
            $this->fixture['actor_id']
        );

        $this->assertSame(251, $retry['generated_count']);
    }

    private function generateMerits(): void
    {
        app(MeritListService::class)->generate(
            $this->fixture['school_id'], $this->fixture['exam_id'],
            null, null, $this->fixture['actor_id']
        );
    }

    private function generateCards()
    {
        return app(ReportCardService::class)->generate(
            $this->fixture['school_id'], $this->fixture['exam_id'],
            null, null, null, $this->fixture['actor_id']
        );
    }
}
