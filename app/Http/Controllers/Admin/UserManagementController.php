<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index()
    {
        $staffs = User::where('role', 'staff')->get();
        return view('admin.users.index', compact('staffs'));
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $staffId = 'STF-' . rand(1000,9999);

        User::create([
            'role' => 'staff',
            'staff_id' => $staffId,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'email' => $request->email,
            'password' => Hash::make('password123'),
            'contact_number' => $request->contact_number,
            'gender' => $request->gender,
            'date_of_birth' => $request->date_of_birth,
            'address' => $request->address,
        ]);

        return redirect()->route('admin.users.index');
    }

    public function destroy($id)
    {
        User::findOrFail($id)->delete();
        return back();
    }
}
