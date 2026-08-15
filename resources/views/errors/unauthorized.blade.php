<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Unauthorized | PROJ-TRACK SYSTEM</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" type="image/png" href="{{ asset('faviconpmu.png') }}?v=3">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
body {
    margin: 0;
    min-height: 100vh;
    font-family: 'Segoe UI', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;

    /* ✨ ANIMATED BACKGROUND */
    opacity: 0;
    background: linear-gradient(-45deg, #e5e7eb, #2563eb, #e5e7eb, #2563eb);
    background-size: 300% 300%;
    animation: gradientMove 10s ease infinite, pageFade 0.8s ease forwards;
}

/* fade in */
@keyframes pageFade {
    to { opacity: 1; }
}

/* moving gradient */
@keyframes gradientMove {
    0%   { background-position: 0% 50%; }
    50%  { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* CARD */
.error-card {
    width: 900px;
    max-width: 95%;
    border-radius: 20px;
    overflow: hidden;

    box-shadow:
        0 20px 40px rgba(0,0,0,0.15),
        0 8px 20px rgba(37,99,235,0.15);

    animation: fadeSlideUp 0.8s ease;
    transition: 0.3s ease;
}

.error-card:hover {
    transform: scale(1.01);
    box-shadow:
        0 30px 70px rgba(0,0,0,0.25),
        0 10px 30px rgba(37,99,235,0.25);
}

/* entry animation */
@keyframes fadeSlideUp {
    from { opacity: 0; transform: translateY(40px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* FORM SIDE */
.form-side {
    background: #f3f4f6;
    padding: 60px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
}

/* BUTTON */
.btn-back {
    background: linear-gradient(135deg, #2563eb, #1e3a8a);
    border: none;
    border-radius: 10px;
    padding: 12px;
    color: white;
    font-weight: 500;
    transition: 0.25s ease;
}

.btn-back:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,0.2);
}

/* IMAGE SIDE */
.image-side {
    background: linear-gradient(135deg, #2563eb, #1e3a8a);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.image-side img {
    width: 150%;
    height: 150%;
    object-fit: cover;

    /* ✨ zoom animation like login */
    animation: zoomImage 20s ease-in-out infinite alternate;
}

@keyframes zoomImage {
    from { transform: scale(1); }
    to   { transform: scale(1.1); }
}

/* TEXT OVERLAY */
.overlay-text {
    position: absolute;
    text-align: center;
    color: white;
    font-size: 50px;
    font-weight: 800;
    letter-spacing: 3px;
    -webkit-text-stroke: 2px rgba(0,0,0,0.5);
    text-shadow: 2px 2px 10px rgba(0,0,0,0.4);
}

/* MOBILE */
@media(max-width:768px){
    .image-side{
        display:none;
    }

    .form-side{
        padding:40px 25px;
    }
}
</style>
</head>

<body>

<div class="error-card row g-0">

    <!-- LEFT SIDE -->
    <div class="col-md-6 form-side">

        <i class="bi bi-slash-circle-fill text-warning mb-3" style="font-size:60px;"></i>

        <h1 class="fw-bold">Unauthorized</h1>

        <p class="text-muted mb-4">
            You don’t have permission to view this page. Please log in with the correct account.
        </p>

        <a href="/login" class="btn btn-back">
            <i class="bi bi-box-arrow-in-right"></i> Return to Login
        </a>

    </div>

    <!-- RIGHT SIDE -->
    <div class="col-md-6 image-side">

        <img src="{{ asset('images/login-image.png') }}" alt="System Image">

        <div class="overlay-text">
            PROJ-TRACK SYSTEM
        </div>

    </div>

</div>

</body>
</html>