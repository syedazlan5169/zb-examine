<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class UiFoundationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_shared_ui_primitives_render_semantic_accessible_markup(): void
    {
        $this->view('components.ui.empty-state', ['title' => 'Nothing here'])
            ->assertSee('Nothing here');

        $this->view('components.ui.checkbox', ['name' => 'active', 'label' => 'Active'])
            ->assertSee('for="active"', false)
            ->assertSee('type="checkbox"', false);
    }
}
