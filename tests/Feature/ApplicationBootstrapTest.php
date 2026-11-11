<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
#[Group('security')]
class ApplicationBootstrapTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_public_bootstrap_is_healthy_and_personal_resources_require_authentication(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->get('/up')->assertOk();
        $this->getJson('/api/profile')->assertUnauthorized();
    }
}
