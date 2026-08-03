<?php

namespace App\Services\Communication;

use Illuminate\Validation\ValidationException;

class KenyanPhoneNormalizer
{
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', trim($phone));
        $normalized = match (true) {
            preg_match('/^\+254(7|1)\d{8}$/', $digits) === 1 => $digits,
            preg_match('/^254(7|1)\d{8}$/', $digits) === 1 => '+'.$digits,
            preg_match('/^0(7|1)\d{8}$/', $digits) === 1 => '+254'.substr($digits, 1),
            default => null,
        };
        if (! $normalized) {
            throw ValidationException::withMessages(['phone' => 'A valid Kenyan mobile number is required.']);
        }

        return $normalized;
    }

    public function valid(?string $phone): bool
    {
        try {
            $this->normalize((string) $phone);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
