<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Project;

class ProjectPolicy
{
    /**
     * Determine if the user can view any project
     * (Used for viewAny checks - typically for listing pages)
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users (admin, staff, contractor) can view some projects
        return in_array($user->role, ['admin', 'staff', 'contractor']);
    }

    /**
     * Determine if the user can view a specific project
     * 
     * Rules:
     * - Admin: Can view any project
     * - Staff: Can view any project
     * - Contractor: Can only view their assigned projects
     */
    public function view(User $user, Project $project): bool
    {
        // Admin and Staff can view all projects
        if (in_array($user->role, ['admin', 'staff'])) {
            return true;
        }

        // Contractor can only view if they are assigned to this project
        if ($user->role === 'contractor') {
            // Check if user's contractor record matches the project's contractor
            $contractor = $user->contractor;
            if (!$contractor) {
                return false; // User is contractor role but has no contractor record
            }
            return $project->contractor_id === $contractor->id;
        }

        return false;
    }

    /**
     * Determine if the user can create a project
     * 
     * Rules:
     * - Admin: Can create
     * - Staff: Can create
     * - Contractor: Cannot create
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff']);
    }

    /**
     * Determine if the user can update a project
     * 
     * Rules:
     * - Admin: Can update any project
     * - Staff: Can only update assigned projects
     * - Contractor: Cannot update
     */
    public function update(User $user, Project $project): bool
    {
        if ($user->role === 'admin') return true;
        return $project->isAssignedTo($user);
    }

    /**
     * Determine if the user can delete a project
     * 
     * Rules:
     * - Admin: Can delete
     * - Staff: Cannot delete
     * - Contractor: Cannot delete
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can archive a project
     * 
     * Rules:
     * - Admin: Can archive
     * - Staff: Can archive
     * - Contractor: Cannot archive
     */
    public function archive(User $user, Project $project): bool
    {
        return in_array($user->role, ['admin', 'staff']);
    }

    /**
     * Determine if the user can restore an archived project
     * 
     * Rules:
     * - Admin: Can restore
     * - Staff: Can restore
     * - Contractor: Cannot restore
     */
    public function restore(User $user, Project $project): bool
    {
        return in_array($user->role, ['admin', 'staff']);
    }
}
