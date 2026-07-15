<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinanceReceiptService
{
    public function receipt(User $user, string $paymentId): array
    {
        $payment = Payment::whereKey($paymentId)->where('school_id', $user->school_id)->with('paymentMethod', 'allocations.invoice', 'learner')->firstOrFail();
        $settings = DB::table('finance_settings')->where('school_id', $user->school_id)->where('active', true)->first();
        $school = DB::table('schools')->where('id', $user->school_id)->select('school_name', 'school_code')->first();
        $receiver = DB::table('users')->where('id', $payment->received_by)->where('school_id', $user->school_id)->select('first_name', 'middle_name', 'last_name')->first();
        $phone = $payment->payer_phone;

        return ['school' => ['name' => $school?->school_name, 'code' => $school?->school_code], 'receipt_number' => $payment->receipt_number, 'learner' => ['name' => trim($payment->learner->first_name.' '.$payment->learner->middle_name.' '.$payment->learner->last_name), 'admission_number' => $payment->learner->admission_no], 'payment_date' => $payment->payment_date, 'payment_method' => $payment->paymentMethod?->method_name, 'amount' => $payment->amount, 'allocated_amount' => $payment->allocated_amount, 'remaining_credit' => app(FinanceMoney::class)->decimal(app(FinanceMoney::class)->minor($payment->amount) - app(FinanceMoney::class)->minor($payment->allocated_amount)), 'payer_name' => $payment->payer_name, 'payer_phone' => $phone ? str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4) : null, 'received_by' => $receiver ? trim($receiver->first_name.' '.$receiver->middle_name.' '.$receiver->last_name) : null, 'status' => $payment->payment_status, 'reversed' => (bool) $payment->reversed, 'currency' => $settings?->currency ?? 'KES', 'allocations' => $payment->allocations->map(fn ($allocation) => ['invoice_number' => $allocation->invoice?->invoice_number, 'amount' => $allocation->allocated_amount, 'status' => $allocation->status])];
    }
}
