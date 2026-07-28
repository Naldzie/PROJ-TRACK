@extends('layouts.app')

@section('content')
@php use Illuminate\Support\Str; @endphp

<style>

/* BACKGROUND */
body {
    background: #f4f6f9;
}

/* CARD */
.modern-card {
    background: white;
    border-radius: 18px;
    padding: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
}

/* TABLE */
.modern-table thead {
    background: transparent;
}

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

.avatar-lg {
    width: 100px;
    height: 100px;
    border-radius: 50%;
}

/* BADGE */
.id-badge {
    background: #f1f3f5;
    padding: 6px 12px;
    border-radius: 10px;
    font-size: 12px;
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

/* BUTTONS */
.btn-primary-modern {
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 10px;
    transition: 0.2s;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    opacity: 0.9;
}

.btn-soft {
    background: #eef2ff;
    border: none;
    color: #4f46e5;
}

.btn-danger-soft {
    background: #fee2e2;
    border: none;
    color: #dc2626;
}

/* SELECT */
.modern-select {
    border-radius: 8px;
    border: 1px solid #eee;
}

/* INPUT */
.modern-input {
    border-radius: 10px;
    border: 1px solid #eee;
}

/* MODAL */
.modern-modal {
    border-radius: 16px;
    border: none;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
}

/* STATS */
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

/* INFO BOX */
.info-box {
    background: #f9fafb;
    padding: 15px;
    border-radius: 12px;
    text-align: left;
}

.info-box small {
    color: #888;
}

.info-box div {
    font-weight: 600;
}

/* ROW HOVER */
tbody tr:hover {
    background: #fafafa;
    transition: 0.2s;
}
/* ACTION GROUP */
.action-group {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* ICON BUTTON */
.action-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    color: #374151;
    transition: all 0.2s ease;
}

/* ICON SIZE */
.action-btn i {
    font-size: 16px;
}

/* HOVER EFFECT */
.action-btn:hover {
    transform: translateY(-2px);
    background: #e5e7eb;
}

/* VIEW BUTTON COLOR */
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

/* SELECT STYLE */
.action-select {
    width: 110px;
    font-size: 13px;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
}
/* AVATAR WRAPPER */
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

/* ROLE BADGE */
.role-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    background: #eef2ff;
    color: #4f46e5;
    font-weight: 500;
}

/* MODERN INFO BOX */
.modern-info {
    background: #f9fafb;
    border: 1px solid #f1f1f1;
    transition: 0.2s;
}

.modern-info:hover {
    transform: translateY(-2px);
    background: #f3f4f6;
}

.avatar-lg {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
}
</style>
<div class="container py-5">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 class="fw-bold mb-1">PMU Staff Management</h2>
<p class="text-muted mb-0">Administrative control panel for PMU staff records</p>
        </div>

        <button class="btn btn-primary-modern"
                data-bs-toggle="modal"
                data-bs-target="#addModal">
            + Add Staff
        </button>
    </div>

    <!-- STATS -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <small>Total Users</small>
                <h3>{{ count($staffs) }}</h3>
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
                @forelse($staffs as $user)

                @php
                    $photo = $user->profile_photo;

                    if ($photo && !Str::startsWith($photo, 'http')) {
                        $photo = asset('storage/' . $photo);
                    }

                    $photo = $photo ?? asset('images/default-profile.png');
                @endphp

                <tr class="{{ $user->is_active ? '' : 'row-disabled' }}" id="row-{{ $user->id }}">

                    <!-- USER -->
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <img src="{{ $photo }}" class="avatar">

                            <div>
                                <div class="fw-semibold">
                                    {{ $user->name ?? 'No name yet' }}
                                </div>
                                <small class="text-muted">
                                    {{ ucfirst($user->role) }}
                                </small>
                            </div>
                        </div>
                    </td>

                    <!-- ID -->
                    <td>
                        <span class="id-badge">
                            @if($user->role == 'admin')
                                {{ optional($user->admin)->admin_id ?? 'ADMIN' }}
                            @else
                                {{ optional($user->staff)->staff_id ?? 'N/A' }}
                            @endif
                        </span>
                    </td>

                    <!-- EMAIL -->
                    <td class="text-muted">
                        {{ $user->email }}
                    </td>

                    <!-- STATUS -->
                    <td>
                        <span class="status-badge {{ $user->is_active ? 'active' : 'disabled' }}" id="status-badge-{{ $user->id }}">
                            {{ $user->is_active ? 'Active' : 'Disabled' }}
                        </span>
                    </td>

                    <td class="pe-4">
    <div class="action-group">

        <button class="action-btn viewBtn"
            data-id="{{ $user->id }}"
            title="View">
            <i class="bi bi-eye"></i>
        </button>

        <select class="form-select form-select-sm modern-select action-select changeRole"
            data-id="{{ $user->id }}">
            <option value="">Role</option>
            <option value="admin" {{ $user->role == 'admin' ? 'selected' : '' }}>Admin</option>
            <option value="staff" {{ $user->role == 'staff' ? 'selected' : '' }}>Staff</option>
        </select>

        <button class="action-btn toggleBtn {{ $user->is_active ? 'is-active' : 'is-inactive' }}"
            data-id="{{ $user->id }}"
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
                        <h6>No staff members yet</h6>
                    </td>
                </tr>
                @endforelse
                </tbody>

            </table>
        </div>
    </div>
</div>


