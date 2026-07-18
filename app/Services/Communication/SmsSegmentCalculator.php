<?php

namespace App\Services\Communication;

use Illuminate\Validation\ValidationException;

class SmsSegmentCalculator
{
    public function calculate(string $message): array
    {
        $unicode = preg_match('/[^\x0A\x0D\x20-\x7E]/u', $message) === 1;
        $length = mb_strlen($message);
        $single = $unicode ? 70 : 160;
        $multipart = $unicode ? 67 : 153;
        $segments = $length <= $single ? 1 : (int) ceil($length / $multipart);
        if ($segments > config('communication.sms.maximum_segments', 4)) {
            throw ValidationException::withMessages(['body' => 'SMS exceeds the configured segment limit.']);
        }

        return ['encoding' => $unicode ? 'unicode' : 'gsm', 'characters' => $length, 'segments' => $segments];
    }
}
