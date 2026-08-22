<!-- RISK REGISTER SECTION -->
<div id="risksSection">
    <div style="border-top: 2px solid #e5e7eb; margin-top: 25px; padding-top: 20px;">

        <!-- Risk Register Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 10px;">
                <i class="bi bi-shield-exclamation" style="color: #d97706; font-size: 20px;"></i> Risk Register
            </h4>
            @if(auth()->user()->role === 'admin' || $isAssignedStaff)
            <button class="btn" data-bs-toggle="modal" data-bs-target="#riskModal"
                    style="background: #d97706; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;"
                    onmouseover="this.style.background='#b45309'; this.style.transform='translateY(-2px)';"
                    onmouseout="this.style.background='#d97706'; this.style.transform='translateY(0)';">
                <i class="bi bi-plus-circle"></i> Add Risk
            </button>
            @endif
        </div>

        <!-- Risk Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
            <div style="padding: 15px; background: #fee2e2; border: 1px solid #fecaca; border-radius: 8px;">
                <small style="color: #991b1b; font-weight: 600; display: block; margin-bottom: 6px;">Open Risks</small>
                <div style="font-size: 14px; font-weight: 700; color: #7f1d1d;" id="openRisksCount">0</div>
            </div>
            <div style="padding: 15px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
                <small style="color: #92400e; font-weight: 600; display: block; margin-bottom: 6px;">Mitigated</small>
                <div style="font-size: 14px; font-weight: 700; color: #78350f;" id="mitigatedRisksCount">0</div>
            </div>
            <div style="padding: 15px; background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 8px;">
                <small style="color: #166534; font-weight: 600; display: block; margin-bottom: 6px;">Closed</small>
                <div style="font-size: 14px; font-weight: 700; color: #15803d;" id="closedRisksCount">0</div>
            </div>
            <div style="padding: 15px; background: #fecaca; border: 1px solid #f87171; border-radius: 8px;">
                <small style="color: #7f1d1d; font-weight: 700; display: block; margin-bottom: 6px;">High-Risk ⚠</small>
                <div style="font-size: 14px; font-weight: 800; color: #991b1b;" id="highRisksCount">0</div>
            </div>
        </div>

        <!-- Risks Table -->
        <div style="border-top: 2px solid #e5e7eb; padding-top: 20px;">
            <h6 style="margin-bottom: 15px; font-weight: 600; color: #111827;">
                <i class="bi bi-list-check" style="margin-right: 6px;"></i>
                All Risks <span id="totalRisksCount" style="background: #e5e7eb; color: #374151; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 8px;">0</span>
            </h6>
            <div id="risksTableContainer">
                <!-- Will be populated by risk-register.js -->
                <div style="text-align: center; padding: 40px; color: #9ca3af;">
                    <i class="bi bi-inbox" style="font-size: 32px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                    <div style="font-size: 13px;">Loading risks...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- RISK REGISTER MODALS -->
@include('projects.project_detail.components.modals.risk_modal')
@include('projects.project_detail.components.modals.risk_to_issue_modal')

<link rel="stylesheet" href="{{ asset('css/admin/projects/risk-register.css') }}">
<script src="{{ asset('js/admin/projects/risk-register.js') }}"></script>
