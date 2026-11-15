<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StudentElection\StudentElectionNetworkConfiguration;
use PHPUnit\Framework\TestCase;

class StudentElectionNetworkConfigurationTest extends TestCase
{
    public function test_explicit_school_ipv4_and_ipv6_cidrs_are_supported(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $map = [$id => ['10.20.30.0/24', '2001:db8::/64']];
        $this->assertSame($map, StudentElectionNetworkConfiguration::parse(json_encode($map)));
    }

    public function test_invalid_configuration_fails_closed_for_the_entire_map(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        foreach ([null, '', '{', '[]', '{}', '{"*": ["10.0.0.0/8"]}',
            json_encode([$id => ['0.0.0.0/0']]), json_encode([$id => ['::/0']]),
            json_encode([$id => ['10.0.0.0/33']]), json_encode([$id => ['2001:db8::/129']]),
            json_encode([$id => ['invalid/24']]), json_encode([$id => ['10.0.0.1']]),
            json_encode([$id => ['10.0.0.0/24', 'invalid/24']]), json_encode([$id => []]),
        ] as $input) {
            $this->assertSame([], StudentElectionNetworkConfiguration::parse($input));
        }
    }
}
