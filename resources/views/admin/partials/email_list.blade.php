@forelse($emails as $email)
@php
    $project = $email->project;
    $ai = $email->aiSolution;
    $body = $ai ? $ai->analysis_text : 'Report analysis not available.';
    $preview = strlen($body) > 120 ? substr($body, 0, 120) . '...' : $body;
    $month = $email->sent_at ? $email->sent_at->format('F Y') : '';
    $shortDate = $email->sent_at ? $email->sent_at->format('M d') : '';
    $status = $email->opened_at ? 'Opened' : ($email->delivered_at ? 'Delivered' : 'Pending');
    $statusClass = $status === 'Opened' ? 'tag-opened' : ($status === 'Delivered' ? 'tag-delivered' : 'tag-pending');
    $initials = '';
    if ($email->recipient_email) {
        $local = explode('@', $email->recipient_email)[0];
        $parts = preg_split('/[._-]/', $local);
        foreach ($parts as $p) { if (strlen($p) > 0) $initials .= strtoupper($p[0]); }
        $initials = substr($initials, 0, 2);
    }
    $contractorName = $email->contractor?->contractor_name ?? $email->recipient_email;
    $companyName = $email->contractor?->company_name ?? '';
    $unread = !$email->opened_at && !$email->delivered_at;
@endphp
<article class="message {{ $unread ? 'unread' : '' }}" onclick="viewMsg({{ $email->id }})">
  <div class="message-avatar">{{ $initials ?: '?' }}</div>
  <div class="message-main">
    <div class="message-top">
      <strong>{{ $contractorName }}</strong>
      <span class="message-time">{{ $shortDate }}</span>
    </div>
    <span class="message-subject">{{ $email->subject }}</span>
    <p class="message-snippet">{{ $preview }}</p>
    <div class="message-meta">
      <span class="tag tag-ai"><i class="bi bi-cpu" style="font-size:8px;"></i> AI report</span>
      @if($month)
      <span class="tag tag-date">{{ $month }}</span>
      @endif
      <span class="tag {{ $statusClass }}">{{ $status }}</span>
      @if($companyName)
      <span class="tag tag-project">{{ $companyName }}</span>
      @endif
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
  <i class="bi bi-send"></i>
  <strong>No messages found</strong>
  <span>Try another search or filter.</span>
</div>
@endforelse
