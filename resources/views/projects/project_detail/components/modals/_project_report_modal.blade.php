{{--
    Project Report modal — pick the reporting month, then preview (PDF in the
    shared document viewer) or download as Word (.docx, editable) or PDF.

    Included by: projects/project_detail/components/header.blade.php
    Behaviour:   public/js/admin/projects/project-report-modal.js
    Periods:     months with an inspection record, newest first; the latest is
                 preselected (textfiles/project_report_overhaul_plan.md R-D2).
    Expects:     $project, $reportRole (admin | staff | contractor)
--}}
@php
    $reportPeriods = app(\App\Services\Reports\ProjectReportDataService::class)->availablePeriods($project);
@endphp
<div class="modal fade" id="projectReportModal" tabindex="-1" aria-labelledby="projectReportModalLabel" aria-hidden="true"
     data-code="{{ \Illuminate\Support\Str::slug($project->code ?? $project->id) }}"
     data-view-url="{{ route($reportRole . '.projects.exports.summary.view', $project->id) }}"
     data-pdf-url="{{ route($reportRole . '.projects.exports.summary', $project->id) }}"
     data-docx-url="{{ route($reportRole . '.projects.exports.report.docx', $project->id) }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="projectReportModalLabel">
                    <i class="bi bi-file-earmark-text"></i> Project Monitoring Report
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="projectReportPeriod" class="form-label fw-semibold">Reporting period</label>
                <select id="projectReportPeriod" class="form-select">
                    @foreach($reportPeriods as $i => $p)
                        <option value="{{ $p['value'] }}" @selected($i === 0)>
                            {{ $p['label'] }}{{ $i === 0 ? ' (latest)' : '' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">
                    The report shows the project as of the end of the chosen month.
                    Only months with an inspection record are listed.
                </div>

                <div class="small text-muted mt-3">
                    <div><i class="bi bi-file-earmark-word text-primary"></i> <strong>Word</strong> — editable; adjust the wording before printing or sending.</div>
                    <div><i class="bi bi-file-earmark-pdf text-danger"></i> <strong>PDF</strong> — ready to print or share as is.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-report-action="preview">
                    <i class="bi bi-eye"></i> Preview
                </button>
                <button type="button" class="btn btn-outline-danger" data-report-action="pdf">
                    <i class="bi bi-file-earmark-pdf"></i> Download PDF
                </button>
                <button type="button" class="btn btn-primary" data-report-action="docx">
                    <i class="bi bi-file-earmark-word"></i> Download Word
                </button>
            </div>
        </div>
    </div>
</div>
<script src="{{ asset('js/admin/projects/project-report-modal.js') . '?v=' . filemtime(public_path('js/admin/projects/project-report-modal.js')) }}" defer></script>
