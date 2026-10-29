<?php

namespace Tests\Feature;

use App\Http\Middleware\DujiaoBoot;
use App\Service\GoodsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_homepage_renders_with_an_empty_catalog()
    {
        // Render the homepage independently of the developer's install.lock and database.
        $this->withoutMiddleware(DujiaoBoot::class);
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        Cache::put('system-setting', ['template' => 'unicorn']);
        $goods = \Mockery::mock(GoodsService::class);
        $goods->shouldReceive('withGroup')->once()->andReturn([]);
        $this->app->instance('Service\GoodsService', $goods);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('unicorn.static_pages.home');
        $response->assertViewHas('data', []);
    }
}
