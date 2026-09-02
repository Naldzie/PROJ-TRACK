<?php

namespace App\Policies;

use App\Models\User;
use App\Models\BudgetSource;

class BudgetSourcePolicy
{
    /**
     * Determine if the user can view any budget sources
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the budget source
     */
    public function view(User $user, BudgetSource $budgetSource): bool
    {
        return true;
    }

    /**
     * Determine if the user can create budget sources
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can update the budget source
     */
    public function update(User $user, BudgetSource $budgetSource): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can delete the budget source
     */
    public function delete(User $user, BudgetSource $budgetSource): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can restore the budget source
     */
    public function restore(User $user, BudgetSource $budgetSource): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can permanently delete the budget source
     */
    public function forceDelete(User $user, BudgetSource $budgetSource): bool
    {
        return $user->role === 'admin';
    }
}
