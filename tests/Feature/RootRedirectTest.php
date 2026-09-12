<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    public function test_root_redirects_to_catalog(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/catalog');
    }
}
