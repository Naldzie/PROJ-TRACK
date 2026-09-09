<?php

namespace App\Policies;

use App\Models\ScurveHeaderProposal;
use App\Models\User;

// E1 header-learning review gates (E1b: same trust as keywords — staff AND admin).
// Contractors are excluded everywhere (read-only; they never upload S-curve files).
// Called by: Gate checks + controller role guards (defense in depth).
class ScurveHeaderProposalPolicy
{
    // Review roles can view the capture list.
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Review roles can view a single capture.
    public function view(User $user, ScurveHeaderProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Approving / creating live maps: admin or staff (NOT admin-only —
    // the extension-approval lesson: sole-admin approval rots).
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Same trust level as approval.
    public function update(User $user, ScurveHeaderProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Rejecting / removing captures: admin or staff.
    public function delete(User $user, ScurveHeaderProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Restoring soft-deleted rows: admin or staff.
    public function restore(User $user, ScurveHeaderProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Permanent removal: admin or staff.
    public function forceDelete(User $user, ScurveHeaderProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }
}
