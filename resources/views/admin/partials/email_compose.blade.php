{{--
    Shared email draft composer/editor.
    Expects: $prefix ('/admin' or '/staff'), $projects (eligible projects w/ contractor).
    Included by both resources/views/admin/email.blade.php and resources/views/staff/email.blade.php.
--}}
<style>
.tag-draft { background: oklch(93% .05 304); color: var(--purple); border: 1px dashed oklch(75% .1 304); }
.tag-edited { background: oklch(93% .045 250); color: oklch(45% .15 265); border: 1px solid oklch(82% .07 265); }
.message.draft::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: repeating-linear-gradient(180deg, var(--purple) 0 6px, transparent 6px 11px);
}
.row-button.disabled-action { opacity: .55; cursor: help; }

@keyframes spin { to { transform: rotate(360deg); } }

.compose-field-label {
  color: var(--muted);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  display: block;
  margin: 0 0 6px;
}
.compose-group { margin-bottom: 14px; }
.compose-input,
.compose-select,
.compose-textarea {
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 9px;
  background: var(--canvas);
  color: var(--ink);
  font-size: 12px;
  padding: 10px 12px;
  outline: none;
  font-family: inherit;
  transition: border-color .2s, box-shadow .2s;
}
.compose-textarea { min-height: 190px; resize: vertical; line-height: 1.65; }
.compose-input:focus,
.compose-select:focus,
.compose-textarea:focus {
  border-color: oklch(70% .12 265);
  background: var(--surface);
  box-shadow: 0 0 0 3px var(--blue-pale);
}
.compose-input:disabled,
.compose-select:disabled { opacity: .6; cursor: not-allowed; }
.compose-recipient {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--blue-pale);
  border: 1px solid var(--line);
  border-radius: 9px;
  padding: 9px 12px;
  font-size: 12px;
  color: var(--ink);
}
.compose-recipient i { color: var(--blue); flex-shrink: 0; }
.compose-error {
  display: none;
  background: oklch(94% .03 25);
  border: 1px solid oklch(85% .07 25);
  color: oklch(42% .16 25);
  border-radius: 9px;
  padding: 9px 12px;
  font-size: 11px;
  line-height: 1.5;
  margin-bottom: 14px;
}
.compose-error.show { display: block; }
.compose-note {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  background: oklch(94% .04 225);
  border: 1px solid oklch(85% .06 230);
  border-radius: 8px;
  padding: 9px 12px;
  font-size: 10.5px;
  color: oklch(35% .08 240);
  line-height: 1.55;
}
.btn-accent {
  background: var(--blue);
  border-color: var(--blue);
  color: oklch(98% .008 270);
}
.btn-accent:hover { background: oklch(45% .18 265); }
#v-actions { display: contents; }
.v-action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
</style>

<!-- COMPOSE / EDIT DRAFT MODAL -->
<div class="overlay" id="composeOverlay">
  <div class="modal-box" style="width:600px;">
    <div class="modal-head">
      <div class="modal-head-left">
        <div class="modal-title" id="c-title">Compose message</div>
        <div class="modal-meta" id="c-meta">Saved as a draft — editable until you send it</div>
      </div>
      <button class="close-btn" onclick="closeCompose()">×</button>
    </div>
    <div class="modal-body">
      <div class="compose-error" id="cError"></div>
      <div class="compose-group">
        <label class="compose-field-label" for="cProject">Project · determines the recipient</label>
        <select class="compose-select" id="cProject" required>
          <option value="">— Select project —</option>
          @foreach($projects as $p)
          <option value="{{ $p->id }}" data-email="{{ $p->contractor?->user?->email ?? '' }}">
            {{ $p->code }} — {{ \Illuminate\Support\Str::limit($p->title, 40) }}
          </option>
          @endforeach
        </select>
      </div>
      <div class="compose-group">
        <label class="compose-field-label">Recipient</label>
        <div class="compose-recipient"><i class="bi bi-envelope-fill"></i><span id="cRecipient">Select a project first</span></div>
      </div>
      <div class="compose-group">
        <label class="compose-field-label" for="cSubject">Subject</label>
        <input type="text" class="compose-input" id="cSubject" maxlength="255" placeholder="e.g. Project Status Update — August 2026">
      </div>
      <div class="compose-group" style="margin-bottom:0;">
        <label class="compose-field-label" for="cBody">Message</label>
        <textarea class="compose-textarea" id="cBody" placeholder="Write the message you want to send to the contractor…"></textarea>
      </div>
    </div>
    <div class="modal-body" style="padding-top:0;">
      <div class="compose-note">
        <i class="bi bi-info-circle" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i>
        <span>You can save this as a draft and come back to edit or delete it anytime. Once sent, the message becomes permanent and read-only.</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-close-modal v-action-btn" onclick="closeCompose()">Cancel</button>
      <button class="btn-close-modal btn-accent v-action-btn" id="composeSaveBtn" onclick="submitCompose()">
        <i class="bi bi-save" style="font-size:12px;"></i> Save draft
      </button>
    </div>
  </div>