<!-- ================= ADD STAFF (GMAIL STYLE) ================= -->
<div class="modal fade" id="addModal">
<div class="modal-dialog modal-dialog-centered">
<form method="POST" action="{{ route('admin.staff.store') }}" class="modal-content border-0 shadow rounded-4">
@csrf

<div class="modal-header border-0 pb-0">
    <h5 class="fw-bold">Invite Staff</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body pt-2">

    <p class="text-muted small mb-3">
        Enter the email address to send an invitation. The user will receive access once they accept.
    </p>

    <div class="form-floating mb-3">
        <input type="email"
               name="email"
               class="form-control"
               placeholder="name@gmail.com"
               pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$"
               required>
        <label>Recipient Email (CSPC Gmail only)</label>
    </div>

    <div class="form-floating">
        <select name="role" class="form-select">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
        </select>
        <label>Select Role</label>
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

<!-- ================= VIEW MODAL ================= -->
<div class="modal fade" id="viewModal">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content border-0 shadow rounded-4">

<div class="modal-header border-0 pb-0">
    <h5 class="fw-bold">Staff Information</h5>
    <button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body pt-2">

    <!-- PROFILE HEADER -->
    <div class="text-center mb-4">

        <img id="view_photo"
             class="profile-avatar mb-2">

        <h5 id="view_name" class="fw-semibold mb-1"></h5>
        <p id="view_email" class="text-muted small mb-0"></p>
        <span id="view_role" class="role-badge"></span>
        <span id="view_status" class="status-badge ms-1"></span>

    </div>

    <!-- INFO GRID -->
    <div class="row g-3">

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>Staff ID</small>
                <div id="view_staff_id"></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="info-box modern-info">
                <small>Profession</small>
                <div id="view_profession"></div>
            </div>
        </div>

        <div class="col-md-12">
            <div class="info-box modern-info">
                <small>Contact Number</small>
                <div id="view_contact"></div>
            </div>
        </div>

    </div>

</div>

</div>
</div>
</div>

<script>

// ================= TOGGLE STATUS (Disable / Enable) =================
document.addEventListener('click', function (e) {

    const btn = e.target.closest('.toggleBtn');
    if (!btn) return;

    let id = btn.dataset.id;
    let isActive = btn.dataset.active === '1';

    Swal.fire({
        title: isActive ? "Disable this account?" : "Enable this account?",
        text: isActive
            ? "The user will no longer be able to log in."
            : "The user will regain access to log in.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: isActive ? "#d33" : "#16a34a",
        confirmButtonText: isActive ? "Yes, disable" : "Yes, enable"
    }).then((result) => {

        if (result.isConfirmed) {
            fetch(`/admin/staff/${id}/toggle-status`, {
                method: "PATCH",
                headers: {
                    "X-CSRF-TOKEN": "{{ csrf_token() }}"
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: "Success",
                        text: data.message,
                        icon: "success",
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire("Error", data.message || "Action failed", "error");
                }
            })
            .catch(() => {
                Swal.fire("Error", "Action failed", "error");
            });
        }

    });
});
// ================= CHANGE ROLE =================
document.addEventListener('change', function (e) {
    if (e.target.classList.contains('changeRole')) {

        let id = e.target.dataset.id;
        let role = e.target.value;

        if (!role) return;

        fetch(`/admin/staff/${id}/change-role`, {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": "{{ csrf_token() }}",
                "Content-Type": "application/json"
            },
            body: JSON.stringify({ role })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: "Success",
                    text: data.message,
                    icon: "success",
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire("Error", "Role update failed", "error");
            }
        })
        .catch(() => Swal.fire("Error", "Role update failed", "error"));
    }
});


// ================= VIEW =================
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.viewBtn'); // ✅ FIXED
    if (!btn) return;

    let id = btn.dataset.id;

    fetch(`/admin/staff/${id}`)
    .then(res => {
        if (!res.ok) throw new Error('Network error'); // optional debug
        return res.json();
    })
.then(data => {

    document.getElementById('view_photo').src =
        data.profile_photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(data.name ?? 'User')}`;

    document.getElementById('view_staff_id').innerText = data.staff_id ?? '-';
    document.getElementById('view_name').innerText = data.name ?? 'No name yet';
    document.getElementById('view_email').innerText = data.email ?? '-';

    const roleEl = document.getElementById('view_role');

    // ✅ check if exists (prevents crash)
    if (roleEl) {
        roleEl.innerText = data.label;

        if (data.role === 'admin') {
            roleEl.style.background = '#fee2e2';
            roleEl.style.color = '#dc2626';
        } else {
            roleEl.style.background = '#eef2ff';
            roleEl.style.color = '#4f46e5';
        }
    }

    const statusEl = document.getElementById('view_status');
    if (statusEl) {
        statusEl.innerText = data.is_active ? 'Active' : 'Disabled';
        statusEl.className = 'status-badge ms-1 ' + (data.is_active ? 'active' : 'disabled');
    }

    // ✅ handle fields properly
    const profession = document.getElementById('view_profession');
    const contact = document.getElementById('view_contact');

    if (data.role === 'admin') {
        profession.innerText = '—';
        contact.innerText = '—';
    } else {
        profession.innerText = data.profession ?? '-';
        contact.innerText = data.contact_number ?? '-';
    }

    new bootstrap.Modal(document.getElementById('viewModal')).show();
})
.catch(err => {
    console.error(err);
    Swal.fire("Error", "Failed to load staff details", "error");
});
});

</script>

@endsection