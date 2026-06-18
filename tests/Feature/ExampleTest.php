<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_events_visual_1()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('events.visual1'));
    }
}
