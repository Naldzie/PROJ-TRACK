<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

// Attachment: a polymorphic, record-level evidence file (inspection photo,
// issue photo, resolution proof). Mirrors the Document model's storage/accessor
// conventions but binds to a parent record (progress history | issue) via morph.
class Attachment extends Model
{
    use HasFactory;

    // Context classifications for attachment slots on a parent record.
    const CONTEXTS = ['inspection', 'issue', 'resolution'];

    // The only parent types the controller accepts. Inverse morphMany relations
    // are wired on these models in Phases C/D of the core-monitoring plan.
    const ALLOWED_ATTACHABLE_TYPES = [
        ProjectProgressHistory::class,
        Issue::class,
    ];

    protected $fillable = [
        'project_id',
        'attachable_type',
        'attachable_id',
        'context',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    // === RELATIONSHIPS ===

    /**
     * The project this attachment belongs to (denormalized for cascade + scoping)
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The parent record (ProjectProgressHistory or Issue) this file proves.
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    // === ACCESSORS ===

    /**
     * Get the full URL to the attachment
     */
    public function getFileUrlAttribute()
    {
        return url('storage/' . $this->file_path);
    }

    /**
     * Get the full system path to the attachment
     */
    public function getFullPathAttribute()
    {
        return storage_path('app/public/' . $this->file_path);
    }

    /**
     * Get file size in KB (from the stored byte count — no Storage round-trip)
     */
    public function getSizeKbAttribute()
    {
        return round(($this->file_size ?? 0) / 1024, 2);
    }

    /**
     * Human-readable size, e.g. "845 KB" or "2.3 MB"
     */
    public function getDisplaySizeAttribute()
    {
        $bytes = $this->file_size ?? 0;
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
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
     * Automatically delete the physical file when the record is deleted.
     */
    protected static function booted()
    {
        static::deleting(function ($attachment) {
            if ($attachment->fileExists()) {
                Storage::disk('public')->delete($attachment->file_path);
            }
        });
    }

    // === HELPERS ===

    /**
     * Generate storage path for an attachment.
     * Format: projects/attachments/{PROJECT_CODE}/{context}/{timestamp}-{random}.{ext}
     */
    public static function generateStoragePath($projectCode, $context, $extension)
    {
        $timestamp = now()->format('Y-m-d-His');
        $random = uniqid();
        $filename = $timestamp . '-' . $random . '.' . strtolower($extension);

        return 'projects/attachments/' . $projectCode . '/' . strtolower($context) . '/' . $filename;
    }
}
