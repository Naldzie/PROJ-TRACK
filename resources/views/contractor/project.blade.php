@extends('layouts.contractorlayouts')

@section('content')

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<div class="container mt-4">

{{-- HEADER --}}
<div class="d-flex justify-content-between align-items-center mb-3">

<h3 class="fw-bold">
<i class="bi bi-kanban"></i> Contractor Project Progress
</h3>

<select class="form-select w-auto">
<option>Road Expansion</option>
<option>School Building</option>
<option>Bridge Repair</option>
</select>

</div>


{{-- STATUS CARDS --}}
<div class="row mb-4">

<div class="col-md-3">
<div class="card shadow-sm border-0">
<div class="card-body">

<h6>Your Progress</h6>

<div class="progress">
<div id="progressBar"
class="progress-bar bg-success"
style="width:85%">
85%
</div>
</div>

</div>
</div>
</div>

<div class="col-md-3">
<div class="card shadow-sm border-0">
<div class="card-body">

<h6>Status</h6>

<span id="statusBadge"
class="badge bg-success">
On Track
</span>

</div>
</div>
</div>

<div class="col-md-3">
<div class="card shadow-sm border-0">
<div class="card-body">

<h6>Total Delay</h6>

<span id="delayBadge"
class="badge bg-danger">
0 %
</span>

</div>
</div>
</div>

<div class="col-md-3">
<div class="card shadow-sm border-0">
<div class="card-body">

<h6>Project Target</h6>
Jun 2025

</div>
</div>
</div>

</div>


{{-- AI COMMENT --}}
<div class="card shadow-sm mb-4 border-0">

<div class="card-header bg-dark text-white fw-bold">
🤖 Project Assistant
</div>

<div class="card-body">
<div id="aiBox" class="p-3 rounded bg-light">
Update your monthly progress to check if the project is on schedule.
</div>
</div>

</div>



{{-- TASK TABLE --}}
<div class="card shadow-sm mb-4">

<div class="card-header fw-bold">
<i class="bi bi-list-check"></i>
Monthly Progress Report
</div>

<div class="card-body">

<table class="table table-hover table-bordered">

<thead class="table-dark">
<tr>
<th>Month</th>
<th>Task</th>
<th>Your Progress %</th>
<th>Planned %</th>
<th>Delay %</th>
</tr>
</thead>

<tbody id="taskTable">

<tr>
<td>Jan</td>
<td>Site Clearing</td>
<td><input class="form-control actual" value="8"></td>
<td class="planned">10</td>
<td class="delay text-danger fw-bold">2%</td>
</tr>

<tr>
<td>Feb</td>
<td>Foundation</td>
<td><input class="form-control actual" value="18"></td>
<td class="planned">20</td>
<td class="delay text-danger fw-bold">2%</td>
</tr>

<tr>
<td>Mar</td>
<td>Column</td>
<td><input class="form-control actual" value="30"></td>
<td class="planned">40</td>
<td class="delay text-danger fw-bold">10%</td>
</tr>

<tr>
<td>Apr</td>
<td>Roof</td>
<td><input class="form-control actual" value="50"></td>
<td class="planned">60</td>
<td class="delay text-danger fw-bold">10%</td>
</tr>

<tr>
<td>May</td>
<td>Finishing</td>
<td><input class="form-control actual" value="70"></td>
<td class="planned">80</td>
<td class="delay text-danger fw-bold">10%</td>
</tr>

<tr>
<td>Jun</td>
<td>Glass + Paint</td>
<td><input class="form-control actual" value="85"></td>
<td class="planned">100</td>
<td class="delay text-danger fw-bold">15%</td>
</tr>

</tbody>

</table>


<button class="btn btn-primary" onclick="updateChart()">
<i class="bi bi-arrow-repeat"></i>
Submit Progress Update
</button>

<button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#extensionModal">
<i class="bi bi-clock"></i>
Request Extension
</button>

</div>
</div>



{{-- CHART --}}
<div class="card shadow-sm">

<div class="card-header fw-bold">
<i class="bi bi-graph-up"></i>
Project S-Curve
</div>

<div class="card-body">
<canvas id="scurve" height="110"></canvas>
</div>

</div>

</div>



{{-- EXTENSION MODAL --}}
<div class="modal fade" id="extensionModal">

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">
<h5>Extension Request</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<label>Reason for Extension</label>

<textarea class="form-control"></textarea>

<button class="btn btn-warning mt-3 w-100">
Submit Request
</button>

</div>

</div>

</div>

</div>



<script>

const labels = ['Jan','Feb','Mar','Apr','May','Jun'];

let planned = [10,20,40,60,80,100];

let actual = [8,18,30,50,70,85];

const ctx = document.getElementById('scurve');

let chart = new Chart(ctx, {

type: 'line',

data: {

labels: labels,

datasets: [

{
label: 'Planned Progress',
data: planned,
borderColor: 'blue',
tension: .4
},

{
label: 'Your Progress',
data: actual,
borderColor: 'red',
tension: .4
}

]

},

options: {

scales: {

y: {
beginAtZero: true,
max: 120
}

}

}

});



function updateChart(){

let inputs = document.querySelectorAll(".actual");
let plannedCells = document.querySelectorAll(".planned");
let delayCells = document.querySelectorAll(".delay");

actual = [];

let totalDelay = 0;

inputs.forEach((i,index)=>{

let actualValue = parseInt(i.value);
let plannedValue = parseInt(plannedCells[index].innerText);

actual.push(actualValue);

let delay = plannedValue - actualValue;

if(delay < 0) delay = 0;

delayCells[index].innerText = delay + "%";

totalDelay += delay;

});

chart.data.datasets[1].data = actual;

chart.update();

updateStatus(totalDelay);

}



function updateStatus(totalDelay){

let lastActual = actual[actual.length-1];

document.getElementById("delayBadge").innerText =
totalDelay + " %";

document.getElementById("progressBar").style.width =
lastActual + "%";

document.getElementById("progressBar").innerText =
lastActual + "%";

if(totalDelay <= 10){

statusBadge.className =
"badge bg-success";

statusBadge.innerText =
"On Track";

}else{

statusBadge.className =
"badge bg-danger";

statusBadge.innerText =
"Delayed";

}

}

</script>

@endsection