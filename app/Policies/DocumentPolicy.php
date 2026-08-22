<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determine whether the user can view the document
     */
    public function view(User $user, Document $document)
    {
        // User can view if they can view the associated project
        return $user->can('view', $document->project);
    }

    /**
     * Determine whether the user can create documents for a project
     */
    public function create(User $user, Project $project)
    {
        if ($user->role === 'admin') return true;
        return $project->isAssignedTo($user);
    }

    /**
     * Determine whether the user can delete the document
     */
    public function delete(User $user, Document $document)
    {
        if ($user->role === 'admin') return true;
        return $document->project->isAssignedTo($user);
    }

    /**
     * Determine whether the user can restore the document
     */
    public function restore(User $user, Document $document)
    {
        // Only admin can restore deleted documents
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can permanently delete the document
     */
    public function forceDelete(User $user, Document $document)
    {
        // Only admin can force delete
        return $user->role === 'admin';
    }
}
