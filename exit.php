<?php
require_once 'config.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in, redirect to login if not
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$currentUserId = intval($_SESSION['user_id']);

$conn = getDBConnection();

// Get parked vehicles with JOINs - only for current user
$parkedSql = "SELECT v.*, vt.type_code, vt.type_name, vt.rate_per_hour, vt.next_hour, a.area_name, a.area_code
              FROM vehicles v 
              LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id 
              LEFT JOIN areas a ON v.area_id = a.id 
              WHERE v.status = 'parked' AND v.user_id = $currentUserId
              ORDER BY v.check_in_time DESC";
$parkedResult = $conn->query($parkedSql);

$parkedVehicles = [];
if ($parkedResult && $parkedResult->num_rows > 0) {
    while ($row = $parkedResult->fetch_assoc()) {
        $parkedVehicles[] = $row;
    }
}

// Get exited vehicles with JOINs - only for current user
$exitedSql = "SELECT v.*, vt.type_code, vt.type_name, a.area_name, a.area_code
              FROM vehicles v 
              LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id 
              LEFT JOIN areas a ON v.area_id = a.id 
              WHERE v.status = 'checked_out' AND v.user_id = $currentUserId
              ORDER BY v.check_out_time DESC";
$exitedResult = $conn->query($exitedSql);

$exitedVehicles = [];
if ($exitedResult && $exitedResult->num_rows > 0) {
    while ($row = $exitedResult->fetch_assoc()) {
        $exitedVehicles[] = $row;
    }
}

// Handle exit/checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['exit_id'])) {
    $exitId = intval($_POST['exit_id']);
    
    // Get vehicle details before checkout - verify it belongs to current user
    $getVehicleSql = "SELECT v.*, vt.type_code, vt.rate_per_hour, vt.next_hour 
                      FROM vehicles v 
                      LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id 
                      WHERE v.id = $exitId AND v.status = 'parked' AND v.user_id = $currentUserId";
    $vehicleResult = $conn->query($getVehicleSql);
    
    if ($vehicleResult && $vehicleResult->num_rows > 0) {
        $vehicle = $vehicleResult->fetch_assoc();
        
        // Calculate parking duration and fee (using Sri Lanka timezone)
        date_default_timezone_set('Asia/Colombo');
        $checkInTime = new DateTime($vehicle['check_in_time'], new DateTimeZone('Asia/Colombo'));
        $checkOutTime = new DateTime('now', new DateTimeZone('Asia/Colombo'));
        $checkOutTimeFormatted = $checkOutTime->format('Y-m-d H:i:s');
        $duration = $checkOutTime->diff($checkInTime);
        
        // Get rates from vehicle type (first hour and extra hours)
        $firstHourRate = !empty($vehicle['rate_per_hour']) ? floatval($vehicle['rate_per_hour']) : 50;
        $extraHourRate = !empty($vehicle['next_hour']) ? floatval($vehicle['next_hour']) : $firstHourRate;
        $vehicleTypeCode = !empty($vehicle['type_code']) ? $vehicle['type_code'] : 'car';
        
        // Calculate parking fee with night free period (7 PM to 8 AM)
        $parkingFee = calculateParkingFee($checkInTime, $checkOutTime, $firstHourRate, $extraHourRate, $vehicleTypeCode);
        
        // Update vehicle with checkout time and fee (using Sri Lanka timezone)
        $updateSql = "UPDATE vehicles SET status = 'checked_out', check_out_time = '$checkOutTimeFormatted', parking_fee = $parkingFee, updated_at = '$checkOutTimeFormatted' WHERE id = $exitId";
        
        if ($conn->query($updateSql) === TRUE) {
            // Redirect to invoice page
            header("Location: invoice.php?id=$exitId");
            exit();
        }
    }
}

closeDBConnection($conn);

// Helper function to get vehicle icon
function getVehicleIcon($typeCode) {
    $icons = [
        'car' => '🚗',
        'three_wheeler' => '🛺',
        'motorcycle' => '🏍️',
        'transport' => '🚛'
    ];
    return isset($icons[$typeCode]) ? $icons[$typeCode] : '🚗';
}

