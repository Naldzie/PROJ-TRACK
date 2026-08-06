<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * UiPolishLayoutTest
 * ============================================================================
 *
 * Locks down the Phase D cross-cutting UI-polish changes:
 *   - The legacy bottom-right toast (`#toast`, local `showToast(text)`) has
 *     been removed from the four live pages (admin.email, staff.email,
 *     contractor.email, admin.rating) in favour of the shared
 *     `components/toast` (top-right `#toastContainer`, typed showToast).
 *   - The three layouts now include the shared responsive sidebar assets
 *     (public/css/layouts/responsive.css + public/js/layouts/sidebar.js)
 *     and a hamburger `.sidebar-toggle` button.
 *
 * Run With
 * --------
 *   php artisan test --filter=UiPolishLayoutTest
 */
class UiPolishLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $contractorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Polish Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Polish Staff',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->contractorUser = User::create([
            'name'      => 'Polish Contractor',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'contractor',
            'is_active' => true,
        ]);

        Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Polish Builders',
            'company_name'    => 'Polish Builders Inc',
        ]);
    }

    /**
     * Shared assertions for a rendered page: shared toast present, legacy toast
     * absent, responsive sidebar wired in via the layout.
     */
    private function assertPhaseDPolish(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk()
            ->assertSee('id="toastContainer"', false)
            ->assertSee('css/layouts/responsive.css', false)
            ->assertSee('js/layouts/sidebar.js', false)
            ->assertSee('class="sidebar-toggle"', false)
            ->assertDontSee('<div class="toast" id="toast"', false);
    }

    public function test_admin_email_page_uses_shared_toast_and_responsive_sidebar(): void
    {
        $this->assertPhaseDPolish(
            $this->actingAs($this->admin)->get(route('admin.email'))
        );
    }

    public function test_staff_email_page_uses_shared_toast_and_responsive_sidebar(): void
    {
        $this->assertPhaseDPolish(
            $this->actingAs($this->staff)->get(route('staff.email'))
        );
    }

    public function test_contractor_email_page_uses_shared_toast_and_responsive_sidebar(): void
    {
        $this->assertPhaseDPolish(
            $this->actingAs($this->contractorUser)->get(route('contractor.email'))
        );
    }

    public function test_admin_rating_page_uses_shared_toast_and_responsive_sidebar(): void
    {
        $this->assertPhaseDPolish(
            $this->actingAs($this->admin)->get(route('admin.rating'))
        );
    }
}
