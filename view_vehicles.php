<?php
require_once 'config.php';

$conn = getDBConnection();

// Get all parked vehicles
$sql = "SELECT * FROM vehicles WHERE status = 'parked' ORDER BY check_in_time DESC";
$result = $conn->query($sql);

$vehicles = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
}

// Handle check out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout_id'])) {
    $checkoutId = intval($_POST['checkout_id']);
    $updateSql = "UPDATE vehicles SET status = 'checked_out', check_out_time = NOW() WHERE id = $checkoutId";
    
    if ($conn->query($updateSql) === TRUE) {
        header("Location: view_vehicles.php?checkout=success");
        exit();
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Parked Vehicles</title>
    <link rel="stylesheet" href="style.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0F2854">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('service-worker.js');
            });
        }
    </script>
    <?php include 'protection.php'; ?>
</head>
<body>
    <!-- Status Bar -->
    <div class="status-bar">
        <span class="time">1:54</span>
        <div class="status-icons">
            <span class="signal">📶</span>
            <span class="wifi">📶</span>
            <span class="battery">85%</span>
        </div>
    </div>

    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <span class="app-title">LAKMAL</span>
            <a href="checkin.php" class="exit-icon">→</a>
        </div>
        <a href="view_vehicles.php" class="refresh-icon">↻</a>
    </div>

    <div class="container">
        <div class="card">
            <h1>Parked Vehicles</h1>
            <h2>Parked Vehicles</h2>
            
            <?php if (isset($_GET['checkout']) && $_GET['checkout'] == 'success'): ?>
                <div class="message success">
                    Vehicle checked out successfully!
                </div>
            <?php endif; ?>
            
            <div class="links">
                <a href="checkin.php" class="link-btn">← Back to Check In</a>
            </div>
            
            <div class="vehicles-list">
                <?php if (count($vehicles) > 0): ?>
                    <div class="count-badge">
                        Total Vehicles: <?php echo count($vehicles); ?>
                    </div>
                    
                    <?php foreach ($vehicles as $vehicle): ?>
                        <div class="vehicle-card">
                            <div class="vehicle-info">
                                <div class="vehicle-number">
                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                    <?php 
                                    $typeIcon = '';
                                    if (isset($vehicle['vehicle_type'])) {
                                        if ($vehicle['vehicle_type'] == 'three_wheeler') $typeIcon = ' 🛺';
                                        elseif ($vehicle['vehicle_type'] == 'motorcycle') $typeIcon = ' 🏍️';
                                        else $typeIcon = ' 🚗';
                                    }
                                    echo $typeIcon;
                                    ?>
                                </div>
                                <div class="vehicle-time">
                                    Check In: <?php echo date('Y-m-d H:i:s', strtotime($vehicle['check_in_time'])); ?>
                                </div>
                                <?php
                                $checkInTime = new DateTime($vehicle['check_in_time']);
                                $now = new DateTime();
                                $duration = $now->diff($checkInTime);
                                ?>
                                <div class="vehicle-duration">
                                    Duration: 
                                    <?php 
                                    if ($duration->days > 0) echo $duration->days . " days ";
                                    if ($duration->h > 0) echo $duration->h . " hours ";
                                    echo $duration->i . " minutes";
                                    ?>
                                </div>
                            </div>
                            <form method="POST" action="view_vehicles.php" class="checkout-form">
                                <input type="hidden" name="checkout_id" value="<?php echo $vehicle['id']; ?>">
                                <button type="submit" class="btn-secondary">Check Out</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No parked vehicles</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
