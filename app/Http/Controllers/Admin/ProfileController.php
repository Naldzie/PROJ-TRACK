<?php
// New files(for merging)
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    // Show Profile Page
    public function edit()
    {
        $user = Auth::user();
        return view('admin.profile', compact('user'));
    }

    // Update Profile
   public function update(Request $request)
{
    $user = Auth::user();
    $admin = $user->admin;

    $request->validate([
        'full_name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email,' . $user->id,
        'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    // =============================
    // HANDLE PHOTO
    // =============================
    if ($request->hasFile('profile_photo')) {

        if ($user->profile_photo && Storage::disk('public')->exists($user->profile_photo)) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        $path = $request->file('profile_photo')->store('profile', 'public');

        $user->update([
            'profile_photo' => $path
        ]);
    }

    // =============================
    // UPDATE USERS TABLE
    // =============================
    $user->update([
        'email' => $request->email,
    ]);

    // =============================
    // UPDATE ADMINS TABLE
    // =============================
    if ($admin) {
        $admin->update([
            'full_name' => $request->full_name,
        ]);
    }

    return back()->with('success', 'Profile updated successfully.');
}

    // Change Password
        public function changePassword(Request $request)
        {
            $user = Auth::user();

            $request->validate([
                'current_password' => 'required',

                'new_password' => [
                    'required',
                    'confirmed',
                    'min:8',
                    'regex:/[A-Z]/',          // at least 1 uppercase
                    'regex:/[0-9]/',          // at least 1 number
                    'regex:/[^A-Za-z0-9]/'    // at least 1 special character
                ],
            ], [
                'current_password.required' => 'Please enter your current password.',
                'new_password.required' => 'Please enter a new password.',
                'new_password.min' => 'Password must be at least 8 characters.',
                'new_password.confirmed' => 'Password confirmation does not match.',
                'new_password.regex' => 'Password must include at least 1 uppercase letter, 1 number, and 1 special character.',
            ]);

            // Check current password
            if (!Hash::check($request->current_password, $user->password)) {
                return back()
                    ->withErrors(['current_password' => 'Current password is incorrect.'])
                    ->withInput();
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Password Change',
                'description' => 'User changed account password',
                'ip_address' => $request->ip(),
                'performed_at' => now(),
            ]);

            return back()->with('success', 'Password changed successfully.');
        }
}