<div id="ai-prediction" style="padding: 20px;">

    @if ($prediction['is_completed'])
        <!-- PROJECT COMPLETED VIEW -->
        <div class="card" style="border-left: 4px solid #10b981;">
            <div class="card-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                <span class="card-title">
                    <i class="bi bi-check-circle" style="margin-right: 8px;"></i> Project Completed
                </span>
            </div>
            <div style="padding: 20px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            Completion Date
                        </div>
                        <div style="font-size: 20px; color: #1f2937; font-weight: 600;">
                            {{ $prediction['actual_completion'] ? $prediction['actual_completion']->format('M d, Y') : 'N/A' }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            Planned Date
                        </div>
                        <div style="font-size: 20px; color: #1f2937; font-weight: 600;">
                            {{ $prediction['planned_completion'] ? $prediction['planned_completion']->format('M d, Y') : 'N/A' }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            Status
                        </div>
                        <div style="font-size: 20px; font-weight: 600;">
                            @if ($prediction['completion_delay'] === 0)
                                <span style="color: #10b981;"><i class="bi bi-check-circle" style="margin-right: 6px;"></i>On Schedule</span>
                            @elseif ($prediction['completion_delay'] > 0)
                                <span style="color: #f59e0b;"><i class="bi bi-exclamation-circle" style="margin-right: 6px;"></i>{{ $prediction['completion_delay'] }} days late</span>
                            @else
                                <span style="color: #10b981;"><i class="bi bi-check-circle" style="margin-right: 6px;"></i>{{ abs($prediction['completion_delay']) }} days early</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

    @else
        <!-- ONGOING PROJECT PREDICTION VIEW -->
        
        <!-- STATUS CARD -->
        <div class="card" style="margin-bottom: 20px; border-left: 4px solid 
            @if ($prediction['is_insufficient_data'] ?? false) #9ca3af;
            @elseif ($prediction['status'] === 'ON TRACK') #10b981;
            @else #f59e0b;
            @endif">
            <div class="card-header" style="background: 
                @if ($prediction['is_insufficient_data'] ?? false)
                    linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
                @elseif ($prediction['status'] === 'ON TRACK') 
                    linear-gradient(135deg, #10b981 0%, #059669 100%);
                @else 
                    linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                @endif color: white; display: flex; justify-content: space-between; align-items: center;">
                
                <span class="card-title">
                    @if ($prediction['is_insufficient_data'] ?? false)
                        <i class="bi bi-question-circle" style="margin-right: 8px;"></i>
                        INSUFFICIENT DATA - Intelligent Prediction Summary
                    @elseif ($prediction['status'] === 'ON TRACK')
                        <i class="bi bi-check-lg" style="margin-right: 8px;"></i>
                        {{ $prediction['status'] }} - Intelligent Prediction Summary
                    @else
                        <i class="bi bi-exclamation-triangle" style="margin-right: 8px;"></i>
                        {{ $prediction['status'] }} - Intelligent Prediction Summary
                    @endif
                </span>
                <span style="font-size: 12px; background: rgba(255,255,255,0.3); padding: 6px 12px; border-radius: 20px;">
                    {{ $prediction['certainty_label'] ?? 'N/A' }}
                </span>
            </div>

            <div style="padding: 25px;">
                <!-- Estimated Completion Gauge -->
                @php
                    $_gap = max(-20, min(20, $prediction['progress_gap'] ?? 0));
                    $_riskColors = ['Low' => '#10b981', 'Moderate' => '#eab308', 'High' => '#f97316', 'Critical' => '#ef4444'];
                    $_gaugeColor = $_riskColors[$prediction['risk_level']] ?? '#6b7280';
                    $_insufficient = $prediction['is_insufficient_data'] ?? false;
                @endphp
                <div style="margin-bottom: 30px; background: #f9fafb; border-radius: 12px; border: 1px solid #e5e7eb; padding: 20px;">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 40px; flex-wrap: wrap;">
                        <!-- Gauge visual -->
                        <div style="position: relative; width: 260px; height: 260px; flex-shrink: 0;">
                            @if ($_insufficient)
                            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;flex-direction:column;background:#f3f4f6;border-radius:50%;">
                                <i class="bi bi-question-circle" style="font-size:36px;color:#9ca3af;"></i>
                                <div style="font-size:14px;color:#9ca3af;font-weight:600;margin-top:8px;">Insufficient Data</div>
                            </div>
                            @else
                            <div id="project-health-gauge" style="width:260px;height:260px;"></div>
                            <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);text-align:center;z-index:2;pointer-events:none;">
                                <div style="font-size:15px;font-weight:700;color:#1f2937;letter-spacing:0.5px;">{{ $prediction['status'] }}</div>
                                <div style="font-size:12px;font-weight:600;color:{{ $_gaugeColor }};margin-top:4px;">{{ $prediction['risk_level'] }} Risk</div>
                            </div>
                            <style>
                                #project-health-gauge text { opacity: 0; }
                            </style>
                            @endif
                        </div>

                        <!-- Gauge info -->
                        <div style="flex: 1; min-width: 180px;">
                            <div style="font-size: 13px; font-weight: 600; color: #1f2937; margin-bottom: 12px;">
                                <i class="bi bi-speedometer2" style="margin-right: 6px;"></i>Project Health Overview
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <span style="font-size: 12px; color: #6b7280;">Progress Gap</span>
                                    <span style="font-size: 14px; font-weight: 700; color: {{ $_insufficient ? '#9ca3af' : ($prediction['progress_gap'] <= 0 ? '#10b981' : ($prediction['progress_gap'] < 10 ? '#eab308' : '#ef4444')) }};">
                                        @if ($_insufficient) N/A
                                        @elseif (($prediction['progress_gap'] ?? 0) > 0)+{{ $prediction['progress_gap'] }}%
                                        @else {{ $prediction['progress_gap'] }}%
                                        @endif
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <span style="font-size: 12px; color: #6b7280;">Schedule Status</span>
                                    <span style="font-size: 14px; font-weight: 700; color: {{ $_insufficient ? '#9ca3af' : $_gaugeColor }};">
                                        @if ($_insufficient) N/A
                                        @else {{ $prediction['status'] }}
                                        @endif
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb;">
                                    <span style="font-size: 12px; color: #6b7280;">Confidence</span>
                                    <span style="font-size: 14px; font-weight: 700; color: {{ $_insufficient ? '#9ca3af' : ($prediction['completion_confidence'] === 'High' ? '#10b981' : ($prediction['completion_confidence'] === 'Medium' ? '#eab308' : ($prediction['completion_confidence'] === 'Low' ? '#ef4444' : '#6b7280'))) }};">
                                        @if ($_insufficient) N/A
                                        @elseif ($prediction['completion_confidence']) {{ $prediction['completion_confidence'] }}
                                        @else N/A
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @if (!$_insufficient)
                <script>
                (function(){
                    var gap = {{ $_gap }};
                    var value = Math.round(100 - ((gap + 20) * 2.5));
                    var retries = 0;
                    function init(){
                        if (typeof JustGage !== 'undefined' && typeof Raphael !== 'undefined') {
                            try {
                                var g = new JustGage({
                                    id: 'project-health-gauge',
                                    value: value,
                                    min: 0,
                                    max: 100,
                                    levelColors: ['#ef4444','#f97316','#eab308','#22c55e','#10b981'],
                                    noGradient: true,
                                    gaugeWidthScale: 1,
                                    pointer: true,
                                    pointerOptions: { color: '#1f2937', stroke: '#1f2937' },
                                    counter: true,
                                    valueFontColor: 'transparent',
                                    hideMinMax: true,
                                    title: '',
                                    label: '',
                                });
                            } catch(e) {
                                console.error('[Gauge] error:', e.message);
                            }
                        } else if (retries++ < 60) {
                            setTimeout(init, 300);
                        }
                    }
                    document.readyState === 'loading'
                        ? document.addEventListener('DOMContentLoaded', init)
                        : init();
                })();
                </script>
                @endif

                <!-- Key Metrics Row -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            Current Progress
                        </div>
                        <div style="font-size: 28px; color: #1f2937; font-weight: 700;">
                            {{ $prediction['current_progress'] }}%
                        </div>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            Expected: {{ $prediction['expected_progress'] }}%
                        </div>
                    </div>

                    {{-- Next Month Prediction box --}}
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            @if ($prediction['next_is_current_month'] ?? false)
                                This Month Prediction
                            @else
                                Next Month Prediction
                            @endif
                        </div>
                        @if (!($prediction['is_insufficient_data'] ?? false) && $prediction['next_prediction'] !== null)
                            <div style="font-size: 28px; color: #667eea; font-weight: 700;">
                                {{ $prediction['next_prediction'] }}%
                            </div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                @if ($prediction['next_planned_progress'] !== null)
                                    @if ($prediction['next_is_current_month'] ?? false)
                                        Planned This Month: {{ $prediction['next_planned_progress'] }}%
                                    @else
                                        Planned Next: {{ $prediction['next_planned_progress'] }}%
                                    @endif
                                @else
                                    Predicted: {{ $prediction['next_prediction'] }}%
                                @endif
                            </div>
                        @else
                            <div style="font-size: 18px; color: #9ca3af; font-weight: 600;">
                                N/A
                            </div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                Not enough data to predict
                            </div>
                        @endif
                    </div>

                    {{-- Prediction Confidence box --}}
                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            Prediction Confidence
                        </div>
                        @if (!($prediction['is_insufficient_data'] ?? false) && $prediction['regression_info'] !== null)
                            <div style="font-size: 28px; color: #10b981; font-weight: 700;">
                                {{ round($prediction['regression_info']['r_squared'] * 100, 1) }}%
                            </div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                {{ $prediction['regression_info']['fit_quality'] }} — based on {{ $prediction['regression_info']['observation_count'] }} progress records
                            </div>
                        @else
                            <div style="font-size: 18px; color: #9ca3af; font-weight: 600;">
                                N/A
                            </div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                Not enough data to estimate
                            </div>
                        @endif
                    </div>

                    <div style="background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                            Progress Trend
                        </div>
                        <div style="font-size: 16px; color: #1f2937; font-weight: 600; margin-bottom: 4px;">
                            {{ $prediction['trend'] }}
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">
                            @if ($prediction['trend'] === 'ACCELERATING')
                                <i class="bi bi-arrow-up" style="color: #10b981;"></i> {{ $prediction['trend_label'] }}
                            @elseif ($prediction['trend'] === 'DECELERATING')
                                <i class="bi bi-arrow-down" style="color: #f59e0b;"></i> {{ $prediction['trend_label'] }}
                            @else
                                <i class="bi bi-arrow-right" style="color: #6b7280;"></i> {{ $prediction['trend_label'] }}
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Collapsible Detailed Analysis Section -->
                <details style="margin-top: 20px;" open>
                    <summary style="cursor: pointer; font-weight: 600; color: #1f2937; font-size: 14px; padding: 12px; background: #f3f4f6; border-radius: 6px; user-select: none; outline: none;">
                        <i class="bi bi-chevron-down" style="margin-right: 8px; transition: transform 0.3s;"></i>Detailed Analysis & Recommendations
                    </summary>
                    <div style="padding: 20px 0;">

                <!-- Analysis Section -->
                <div style="background: #fffbeb; border: 1px solid #fbbf24; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="font-size: 13px; font-weight: 600; color: #92400e; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                        <span><i class="bi bi-lightbulb" style="margin-right: 6px;"></i>Analysis</span>
                        @if ($prediction['ai_generated_at'] ?? null)
                            <span style="font-size: 11px; background: #eef2ff; color: #4338ca; padding: 3px 10px; border-radius: 12px; white-space: nowrap;">
                                <i class="bi bi-robot" style="margin-right: 4px;"></i>AI Generated — {{ \Carbon\Carbon::parse($prediction['ai_generated_at'])->format('M d, Y g:i A') }}
                            </span>
                        @endif
                    </div>
                    <div id="analysis-text" style="font-size: 14px; color: #78350f; line-height: 1.6;">
                        {{ $prediction['analysis_text'] }}
                    </div>
                    @if (!($prediction['is_insufficient_data'] ?? false) && !($prediction['is_completed'] ?? false))
                        <div style="margin-top: 12px; display: flex; align-items: center; gap: 10px;">
                            <button id="regenerate-btn" onclick="regenerateAnalysis({{ $project->id }})" style="font-size: 13px; padding: 6px 14px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer;">
                                <i class="bi bi-arrow-repeat" style="margin-right: 4px;"></i> Regenerate Analysis
                            </button>
                            <span id="regenerate-status" style="display: none; font-size: 12px; color: #6b7280;">
                                <i class="bi bi-hourglass-split"></i> Generating...
                            </span>
                        </div>
                    @endif
                </div>

                @if (!($prediction['is_insufficient_data'] ?? false) && $prediction['regression_info'] !== null)
                {{-- Regression Summary --}}
                @php
                    $regInfo = $prediction['regression_info'];
                    $regMethod = $regInfo['regression_method'] ?? 'slr';
                    $isPoly = $regMethod === 'polynomial';
                    $hasComparison = $regInfo['r_squared_slr'] !== null && $regInfo['r_squared_poly'] !== null;
                @endphp
                <div style="background: #eef2ff; border: 1px solid #a5b4fc; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="font-size: 13px; font-weight: 600; color: #4338ca; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                        <span><i class="bi bi-bar-chart-line" style="margin-right: 6px;"></i>Model Summary</span>
                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                            @if ($isPoly)
                                <span style="font-size: 11px; background: #667eea; color: white; padding: 3px 10px; border-radius: 12px; font-weight: 600;">
                                    <i class="bi bi-graph-up" style="margin-right: 4px;"></i>Polynomial (Cubic)
                                </span>
                            @else
                                <span style="font-size: 11px; background: #6b7280; color: white; padding: 3px 10px; border-radius: 12px; font-weight: 600;">
                                    <i class="bi bi-bar-chart" style="margin-right: 4px;"></i>Linear (SLR)
                                </span>
                            @endif
                        </span>
                    </div>

                    {{-- Equation --}}
                    <div style="font-size: 14px; color: #3730a3; line-height: 1.6; margin-bottom: 10px;">
                        <strong>Equation:</strong> {{ $regInfo['equation'] }}
                    </div>

                    {{-- Fit Quality --}}
                    <div style="font-size: 14px; color: #3730a3; line-height: 1.6; margin-bottom: 10px;">
                        Based on <strong>{{ $regInfo['observation_count'] }}</strong> monthly progress records, 
                        the model explains <strong>{{ round($regInfo['r_squared'] * 100, 1) }}%</strong> 
                        of the observed progress trend (<strong>R² = {{ number_format($regInfo['r_squared'], 3) }}</strong>), 
                        indicating a <strong>{{ $regInfo['fit_quality'] }}</strong> fit.
                    </div>

                    {{-- R² Comparison (shown when polynomial was considered) --}}
                    @if ($hasComparison)
                    <div style="background: white; border: 1px solid #c7d2fe; border-radius: 6px; padding: 12px; margin-top: 8px;">
                        <div style="font-size: 12px; font-weight: 600; color: #4338ca; margin-bottom: 8px;">
                            <i class="bi bi-arrow-left-right" style="margin-right: 4px;"></i>Model Comparison
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; font-size: 13px;">
                            <div style="text-align: center;">
                                <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">SLR R²</div>
                                <div style="color: #6b7280; font-weight: 700;">{{ number_format($regInfo['r_squared_slr'], 4) }}</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">Polynomial R²</div>
                                <div style="color: {{ $isPoly ? '#667eea' : '#6b7280' }}; font-weight: 700;">
                                    {{ $regInfo['r_squared_poly'] !== null ? number_format($regInfo['r_squared_poly'], 4) : 'N/A' }}
                                </div>
                            </div>
                            <div style="text-align: center;">
                                <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">Improvement</div>
                                <div style="font-weight: 700;
                                    @if ($isPoly) color: #10b981;
                                    @else color: #6b7280;
                                    @endif">
                                    @if ($regInfo['r_squared_poly'] !== null)
                                        {{ $regInfo['r_squared_poly'] - $regInfo['r_squared_slr'] > 0 ? '+' : '' }}
                                        {{ number_format($regInfo['r_squared_poly'] - $regInfo['r_squared_slr'], 4) }}
                                    @else
                                        N/A
                                    @endif
                                </div>
                            </div>
                        </div>
                        {{-- Visual bar comparison --}}
                        @if ($regInfo['r_squared_slr'] > 0 && $regInfo['r_squared_poly'] !== null)
                        <div style="margin-top: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <span style="font-size: 11px; color: #6b7280; width: 50px;">SLR</span>
                                <div style="flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; width: {{ round($regInfo['r_squared_slr'] * 100) }}%; background: #9ca3af; border-radius: 4px;"></div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 11px; color: #6b7280; width: 50px;">
                                    @if ($isPoly) <strong>Poly</strong> @else Poly @endif
                                </span>
                                <div style="flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; width: {{ round($regInfo['r_squared_poly'] * 100) }}%; background: {{ $isPoly ? '#667eea' : '#9ca3af' }}; border-radius: 4px;"></div>
                                </div>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endif

                    {{-- Method Reason --}}
                    @if ($regInfo['method_reason'] ?? null)
                    <div style="font-size: 12px; color: #6b7280; margin-top: 10px; padding-top: 8px; border-top: 1px dashed #c7d2fe;">
                        <i class="bi bi-info-circle" style="margin-right: 4px;"></i>{{ $regInfo['method_reason'] }}
                    </div>
                    @endif
                </div>
                @endif

                <!-- Progress Comparison (Early vs Recent) -->
                <div style="background: #f3f4f6; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="font-size: 13px; font-weight: 600; color: #1f2937; margin-bottom: 12px;">
                        <i class="bi bi-graph-up" style="margin-right: 6px;"></i>Progress Speed Comparison
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #667eea;">
                            <div style="font-size: 11px; color: #6b7280; margin-bottom: 6px; font-weight: 600;">EARLY PHASE SPEED</div>
                            <div style="font-size: 20px; color: #667eea; font-weight: 700;">
                                {{ $prediction['early_speed'] ?? 0 }}%/week
                            </div>
                        </div>
                        <div style="background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #f59e0b;">
                            <div style="font-size: 11px; color: #6b7280; margin-bottom: 6px; font-weight: 600;">RECENT PHASE SPEED</div>
                            <div style="font-size: 20px; color: #f59e0b; font-weight: 700;">
                                {{ $prediction['recent_speed'] ?? 0 }}%/week
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Behind Milestone Alert -->
                @if ($prediction['behind_milestone'])
                    <div style="background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <div style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 8px;">
                            <i class="bi bi-exclamation-triangle-fill" style="margin-right: 6px;"></i>Behind Schedule
                        </div>
                        <div style="font-size: 14px; color: #7f1d1d;">
                            Milestone <strong>"{{ $prediction['behind_milestone']['name'] }}"</strong> is 
                            <strong>{{ $prediction['behind_milestone']['days_late'] }} days overdue</strong>. 
                            This is a critical blocker for subsequent milestones.
                        </div>
                    </div>
                @endif

                <!-- Recommendations -->
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 15px;">
                    <div style="font-size: 13px; font-weight: 600; color: #166534; margin-bottom: 12px;">
                        <i class="bi bi-lightbulb-fill" style="margin-right: 6px;"></i>Recommendations
                    </div>
                    @if (!empty($prediction['structured_recommendations']))
                        @foreach ($prediction['structured_recommendations'] as $rec)
                            <div style="margin-bottom: 10px;">
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: white; background:
                                    @if ($rec['category'] === 'escalation') #ef4444;
                                    @elseif ($rec['category'] === 'corrective') #f59e0b;
                                    @elseif ($rec['category'] === 'monitoring') #eab308;
                                    @else #3b82f6;
                                    @endif">
                                    {{ ucfirst($rec['category']) }}
                                </span>
                                <span style="font-size: 14px; color: #166534; margin-left: 6px;">
                                    {{ $rec['message'] }}
                                </span>
                                @if ($rec['reason'] ?? null)
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px; margin-left: 4px;">
                                        {{ $rec['reason'] }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @else
                        {{-- Fallback to flat strings --}}
                        <ul style="margin: 0; padding-left: 20px; font-size: 14px; color: #187547;">
                            @foreach ($prediction['recommendations'] as $recommendation)
                                <li style="margin-bottom: 8px;">{{ $recommendation }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <!-- Timeline Visualization -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
                <span class="card-title">
                    <i class="bi bi-calendar3-event" style="margin-right: 8px;"></i>Project Timeline Forecast
                </span>
            </div>
            <div style="padding: 20px;">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                    <div style="flex: 1; text-align: center;">
                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;"><i class="bi bi-play-circle" style="margin-right: 4px;"></i>Planned Start</div>
                        <div style="font-size: 16px; color: #1f2937; font-weight: 600;">
                            {{ $project->planned_start_date->format('M d, Y') }}
                        </div>
                    </div>
                    
                    <div style="flex: 2; position: relative; height: 4px; background: #e5e7eb; border-radius: 2px;">
                        <!-- Timeline bar -->
                        <div style="position: absolute; height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); border-radius: 2px; width: {{ min(100, ($prediction['days_elapsed'] / $prediction['days_total']) * 100) }}%;"></div>
                        
                        <!-- Today marker -->
                        <div style="position: absolute; width: 2px; height: 20px; background: #ef4444; top: -8px; left: {{ min(100, ($prediction['days_elapsed'] / $prediction['days_total']) * 100) }}%; transform: translateX(-50%);"></div>
                    </div>
                    
                    <div style="flex: 1; text-align: center;">
                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;"><i class="bi bi-flag-fill" style="margin-right: 4px;"></i>Est. Completion</div>
                        <div style="font-size: 16px; color: 
                            @if (!($prediction['estimated_completion'] ?? null)) #6b7280;
                            @elseif ($prediction['predicted_delay_days'] === 0) #10b981;
                            @elseif ($prediction['predicted_delay_days'] < 0) #10b981;
                            @else #f59e0b;
                            @endif font-weight: 600;">
                            {{ ($prediction['estimated_completion'] ?? null) ? $prediction['estimated_completion']->format('M d, Y') : 'Not enough data' }}
                        </div>
                        @if ($prediction['estimated_completion'] ?? null)
                        <div style="margin-top: 6px; display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap;">
                            @if ($prediction['completion_confidence'] ?? null)
                            <span style="font-size: 10px; padding: 1px 8px; border-radius: 8px; font-weight: 600;
                                @if ($prediction['completion_confidence'] === 'High') background: #d1fae5; color: #065f46;
                                @elseif ($prediction['completion_confidence'] === 'Medium') background: #fef3c7; color: #92400e;
                                @else background: #fee2e2; color: #991b1b;
                                @endif">
                                {{ $prediction['completion_confidence'] }} confidence
                            </span>
                            @endif
                            @if ($prediction['predicted_delay_days'] > 0)
                            <span style="font-size: 11px; color: #ef4444; font-weight: 600;">{{ $prediction['predicted_delay_days'] }} days behind</span>
                            @elseif ($prediction['predicted_delay_days'] < 0)
                            <span style="font-size: 11px; color: #10b981; font-weight: 600;">{{ abs($prediction['predicted_delay_days']) }} days ahead</span>
                            @else
                            <span style="font-size: 11px; color: #10b981; font-weight: 600;">On schedule</span>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>

                <div style="background: #f9fafb; padding: 12px; border-radius: 6px; font-size: 12px; color: #6b7280; text-align: center;">
                    <strong>Days Elapsed:</strong> {{ $prediction['days_elapsed'] }} / {{ $prediction['days_total'] }} 
                    (<strong>{{ round(($prediction['days_elapsed'] / $prediction['days_total']) * 100, 1) }}%</strong> of timeline)
                    &nbsp; | &nbsp;
                    <strong>Days Remaining:</strong> {{ $prediction['days_remaining'] }} days
                </div>
            </div>
        </div>

        @if (!($prediction['is_insufficient_data'] ?? false) && !($prediction['is_completed'] ?? false) && ($prediction['completion_multi']['methods']['polynomial'] ?? null) !== null)
        <!-- Multi-Method Completion Card -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <span class="card-title">
                    <i class="bi bi-grid-3x3-gap-fill" style="margin-right: 8px;"></i>Completion Estimation Comparison (3 Methods)
                </span>
            </div>
            <div style="padding: 20px;">
                @php
                    $methods = $prediction['completion_multi']['methods'];
                    $comparison = $prediction['completion_multi']['comparison'];
                @endphp

                {{-- Three Method Cards --}}
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    @foreach (['polynomial', 'schedule_aware', 'earned_schedule'] as $key)
                        @php $m = $methods[$key] ?? null; @endphp
                        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; display: flex; flex-direction: column;">
                            <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">
                                {{ $m['label_short'] ?? ucfirst(str_replace('_', ' ', $key)) }}
                            </div>
                            @if ($m && $m['estimated_completion_date'])
                                <div style="font-size: 14px; color: #1f2937; font-weight: 600; margin-bottom: 6px;">
                                    {{ $m['estimated_completion_date']->format('M d, Y') }}
                                </div>
                                <div style="font-size: 12px; color: #6b7280;">
                                    @php $delay = $m['predicted_delay_days'] ?? 0; @endphp
                                    @if ($delay > 0)
                                        <span style="color: #f59e0b;">{{ $delay }} days behind</span>
                                    @elseif ($delay < 0)
                                        <span style="color: #10b981;">{{ abs($delay) }} days ahead</span>
                                    @else
                                        <span style="color: #10b981;">On schedule</span>
                                    @endif
                                </div>
                                @if ($m['completion_confidence'] ?? null)
                                    <div style="margin-top: auto; padding-top: 8px; font-size: 11px;">
                                        <span style="padding: 1px 8px; border-radius: 8px; font-weight: 600;
                                            @if ($m['completion_confidence'] === 'High') background: #d1fae5; color: #065f46;
                                            @elseif ($m['completion_confidence'] === 'Medium') background: #fef3c7; color: #92400e;
                                            @else background: #fee2e2; color: #991b1b;
                                            @endif">
                                            {{ $m['completion_confidence'] }}
                                        </span>
                                    </div>
                                @endif
                            @else
                                <div style="font-size: 14px; color: #9ca3af; font-weight: 600;">
                                    N/A
                                </div>
                                <div style="font-size: 11px; color: #9ca3af; margin-top: auto; padding-top: 8px;">
                                    Could not estimate
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Comparison Summary --}}
                @if ($comparison['valid_count'] >= 2)
                <div style="background: #eef2ff; border: 1px solid #a5b4fc; border-radius: 8px; padding: 15px;">
                    <div style="font-size: 12px; font-weight: 600; color: #4338ca; margin-bottom: 10px;">
                        <i class="bi bi-diagram-3" style="margin-right: 4px;"></i>Comparison Summary
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; font-size: 13px;">
                        <div>
                            <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">Earliest Estimate</div>
                            <div style="color: #10b981; font-weight: 700;">{{ $comparison['earliest_date']->format('M d, Y') }}</div>
                        </div>
                        <div>
                            <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">Latest Estimate</div>
                            <div style="color: #f59e0b; font-weight: 700;">{{ $comparison['latest_date']->format('M d, Y') }}</div>
                        </div>
                        <div>
                            <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">Spread</div>
                            <div style="font-weight: 700;">{{ $comparison['spread_days'] }} days</div>
                        </div>
                        <div>
                            <div style="color: #6b7280; font-size: 11px; margin-bottom: 4px;">Agreement</div>
                            <div style="font-weight: 700;
                                @if ($comparison['agreement'] === 'High') color: #10b981;
                                @elseif ($comparison['agreement'] === 'Moderate') color: #f59e0b;
                                @else color: #ef4444;
                                @endif">{{ $comparison['agreement'] }}</div>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
        @endif

                    </div>
                </details>
                <!-- End Collapsible Section -->

        {{-- Prediction History Card --}}
        @if (!($prediction['is_insufficient_data'] ?? false) && !empty($prediction['prediction_history']))
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white;">
                <span class="card-title">
                    <i class="bi bi-clock-history" style="margin-right: 8px;"></i>Prediction History & Accuracy
                </span>
            </div>
            <div style="padding: 20px;">
                @php
                    $summary = $prediction['prediction_history_summary'];
                @endphp

                {{-- Summary stats row --}}
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px;">
                    <div style="background: #f0f9ff; border-radius: 8px; padding: 12px; text-align: center;">
                        <div style="font-size: 11px; color: #0369a1; font-weight: 600; text-transform: uppercase;">Predictions</div>
                        <div style="font-size: 22px; color: #0c4a6e; font-weight: 700;">{{ $summary['total_with_actual'] }}</div>
                    </div>
                    <div style="background: #f0fdf4; border-radius: 8px; padding: 12px; text-align: center;">
                        <div style="font-size: 11px; color: #15803d; font-weight: 600; text-transform: uppercase;">Accurate (±1%)</div>
                        <div style="font-size: 22px; color: #14532d; font-weight: 700;">{{ $summary['accurate_count'] }}</div>
                    </div>
                    <div style="background: #fefce8; border-radius: 8px; padding: 12px; text-align: center;">
                        <div style="font-size: 11px; color: #a16207; font-weight: 600; text-transform: uppercase;">Accuracy Rate</div>
                        <div style="font-size: 22px; color: #713f12; font-weight: 700;">{{ $summary['accuracy_percent'] }}%</div>
                    </div>
                    <div style="background: #fef2f2; border-radius: 8px; padding: 12px; text-align: center;">
                        <div style="font-size: 11px; color: #dc2626; font-weight: 600; text-transform: uppercase;">Avg Variance</div>
                        <div style="font-size: 22px; color: #7f1d1d; font-weight: 700;">
                            @if ($summary['avg_variance'] > 0)+@endif{{ $summary['avg_variance'] }}%
                        </div>
                    </div>
                </div>

                {{-- History table --}}
                @if (count($prediction['prediction_history']) > 0)
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="padding: 8px 12px; text-align: left; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Month</th>
                                <th style="padding: 8px 12px; text-align: left; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Method</th>
                                <th style="padding: 8px 12px; text-align: right; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Predicted</th>
                                <th style="padding: 8px 12px; text-align: right; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Actual</th>
                                <th style="padding: 8px 12px; text-align: right; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($prediction['prediction_history'] as $row)
                                @php
                                    $varColor = abs($row['variance']) < 2 ? '#10b981' : (abs($row['variance']) < 5 ? '#f59e0b' : '#ef4444');
                                @endphp
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 8px 12px; color: #374151;">{{ $row['month'] }}</td>
                                    <td style="padding: 8px 12px; color: #6b7280;">
                                        <span style="font-size: 11px; background: #e5e7eb; padding: 2px 8px; border-radius: 8px;">
                                            {{ $row['method'] === 'polynomial' ? 'Poly' : 'SLR' }}
                                        </span>
                                    </td>
                                    <td style="padding: 8px 12px; text-align: right; color: #374151; font-weight: 600;">{{ $row['predicted'] }}%</td>
                                    <td style="padding: 8px 12px; text-align: right; color: #374151; font-weight: 600;">{{ $row['actual'] }}%</td>
                                    <td style="padding: 8px 12px; text-align: right; color: {{ $varColor }}; font-weight: 600;">
                                        @if ($row['variance'] > 0)+@endif{{ $row['variance'] }}%
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                {{-- Accuracy hint --}}
                <div style="margin-top: 12px; font-size: 12px; color: #6b7280; background: #f9fafb; padding: 10px; border-radius: 6px;">
                    <i class="bi bi-info-circle" style="margin-right: 4px;"></i>
                    Accuracy is measured as actual vs. predicted progress within ±1%. 
                    Variance is (actual - predicted). Updated after each monthly progress recording.
                </div>
            </div>
        </div>
        @endif

    @endif

</div>

<script>
function regenerateAnalysis(projectId) {
    const btn = document.getElementById('regenerate-btn');
    const status = document.getElementById('regenerate-status');
    const textDiv = document.getElementById('analysis-text');
    const prefix = window.location.pathname.split('/')[1];

    btn.disabled = true;
    btn.style.opacity = '0.6';
    status.style.display = 'inline';
    status.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating...';

    fetch('/' + prefix + '/projects/' + projectId + '/regenerate-analysis', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(function (r) {
        if (!r.ok) throw new Error('Request failed');
        return r.json();
    })
    .then(function (data) {
        textDiv.innerText = data.analysis_text;
        status.innerHTML = '<i class="bi bi-check-circle" style="color: #10b981;"></i> Generated: ' + data.generated_at;
        btn.disabled = false;
        btn.style.opacity = '1';
    })
    .catch(function () {
        status.innerHTML = '<i class="bi bi-exclamation-circle" style="color: #ef4444;"></i> Failed to regenerate. Try again.';
        btn.disabled = false;
        btn.style.opacity = '1';
    });
}
</script>

<style>
    #ai-prediction .card {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border: 1px solid #e5e7eb;
        margin-bottom: 0;
    }

    #ai-prediction .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        font-weight: 600;
    }

    #ai-prediction .card-title {
        font-size: 15px;
        font-weight: 600;
        color: inherit;
        margin: 0;
    }

    @media (max-width: 768px) {
        #ai-prediction > div:first-child {
            grid-template-columns: 1fr !important;
        }
    }
</style>
