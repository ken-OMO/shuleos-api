<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

final class StudentElectionNetworkConfiguration
{
    /** @return array<string, list<string>> */
    public static function parse(mixed $value): array
    {
        if (! is_string($value) || $value === '' || strlen($value) > 65536) {
            return [];
        }
        try {
            $map = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (! is_array($map) || $map === [] || array_is_list($map)) {
            return [];
        }
        $result = [];
        foreach ($map as $school => $networks) {
            if (! is_string($school) || ! preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $school)
                || ! is_array($networks) || ! array_is_list($networks) || $networks === [] || count($networks) > 64) {
                return [];
            }
            foreach ($networks as $network) {
                if (! is_string($network) || substr_count($network, '/') !== 1) {
                    return [];
                }
                [$ip, $prefix] = explode('/', $network, 2);
                $bytes = @inet_pton($ip);
                if ($bytes === false || ! preg_match('/^[1-9][0-9]*$/D', $prefix)
                    || (int) $prefix > strlen($bytes) * 8) {
                    return [];
                }
            }
            $result[$school] = array_values(array_unique($networks));
        }

        return $result;
    }
}
