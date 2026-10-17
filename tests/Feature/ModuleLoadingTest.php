<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ModuleLoadingTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_module_routes_are_loaded(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'module' => 'identity']);
    }
}
