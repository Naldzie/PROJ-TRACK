<?php

namespace App\Http\Controllers\Contractor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Contractor;

class ProfileContractorController extends Controller
{
    // ✅ Show Profile Page
    public function index()
    {
        $user = Auth::user();
        $contractor = $user->contractor ?? Contractor::firstOrCreate([
    'user_id' => $user->id
]);

        $isIncomplete =
            !$contractor ||
            !$contractor->company_name ||
            !$contractor->contractor_name ||
            !$contractor->contact_number;

        return view('contractor.profile', compact('user', 'contractor', 'isIncomplete'));
    }

    // ✅ Update ONLY editable fields (like staff)
    public function update(Request $request)
    {
        $user = Auth::user();
        $contractor = $user->contractor ?? Contractor::firstOrCreate([
    'user_id' => $user->id
]);

        $request->validate([
            'company_name' => 'nullable|string|max:255',
            'contractor_name' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:20',
            'company_landline' => 'nullable|string|max:20',
            'company_address' => 'nullable|string|max:255',
        ]);

        // Update contractor info ONLY
        if ($contractor) {
            $contractor->update([
                'company_name' => $request->company_name,
                'contact_number' => $request->contact_number,
                'company_landline' => $request->company_landline,
                'company_address' => $request->company_address,
            ]);
        }

        return back()->with('success', 'Profile updated successfully!');
    }
}