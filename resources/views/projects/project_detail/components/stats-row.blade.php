<!-- STATS ROW -->
<div class="stats-row">
    <div class="stat-cell orange">
        <div class="stat-label">Contract Price</div>
        <div class="stat-value orange">{{ \App\Helpers\FormatHelper::formatCurrency($data['overview']['totalBudget'] ?? 0) }}</div>
        {{-- A revised figure must not be captioned "Winning Bid" — that is the
             original award, and the number above is no longer it. --}}
        @if($data['overview']['isContractRevised'] ?? false)
            <div class="stat-sub">Revised &middot; was {{ AppHelpersFormatHelper::formatCurrency($data['overview']['originalContractPrice'] ?? 0) }}</div>
        @else
            <div class="stat-sub">Winning Bid</div>
        @endif
    </div>
    @if($data['overview']['isCompleted'])
        <div class="stat-cell {{ $data['overview']['timeVarianceColor'] ?? 'blue' }}">
            <div class="stat-label">Time Variance</div>
            <div class="stat-value {{ $data['overview']['timeVarianceColor'] ?? 'blue' }}">{{ isset($data['overview']['timeVariance']) && $data['overview']['timeVariance'] !== null ? abs($data['overview']['timeVariance']) : 'N/A' }}</div>
            <div class="stat-sub">{{ $data['overview']['timeVarianceLabel'] ?? 'N/A' }}</div>
        </div>
    @else
        <div class="stat-cell blue">
            <div class="stat-label">Days Remaining</div>
            <div class="stat-value blue">{{ $data['overview']['daysRemaining'] }}</div>
            <div class="stat-sub">Due {{ $data['overview']['endDateFormatted'] }}</div>
        </div>
    @endif
    <div class="stat-cell purple">
        <div class="stat-label">Activities</div>
        <div class="stat-value purple">{{ $data['overview']['completedActivities'] ?? 0 }}/{{ $data['overview']['totalActivities'] ?? 0 }}</div>
        <div class="stat-sub">{{ $data['overview']['activityCompletionRate'] ?? 0 }}% complete</div>
    </div>
</div>
