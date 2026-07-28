<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileStaffController extends Controller
{
    // Show Profile Page
    public function index()
    {
        $user = Auth::user();
        $staff = $user->staff;

        $isIncomplete =
            !$staff ||
            !$staff->profession ||
            !$staff->contact_number;

        return view('staff.profile', compact('user', 'staff', 'isIncomplete'));
    }

    // Update ONLY editable fields
    public function update(Request $request)
    {
        $user = Auth::user();
        $staff = $user->staff;

        $request->validate([
            'profession' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:20',
        ]);

        // Update staff info ONLY
        if ($staff) {
            $staff->update([
                'profession' => $request->profession,
                'contact_number' => $request->contact_number,
            ]);
        }

        return back()->with('success', 'Profile updated successfully!');
    }
}