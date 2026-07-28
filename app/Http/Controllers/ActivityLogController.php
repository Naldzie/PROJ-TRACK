<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Project;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ActivityLogController extends Controller
{
    /**
     * 📋 VIEW LOGS
     */
    public function index(Request $request)
    {
        $logs = ActivityLog::with('user');

        // 🔎 FILTER BY ACTION
        if ($request->filled('action')) {
            $logs->where('action', $request->action);
        }

        // 🔎 FILTER BY DATE
        if ($request->filled('date')) {
            try {
                $parsedDate = Carbon::parse($request->date)->format('Y-m-d');
                $logs->whereDate('created_at', $parsedDate);
            } catch (\Exception $e) {
                $logs->whereRaw('1 = 0');
            }
        }

        // 🔎 FILTER BY USER
        if ($request->filled('user_id')) {
            $logs->where('user_id', $request->user_id);
        }

        $logs = $logs->latest('created_at')->paginate(20);

        return view('admin.login', compact('logs'));
    }

    /**
     * 🔐 LOGIN LOG
     */
    public function login(Request $request)
    {
        if (auth()->attempt($request->only('email', 'password'))) {

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Login',
                'description' => auth()->user()->name . ' logged in',
            ]);

            return redirect()->route('dashboard');
        }

        return back()->withErrors(['email' => 'Invalid credentials']);
    }

    /**
     * 🚪 LOGOUT LOG
     */
    public function logout()
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'Logout',
            'description' => auth()->user()->name . ' logged out',
        ]);

        auth()->logout();

        return redirect()->route('login');
    }

    /**
     * ✏️ EDIT USER
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $oldName = $user->name;

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'Edit User',
            'description' => auth()->user()->name . " edited user: {$oldName} → {$user->name}",
        ]);

        return back()->with('success', 'User updated successfully');
    }

    /**
     * 📁 CREATE PROJECT
     */
    public function createProject(Request $request)
    {
        $project = Project::create([
            'code' => $request->code,
            'title' => $request->title,
            'category' => $request->category,
            'location' => $request->location,
            'description' => $request->description,
            'approved_budget' => $request->approved_budget,
            'planned_start_date' => $request->planned_start_date,
            'planned_end_date' => $request->planned_end_date,
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'Create Project',
            'description' => auth()->user()->name . " created project: {$project->title}",
        ]);

        return redirect()->route('projects.index')
            ->with('success', 'Project created successfully');
    }
}