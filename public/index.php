<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
$ipMiddleware->enforce();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP Dashboard</title>
    <link rel="stylesheet" href="/assets/dashboard.css">
</head>
<body>
<main class="container">
    <header>
        <h1>Service Desk Dashboard</h1>
        <p id="status">Loading...</p>
    </header>
    <section class="tile" aria-live="polite">
        <h2>Unassigned Tickets</h2>
        <div class="value" id="unassignedValue">--</div>
        <small id="updatedAt">Last updated: --</small>
    </section>
</main>
<script>
    async function refreshDashboard() {
        try {
            const response = await fetch('/api/dashboard', {headers: {'Accept': 'application/json'}});
            const data = await response.json();

            document.getElementById('unassignedValue').textContent = data.tiles.unassignedCount ?? '--';
            document.getElementById('updatedAt').textContent = 'Last updated: ' + (data.updatedAt.halo ?? '--');

            const halo = data.apiStatus.halo;
            document.getElementById('status').textContent = `Halo: ${halo.state.toUpperCase()} - ${halo.message}`;
            document.body.dataset.haloStatus = halo.state;
        } catch (error) {
            document.getElementById('status').textContent = 'Unable to load dashboard data';
            document.body.dataset.haloStatus = 'red';
        }
    }

    refreshDashboard();
    setInterval(refreshDashboard, 10000);
</script>
</body>
</html>
