<?php
require_once 'config.php';

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

$vehicleId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($vehicleId == 0) {
    header("Location: exit.php");
    exit();
}

$conn = getDBConnection();

// Get vehicle details with JOINs to get vehicle type and area information
$sql = "SELECT v.*, vt.type_code, vt.type_name, vt.rate_per_hour, vt.next_hour, a.area_code, a.area_name
        FROM vehicles v 
        LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id 
        LEFT JOIN areas a ON v.area_id = a.id 
        WHERE v.id = $vehicleId";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    header("Location: exit.php");
    exit();
}

$vehicle = $result->fetch_assoc();

// Calculate duration (using Sri Lanka timezone)
date_default_timezone_set('Asia/Colombo');
$checkInTime = new DateTime($vehicle['check_in_time'], new DateTimeZone('Asia/Colombo'));
$checkOutTime = new DateTime($vehicle['check_out_time'], new DateTimeZone('Asia/Colombo'));
$duration = $checkOutTime->diff($checkInTime);

// Calculate total minutes from check-in
$totalDays = $duration->days;
$totalHours = $duration->h;
$totalMinutes = $duration->i;
$totalMinutesAll = ($totalDays * 24 * 60) + ($totalHours * 60) + $totalMinutes;

// Get rates from vehicle type
$firstHourRate = !empty($vehicle['rate_per_hour']) ? floatval($vehicle['rate_per_hour']) : 50;
$extraHourRate = !empty($vehicle['next_hour']) ? floatval($vehicle['next_hour']) : $firstHourRate;
$vehicleTypeCode = !empty($vehicle['type_code']) ? $vehicle['type_code'] : 'car';

// Check if vehicle type is transport
$isTransport = ($vehicleTypeCode == 'transport');

// Free period for bike, threewheeler, car (first 10 minutes)
$freeMinutes = 10;

// Calculate parking fee with night free period (8 PM to 7 AM)
// Use stored fee if available, otherwise recalculate
if (!empty($vehicle['parking_fee'])) {
    $parkingFee = floatval($vehicle['parking_fee']);
} else {
    $parkingFee = calculateParkingFee($checkInTime, $checkOutTime, $firstHourRate, $extraHourRate, $vehicleTypeCode);
}

// Calculate hours for display
$hours = max(1, ceil($totalMinutesAll / 60));

// Format date and time
$checkInDate = date('Y-m-d', strtotime($vehicle['check_in_time']));
$checkInTime = date('h:i A', strtotime($vehicle['check_in_time']));
$checkOutTimeFormatted = date('h:i A', strtotime($vehicle['check_out_time']));

// Get area name (format: "Area 05" from "AREA 05" or "AREA05")
$areaName = 'N/A';
if (!empty($vehicle['area_name'])) {
    $areaName = $vehicle['area_name'];
} elseif (!empty($vehicle['area_code'])) {
    $areaName = str_replace('AREA', 'Area', $vehicle['area_code']);
}

// Get vehicle type code (LITE, BIKE, Heavy)
$vehicleTypeCode = 'N/A';
$vehicleTypeMap = [
    'car' => 'LITE',
    'three_wheeler' => 'LITE',
    'motorcycle' => 'BIKE',
    'transport' => 'Heavy'
];
if (!empty($vehicle['type_code'])) {
    $vehicleTypeCode = isset($vehicleTypeMap[$vehicle['type_code']]) ? $vehicleTypeMap[$vehicle['type_code']] : strtoupper($vehicle['type_code']);
}