</div>

<script>
const EMAIL_PREFIX = '{{ $prefix }}';
let composeMode = 'create';
let editingEmailId = null;

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.content : '';
}

function cShowError(msg) {
  const box = document.getElementById('cError');
  if (!box) return;
  box.textContent = msg;
  box.classList.add('show');
}

function cClearError() {
  const box = document.getElementById('cError');
  if (box) { box.textContent = ''; box.classList.remove('show'); }
}

function resetComposeForm() {
  document.getElementById('cProject').value = '';
  document.getElementById('cProject').disabled = false;
  document.getElementById('cSubject').value = '';
  document.getElementById('cBody').value = '';
  document.getElementById('cRecipient').textContent = 'Select a project first';
  document.getElementById('composeSaveBtn').innerHTML = '<i class="bi bi-save" style="font-size:12px;"></i> Save draft';
}

function setComposeModeUi(isDraft, recipient) {
  const title = document.getElementById('c-title');
  const meta = document.getElementById('c-meta');
  const saveBtn = document.getElementById('composeSaveBtn');

  if (isDraft) {
    title.textContent = 'Edit draft message';
    meta.textContent = `To: ${recipient} · still unsent, so it remains editable`;
    saveBtn.innerHTML = '<i class="bi bi-save" style="font-size:12px;"></i> Save draft';
  } else {
    title.textContent = 'Edit sent message';
    meta.textContent = `To: ${recipient} · saving will automatically re-send it`;
    saveBtn.innerHTML = '<i class="bi bi-arrow-repeat" style="font-size:12px;"></i> Save &amp; re-send';
  }
}

function openCompose() {
  composeMode = 'create';
  editingEmailId = null;
  cClearError();
  resetComposeForm();
  document.getElementById('c-title').textContent = 'Compose message';
  document.getElementById('c-meta').textContent = 'Saved as a draft — editable until you send it';
  document.getElementById('composeOverlay').classList.add('open');
  setTimeout(() => document.getElementById('cProject').focus(), 60);
}

function editDraft(id) {
  closeOverlay();
  fetch(`${EMAIL_PREFIX}/emails/${id}/edit`, { headers: { 'Accept': 'application/json' } })
    .then(r => r.json().then(d => ({ ok: r.ok, d })))
    .then(({ ok, d }) => {
      if (!ok || d.success === false) {
        showToast(d.message || 'Unable to load this draft', 'error');
        return;
      }
      composeMode = 'edit';
      editingEmailId = id;
      cClearError();
      resetComposeForm();
      document.getElementById('cProject').value = d.project_id ?? '';
      document.getElementById('cProject').disabled = true;
      updateRecipientPreview();
      document.getElementById('cSubject').value = d.subject ?? '';
      document.getElementById('cBody').value = d.body ?? '';
      setComposeModeUi(!!d.is_draft, d.recipient_email || '');
      document.getElementById('composeOverlay').classList.add('open');
    })
    .catch(() => showToast('Something went wrong loading this message', 'error'));
}

