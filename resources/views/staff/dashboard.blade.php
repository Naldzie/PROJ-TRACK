@extends('layouts.stafflayouts')

@section('content')

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container py-4">

    <h3 class="mb-4 fw-bold">Admin Dashboard</h3>


    <!-- CARDS -->
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="card shadow border-0 text-white" style="background: linear-gradient(45deg,#28a745,#5ddf8f)">
                <div class="card-body">
                    <h6>Finished Projects</h6>
                    <h2>5</h2>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow border-0 text-white" style="background: linear-gradient(45deg,#ffc107,#ffdd75)">
                <div class="card-body">
                    <h6>Pending Projects</h6>
                    <h2>3</h2>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow border-0 text-white" style="background: linear-gradient(45deg,#0d6efd,#6ea8fe)">
                <div class="card-body">
                    <h6>In Progress</h6>
                    <h2>4</h2>
                </div>
            </div>
        </div>

    </div>

    

<!-- GRAPHS -->
<div class="row g-4 mb-4">

    <!-- S-Curve Line Graph -->
    <div class="col-md-8">
        <div class="card shadow border-0">
            <div class="card-header fw-bold">
                Cumulative Progress (S-Curve)
            </div>
            <div class="card-body">
                <canvas id="progressChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Status Donut Chart -->
    <div class="col-md-4">
        <div class="card shadow border-0">
            <div class="card-header fw-bold">
                Status Distribution
            </div>
            <div class="card-body">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

</div>





    <!-- PROJECT TABLE -->
    <div class="card shadow border-0">

        <div class="card-header fw-bold">
            Project List
        </div>

        <div class="card-body">

            <table class="table table-hover">

                <thead class="table-dark">
                    <tr>
                        <th>Project</th>
                        <th>Contractor</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <tr>
                        <td>Building A</td>
                        <td>ABC Contractor</td>
                        <td><span class="badge bg-success">Finished</span></td>
                    </tr>

                    <tr>
                        <td>Building B</td>
                        <td>XYZ Contractor</td>
                        <td><span class="badge bg-warning">Pending</span></td>
                    </tr>

                    <tr>
                        <td>Building C</td>
                        <td>John Builder</td>
                        <td><span class="badge bg-primary">In Progress</span></td>
                    </tr>

                </tbody>

            </table>

        </div>
    </div>


</div>


<script>

// S-CURVE GRAPH
new Chart(document.getElementById('progressChart'), {
    type: 'line',
    data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun'],
        datasets: [
        {
            label: 'Planned Progress',
            data: [5,12,25,40,55,70],
            tension: 0.3
        },
        {
            label: 'Actual Progress',
            data: [4,10,21,35,48,62],
            tension: 0.3
        }]
    }
});


// DONUT CHART
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['On Track','Delayed','Critical'],
        datasets: [{
            data: [60,25,15]
        }]
    }
});

</script>

@endsection