// Helper function to calculate free night period minutes (8 PM to 7:00 AM next day, charging starts at 7:01 AM)
function calculateFreeNightMinutes($checkInTime, $checkOutTime) {
    $freeMinutes = 0;
    $checkIn = clone $checkInTime;
    $checkOut = clone $checkOutTime;
    
    // Set timezone to Sri Lanka
    $checkIn->setTimezone(new DateTimeZone('Asia/Colombo'));
    $checkOut->setTimezone(new DateTimeZone('Asia/Colombo'));
    
    $current = clone $checkIn;
    
    // Free period: 20:00 (8 PM) to 07:00 (7:00 AM inclusive), charging starts at 7:01 AM
    while ($current < $checkOut) {
        $currentHour = (int)$current->format('H');
        $currentMinute = (int)$current->format('i');
        
        // Check if current time is within free period (20:00 to 07:00 inclusive)
        // Free if: hour >= 20 OR (hour < 7) OR (hour == 7 AND minute == 0)
        $isInFreePeriod = ($currentHour >= 20 || $currentHour < 7 || ($currentHour == 7 && $currentMinute == 0));
        
        if ($isInFreePeriod) {
            // Determine the end of this free period
            if ($currentHour >= 20) {
                // After 8 PM, free until 7:00 AM next day (inclusive)
                $freeEnd = clone $current;
                $freeEnd->setTime(7, 0, 0);
                $freeEnd->modify('+1 day');
            } else if ($currentHour == 7 && $currentMinute == 0) {
                // At exactly 7:00 AM, free period ends at 7:00 AM, move to 7:01 for charging
                $freeEnd = clone $current;
                $freeEnd->setTime(7, 1, 0);
            } else {
                // Before 7 AM, free until 7:00 AM same day (inclusive)
                $freeEnd = clone $current;
                $freeEnd->setTime(7, 0, 0);
            }
            
            // Use whichever comes first: end of free period or check-out time
            $actualEnd = ($freeEnd < $checkOut) ? $freeEnd : $checkOut;
            
            // Calculate minutes in this free period
            $diff = $current->diff($actualEnd);
            $minutesInPeriod = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;
            $freeMinutes += $minutesInPeriod;
            
            $current = $actualEnd;
        } else {
            // Not in free period (7:01 AM to 7:59 PM), move to start of next free period (8 PM) or check-out
            $nextFreeStart = clone $current;
            $nextFreeStart->setTime(20, 0, 0);
            if ($nextFreeStart <= $current) {
                $nextFreeStart->modify('+1 day');
            }
            
            $current = ($nextFreeStart < $checkOut) ? $nextFreeStart : $checkOut;
        }
    }
    
    return $freeMinutes;
}

