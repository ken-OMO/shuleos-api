<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

class FinanceMoney
{
    public function minor(string|int $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Money must have at most two decimal places.']);
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    public function decimal(int $minor): string
    {
        $negative = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $negative.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public function positive(string|int $amount): string
    {
        $minor = $this->minor($amount);
        if ($minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return $this->decimal($minor);
    }
}
