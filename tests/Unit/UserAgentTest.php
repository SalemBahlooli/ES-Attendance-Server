<?php

namespace Tests\Unit;

use App\Support\UserAgent;
use Tests\TestCase;

class UserAgentTest extends TestCase
{
    public function test_common_browsers(): void
    {
        $edge = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 Edg/140.0');
        $this->assertSame(['Edge', 'Windows', 'desktop'], [$edge['browser'], $edge['os'], $edge['type']]);

        $iphone = UserAgent::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1');
        $this->assertSame(['Safari', 'iOS', 'mobile'], [$iphone['browser'], $iphone['os'], $iphone['type']]);

        $this->assertSame('other', UserAgent::parse('curl/8.0')['type']);
    }

    public function test_the_family_ignores_versions(): void
    {
        $a = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0) Chrome/139.0 Safari/537.36');
        $b = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36');

        $this->assertSame($a['family'], $b['family']);
    }
}