// Get all vehicle types for rate table
$rateTableSql = "SELECT * FROM vehicle_types WHERE status = 'active' ORDER BY id";
$rateTableResult = $conn->query($rateTableSql);
$rateTableData = [];
if ($rateTableResult && $rateTableResult->num_rows > 0) {
    while ($row = $rateTableResult->fetch_assoc()) {
        $rateTableData[] = $row;
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="si">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Payment Ticket</title>
    <?php include 'protection.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .payment-ticket-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            padding-bottom: 30px;
            padding-top: 30px;
        }

        .ticket-title {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        .reprint-label {
            text-align: center;
            font-size: 14px;
            margin-bottom: 15px;
            color: #333;
        }

        .collector-info {
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .details-section {
            margin: 20px 0;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            font-size: 14px;
        }

        .detail-label {
            font-weight: normal;
        }

        .detail-value {
            font-weight: bold;
        }

        .amount-section {
            text-align: center;
            margin: 25px 0;
            padding: 15px 0;
        }

        .amount-label {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .amount-value {
            font-size: 28px;
            font-weight: bold;
        }

        .rate-table {
            width: 100%;
            margin: 25px 0;
            border-collapse: collapse;
        }

        .rate-table th,
        .rate-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #000;
            font-size: 13px;
        }

        .rate-table th {
            background: #f0f0f0;
            font-weight: bold;
        }

        .rate-table td {
            font-weight: normal;
        }

        .note-section {
            margin: 20px 0;
            font-size: 12px;
            text-align: center;
            font-weight: bold;
        }

        .help-desk {
            margin-top: 20px;
            font-size: 14px;
            text-align: center;
        }

        /* Print Styles */
        @media print {
            * {
                margin: 0;
                padding: 0;
            }

            body {
                padding: 0;
                margin: 0;
                display: block;
                min-height: auto;
                width: 50mm;
                height: 80mm;
                font-size: 10px;
                overflow: hidden;
            }

            .payment-ticket-container {
                max-width: 50mm;
                max-height: 80mm;
                width: 50mm;
                height: 80mm;
                padding: 2mm;   
                margin: 0;
                box-shadow: none;
                display: block;
                page-break-inside: avoid;
                overflow: hidden;
            }

            .ticket-title {
                font-size: 10px;
                margin-bottom: 1mm;
                line-height: 1.2;
            }

            .reprint-label {
                font-size: 8px;
                margin-bottom: 1mm;
            }

            .collector-info {
                font-size: 8px;
                margin-bottom: 2mm;
                line-height: 1.2;
            }

            .details-section {
                width: 100%;
                margin: 2mm 0;
            }

            .detail-row {
                text-align: left;
                margin: 1mm 6mm;
                font-size: 8px;
                display: flex;
                justify-content: space-between;
            }

            .detail-label {
                font-size: 10px;
            }

            .detail-value {
                font-size: 10px;
            }

            .amount-section {
                margin: 0 0;
                text-align: center;
            }

            .amount-label {
                font-size: 8px;
            }

            .amount-value {
                font-size: 14px;
            }

            .rate-table {
                width: 100%;
                margin: 0 0;
                font-size: 6px;
                border-collapse: collapse;
            }

            .rate-table th,
            .rate-table td {
                padding: 1mm;
                font-size: 6px;
                text-align: center;
                border: 0.5px solid #000;
            }

            .rate-table th {
                font-weight: bold;
                font-size: 6px;
            }

            .note-section {
                width: 100%;
                margin: 2mm 0;
                text-align: center;
                font-size: 6px;
            }

            .help-desk {
                text-align: center;
                margin-top: 2mm;
                font-size: 6px;
            }

            @page {
                margin: 0;
                size: 50mm 80mm;
            }
        }

        /* Screen only - hide print button when printing */
        @media screen {
            .print-btn-container {
                text-align: center;
                margin-top: 30px;
                padding: 20px 0;
                width: 100%;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: white;
                box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            }

            .print-btn {
                background: #4988C4;
                color: white;
                padding: 12px 30px;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
                font-weight: bold;
                margin: 0 5px;
            }

            .print-btn:hover {
                background: #1C4D8D;
            }

            .back-btn {
                display: inline-block;
                margin-left: 10px;
                background: #666;
                color: white;
                padding: 12px 30px;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                cursor: pointer;
                text-decoration: none;
                font-weight: bold;
                margin: 0 5px;
            }

            .back-btn:hover {
                background: #444;
            }

            body {
                padding-bottom: 100px;
            }
        }

        @media print {
            .print-btn-container {
                display: none;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="payment-ticket-container">
        <div class="ticket-title">PAYMENT TICKET</div>
        <div class="reprint-label">[ REPRINT ]</div>

        <div class="collector-info">
            COLLECTOR : Susantha Synergy Solutions pvt Ltd
        </div>

        <div class="details-section">
            <div class="detail-row">
                <span class="detail-label">Street :</span>
                <span class="detail-value"><?php echo htmlspecialchars($areaName); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Vehicle No :</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Veh-Type :</span>
                <span class="detail-value"><?php echo $vehicleTypeCode; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Date :</span>
                <span class="detail-value"><?php echo $checkInDate; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">IN Time :</span>
                <span class="detail-value"><?php echo $checkInTime; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">OUT Time :</span>
                <span class="detail-value"><?php echo $checkOutTimeFormatted; ?></span>
            </div>
        </div>

        <div class="amount-section">
            <div class="amount-value">Amount Rs. <?php echo number_format($parkingFee, 2); ?></div>
        </div>

        <table class="rate-table">
            <thead>
                <tr>
                    <th>V-Type</th>
                    <th>First Hour</th>
                    <th>Extra Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Display rate table with three main categories
                // Find rates for each category
                $bikeRate = null;
                $liteRate = null;
                $heavyRate = null;

                foreach ($rateTableData as $type) {
                    if ($type['type_code'] == 'motorcycle') {
                        $bikeRate = $type;
                    } elseif ($type['type_code'] == 'car' || $type['type_code'] == 'three_wheeler') {
                        // Use car rate for LITE / Threewheel (they have same rates)
                        if (!$liteRate) {
                            $liteRate = $type;
                        }
                    } elseif ($type['type_code'] == 'transport') {
                        $heavyRate = $type;
                    }
                }

                // Display BIKE
                if ($bikeRate) {
                ?>
                    <tr>
                        <td>BIKE</td>
                        <td><?php echo number_format(floatval($bikeRate['rate_per_hour']), 2); ?></td>
                        <td><?php echo number_format(floatval($bikeRate['next_hour']), 2); ?></td>
                    </tr>
                <?php
                }

                // Display LITE / Threewheel
                if ($liteRate) {
                ?>
                    <tr>
                        <td>LITE / Threewheel</td>
                        <td><?php echo number_format(floatval($liteRate['rate_per_hour']), 2); ?></td>
                        <td><?php echo number_format(floatval($liteRate['next_hour']), 2); ?></td>
                    </tr>
                <?php
                }

                // Display Heavy
                if ($heavyRate) {
                ?>
                    <tr>
                        <td>Heavy</td>
                        <td><?php echo number_format(floatval($heavyRate['rate_per_hour']), 2); ?></td>
                        <td><?php echo number_format(floatval($heavyRate['next_hour']), 2); ?></td>
                    </tr>
                <?php
                }
                ?>
            </tbody>
        </table>

        <div class="note-section">
            Charges are for ground rent only !
        </div>

        <div class="help-desk">
            Help Desk : (077) 717 4850
        </div>
    </div>

    <div class="print-btn-container">
        <a href="checkin.php" class="back-btn">Back to</a>
        <button class="print-btn" onclick="window.print()">Print</button>
    </div>
</body>

</html>