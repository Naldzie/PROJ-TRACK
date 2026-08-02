<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;

// AttachmentPolicy mirrors DocumentPolicy: view is delegated to the parent
// project's view policy; create/delete are admin+staff only. Contractors are
// read-only by design.
class AttachmentPolicy
{
    /**
     * Determine whether the user can view the attachment
     */
    public function view(User $user, Attachment $attachment)
    {
        return $user->can('view', $attachment->project);
    }

    /**
     * Determine whether the user can create an attachment for a project
     */
    public function create(User $user, Project $project)
    {
        return in_array($user->role, ['admin', 'staff']);
    }

    /**
     * Determine whether the user can delete the attachment
     */
    public function delete(User $user, Attachment $attachment)
    {
        return in_array($user->role, ['admin', 'staff']);
    }
}