function closeCompose() {
  document.getElementById('composeOverlay').classList.remove('open');
}

function updateRecipientPreview() {
  const sel = document.getElementById('cProject');
  const opt = sel.options[sel.selectedIndex];
  const email = opt && opt.dataset ? opt.dataset.email : '';
  document.getElementById('cRecipient').textContent = email || 'No contractor email assigned yet';
}

document.addEventListener('change', function(e) {
  if (e.target && e.target.id === 'cProject') updateRecipientPreview();
});

async function submitCompose() {
  const projectId = document.getElementById('cProject').value;
  const subject = document.getElementById('cSubject').value.trim();
  const body = document.getElementById('cBody').value.trim();

  cClearError();

  if (!projectId) return cShowError('Please select a project for this message.');
  if (!subject) return cShowError('Please enter a subject.');
  if (!body) return cShowError('Message body cannot be empty.');

  const saveBtn = document.getElementById('composeSaveBtn');
  const originalHtml = saveBtn.innerHTML;
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<i class="bi bi-arrow-clockwise" style="font-size:12px;animation:spin 1s linear infinite;"></i> Saving…';

  const url = composeMode === 'create'
    ? `${EMAIL_PREFIX}/emails`
    : `${EMAIL_PREFIX}/emails/${editingEmailId}`;

  const res = await fetch(url, {
    method: composeMode === 'create' ? 'POST' : 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(
      composeMode === 'create'
        ? { project_id: projectId, subject, body }
        : { subject, body }
    ),
  });

  saveBtn.disabled = false;
  saveBtn.innerHTML = originalHtml;

  let data = {};
  try { data = await res.json(); } catch (_) {}

  if (!res.ok) {
    if (res.status === 422 && data.errors) {
      cShowError(Object.values(data.errors).flat().join(' '));
    } else {
      cShowError(data.message || 'Unable to save this draft.');
    }
    return;
  }

  showToast(data.message || 'Draft saved', 'success');
  setTimeout(() => window.location.reload(), 700);
}

function confirmSendDraft(id) {
  if (!confirm('Send this message now? Once sent it becomes permanent and can no longer be edited.')) return;

  fetch(`${EMAIL_PREFIX}/emails/${id}/send`, {
    method: 'POST',
    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
  })
    .then(r => r.json().then(d => ({ ok: r.ok, d })))
    .then(({ ok, d }) => {
      showToast(d.message || (ok ? 'Sent' : 'Send failed'), ok ? 'success' : 'error');
      if (ok) setTimeout(() => window.location.reload(), 800);
    })
    .catch(() => showToast('Something went wrong while sending.', 'error'));
}

function deleteDraft(id) {
  if (!confirm('Delete this draft permanently?')) return;

  fetch(`${EMAIL_PREFIX}/emails/${id}`, {
    method: 'DELETE',
    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
  })
    .then(r => r.json().then(d => ({ ok: r.ok, d })))
    .then(({ ok, d }) => {
      showToast(d.message || (ok ? 'Deleted' : 'Delete failed'), ok ? 'success' : 'error');
      if (ok) setTimeout(() => window.location.reload(), 700);
    })
    .catch(() => showToast('Something went wrong while deleting.', 'error'));
}

/* Message action buttons inside the view-message modal footer */
function renderViewActions(isDraft, id) {
  const wrap = document.getElementById('v-actions');
  if (!wrap) return;
  let html = `<button class="btn-close-modal v-action-btn" onclick="closeOverlay();editDraft(${id});"><i class="bi bi-pencil" style="font-size:12px;"></i> ${isDraft ? 'Edit' : 'Edit &amp; re-send'}</button>`;
  if (isDraft) {
    html += `<button class="btn-close-modal btn-accent v-action-btn" onclick="closeOverlay();confirmSendDraft(${id});"><i class="bi bi-send-fill" style="font-size:12px;"></i> Send</button>`;
  }
  wrap.innerHTML = html;
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeCompose();
});
</script>
