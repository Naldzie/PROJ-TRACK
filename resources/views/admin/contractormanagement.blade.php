@extends('layouts.app')

@section('content')
@php use Illuminate\Support\Str; @endphp

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
body { background: #f4f6f9; }

/* CARD */
.modern-card {
    background: white;
    border-radius: 18px;
    padding: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
}

/* TABLE */
.modern-table th {
    font-size: 12px;
    text-transform: uppercase;
    color: #999;
    border: none;
}
.modern-table td {
    border-top: 1px solid #f1f1f1;
    vertical-align: middle;
}

/* AVATAR */
.avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    object-fit: cover;
}

.avatar-wrapper {
    width: 110px;
    height: 110px;
    margin: auto;
    padding: 4px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
}

.avatar-xl {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    background: white;
}

/* BUTTON */
.btn-primary-modern {
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 10px;
}

/* STAT CARD (same as staff) */
.stat-card {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
    padding: 20px;
    border-radius: 16px;
}

.stat-card small {
    opacity: 0.8;
}

.stat-card h3 {
    margin: 0;
    font-weight: bold;
}

/* STATUS BADGE */
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.status-badge.active {
    background: #dcfce7;
    color: #16a34a;
}

.status-badge.disabled {
    background: #fee2e2;
    color: #dc2626;
}

/* DISABLED ROW */
tr.row-disabled {
    opacity: 0.55;
}

/* ACTIONS */
.action-group {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.action-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.2s;
}

.action-btn:hover {
    transform: translateY(-2px);
    background: #e5e7eb;
}

.action-btn.viewBtn:hover {
    background: #e0e7ff;
    color: #4f46e5;
}

/* DISABLE BUTTON COLOR */
.action-btn.toggleBtn.is-active {
    background: #fee2e2;
    color: #dc2626;
}

.action-btn.toggleBtn.is-active:hover {
    background: #fecaca;
}

/* ENABLE BUTTON COLOR */
.action-btn.toggleBtn.is-inactive {
    background: #dcfce7;
    color: #16a34a;
}

.action-btn.toggleBtn.is-inactive:hover {
    background: #bbf7d0;
}

/* INFO BOX */
.info-box {
    background: #f9fafb;
    padding: 15px;
    border-radius: 12px;
}

.modern-info {
    background: #f9fafb;
    border: 1px solid #f1f1f1;
    transition: 0.2s;
}

.modern-info:hover {
    transform: translateY(-2px);
    background: #f3f4f6;
}

/* ROLE BADGE */
.role-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    background: #eef2ff;
    color: #4f46e5;
}

/* ROW HOVER */
tbody tr:hover {
    background: #fafafa;
}
</style>

<div class="container py-5">

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
       <h2 class="fw-bold mb-1">Contractor Management</h2>
        <p class="text-muted mb-0">Administrative control for contractor records and access</p>
    </div>

    <button class="btn btn-primary-modern"
        data-bs-toggle="modal"
        data-bs-target="#addModal">
        + Add Contractor
    </button>
</div>

<!-- STATS -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <small>Total Contractors</small>
            <h3>{{ count($contractors) }}</h3>
        </div>
    </div>
</div>

<!-- TABLE -->
<div class="modern-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 modern-table">

            <thead>
                <tr>
                    <th class="ps-4">User</th>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th class="text-center pe-4">Actions</th>
                </tr>
            </thead>

            <tbody>
            @forelse($contractors as $contractor)

            @php
                $user = $contractor;

                $photo = $user?->profile_photo;
                if ($photo && !Str::startsWith($photo, 'http')) {
                    $photo = asset('storage/' . $photo);
                }

                $photo = $photo ?? 'https://ui-avatars.com/api/?name=' . urlencode($user->name ?? 'Contractor');
            @endphp

            <tr class="{{ $user->is_active ? '' : 'row-disabled' }}" id="row-{{ $user->id }}">

                <!-- USER -->
                <td class="ps-4">
                    <div class="d-flex align-items-center gap-3">
                        <img src="{{ $photo }}" class="avatar">

                        <div>
                            <div class="fw-semibold">
                                {{ $user->name ?? 'Pending' }}
                            </div>
                            <small class="text-muted">
                                Contractor
                            </small>
                        </div>
                    </div>
                </td>

                <!-- ID -->
                <td>
                    <span class="id-badge">
                        {{ optional($contractor->contractor)->contractor_id ?? 'N/A' }}
                    </span>
                </td>

                <!-- EMAIL -->
                <td class="text-muted">
                    {{ $user?->email ?? 'N/A' }}
                </td>

                <!-- STATUS -->
                <td>
                    <span class="status-badge {{ $user->is_active ? 'active' : 'disabled' }}" id="status-badge-{{ $user->id }}">
                        {{ $user->is_active ? 'Active' : 'Disabled' }}
                    </span>
                </td>

                <!-- ACTIONS -->
                <td class="pe-4">
                    <div class="action-group">

                        <button class="action-btn viewBtn"
                            data-id="{{ $contractor->id }}"
                            title="View">
                            <i class="bi bi-eye"></i>
                        </button>

                        <button class="action-btn toggleBtn {{ $user->is_active ? 'is-active' : 'is-inactive' }}"
                            data-id="{{ $contractor->id }}"
                            data-active="{{ $user->is_active ? '1' : '0' }}"
                            title="{{ $user->is_active ? 'Disable account' : 'Enable account' }}">
                            <i class="bi {{ $user->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>
                        </button>

                    </div>
                </td>

            </tr>

            @empty
            <tr>
                <td colspan="5" class="text-center text-muted py-5">
                    <h6>No contractors yet</h6>
                </td>
            </tr>
            @endforelse
            </tbody>

        </table>
    </div>
