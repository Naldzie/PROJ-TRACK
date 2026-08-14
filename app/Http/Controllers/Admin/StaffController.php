<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\LoginPageLinkMail;

class StaffController extends Controller
{
    public function index()
    {
        $staffs = User::whereIn('role', ['admin', 'staff'])
            ->with('staff')
            ->orderByRaw("role = 'admin' DESC") // admins first
            ->latest()
            ->get();

        $recentActivity = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('admin.staffmanagement', compact('staffs', 'recentActivity'));
    }

    // ✅ Add staff via Google account only (restricted domain)
    public function store(Request $request)
    {
        $request->validate([
            'email' => [
                'required',
                'email',
                'unique:users,email',

                // ✅ Custom validation
                function ($attribute, $value, $fail) {

                    // 🔒 Only allow my.cspc.edu.ph
                    if (!str_ends_with($value, '@my.cspc.edu.ph')) {
                        $fail('Only @my.cspc.edu.ph emails are allowed.');
                        return;
                    }

                    // 🔍 Check if domain exists (basic real check)
                    $domain = substr(strrchr($value, "@"), 1);
                    if (!checkdnsrr($domain, "MX")) {
                        $fail('Invalid email domain.');
                    }
                },
            ],
        ]);

        DB::beginTransaction();

        try {
            // ✅ Create user
            $user = User::create([
                'role' => 'staff',
                'email' => $request->email,
                'name' => explode('@', $request->email)[0], // auto name
                'is_active' => true,
            ]);

            // ✅ Generate staff ID
            $staffId = 'STAFF-' . strtoupper(Str::random(6));

            // ✅ Create staff record
            Staff::create([
                'user_id' => $user->id,
                'staff_id' => $staffId,
            ]);

            // ✅ Send email
            Mail::to($user->email)->send(new LoginPageLinkMail($user->email));

            DB::commit();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'staff_created',
                'description' => 'Added new staff member — ' . $user->email,
                'ip_address' => $request->ip(),
                'visibility' => 'admin_only',
            ]);

            return back()->with('success', 'Staff added successfully and email sent!');

        } catch (\Exception $e) {
            DB::rollback();

            return back()->with('error', 'Failed to add staff. Please try again.');
        }
    }

    // ✅ Change role
    public function changeRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:admin,staff',
        ]);

        // ❗ Prevent self-modification
        if (auth()->id() == $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own role.'
            ], 403);
        }

        $user = User::findOrFail($id);

        $user->update([
            'role' => $request->role,
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_changed',
            'description' => 'Changed ' . ($user->name ?? $user->email) . "'s role to " . $request->role,
            'ip_address' => $request->ip(),
            'visibility' => 'admin_only',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully'
        ]);
    }

    // ✅ Disable / Enable staff (replaces destroy)
    public function toggleStatus(Request $request, $id)
    {
        // ❗ Prevent self-disable
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
                'action' => 'staff_' . ($user->is_active ? 'enabled' : 'disabled'),
                'description' => ($user->is_active ? 'Enabled' : 'Disabled') . ' staff account — ' . ($user->name ?? $user->email),
                'ip_address' => $request->ip(),
                'visibility' => 'admin_only',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'is_active' => $user->is_active,
                'message' => $user->is_active
                    ? 'Staff account enabled.'
                    : 'Staff account disabled.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle staff status. Please try again.'
            ], 500);
        }
    }

    // ✅ View details
    public function show($id)
    {
        $user = User::with(['staff', 'admin'])->findOrFail($id);

        $profilePhoto = $user->profile_photo
            ? (str_starts_with($user->profile_photo, 'http')
                ? $user->profile_photo
                : asset('storage/' . $user->profile_photo))
            : 'https://ui-avatars.com/api/?name=' . urlencode($user->name ?? 'User');

        // When the user hasn't signed in yet and has no name, show the first
        // two letters of their email as a placeholder (e.g. ne@... → NE).
        $displayName = $user->name;
        if (!$displayName && $user->email) {
            $displayName = strtoupper(substr(explode('@', $user->email)[0], 0, 2));
        }
        if (!$displayName) {
            $displayName = 'No name yet';
        }

        if ($user->role === 'admin') {

            return response()->json([
                'id' => $user->id,
                'email' => $user->email,
                'name' => $displayName,
                'profile_photo' => $profilePhoto,
                'is_active' => $user->is_active,

                'role' => 'admin',
                'label' => 'Administrator',

                'staff_id' => optional($user->admin)->admin_id ?? 'ADMIN',
                'profession' => null,
                'contact_number' => null,
            ]);

        } else {

            return response()->json([
                'id' => $user->id,
                'email' => $user->email,
                'name' => $displayName,
                'profile_photo' => $profilePhoto,
                'is_active' => $user->is_active,

                'role' => 'staff',
                'label' => 'Staff Member',

                'staff_id' => optional($user->staff)->staff_id,
                'profession' => optional($user->staff)->profession,
                'contact_number' => optional($user->staff)->contact_number,
            ]);
        }
    }
}