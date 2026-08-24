@forelse($emails as $email)
@php
    $project = $email->project;
    $ai = $email->aiSolution;
    $isDraft = $email->isDraft();
    $body = $email->effectiveBody() ?? 'Report analysis not available.';
    $preview = strlen($body) > 120 ? substr($body, 0, 120) . '...' : $body;
    $displayDate = $isDraft ? $email->created_at : ($email->sent_at ?? $email->created_at);
    $month = $displayDate ? $displayDate->format('F Y') : '';
    $shortDate = $displayDate ? $displayDate->format('M d') : '';
    $status = $isDraft ? 'Draft' : ($email->opened_at ? 'Opened' : ($email->delivered_at ? 'Delivered' : 'Pending'));
    $statusClass = $isDraft ? 'tag-draft' : ($status === 'Opened' ? 'tag-opened' : ($status === 'Delivered' ? 'tag-delivered' : 'tag-pending'));
    $initials = '';
    if ($email->recipient_email) {
        $local = explode('@', $email->recipient_email)[0];
        $parts = preg_split('/[._-]/', $local);
        foreach ($parts as $p) { if (strlen($p) > 0) $initials .= strtoupper($p[0]); }
        $initials = substr($initials, 0, 2);
    }
    $contractorName = $email->contractor?->contractor_name ?? $email->recipient_email;
    $companyName = $email->contractor?->company_name ?? '';
    $unread = !$isDraft && !$email->opened_at && !$email->delivered_at;
@endphp
<article class="message {{ $unread ? 'unread' : '' }} {{ $isDraft ? 'draft' : '' }}" data-status="{{ strtolower($status) }}" onclick="viewMsg({{ $email->id }})">
  <div class="message-avatar"><img src="{{ asset('faviconpmu.png') }}" alt="PROJ-TRACK"></div>
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
      @if($email->is_edited)
      @php
          $editedBy = $email->editedBy?->name ?? 'a user';
          $editedInfo = 'Edited by ' . $editedBy . ($email->edited_at ? ' · ' . $email->edited_at->format('M d, Y g:i A') : '');
      @endphp
      <span class="tag tag-edited" title="{{ $editedInfo }}"><i class="bi bi-pencil-fill" style="font-size:8px;"></i> Edited</span>
      @endif
      @if($companyName)
      <span class="tag tag-project">{{ $companyName }}</span>
      @endif
    </div>
  </div>
  <div class="message-actions">
    <button class="row-button" onclick="event.stopPropagation();viewMsg({{ $email->id }})" aria-label="Open report">
      <i class="bi bi-box-arrow-up-right"></i>
    </button>
    @if($isDraft)
    <button class="row-button" onclick="event.stopPropagation();editDraft({{ $email->id }})" aria-label="Edit message">
      <i class="bi bi-pencil"></i>
    </button>
    <button class="row-button" onclick="event.stopPropagation();confirmSendDraft({{ $email->id }})" aria-label="Send message">
      <i class="bi bi-send-fill"></i>
    </button>
    <button class="row-button" onclick="event.stopPropagation();deleteDraft({{ $email->id }})" aria-label="Delete draft">
      <i class="bi bi-trash3"></i>
    </button>
    @else
    <button class="row-button" onclick="event.stopPropagation();editDraft({{ $email->id }})" aria-label="Edit message (saving re-sends it)">
      <i class="bi bi-pencil"></i>
    </button>
    <button class="row-button" onclick="event.stopPropagation();showToast('Sent messages cannot be deleted')" aria-label="Locked from deletion">
      <i class="bi bi-lock"></i>
    </button>
    @endif
  </div>
</article>
@empty
<div class="empty-state" id="emptyState">
  <i class="bi bi-send"></i>
  <strong>No messages found</strong>
  <span>Try another search or filter.</span>
</div>
@endforelse
