<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'fee_categories', 'fee_structures', 'fee_invoices', 'fee_invoice_items',
        'payments', 'payment_methods', 'payment_allocations', 'learner_fee_accounts',
        'learner_fee_ledger', 'fee_discounts', 'fee_discount_categories',
        'learner_discounts', 'fee_discount_applications', 'payment_plans',
        'payment_plan_installments', 'payment_plan_invoices', 'fee_refunds',
        'finance_adjustments', 'fee_arrears', 'finance_settings', 'finance_audit_logs',
        'finance_clearances', 'finance_clearance_certificates',
    ];

    private array $columns = [];

    private function q(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function name(string $kind, string $table, string $key): string
    {
        return 'f611_'.$kind.'_'.substr(hash('sha256', $table.'.'.$key), 0, 20);
    }

    private function key(string $table, array $columns): void
    {
        $name = $this->name('uk', $table, implode(',', $columns));
        $fields = implode(', ', array_map($this->q(...), $columns));
        DB::statement('CREATE UNIQUE INDEX '.$this->q($name).' ON public.'.$this->q($table).' ('.$fields.')');
    }

    private function constraint(string $table, string $key, string $definition): void
    {
        $name = $this->q($this->name('ck', $table, $key));
        DB::statement('ALTER TABLE public.'.$this->q($table).' ADD CONSTRAINT '.$name.' '.$definition.' NOT VALID');
        DB::statement('ALTER TABLE public.'.$this->q($table).' VALIDATE CONSTRAINT '.$name);
    }

    private function fk(string $table, array $local, string $parent, array $remote): void
    {
        $fields = implode(', ', array_map($this->q(...), $local));
        $target = implode(', ', array_map($this->q(...), $remote));
        $name = $this->name('ix', $table, implode(',', $local));
        DB::statement('CREATE INDEX '.$this->q($name).' ON public.'.$this->q($table).' ('.$fields.')');
        $this->constraint($table, 'fk:'.implode(',', $local).':'.$parent, 'FOREIGN KEY ('.$fields.') REFERENCES public.'.$this->q($parent).' ('.$target.') ON DELETE NO ACTION ON UPDATE NO ACTION');
    }

    private function check(string $table, string $key, string $expression): void
    {
        $this->constraint($table, $key, 'CHECK ('.$expression.')');
    }

    private function has(string $table, string $column): bool
    {
        return isset($this->columns[$table][$column]);
    }

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('6A.11 requires PostgreSQL.');
        }
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");
        foreach (DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public'") as $row) {
            $this->columns[$row->table_name][$row->column_name] = true;
        }
        foreach (self::TABLES as $table) {
            if (! $this->has($table, 'id')) {
                throw new RuntimeException('Required finance table missing: '.$table);
            }
        }

        // Preserve the actual legacy FK definition for an empty-allocation rollback.
        DB::statement('CREATE TABLE public.f611_original_nullable (table_name text PRIMARY KEY)');
        foreach (self::TABLES as $table) {
            $column = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'school_id'", [$table]);
            if ($column && $column->is_nullable === 'YES') {
                DB::table('f611_original_nullable')->insert(['table_name' => $table]);
            }
        }
        DB::statement('CREATE TABLE public.f611_legacy_allocation_fks (name text PRIMARY KEY, definition text NOT NULL)');
        $legacy = DB::select("SELECT c.conname AS name, pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c JOIN pg_attribute a
            ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
            WHERE c.conrelid = 'public.payment_allocations'::regclass
            AND c.contype = 'f' AND a.attname = 'invoice_id'");
        foreach ($legacy as $row) {
            DB::table('f611_legacy_allocation_fks')->insert((array) $row);
            DB::statement('ALTER TABLE public.payment_allocations DROP CONSTRAINT '.$this->q($row->name));
        }

        DB::statement('ALTER TABLE public.fee_invoice_items ADD COLUMN school_id uuid');
        DB::statement('UPDATE public.fee_invoice_items item SET school_id = invoice.school_id FROM public.fee_invoices invoice WHERE invoice.id = item.invoice_id');
        $this->columns['fee_invoice_items']['school_id'] = true;
        DB::statement('ALTER TABLE public.payment_allocations ADD COLUMN learner_id uuid');
        DB::statement('UPDATE public.payment_allocations a SET learner_id = p.learner_id FROM public.payments p WHERE p.id = a.payment_id');
        DB::statement('ALTER TABLE public.payment_allocations ALTER COLUMN learner_id SET NOT NULL');
        $this->columns['payment_allocations']['learner_id'] = true;

        $parents = [
            'learner_id' => 'learners', 'academic_year_id' => 'academic_years',
            'term_id' => 'terms', 'carried_forward_to_term_id' => 'terms',
            'grade_id' => 'grades', 'stream_id' => 'streams',
            'fee_category_id' => 'fee_categories', 'fee_structure_id' => 'fee_structures',
            'invoice_id' => 'fee_invoices', 'payment_id' => 'payments',
            'payment_method_id' => 'payment_methods', 'payment_plan_id' => 'payment_plans',
            'learner_fee_account_id' => 'learner_fee_accounts', 'discount_id' => 'fee_discounts',
            'learner_discount_id' => 'learner_discounts', 'ledger_entry_id' => 'learner_fee_ledger',
            'reversal_ledger_entry_id' => 'learner_fee_ledger', 'reverses_ledger_id' => 'learner_fee_ledger',
            'carry_forward_ledger_id' => 'learner_fee_ledger', 'source_resolution_ledger_id' => 'learner_fee_ledger',
            'clearance_id' => 'finance_clearances',
        ];
        $actors = ['created_by', 'updated_by', 'approved_by', 'generated_by', 'posted_by',
            'cancelled_by', 'received_by', 'confirmed_by', 'reversed_by', 'applied_by',
            'assigned_by', 'requested_by', 'processed_by', 'rejected_by', 'submitted_by',
            'calculated_by', 'resolved_by', 'revoked_by', 'issued_by', 'user_id', 'deleted_by'];
        foreach ($actors as $actor) {
            $parents[$actor] = 'users';
        }
        foreach (array_unique(array_merge(self::TABLES, array_values($parents))) as $parent) {
            if (! $this->has($parent, 'school_id')) {
                throw new RuntimeException('Expected tenant-owned parent: '.$parent);
            }
            $this->key($parent, ['school_id', 'id']);
        }
        $this->key('learner_fee_accounts', ['school_id', 'learner_id', 'id']);
        $this->key('payments', ['school_id', 'learner_id', 'id']);
        $this->key('fee_invoices', ['school_id', 'learner_id', 'id']);
        $this->fk('payment_allocations', ['school_id', 'learner_id', 'payment_id'], 'payments', ['school_id', 'learner_id', 'id']);
        $this->fk('payment_allocations', ['school_id', 'learner_id', 'invoice_id'], 'fee_invoices', ['school_id', 'learner_id', 'id']);
        $this->key('terms', ['school_id', 'academic_year_id', 'id']);
        $this->key('streams', ['school_id', 'grade_id', 'id']);

        foreach (self::TABLES as $table) {
            DB::statement('ALTER TABLE public.'.$this->q($table).' ALTER COLUMN school_id SET NOT NULL');
            $this->fk($table, ['school_id'], 'schools', ['id']);
            foreach ($parents as $field => $parent) {
                if ($this->has($table, $field)) {
                    $this->fk($table, ['school_id', $field], $parent, ['school_id', 'id']);
                }
            }
            if ($this->has($table, 'learner_id') && $this->has($table, 'learner_fee_account_id')) {
                $this->fk($table, ['school_id', 'learner_id', 'learner_fee_account_id'], 'learner_fee_accounts', ['school_id', 'learner_id', 'id']);
            }
            if ($this->has($table, 'term_id') && $this->has($table, 'academic_year_id')) {
                $this->fk($table, ['school_id', 'academic_year_id', 'term_id'], 'terms', ['school_id', 'academic_year_id', 'id']);
            }
            if ($this->has($table, 'stream_id') && $this->has($table, 'grade_id')) {
                $this->fk($table, ['school_id', 'grade_id', 'stream_id'], 'streams', ['school_id', 'grade_id', 'id']);
            }
        }

        // Explicit service status sets. Also replace legacy uppercase defaults.
        $statuses = [
            'fee_structures' => ['draft', 'approved', 'active', 'archived'],
            'fee_invoices' => ['draft', 'posted', 'partially_paid', 'paid', 'cancelled'],
            'payments' => ['pending', 'confirmed', 'reversed'],
            'payment_allocations' => ['active', 'reversed'],
            'fee_discounts' => ['draft', 'approved', 'active', 'archived', 'cancelled'],
            'learner_discounts' => ['pending', 'approved', 'active', 'cancelled'],
            'fee_discount_applications' => ['active', 'reversed'],
            'payment_plans' => ['draft', 'approved', 'active', 'cancelled', 'defaulted', 'completed'],
            'payment_plan_installments' => ['pending', 'partially_paid', 'paid', 'overdue', 'waived', 'cancelled'],
            'fee_refunds' => ['requested', 'under_review', 'approved', 'rejected', 'processed', 'cancelled'],
            'finance_adjustments' => ['draft', 'submitted', 'approved', 'posted', 'rejected', 'cancelled', 'reversed'],
            'fee_arrears' => ['outstanding', 'carried_forward', 'resolved'],
            'finance_clearances' => ['not_cleared', 'cleared', 'conditionally_cleared', 'revoked'],
        ];
        foreach ($statuses as $table => $values) {
            $field = $table === 'payments' ? 'payment_status' : 'status';
            $quoted = implode(', ', array_map(fn ($value) => "'".$value."'", $values));
            $this->check($table, 'status', $this->q($field).' IS NOT NULL AND '.$this->q($field).' IN ('.$quoted.')');
            if (in_array($table, ['fee_invoices', 'payments', 'finance_adjustments'], true)) {
                DB::statement('ALTER TABLE public.'.$this->q($table).' ALTER COLUMN '.$this->q($field)." SET DEFAULT '".$values[0]."'");
            }
        }

        $checks = [
            'fee_categories' => ['name' => 'length(btrim(category_name)) > 0'],
            'fee_structures' => ['amount' => "amount > 0 AND amount < 'Infinity'::numeric"],
            'fee_invoice_items' => ['amount' => "amount > 0 AND amount < 'Infinity'::numeric"],
            'fee_invoices' => ['amounts' => "total_amount >= 0 AND total_amount < 'Infinity'::numeric AND amount_paid IS NOT NULL AND amount_paid >= 0 AND amount_paid <= total_amount AND balance = total_amount - amount_paid"],
            'payments' => ['amounts' => "amount > 0 AND amount < 'Infinity'::numeric AND allocated_amount >= 0 AND allocated_amount <= amount AND refunded_amount >= 0 AND refunded_amount <= allocated_amount"],
            'payment_allocations' => ['amounts' => "allocated_amount > 0 AND allocated_amount < 'Infinity'::numeric AND refunded_amount >= 0 AND refunded_amount <= allocated_amount"],
            'fee_discount_applications' => ['amounts' => "eligible_amount >= 0 AND eligible_amount < 'Infinity'::numeric AND discount_amount > 0 AND discount_amount <= eligible_amount"],
            'fee_discounts' => ['value' => "discount_value > 0 AND discount_value < 'Infinity'::numeric AND (discount_type NOT IN ('percentage', 'full_waiver') OR discount_value <= 100) AND (maximum_discount IS NULL OR (maximum_discount > 0 AND maximum_discount < 'Infinity'::numeric))"],
            'learner_discounts' => ['value' => "assigned_value IS NOT NULL AND assigned_value > 0 AND assigned_value < 'Infinity'::numeric"],
            'payment_plan_installments' => ['amounts' => "scheduled_amount IS NOT NULL AND scheduled_amount > 0 AND scheduled_amount < 'Infinity'::numeric AND paid_amount >= 0 AND paid_amount <= scheduled_amount AND outstanding_amount IS NOT NULL AND outstanding_amount = scheduled_amount - paid_amount AND percentage >= 0 AND percentage <= 100 AND installment_order > 0"],
            'payment_plans' => ['amount' => "total_planned_amount IS NULL OR (total_planned_amount > 0 AND total_planned_amount < 'Infinity'::numeric)"],
            'fee_refunds' => ['amount' => "refund_amount > 0 AND refund_amount < 'Infinity'::numeric", 'approver' => 'approved_by IS NULL OR (requested_by IS NOT NULL AND approved_by <> requested_by)'],
            'finance_adjustments' => ['amount' => "amount > 0 AND amount < 'Infinity'::numeric", 'direction' => "direction IS NOT NULL AND direction IN ('debit', 'credit')", 'approver' => 'approved_by IS NULL OR (created_by IS NOT NULL AND approved_by <> created_by)'],
            'fee_arrears' => ['amounts' => "amount >= 0 AND amount < 'Infinity'::numeric AND carried_forward_amount >= 0 AND carried_forward_amount <= amount"],
            'learner_fee_ledger' => [
                'amounts' => "debit_amount IS NOT NULL AND credit_amount IS NOT NULL AND debit_amount >= 0 AND credit_amount >= 0 AND debit_amount < 'Infinity'::numeric AND credit_amount < 'Infinity'::numeric AND ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0))",
                'key' => 'reference_type IS NOT NULL AND length(btrim(reference_type)) > 0 AND reference_id IS NOT NULL AND posting_action IS NOT NULL AND length(btrim(posting_action)) > 0',
                'account' => "learner_fee_account_id IS NOT NULL AND running_balance IS NOT NULL AND running_balance > '-Infinity'::numeric AND running_balance < 'Infinity'::numeric",
            ],
        ];
        foreach ($checks as $table => $rules) {
            foreach ($rules as $key => $expression) {
                $this->check($table, $key, $expression);
            }
        }
        DB::statement('CREATE UNIQUE INDEX f611_category_name ON public.fee_categories (school_id, lower(category_name)) WHERE is_deleted = false');
        DB::statement("CREATE UNIQUE INDEX f611_structure_scope ON public.fee_structures (school_id, academic_year_id, term_id, grade_id, stream_id, fee_category_id) NULLS NOT DISTINCT WHERE is_deleted = false AND status IN ('draft', 'approved', 'active')");
        DB::statement("CREATE UNIQUE INDEX f611_invoice_scope ON public.fee_invoices (school_id, learner_id, academic_year_id, term_id) WHERE status <> 'cancelled'");
        DB::statement("CREATE UNIQUE INDEX f611_payment_reference ON public.payments (school_id, transaction_reference) WHERE transaction_reference IS NOT NULL AND btrim(transaction_reference) <> ''");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.f611_item_school() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner uuid;
BEGIN
    SELECT school_id INTO owner FROM public.fee_invoices WHERE id = NEW.invoice_id;
    IF owner IS NULL THEN
        RAISE EXCEPTION 'Invoice missing' USING ERRCODE = '23503';
    END IF;
    IF NEW.school_id IS NULL THEN NEW.school_id := owner; END IF;
    IF NEW.school_id <> owner THEN
        RAISE EXCEPTION 'Invoice item tenant mismatch' USING ERRCODE = '23503';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER f611_item_school BEFORE INSERT OR UPDATE ON public.fee_invoice_items
FOR EACH ROW EXECUTE FUNCTION public.f611_item_school();

CREATE OR REPLACE FUNCTION public.f611_allocation_learner() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner uuid;
BEGIN
    SELECT learner_id INTO owner FROM public.payments WHERE id = NEW.payment_id;
    IF owner IS NULL THEN
        RAISE EXCEPTION 'Payment missing' USING ERRCODE = '23503';
    END IF;
    IF NEW.learner_id IS NULL THEN NEW.learner_id := owner; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER f611_allocation_learner BEFORE INSERT OR UPDATE ON public.payment_allocations
FOR EACH ROW EXECUTE FUNCTION public.f611_allocation_learner();

CREATE OR REPLACE FUNCTION public.f611_ledger_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Finance ledger is append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER f611_ledger_immutable BEFORE UPDATE OR DELETE ON public.learner_fee_ledger
FOR EACH ROW EXECUTE FUNCTION public.f611_ledger_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::table('payment_allocations')->exists()) {
            throw new RuntimeException('Populated allocation rollback requires a reviewed forward migration.');
        }
        foreach ([['fee_invoice_items', 'f611_item_school'], ['payment_allocations', 'f611_allocation_learner'], ['learner_fee_ledger', 'f611_ledger_immutable']] as [$table, $trigger]) {
            DB::statement('DROP TRIGGER '.$this->q($trigger).' ON public.'.$this->q($table));
        }
        foreach (['f611_item_school', 'f611_allocation_learner', 'f611_ledger_immutable'] as $function) {
            DB::statement('DROP FUNCTION public.'.$this->q($function).'()');
        }
        foreach (DB::select("SELECT t.relname AS table_name, c.conname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = 'public' AND left(c.conname, 8) = 'f611_ck_'") as $row) {
            DB::statement('ALTER TABLE public.'.$this->q($row->table_name).' DROP CONSTRAINT '.$this->q($row->conname));
        }
        foreach (DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND left(indexname, 5) = 'f611_' AND tablename NOT IN ('f611_original_nullable', 'f611_legacy_allocation_fks')") as $row) {
            DB::statement('DROP INDEX public.'.$this->q($row->indexname));
        }
        DB::statement('ALTER TABLE public.fee_invoice_items DROP COLUMN school_id');
        DB::statement('ALTER TABLE public.payment_allocations DROP COLUMN learner_id');
        foreach (DB::table('f611_legacy_allocation_fks')->get() as $row) {
            DB::statement('ALTER TABLE public.payment_allocations ADD CONSTRAINT '.$this->q($row->name).' '.$row->definition);
        }
        DB::statement('DROP TABLE public.f611_legacy_allocation_fks');
        foreach (DB::table('f611_original_nullable')->get() as $row) {
            DB::statement('ALTER TABLE public.'.$this->q($row->table_name).' ALTER COLUMN school_id DROP NOT NULL');
        }
        DB::statement('DROP TABLE public.f611_original_nullable');
        DB::statement("ALTER TABLE public.fee_invoices ALTER COLUMN status SET DEFAULT 'UNPAID'");
        DB::statement("ALTER TABLE public.payments ALTER COLUMN payment_status SET DEFAULT 'COMPLETED'");
        DB::statement("ALTER TABLE public.finance_adjustments ALTER COLUMN status SET DEFAULT 'pending'");
    }
};
