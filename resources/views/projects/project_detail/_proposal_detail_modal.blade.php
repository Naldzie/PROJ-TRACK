{{-- === PROPOSAL DETAIL MODAL === --}}
{{-- Included from project_detail.blade.php when $project->proposal exists --}}

<div class="modal fade" id="proposalDetailModal" tabindex="-1" aria-labelledby="proposalDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="proposalDetailModalLabel">
                    <i class="bi bi-file-earmark-check text-primary"></i> Proposal Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                @php $proposal = $project->proposal; @endphp

                <div class="row g-0">
                    {{-- === LEFT SIDE: PDF VIEWER === --}}
                    <div class="col-lg-6 border-end">
                        <div class="p-3">
                            <h6 class="mb-3"><i class="bi bi-file-pdf text-danger"></i> Proposal Document</h6>

                            @if($proposal->file_path)
                                <div style="height: 600px; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; background: #f5f5f5;">
                                    {{-- PDF Controls --}}
                                    <div id="proposal-pdf-controls" class="d-flex justify-content-between align-items-center p-2 bg-light border-bottom">
                                        <div>
                                            <button id="proposalPrevPage" class="btn btn-sm btn-outline-secondary" title="Previous Page">
                                                <i class="bi bi-chevron-left"></i>
                                            </button>
                                            <span class="mx-2">
                                                <input type="number" id="proposalPageNum" value="1" style="width: 50px; text-align: center;" class="form-control form-control-sm d-inline-block">
                                                <span id="proposalPageCount"> / -</span>
                                            </span>
                                            <button id="proposalNextPage" class="btn btn-sm btn-outline-secondary" title="Next Page">
                                                <i class="bi bi-chevron-right"></i>
                                            </button>
                                        </div>
                                        <div>
                                            <a href="{{ route('admin.proposals.download', $proposal->id) }}" class="btn btn-sm btn-primary" download>
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        </div>
                                    </div>
                                    {{-- PDF Render Area --}}
                                    <div id="proposal-pdf-viewer" style="text-align: center; padding: 10px; height: calc(100% - 50px); overflow-y: auto;">
                                        <p class="text-muted mt-5"><i class="bi bi-hourglass-split"></i> Loading PDF...</p>
                                    </div>
                                </div>
                            @else
                                <div class="alert alert-warning text-center p-5">
                                    <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                                    <p class="mt-2 mb-0">No PDF file attached to this proposal.</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- === RIGHT SIDE: PROPOSAL DETAILS === --}}
                    <div class="col-lg-6">
                        <div class="p-3" style="max-height: 600px; overflow-y: auto;">

                            {{-- Submission Information --}}
                            <div class="card mb-3">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-file-earmark-arrow-up text-primary"></i> Submission Details
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <small class="text-muted d-block">Submitted Date</small>
                                            <span class="fw-semibold">{{ $proposal->date_submitted ? $proposal->date_submitted->format('M d, Y H:i') : ($proposal->created_at ? $proposal->created_at->format('M d, Y H:i') : '-') }}</span>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <small class="text-muted d-block">Submitted By</small>
                                            <span class="fw-semibold">{{ $proposal->submitter?->full_name ?? 'System' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Project Information --}}
                            <div class="card mb-3">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-folder text-primary"></i> Project Information
                                    </h6>
                                    <div class="mb-2">
                                        <small class="text-muted d-block">Project Title</small>
                                        <span class="fw-semibold">{{ $proposal->project_title }}</span>
                                    </div>
                                    @if($proposal->proposed_project_name)
                                        <div class="mb-2">
                                            <small class="text-muted d-block">Proposed Project Name</small>
                                            <span class="fw-semibold">{{ $proposal->proposed_project_name }}</span>
                                        </div>
                                    @endif
                                    @if($proposal->project_description)
                                        <div class="mb-0">
                                            <small class="text-muted d-block">Description</small>
                                            <span>{{ $proposal->project_description }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Category & Location --}}
                            <div class="card mb-3">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-pin-map text-primary"></i> Project Details
                                    </h6>
                                    <div class="row">
                                        @if($proposal->category)
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Category</small>
                                                <span class="badge bg-light text-dark border">{{ $proposal->category_label }}</span>
                                            </div>
                                        @endif
                                        @if($proposal->location_site)
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Location / Site</small>
                                                <span class="fw-semibold">{{ $proposal->location_site }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Contractor Information --}}
                            <div class="card mb-3">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-building text-primary"></i> Contractor Information
                                    </h6>
                                    @if($proposal->contractor)
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Company Name</small>
                                                <span class="fw-semibold">{{ $proposal->contractor->company_name }}</span>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Contact Person</small>
                                                <span class="fw-semibold">{{ $proposal->contractor->contact_person ?? '-' }}</span>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Email</small>
                                                @if($proposal->contractor->email)
                                                    <a href="mailto:{{ $proposal->contractor->email }}" class="text-decoration-none">{{ $proposal->contractor->email }}</a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <small class="text-muted d-block">Phone</small>
                                                @if($proposal->contractor->phone)
                                                    <a href="tel:{{ $proposal->contractor->phone }}" class="text-decoration-none">{{ $proposal->contractor->phone }}</a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <p class="text-muted mb-0">No contractor assigned to this proposal.</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Timeline --}}
                            <div class="card mb-3">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-calendar-event text-primary"></i> Proposed Timeline
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-4 mb-2">
                                            <small class="text-muted d-block">Start Date</small>
                                            <span class="fw-semibold">{{ $proposal->proposed_start_date ? $proposal->proposed_start_date->format('M d, Y') : '-' }}</span>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <small class="text-muted d-block">Duration</small>
                                            <span class="fw-semibold">{{ $proposal->proposed_duration_days ? $proposal->proposed_duration_days . ' days' : '-' }}</span>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <small class="text-muted d-block">End Date</small>
                                            <span class="fw-semibold">{{ $proposal->proposed_end_date ? $proposal->proposed_end_date->format('M d, Y') : '-' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Budget --}}
                            <div class="card mb-3">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-credit-card text-primary"></i> Budget
                                    </h6>
                                    <div class="mb-0">
                                        <small class="text-muted d-block">Estimated Budget</small>
                                        <span class="fw-bold fs-5 text-success">
                                            {{ $proposal->estimated_budget ? '₱ ' . number_format($proposal->estimated_budget, 2) : 'Not specified' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Approval & Evaluation --}}
                            <div class="card mb-0">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-3 pb-2 border-bottom">
                                        <i class="bi bi-shield-check text-success"></i> Approval Information
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <small class="text-muted d-block">Approved By</small>
                                            <span class="fw-semibold">{{ $proposal->approver?->full_name ?? 'System' }}</span>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <small class="text-muted d-block">Approval Date</small>
                                            <span class="fw-semibold">{{ $proposal->approval_date ? $proposal->approval_date->format('M d, Y H:i') : '-' }}</span>
                                        </div>
                                    </div>
                                    @if($proposal->evaluation_remarks)
                                        <div class="mt-2">
                                            <small class="text-muted d-block">Evaluation Remarks</small>
                                            <span>{{ $proposal->evaluation_remarks }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                @if($proposal->file_path)
                    <a href="{{ route('admin.proposals.download', $proposal->id) }}" class="btn btn-outline-primary" download>
                        <i class="bi bi-download"></i> Download PDF
                    </a>
                @endif
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- === PDF.JS RENDERING SCRIPT FOR PROPOSAL MODAL === --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize PDF.js only when modal is shown (lazy loading)
    const proposalModal = document.getElementById('proposalDetailModal');
    let proposalPdfLoaded = false;

    if (proposalModal) {
        proposalModal.addEventListener('shown.bs.modal', function() {
            if (proposalPdfLoaded) return;
            proposalPdfLoaded = true;
            loadProposalPdf();
        });
    }

    function loadProposalPdf() {
        @if($proposal->file_path)
            // Set up PDF.js worker
            if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            }

            const pdfUrl = '{{ asset("storage/" . $proposal->file_path) }}';
            let pdfDoc = null;
            let currentPage = 1;
            let rendering = false;
            let pendingPage = null;
            const scale = 1.4;
            const viewer = document.getElementById('proposal-pdf-viewer');

            function renderPage(num) {
                rendering = true;
                pdfDoc.getPage(num).then(function(page) {
                    const viewport = page.getViewport({ scale: scale });
                    
                    // Create or reuse canvas
                    let canvas = viewer.querySelector('canvas');
                    if (!canvas) {
                        viewer.innerHTML = '';
                        canvas = document.createElement('canvas');
                        viewer.appendChild(canvas);
                    }

                    const ctx = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    canvas.style.maxWidth = '100%';
                    canvas.style.height = 'auto';

                    page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function() {
                        rendering = false;
                        if (pendingPage !== null) {
                            renderPage(pendingPage);
                            pendingPage = null;
                        }
                    });
                });
                document.getElementById('proposalPageNum').value = num;
            }

            function queueRender(num) {
                if (rendering) { pendingPage = num; } else { renderPage(num); }
            }

            document.getElementById('proposalPrevPage').addEventListener('click', function() {
                if (currentPage <= 1) return;
                currentPage--;
                queueRender(currentPage);
            });

            document.getElementById('proposalNextPage').addEventListener('click', function() {
                if (!pdfDoc || currentPage >= pdfDoc.numPages) return;
                currentPage++;
                queueRender(currentPage);
            });

            document.getElementById('proposalPageNum').addEventListener('change', function() {
                const num = parseInt(this.value);
                if (pdfDoc && num >= 1 && num <= pdfDoc.numPages) {
                    currentPage = num;
                    queueRender(currentPage);
                }
            });

            pdfjsLib.getDocument(pdfUrl).promise.then(function(pdf) {
                pdfDoc = pdf;
                document.getElementById('proposalPageCount').textContent = ' / ' + pdf.numPages;
                renderPage(currentPage);
            }).catch(function(error) {
                console.error('Error loading proposal PDF:', error);
                viewer.innerHTML = '<p class="text-danger mt-3">Error loading PDF file. The file may have been moved or deleted.</p>';
            });
        @endif
    }
});
</script>
