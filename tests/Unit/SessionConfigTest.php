<?php

namespace Tests\Unit;

use Tests\TestCase;

class SessionConfigTest extends TestCase
{
    public function test_session_lifetime_defaults_to_24_hours(): void
    {
        $this->assertSame('1440', env('SESSION_LIFETIME'));
        $this->assertSame(1440, (int) config('session.lifetime'));
        $this->assertFalse(config('session.expire_on_close'));
    }
}
