<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Contractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Mail\ContractorAccountMail;

class ContractorController extends Controller
{
    // ✅ Show all contractors
    public function index()
    {
        $contractors = User::where('role', 'contractor')
            ->with('contractor')
            ->latest()
            ->get();

        $recentActivity = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('admin.contractormanagement', compact('contractors', 'recentActivity'));
    }

    // ✅ Add contractor (email + company name)
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'company_name' => 'required|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'role' => 'contractor',
                'email' => $request->email,
                'name' => $request->company_name,
                'is_active' => true,
            ]);

            $last = Contractor::orderBy('id', 'desc')->first();

            $number = $last ? intval(substr($last->contractor_id, 5)) + 1 : 1;

            $contractorId = 'CONT-' . str_pad($number, 3, '0', STR_PAD_LEFT);

            Contractor::create([
                'user_id' => $user->id,
                'contractor_id' => $contractorId,
                'company_name' => $request->company_name,
                'contractor_name' => $request->company_name,
            ]);

            Mail::to($user->email)->send(new ContractorAccountMail($user->email));

            DB::commit();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'contractor_created',
                'description' => 'Added new contractor — ' . $request->company_name . ' (' . $user->email . ')',
                'ip_address' => $request->ip(),
                'visibility' => 'admin_only',
            ]);

            return back()->with('success', 'Contractor added successfully and email sent!');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Failed to add contractor. Please try again.');
        }
    }

    // ✅ Disable / Enable contractor (replaces destroy)
    public function toggleStatus(Request $request, $id)
    {
        if (auth()->id() == $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot disable your own account.'
            ], 403);
        }

        DB::beginTransaction();

        try {
            $user = User::findOrFail($id);

            $user->update([
                'is_active' => !$user->is_active,
            ]);

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'contractor_' . ($user->is_active ? 'enabled' : 'disabled'),
                'description' => ($user->is_active ? 'Enabled' : 'Disabled') . ' contractor account — ' . ($user->name ?? $user->email),
                'ip_address' => $request->ip(),
                'visibility' => 'admin_only',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'is_active' => $user->is_active,
                'message' => $user->is_active
                    ? 'Contractor account enabled.'
                    : 'Contractor account disabled.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle contractor status. Please try again.'
            ], 500);
        }
    }

    // ✅ View contractor (WITH GOOGLE NAME + PHOTO SUPPORT)
    public function show($id)
    {
        $user = User::with('contractor')->findOrFail($id);

        $profilePhoto = $user->profile_photo
            ? (str_starts_with($user->profile_photo, 'http')
                ? $user->profile_photo
                : asset('storage/' . $user->profile_photo))
            : ($user->avatar ?? 'https://ui-avatars.com/api/?name=' . urlencode($user->name));

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name ?? 'N/A',
            'profile_photo' => $profilePhoto,
            'is_active' => $user->is_active,

            'role' => 'contractor',
            'label' => 'Contractor',

            'contractor_id' => optional($user->contractor)->contractor_id,
            'company_name' => optional($user->contractor)->company_name,
            'contractor_name' => optional($user->contractor)->contractor_name,
            'contact_number' => optional($user->contractor)->contact_number,

            'company_landline' => optional($user->contractor)->company_landline,
            'company_address' => optional($user->contractor)->company_address,
        ]);
    }
}