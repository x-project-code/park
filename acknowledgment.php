<?php
require_once 'config.php';

$vehicleId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($vehicleId == 0) {
    header("Location: checkin.php");
    exit();
}

$conn = getDBConnection();

// Get vehicle details with JOIN to get vehicle type and area information
$sql = "SELECT v.*, vt.type_code, vt.type_name, a.area_code, a.area_name, u.username
        FROM vehicles v 
        LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id 
        LEFT JOIN areas a ON v.area_id = a.id 
        LEFT JOIN users u ON v.user_id = u.id
        WHERE v.id = $vehicleId";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    header("Location: checkin.php");
    exit();
}

$vehicle = $result->fetch_assoc();

// Format date and time
$checkInDate = date('Y-m-d', strtotime($vehicle['check_in_time']));
$checkInTime = date('h:i A', strtotime($vehicle['check_in_time']));

// Get area name (format: "Area 05" from "AREA 05" or "AREA05")
$areaName = 'N/A';
if (!empty($vehicle['area_name'])) {
    $areaName = $vehicle['area_name'];
} elseif (!empty($vehicle['area_code'])) {
    $areaName = str_replace('AREA', 'Area', $vehicle['area_code']);
}

// Get vehicle type (short code like "LITE" for car, etc.)
$vehicleTypeCode = 'N/A';
$vehicleTypeMap = [
    'car' => 'LITE',
    'three_wheeler' => '3W',
    'motorcycle' => 'BIKE',
    'transport' => 'HEAVY'
];
if (!empty($vehicle['type_code'])) {
    $vehicleTypeCode = isset($vehicleTypeMap[$vehicle['type_code']]) ? $vehicleTypeMap[$vehicle['type_code']] : strtoupper($vehicle['type_code']);
}

// Employee info
$employeeInfo = 'N/A';
if (!empty($vehicle['username'])) {
    $employeeInfo = $vehicle['username'] . ' ' . $vehicleId . '/' . date('y', strtotime($vehicle['check_in_time']));
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="si">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Acknowledgment</title>
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

        .acknowledgment-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        @media print {
            .acknowledgment-container {
                max-width: 50mm;
                width: 50mm;
                padding: 2mm;
                margin: 0;
            }
        }

        .acknowledgment-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 20px;
            letter-spacing: 1px;
            padding-bottom: 10px;
        }

        .collector-info {
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .payment-instruction {
            text-align: center;
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
            margin: 20px 0;
            padding: 12px 0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            letter-spacing: 1px;
        }

        .details-section {
            margin: 25px 0;
            text-align: center;
        }

        .detail-row {
            text-align: left;
            margin: 1mm 6mm;
            font-size: 8px;
            display: flex;
            justify-content: space-between;
        }

        .detail-label {
            font-weight: normal;
            margin-right: 8px;
        }

        .detail-value {
            font-weight: bold;
        }

        .employee-info {
            margin: 20px 0;
            font-size: 14px;
            text-align: center;
        }

        .disclaimer {
            margin: 25px 0;
            font-size: 11px;
            line-height: 1.6;
            text-align: center;
        }

        .disclaimer p {
            margin: 4px 0;
        }

        .help-desk {
            margin-top: 25px;
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

            .acknowledgment-container {
                max-width: 50mm;
                width: 50mm;
                height: 80mm;
                padding: 2mm;
                margin: 0;
                box-shadow: none;
                display: block;
                page-break-inside: avoid;
                overflow: hidden;
            }

            .acknowledgment-title {
                font-size: 10px;
                margin-bottom: 0;
            }

            .collector-info {
                font-size: 8px;
                margin-bottom: 0;
            }

            .payment-instruction {
                margin: 2mm auto;
                width: 100%;
                font-size: 8px;
                line-height: 1.3;
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

            .employee-info {
                text-align: center;
                margin: 3mm 0;
                font-size: 8px;
            }

            .disclaimer {
                width: 100%;
                margin: 1mm 0;
                text-align: center;
                font-size: 7px;
                line-height: 1;
            }

            .disclaimer p {
                margin: 1mm 0;
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
    <div class="acknowledgment-container">
        <div class="acknowledgment-title">ACKNOWLEDGMENT</div>

        <div class="collector-info">
            COLLECTOR : Susantha Synergy<br>
            Solutions pvt LtdS
        </div>

        <div class="payment-instruction">
            --PLEASE DO PAYMENT IN EXIT--
        </div>

        <div class="details-section">
            <div class="detail-row">
                <span class="detail-label">Street :</span>
                <span class="detail-value"><?php echo htmlspecialchars($areaName); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Date :</span>
                <span class="detail-value"><?php echo $checkInDate; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Time :</span>
                <span class="detail-value"><?php echo $checkInTime; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Vehicle No :</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">V.Type :</span>
                <span class="detail-value"><?php echo $vehicleTypeCode; ?></span>
            </div>
        </div>

        <div class="employee-info">
            EMPN : <?php echo htmlspecialchars($employeeInfo); ?>
        </div>

        <div class="disclaimer">
            <p>* Charges are for ground rent only !</p>
            <p>Neither we nor the Bandarawela municipal Council are</p>
            <p>responsible for any loss or damage caused by</p>
            <p>parking vehicles at this location and the</p>
            <p>responsibility lies with the owner of the vehicle.</p>
            <p>If you are parking your vehicle for an extended</p>
            <p>period of time, please use the city's parking lot.</p>
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
