@extends('layouts.stafflayouts')

@section('content')

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<div class="container-fluid mt-3">

<!-- HEADER -->
<div class="d-flex justify-content-between mb-3">

<h3 class="fw-bold">
<i class="bi bi-kanban"></i>
Admin Projects
</h3>

<button class="btn btn-primary"
data-bs-toggle="modal"
data-bs-target="#createModal">

<i class="bi bi-plus"></i>
New Project

</button>

</div>



<!-- FILTER -->
<div class="card mb-3">

<div class="card-body">

<div class="row">

<div class="col-md-4">

<input
class="form-control"
placeholder="Search project...">

</div>

<div class="col-md-3">

<select class="form-control">

<option>All Status</option>
<option>On Track</option>
<option>Delayed</option>
<option>Completed</option>

</select>

</div>

</div>

</div>

</div>



<!-- PROJECT TABLE -->
<div class="card shadow-sm">

<div class="card-header fw-bold">
Project List
</div>

<div class="card-body">

<table class="table table-striped table-hover">

<thead class="table-dark">

<tr>
<th>#</th>
<th>Project</th>
<th>Status</th>
<th>Actions</th>
</tr>

</thead>

<tbody>

<tr>
<td>1</td>
<td>Road Expansion</td>
<td><span class="badge bg-success">On Track</span></td>
<td>

<a href="{{ url('/project-view') }}"
class="btn btn-sm btn-info">

<i class="bi bi-eye"></i>

</a>

<button class="btn btn-sm btn-warning"
data-bs-toggle="modal"
data-bs-target="#editModal">

<i class="bi bi-pencil"></i>

</button>

<button class="btn btn-sm btn-danger">

<i class="bi bi-trash"></i>

</button>

</td>
</tr>



<tr>
<td>2</td>
<td>Bridge Construction</td>
<td><span class="badge bg-warning text-dark">Delayed</span></td>
<td>

<a href="{{ url('/project-view') }}"
class="btn btn-sm btn-info">

<i class="bi bi-eye"></i>

</a>

<button class="btn btn-sm btn-warning"
data-bs-toggle="modal"
data-bs-target="#editModal">

<i class="bi bi-pencil"></i>

</button>

<button class="btn btn-sm btn-danger">

<i class="bi bi-trash"></i>

</button>

</td>
</tr>



<tr>
<td>3</td>
<td>Water System</td>
<td><span class="badge bg-success">On Track</span></td>
<td>

<a href="{{ url('/project-view') }}"
class="btn btn-sm btn-info">
<i class="bi bi-eye"></i>
</a>

<button class="btn btn-sm btn-warning"
data-bs-toggle="modal"
data-bs-target="#editModal">
<i class="bi bi-pencil"></i>
</button>

<button class="btn btn-sm btn-danger">
<i class="bi bi-trash"></i>
</button>

</td>
</tr>



<tr>
<td>4</td>
<td>School Building</td>
<td><span class="badge bg-success">On Track</span></td>
<td>

<a href="{{ url('/project-view') }}"
class="btn btn-sm btn-info">
<i class="bi bi-eye"></i>
</a>

<button class="btn btn-sm btn-warning"
data-bs-toggle="modal"
data-bs-target="#editModal">
<i class="bi bi-pencil"></i>
</button>

<button class="btn btn-sm btn-danger">
<i class="bi bi-trash"></i>
</button>

</td>
</tr>



<tr>
<td>5</td>
<td>Housing Project</td>
<td><span class="badge bg-warning text-dark">Delayed</span></td>
<td>

<a href="{{ url('/project-view') }}"
class="btn btn-sm btn-info">
<i class="bi bi-eye"></i>
</a>

<button class="btn btn-sm btn-warning"
data-bs-toggle="modal"
data-bs-target="#editModal">
<i class="bi bi-pencil"></i>
</button>

<button class="btn btn-sm btn-danger">
<i class="bi bi-trash"></i>
</button>

</td>
</tr>


</tbody>

</table>

</div>

</div>

</div>



<!-- CREATE MODAL -->
<div class="modal fade" id="createModal">

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">
<h5>Create Project</h5>
</div>

<div class="modal-body">

<input class="form-control mb-2"
placeholder="Project name">

<select class="form-control mb-2">
<option>On Track</option>
<option>Delayed</option>
<option>Completed</option>
</select>

<input type="file"
class="form-control">

</div>

<div class="modal-footer">

<button class="btn btn-primary">
Save
</button>

</div>

</div>

</div>

</div>



<!-- EDIT MODAL -->
<div class="modal fade" id="editModal">

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">
<h5>Edit Project</h5>
</div>

<div class="modal-body">

<input class="form-control mb-2"
value="Project name">

<select class="form-control mb-2">
<option>On Track</option>
<option>Delayed</option>
<option>Completed</option>
</select>

</div>

<div class="modal-footer">

<button class="btn btn-warning">
Update
</button>

</div>

</div>

</div>

</div>

@endsection