// Helper function to calculate parking fee with night free period
function calculateParkingFee($checkInTime, $checkOutTime, $firstHourRate, $extraHourRate, $vehicleTypeCode) {
    // Calculate total duration
    $duration = $checkOutTime->diff($checkInTime);
    $totalDays = $duration->days;
    $totalHours = $duration->h;
    $totalMinutes = $duration->i;
    $totalMinutesAll = ($totalDays * 24 * 60) + ($totalHours * 60) + $totalMinutes;
    
    // Calculate free night period minutes (8 PM to 7:00 AM, charging starts at 7:01 AM)
    $freeNightMinutes = calculateFreeNightMinutes($checkInTime, $checkOutTime);
    
    // Subtract free night minutes from total
    $chargeableMinutes = max(0, $totalMinutesAll - $freeNightMinutes);
    
    $isTransport = ($vehicleTypeCode == 'transport');
    
    // Calculate fee based on chargeable minutes
    if ($isTransport) {
        // Transport: charge from the beginning (no free period)
        // First hour rate applies from start, then additional hours
        if ($chargeableMinutes <= 60) {
            $fee = $firstHourRate;
        } else {
            $additionalMinutes = $chargeableMinutes - 60;
            $additionalHours = ceil($additionalMinutes / 60);
            $fee = $firstHourRate + ($additionalHours * $extraHourRate);
        }
    } else {
        // Car, Bike, Threewheeler: free if duration < 10 minutes, charge from 10 minutes
        // Duration 9.59 minutes or less = free, 10 minutes or more = charge first hour rate
        if ($chargeableMinutes < 10) {
            $fee = 0;
        } elseif ($chargeableMinutes <= 60) {
            $fee = $firstHourRate;
        } else {
            $additionalMinutes = $chargeableMinutes - 60;
            $additionalHours = ceil($additionalMinutes / 60);
            $fee = $firstHourRate + ($additionalHours * $extraHourRate);
        }
    }
    
    return $fee;
}
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Exit Vehicle</title>
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
    <style>
        
        .tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
        }
        
        .tab-btn {
            flex: 1;
            padding: 12px 15px;
            background: #1C4D8D;
            border: 2px solid #4988C4;
            color: #BDE8F5;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 0;
        }
        
        .tab-btn:first-child {
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
            border-right: none;
        }
        
        .tab-btn:last-child {
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
            border-left: none;
        }
        
        .tab-btn.active {
            background: #4988C4;
            color: #FFFDE1;
            border-color: #4988C4;
        }
        
        .tab-btn:active {
            transform: scale(0.98);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .vehicle-card-small {
            background: #1C4D8D;
            border: 2px solid #4988C4;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            position: relative;
        }
        
        .vehicle-card-small:hover {
            border-color: #BDE8F5;
            box-shadow: 0 2px 8px rgba(100, 181, 246, 0.2);
        }
        
        .vehicle-info-small {
            flex: 1;
            min-width: 0;
        }
        
        .vehicle-number-small {
            font-size: 18px;
            font-weight: 700;
            color: #BDE8F5;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 2px;
        }
        
        .vehicle-details-small {
            font-size: 12px;
            color: #90caf9;
            margin-top: 4px;
        }
        
        .vehicle-details-small div {
            margin: 2px 0;
        }
        
        .checkout-form-small {
            margin-left: 10px;
            margin-top: 25px;
        }
        
        .btn-secondary-small {
            background: #f44336;
            color: white;
            padding: 8px 16px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .btn-secondary-small:hover {
            background: #d32f2f;
            transform: translateY(-1px);
        }
        
        .btn-secondary-small:active {
            transform: scale(0.95);
        }
        
        .exited-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #4caf50;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: 600;
            z-index: 10;
        }
        
        .btn-print-small {
            background: #4988C4;
            color: white;
            padding: 8px 16px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-print-small:hover {
            background: #1C4D8D;
            transform: translateY(-1px);
        }
        
        .btn-print-small:active {
            transform: scale(0.95);
        }
        
        .btn-print-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(73, 136, 196, 0.8);
            color: white;
            padding: 5px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            backdrop-filter: blur(4px);
        }
        
        .btn-print-icon:hover {
            background: #4988C4;
            transform: scale(1.05);
        }
        
        .btn-print-icon:active {
            transform: scale(0.95);
        }
        
        .vehicle-actions-small {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .vehicle-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-left: 10px;
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }
        
        .modal-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .modal-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .modal-btn-confirm {
            background: #f44336;
            color: white;
        }
        
        .modal-btn-confirm:hover {
            background: #d32f2f;
        }
        
        .modal-btn-cancel {
            background: #666;
            color: white;
        }
        
        .modal-btn-cancel:hover {
            background: #444;
        }
        
        .search-container {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #4988C4;
            border-radius: 8px;
            background: #1C4D8D;
            color: #BDE8F5;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #BDE8F5;
            background: #0F2854;
        }
        
        .search-input::placeholder {
            color: #90caf9;
        }
        
        .date-input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        
        .calendar-icon-btn {
            padding: 12px;
            border: 2px solid #4988C4;
            border-radius: 8px;
            background: #1C4D8D;
            color: #BDE8F5;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            height: 44px;
        }
        
        .calendar-icon-btn:hover {
            background: #4988C4;
            border-color: #BDE8F5;
            color: #FFFDE1;
        }
        
        .calendar-icon-btn:active {
            transform: scale(0.95);
        }
        
        .calendar-icon-btn svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }
        
        .vehicle-card-small.hidden {
            display: none;
        }
        
        .vehicles-list {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
            overflow-x: hidden;
            padding-bottom: 35px;
        }
        
        /* Hide scrollbar for Chrome, Safari and Opera */
        .vehicles-list::-webkit-scrollbar {
            display: none;
        }
        
        /* Hide scrollbar for IE, Edge and Firefox */
        .vehicles-list {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-right">
            <a href="checkin.php" class="link-btn" style="padding: 8px 15px; font-size: 14px;">Back</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <!-- Tabs -->
            <div class="tabs-container">
                <div class="tabs">
                    <button class="tab-btn active" data-tab="parked">
                        Parked (<?php echo count($parkedVehicles); ?>)
                    </button>
                    <button class="tab-btn" data-tab="exited">
                        Exited (<?php echo count($exitedVehicles); ?>)
                    </button>
                </div>
                
                <!-- Parked Vehicles Tab -->
                <div id="parked-tab" class="tab-content active">
                    <div class="search-container">
                        <input type="text" 
                               id="search-parked" 
                               class="search-input" 
                               placeholder="Search by vehicle number" 
                               autocomplete="off">
                        <input type="date" 
                               id="date-parked" 
                               class="date-input">
                        <button type="button" 
                                class="calendar-icon-btn" 
                                onclick="openDatePicker('date-parked')"
                                title="Select Date">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM5 7V6h14v1H5z"/>
                                <path d="M7 11h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2zm-8 4h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2z"/>
                            </svg>
                        </button>
                    </div>
                    <div class="vehicles-list" id="parked-vehicles-list">
                        <?php if (count($parkedVehicles) > 0): ?>
                            <?php foreach ($parkedVehicles as $vehicle): ?>
                                <?php 
                                $areaName = !empty($vehicle['area_name']) ? $vehicle['area_name'] : (!empty($vehicle['area_code']) ? $vehicle['area_code'] : 'N/A');
                                $checkInDate = date('Y-m-d', strtotime($vehicle['check_in_time']));
                                ?>
                                <div class="vehicle-card-small" 
                                     data-vehicle-number="<?php echo strtolower(htmlspecialchars($vehicle['vehicle_number'])); ?>"
                                     data-area="<?php echo strtolower(htmlspecialchars($areaName)); ?>"
                                     data-checkin-date="<?php echo $checkInDate; ?>">
                                    <a href="acknowledgment.php?id=<?php echo $vehicle['id']; ?>" class="btn-print-icon" title="Print Acknowledgment">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="6 9 6 2 18 2 18 9"></polyline>
                                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                                            <rect x="6" y="14" width="12" height="8"></rect>
                                        </svg>
                                    </a>
                                    <div class="vehicle-info-small">
                                        <div class="vehicle-number-small">
                                            <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                            <?php echo getVehicleIcon($vehicle['type_code'] ?? 'car'); ?>
                                        </div>
                                        <div class="vehicle-details-small">
                                            <div>
                                                <?php 
                                                echo "Area: " . htmlspecialchars($areaName);
                                                ?>
                                            </div>
                                            <div>
                                                Check In: <?php echo date('Y-m-d H:i', strtotime($vehicle['check_in_time'])); ?>
                                            </div>
                                            <?php
                                            // Set timezone to Sri Lanka
                                            date_default_timezone_set('Asia/Colombo');
                                            $checkInTime = new DateTime($vehicle['check_in_time'], new DateTimeZone('Asia/Colombo'));
                                            $now = new DateTime('now', new DateTimeZone('Asia/Colombo'));
                                            $duration = $now->diff($checkInTime);

                                            // Calculate estimated fee up to current time
                                            $estimatedFee = 0;
                                            if (!empty($vehicle['rate_per_hour']) && !empty($vehicle['type_code'])) {
                                                $firstHourRate = floatval($vehicle['rate_per_hour']);
                                                $extraHourRate = !empty($vehicle['next_hour']) ? floatval($vehicle['next_hour']) : $firstHourRate;
                                                $vehicleTypeCode = $vehicle['type_code'];
                                                
                                                // Calculate fee with night free period (7 PM to 8 AM)
                                                $estimatedFee = calculateParkingFee($checkInTime, $now, $firstHourRate, $extraHourRate, $vehicleTypeCode);
                                            }
                                            ?>
                                            <?php if ($estimatedFee > 0): ?>
                                            <div>
                                                
                                            </div>
                                            <?php endif; ?>
                                            <div id="duration-<?php echo $vehicle['id']; ?>" 
                                                 data-checkin="<?php echo $vehicle['check_in_time']; ?>"
                                                 data-rate-per-hour="<?php echo !empty($vehicle['rate_per_hour']) ? $vehicle['rate_per_hour'] : 0; ?>"
                                                 data-next-hour="<?php echo !empty($vehicle['next_hour']) ? $vehicle['next_hour'] : 0; ?>"
                                                 data-type-code="<?php echo !empty($vehicle['type_code']) ? $vehicle['type_code'] : 'car'; ?>">
                                                Duration: 
                                                <span class="duration-text">
                                                <?php 
                                                if ($duration->days > 0) echo $duration->days . "d ";
                                                if ($duration->h > 0) echo $duration->h . "h ";
                                                echo $duration->i . "m";
                                                ?>
                                                </span>
                                                <span class="fee-amount" style="color: #4caf50; font-weight: 600;">
                                                    (Rs. <?php echo number_format($estimatedFee, 2); ?>)
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <form method="POST" action="exit.php" class="checkout-form-small" id="exit-form-<?php echo $vehicle['id']; ?>">
                                        <input type="hidden" name="exit_id" value="<?php echo $vehicle['id']; ?>">
                                        <button type="submit" class="btn-secondary-small" onclick="return confirmExit(event, <?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['vehicle_number'], ENT_QUOTES); ?>');">Exit</button>
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
                
                <!-- Exited Vehicles Tab -->
                <div id="exited-tab" class="tab-content">
                    <div class="search-container">
                        <input type="text" 
                               id="search-exited" 
                               class="search-input" 
                               placeholder="Search by vehicle number" 
                               autocomplete="off">
                        <input type="date" 
                               id="date-exited" 
                               class="date-input">
                        <!-- <button type="button" 
                                class="calendar-icon-btn" 
                                onclick="openDatePicker('date-exited')"
                                title="Select Date">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM5 7V6h14v1H5z"/>
                                <path d="M7 11h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2zm-8 4h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2z"/>
                            </svg>
                        </button> -->
                    </div>
                    <div class="vehicles-list" id="exited-vehicles-list">
                        <?php if (count($exitedVehicles) > 0): ?>
                            <?php foreach ($exitedVehicles as $vehicle): ?>
                                <?php 
                                $areaName = !empty($vehicle['area_name']) ? $vehicle['area_name'] : (!empty($vehicle['area_code']) ? $vehicle['area_code'] : 'N/A');
                                $checkOutDate = date('Y-m-d', strtotime($vehicle['check_out_time']));
                                ?>
                                <div class="vehicle-card-small" 
                                     data-vehicle-number="<?php echo strtolower(htmlspecialchars($vehicle['vehicle_number'])); ?>"
                                     data-area="<?php echo strtolower(htmlspecialchars($areaName)); ?>"
                                     data-checkout-date="<?php echo $checkOutDate; ?>">
                                    <span class="exited-badge">EXITED</span>
                                    <div class="vehicle-info-small">
                                        <div class="vehicle-number-small">
                                            <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                            <?php echo getVehicleIcon($vehicle['type_code'] ?? 'car'); ?>
                                        </div>
                                        <div class="vehicle-details-small">
                                            <div>
                                                <?php 
                                                echo "Area: " . htmlspecialchars($areaName);
                                                ?>
                                            </div>
                                            <div>
                                                Check In: <?php echo date('Y-m-d H:i', strtotime($vehicle['check_in_time'])); ?>
                                            </div>
                                            <div>
                                                Check Out: <?php echo date('Y-m-d H:i', strtotime($vehicle['check_out_time'])); ?>
                                            </div>
                                            <?php if (!empty($vehicle['parking_fee'])): ?>
                                                <div style="color: #4caf50; font-weight: 600;">
                                                    Fee: Rs. <?php echo number_format($vehicle['parking_fee'], 2); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="vehicle-actions">
                                        <a href="invoice.php?id=<?php echo $vehicle['id']; ?>" class="btn-print-small">Print</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No exited vehicles</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Alert -->
    <div class="modal-overlay" id="exitModal">
        <div class="modal-content">
            <div class="modal-title">Confirm Exit</div>
            <div class="modal-message" id="modalMessage">Are you sure you want to exit this vehicle?</div>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-cancel" onclick="closeExitModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmExitBtn">Exit</button>
            </div>
        </div>
    </div>
    
    <script>
        let pendingExitForm = null;
        
        // Function to open date picker
        function openDatePicker(dateInputId) {
            const dateInput = document.getElementById(dateInputId);
            if (dateInput) {
                // Try showPicker() method (modern browsers)
                if (dateInput.showPicker) {
                    dateInput.showPicker();
                } else {
                    // Fallback: trigger click on the input
                    dateInput.click();
                }
            }
        }
        
        // Confirmation function before exit
        function confirmExit(event, vehicleId, vehicleNumber) {
            event.preventDefault();
            const modal = document.getElementById('exitModal');
            const message = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('confirmExitBtn');
            
            message.textContent = 'Are you sure you want to exit vehicle ' + vehicleNumber + '?';
            pendingExitForm = document.getElementById('exit-form-' + vehicleId);
            
            modal.classList.add('active');
            
            // Set up confirm button
            confirmBtn.onclick = function() {
                if (pendingExitForm) {
                    pendingExitForm.submit();
                }
            };
            
            return false;
        }
        
        function closeExitModal() {
            const modal = document.getElementById('exitModal');
            modal.classList.remove('active');
            pendingExitForm = null;
        }
        
        // Close modal when clicking outside
        document.getElementById('exitModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeExitModal();
            }
        });
        
        // Tab switching functionality
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                document.getElementById(tabName + '-tab').classList.add('active');
            });
        });
        
        // Filter function for Parked vehicles
        function filterParkedVehicles() {
            const searchTerm = (document.getElementById('search-parked')?.value || '').toLowerCase().trim();
            const selectedDate = document.getElementById('date-parked')?.value || '';
            const vehicleCards = document.querySelectorAll('#parked-vehicles-list .vehicle-card-small');
            
            vehicleCards.forEach(card => {
                const vehicleNumber = card.dataset.vehicleNumber || '';
                const area = card.dataset.area || '';
                const checkInDate = card.dataset.checkinDate || '';
                
                // Filter by date
                const dateMatch = !selectedDate || checkInDate === selectedDate;
                
                // Filter by search term
                const searchMatch = !searchTerm || vehicleNumber.includes(searchTerm) || area.includes(searchTerm);
                
                if (dateMatch && searchMatch) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }
        
        // Filter function for Exited vehicles
        function filterExitedVehicles() {
            const searchTerm = (document.getElementById('search-exited')?.value || '').toLowerCase().trim();
            const selectedDate = document.getElementById('date-exited')?.value || '';
            const vehicleCards = document.querySelectorAll('#exited-vehicles-list .vehicle-card-small');
            
            vehicleCards.forEach(card => {
                const vehicleNumber = card.dataset.vehicleNumber || '';
                const area = card.dataset.area || '';
                const checkoutDate = card.dataset.checkoutDate || '';
                
                // Filter by date
                const dateMatch = !selectedDate || checkoutDate === selectedDate;
                
                // Filter by search term
                const searchMatch = !searchTerm || vehicleNumber.includes(searchTerm) || area.includes(searchTerm);
                
                if (dateMatch && searchMatch) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }
        
        // Search functionality for Parked vehicles
        const searchParked = document.getElementById('search-parked');
        if (searchParked) {
            searchParked.addEventListener('input', filterParkedVehicles);
        }
        
        // Date filter for Parked vehicles
        const dateParked = document.getElementById('date-parked');
        if (dateParked) {
            dateParked.addEventListener('change', filterParkedVehicles);
        }
        
        // Search functionality for Exited vehicles
        const searchExited = document.getElementById('search-exited');
        if (searchExited) {
            searchExited.addEventListener('input', filterExitedVehicles);
        }
        
        // Date filter for Exited vehicles
        const dateExited = document.getElementById('date-exited');
        if (dateExited) {
            dateExited.addEventListener('change', filterExitedVehicles);
        }
        
        // Initial filter on page load (show all vehicles)
        filterParkedVehicles();
        filterExitedVehicles();
        
        // Live duration and fee calculation
        function updateDurationAndFee() {
            document.querySelectorAll('[id^="duration-"]').forEach(durationEl => {
                const checkInTime = new Date(durationEl.dataset.checkin);
                const now = new Date();
                const diff = now - checkInTime;
                
                // Calculate duration
                const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                
                // Format duration text
                let durationText = '';
                if (days > 0) durationText += days + 'd ';
                if (hours > 0) durationText += hours + 'h ';
                durationText += minutes + 'm';
                
                // Update duration text
                const durationTextEl = durationEl.querySelector('.duration-text');
                if (durationTextEl) {
                    durationTextEl.textContent = durationText;
                }
                
                // Calculate fee with night free period (7 PM to 8 AM)
                const ratePerHour = parseFloat(durationEl.dataset.ratePerHour) || 0;
                const nextHour = parseFloat(durationEl.dataset.nextHour) || ratePerHour;
                const typeCode = durationEl.dataset.typeCode || 'car';
                
                let estimatedFee = 0;
                
                if (ratePerHour > 0) {
                    // Calculate free night minutes (8 PM to 7:00 AM, charging starts at 7:01 AM)
                    const calculateFreeNightMinutesJS = (checkIn, checkout) => {
                        let freeMins = 0;
                        let current = new Date(checkIn);
                        const nightStartHour = 20; // 8 PM
                        const nightEndHour = 7;   // 7 AM
                        
                        while (current < checkout) {
                            const currentHour = current.getHours();
                            const currentMinute = current.getMinutes();
                            
                            // Free if: hour >= 20 OR (hour < 7) OR (hour == 7 AND minute == 0)
                            const isInFreePeriod = (currentHour >= nightStartHour || currentHour < nightEndHour || (currentHour == nightEndHour && currentMinute == 0));
                            
                            if (isInFreePeriod) {
                                let freeEnd = new Date(current);
                                if (currentHour >= nightStartHour) {
                                    // After 8 PM, free until 7:00 AM next day (inclusive)
                                    freeEnd.setDate(freeEnd.getDate() + 1);
                                    freeEnd.setHours(nightEndHour, 0, 0, 0);
                                } else if (currentHour == nightEndHour && currentMinute == 0) {
                                    // At exactly 7:00 AM, free period ends at 7:00 AM, move to 7:01 for charging
                                    freeEnd.setHours(nightEndHour, 1, 0, 0);
                                } else {
                                    // Before 7 AM, free until 7:00 AM same day (inclusive)
                                    freeEnd.setHours(nightEndHour, 0, 0, 0);
                                }
                                const actualEnd = (freeEnd < checkout) ? freeEnd : checkout;
                                const minutesInPeriod = Math.floor((actualEnd - current) / (1000 * 60));
                                freeMins += minutesInPeriod;
                                current = actualEnd;
                            } else {
                                // Not in free period (7:01 AM to 7:59 PM), move to start of next free period (8 PM) or check-out
                                let nextFreeStart = new Date(current);
                                nextFreeStart.setHours(nightStartHour, 0, 0, 0);
                                if (nextFreeStart <= current) {
                                    nextFreeStart.setDate(nextFreeStart.getDate() + 1);
                                }
                                current = (nextFreeStart < checkout) ? nextFreeStart : checkout;
                            }
                        }
                        return freeMins;
                    };
                    
                    const totalMinutesAll = Math.floor(diff / (1000 * 60));
                    const freeNightMinutes = calculateFreeNightMinutesJS(checkInTime, now);
                    const chargeableMinutes = Math.max(0, totalMinutesAll - freeNightMinutes);
                    
                    const isTransport = (typeCode === 'transport');
                    
                    if (isTransport) {
                        // Transport: charge from the beginning (no free period)
                        // First hour rate applies from start, then additional hours
                        if (chargeableMinutes <= 60) {
                            estimatedFee = ratePerHour;
                        } else {
                            const additionalMinutes = chargeableMinutes - 60;
                            const additionalHours = Math.ceil(additionalMinutes / 60);
                            estimatedFee = ratePerHour + (additionalHours * nextHour);
                        }
                    } else {
                        // Car, Bike, Threewheeler: free if duration < 10 minutes, charge from 10 minutes
                        // Duration 9.59 minutes or less = free, 10 minutes or more = charge first hour rate
                        if (chargeableMinutes < 10) {
                            estimatedFee = 0;
                        } else if (chargeableMinutes <= 60) {
                            estimatedFee = ratePerHour;
                        } else {
                            const additionalMinutes = chargeableMinutes - 60;
                            const additionalHours = Math.ceil(additionalMinutes / 60);
                            estimatedFee = ratePerHour + (additionalHours * nextHour);
                        }
                    }
                }
                
                // Update fee text
                const feeEl = durationEl.querySelector('.fee-amount');
                if (feeEl) {
                    feeEl.textContent = '(Rs. ' + estimatedFee.toFixed(2) + ')';
                }
            });
        }
        
        // Update every second
        setInterval(updateDurationAndFee, 1000);
        
        // Initial update
        updateDurationAndFee();
    </script>
</body>
</html>
