<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        return view('admin.contractormanagement', compact('contractors'));
    }

    // ✅ Add contractor (email only)
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'role' => 'contractor',
                'email' => $request->email,
                'name' => explode('@', $request->email)[0],
                'is_active' => true,
            ]);

            $last = Contractor::orderBy('id', 'desc')->first();

            $number = $last ? intval(substr($last->contractor_id, 5)) + 1 : 1;

            $contractorId = 'CONT-' . str_pad($number, 3, '0', STR_PAD_LEFT);

            Contractor::create([
                'user_id' => $user->id,
                'contractor_id' => $contractorId,
            ]);

            Mail::to($user->email)->send(new ContractorAccountMail($user->email));

            DB::commit();

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

        $user = User::findOrFail($id);

        $user->update([
            'is_active' => !$user->is_active,
        ]);

        return response()->json([
            'success' => true,
            'is_active' => $user->is_active,
            'message' => $user->is_active
                ? 'Contractor account enabled.'
                : 'Contractor account disabled.'
        ]);
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