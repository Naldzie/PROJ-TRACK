<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'document_type_id',
        'document_name',
        'file_path',
        'uploaded_by',
        'description',
    ];

    // === RELATIONSHIPS ===
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    // === ACCESSORS ===
    /**
     * Get the full URL to the document
     */
    public function getFileUrlAttribute()
    {
        return url('storage/' . $this->file_path);
    }

    /**
     * Get the full system path to the document
     */
    public function getFullPathAttribute()
    {
        return storage_path('app/public/' . $this->file_path);
    }

    /**
     * Get file size in KB
     */
    public function getFileSizeAttribute()
    {
        if (Storage::disk('public')->exists($this->file_path)) {
            return Storage::disk('public')->size($this->file_path) / 1024; // Convert to KB
        }
        return 0;
    }

    /**
     * Get file extension
     */
    public function getFileExtensionAttribute()
    {
        return pathinfo($this->file_path, PATHINFO_EXTENSION);
    }

    /**
     * Check if file exists on storage
     */
    public function fileExists()
    {
        return Storage::disk('public')->exists($this->file_path);
    }

    // === MUTATORS ===
    /**
     * Automatically delete file when document is deleted from database
     */
    protected static function booted()
    {
        static::deleting(function ($document) {
            // Delete the file from storage if it exists
            if ($document->fileExists()) {
                Storage::disk('public')->delete($document->file_path);
            }
        });
    }

    // === SCOPES ===
    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByType($query, $documentTypeId)
    {
        return $query->where('document_type_id', $documentTypeId);
    }

    public function scopeByTypeName($query, $typeName)
    {
        return $query->whereHas('documentType', function ($q) use ($typeName) {
            $q->where('name', $typeName);
        });
    }

    /**
     * True when $user may read this document: it belongs to a project the user
     * may view (ProjectPolicy::view — admin/staff any project, a contractor only
     * their own). Used before a document is read by id outside the documents
     * pages, e.g. the S-curve import endpoints (textfiles/
     * scurve_import_original_baseline_plan.md, F-3).
     */
    public function isViewableBy(?\App\Models\User $user): bool
    {
        if (!$user || !$this->project) {
            return false;
        }

        return \Illuminate\Support\Facades\Gate::forUser($user)->allows('view', $this->project);
    }

    // === HELPERS ===
    /**
     * Generate storage path for a document
     * Format: projects/documents/PRJ-{CODE}/{document_type}-{timestamp}-{random}.{ext}
     */
    public static function generateStoragePath($projectCode, $documentType, $extension)
    {
        $timestamp = now()->format('Y-m-d-His');
        $random = uniqid();
        $filename = strtolower($documentType) . '-' . $timestamp . '-' . $random . '.' . $extension;
        
        return 'projects/documents/' . $projectCode . '/' . $filename;
    }
}
