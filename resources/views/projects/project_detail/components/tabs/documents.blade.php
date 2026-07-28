<!-- DOCUMENTS TAB -->
<div id="documents" class="tab-content">
    <div style="padding: 20px;">

        {{-- ── Header row ─────────────────────────────────────────────────── --}}
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
                Project Documents
            </h3>
            @can('update', $project)
            <button type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#uploadDocumentModal">
                <i class="bi bi-upload" style="margin-right: 6px;"></i>
                Add Document
            </button>
            @endcan
        </div>

        @php
            $documents = $data['documents_by_type'] ?? collect();

            $colorMap = [
                'Contract'      => ['bg' => 'rgba(26,86,219,0.1)',    'stroke' => 'var(--primary)'],
                'Specification' => ['bg' => 'rgba(60,196,255,0.12)',  'stroke' => 'var(--blue)'],
                'Technical'     => ['bg' => 'rgba(60,196,255,0.12)',  'stroke' => 'var(--blue)'],
                'Report'        => ['bg' => 'rgba(26,86,219,0.1)',    'stroke' => 'var(--primary)'],
                'Progress'      => ['bg' => 'rgba(26,86,219,0.1)',    'stroke' => 'var(--primary)'],
                'Inspection'    => ['bg' => 'rgba(185,123,255,0.12)', 'stroke' => 'var(--purple)'],
                'Extension'     => ['bg' => 'rgba(245,158,11,0.1)',   'stroke' => 'var(--warning)'],
                'Supporting'    => ['bg' => 'rgba(255,79,79,0.12)',   'stroke' => 'var(--red)'],
            ];

            $getColor = fn($type) => $colorMap[$type]
                ?? ['bg' => 'rgba(107,114,128,0.1)', 'stroke' => '#6b7280'];

            $getFileSize = function ($sizeInKb) {
                return $sizeInKb >= 1024
                    ? round($sizeInKb / 1024, 1) . ' MB'
                    : round($sizeInKb, 1) . ' KB';
            };

            // Build role-aware route names
            $role       = auth()->user()->role;
            $viewRoute  = $role . '.documents.view';
            $dlRoute    = $role . '.documents.download';

            // Grab any one document to use as the @can gate subject for the JS block.
            // DocumentPolicy::delete() only checks the user's role, so any document works.
            $anyDocument = $documents->flatten()->first();
        @endphp

        @if ($documents->isEmpty())
            <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                <p>No documents uploaded yet. Upload documents to track project files and compliance.</p>
            </div>
        @else
            @foreach ($documents as $typeName => $docsInType)
                <div class="card doc-type-group"
                     id="doc-group-{{ Str::slug($typeName) }}"
                     style="margin-bottom: 24px;">
                    <div class="card-header">
                        <span class="card-title">{{ $typeName }}</span>
                        <span class="doc-type-count card-action" style="opacity: 0.7;">
                            ({{ $docsInType->count() }} file{{ $docsInType->count() !== 1 ? 's' : '' }})
                        </span>
                    </div>
                    <div class="doc-list">
                        @foreach ($docsInType as $document)
                            @php
                                $color      = $getColor($typeName);
                                $fileName   = $document->document_name;
                                $fileSize   = $getFileSize($document->file_size);
                                $uploadDate = $document->created_at->format('M d, Y');

                                // Build the view & download URLs via named routes
                                $viewUrl     = route($viewRoute, $document->id);
                                $downloadUrl = route($dlRoute,   $document->id);
                            @endphp

                            <div class="doc-item doc-item-clickable"
                                 id="doc-row-{{ $document->id }}"
                                 title="Click to preview"
                                 onclick="openDocumentViewer({
                                     id:          {{ $document->id }},
                                     name:        {{ Js::from($fileName) }},
                                     type:        {{ Js::from($typeName) }},
                                     size:        {{ Js::from($fileSize) }},
                                     uploadedBy:  {{ Js::from($document->uploaded_by ?? '—') }},
                                     uploadedAt:  {{ Js::from($uploadDate) }},
                                     viewUrl:     {{ Js::from($viewUrl) }},
                                     downloadUrl: {{ Js::from($downloadUrl) }},
                                 })">

                                <div class="doc-icon" style="background: {{ $color['bg'] }}">
                                    <svg viewBox="0 0 24 24" fill="none"
                                         stroke="{{ $color['stroke'] }}" stroke-width="1.8">
                                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                </div>

                                <div class="doc-info">
                                    <div class="doc-name">{{ $fileName }}</div>
                                    <div class="doc-meta">{{ $fileSize }} · {{ $uploadDate }}</div>
                                </div>

                                {{-- Quick-action icons on hover --}}
                                <div class="doc-quick-actions">

                                    {{-- Preview --}}
                                    <span class="doc-qa-btn" title="Preview"
                                          onclick="event.stopPropagation(); openDocumentViewer({
                                              id:          {{ $document->id }},
                                              name:        {{ Js::from($fileName) }},
                                              type:        {{ Js::from($typeName) }},
                                              size:        {{ Js::from($fileSize) }},
                                              uploadedBy:  {{ Js::from($document->uploaded_by ?? '—') }},
                                              uploadedAt:  {{ Js::from($uploadDate) }},
                                              viewUrl:     {{ Js::from($viewUrl) }},
                                              downloadUrl: {{ Js::from($downloadUrl) }},
                                          })">
                                        <i class="bi bi-eye"></i>
                                    </span>

                                    {{-- Open in new tab --}}
                                    <a class="doc-qa-btn" href="{{ $viewUrl }}" target="_blank"
                                       title="Open in new tab"
                                       onclick="event.stopPropagation();">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>

                                    {{-- Download --}}
                                    <a class="doc-qa-btn" href="{{ $downloadUrl }}"
                                       title="Download"
                                       onclick="event.stopPropagation();">
                                        <i class="bi bi-download"></i>
                                    </a>

                                    {{-- Delete — policy decides who sees this --}}
                                    @can('delete', $document)
                                        <span class="doc-qa-btn doc-qa-btn-danger"
                                              title="Delete document"
                                              onclick="event.stopPropagation();
                                                       deleteDocument(
                                                           {{ $document->id }},
                                                           {{ Js::from($fileName) }}
                                                       );">
                                            <i class="bi bi-trash3"></i>
                                        </span>
                                    @endcan

                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif

    </div>
