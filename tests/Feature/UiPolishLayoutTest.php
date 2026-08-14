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
 * Locks down the Phase D cross-cutting UI-polish changes + the teammate
 * layout redesign (feature/teammate-layout-design):
 *   - The legacy bottom-right toast (`#toast`, local `showToast(text)`) has
 *     been removed from the four live pages (admin.email, staff.email,
 *     contractor.email, admin.rating) in favour of the shared
 *     `components/toast` (top-right `#toastContainer`, typed showToast).
 *   - The three layouts now use the redesigned collapsible sidebar
 *     (public/css/layouts/sidebar.css + public/js/layouts/sidebar.js,
 *     `#sidebarToggle` hamburger), the branded preloader
 *     (components.preloader + css/components/preloader.css +
 *     js/components/preloader.js), the shared SweetAlert2 theme
 *     (css/layouts/sweetalert-theme.css), the 20-min inactivity auto-logout
 *     (components.inactivity_logout), and the restored notification bell
 *     (notifications.partials.bell).
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
     * absent, redesigned sidebar (sidebar.css + #sidebarToggle), branded
     * preloader, SweetAlert2 theme, restored notification bell, and the
     * inactivity auto-logout wired in via the layout.
     */
    private function assertPhaseDPolish(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk()
            ->assertSee('id="toastContainer"', false)
            ->assertSee('css/layouts/sidebar.css', false)
            ->assertSee('js/layouts/sidebar.js', false)
            ->assertSee('id="sidebarToggle"', false)
            ->assertSee('css/components/preloader.css', false)
            ->assertSee('js/components/preloader.js', false)
            ->assertSee('id="pt-preloader"', false)
            ->assertSee('css/layouts/sweetalert-theme.css', false)
            ->assertSee('notification-bell', false)
            ->assertSee('Session Ending Soon', false)
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

    public function test_admin_projects_page_renders_branded_preloader(): void
    {
        $this->assertPhaseDPolish(
            $this->actingAs($this->admin)->get(route('admin.projects'))
        );
    }
}
