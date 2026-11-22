<?php

namespace App\Console\Commands;

use App\Services\School\SubscriptionDarajaSandbox;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SubscriptionSandboxCheckout extends Command
{
    protected $signature = 'shuleos:subscription-sandbox {action : start or verify} {school : School UUID} {attempt : Unique attempt UUID} {--package= : Package UUID for start}';

    protected $description = 'Start or verify a Daraja sandbox checkout in an isolated local database';

    public function handle(SubscriptionDarajaSandbox $daraja): int
    {
        try {
            $this->guardDatabase();
            $school = $this->argument('school');
            $attempt = $this->argument('attempt');
            if (! Str::isUuid($school) || ! Str::isUuid($attempt)) {
                throw new RuntimeException('School and attempt must be UUIDs.');
            }

            $status = match ($this->argument('action')) {
                'start' => $this->start($daraja, $school, $attempt),
                'verify' => $this->verify($daraja, $school, $attempt),
                default => throw new RuntimeException('Choose start or verify.'),
            };
            $this->info('Sandbox payment status: '.$status);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Sandbox checkout stopped. Check the isolated database, school, package, attempt and Daraja configuration. Do not reuse a new attempt ID to retry an uncertain submission.');

            return self::FAILURE;
        }
    }

    private function guardDatabase(): void
    {
        $db = DB::connection();
        if (! app()->environment(['local', 'testing']) || $db->getDriverName() !== 'pgsql'
            || $db->getDatabaseName() !== 'shuleos_daraja_sandbox'
            || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || $db->selectOne('select current_database() as name', [], false)->name !== 'shuleos_daraja_sandbox') {
            throw new RuntimeException('An isolated local Daraja database is required.');
        }
    }

    private function start(SubscriptionDarajaSandbox $daraja, string $school, string $attempt): string
    {
        $existing = $this->payment($school, $attempt)->first();
        if ($existing) {
            return $existing->status;
        }

        $packageId = $this->option('package');
        $phone = $this->secret('Enter the sandbox phone number in 254 format', false);
        if (! is_string($packageId) || ! Str::isUuid($packageId) || ! preg_match('/^254[17][0-9]{8}$/D', (string) $phone)) {
            throw new RuntimeException('Invalid package or phone.');
        }

        $amount = DB::transaction(function () use ($school, $attempt, $packageId) {
            $this->lockSchool($school);
            if ($this->payment($school, $attempt)->exists()) {
                return null;
            }

            if (DB::table('sandbox_subscription_payments')->where('school_id', $school)
                ->whereIn('status', ['submission_unconfirmed', 'pending'])->exists()
                || $this->hasSubscription($school)) {
                throw new RuntimeException('The school already has a payment in progress or a subscription.');
            }

            $package = DB::table('subscription_packages')->where('id', $packageId)->where('active', true)->first();
            if (! $package || ! preg_match('/^[1-9][0-9]{0,7}(?:\.00)?$/D', (string) $package->amount)
                || (int) $package->duration_months < 1) {
                throw new RuntimeException('An active whole-shilling package is required.');
            }

            DB::table('sandbox_subscription_payments')->insert([
                'id' => $attempt,
                'school_id' => $school,
                'package_id' => $packageId,
                'amount_kes' => (int) $package->amount,
                'duration_months' => (int) $package->duration_months,
                'status' => 'submission_unconfirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (int) $package->amount;
        });

        if ($amount === null) {
            return $this->payment($school, $attempt)->value('status');
        }

        $result = $daraja->initiate($phone, $amount, strtoupper(substr(str_replace('-', '', $attempt), 0, 12)));
        $checkout = $result['CheckoutRequestID'] ?? null;
        $merchant = $result['MerchantRequestID'] ?? null;
        if (! in_array($result['ResponseCode'] ?? null, [0, '0'], true)
            || ! $this->validProviderId($checkout) || ! $this->validProviderId($merchant)) {
            return 'submission_unconfirmed';
        }

        $this->payment($school, $attempt)->where('status', 'submission_unconfirmed')->update([
            'checkout_id' => $checkout,
            'merchant_id' => $merchant,
            'status' => 'pending',
            'updated_at' => now(),
        ]);

        return 'pending';
    }

    private function verify(SubscriptionDarajaSandbox $daraja, string $school, string $attempt): string
    {
        $payment = $this->payment($school, $attempt)->first();
        if (! $payment) {
            throw new RuntimeException('School payment was not found.');
        }
        if ($payment->status !== 'pending') {
            return $payment->status;
        }

        $result = $daraja->query($payment->checkout_id);
        if (! in_array($result['ResponseCode'] ?? null, [0, '0'], true)
            || ($result['CheckoutRequestID'] ?? null) !== $payment->checkout_id
            || ($result['MerchantRequestID'] ?? null) !== $payment->merchant_id) {
            return 'query_unconfirmed';
        }

        $code = $result['ResultCode'] ?? null;
        $cancelled = in_array($code, [1032, '1032'], true);
        if (! $cancelled && ! in_array($code, [0, '0'], true)) {
            return 'pending';
        }

        $amount = $result['Amount'] ?? null;
        if (! $cancelled && ((! is_int($amount) && ! is_string($amount))
            || ! preg_match('/^[0-9]+(?:\.00)?$/D', (string) $amount) || (int) $amount !== (int) $payment->amount_kes)) {
            return 'amount_unverified';
        }

        return DB::transaction(function () use ($school, $attempt, $cancelled) {
            $schoolRow = $this->lockSchool($school);
            $payment = $this->payment($school, $attempt)->lockForUpdate()->first();
            if ($payment->status !== 'pending') {
                return $payment->status;
            }
            if ($cancelled) {
                $this->payment($school, $attempt)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return 'cancelled';
            }
            if ($this->hasSubscription($school)) {
                return 'existing_subscription';
            }

            $date = CarbonImmutable::today($schoolRow->timezone ?: 'Africa/Nairobi');
            DB::table('school_subscriptions')->insert([
                'id' => $attempt,
                'school_id' => $school,
                'package_id' => $payment->package_id,
                'start_date' => $date->toDateString(),
                'expiry_date' => $date->addMonthsNoOverflow((int) $payment->duration_months)->subDay()->toDateString(),
                'status' => 'ACTIVE',
                'source' => 'DARAJA_SANDBOX',
                'payment_reference' => $payment->checkout_id,
                'amount_paid' => $payment->amount_kes,
                'is_trial' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->payment($school, $attempt)->update([
                'status' => 'activated', 'subscription_id' => $attempt, 'updated_at' => now(),
            ]);

            return 'activated';
        });
    }

    private function hasSubscription(string $school): bool
    {
        return DB::table('school_subscriptions')->where('school_id', $school)
            ->whereIn('status', ['TRIAL', 'ACTIVE', 'GRACE_PERIOD'])->exists();
    }

    private function lockSchool(string $school): object
    {
        $row = DB::table('schools')->where('id', $school)->where('active', true)
            ->where('is_deleted', false)->whereIn('lifecycle_state', ['onboarding', 'active'])->lockForUpdate()->first();
        if (! $row) {
            throw new RuntimeException('School is unavailable for the sandbox test.');
        }

        return $row;
    }

    private function payment(string $school, string $attempt): Builder
    {
        return DB::table('sandbox_subscription_payments')->where('school_id', $school)->where('id', $attempt);
    }

    private function validProviderId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value) === 1;
    }
}