</div>

</div>

<!-- ADD MODAL -->
<div class="modal fade" id="addModal">
<div class="modal-dialog modal-dialog-centered">
<form method="POST" action="{{ url('admin/contractors') }}" class="modal-content border-0 shadow rounded-4">
@csrf

<div class="modal-header border-0 pb-0">
    <h5 class="fw-bold">Invite Contractor</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body pt-2">
    <p class="text-muted small mb-3">
        Enter the email address to send an invitation. The user will receive access once they accept.
    </p>

    <div class="form-floating">
        <input type="email"
               name="email"
               class="form-control"
               placeholder="email@example.com"
               required>
        <label>Email Address</label>
    </div>
</div>

<div class="modal-footer border-0">
    <button type="button" class="btn btn-light w-50" data-bs-dismiss="modal">
        Cancel
    </button>
    <button class="btn btn-dark w-50">
        Send Invitation
    </button>
</div>

</form>
</div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewModal">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content border-0 shadow rounded-4">

<div class="modal-header border-0 pb-0">
    <h5 class="fw-bold">Contractor Information</h5>
    <button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body pt-2">

    <!-- PROFILE -->
    <div class="text-center mb-4">

        <img id="view_photo" class="avatar-lg mb-2">

        <h5 id="view_name" class="fw-semibold mt-3 mb-1"></h5>
        <p id="view_email" class="text-muted small mb-0"></p>
        <span class="role-badge">Contractor</span>
        <span id="view_status" class="status-badge ms-1"></span>

    </div>

    <!-- INFO -->
    <div class="row g-3">

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>ID</small>
                <div id="view_id"></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>Company</small>
                <div id="view_company"></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>Contact</small>
                <div id="view_contact"></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>Landline</small>
                <div id="view_landline"></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>Company Address</small>
                <div id="view_address"></div>
            </div>
        </div>

    </div>

</div>
</div>
</div>
</div>

<script>
// VIEW
document.addEventListener('click', function(e){
    const btn = e.target.closest('.viewBtn');
    if(!btn) return;

    let id = btn.dataset.id;

    fetch(`/admin/contractors/${id}`)
    .then(res => res.json())
    .then(data => {

        document.getElementById('view_photo').src =
            data.profile_photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(data.name ?? 'Contractor')}`;

        document.getElementById('view_name').innerText = data.name ?? 'Pending';
        document.getElementById('view_email').innerText = data.email ?? '-';
        document.getElementById('view_id').innerText = data.contractor_id ?? '-';
        document.getElementById('view_company').innerText = data.company_name ?? '-';
        document.getElementById('view_contact').innerText = data.contact_number ?? '-';
        document.getElementById('view_landline').innerText = data.company_landline ?? '-';
        document.getElementById('view_address').innerText = data.company_address ?? '-';

        const statusEl = document.getElementById('view_status');
        if (statusEl) {
            statusEl.innerText = data.is_active ? 'Active' : 'Disabled';
            statusEl.className = 'status-badge ms-1 ' + (data.is_active ? 'active' : 'disabled');
        }

        new bootstrap.Modal(document.getElementById('viewModal')).show();
    })
    .catch(() => Swal.fire("Error", "Failed to load contractor", "error"));
});

// TOGGLE STATUS (Disable / Enable)
document.addEventListener('click', function(e){
    const btn = e.target.closest('.toggleBtn');
    if(!btn) return;

    let id = btn.dataset.id;
    let isActive = btn.dataset.active === '1';

    Swal.fire({
        title: isActive ? "Disable this contractor?" : "Enable this contractor?",
        text: isActive
            ? "The contractor will no longer be able to log in."
            : "The contractor will regain access to log in.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: isActive ? "#d33" : "#16a34a",
        confirmButtonText: isActive ? "Yes, disable" : "Yes, enable"
    }).then(result => {

        if(result.isConfirmed){

            fetch(`/admin/contractors/${id}/toggle-status`, {
                method: "PATCH",
                headers: {
                    "X-CSRF-TOKEN": "{{ csrf_token() }}"
                }
            })
            .then(res => res.json())
            .then(data => {
                if(data.success){
                    Swal.fire("Success", data.message, "success")
                        .then(() => location.reload());
                } else {
                    Swal.fire("Error", data.message, "error");
                }
            })
            .catch(() => Swal.fire("Error", "Action failed", "error"));
        }
    });
});
</script>

@endsection