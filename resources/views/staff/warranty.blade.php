@extends('layouts.stafflayouts')

@section('content')

<style>
* { box-sizing: border-box; }

.wm-wrap {
    display: flex;
    gap: 14px;
    height: calc(100vh - 120px);
    min-height: 500px;
}

/* ── LEFT PANEL ── */
.wm-left {
    width: 300px;
    flex-shrink: 0;
    background: var(--color-background-primary, #fff);
    border: 0.5px solid var(--color-border-secondary, rgba(0,0,0,0.15));
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── RIGHT PANEL ── */
.wm-right {
    flex: 1;
    background: var(--color-background-primary, #fff);
    border: 0.5px solid var(--color-border-secondary, rgba(0,0,0,0.15));
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── PANEL HEADER ── */
.panel-head {
    padding: 14px 16px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    background: var(--color-background-secondary, #f9fafb);
}

.panel-label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 2px;
}

.panel-title {
    font-size: 14px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

.btn-add {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    padding: 5px 11px;
    border-radius: 8px;
    background: #2563eb;
    color: #fff;
    border: none;
    cursor: pointer;
    font-weight: 500;
    transition: background 0.15s;
    text-decoration: none;
}
.btn-add:hover { background: #1d4ed8; color: #fff; }

/* ── SEARCH ── */
.wm-search {
    padding: 10px 12px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    flex-shrink: 0;
}

.wm-search input {
    width: 100%;
    padding: 7px 10px 7px 30px;
    font-size: 13px;
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 8px;
    background: var(--color-background-secondary, #f9fafb);
    color: var(--color-text-primary, #111);
    outline: none;
    background-image: url("data:image/svg+xml,%3Csvg width='14' height='14' viewBox='0 0 14 14' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='6' cy='6' r='4.5' stroke='%239ca3af' stroke-width='1.2'/%3E%3Cpath d='M9.5 9.5L12 12' stroke='%239ca3af' stroke-width='1.2' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 8px center;
    transition: border-color 0.15s;
}
.wm-search input:focus { border-color: #2563eb; }

/* ── LIST ── */
.wm-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

.list-section-label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    padding: 8px 8px 4px;
}

.wm-divider {
    height: 0.5px;
    background: var(--color-border-tertiary, #e5e7eb);
    margin: 4px 0;
}

/* ── WARRANTY ITEM ── */
.wm-item {
    padding: 11px 12px;
    border-radius: 10px;
    border: 0.5px solid transparent;
    cursor: pointer;
    margin-bottom: 3px;
    transition: background 0.12s, border-color 0.12s;
}
.wm-item:hover {
    background: var(--color-background-secondary, #f9fafb);
    border-color: var(--color-border-tertiary, #e5e7eb);
}
.wm-item.active {
    border-color: #93c5fd;
    background: #eff6ff;
}

.item-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 5px;
}

.item-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
    line-height: 1.3;
}

.item-project {
    font-size: 11px;
    color: var(--color-text-secondary, #6b7280);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.item-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

.item-date {
    font-size: 11px;
    color: var(--color-text-tertiary, #9ca3af);
}

/* ── BADGES ── */
.badge {
    font-size: 10px;
    font-weight: 500;
    padding: 2px 7px;
    border-radius: 5px;
    letter-spacing: 0.02em;
    white-space: nowrap;
}
.badge-active  { background: #dcfce7; color: #166534; }
.badge-expired { background: #fee2e2; color: #991b1b; }
.badge-pending { background: #fef3c7; color: #92400e; }

/* ── EMPTY STATE ── */
.wm-empty {
    text-align: center;
    padding: 48px 16px;
}
.wm-empty-icon {
    width: 48px; height: 48px;
    background: var(--color-background-secondary, #f9fafb);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 12px;
    font-size: 22px;
}
.wm-empty h6 {
    font-size: 14px; font-weight: 500;
    color: var(--color-text-primary, #111);
    margin: 0 0 4px;
}
.wm-empty p {
    font-size: 12px;
    color: var(--color-text-tertiary, #9ca3af);
    margin: 0;
}

/* ── RIGHT HEAD ── */
.wm-right-head {
    padding: 14px 20px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    flex-shrink: 0;
    background: var(--color-background-secondary, #f9fafb);
}

.right-label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 4px;
}

.right-title {
    font-size: 15px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
    margin-bottom: 8px;
}

.wm-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.wm-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    color: var(--color-text-secondary, #6b7280);
    background: var(--color-background-primary, #fff);
    padding: 4px 10px;
    border-radius: 6px;
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
}

/* ── RIGHT BODY ── */
.wm-right-body {
    flex: 1;
    overflow: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── DETAIL GRID ── */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.detail-card {
    background: var(--color-background-secondary, #f9fafb);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 10px;
    padding: 12px;
}

.detail-card-label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 3px;
}

.detail-card-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

/* ── PLACEHOLDER ── */
.wm-placeholder {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    text-align: center;
}

.wm-placeholder-icon {
    width: 56px; height: 56px;
    background: var(--color-background-secondary, #f9fafb);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
}

.wm-placeholder h6 {
    font-size: 14px; font-weight: 500;
    color: var(--color-text-secondary, #6b7280);
    margin: 0;
}

.wm-placeholder p {
    font-size: 12px; margin: 0;
    color: var(--color-text-tertiary, #9ca3af);
}

/* ── PREVIEW ── */
.wm-file-preview {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    gap: 8px;
    background: var(--color-background-secondary, #f9fafb);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 10px;
    padding: 24px;
    flex: 1;
}

.wm-file-icon {
    width: 44px; height: 44px;
    background: #fee2e2;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}

.wm-file-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

.wm-file-meta {
    font-size: 12px;
    color: var(--color-text-tertiary, #9ca3af);
}

#previewContainer img {
    max-width: 100%;
    border-radius: 10px;
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
}
#previewContainer iframe {
    width: 100%;
    height: 100%;
    min-height: 480px;
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 10px;
}

/* ── OPEN BUTTON ── */
.btn-open {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    padding: 8px 16px;
    border-radius: 8px;
    background: #2563eb;
    color: #fff;
    border: none;
    text-decoration: none;
    font-weight: 500;
    transition: background 0.15s;
    align-self: flex-start;
}
.btn-open:hover { background: #1d4ed8; color: #fff; }

/* ── INSPECTION SCHEDULE ── */
.inspection-schedule {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 8px;
}

.inspection-card {
    background: var(--color-background-secondary, #f9fafb);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 10px;
    padding: 12px 14px;
}

.inspection-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 6px;
}

.inspection-title {
    display: flex;
    align-items: center;
    gap: 8px;
}

.inspection-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.inspection-icon.mid-year { background: #dbeafe; color: #2563eb; }
.inspection-icon.final { background: #fef3c7; color: #d97706; }

.inspection-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-primary, #111);
}

.inspection-date {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

.inspection-badge {
    font-size: 10px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 4px;
    letter-spacing: 0.02em;
    white-space: nowrap;
}
.badge-upcoming { background: #dbeafe; color: #1e40af; }
.badge-overdue { background: #fee2e2; color: #991b1b; }
.badge-done { background: #dcfce7; color: #166534; }
.badge-not-scheduled { background: #f3f4f6; color: #6b7280; }

.inspection-desc {
    font-size: 11px;
    color: var(--color-text-tertiary, #9ca3af);
    margin: 0;
}

/* ── REMARKS ── */
.remarks-section {
    margin-top: 12px;
}

.remarks-label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 6px;
    display: block;
}

.remarks-content {
    background: var(--color-background-secondary, #f9fafb);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 10px;
    padding: 12px;
    min-height: 60px;
    white-space: pre-wrap;
    font-size: 13px;
    color: var(--color-text-primary, #111);
    line-height: 1.5;
}

.remarks-empty {
    color: var(--color-text-tertiary, #9ca3af);
    font-style: italic;
}

/* ── LEFT PANEL ENHANCEMENTS ── */
.item-contractor {
    font-size: 10px;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.item-next-inspection {
    font-size: 10px;
    color: var(--color-text-secondary, #6b7280);
    display: flex;
    align-items: center;
    gap: 4px;
}

.item-next-inspection.upcoming { color: #2563eb; }
.item-next-inspection.overdue { color: #dc2626; }

/* ── DETAIL GRID ENHANCEMENTS ── */
.detail-grid-enhanced {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 8px;
}

/* ── FORM ENHANCEMENTS ── */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.form-row-single {
    grid-column: 1 / -1;
}

@media (max-width: 640px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    .detail-grid-enhanced {
        grid-template-columns: 1fr;
    }
}

/* ── MODAL ── */
.modal-content {
    border: 0.5px solid var(--color-border-secondary, rgba(0,0,0,0.15));
    border-radius: 14px;
    overflow: hidden;
    box-shadow: none;
}
.modal-header {
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    padding: 14px 20px;
    background: var(--color-background-secondary, #f9fafb);
}
.modal-header-label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 2px;
}
.modal-header h5 {
    font-size: 15px; font-weight: 500; margin: 0;
    color: var(--color-text-primary, #111);
}
.modal-footer {
    border-top: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    padding: 12px 20px;
    background: var(--color-background-secondary, #f9fafb);
}
.modal-body { padding: 20px; }

.form-label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 4px;
    display: block;
}

.modal-body .form-control {
    font-size: 13px;
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 8px;
    padding: 8px 10px;
    background: var(--color-background-primary, #fff);
    color: var(--color-text-primary, #111);
}
.modal-body .form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
}

.btn-save {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 13px; padding: 8px 18px;
    border-radius: 8px; background: #2563eb; color: #fff;
    border: none; cursor: pointer; font-weight: 500;
    transition: background 0.15s;
}
.btn-save:hover { background: #1d4ed8; }
</style>

<div class="wm-wrap">

    {{-- ── LEFT PANEL ── --}}
    <div class="wm-left">

        <div class="panel-head">
            <div>
                <div class="panel-label">Documents</div>
                <div class="panel-title">Warranties</div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a href="{{ route('staff.warranty.preview') }}" target="_blank" class="btn-add" style="background:#6b7280;">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><rect x="1" y="1" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.2"/><path d="M4 6h4M6 4v4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    Preview
                </a>
                <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Add new
                </button>
            </div>
        </div>

        <div class="wm-search">
            <input type="text" id="searchInput" placeholder="Search by title or project…" oninput="filterWarranties(this.value)">
        </div>

        <div class="wm-list" id="warrantyList">

            @php
                $grouped = $warranties->groupBy('status');
                $order   = ['Active', 'Pending', 'Expired'];
            @endphp

            @forelse($order as $statusGroup)
                @if($grouped->has($statusGroup))
                    <div class="list-section-label">
                        {{ $statusGroup }} · {{ $grouped[$statusGroup]->count() }}
                    </div>

                    @foreach($grouped[$statusGroup] as $w)
                        @php
                            $contractorName = $w->contractor ? $w->contractor->company_name : 'N/A';
                            // Existing project data: budget from the effective contract price, location defaults to CSPC
                            $projectBudget = $w->project->effectiveContractPrice() !== null ? '₱ ' . number_format((float) $w->project->effectiveContractPrice(), 2) : 'N/A';
                            $projectLocation = $w->project->location ?: 'Camarines Sur Polytechnic Colleges';
                            $nextInspection = null;
                            $nextInspectionLabel = '';
                            $nextInspectionClass = '';
                            $today = \Carbon\Carbon::today();
                            if ($w->mid_year_inspection && \Carbon\Carbon::parse($w->mid_year_inspection)->gte($today)) {
                                $nextInspection = \Carbon\Carbon::parse($w->mid_year_inspection);
                                $nextInspectionLabel = 'Mid-Year: ' . $nextInspection->format('M d, Y');
                                $nextInspectionClass = 'upcoming';
                            } elseif ($w->final_inspection && \Carbon\Carbon::parse($w->final_inspection)->gte($today)) {
                                $nextInspection = \Carbon\Carbon::parse($w->final_inspection);
                                $nextInspectionLabel = 'Final: ' . $nextInspection->format('M d, Y');
                                $nextInspectionClass = 'upcoming';
                            } elseif ($w->mid_year_inspection && \Carbon\Carbon::parse($w->mid_year_inspection)->lt($today)) {
                                $nextInspection = \Carbon\Carbon::parse($w->mid_year_inspection);
                                $nextInspectionLabel = 'Mid-Year overdue: ' . $nextInspection->format('M d, Y');
                                $nextInspectionClass = 'overdue';
                            } elseif ($w->final_inspection && \Carbon\Carbon::parse($w->final_inspection)->lt($today)) {
                                $nextInspection = \Carbon\Carbon::parse($w->final_inspection);
                                $nextInspectionLabel = 'Final overdue: ' . $nextInspection->format('M d, Y');
                                $nextInspectionClass = 'overdue';
                            } else {
                                $nextInspectionLabel = 'Not scheduled';
                                $nextInspectionClass = '';
                            }
                        @endphp
                        <div class="wm-item"
                            data-id="{{ $w->id }}"
                            data-title="{{ strtolower($w->title ?? '') }}"
                            data-project="{{ strtolower($w->project->title) }}"
                            data-contractor="{{ strtolower($contractorName) }}"
                            data-budget="{{ strtolower($projectBudget) }}"
                            data-location="{{ strtolower($projectLocation) }}"
                            data-completion-date="{{ $w->completion_date ?? '' }}"
                            data-start-date="{{ $w->start_date }}"
                            data-end-date="{{ $w->end_date }}"
                            data-mid-year="{{ $w->mid_year_inspection ?? '' }}"
                            data-final-inspection="{{ $w->final_inspection ?? '' }}"
                            data-status="{{ $w->status }}"
                            data-remarks="{{ addslashes($w->remarks ?? '') }}"
                            onclick="viewWarranty(
                                this,
                                '{{ asset('storage/'.$w->file_path) }}',
                                '{{ addslashes($w->title ?? 'Warranty') }}',
                                '{{ addslashes($w->project->title) }}',
                                '{{ addslashes($contractorName) }}',
                                '{{ addslashes($projectBudget) }}',
                                '{{ addslashes($projectLocation) }}',
                                '{{ $w->completion_date ?? '' }}',
                                '{{ $w->start_date }}',
                                '{{ $w->end_date }}',
                                '{{ $w->mid_year_inspection ?? '' }}',
                                '{{ $w->final_inspection ?? '' }}',
                                '{{ $w->status }}',
                                '{{ addslashes($w->remarks ?? '') }}',
                                '{{ $w->project->id }}'
                            )">

                            <div class="item-row">
                                <div class="item-name">{{ $w->title ?? 'Warranty' }}</div>
                                <span class="badge
                                    {{ $w->status == 'Active'  ? 'badge-active'  : '' }}
                                    {{ $w->status == 'Expired' ? 'badge-expired' : '' }}
                                    {{ $w->status == 'Pending' ? 'badge-pending' : '' }}">
                                    {{ $w->status }}
                                </span>
                            </div>

                            <div class="item-project">
                                <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><rect x="1" y="2" width="10" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 1v2M8 1v2M1 5h10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                <a href="{{ route('staff.projects.detail', $w->project->id) }}" style="color:inherit; text-decoration:none;" onclick="event.stopPropagation();">{{ $w->project->title }}</a>
                            </div>

                            @if($contractorName !== 'N/A')
                            <div class="item-contractor">
                                <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M6 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm0 1a2 2 0 1 1 0 4 2 2 0 0 1 0-4ZM9 11a5 5 0 0 0-5 5v1h10v-1a5 5 0 0 0-5-5Z" stroke="currentColor" stroke-width="1.2"/></svg>
                                {{ $contractorName }}
                            </div>
                            @endif

                            <div class="item-footer">
                                <span class="item-date">
                                    {{ $w->status == 'Expired' ? 'Ended' : 'Ends' }}
                                    {{ \Carbon\Carbon::parse($w->end_date)->format('M d, Y') }}
                                </span>
                            </div>

                            @if($nextInspectionLabel)
                            <div class="item-next-inspection {{ $nextInspectionClass }}">
                                <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.2"/><path d="M6 3.5V6l2 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                Next: {{ $nextInspectionLabel }}
                            </div>
                            @endif

                        </div>
                    @endforeach

                    @if(!$loop->last)
                        <div class="wm-divider"></div>
                    @endif
                @endif
            @empty
                <div class="wm-empty">
                    <div class="wm-empty-icon">📄</div>
                    <h6>No warranties yet</h6>
                    <p>Add your first warranty record to get started</p>
                </div>
            @endforelse

        </div>
    </div>

    {{-- ── RIGHT PANEL ── --}}
    <div class="wm-right">

        {{-- Head: hidden until a warranty is selected --}}
        <div class="wm-right-head" id="rightHead" style="display:none;">
            <div class="right-label">Warranty details</div>
            <div class="right-title" id="rightTitle"></div>
            <div class="wm-meta">
                <span class="wm-meta-pill">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><rect x="1" y="2" width="10" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 1v2M8 1v2M1 5h10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    <span id="metaProject"></span>
                </span>
                <span class="wm-meta-pill">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.2"/><path d="M6 3.5V6l2 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    <span id="dateRange"></span>
                </span>
                <span id="statusBadge" class="badge"></span>
            </div>
            <a id="btnProject" class="btn-open" style="margin-top:8px; background:#6b7280;" href="#" target="_blank">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><rect x="1" y="2" width="10" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 1v2M8 1v2M1 5h10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                Go to Project
            </a>
        </div>

        <div class="wm-right-body" id="rightBody">

            {{-- Placeholder --}}
            <div class="wm-placeholder" id="placeholder">
                <div class="wm-placeholder-icon">📋</div>
                <h6>No warranty selected</h6>
                <p>Choose a warranty from the list to view its details and document</p>
            </div>

            {{-- Detail cards --}}
            <div class="detail-grid-enhanced" id="detailGrid" style="display:none;">
                <div class="detail-card">
                    <div class="detail-card-label">Project</div>
                    <div class="detail-card-value" id="dcProject"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Contractor</div>
                    <div class="detail-card-value" id="dcContractor"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Project Budget</div>
                    <div class="detail-card-value" id="dcBudget"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Location</div>
                    <div class="detail-card-value" id="dcLocation"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Date of Completion</div>
                    <div class="detail-card-value" id="dcCompletion"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Warranty Period</div>
                    <div class="detail-card-value" id="dcWarrantyPeriod"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Coverage start</div>
                    <div class="detail-card-value" id="dcStart"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Coverage end</div>
                    <div class="detail-card-value" id="dcEnd"></div>
                </div>
                <div class="detail-card">
                    <div class="detail-card-label">Current status</div>
                    <div class="detail-card-value" id="dcStatus"></div>
                </div>
            </div>

            {{-- Inspection Schedule --}}
            <div id="inspectionSchedule" style="display:none;">
                <div class="inspection-schedule">
                    <div class="inspection-card" id="midYearCard">
                        <div class="inspection-header">
                            <div class="inspection-title">
                                <div class="inspection-icon mid-year">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6v6l4 2"/>
                                    </svg>
                                </div>
                                <span class="inspection-label">Mid-Year Inspection</span>
                            </div>
                            <span class="inspection-badge" id="midYearBadge">—</span>
                        </div>
                        <div class="inspection-date" id="midYearDate">—</div>
                        <p class="inspection-desc" id="midYearDesc">6 months after completion</p>
                    </div>
                    <div class="inspection-card" id="finalCard">
                        <div class="inspection-header">
                            <div class="inspection-title">
                                <div class="inspection-icon final">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6v6l4 2"/>
                                        <path d="M9 11l3 3L22 4"/>
                                    </svg>
                                </div>
                                <span class="inspection-label">Final Inspection</span>
                            </div>
                            <span class="inspection-badge" id="finalBadge">—</span>
                        </div>
                        <div class="inspection-date" id="finalDate">—</div>
                        <p class="inspection-desc" id="finalDesc">11 months after completion</p>
                    </div>
                </div>
            </div>

            {{-- Remarks --}}
            <div id="remarksSection" style="display:none;" class="remarks-section">
                <span class="remarks-label">Remarks</span>
                <div class="remarks-content" id="remarksContent"></div>
            </div>

            {{-- File preview --}}
            <div id="previewContainer" style="flex:1; display:none;"></div>

            <a id="openFile" href="#" target="_blank" class="btn-open" style="display:none;">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M5 2H2a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V7M7 1h4m0 0v4m0-4L5 7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Open file
            </a>
            <form id="deleteForm" method="POST" style="display:none; margin-top:8px;">
                @csrf
                @method('DELETE')

                <button type="submit" class="btn-open" style="background:#dc2626;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M2 3h8M5 5v4M7 5v4M3 3l1-1h4l1 1M4 3v6a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V3"
                            stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    Delete
                </button>
            </form>
        </div>
    </div>

</div>

{{-- ── ADD MODAL ── --}}
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('staff.warranty.store') }}" enctype="multipart/form-data" class="modal-content">
            @csrf

            <div class="modal-header">
                <div>
                    <div class="modal-header-label">New record</div>
                    <h5>Add warranty</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="mb-3">
                    <label class="form-label">Project</label>
                    <select name="project_id" id="modalProjectId" class="form-control" required>
                        @foreach($projects as $p)
                            <option value="{{ $p->id }}" data-contractor="{{ $p->contractor ? $p->contractor->id : '' }}">{{ $p->title }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Contractor</label>
                    <select name="contractor_id" id="modalContractorId" class="form-control">
                        <option value="">— Select contractor —</option>
                        @foreach($contractors as $c)
                            <option value="{{ $c->id }}">{{ $c->company_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Warranty title</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Structural Warranty">
                </div>

                <div class="mb-3">
                    <label class="form-label">Date of Completion</label>
                    <input type="date" name="completion_date" id="modalCompletionDate" class="form-control">
                </div>

                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" required>
                        <option value="Active">Active</option>
                        <option value="Expired">Expired</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;" class="mb-3">
                    <div>
                        <label class="form-label">Warranty Start</label>
                        <input type="date" name="start_date" id="modalStartDate" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Warranty End</label>
                        <input type="date" name="end_date" id="modalEndDate" class="form-control" required>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;" class="mb-3">
                    <div>
                        <label class="form-label">Mid-Year Inspection</label>
                        <input type="date" name="mid_year_inspection" id="modalMidYearInspection" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Final Inspection</label>
                        <input type="date" name="final_inspection" id="modalFinalInspection" class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" id="modalRemarks" class="form-control" rows="3" placeholder="e.g. Mid-year Inspection: Inspected as of April 7, 2026"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Upload file</label>
                    <input type="file" name="file" class="form-control" required>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-save">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6.5l3 3 5-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Save warranty
                </button>
            </div>

</form>
    </div>
</div>

<script>
// Track auto-calculated fields to avoid overwriting user edits
const autoCalculatedFields = new Set();

function addMonths(date, months) {
    const result = new Date(date);
    result.setMonth(result.getMonth() + months);
    // Handle month overflow (e.g., Jan 31 + 1 month = Feb 28/29)
    if (result.getDate() !== date.getDate() && date.getDate() === new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()) {
        result.setDate(0); // Last day of previous month
    }
    return result;
}

function formatDateInput(date) {
    if (!date) return '';
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function autoCalculateDates() {
    const completionDate = document.getElementById('modalCompletionDate').value;
    if (!completionDate) return;

    const completion = new Date(completionDate + 'T00:00:00');

    // Auto-calculate Warranty End (completion + 1 year) if empty
    const endDateInput = document.getElementById('modalEndDate');
    if (!endDateInput.value || autoCalculatedFields.has('modalEndDate')) {
        const warrantyEnd = addMonths(completion, 12);
        endDateInput.value = formatDateInput(warrantyEnd);
        autoCalculatedFields.add('modalEndDate');
    }

    // Auto-calculate Mid-Year Inspection (completion + 6 months) if empty
    const midYearInput = document.getElementById('modalMidYearInspection');
    if (!midYearInput.value || autoCalculatedFields.has('modalMidYearInspection')) {
        const midYear = addMonths(completion, 6);
        midYearInput.value = formatDateInput(midYear);
        autoCalculatedFields.add('modalMidYearInspection');
    }

    // Auto-calculate Final Inspection (completion + 11 months) if empty
    const finalInput = document.getElementById('modalFinalInspection');
    if (!finalInput.value || autoCalculatedFields.has('modalFinalInspection')) {
        const final = addMonths(completion, 11);
        finalInput.value = formatDateInput(final);
        autoCalculatedFields.add('modalFinalInspection');
    }

    // Auto-calculate Warranty Start (same as completion) if empty
    const startDateInput = document.getElementById('modalStartDate');
    if (!startDateInput.value || autoCalculatedFields.has('modalStartDate')) {
        startDateInput.value = formatDateInput(completion);
        autoCalculatedFields.add('modalStartDate');
    }
}

function clearAutoCalcFlags(fieldId) {
    autoCalculatedFields.delete(fieldId);
}

// Initialize event listeners when modal opens
document.getElementById('addModal')?.addEventListener('shown.bs.modal', function () {
    autoCalculatedFields.clear();

    const completionInput = document.getElementById('modalCompletionDate');
    const endDateInput = document.getElementById('modalEndDate');
    const midYearInput = document.getElementById('modalMidYearInspection');
    const finalInput = document.getElementById('modalFinalInspection');
    const startDateInput = document.getElementById('modalStartDate');

    // Track manual edits
    [endDateInput, midYearInput, finalInput, startDateInput].forEach(input => {
        input.addEventListener('input', function() {
            clearAutoCalcFlags(this.id);
        });
    });

    // Auto-calculate when completion date changes
    completionInput.addEventListener('change', autoCalculateDates);

    // Auto-fill contractor from the selected project. Every project change
    // overwrites the contractor, so switching projects never leaves a stale
    // contractor behind; also runs on modal open for the initial selection.
    const projectSelect = document.getElementById('modalProjectId');
    const contractorSelect = document.getElementById('modalContractorId');
    function syncContractorFromProject() {
        const selectedOption = projectSelect.options[projectSelect.selectedIndex];
        const contractorId = selectedOption ? selectedOption.dataset.contractor : '';
        // Only set values present in the dropdown; otherwise clear the stale one.
        contractorSelect.value = (contractorId && contractorSelect.querySelector(`option[value="${contractorId}"]`)) ? contractorId : '';
    }
    projectSelect.addEventListener('change', syncContractorFromProject);
    syncContractorFromProject();
});

let currentWarrantyData = {};

function viewWarranty(el, file, title, project, contractor, budget, location, completionDate, start, end, midYear, finalInspection, status, remarks, projectId) {
    document.querySelectorAll('.wm-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');

    // Store current warranty data for preview modal
    currentWarrantyData = {
        title: title,
        project: project,
        contractor: contractor,
        budget: budget,
        location: location,
        completionDate: completionDate,
        start: start,
        end: end,
        midYear: midYear,
        finalInspection: finalInspection,
        status: status,
        remarks: remarks,
        projectId: projectId
    };

    // Head
    document.getElementById('rightHead').style.display = 'block';
    document.getElementById('rightTitle').innerText = title;
    document.getElementById('metaProject').innerText = project;
    document.getElementById('dateRange').innerText = formatDate(start) + ' → ' + formatDate(end);

    // Set project link
    document.getElementById('btnProject').href = '/staff/projects/' + projectId;

    const sb = document.getElementById('statusBadge');
    sb.className = 'badge ' + (status === 'Active' ? 'badge-active' : status === 'Expired' ? 'badge-expired' : 'badge-pending');
    sb.innerText = status;

    // Detail cards
    document.getElementById('detailGrid').style.display = 'grid';
    document.getElementById('dcProject').innerText = project;
    document.getElementById('dcContractor').innerText = contractor || 'N/A';
    document.getElementById('dcBudget').innerText = budget || 'N/A';
    document.getElementById('dcLocation').innerText = location || 'Camarines Sur Polytechnic Colleges';
    document.getElementById('dcCompletion').innerText = formatDate(completionDate);
    document.getElementById('dcWarrantyPeriod').innerText = formatDate(start) + ' → ' + formatDate(end);
    document.getElementById('dcStart').innerText = formatDate(start);
    document.getElementById('dcEnd').innerText = formatDate(end);
    document.getElementById('dcStatus').innerText = status;

    // Inspection Schedule
    const midYearCard = document.getElementById('midYearCard');
    const finalCard = document.getElementById('finalCard');
    const midYearBadge = document.getElementById('midYearBadge');
    const finalBadge = document.getElementById('finalBadge');
    const midYearDate = document.getElementById('midYearDate');
    const finalDate = document.getElementById('finalDate');
    const midYearDesc = document.getElementById('midYearDesc');
    const finalDesc = document.getElementById('finalDesc');

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Mid-Year Inspection
    if (midYear) {
        midYearCard.style.display = 'block';
        const midYearDateObj = new Date(midYear + 'T00:00:00');
        midYearDateObj.setHours(0, 0, 0, 0);
        midYearDate.innerText = formatDate(midYear);
        midYearDesc.innerText = '6 months after completion';

        if (midYearDateObj < today) {
            midYearBadge.className = 'inspection-badge badge-overdue';
            midYearBadge.innerText = 'Overdue';
        } else {
            midYearBadge.className = 'inspection-badge badge-upcoming';
            midYearBadge.innerText = 'Upcoming';
        }
    } else {
        midYearCard.style.display = 'none';
    }

    // Final Inspection
    if (finalInspection) {
        finalCard.style.display = 'block';
        const finalDateObj = new Date(finalInspection + 'T00:00:00');
        finalDateObj.setHours(0, 0, 0, 0);
        finalDate.innerText = formatDate(finalInspection);
        finalDesc.innerText = '11 months after completion';

        if (finalDateObj < today) {
            finalBadge.className = 'inspection-badge badge-overdue';
            finalBadge.innerText = 'Overdue';
        } else {
            finalBadge.className = 'inspection-badge badge-upcoming';
            finalBadge.innerText = 'Upcoming';
        }
    } else {
        finalCard.style.display = 'none';
    }

    document.getElementById('inspectionSchedule').style.display = (midYear || finalInspection) ? 'block' : 'none';

    // Remarks
    const remarksSection = document.getElementById('remarksSection');
    const remarksContent = document.getElementById('remarksContent');
    if (remarks) {
        remarksSection.style.display = 'block';
        remarksContent.innerText = remarks;
        remarksContent.classList.remove('remarks-empty');
    } else {
        remarksSection.style.display = 'block';
        remarksContent.innerText = 'No remarks provided.';
        remarksContent.classList.add('remarks-empty');
    }

    // Hide placeholder
    document.getElementById('placeholder').style.display = 'none';

    // Preview
    const container = document.getElementById('previewContainer');
    container.style.display = 'block';

    // DELETE FORM HANDLING
    const deleteForm = document.getElementById('deleteForm');
    if (deleteForm) {
        deleteForm.style.display = 'block';
        const id = el.getAttribute('data-id');
        deleteForm.action = `/staff/warranty/${id}`;
        deleteForm.onsubmit = function() {
            return confirm('Are you sure you want to delete this warranty?');
        };
    }
    const ext = file.split('.').pop().toLowerCase();
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
        container.innerHTML = `<img src="${file}" alt="Warranty document">`;
    } else if (ext === 'pdf') {
        container.innerHTML = `<iframe src="${file}" title="Warranty PDF"></iframe>`;
    } else {
        const fname = file.split('/').pop();
        container.innerHTML = `
            <div class="wm-file-preview">
                <div class="wm-file-icon">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M5 2h7l4 4v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="#dc2626" stroke-width="1.4"/>
                        <path d="M12 2v4h4" stroke="#dc2626" stroke-width="1.4"/>
                        <path d="M7 11h6M7 14h4" stroke="#dc2626" stroke-width="1.4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="wm-file-name">${fname}</div>
                <div class="wm-file-meta">Preview not available for this file type</div>
            </div>`;
    }

    const btn = document.getElementById('openFile');
    btn.href = file;
    btn.style.display = 'inline-flex';
}

function formatDate(str) {
    if (!str) return '—';
    // Parse Y-m-d format safely (avoid timezone issues)
    const parts = str.split(/[-\sT:]/);
    if (parts.length >= 3) {
        const year = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10) - 1; // 0-indexed
        const day = parseInt(parts[2], 10);
        if (!isNaN(year) && !isNaN(month) && !isNaN(day)) {
            const d = new Date(year, month, day);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }
    }
    // Fallback for other formats
    const d = new Date(str);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function filterWarranties(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.wm-item').forEach(el => {
        const t = (el.dataset.title || '') + (el.dataset.project || '') + (el.dataset.contractor || '') + (el.dataset.budget || '') + (el.dataset.location || '');
        el.style.display = t.includes(q) ? '' : 'none';
    });
}

// All warranties data for preview modal
const allWarranties = {!! json_encode($warranties->map(function($w) {
    $contractorName = $w->contractor ? $w->contractor->company_name : 'N/A';
    return [
        'id' => $w->id,
        'title' => $w->title ?? 'Warranty',
        'project' => $w->project->title,
        'project_id' => $w->project->id,
        'contractor' => $contractorName,
        'budget' => $w->project->effectiveContractPrice() !== null ? '₱ ' . number_format((float) $w->project->effectiveContractPrice(), 2) : 'N/A',
        'location' => $w->project->location ?: 'Camarines Sur Polytechnic Colleges',
        'completion_date' => $w->completion_date ?? '',
        'start_date' => $w->start_date,
        'end_date' => $w->end_date,
        'mid_year_inspection' => $w->mid_year_inspection ?? '',
        'final_inspection' => $w->final_inspection ?? '',
        'status' => $w->status,
        'remarks' => $w->remarks ?? '',
    ];
})) !!};
</script>

@endsection