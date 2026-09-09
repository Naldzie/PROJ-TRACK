<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\ScurveHeaderProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// E1-1: silent header-block capture from failed S-curve imports.
// Collection is open to admin AND staff (E1b); contractors get 403. Captures never
// affect extraction until E1-2 review.
class ScurveHeaderProposalTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Header ' . $role,
            'email' => fake()->unique()->safeEmail(),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeWorkbook(string $path, string $descHeader = 'WEIRD DESC HEADER'): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', $descHeader);
        $sheet->setCellValue('B10', 'TIMEFRAME ONE');
        $sheet->setCellValue('A11', 'Some Work');
        $sheet->setCellValue('B11', 100);
        (new Xlsx($spreadsheet))->save($path);
    }

    // Store captures from an admin file upload (no contractor → global NULL row).
    // Called by: POST /admin/scurve-header-proposals/collect from scurveCaptureHeaders().
    public function test_admin_can_store_capture(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);

        $response = $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', ['file' => $upload]);

        $response->assertCreated()->assertJson(['success' => true, 'stored' => 1]);
        $this->assertDatabaseHas('scurve_header_proposals', [
            'status' => 'proposed',
            'source' => 'auto-capture',
            'source_type' => 'excel',
            'occurrences' => 1,
        ]);
        $this->assertNull(ScurveHeaderProposal::first()->contractor_id);

        unlink($path);
    }

    // Repeats bump occurrences; the third confirmation auto-promotes to live.
    // Called by: repeated failures on the same unknown layout.
    public function test_repeats_increment_and_third_promotes_to_live(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);

        $staff = $this->makeUser('staff');
        for ($i = 0; $i < 3; $i++) {
            $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);
            $this->actingAs($staff)
                ->post('/staff/scurve-header-proposals/collect', ['file' => $upload])
                ->assertCreated();
        }

        $row = ScurveHeaderProposal::first();
        $this->assertNotNull($row);
        $this->assertSame(3, $row->occurrences);
        $this->assertSame('live', $row->status);

        unlink($path);
    }

    // Contractors never upload, and the endpoint refuses them regardless.
    // Called by: POST to either role collect route as a contractor.
    public function test_contractor_is_forbidden_on_both_routes(): void
    {
        $contractor = $this->makeUser('contractor');

        $this->actingAs($contractor)->postJson('/staff/scurve-header-proposals/collect', [])
            ->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->postJson('/admin/scurve-header-proposals/collect', [])
            ->assertRedirect(route('unauthorized'));
        $this->assertDatabaseCount('scurve_header_proposals', 0);
    }

    // Source discipline: exactly one source, Excel only, known contractor ids.
    // Called by: malformed capture payloads.
    public function test_validation_rejects_bad_payloads(): void
    {
        $admin = $this->makeUser('admin');

        // No source at all.
        $this->actingAs($admin)->postJson('/admin/scurve-header-proposals/collect', [])
            ->assertUnprocessable();

        // Unknown contractor id (multipart posts need explicit JSON accept for 422s).
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);
        $this->actingAs($admin)->post(
            '/admin/scurve-header-proposals/collect',
            ['file' => $upload, 'contractor_id' => 999999],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        // Non-Excel upload.
        $pdf = UploadedFile::fake()->create('curve.pdf', 100, 'application/pdf');
        $this->actingAs($admin)->post(
            '/admin/scurve-header-proposals/collect',
            ['file' => $pdf],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertDatabaseCount('scurve_header_proposals', 0);
        unlink($path);
    }

    // Stored documents resolve the contractor via Document → project (E1a hybrid scope).
    // Called by: detail-flow captures where the client sends only document_id.
    public function test_document_id_resolves_contractor_via_project(): void
    {
        Storage::fake('public');

        $admin = $this->makeUser('admin');
        $contractorUser = $this->makeUser('contractor');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'company_name' => 'Capture Builders',
        ]);
        $project = Project::factory()->create(['contractor_id' => $contractor->id]);
        $type = DocumentType::firstOrCreate(['name' => 'Schedule']);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        Storage::disk('public')->put('cap/weird.xlsx', file_get_contents($path));

        $doc = Document::create([
            'project_id' => $project->id,
            'document_type_id' => $type->id,
            'document_name' => 'Weird curve',
            'file_path' => 'cap/weird.xlsx',
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/scurve-header-proposals/collect', ['document_id' => $doc->id])
            ->assertCreated()->assertJson(['success' => true]);

        $row = ScurveHeaderProposal::first();
        $this->assertNotNull($row);
        $this->assertSame($contractor->id, $row->contractor_id);

        unlink($path);
    }

    // Explicit contractor_id wins over document resolution.
    // Called by: wizard-flow captures carrying the Page-4 contractor choice.
    public function test_explicit_contractor_id_wins(): void
    {
        Storage::fake('public');

        $admin = $this->makeUser('admin');
        $contractorUser = $this->makeUser('contractor');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'company_name' => 'Explicit Builders',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);

        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'file' => $upload,
            'contractor_id' => $contractor->id,
        ])->assertCreated();

        $this->assertSame($contractor->id, ScurveHeaderProposal::first()->contractor_id);

        unlink($path);
    }

    // Staff can review: list shows captures most-seen first, with ?status= filter.
    // Called by: GET .../scurve-header-proposals/all from the review modal.
    public function test_staff_can_list_captures_with_status_filter(): void
    {
        $staff = $this->makeUser('staff');
        ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'occurrences' => 5, 'status' => 'proposed',
        ]);
        ScurveHeaderProposal::create([
            'header_rows_text' => 'OTHER HEADER', 'header_hash' => hash('sha256', 'b'),
            'occurrences' => 1, 'status' => 'live',
        ]);

        $this->actingAs($staff)->getJson('/staff/scurve-header-proposals/all')
            ->assertOk()->assertJsonCount(2);
        $this->actingAs($staff)->getJson('/staff/scurve-header-proposals/all?status=live')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.status', 'live');
    }

    // Approve flips proposed → live (staff may approve per E1b, via staff route).
    // Called by: PATCH .../approve from the review modal Approve button.
    public function test_staff_can_approve_capture(): void
    {
        $staff = $this->makeUser('staff');
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
        ]);

        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$row->id}/approve")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame('live', $row->fresh()->status);
    }

    // Reject soft-deletes (history preserved, recoverable).
    // Called by: DELETE from the review modal Reject button.
    public function test_admin_can_reject_capture(): void
    {
        $admin = $this->makeUser('admin');
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
        ]);

        $this->actingAs($admin)->deleteJson("/admin/scurve-header-proposals/{$row->id}")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSoftDeleted('scurve_header_proposals', ['id' => $row->id]);
    }

    // Contractors get nothing on review routes either (redirect by role middleware).
    // Called by: any contractor request to the review endpoints.
    public function test_contractor_blocked_from_review_endpoints(): void
    {
        $contractor = $this->makeUser('contractor');
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
        ]);

        $this->actingAs($contractor)->getJson('/staff/scurve-header-proposals/all')->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->patchJson("/admin/scurve-header-proposals/{$row->id}/approve")->assertRedirect(route('unauthorized'));
        $this->assertSame('proposed', $row->fresh()->status);
    }
}
