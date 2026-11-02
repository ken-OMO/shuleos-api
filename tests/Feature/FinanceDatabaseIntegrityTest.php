<?php

namespace Tests\Feature;

use App\Models\FeeInvoice;
use App\Models\LearnerFeeAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\Boarding\BedAllocationService;
use App\Services\Finance\FinanceLedgerService;
use App\Services\Finance\FinanceMasterDataService;
use App\Services\Finance\FinancePaymentService;
use App\Services\SchoolSetupReadinessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class FinanceDatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertContains(
            DB::selectOne('SELECT current_database() AS name')->name,
            ['shuleos_assessment_fk_test', 'shuleos_testing']
        );
        $this->a = $this->school();
        $this->b = $this->school();
    }

    private function prepareFinanceHttp(): void
    {
        $this->withMiddleware();
        config(['jwt.secret' => str_repeat('finance-http-isolation-test-', 3)]);

        $names = [
            'view_finance_analytics', 'manage_fee_categories',
            'manage_fee_structures', 'provision_fee_accounts',
            'generate_fee_invoices', 'post_fee_invoices',
            'cancel_fee_invoices', 'record_fee_payments',
            'confirm_fee_payments', 'reverse_fee_payments',
            'allocate_fee_payments', 'view_finance_receipts',
            'reconcile_fee_ledger', 'export_finance_reports',
        ];

        foreach ([$this->a, $this->b] as $s) {
            DB::table('schools')->where('id', $s['school']->id)
                ->update(['lifecycle_state' => 'active']);

            foreach ($names as $name) {
                $permission = DB::table('permissions')
                    ->where('permission_name', $name)->value('id');

                $this->assertNotNull(
                    $permission, 'Missing migrated permission: '.$name
                );

                DB::table('role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'role_id' => $s['user']->role_id,
                    'permission_id' => $permission,
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function financeHttpAs(array $s): void
    {

        $user = User::withoutGlobalScopes()->findOrFail($s['user']->id);
        $token = JWTAuth::fromUser($user);

        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function financeHttpSnapshot(): array
    {
        $snapshot = [];

        foreach ([
            'fee_categories', 'fee_structures', 'fee_invoices',
            'fee_invoice_items', 'payments', 'payment_methods',
            'payment_allocations', 'learner_fee_accounts',
            'learner_fee_ledger', 'finance_audit_logs',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_finance_http_jwt_reads_are_isolated_in_both_directions(): void
    {
        $this->prepareFinanceHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            $this->financeHttpAs($own);

            foreach ([
                ['fee-categories', 'category'],
                ['accounts', 'account'],
                ['invoices', 'invoice'],
                ['payments', 'payment'],
                ['payment-methods', 'method'],
            ] as [$route, $key]) {
                $response = $this->getJson(
                    '/api/finance/'.$route.'?school_id='.$foreign['school']->id
                )->assertOk();

                $this->assertStringContainsString(
                    $own[$key], $response->getContent()
                );
                $this->assertStringNotContainsString(
                    $foreign[$key], $response->getContent()
                );
            }

            $ownReceipt = DB::table('payments')
                ->where('id', $own['payment'])->value('receipt_number');
            $foreignReceipt = DB::table('payments')
                ->where('id', $foreign['payment'])->value('receipt_number');

            foreach ([
                ['fee-categories/'.$own['category'],
                    'fee-categories/'.$foreign['category'], $own['category']],
                ['accounts/'.$own['account'],
                    'accounts/'.$foreign['account'], $own['account']],
                ['invoices/'.$own['invoice'],
                    'invoices/'.$foreign['invoice'], $own['invoice']],
                ['payments/'.$own['payment'],
                    'payments/'.$foreign['payment'], $own['payment']],
                ['accounts/'.$own['account'].'/ledger',
                    'accounts/'.$foreign['account'].'/ledger', $own['ledger']],
                ['accounts/'.$own['account'].'/statement',
                    'accounts/'.$foreign['account'].'/statement', null],
                ['payments/'.$own['payment'].'/allocations',
                    'payments/'.$foreign['payment'].'/allocations', null],
                ['payments/'.$own['payment'].'/receipt',
                    'payments/'.$foreign['payment'].'/receipt', $ownReceipt],
                ['receipts/'.$ownReceipt,
                    'receipts/'.$foreignReceipt, $ownReceipt],
            ] as [$localPath, $foreignPath, $marker]) {
                $response = $this->getJson(
                    '/api/finance/'.$localPath
                )->assertOk();

                if ($marker !== null) {
                    $this->assertStringContainsString(
                        $marker, $response->getContent()
                    );
                }

                $this->assertStringNotContainsString(
                    $foreignReceipt, $response->getContent()
                );

                $this->getJson('/api/finance/'.$foreignPath)
                    ->assertNotFound();
            }
        }
    }

    public function test_finance_http_foreign_mutations_leave_both_schools_unchanged(): void
    {
        $this->prepareFinanceHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            $this->financeHttpAs($own);

            // Establish that an authorised local write works.
            $this->putJson('/api/finance/fee-categories/'.$own['category'], [
                'category_name' => 'Lunch',
                'description' => 'Local update',
            ])->assertOk();

            $before = $this->financeHttpSnapshot();

            $this->putJson('/api/finance/fee-categories/'.$foreign['category'], [
                'category_name' => 'Forbidden change',
            ])->assertNotFound();

            $this->deleteJson(
                '/api/finance/fee-categories/'.$foreign['category']
            )->assertNotFound();

            foreach ([
                ['invoices/'.$foreign['invoice'].'/post', []],
                ['invoices/'.$foreign['invoice'].'/cancel',
                    ['reason' => 'Forbidden cancellation']],
                ['payments/'.$foreign['payment'].'/confirm', []],
                ['payments/'.$foreign['payment'].'/reverse',
                    ['reason' => 'Forbidden reversal']],
                ['payments/'.$foreign['payment'].'/auto-allocate', []],
                ['accounts/'.$foreign['account'].'/recalculate', []],
                ['payments/'.$own['payment'].'/allocate',
                    ['invoice_id' => $foreign['invoice'], 'amount' => '10.00']],
                ['payments/'.$foreign['payment'].'/allocate',
                    ['invoice_id' => $own['invoice'], 'amount' => '10.00']],
            ] as [$route, $payload]) {
                $this->postJson('/api/finance/'.$route, $payload)
                    ->assertNotFound();

                $this->assertSame($before, $this->financeHttpSnapshot());
            }

            $this->assertSame($before, $this->financeHttpSnapshot());

            $this->postJson('/api/finance/payments/'.$own['payment'].'/allocate', [
                'invoice_id' => $own['invoice'],
                'amount' => '10.00',
            ])->assertCreated();

            $this->assertSame(
                '90.00',
                (string) DB::table('fee_invoices')
                    ->where('id', $own['invoice'])->value('balance')
            );
        }
    }

    public function test_finance_http_rejects_foreign_creation_references_both_ways(): void
    {
        $this->prepareFinanceHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            $this->financeHttpAs($own);
            $this->getJson('/api/finance/accounts/'.$own['account'])->assertOk();

            $before = $this->financeHttpSnapshot();

            $this->postJson('/api/finance/accounts/provision', [
                'learner_id' => $foreign['learner']->id,
            ])->assertNotFound();

            $this->postJson('/api/finance/invoices/generate', [
                'learner_id' => $foreign['learner']->id,
                'academic_year_id' => $own['year']->id,
                'term_id' => $own['term']->id,
            ])->assertNotFound();

            $this->postJson('/api/finance/payments', [
                'learner_fee_account_id' => $foreign['account'],
                'payment_method_id' => $own['method'],
                'amount' => '10.00',
                'payment_date' => now()->toDateString(),
                'transaction_reference' => 'TEST-'.Str::uuid(),
            ])->assertNotFound();

            $this->postJson('/api/finance/payments', [
                'learner_fee_account_id' => $own['account'],
                'payment_method_id' => $foreign['method'],
                'amount' => '10.00',
                'payment_date' => now()->toDateString(),
                'transaction_reference' => 'TEST-'.Str::uuid(),
            ])->assertUnprocessable();

            $this->postJson('/api/finance/fee-structures', [
                'academic_year_id' => $own['year']->id,
                'term_id' => $own['term']->id,
                'grade_id' => $own['grade']->id,
                'fee_category_id' => $foreign['category'],
                'amount' => '100.00',
            ])->assertUnprocessable();

            $this->assertSame($before, $this->financeHttpSnapshot());
        }
    }

    public function test_finance_http_client_school_and_status_cannot_control_payment(): void
    {
        $this->prepareFinanceHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            $this->financeHttpAs($own);

            $before = DB::table('payments')
                ->where('school_id', $foreign['school']->id)
                ->orderBy('id')->get()->toJson();

            $response = $this->postJson('/api/finance/payments', [
                'school_id' => $foreign['school']->id,
                'learner_fee_account_id' => $own['account'],
                'payment_method_id' => $own['method'],
                'amount' => '10.00',
                'payment_date' => now()->toDateString(),
                'transaction_reference' => 'TEST-'.Str::uuid(),
                'payment_status' => 'confirmed',
                'allocated_amount' => '10.00',
                'refunded_amount' => '10.00',
                'confirmed_by' => $foreign['user']->id,
            ])->assertCreated();

            $id = $response->json('data.id');
            $this->assertNotEmpty($id);

            $this->assertDatabaseHas('payments', [
                'id' => $id,
                'school_id' => $own['school']->id,
                'learner_id' => $own['learner']->id,
                'payment_status' => 'pending',
                'allocated_amount' => '0.00',
                'refunded_amount' => '0.00',
                'confirmed_by' => null,
            ]);

            $this->assertSame(
                $before,
                DB::table('payments')
                    ->where('school_id', $foreign['school']->id)
                    ->orderBy('id')->get()->toJson()
            );
        }
    }

    public function test_finance_http_requires_authentication_and_action_permission(): void
    {
        $this->prepareFinanceHttp();
        auth()->forgetGuards();

        $this->getJson('/api/finance/payments')->assertUnauthorized();

        $permission = DB::table('permissions')
            ->where('permission_name', 'allocate_fee_payments')->value('id');

        DB::table('role_permissions')
            ->where('role_id', $this->a['user']->role_id)
            ->where('permission_id', $permission)->delete();

        $this->financeHttpAs($this->a);
        $this->getJson('/api/finance/payments/'.$this->a['payment'])->assertOk();

        $before = $this->financeHttpSnapshot();

        $this->postJson('/api/finance/payments/'.$this->a['payment'].'/allocate', [
            'invoice_id' => $this->a['invoice'],
            'amount' => '10.00',
        ])->assertForbidden();

        $this->assertSame($before, $this->financeHttpSnapshot());

        $this->financeHttpAs($this->b);
        $this->postJson('/api/finance/payments/'.$this->b['payment'].'/allocate', [
            'invoice_id' => $this->b['invoice'],
            'amount' => '10.00',
        ])->assertCreated();
    }

    public function test_manual_payment_policy_requires_reference_positive_amount_and_supported_method(): void
    {
        $this->prepareFinanceHttp();
        $this->financeHttpAs($this->a);

        $data = [
            'learner_fee_account_id' => $this->a['account'],
            'payment_method_id' => $this->a['method'],
            'amount' => '10.00',
            'payment_date' => now()->toDateString(),
            'transaction_reference' => 'VALID-REFERENCE',
        ];

        foreach ([null, '', '   '] as $reference) {
            $this->postJson('/api/finance/payments', array_replace($data, [
                'transaction_reference' => $reference,
            ]))->assertUnprocessable()
                ->assertJsonValidationErrors('transaction_reference');
        }

        foreach (['0', '-1', '1.001'] as $amount) {
            $this->postJson('/api/finance/payments', array_replace($data, [
                'amount' => $amount,
            ]))->assertUnprocessable()->assertJsonValidationErrors('amount');
        }

        foreach (['Cash', 'Cheque', 'Other Manual'] as $method) {
            DB::table('payment_methods')->where('id', $this->a['method'])
                ->update(['method_name' => $method]);

            $before = $this->financeHttpSnapshot();

            $this->getJson('/api/finance/payment-methods')->assertOk()
                ->assertJsonMissing(['id' => $this->a['method']]);

            $this->postJson('/api/finance/payments', $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('payment_method_id');

            $this->assertSame($before, $this->financeHttpSnapshot());
        }
    }

    public function test_manual_payment_reference_is_normalized_unique_per_school_and_balance_changes_only_on_allocation(): void
    {
        $this->prepareFinanceHttp();

        foreach ([
            [$this->a, 'M-Pesa', 'mpesa'],
            [$this->b, 'Bank', 'bank'],
        ] as [$school, $method, $channel]) {
            DB::table('payment_methods')->where('id', $school['method'])
                ->update(['method_name' => $method]);

            $this->financeHttpAs($school);

            $this->getJson('/api/finance/payment-methods')->assertOk()
                ->assertJsonFragment(['id' => $school['method']]);

            $data = [
                'learner_fee_account_id' => $school['account'],
                'payment_method_id' => $school['method'],
                'amount' => '25.00',
                'payment_date' => now()->toDateString(),
                'transaction_reference' => ' shared-reference-123 ',
            ];

            $id = $this->postJson('/api/finance/payments', $data)
                ->assertCreated()->json('data.id');

            $this->assertNotEmpty($id);
            $this->assertDatabaseHas('payments', [
                'id' => $id,
                'school_id' => $school['school']->id,
                'payment_channel' => $channel,
                'transaction_reference' => 'SHARED-REFERENCE-123',
                'payment_status' => 'pending',
                'allocated_amount' => '0.00',
            ]);

            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'], 'balance' => '100.00',
            ]);

            $before = $this->financeHttpSnapshot();

            $this->postJson('/api/finance/payments', array_replace($data, [
                'transaction_reference' => 'SHARED-reference-123',
            ]))->assertUnprocessable()
                ->assertJsonValidationErrors('transaction_reference');

            $this->assertSame($before, $this->financeHttpSnapshot());

            $this->postJson('/api/finance/payments/'.$id.'/confirm')
                ->assertOk();

            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'], 'balance' => '100.00',
            ]);

            $this->postJson('/api/finance/payments/'.$id.'/allocate', [
                'invoice_id' => $school['invoice'],
                'amount' => '25.00',
            ])->assertCreated();

            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'],
                'amount_paid' => '25.00',
                'balance' => '75.00',
                'status' => 'partially_paid',
            ]);

            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '75.00',
            ]);
        }
    }

    private function preparePhaseTwoHttp(): void
    {
        $this->prepareFinanceHttp();

        foreach ([$this->a, $this->b] as $school) {
            foreach ([
                'manage_fee_discounts', 'approve_fee_discounts',
                'assign_learner_discounts', 'approve_learner_discounts',
                'apply_fee_discounts', 'reverse_fee_discounts',
                'manage_payment_plans', 'approve_payment_plans',
                'reschedule_payment_plans', 'request_fee_refunds',
                'approve_fee_refunds', 'process_fee_refunds',
                'create_finance_adjustments', 'approve_finance_adjustments',
                'post_finance_adjustments', 'reverse_finance_adjustments',
            ] as $name) {
                $id = DB::table('permissions')
                    ->where('permission_name', $name)->value('id');
                $this->assertNotNull($id, 'Missing permission: '.$name);

                DB::table('role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'role_id' => $school['user']->role_id,
                    'permission_id' => $id,
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function phaseTwoSnapshot(): array
    {
        $snapshot = $this->financeHttpSnapshot();

        foreach ([
            'fee_discounts', 'fee_discount_categories', 'learner_discounts',
            'fee_discount_applications', 'payment_plans',
            'payment_plan_installments', 'payment_plan_invoices',
            'fee_refunds', 'finance_adjustments',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function phaseTwoPlanData(array $school): array
    {
        $balance = DB::table('fee_invoices')
            ->where('id', $school['invoice'])->value('balance');

        return [
            'learner_id' => $school['learner']->id,
            'academic_year_id' => $school['year']->id,
            'term_id' => $school['term']->id,
            'plan_name' => 'Local instalments',
            'invoice_ids' => [$school['invoice']],
            'installments' => [[
                'name' => 'Final instalment',
                'amount' => $balance,
                'due_date' => now()->addDays(30)->toDateString(),
                'installment_order' => 1,
            ]],
        ];
    }

    private function phaseTwoRecords(array $school): array
    {
        $this->financeHttpAs($school);

        $discount = $this->postJson('/api/finance/discounts', [
            'discount_name' => 'Local fee benefit',
            'discount_type' => 'fixed_amount',
            'discount_value' => '10.00',
        ])->assertOk()->json('data.id');
        $this->assertNotEmpty($discount);

        $scholarship = $this->postJson('/api/finance/discounts', [
            'discount_name' => 'Partial scholarship',
            'discount_type' => 'scholarship',
            'discount_value' => '15.00',
        ])->assertOk()->json('data.id');
        $this->assertNotEmpty($scholarship);

        $this->postJson('/api/finance/discounts/'.$scholarship.'/approve')
            ->assertOk();
        $this->postJson('/api/finance/discounts/'.$scholarship.'/activate')
            ->assertOk();

        $assignment = $this->postJson('/api/finance/learner-discounts', [
            'learner_id' => $school['learner']->id,
            'discount_id' => $scholarship,
            'academic_year_id' => $school['year']->id,
            'term_id' => $school['term']->id,
        ])->assertCreated()->json('data.id');
        $this->assertNotEmpty($assignment);

        $this->postJson('/api/finance/payments/'.$school['payment'].'/allocate', [
            'invoice_id' => $school['invoice'],
            'amount' => '20.00',
        ])->assertCreated();

        $refund = $this->postJson('/api/finance/refunds', [
            'payment_id' => $school['payment'],
            'amount' => '5.00',
            'reason' => 'Recorded refund request',
        ])->assertCreated()->json('data.id');
        $this->assertNotEmpty($refund);

        $plan = $this->postJson(
            '/api/finance/payment-plans', $this->phaseTwoPlanData($school)
        )->assertOk()->json('data.id');
        $this->assertNotEmpty($plan);

        $adjustment = $this->postJson('/api/finance/adjustments', [
            'learner_fee_account_id' => $school['account'],
            'adjustment_type' => 'debit_adjustment',
            'amount' => '5.00',
            'reason' => 'Recorded adjustment',
        ])->assertCreated()->json('data.id');
        $this->assertNotEmpty($adjustment);

        return compact('discount', 'scholarship', 'assignment', 'refund', 'plan', 'adjustment');
    }

    public function test_phase_two_http_lists_details_and_mutations_are_isolated_both_ways(): void
    {
        $this->preparePhaseTwoHttp();
        $a = $this->phaseTwoRecords($this->a);
        $b = $this->phaseTwoRecords($this->b);

        foreach ([
            [$this->a, $a, $this->b, $b],
            [$this->b, $b, $this->a, $a],
        ] as [$school, $own, $foreignSchool, $foreign]) {
            $this->financeHttpAs($school);

            foreach ([
                ['discounts', 'discount'],
                ['discounts', 'scholarship'],
                ['learner-discounts', 'assignment'],
                ['payment-plans', 'plan'],
                ['refunds', 'refund'],
                ['adjustments', 'adjustment'],
            ] as [$route, $key]) {
                $response = $this->getJson(
                    '/api/finance/'.$route.'?school_id='.$foreignSchool['school']->id
                )->assertOk();

                $this->assertStringContainsString(
                    $own[$key], $response->getContent()
                );
                $this->assertStringNotContainsString(
                    $foreign[$key], $response->getContent()
                );

                $this->getJson('/api/finance/'.$route.'/'.$own[$key])
                    ->assertOk();
                $this->getJson('/api/finance/'.$route.'/'.$foreign[$key])
                    ->assertNotFound();
            }

            $this->getJson(
                '/api/finance/payment-plans/'.$own['plan'].'/installments'
            )->assertOk();
            $this->getJson(
                '/api/finance/payment-plans/'.$foreign['plan'].'/installments'
            )->assertNotFound();

            $before = $this->phaseTwoSnapshot();

            $this->putJson('/api/finance/discounts/'.$foreign['discount'], [
                'discount_name' => 'Forbidden edit',
                'discount_type' => 'fixed_amount',
                'discount_value' => '11.00',
            ])->assertNotFound();

            $this->putJson(
                '/api/finance/payment-plans/'.$foreign['plan'],
                $this->phaseTwoPlanData($school)
            )->assertNotFound();

            $actions = [
                ['discounts', $foreign['discount'], 'approve'],
                ['discounts', $foreign['scholarship'], 'activate'],
                ['discounts', $foreign['scholarship'], 'archive'],
                ['learner-discounts', $foreign['assignment'], 'approve'],
                ['learner-discounts', $foreign['assignment'], 'cancel'],
                ['payment-plans', $foreign['plan'], 'approve'],
                ['payment-plans', $foreign['plan'], 'activate'],
                ['payment-plans', $foreign['plan'], 'cancel'],
                ['payment-plans', $foreign['plan'], 'reschedule'],
                ['refunds', $foreign['refund'], 'approve'],
                ['refunds', $foreign['refund'], 'reject'],
                ['refunds', $foreign['refund'], 'process'],
                ['refunds', $foreign['refund'], 'cancel'],
                ['adjustments', $foreign['adjustment'], 'submit'],
                ['adjustments', $foreign['adjustment'], 'approve'],
                ['adjustments', $foreign['adjustment'], 'reject'],
                ['adjustments', $foreign['adjustment'], 'post'],
                ['adjustments', $foreign['adjustment'], 'reverse'],
            ];

            foreach ($actions as [$route, $id, $action]) {
                $this->postJson('/api/finance/'.$route.'/'.$id.'/'.$action, [
                    'reason' => 'Foreign operation must be rejected',
                    'due_dates' => [now()->addDays(40)->toDateString()],
                ])->assertNotFound();

                $this->assertSame($before, $this->phaseTwoSnapshot());
            }

            $this->getJson(
                '/api/finance/invoices/'.$foreignSchool['invoice'].'/discounts'
            )->assertNotFound();
            $this->postJson(
                '/api/finance/invoices/'.$foreignSchool['invoice'].'/apply-discounts'
            )->assertNotFound();

            $this->assertSame($before, $this->phaseTwoSnapshot());

            // Positive controls prove local lifecycle routes are accessible.
            $this->postJson('/api/finance/discounts/'.$own['discount'].'/approve')
                ->assertOk();
            $this->postJson('/api/finance/payment-plans/'.$own['plan'].'/approve')
                ->assertOk();
            $this->postJson('/api/finance/adjustments/'.$own['adjustment'].'/submit')
                ->assertOk();

            $this->assertDatabaseHas('fee_discounts', [
                'id' => $own['discount'], 'status' => 'approved',
            ]);
            $this->assertDatabaseHas('payment_plans', [
                'id' => $own['plan'], 'status' => 'approved',
            ]);
            $this->assertDatabaseHas('finance_adjustments', [
                'id' => $own['adjustment'], 'status' => 'submitted',
            ]);
        }
    }

    public function test_phase_two_http_foreign_creation_references_are_rejected_both_ways(): void
    {
        $this->preparePhaseTwoHttp();
        $a = $this->phaseTwoRecords($this->a);
        $b = $this->phaseTwoRecords($this->b);

        foreach ([
            [$this->a, $a, $this->b, $b],
            [$this->b, $b, $this->a, $a],
        ] as [$school, $own, $foreignSchool, $foreign]) {
            $this->financeHttpAs($school);
            $before = $this->phaseTwoSnapshot();

            $this->postJson('/api/finance/discounts', [
                'discount_name' => 'Foreign category benefit',
                'discount_type' => 'fixed_amount',
                'discount_value' => '10.00',
                'fee_category_ids' => [$foreignSchool['category']],
            ])->assertUnprocessable();

            $assignment = [
                'learner_id' => $school['learner']->id,
                'discount_id' => $own['scholarship'],
                'academic_year_id' => $school['year']->id,
                'term_id' => $school['term']->id,
            ];

            $this->postJson('/api/finance/learner-discounts', array_replace(
                $assignment, ['learner_id' => $foreignSchool['learner']->id]
            ))->assertNotFound();

            $this->postJson('/api/finance/learner-discounts', array_replace(
                $assignment, ['discount_id' => $foreign['scholarship']]
            ))->assertUnprocessable();

            $this->postJson('/api/finance/payment-plans', array_replace(
                $this->phaseTwoPlanData($school),
                ['invoice_ids' => [$foreignSchool['invoice']]]
            ))->assertUnprocessable();

            $this->postJson('/api/finance/refunds', [
                'payment_id' => $foreignSchool['payment'],
                'amount' => '1.00',
                'reason' => 'Foreign payment',
            ])->assertNotFound();

            $this->postJson('/api/finance/adjustments', [
                'learner_fee_account_id' => $foreignSchool['account'],
                'adjustment_type' => 'debit_adjustment',
                'amount' => '1.00',
                'reason' => 'Foreign account',
            ])->assertNotFound();

            $this->assertSame($before, $this->phaseTwoSnapshot());
        }
    }

    private function phaseTwoChecker(array $school): array
    {
        $record = UserBuilder::create(
            $school['school'],
            (object) ['id' => $school['user']->role_id]
        );

        $checker = $school;
        $checker['user'] = User::withoutGlobalScopes()->findOrFail($record->id);

        return $checker;
    }

    private function phaseTwoSchoolSnapshot(array $school): array
    {
        $snapshot = [];

        foreach ([
            'fee_invoices', 'fee_invoice_items', 'payments',
            'payment_allocations', 'learner_fee_accounts', 'learner_fee_ledger',
            'fee_discounts', 'learner_discounts', 'fee_discount_applications',
            'payment_plans', 'payment_plan_installments', 'payment_plan_invoices',
            'fee_refunds', 'finance_adjustments', 'finance_audit_logs',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->where('school_id', $school['school']->id)
                ->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_phase_two_workflow_refund_requires_another_officer_and_updates_balances(): void
    {
        $this->preparePhaseTwoHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$school, $foreign]) {
            $records = $this->phaseTwoRecords($school);
            $checker = $this->phaseTwoChecker($school);
            $foreignBefore = $this->phaseTwoSchoolSnapshot($foreign);
            $url = '/api/finance/refunds/'.$records['refund'];

            $this->financeHttpAs($school);
            $before = $this->phaseTwoSnapshot();

            $this->postJson($url.'/approve')
                ->assertUnprocessable()->assertJsonValidationErrors('approval');
            $this->assertSame($before, $this->phaseTwoSnapshot());

            $this->financeHttpAs($checker);
            $this->postJson($url.'/approve')->assertOk();

            $this->assertDatabaseHas('fee_refunds', [
                'id' => $records['refund'],
                'status' => 'approved',
                'requested_by' => $school['user']->id,
                'approved_by' => $checker['user']->id,
            ]);
            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'],
                'amount_paid' => '20.00', 'balance' => '80.00',
            ]);

            $this->postJson($url.'/process')->assertOk();

            $this->assertDatabaseHas('fee_refunds', [
                'id' => $records['refund'],
                'status' => 'processed',
                'processed_by' => $checker['user']->id,
            ]);
            $this->assertDatabaseHas('payments', [
                'id' => $school['payment'], 'refunded_amount' => '5.00',
            ]);
            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'],
                'amount_paid' => '15.00', 'balance' => '85.00',
            ]);
            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '85.00',
            ]);
            $this->assertSame(
                1,
                DB::table('learner_fee_ledger')
                    ->where('school_id', $school['school']->id)
                    ->where('reference_type', 'fee_refund')
                    ->where('reference_id', $records['refund'])->count()
            );

            $before = $this->phaseTwoSnapshot();
            $this->postJson($url.'/process')->assertNotFound();
            $this->assertSame($before, $this->phaseTwoSnapshot());
            $this->assertSame(
                $foreignBefore, $this->phaseTwoSchoolSnapshot($foreign)
            );
        }
    }

    public function test_phase_two_workflow_adjustment_requires_another_officer_before_posting(): void
    {
        $this->preparePhaseTwoHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$school, $foreign]) {
            $records = $this->phaseTwoRecords($school);
            $checker = $this->phaseTwoChecker($school);
            $foreignBefore = $this->phaseTwoSchoolSnapshot($foreign);
            $url = '/api/finance/adjustments/'.$records['adjustment'];

            $this->financeHttpAs($school);
            $before = $this->phaseTwoSnapshot();
            $this->postJson($url.'/post')->assertUnprocessable();
            $this->assertSame($before, $this->phaseTwoSnapshot());

            $this->postJson($url.'/submit')->assertOk();
            $before = $this->phaseTwoSnapshot();
            $this->postJson($url.'/approve')
                ->assertUnprocessable()->assertJsonValidationErrors('approval');
            $this->assertSame($before, $this->phaseTwoSnapshot());

            $this->financeHttpAs($checker);
            $this->postJson($url.'/approve')->assertOk();
            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '80.00',
            ]);

            $this->postJson($url.'/post')->assertOk();
            $this->assertDatabaseHas('finance_adjustments', [
                'id' => $records['adjustment'],
                'status' => 'posted',
                'created_by' => $school['user']->id,
                'approved_by' => $checker['user']->id,
                'posted_by' => $checker['user']->id,
            ]);
            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '85.00',
            ]);

            // Account adjustments do not rewrite individual invoice charges.
            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'], 'balance' => '80.00',
            ]);

            $before = $this->phaseTwoSnapshot();
            $this->postJson($url.'/post')->assertUnprocessable();
            $this->assertSame($before, $this->phaseTwoSnapshot());

            $this->postJson($url.'/reverse', [
                'reason' => 'Reverse the test adjustment',
            ])->assertOk();
            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '80.00',
            ]);

            $this->assertSame(
                2,
                DB::table('learner_fee_ledger')
                    ->where('school_id', $school['school']->id)
                    ->where('reference_type', 'finance_adjustment')
                    ->where('reference_id', $records['adjustment'])->count()
            );
            $this->assertSame(
                $foreignBefore, $this->phaseTwoSchoolSnapshot($foreign)
            );
        }
    }

    public function test_phase_two_workflow_partial_scholarship_reduces_balance_once_and_can_be_reversed(): void
    {
        $this->preparePhaseTwoHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$school, $foreign]) {
            $records = $this->phaseTwoRecords($school);
            $foreignBefore = $this->phaseTwoSchoolSnapshot($foreign);

            $this->financeHttpAs($school);
            $this->postJson(
                '/api/finance/learner-discounts/'.$records['assignment'].'/approve'
            )->assertOk();

            $url = '/api/finance/invoices/'.$school['invoice'];
            $this->postJson($url.'/apply-discounts')->assertOk();

            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'],
                'total_amount' => '85.00',
                'amount_paid' => '20.00',
                'balance' => '65.00',
            ]);
            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '65.00',
            ]);

            $application = DB::table('fee_discount_applications')
                ->where('school_id', $school['school']->id)
                ->where('invoice_id', $school['invoice'])
                ->where('learner_discount_id', $records['assignment'])
                ->first();

            $this->assertNotNull($application);
            $this->assertSame('15.00', (string) $application->discount_amount);

            $before = $this->phaseTwoSnapshot();
            $this->postJson($url.'/apply-discounts')->assertOk();
            $this->assertSame($before, $this->phaseTwoSnapshot());

            $this->getJson($url.'/discounts')->assertOk()
                ->assertJsonFragment(['id' => $application->id]);

            // The other school cannot view or reverse the benefit.
            $this->financeHttpAs($foreign);
            $before = $this->phaseTwoSnapshot();
            $this->getJson($url.'/discounts')->assertNotFound();
            $this->postJson($url.'/discounts/'.$application->id.'/reverse', [
                'reason' => 'Foreign reversal',
            ])->assertNotFound();
            $this->assertSame($before, $this->phaseTwoSnapshot());

            $this->financeHttpAs($school);
            $this->postJson($url.'/discounts/'.$application->id.'/reverse', [
                'reason' => 'Benefit withdrawn',
            ])->assertOk();

            $this->assertDatabaseHas('fee_invoices', [
                'id' => $school['invoice'],
                'total_amount' => '100.00',
                'amount_paid' => '20.00',
                'balance' => '80.00',
            ]);
            $this->assertDatabaseHas('learner_fee_accounts', [
                'id' => $school['account'], 'current_balance' => '80.00',
            ]);
            $this->assertSame(
                $foreignBefore, $this->phaseTwoSchoolSnapshot($foreign)
            );
        }
    }

    public function test_charging_acceptance_school_defined_rates_follow_grade_education_levels(): void
    {
        $this->prepareFinanceHttp();

        $levels = [];
        foreach (['Primary acceptance', 'Junior acceptance'] as $index => $name) {
            $id = (string) Str::uuid();
            DB::table('education_levels')->insert([
                'id' => $id,
                'level_name' => $name.' '.$id,
                'level_order' => 900 + $index,
                'active' => true,
            ]);
            $levels[] = (object) ['id' => $id];
        }

        $invoices = [];

        foreach ([
            [$this->a, ['125.00', '240.00']],
            [$this->b, ['175.00', '310.00']],
        ] as [$school, $rates]) {
            $permission = DB::table('permissions')
                ->where('permission_name', 'approve_fee_structures')->value('id');
            $this->assertNotNull($permission);

            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $school['user']->role_id,
                'permission_id' => $permission,
                'created_at' => now(),
            ]);

            DB::table('schools')->where('id', $school['school']->id)
                ->update(['operating_mode' => 'day_and_boarding']);
            $this->financeHttpAs($school);

            $category = $this->postJson('/api/finance/fee-categories', [
                'category_name' => 'School-defined activity charge',
                'description' => 'A charge named and priced by this school',
            ])->assertOk()->json('data.id');
            $this->assertNotEmpty($category);

            foreach ($levels as $index => $level) {
                $grade = GradeBuilder::create($school['school'], $level);
                $stream = StreamBuilder::create($school['school'], $grade);
                $learner = LearnerBuilder::create(
                    $school['school'], $grade, $stream, ['mode_of_study' => 'day_scholar']
                );

                $structure = $this->postJson('/api/finance/fee-structures', [
                    'academic_year_id' => $school['year']->id,
                    'term_id' => $school['term']->id,
                    'grade_id' => $grade->id,
                    'fee_category_id' => $category,
                    'amount' => $rates[$index],
                ])->assertOk()->json('data.id');
                $this->assertNotEmpty($structure);

                $this->postJson(
                    '/api/finance/fee-structures/'.$structure.'/approve'
                )->assertOk();
                $this->postJson(
                    '/api/finance/fee-structures/'.$structure.'/activate'
                )->assertOk();

                $data = [
                    'learner_id' => $learner->id,
                    'academic_year_id' => $school['year']->id,
                    'term_id' => $school['term']->id,
                ];

                $invoice = $this->postJson('/api/finance/invoices/generate', $data)
                    ->assertCreated()->json('data.id');
                $this->assertNotEmpty($invoice);

                $this->assertDatabaseHas('grades', [
                    'id' => $grade->id,
                    'school_id' => $school['school']->id,
                    'education_level_id' => $level->id,
                ]);
                $this->assertDatabaseHas('fee_invoices', [
                    'id' => $invoice,
                    'school_id' => $school['school']->id,
                    'learner_id' => $learner->id,
                    'total_amount' => $rates[$index],
                    'balance' => $rates[$index],
                    'status' => 'draft',
                ]);
                $this->assertDatabaseHas('fee_invoice_items', [
                    'invoice_id' => $invoice,
                    'fee_category_id' => $category,
                    'amount' => $rates[$index],
                ]);
                $this->assertSame(
                    1,
                    DB::table('fee_invoice_items')
                        ->where('invoice_id', $invoice)->count()
                );

                $this->postJson('/api/finance/invoices/'.$invoice.'/post')
                    ->assertOk();

                $this->assertDatabaseHas('learner_fee_accounts', [
                    'school_id' => $school['school']->id,
                    'learner_id' => $learner->id,
                    'current_balance' => $rates[$index],
                ]);

                // Regeneration returns the existing invoice without duplicating it.
                $repeated = $this->postJson(
                    '/api/finance/invoices/generate', $data
                )->assertCreated()->json('data.id');
                $this->assertSame($invoice, $repeated);
                $this->assertSame(
                    1,
                    DB::table('fee_invoices')
                        ->where('school_id', $school['school']->id)
                        ->where('learner_id', $learner->id)
                        ->where('term_id', $school['term']->id)->count()
                );

                $invoices[] = [$school, $invoice];
            }
        }

        foreach ($invoices as [$owner, $invoice]) {
            $foreign = $owner['school']->id === $this->a['school']->id
                ? $this->b : $this->a;

            $this->financeHttpAs($foreign);
            $this->getJson('/api/finance/invoices/'.$invoice)->assertNotFound();
        }
    }

    public function test_operating_mode_boarding_access_uses_the_authenticated_school(): void
    {
        $this->prepareFinanceHttp();

        foreach ([$this->a, $this->b] as $school) {
            $id = DB::table('permissions')
                ->where('permission_name', 'manage_boarding')->value('id');
            $this->assertNotNull($id);

            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $school['user']->role_id,
                'permission_id' => $id,
                'created_at' => now(),
            ]);
        }

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            DB::table('schools')->where('id', $foreign['school']->id)
                ->update(['operating_mode' => 'boarding']);

            $this->financeHttpAs($own);

            foreach ([null, 'day'] as $mode) {
                DB::table('schools')->where('id', $own['school']->id)
                    ->update(['operating_mode' => $mode]);

                $this->getJson(
                    '/api/boarding/hostels?school_id='.$foreign['school']->id
                )->assertForbidden();

                // Service calls cannot bypass the boarding-mode restriction.
                try {
                    app(BedAllocationService::class)
                        ->allocate(
                            $own['school']->id,
                            $own['learner']->id,
                            (string) Str::uuid(),
                            $own['user']->id
                        );
                    $this->fail('Disabled boarding allocation was accepted.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }

            foreach (['boarding', 'day_and_boarding'] as $mode) {
                DB::table('schools')->where('id', $own['school']->id)
                    ->update(['operating_mode' => $mode]);

                $this->getJson('/api/boarding/hostels')->assertOk();
            }
        }
    }

    private function prepareAdmissionModeHttp(): void
    {
        $this->prepareFinanceHttp();

        foreach ([$this->a, $this->b] as $school) {
            $id = DB::table('permissions')
                ->where('permission_name', 'manage_learners')->value('id');
            $this->assertNotNull($id);

            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $school['user']->role_id,
                'permission_id' => $id,
                'created_at' => now(),
            ]);

            DB::table('schools')->where('id', $school['school']->id)->update([
                'short_name' => 'MODE',
                'registration_number' => 'MODE-'.$school['school']->id,
                'school_type' => 'Primary',
                'county' => 'Nairobi',
                'phone' => '+254700123456',
                'email' => 'mode-'.$school['school']->id.'@example.test',
                'timezone' => 'Africa/Nairobi',
                'locale' => 'en',
                'operating_mode' => 'day_and_boarding',
            ]);

            $readiness = app(SchoolSetupReadinessService::class)
                ->readiness($school['school']->id);
            $this->assertTrue(
                $readiness['setup_complete'],
                json_encode($readiness, JSON_THROW_ON_ERROR)
            );

            // Grade 2 does not require an assessment number.
            DB::table('grades')->where('id', $school['grade']->id)
                ->update(['grade_number' => 2]);
        }
    }

    private function admissionModeData(array $school, string $mode): array
    {
        return [
            'admission_no' => 'MODE-'.Str::upper(Str::random(12)),
            'first_name' => 'Admission',
            'last_name' => 'Acceptance',
            'grade_id' => $school['grade']->id,
            'stream_id' => $school['stream']->id,
            'mode_of_study' => $mode,
        ];
    }

    public function test_admission_mode_supported_classifications_are_saved_for_each_school(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            $this->financeHttpAs($own);
            $foreignBefore = DB::table('learners')
                ->where('school_id', $foreign['school']->id)
                ->orderBy('id')->get()->toJson();

            foreach ([
                ['day', 'day_scholar'],
                ['boarding', 'boarder'],
                ['day_and_boarding', 'day_scholar'],
                ['day_and_boarding', 'boarder'],
            ] as [$schoolMode, $learnerMode]) {
                DB::table('schools')->where('id', $own['school']->id)
                    ->update(['operating_mode' => $schoolMode]);

                $data = $this->admissionModeData($own, $learnerMode);
                $id = $this->postJson('/api/learners', $data)
                    ->assertCreated()
                    ->assertJsonPath('data.mode_of_study', $learnerMode)
                    ->json('data.id');
                $this->assertNotEmpty($id);

                $this->assertDatabaseHas('learners', [
                    'id' => $id,
                    'school_id' => $own['school']->id,
                    'admission_no' => $data['admission_no'],
                    'mode_of_study' => $learnerMode,
                    'lifecycle_status' => 'active',
                    'active' => true,
                ]);
                $this->assertDatabaseHas('audit_logs', [
                    'school_id' => $own['school']->id,
                    'user_id' => $own['user']->id,
                    'table_name' => 'learners',
                    'record_id' => $id,
                    'action' => 'Create',
                ]);
            }

            $this->assertSame(
                $foreignBefore,
                DB::table('learners')->where('school_id', $foreign['school']->id)
                    ->orderBy('id')->get()->toJson()
            );
        }
    }

    public function test_admission_mode_rejects_missing_unknown_and_incompatible_classification(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([$this->a, $this->b] as $school) {
            $this->financeHttpAs($school);

            foreach ([
                ['day', 'boarder'],
                ['boarding', 'day_scholar'],
                [null, 'boarder'],
                [null, 'day_scholar'],
                ['day_and_boarding', 'unknown'],
            ] as [$schoolMode, $learnerMode]) {
                DB::table('schools')->where('id', $school['school']->id)
                    ->update(['operating_mode' => $schoolMode]);

                $data = $this->admissionModeData($school, $learnerMode);
                $count = DB::table('learners')->count();
                $auditCount = DB::table('audit_logs')->count();

                $response = $this->postJson('/api/learners', $data);

                if ($schoolMode === null) {
                    $response->assertForbidden();
                } else {
                    $response->assertUnprocessable()
                        ->assertJsonValidationErrors('mode_of_study');
                }

                $this->assertSame($count, DB::table('learners')->count());
                $this->assertSame($auditCount, DB::table('audit_logs')->count());
            }

            $data = $this->admissionModeData($school, 'day_scholar');
            unset($data['mode_of_study']);

            $this->postJson('/api/learners', $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('mode_of_study');
        }
    }

    public function test_admission_mode_cannot_use_foreign_school_or_foreign_placement(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            DB::table('schools')->where('id', $own['school']->id)
                ->update(['operating_mode' => 'day']);
            DB::table('schools')->where('id', $foreign['school']->id)
                ->update(['operating_mode' => 'boarding']);

            $this->financeHttpAs($own);
            $count = DB::table('learners')->count();

            $data = $this->admissionModeData($own, 'boarder');
            $data['school_id'] = $foreign['school']->id;

            $this->postJson('/api/learners', $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('mode_of_study');

            $data = $this->admissionModeData($own, 'day_scholar');
            $data['grade_id'] = $foreign['grade']->id;
            $data['stream_id'] = $foreign['stream']->id;

            $this->postJson('/api/learners', $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('grade_id');

            $this->assertSame($count, DB::table('learners')->count());
        }
    }

    private function activationModeSnapshot(): array
    {
        $result = [];

        foreach ([
            'learners', 'learner_lifecycle_history',
            'learner_mode_of_study_history', 'audit_logs',
        ] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_activation_mode_reactivation_requires_compatible_classification_both_ways(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([
            [$this->a, $this->b, 'day', 'boarder', 'day_scholar'],
            [$this->b, $this->a, 'boarding', 'day_scholar', 'boarder'],
        ] as [$school, $foreign, $schoolMode, $oldMode, $newMode]) {
            DB::table('schools')->where('id', $school['school']->id)
                ->update(['operating_mode' => $schoolMode]);
            DB::table('learners')->where('id', $school['learner']->id)->update([
                'lifecycle_status' => 'withdrawn',
                'active' => false,
                'mode_of_study' => $oldMode,
            ]);

            $this->financeHttpAs($school);
            $url = '/api/learners/'.$school['learner']->id.'/lifecycle';
            $data = [
                'status' => 'active',
                'effective_date' => now()->toDateString(),
                'reason' => 'Readmission after school-mode change',
            ];

            $before = $this->activationModeSnapshot();
            $this->patchJson($url, $data)->assertUnprocessable()
                ->assertJsonValidationErrors('mode_of_study');
            $this->assertSame($before, $this->activationModeSnapshot());

            $this->patchJson($url, $data + ['mode_of_study' => $newMode])
                ->assertOk();

            $this->assertDatabaseHas('learners', [
                'id' => $school['learner']->id,
                'mode_of_study' => $newMode,
                'lifecycle_status' => 'active',
                'active' => true,
            ]);
            $this->assertDatabaseHas('learner_mode_of_study_history', [
                'school_id' => $school['school']->id,
                'learner_id' => $school['learner']->id,
                'from_mode' => $oldMode,
                'to_mode' => $newMode,
                'changed_by' => $school['user']->id,
            ]);
            $this->assertDatabaseHas('learner_lifecycle_history', [
                'school_id' => $school['school']->id,
                'learner_id' => $school['learner']->id,
                'from_status' => 'withdrawn',
                'to_status' => 'active',
            ]);

            $this->financeHttpAs($foreign);
            $before = $this->activationModeSnapshot();
            $this->patchJson($url, $data + ['mode_of_study' => $newMode])
                ->assertNotFound();
            $this->assertSame($before, $this->activationModeSnapshot());
        }
    }

    public function test_activation_mode_archived_active_learner_requires_compatible_restoration(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([
            [$this->a, 'day', 'boarder', 'day_scholar'],
            [$this->b, 'boarding', 'day_scholar', 'boarder'],
        ] as [$school, $schoolMode, $oldMode, $newMode]) {
            DB::table('schools')->where('id', $school['school']->id)
                ->update(['operating_mode' => $schoolMode]);
            DB::table('learners')->where('id', $school['learner']->id)->update([
                'mode_of_study' => $oldMode,
                'lifecycle_status' => 'active',
                'active' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

            $this->financeHttpAs($school);
            $url = '/api/learners/'.$school['learner']->id.'/restore';
            $data = ['reason' => 'Restore archived learner'];

            $before = $this->activationModeSnapshot();
            $this->postJson($url, $data)->assertUnprocessable()
                ->assertJsonValidationErrors('mode_of_study');
            $this->assertSame($before, $this->activationModeSnapshot());

            $this->postJson($url, $data + ['mode_of_study' => $newMode])
                ->assertOk();

            $this->assertDatabaseHas('learners', [
                'id' => $school['learner']->id,
                'mode_of_study' => $newMode,
                'lifecycle_status' => 'active',
                'is_deleted' => false,
                'active' => true,
            ]);
            $this->assertDatabaseHas('learner_mode_of_study_history', [
                'learner_id' => $school['learner']->id,
                'from_mode' => $oldMode,
                'to_mode' => $newMode,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'school_id' => $school['school']->id,
                'record_id' => $school['learner']->id,
                'action' => 'Restore',
            ]);
        }
    }

    public function test_activation_mode_restoring_terminal_history_does_not_activate_learner(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([$this->a, $this->b] as $school) {
            DB::table('schools')->where('id', $school['school']->id)
                ->update(['operating_mode' => 'day']);
            DB::table('learners')->where('id', $school['learner']->id)->update([
                'mode_of_study' => 'boarder',
                'lifecycle_status' => 'withdrawn',
                'active' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

            $this->financeHttpAs($school);
            $historyBefore = DB::table('learner_mode_of_study_history')->count();

            $this->postJson(
                '/api/learners/'.$school['learner']->id.'/restore',
                ['reason' => 'Restore historical record without reactivation']
            )->assertOk();

            $this->assertDatabaseHas('learners', [
                'id' => $school['learner']->id,
                'mode_of_study' => 'boarder',
                'lifecycle_status' => 'withdrawn',
                'is_deleted' => false,
                'active' => false,
            ]);
            $this->assertSame(
                $historyBefore, DB::table('learner_mode_of_study_history')->count()
            );
        }
    }

    public function test_classification_change_preserves_financial_history_both_directions(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$school, $foreign]) {
            DB::table('schools')->where('id', $school['school']->id)
                ->update(['operating_mode' => 'day_and_boarding']);
            DB::table('learners')->where('id', $school['learner']->id)->update([
                'mode_of_study' => 'day_scholar',
                'lifecycle_status' => 'active',
                'active' => true,
            ]);

            $this->financeHttpAs($school);
            $url = '/api/learners/'.$school['learner']->id.'/mode-of-study';
            $financialBefore = $this->phaseTwoSnapshot();
            $from = 'day_scholar';

            foreach (['boarder', 'day_scholar'] as $to) {
                $this->patchJson($url, [
                    'mode_of_study' => $to,
                    'effective_date' => now()->toDateString(),
                    'reason' => 'Approved change in study arrangement',
                ])->assertOk()
                    ->assertJsonPath('data.mode_of_study', $to)
                    ->assertJsonPath('data.change.effective_date', now()->toDateString());

                $this->assertDatabaseHas('learner_mode_of_study_history', [
                    'school_id' => $school['school']->id,
                    'learner_id' => $school['learner']->id,
                    'from_mode' => $from,
                    'to_mode' => $to,
                    'effective_date' => now()->toDateString(),
                    'changed_by' => $school['user']->id,
                ]);
                $this->assertDatabaseHas('audit_logs', [
                    'school_id' => $school['school']->id,
                    'record_id' => $school['learner']->id,
                    'module' => 'Learner Mode of Study',
                    'action' => 'Change',
                ]);
                $this->assertSame($financialBefore, $this->phaseTwoSnapshot());
                $from = $to;
            }

            $this->financeHttpAs($foreign);
            $before = $this->activationModeSnapshot();

            $this->patchJson($url, [
                'mode_of_study' => 'boarder',
                'effective_date' => now()->toDateString(),
                'reason' => 'Foreign change must fail',
            ])->assertNotFound();

            $this->assertSame($before, $this->activationModeSnapshot());
        }
    }

    public function test_classification_change_requires_reason_and_nonfuture_effective_date(): void
    {
        $this->prepareAdmissionModeHttp();
        DB::table('schools')->where('id', $this->a['school']->id)
            ->update(['operating_mode' => 'day_and_boarding']);
        DB::table('learners')->where('id', $this->a['learner']->id)->update([
            'mode_of_study' => 'day_scholar',
            'lifecycle_status' => 'active',
            'active' => true,
        ]);

        $this->financeHttpAs($this->a);
        $url = '/api/learners/'.$this->a['learner']->id.'/mode-of-study';
        $before = $this->activationModeSnapshot();

        foreach ([
            ['mode_of_study' => 'boarder'],
            [
                'mode_of_study' => 'boarder',
                'effective_date' => now()->toDateString(),
                'reason' => '   ',
            ],
            [
                'mode_of_study' => 'boarder',
                'effective_date' => now()->addDay()->toDateString(),
                'reason' => 'Future classification is not scheduled here',
            ],
        ] as $data) {
            $this->patchJson($url, $data)->assertUnprocessable();
            $this->assertSame($before, $this->activationModeSnapshot());
        }
    }

    public function test_charge_eligibility_targets_modes_and_explicit_participation_in_both_schools(): void
    {
        $this->prepareAdmissionModeHttp();

        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$school, $foreign]) {
            $this->financeHttpAs($school);

            $id = DB::table('permissions')
                ->where('permission_name', 'approve_fee_structures')->value('id');
            $this->assertNotNull($id);
            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $school['user']->role_id,
                'permission_id' => $id,
                'created_at' => now(),
            ]);

            $categories = [];
            foreach (['Tuition custom', 'Meals custom', 'Transport custom', 'Activity custom'] as $name) {
                $categories[] = $this->postJson('/api/finance/fee-categories', [
                    'category_name' => $name,
                ])->assertOk()->json('data.id');
            }

            foreach ([
                [$categories[0], 'all', '100.00', false],
                [$categories[0], 'boarder', '150.00', false],
                [$categories[1], 'boarder', '40.00', false],
                [$categories[2], 'boarder', '15.00', false],
                [$categories[3], 'all', '7.00', true],
            ] as [$category, $target, $amount, $participation]) {
                $structure = $this->postJson('/api/finance/fee-structures', [
                    'academic_year_id' => $school['year']->id,
                    'term_id' => $school['term']->id,
                    'grade_id' => $school['grade']->id,
                    'fee_category_id' => $category,
                    'charge_target' => $target,
                    'requires_participation' => $participation,
                    'amount' => $amount,
                ])->assertOk()->json('data.id');
                $this->assertNotEmpty($structure);

                $this->postJson('/api/finance/fee-structures/'.$structure.'/approve')
                    ->assertOk();
                $this->postJson('/api/finance/fee-structures/'.$structure.'/activate')
                    ->assertOk();
            }

            foreach ([
                ['day_scholar', false, '100.00', 1],
                ['boarder', false, '205.00', 3],
                ['day_scholar', true, '107.00', 2],
                ['boarder', true, '212.00', 4],
            ] as [$mode, $participating, $total, $itemCount]) {
                $learnerId = $this->postJson(
                    '/api/learners', $this->admissionModeData($school, $mode)
                )->assertCreated()->json('data.id');

                $participationData = [
                    'learner_id' => $learnerId,
                    'fee_category_id' => $categories[3],
                    'academic_year_id' => $school['year']->id,
                    'term_id' => $school['term']->id,
                    'active' => true,
                ];

                if ($participating) {
                    $membership = $this->putJson(
                        '/api/finance/charge-participations', $participationData
                    )->assertOk()->json('data.id');

                    $this->assertNotEmpty($membership);
                    $this->assertDatabaseHas('finance_audit_logs', [
                        'school_id' => $school['school']->id,
                        'record_id' => $membership,
                        'action' => 'charge_participation_updated',
                    ]);

                    $count = DB::table('fee_charge_participations')->count();
                    $this->putJson(
                        '/api/finance/charge-participations', $participationData
                    )->assertOk()->assertJsonPath('data.id', $membership);
                    $this->assertSame($count, DB::table('fee_charge_participations')->count());
                }

                $data = [
                    'learner_id' => $learnerId,
                    'academic_year_id' => $school['year']->id,
                    'term_id' => $school['term']->id,
                ];

                $invoiceId = $this->postJson('/api/finance/invoices/generate', $data)
                    ->assertCreated()->json('data.id');

                $this->assertDatabaseHas('fee_invoices', [
                    'id' => $invoiceId, 'total_amount' => $total, 'balance' => $total,
                ]);
                $this->assertSame(
                    $itemCount,
                    DB::table('fee_invoice_items')->where('invoice_id', $invoiceId)->count()
                );
                $this->assertSame(
                    1,
                    DB::table('fee_invoice_items')->where('invoice_id', $invoiceId)
                        ->where('fee_category_id', $categories[0])->count()
                );

                $this->postJson('/api/finance/invoices/'.$invoiceId.'/post')->assertOk();

                $financialBefore = $this->phaseTwoSnapshot();

                if ($participating) {
                    $this->putJson('/api/finance/charge-participations', array_replace(
                        $participationData, ['active' => false]
                    ))->assertOk();

                    // Membership edits are audited but do not rewrite financial records.
                    $financialAfter = $this->phaseTwoSnapshot();
                    unset($financialBefore['finance_audit_logs'], $financialAfter['finance_audit_logs']);
                    $this->assertSame($financialBefore, $financialAfter);
                }

                $this->postJson('/api/finance/invoices/generate', $data)
                    ->assertCreated()->assertJsonPath('data.id', $invoiceId);
                $this->assertDatabaseHas('fee_invoices', [
                    'id' => $invoiceId, 'total_amount' => $total, 'balance' => $total,
                ]);

                $before = DB::table('fee_charge_participations')->orderBy('id')->get()->toJson();

                $this->putJson('/api/finance/charge-participations', array_replace(
                    $participationData, ['learner_id' => $foreign['learner']->id]
                ))->assertNotFound();
                $this->putJson('/api/finance/charge-participations', array_replace(
                    $participationData, ['fee_category_id' => $foreign['category']]
                ))->assertNotFound();

                $this->assertSame(
                    $before,
                    DB::table('fee_charge_participations')->orderBy('id')->get()->toJson()
                );

                $this->financeHttpAs($foreign);
                $response = $this->getJson('/api/finance/charge-participations')->assertOk();
                $this->assertStringNotContainsString($learnerId, $response->getContent());
                $this->getJson('/api/finance/invoices/'.$invoiceId)->assertNotFound();
                $this->financeHttpAs($school);
            }
        }
    }

    private function insert(string $table, array $values): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id] + $values);

        return $id;
    }

    private function school(): array
    {
        $school = SchoolBuilder::create();
        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);
        $learner = LearnerBuilder::create($school, $grade, $stream);
        $year = AcademicYearBuilder::create($school);
        $term = TermBuilder::create($school, $year);
        $role = RoleBuilder::create(['school_id' => $school->id]);
        $record = UserBuilder::create($school, $role);
        $user = User::withoutGlobalScopes()->findOrFail($record->id);
        $category = $this->insert('fee_categories', [
            'school_id' => $school->id, 'category_name' => 'Lunch',
            'active' => true, 'is_deleted' => false,
        ]);
        $method = $this->insert('payment_methods', [
            'school_id' => $school->id, 'method_name' => 'M-Pesa',
            'active' => true, 'is_online' => false,
        ]);
        $account = $this->insert('learner_fee_accounts', [
            'school_id' => $school->id, 'learner_id' => $learner->id,
            'account_number' => 'TEST-'.Str::uuid(), 'current_balance' => '0.00',
            'account_status' => 'active', 'active' => true,
        ]);
        $invoice = $this->insert('fee_invoices', [
            'school_id' => $school->id, 'learner_id' => $learner->id,
            'learner_fee_account_id' => $account, 'academic_year_id' => $year->id,
            'term_id' => $term->id, 'grade_id' => $grade->id, 'stream_id' => $stream->id,
            'invoice_number' => 'TEST-'.Str::uuid(), 'invoice_date' => '2026-11-01',
            'total_amount' => '100.00', 'amount_paid' => '0.00', 'balance' => '100.00',
            'status' => 'posted',
        ]);
        $item = $this->insert('fee_invoice_items', [
            'invoice_id' => $invoice, 'fee_category_id' => $category, 'amount' => '100.00',
        ]);
        $payment = $this->insert('payments', [
            'school_id' => $school->id, 'learner_id' => $learner->id,
            'payment_method_id' => $method, 'receipt_number' => 'TEST-'.Str::uuid(),
            'amount' => '100.00', 'allocated_amount' => '0.00', 'refunded_amount' => '0.00',
            'payment_date' => '2026-11-01', 'payment_status' => 'confirmed', 'reversed' => false,
        ]);
        $ledger = app(FinanceLedgerService::class)->post(
            $user, LearnerFeeAccount::withoutGlobalScopes()->findOrFail($account),
            ['reference_type' => 'fee_invoice', 'reference_id' => $invoice,
                'transaction_type' => 'invoice', 'debit' => '100.00',
                'description' => 'Test invoice', 'academic_year_id' => $year->id,
                'term_id' => $term->id]
        );

        return compact('school', 'grade', 'stream', 'learner', 'year', 'term',
            'user', 'category', 'method', 'account', 'invoice', 'item', 'payment', 'ledger');
    }

    private function rejected(callable $write, string $state): void
    {
        DB::beginTransaction();
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);

            return;
        } finally {
            DB::rollBack();
        }
        $this->fail('Database accepted an invalid finance write.');
    }

    public function test_two_schools_can_customize_same_named_categories_independently(): void
    {
        $service = app(FinanceMasterDataService::class);
        foreach ([$this->a, $this->b] as $s) {
            $category = $service->category($s['user'], ['category_name' => 'School activity']);
            $this->assertSame($s['school']->id, $category->school_id);
            $this->rejected(fn () => DB::table('fee_categories')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $s['school']->id,
                'category_name' => 'lunch', 'is_deleted' => false,
            ]), '23505');
        }
    }

    public function test_database_rejects_foreign_finance_references_in_both_directions(): void
    {
        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            foreach (['learner_id' => $foreign['learner']->id,
                'learner_fee_account_id' => $foreign['account'], 'term_id' => $foreign['term']->id,
                'grade_id' => $foreign['grade']->id, 'stream_id' => $foreign['stream']->id] as $field => $value) {
                $this->rejected(fn () => DB::table('fee_invoices')->where('id', $own['invoice'])->update([$field => $value]), '23503');
            }
            $this->rejected(fn () => DB::table('payments')->where('id', $own['payment'])->update(['payment_method_id' => $foreign['method']]), '23503');
            $this->rejected(fn () => DB::table('fee_invoice_items')->where('id', $own['item'])->update(['fee_category_id' => $foreign['category']]), '23503');
            $this->rejected(fn () => $this->insert('payment_allocations', [
                'school_id' => $own['school']->id, 'payment_id' => $own['payment'],
                'invoice_id' => $foreign['invoice'], 'allocated_amount' => '10.00',
                'status' => 'active',
            ]), '23503');
        }
    }

    public function test_allocation_targets_invoice_header_and_preserves_other_school(): void
    {
        $before = (array) DB::table('payments')->where('id', $this->b['payment'])->first();
        $result = app(FinancePaymentService::class)->allocate(
            $this->a['user'], $this->a['payment'], $this->a['invoice'], '40.00'
        );
        $this->assertSame($this->a['invoice'], $result->invoice_id);
        $this->assertSame($this->a['learner']->id, DB::table('payment_allocations')->where('id', $result->id)->value('learner_id'));
        $this->assertSame('40.00', (string) DB::table('payments')->where('id', $this->a['payment'])->value('allocated_amount'));
        $this->assertSame('60.00', (string) DB::table('fee_invoices')->where('id', $this->a['invoice'])->value('balance'));
        $this->assertSame($before, (array) DB::table('payments')->where('id', $this->b['payment'])->first());
    }

    public function test_authenticated_model_reads_and_service_writes_are_isolated_both_ways(): void
    {
        foreach ([[$this->a, $this->b], [$this->b, $this->a]] as [$own, $foreign]) {
            $this->actingAs($own['user']);
            $this->assertTrue(Payment::whereKey($own['payment'])->exists());
            $this->assertFalse(Payment::whereKey($foreign['payment'])->exists());
            $this->assertTrue(FeeInvoice::whereKey($own['invoice'])->exists());
            $this->assertFalse(FeeInvoice::whereKey($foreign['invoice'])->exists());
            try {
                app(FinancePaymentService::class)->allocate($own['user'], $own['payment'], $foreign['invoice'], '10.00');
                $this->fail('Foreign invoice was accepted.');
            } catch (ModelNotFoundException) {
                $this->assertSame('0.00', (string) DB::table('payments')->where('id', $own['payment'])->value('allocated_amount'));
                $this->assertSame('100.00', (string) DB::table('fee_invoices')->where('id', $foreign['invoice'])->value('balance'));
            }
        }
    }

    public function test_same_school_wrong_learner_cannot_receive_an_allocation(): void
    {
        $other = LearnerBuilder::create($this->a['school'], $this->a['grade'], $this->a['stream']);
        DB::table('payments')->where('id', $this->a['payment'])->update(['learner_id' => $other->id]);
        $this->rejected(fn () => $this->insert('payment_allocations', [
            'school_id' => $this->a['school']->id, 'payment_id' => $this->a['payment'],
            'invoice_id' => $this->a['invoice'], 'allocated_amount' => '10.00', 'status' => 'active',
        ]), '23503');
    }

    public function test_same_school_wrong_account_and_calendar_are_rejected(): void
    {
        $other = LearnerBuilder::create($this->a['school'], $this->a['grade'], $this->a['stream']);
        $account = $this->insert('learner_fee_accounts', [
            'school_id' => $this->a['school']->id, 'learner_id' => $other->id,
            'account_number' => 'TEST-'.Str::uuid(), 'current_balance' => '0.00',
        ]);
        $this->rejected(fn () => DB::table('fee_invoices')->where('id', $this->a['invoice'])->update(['learner_fee_account_id' => $account]), '23503');
        $year = AcademicYearBuilder::create($this->a['school'], ['year_name' => '2027']);
        $this->rejected(fn () => DB::table('fee_invoices')->where('id', $this->a['invoice'])->update(['academic_year_id' => $year->id]), '23503');
    }

    public function test_money_status_and_posting_keys_are_enforced_by_database(): void
    {
        foreach ([['amount' => '-1.00'], ['allocated_amount' => '101.00'],
            ['refunded_amount' => '1.00'], ['payment_status' => 'invented']] as $values) {
            $this->rejected(fn () => DB::table('payments')->where('id', $this->a['payment'])->update($values), '23514');
        }
        $this->rejected(fn () => DB::table('fee_invoices')->where('id', $this->a['invoice'])->update(['balance' => '99.00']), '23514');
        $row = (array) DB::table('learner_fee_ledger')->where('id', $this->a['ledger'])->first();
        $row['id'] = (string) Str::uuid();
        $row['reference_id'] = null;
        $this->rejected(fn () => DB::table('learner_fee_ledger')->insert($row), '23514');
        $row['reference_id'] = $this->a['invoice'];
        $this->rejected(fn () => DB::table('learner_fee_ledger')->insert($row), '23505');
    }

    public function test_ledger_is_append_only_and_service_retry_is_idempotent(): void
    {
        $this->rejected(fn () => DB::table('learner_fee_ledger')->where('id', $this->a['ledger'])->update(['description' => 'Changed']), '23514');
        $this->rejected(fn () => DB::table('learner_fee_ledger')->where('id', $this->a['ledger'])->delete(), '23514');
        $id = app(FinanceLedgerService::class)->post(
            $this->a['user'], LearnerFeeAccount::withoutGlobalScopes()->findOrFail($this->a['account']),
            ['reference_type' => 'fee_invoice', 'reference_id' => $this->a['invoice'],
                'transaction_type' => 'invoice', 'debit' => '100.00', 'description' => 'Retry']
        );
        $this->assertSame($this->a['ledger'], $id);
        $this->assertSame('100.00', (string) DB::table('learner_fee_accounts')->where('id', $this->a['account'])->value('current_balance'));
    }

    public function test_rejected_allocation_leaves_financial_records_unchanged(): void
    {
        $tables = ['payments', 'fee_invoices', 'learner_fee_accounts', 'learner_fee_ledger', 'payment_allocations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        try {
            app(FinancePaymentService::class)->allocate($this->a['user'], $this->a['payment'], $this->a['invoice'], '101.00');
            $this->fail('Over-allocation succeeded.');
        } catch (ValidationException) {
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
            }
        }
    }

    public function test_charge_review_draft_edits_preserve_omitted_eligibility(): void
    {
        $service = app(FinanceMasterDataService::class);

        foreach ([$this->a, $this->b] as $school) {
            $data = [
                'academic_year_id' => $school['year']->id,
                'term_id' => $school['term']->id,
                'grade_id' => $school['grade']->id,
                'fee_category_id' => $school['category'],
                'amount' => '25.00',
            ];

            $structure = $service->structure($school['user'], $data + [
                'charge_target' => 'boarder',
                'requires_participation' => true,
            ]);

            $data['amount'] = '30.00';
            $service->structure($school['user'], $data, $structure->id);

            $row = DB::table('fee_structures')->where('id', $structure->id)->first();
            $this->assertSame('boarder', $row->charge_target);
            $this->assertTrue((bool) $row->requires_participation);
            $this->assertSame('30.00', $row->amount);

            // Explicit false and explicit target changes remain supported.
            $service->structure($school['user'], $data + [
                'charge_target' => 'day_scholar',
                'requires_participation' => false,
            ], $structure->id);

            $row = DB::table('fee_structures')->where('id', $structure->id)->first();
            $this->assertSame('day_scholar', $row->charge_target);
            $this->assertFalse((bool) $row->requires_participation);

            $default = $service->structure($school['user'], $data);
            $this->assertSame('all', $default->charge_target);
            $this->assertFalse((bool) $default->requires_participation);
        }
    }

    public function test_charge_review_rollback_retains_targeted_structure(): void
    {
        $school = $this->a;
        $structure = app(FinanceMasterDataService::class)
            ->structure($school['user'], [
                'academic_year_id' => $school['year']->id,
                'term_id' => $school['term']->id,
                'grade_id' => $school['grade']->id,
                'fee_category_id' => $school['category'],
                'amount' => '25.00',
                'charge_target' => 'boarder',
                'requires_participation' => true,
            ]);

        $before = (array) DB::table('fee_structures')->where('id', $structure->id)->first();
        $migration = require database_path(
            'migrations/2026_11_02_090000_add_finance_charge_eligibility.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback discarded targeted charging configuration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Retain charge eligibility data',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            $before,
            (array) DB::table('fee_structures')->where('id', $structure->id)->first()
        );
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('fee_charge_participations'));
    }

    public function test_charge_review_rollback_retains_participation(): void
    {
        $school = $this->a;
        $id = (string) Str::uuid();

        DB::table('fee_charge_participations')->insert([
            'id' => $id,
            'school_id' => $school['school']->id,
            'learner_id' => $school['learner']->id,
            'fee_category_id' => $school['category'],
            'academic_year_id' => $school['year']->id,
            'term_id' => $school['term']->id,
            'active' => false,
            'updated_by' => $school['user']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = (array) DB::table('fee_charge_participations')->where('id', $id)->first();
        $migration = require database_path(
            'migrations/2026_11_02_090000_add_finance_charge_eligibility.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback discarded participation history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Retain charge eligibility data',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            $before,
            (array) DB::table('fee_charge_participations')->where('id', $id)->first()
        );
        $this->assertTrue(
            DB::getSchemaBuilder()->hasColumn('fee_structures', 'charge_target')
        );
    }

    public function test_empty_allocation_schema_rollback_is_transactionally_reversible(): void
    {
        $this->assertSame(0, DB::table('payment_allocations')->count());
        DB::beginTransaction();
        try {
            $migration = require database_path('migrations/2026_11_01_130000_harden_school_finance_integrity.php');
            $eligibilityMigration = require database_path(
                'migrations/2026_11_02_090000_add_finance_charge_eligibility.php'
            );
            $eligibilityMigration->down();
            $migration->down();
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('fee_invoice_items', 'school_id'));
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('payment_allocations', 'learner_id'));
        } finally {
            DB::rollBack();
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('fee_invoice_items', 'school_id'));
        $this->rejected(fn () => DB::table('payments')->where('id', $this->a['payment'])->update(['amount' => '-1.00']), '23514');
    }
}
