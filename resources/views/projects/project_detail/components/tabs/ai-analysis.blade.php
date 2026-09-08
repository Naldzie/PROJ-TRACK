<div id="ai-analysis" style="padding: 20px;">
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white;">
            <span class="card-title">
                <i class="bi bi-robot" style="margin-right: 8px;"></i>AI Analysis & Recommendations
            </span>
        </div>
        <div style="padding: 24px;">
            @if (!empty($analysisText))
                <div class="analysis-block" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; position: relative;" id="analysis-card">
                    <div class="analysis-label" style="font-size: 10px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                        <span>AI-Generated Analysis</span>
                        @if (!empty($aiGeneratedAt))
                            <span style="font-size: 11px; background: #eef2ff; color: #4338ca; padding: 3px 10px; border-radius: 12px; white-space: nowrap;">
                                <i class="bi bi-robot" style="margin-right: 4px;"></i>AI Generated — {{ \Carbon\Carbon::parse($aiGeneratedAt)->format('M d, Y g:i A') }}
                            </span>
                        @endif
                    </div>
                    <div class="report-content" id="analysis-text">
                        @php
                            $parser = app(\App\Services\AiReportParser::class);
                            echo $parser->parseToHtml($analysisText);
                        @endphp
                    </div>
                    @if (!empty($showRegenerate))
                        <div style="margin-top: 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <button id="regenerate-btn" onclick="regenerateAnalysis({{ $projectId ?? 'null' }})" style="font-size: 13px; padding: 6px 14px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s ease;">
                                <i class="bi bi-arrow-repeat" style="margin-right: 4px; transition: transform 0.3s ease;"></i> Regenerate Analysis
                            </button>
                            <div id="regenerate-status-panel" style="display: none; flex-direction: column; gap: 6px; min-width: 200px;">
                                <div id="regenerate-status-main" style="font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 6px;"></div>
                                <div id="regenerate-steps" style="display: none; font-size: 11px; color: #94a3b8;"></div>
                            </div>
                        </div>
                    @endif
                    
                    {{-- Loading overlay --}}
                    <div id="regenerate-overlay" style="display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(248, 250, 252, 0.95); border-radius: 10px; z-index: 10; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 20px;">
                        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div style="text-align: center;">
                            <div id="overlay-status" style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">Initializing...</div>
                            <div id="overlay-substatus" style="font-size: 12px; color: #64748b;">Preparing request...</div>
                            <div class="progress mt-2" style="width: 200px; height: 4px; margin: 0 auto;">
                                <div id="overlay-progress" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div style="font-size: 13px; color: #64748b; text-align: center; padding: 40px 20px;">
                    <i class="bi bi-robot" style="font-size: 32px; color: #cbd5e1; margin-bottom: 12px;"></i>
                    <p style="margin: 0;">No AI analysis available for this project.</p>
                    <p style="margin: 8px 0 0; font-size: 12px; color: #94a3b8;">
                        Analysis will be generated once sufficient progress data is recorded.
                    </p>
                </div>
            @endif
        </div>
    </div>

    <style>
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

    @if (!empty($showRegenerate) && !empty($projectId))
    <script>
        function parseAnalysisText(text) {
            if (!text || !text.trim()) return '<p>No analysis available.</p>';
            
            const sections = [
                { key: 'Report', label: 'Report' },
                { key: 'Possible Risk', label: 'Possible Risk' },
                { key: 'Mitigation', label: 'Mitigation' },
                { key: 'Performance', label: 'Performance' },
                { key: 'AI Comment', label: 'AI Comment' },
                { key: 'Next Steps', label: 'Next Steps' },
                { key: 'Progress', label: 'Progress' },
            ];
            
            let remainingText = text;
            let parsedSections = [];
            
            for (const section of sections) {
                const startMarker = '[SECTION:' + section.key + ']';
                const endMarker = '[/SECTION]';
                
                const startIdx = remainingText.indexOf(startMarker);
                if (startIdx === -1) continue;
                
                const contentStart = startIdx + startMarker.length;
                const endIdx = remainingText.indexOf(endMarker, contentStart);
                if (endIdx === -1) continue;
                
                const content = remainingText.substring(contentStart, endIdx).trim();
                if (content === '') continue;
                
                const formattedContent = formatContent(content);
                
                parsedSections.push({
                    title: section.label,
                    content: formattedContent
                });
                
                remainingText = remainingText.substring(endIdx + endMarker.length);
            }
            
            if (parsedSections.length === 0) {
                parsedSections.push({
                    title: 'Analysis',
                    content: formatContent(text)
                });
            }
            
            let html = '';
            for (const section of parsedSections) {
                html += '<div class="report-section">';
                html += '<div class="report-section-title">' + escapeHtml(section.title) + '</div>';
                html += '<div class="report-section-content">' + section.content + '</div>';
                html += '</div>';
            }
            
            return html;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatContent(content) {
            const lines = content.split('\n');
            let formattedLines = [];
            let inList = false;
            let listType = 'ul';
            let paragraphBuffer = [];
            
            for (const line of lines) {
                const escapedLine = escapeHtml(line);
                const trimmedLine = escapedLine.trim();
                
                const isBullet = /^[-*•]\s+/.test(trimmedLine);
                const isNumbered = /^\d+[.)]\s+/.test(trimmedLine);
                
                if (isBullet || isNumbered) {
                    if (paragraphBuffer.length > 0) {
                        formattedLines.push(formatParagraphs(paragraphBuffer.join('\n')));
                        paragraphBuffer = [];
                    }
                    
                    if (!inList) {
                        inList = true;
                        listType = isNumbered ? 'ol' : 'ul';
                        formattedLines.push('<' + listType + '>');
                    } else if (listType === 'ol' && isBullet) {
                        formattedLines.push('</ol><ul>');
                        listType = 'ul';
                    } else if (listType === 'ul' && isNumbered) {
                        formattedLines.push('</ul><ol>');
                        listType = 'ol';
                    }
                    
                    const itemContent = isNumbered
                        ? trimmedLine.replace(/^\d+[.)]\s+/, '')
                        : trimmedLine.replace(/^[-*•]\s+/, '');
                    formattedLines.push('<li>' + itemContent + '</li>');
                } else {
                    if (inList) {
                        inList = false;
                        formattedLines.push('</' + listType + '>');
                    }
                    paragraphBuffer.push(escapedLine);
                }
            }
            
            if (paragraphBuffer.length > 0) {
                formattedLines.push(formatParagraphs(paragraphBuffer.join('\n')));
            }
            
            if (inList) {
                formattedLines.push('</' + listType + '>');
            }
            
            return formattedLines.join('');
        }
        
        function formatParagraphs(content) {
            const paragraphs = content.split(/\n\s*\n/);
            let formatted = [];
            
            for (const paragraph of paragraphs) {
                const trimmed = paragraph.trim();
                if (trimmed === '') continue;
                const withBreaks = trimmed.replace(/\n/g, '<br>');
                formatted.push('<p>' + withBreaks + '</p>');
            }
            
            return formatted.join('');
        }

        function regenerateAnalysis(projectId) {
            const btn = document.getElementById('regenerate-btn');
            const statusPanel = document.getElementById('regenerate-status-panel');
            const statusMain = document.getElementById('regenerate-status-main');
            const stepsDiv = document.getElementById('regenerate-steps');
            const textDiv = document.getElementById('analysis-text');
            const overlay = document.getElementById('regenerate-overlay');
            const overlayStatus = document.getElementById('overlay-status');
            const overlaySubstatus = document.getElementById('overlay-substatus');
            const overlayProgress = document.getElementById('overlay-progress');
            const btnIcon = btn ? btn.querySelector('i') : null;
            const prefix = window.location.pathname.split('/')[1];

            if (!btn || !textDiv || !overlay) return;

            // Disable button, show overlay
            btn.disabled = true;
            btn.style.opacity = '0.6';
            if (btnIcon) btnIcon.style.transform = 'rotate(360deg)';
            
            if (statusPanel) statusPanel.style.display = 'flex';
            if (statusMain) {
                statusMain.innerHTML = '<i class="bi bi-hourglass-split" style="color: #667eea;"></i> Sending request...';
            }
            if (stepsDiv) {
                stepsDiv.style.display = 'block';
                stepsDiv.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 6px; color: #667eea;"><i class="bi bi-hourglass-split"></i> <span>Sending request...</span></div>
                    <div style="display: flex; align-items: center; gap: 6px; color: #94a3b8;"><i class="bi bi-circle"></i> <span>AI analyzing project data...</span></div>
                    <div style="display: flex; align-items: center; gap: 6px; color: #94a3b8;"><i class="bi bi-circle"></i> <span>Generating report sections...</span></div>
                    <div style="display: flex; align-items: center; gap: 6px; color: #94a3b8;"><i class="bi bi-circle"></i> <span>Finalizing...</span></div>
                `;
            }
            
            overlay.style.display = 'flex';
            let progress = 0;
            const progressSteps = [
                { pct: 15, status: 'Sending request...', sub: 'Connecting to AI service...' },
                { pct: 35, status: 'AI analyzing project data...', sub: 'Processing progress history & baselines...' },
                { pct: 65, status: 'Generating report sections...', sub: 'Building Report, Risk, Mitigation, Performance...' },
                { pct: 90, status: 'Finalizing...', sub: 'Saving recommendations & timestamp...' },
                { pct: 100, status: 'Complete!', sub: 'Refreshing page...' }
            ];
            let stepIndex = 0;
            
            const progressInterval = setInterval(() => {
                if (stepIndex < progressSteps.length) {
                    const step = progressSteps[stepIndex];
                    progress = step.pct;
                    if (overlayProgress) {
                        overlayProgress.style.width = progress + '%';
                        overlayProgress.setAttribute('aria-valuenow', progress);
                    }
                    if (overlayStatus) overlayStatus.textContent = step.status;
                    if (overlaySubstatus) overlaySubstatus.textContent = step.sub;
                    
                    // Update step indicators
                    if (stepsDiv) {
                        const stepItems = stepsDiv.querySelectorAll('div');
                        stepItems.forEach((item, i) => {
                            const icon = item.querySelector('i');
                            const span = item.querySelector('span');
                            if (i < stepIndex) {
                                item.style.color = '#10b981';
                                if (icon) { icon.className = 'bi bi-check-circle-fill'; icon.style.color = '#10b981'; }
                            } else if (i === stepIndex) {
                                item.style.color = '#667eea';
                                if (icon) { icon.className = 'bi bi-hourglass-split'; icon.style.color = '#667eea'; }
                            } else {
                                item.style.color = '#94a3b8';
                                if (icon) { icon.className = 'bi bi-circle'; icon.style.color = '#94a3b8'; }
                            }
                        });
                    }
                    
                    stepIndex++;
                } else {
                    clearInterval(progressInterval);
                }
            }, 1500);

            fetch('/' + prefix + '/projects/' + projectId + '/regenerate-analysis', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(function (r) {
                if (!r.ok) throw new Error('Request failed with status ' + r.status);
                return r.json();
            })
            .then(function (data) {
                clearInterval(progressInterval);
                
                // Update progress to 100%
                if (overlayProgress) {
                    overlayProgress.style.width = '100%';
                    overlayProgress.setAttribute('aria-valuenow', 100);
                }
                if (overlayStatus) overlayStatus.textContent = 'Complete!';
                if (overlaySubstatus) overlaySubstatus.textContent = 'Refreshing page...';
                if (statusMain) {
                    statusMain.innerHTML = '<i class="bi bi-check-circle-fill" style="color: #10b981;"></i> Analysis regenerated — staged in Email In review for 5 days before sending!';
                }
                
                // Update step indicators to all complete
                if (stepsDiv) {
                    const stepItems = stepsDiv.querySelectorAll('div');
                    stepItems.forEach((item) => {
                        item.style.color = '#10b981';
                        const icon = item.querySelector('i');
                        if (icon) { icon.className = 'bi bi-check-circle-fill'; icon.style.color = '#10b981'; }
                    });
                }
                
                // Brief pause to show completion, then reload
                setTimeout(() => {
                    if (window.showToast) {
                        window.showToast('AI analysis regenerated — held in Email In review for 5 days', 'success');
                    }
                    location.reload();
                }, 800);
            })
            .catch(function (err) {
                clearInterval(progressInterval);
                
                // Hide overlay on error
                overlay.style.display = 'none';
                btn.disabled = false;
                btn.style.opacity = '1';
                if (btnIcon) btnIcon.style.transform = 'rotate(0deg)';
                
                const errorMsg = err.message || 'Failed to regenerate. Try again.';
                if (statusMain) {
                    statusMain.innerHTML = '<i class="bi bi-exclamation-circle-fill" style="color: #ef4444;"></i> ' + errorMsg;
                }
                if (stepsDiv) {
                    stepsDiv.innerHTML = '<div style="color: #ef4444;"><i class="bi bi-x-circle-fill"></i> <span>' + errorMsg + '</span></div>';
                }
                
                if (window.showToast) {
                    window.showToast(errorMsg, 'error');
                }
            });
        }
    </script>
    @endif
</div>