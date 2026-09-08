<?php

namespace App\Policies;

use App\Models\ParserKeywordProposal;
use App\Models\User;

// L2 learning-loop review gates (Decision L0a): staff AND admin may review.
// Contractors are excluded everywhere (they barely touch project creation).
// Called by: Gate checks + controller role guards (defense in depth).
class ParserKeywordProposalPolicy
{
    // Anyone signed in can view the review list.
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Anyone signed in (review role) can view a single proposal.
    public function view(User $user, ParserKeywordProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Approving / creating live entries: admin or staff (NOT admin-only —
    // the extension-approval lesson: sole-admin approval rots).
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Same trust level as approval.
    public function update(User $user, ParserKeywordProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Rejecting / removing entries: admin or staff.
    public function delete(User $user, ParserKeywordProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Restoring soft-deleted rows: admin or staff.
    public function restore(User $user, ParserKeywordProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }

    // Permanent removal: admin or staff.
    public function forceDelete(User $user, ParserKeywordProposal $proposal): bool
    {
        return in_array($user->role, ['admin', 'staff'], true);
    }
}