</div>
<!-- END DOCUMENTS TAB -->

{{-- ── Include the reusable viewer modal once per page ──────────────── --}}
@include('components.document-viewer-modal')

{{-- ── Styles ───────────────────────────────────────────────────────── --}}
<style>
.doc-item-clickable {
    cursor: pointer;
    position: relative;
    transition: background 0.18s, box-shadow 0.18s;
}

.doc-item-clickable:hover {
    background: rgba(37, 99, 235, 0.05);
    box-shadow: inset 3px 0 0 #2563eb;
}

.doc-quick-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-left: auto;
    opacity: 0;
    transition: opacity 0.18s;
    flex-shrink: 0;
}

.doc-item-clickable:hover .doc-quick-actions {
    opacity: 1;
}

.doc-qa-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 6px;
    font-size: 14px;
    color: #64748b;
    background: transparent;
    border: 1px solid transparent;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
    line-height: 1;
}

.doc-qa-btn:hover {
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    border-color: rgba(37, 99, 235, 0.2);
}

/* Delete button — danger hover */
.doc-qa-btn-danger:hover {
    background: rgba(239, 68, 68, 0.1) !important;
    color: #ef4444 !important;
    border-color: rgba(239, 68, 68, 0.25) !important;
}

/* Row removal animation */
@keyframes docRowRemove {
    from { opacity: 1; transform: translateX(0);    max-height: 80px; }
    to   { opacity: 0; transform: translateX(16px); max-height: 0; padding: 0; margin: 0; }
}
.doc-row-removing {
    overflow: hidden;
    animation: docRowRemove 0.28s ease forwards;
    pointer-events: none;
}
</style>

{{-- ── Delete JS — only emitted when the current user can delete documents ── --}}
@if($anyDocument && auth()->user()->can('delete', $anyDocument))
<script>
/**
 * deleteDocument
 *
 * Uses the shared reusable components:
 *   • showDeleteConfirmation()  — components/delete-confirmation-modal.blade.php
 *   • showToast()               — components/toast.blade.php
 *
 * On confirm:
 *   1. Sends DELETE /{role}/documents/{id}
 *   2. Controller writes the deletion to activity_logs
 *   3. Animates the row out of the DOM
 *   4. Updates the file-count badge on the type-group card
 *   5. Removes the group card entirely if it becomes empty
 *   6. Shows a success or error toast
 */
function deleteDocument(documentId, documentName) {
    showDeleteConfirmation(
        'Delete Document',
        `Are you sure you want to permanently delete <strong>"${documentName}"</strong>?<br>
         <span style="color: #ef4444; font-size: 13px; margin-top: 6px; display: inline-block;">
             <i class="bi bi-exclamation-triangle-fill" style="margin-right: 4px;"></i>
             This action cannot be undone.
         </span>`,
        function () {
            const deleteUrl = `/${window.userRole}/documents/${documentId}`;
            const csrf      = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch(deleteUrl, {
                method:  'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept':       'application/json',
                },
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const row = document.getElementById(`doc-row-${documentId}`);
                    if (row) {
                        row.classList.add('doc-row-removing');
                        row.addEventListener('animationend', () => {
                            const docList   = row.closest('.doc-list');
                            const groupCard = row.closest('.doc-type-group');
                            row.remove();

                            if (docList && groupCard) {
                                const remaining = docList.querySelectorAll('.doc-item').length;
                                const badge     = groupCard.querySelector('.doc-type-count');
                                if (badge) {
                                    badge.textContent =
                                        `(${remaining} file${remaining !== 1 ? 's' : ''})`;
                                }
                                if (remaining === 0) {
                                    groupCard.style.transition = 'opacity 0.25s';
                                    groupCard.style.opacity    = '0';
                                    setTimeout(() => groupCard.remove(), 260);
                                }
                            }
                        }, { once: true });
                    }

                    showToast('Document deleted successfully.', 'success');
                } else {
                    showToast(data.message || 'Failed to delete the document.', 'error');
                }
            })
            .catch(() => {
                showToast('An unexpected error occurred. Please try again.', 'error');
            });
        }
    );
}
</script>
@endif