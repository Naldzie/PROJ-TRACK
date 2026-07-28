<?php

namespace App\Services;

use App\Models\Project;

class ProjectDocumentService
{
    public function getDocumentsComplianceData(Project $project): array
    {
        $documents = $project->documents()
            ->with('documentType')
            ->orderBy('created_at', 'desc')
            ->get();

        $documentsByType = $documents->groupBy(function ($document) {
            return $document->documentType?->name ?? 'Unknown';
        });

        return [
            'documents' => $documents,
            'documents_by_type' => $documentsByType,
            'total_documents' => $documents->count(),
        ];
    }
}
