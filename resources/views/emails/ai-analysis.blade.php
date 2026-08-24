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
        .metric-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; margin-bottom: 4px; }
        .metric-label { color: #64748b; }
        .metric-val { font-weight: 600; color: #1e293b; }
        .progress-bar { height: 6px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin-bottom: 12px; }
        .progress-fill { height: 100%; border-radius: 99px; }
        .variance-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; color: #fff; margin-top: 8px; }
        .footer-note { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 14px; font-size: 11px; color: #0369a1; line-height: 1.6; margin-top: 20px; }
        .footer-note strong { color: #075985; }
        .footer { text-align: center; padding: 20px 24px; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; }

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
                <div class="footer-note" style="background:#fefce8;border-color:#fde68a;color:#854d0e;margin-top:0;">
                    <strong>&#9998; Revised Message</strong><br>
                    This message was reviewed and edited by the PROJ-TRACK team{{ $tracking->edited_at ? ' on ' . $tracking->edited_at->format('F d, Y') : '' }}.
                    The content below replaces any earlier copy you may have received.
                </div>
                @endif

                <p class="analysis-text">
                    <strong>Project:</strong> {{ $project->title ?? 'N/A' }}<br>
                    <strong>Location:</strong> {{ $project->location ?? 'N/A' }}<br>
                    <strong>Status:</strong> {{ $project->status ?? 'N/A' }}
                </p>

                @if(!empty($tracking->body))
                <div class="analysis-block">
                    <div class="analysis-label">Message</div>
                    <p class="analysis-text" style="margin:0; white-space:pre-line;">{{ $tracking->body }}</p>
                </div>
                @else
                <div class="analysis-block">
                    <div class="analysis-label">AI-Generated Analysis</div>
                    <p class="analysis-text" style="margin:0;">{{ $aiSolution->analysis_text ?? 'Analysis not available.' }}</p>
                </div>
                @endif

                <div class="analysis-block">
                    <div class="analysis-label">Project Progress Analysis</div>
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
