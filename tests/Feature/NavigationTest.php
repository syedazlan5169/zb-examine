<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guest_navigation_contains_public_destinations_only(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee(__('home.new_submission'))
            ->assertSee(__('auth.login'))
            ->assertSee(__('auth.register'))
            ->assertDontSee(__('reports.navigation'))
            ->assertDontSee(__('users.navigation'))
            ->assertSee('aria-controls="mobile-navigation-drawer"', false)
            ->assertSee('data-mobile-navigation', false)
            ->assertSee(__('navigation.open_menu'))
            ->assertSee(__('navigation.close_menu'));
    }

    public function test_agent_navigation_contains_agent_destinations_only(): void
    {
        $response = $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.create'));

        $response->assertOk()
            ->assertSee(__('home.new_submission'))
            ->assertSee(__('examination.agent.navigation'))
            ->assertSee(__('profile.title'))
            ->assertSee(__('auth.logout'))
            ->assertDontSee(__('reports.navigation'))
            ->assertDontSee(__('users.navigation'));
    }

    public function test_officer_navigation_contains_staff_destinations_without_user_management(): void
    {
        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.index'));

        $response->assertOk()
            ->assertSee(__('home.new_submission'))
            ->assertSee(__('examination.navigation'))
            ->assertSee(__('reports.navigation'))
            ->assertSee(__('profile.title'))
            ->assertSee(__('auth.logout'))
            ->assertDontSee(__('users.navigation'))
            ->assertSee('aria-current="page"', false);
    }

    public function test_admin_navigation_contains_all_authorized_destinations(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.users.index'));

        $response->assertOk()
            ->assertSee(__('home.new_submission'))
            ->assertSee(__('examination.navigation'))
            ->assertSee(__('reports.navigation'))
            ->assertSee(__('users.navigation'))
            ->assertSee(__('profile.title'))
            ->assertSee(__('auth.logout'))
            ->assertSee('aria-current="page"', false);
    }

    public function test_logout_remains_a_post_form(): void
    {
        $response = $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.create'));

        $response->assertSee('action="'.route('auth.logout').'"', false)
            ->assertSee('method="POST"', false);
    }
}
