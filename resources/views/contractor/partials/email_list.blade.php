@forelse($emails as $email)
@php
    $project = $email->project;
    $ai = $email->aiSolution;
    // Prefer the reviewed/edited body so contractors see exactly what staff approved.
    $body = $email->effectiveBody() ?? 'Report analysis not available.';
    $preview = strlen($body) > 120 ? substr($body, 0, 120) . '...' : $body;
    $month = $email->sent_at ? $email->sent_at->format('F Y') : '';
    $shortDate = $email->sent_at ? $email->sent_at->format('M d') : '';
    $unread = !$email->is_read;
    $severity = 'ok';
    if ($project) {
        $lastBaseline = $project->baselineSchedules()->latest('planned_date')->first();
        $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
        $progress = $project->getProgressPercentage();
        $actual = $project->status === 'Completed' ? 100 : (float) $progress;
        $variance = $actual - $planned;
        $severity = $variance >= 0 ? 'ok' : ($variance >= -15 ? 'warn' : 'crit');
    }
@endphp
<article class="message {{ $unread ? 'unread' : '' }}" data-email-id="{{ $email->id }}" data-read="{{ $unread ? 'unread' : 'read' }}" onclick="viewMsg({{ $email->id }})">
  <div class="message-avatar"><img src="{{ asset('faviconpmu.png') }}" alt="PROJ-TRACK"></div>
  <div class="message-main">
    <div class="message-top">
      <strong>PROJ-TRACK System</strong>
      <span class="message-time">{{ $shortDate }}</span>
    </div>
    <span class="message-subject">{{ $email->subject }}</span>
    <p class="message-snippet">{{ $preview }}</p>
    <div class="message-meta">
      <span class="tag tag-ai"><i class="bi bi-cpu" style="font-size:8px;"></i> AI report</span>
      @if($month)
      <span class="tag tag-date">{{ $month }}</span>
      @endif
      <span class="tag tag-{{ $severity }}">
        {{ $severity === 'ok' ? 'On track' : ($severity === 'warn' ? 'Delayed' : 'Critical') }}
      </span>
    </div>
  </div>
  <div class="message-actions">
    <button class="row-button" onclick="event.stopPropagation();viewMsg({{ $email->id }})" aria-label="Open report">
      <i class="bi bi-box-arrow-up-right"></i>
    </button>
    <button class="row-button" onclick="event.stopPropagation();showToast('More actions are ready')" aria-label="More options">
      <i class="bi bi-three-dots"></i>
    </button>
  </div>
</article>
@empty
<div class="empty-state" id="emptyState">
  <i class="bi bi-inbox"></i>
  <strong>No messages found</strong>
  <span>Try another search or filter.</span>
</div>
@endforelse
