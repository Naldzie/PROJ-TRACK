<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Project Status Report</title>
    <style>
        body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .wrap { max-width: 600px; margin: 0 auto; padding: 20px 10px; }
        .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .header-icon { width: 44px; height: 44px; background: #2563eb; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; }
        .body { padding: 24px; }
        .greeting { font-size: 14px; color: #334155; line-height: 1.7; margin: 0 0 16px; }
        .analysis-text { font-size: 13px; color: #475569; line-height: 1.8; margin: 0 0 20px; }
        .analysis-block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px; }
        .analysis-label { font-size: 10px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; margin-bottom: 12px; }

        /* ── Highlighted report sections (one accent color per section) ── */
        .section { border-radius: 12px; padding: 0; margin-bottom: 18px; overflow: hidden; border: 1px solid; }
        .section-head { padding: 12px 16px; display: block; }
        .section-num {
            display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center;
            border-radius: 7px; font-size: 11px; font-weight: 800; color: #ffffff; margin-right: 8px;
        }
        .section-title { font-size: 12px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; }
        .section-body { padding: 14px 16px 16px; background: #ffffff; }
        .section-blue { border-color: #bfdbfe; background: #eff6ff; }
        .section-blue .section-num { background: #2563eb; }
        .section-blue .section-title { color: #1d4ed8; }
        .section-violet { border-color: #ddd6fe; background: #f5f3ff; }
        .section-violet .section-num { background: #7c3aed; }
        .section-violet .section-title { color: #6d28d9; }
        .section-amber { border-color: #fde68a; background: #fffbeb; }
        .section-amber .section-num { background: #d97706; }
        .section-amber .section-title { color: #b45309; }
        .section-green { border-color: #bbf7d0; background: #f0fdf4; }
        .section-green .section-num { background: #059669; }
        .section-green .section-title { color: #047857; }
        .info-row { font-size: 13px; color: #334155; padding: 7px 0; border-bottom: 1px dashed #e2e8f0; }
        .info-row:last-child { border-bottom: none; }
        .info-chip {
            display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: 0.04em;
            text-transform: uppercase; color: #1d4ed8; background: #dbeafe;
            border-radius: 6px; padding: 3px 8px; margin-right: 8px; min-width: 62px; text-align: center;
        }
        .rec-item { padding: 10px 0; border-bottom: 1px dashed #fde68a; }
        .rec-item:last-child { border-bottom: none; padding-bottom: 0; }
        .rec-item:first-child { padding-top: 0; }
        .rec-pill {
            display: inline-block; font-size: 10px; font-weight: 800; color: #fff;
            border-radius: 20px; padding: 2px 9px; margin-right: 7px;
        }
        .rec-cat { font-size: 10px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #b45309; }
        .rec-msg { font-size: 13px; color: #44403c; line-height: 1.6; margin: 5px 0 0; }
        .rec-why { font-size: 12px; color: #78716c; line-height: 1.6; margin: 4px 0 0; }
        .metric-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; margin-bottom: 4px; }
        .metric-label { color: #64748b; }
        .metric-val { font-weight: 600; color: #1e293b; }
        .progress-bar { height: 6px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin-bottom: 12px; }
        .progress-fill { height: 100%; border-radius: 99px; }
        .variance-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; color: #fff; margin-top: 8px; }
        .footer-note { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 14px; font-size: 11px; color: #0369a1; line-height: 1.6; margin-top: 20px; }
        .footer-note strong { color: #075985; }
        .footer { text-align: center; padding: 20px 24px; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; }

        /* Report section styles */
        .report-section { margin-bottom: 16px; }
        .report-section-title {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #2563eb;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        .report-section-content { font-size: 13px; color: #475569; line-height: 1.7; }
        .report-section-content p { margin: 0 0 10px; }
        .report-section-content p:last-child { margin-bottom: 0; }
        .report-section-content ul { margin: 8px 0; padding-left: 20px; }
        .report-section-content li { margin: 4px 0; }
        .report-section-content ol { margin: 8px 0; padding-left: 20px; }
        .report-section-content ol li { margin: 4px 0; }

    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td background="https://drive.google.com/uc?export=view&id=1H4iWVbIjMo4syTlfl8y9kXlCRtyQFCTm"
                        style="background-size:cover; background-position:center; text-align:center; padding:40px 20px; color:#ffffff;">
                        <div class="header-icon" style="margin:0 auto 10px;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>
                            </svg>
                        </div>
                        <h1 class="header-title" style="margin:0; font-size:16px; font-weight:600; color:#fff; letter-spacing:0.03em; text-shadow:0 1px 4px rgba(0,0,0,0.4);">PROJ-TRACK SYSTEM</h1>
                        <p class="header-sub" style="margin:4px 0 0; font-size:12px; color:rgba(255,255,255,0.7); text-shadow:0 1px 3px rgba(0,0,0,0.4);">CSPC Project Monitoring Unit &mdash; AI Analysis Report</p>
                    </td>
                </tr>
            </table>

            <div class="body">
                <p class="greeting">
                    Dear <strong>{{ optional($tracking->contractor)->company_name ?? 'Contractor' }}</strong>,
                </p>

                <p class="greeting">
                    This is your automated monthly project status report for <strong>{{ $tracking->sent_at?->format('F Y') }}</strong>.
                    The PROJ-TRACK AI system has completed its scheduled analysis of your project's progress.
                </p>

                @if(!empty($tracking->is_edited))
                <div class="footer-note" style="background:#fefce8;border-color:#fde68a;color:#854d0e;margin-top:0;margin-bottom:18px;">
                    <strong>&#9998; Revised Message</strong><br>
                    This message was reviewed and edited by the PROJ-TRACK team{{ $tracking->edited_at ? ' on ' . $tracking->edited_at->format('F d, Y') : '' }}.
                    The content below replaces any earlier copy you may have received.
                </div>
                @endif

                {{-- 01 · Project snapshot (blue highlight) --}}
                <div class="section section-blue">
                    <div class="section-head"><span class="section-num">01</span><span class="section-title">Project Snapshot</span></div>
                    <div class="section-body">
                        <div class="info-row"><span class="info-chip">Project</span><strong>{{ $project->title ?? 'N/A' }}</strong></div>
                        <div class="info-row"><span class="info-chip">Location</span>{{ $project->location ?? 'N/A' }}</div>
                        <div class="info-row"><span class="info-chip">Status</span>{{ $project->status ?? 'N/A' }}</div>
                    </div>
                </div>

                @php
                    $parser = app(\App\Services\AiReportParser::class);
                    $sourceContent = !empty($tracking->body) ? $tracking->body : ($aiSolution->analysis_text ?? '');
                    $analysisHtml = $parser->parseToHtml($sourceContent);
                    $emailRecs = collect($aiSolution->recommendations ?? [])
                        ->filter(fn ($r) => !empty($r['message'] ?? null))
                        ->sortBy(fn ($r) => (int) ($r['priority'] ?? 5))
                        ->take(5)
                        ->values();
                @endphp

                {{-- 02 · AI-generated analysis (violet highlight) --}}
                <div class="section section-violet">
                    <div class="section-head"><span class="section-num">02</span><span class="section-title">AI-Generated Analysis</span></div>
                    <div class="section-body">
                        <div class="report-content">
                            {!! $analysisHtml !!}
                        </div>
                    </div>
                </div>

                {{-- 03 · Recommended actions (amber highlight, only when present) --}}
                @if($emailRecs->isNotEmpty())
                <div class="section section-amber">
                    <div class="section-head"><span class="section-num">03</span><span class="section-title">Recommended Actions</span></div>
                    <div class="section-body">
                        @foreach($emailRecs as $rec)
                        @php
                            $prio = (int) ($rec['priority'] ?? 5);
                            $prioColor = $prio <= 1 ? '#ef4444' : ($prio === 2 ? '#f97316' : '#64748b');
                        @endphp
                        <div class="rec-item">
                            <div>
                                <span class="rec-pill" style="background:{{ $prioColor }};">P{{ $prio }}</span><span class="rec-cat">{{ $rec['category'] ?? 'General' }}</span>
                            </div>
                            <p class="rec-msg">{{ $rec['message'] }}</p>
                            @if(!empty($rec['reason'] ?? null))
                            <p class="rec-why">Why: {{ $rec['reason'] }}</p>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- 04 · Progress analysis (green highlight) --}}
                <div class="section section-green">
                    <div class="section-head"><span class="section-num">{{ $emailRecs->isNotEmpty() ? '04' : '03' }}</span><span class="section-title">Project Progress Analysis</span></div>
                    <div class="section-body">
                        <div>
                            <div class="metric-row">
                                <span class="metric-label">Planned Progress</span>
                                <span class="metric-val">{{ $plannedProgress }}</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:{{ $plannedProgress }};background:#3b82f6;"></div>
                            </div>
                        </div>
                        <div>
                            <div class="metric-row">
                                <span class="metric-label">Actual Progress</span>
                                <span class="metric-val">{{ $actualProgress }}</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:{{ $actualProgress }};background:{{ $varianceColor }};"></div>
                            </div>
                        </div>
                        <div style="text-align:center;">
                            <span class="variance-badge" style="background:{{ $varianceColor }};">
                                {{ $varianceLabel }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="footer-note">
                    <strong>&#9432; Integrity Monitoring Notice</strong><br>
                    This email contains integrity monitoring technology. For audit and security purposes,
                    delivery and open events may be recorded using a secure tracking pixel. No personal
                    information beyond standard email delivery metadata is collected.
                </div>
            </div>

            <div class="footer">
                CSPC Project Monitoring Unit &bull; PROJ-TRACK System<br>
                This is an automated message. Please do not reply directly.
            </div>
        </div>
    </div>

    <img src="{{ $trackingUrl ?? '' }}" alt="" width="1" height="1" style="width:1px;height:1px;border:0;margin:0;padding:0;outline:none;">
</body>
</html>
