<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's statefulApi() middleware only starts the session for
        // requests that look like they come from a trusted SPA frontend
        // (Referer/Origin matching SANCTUM_STATEFUL_DOMAINS). Without this,
        // every request in the test client would be treated as a
        // stateless/token request and $request->session() would throw.
        $this->withHeader('Referer', 'http://127.0.0.1:8000');
    }
}
