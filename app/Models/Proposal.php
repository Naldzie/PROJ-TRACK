<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        // === FILE AND PROJECT INFORMATION ===
        'file_path',
        'project_title',
        'project_description',
        'proposed_project_name',
        
        // === CONTRACTOR (via relationship) ===
        'contractor_id',
        
        // === PROJECT DETAILS ===
        'category',
        'location_site',
        
        // === PROJECT DATES AND DURATION ===
        'proposed_start_date',
        'proposed_duration_days',
        'proposed_end_date',
        
        // === BUDGET ===
        'estimated_budget',
        
        // === SUBMISSION TRACKING ===
        'date_submitted',
        'submitted_by',
        
        // === APPROVAL INFO (all proposals are pre-approved) ===
        'evaluation_remarks',
        'approved_by',
        'approval_date',
    ];

    protected $casts = [
        'proposed_start_date' => 'date',
        'proposed_end_date' => 'date',
        'date_submitted' => 'datetime',
        'approval_date' => 'datetime',
        'estimated_budget' => 'decimal:2',
        'proposed_duration_days' => 'integer',
    ];

    // === RELATIONSHIPS ===
    
    /**
     * Get the contractor associated with this proposal
     */
    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Get the user who submitted this proposal
     */
    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Get the user who approved this proposal
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the project created from this proposal
     */
    public function project()
    {
        return $this->hasOne(Project::class);
    }

    // === HELPER METHODS ===

    /**
     * Get category label for display
     */
    public function getCategoryLabelAttribute()
    {
        $labels = [
            'new_construction' => 'New Construction',
            'renovation' => 'Renovation',
            'repair' => 'Repair',
            'maintenance' => 'Maintenance',
        ];
        return $labels[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    /**
     * Check if this proposal has been converted to a project
     */
    public function hasProject()
    {
        return $this->project()->exists();
    }
}
