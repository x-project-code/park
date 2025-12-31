<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'Wp200302102105');
define('DB_NAME', 'parking_system_v2');

// Session timeout in seconds (1 hour)
define('SESSION_TIMEOUT', 100);

// Create database connection
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        // Check connection
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        // Set charset to utf8mb4 for proper character support
        $conn->set_charset("utf8mb4");
        
        return $conn;
    } catch (Exception $e) {
        die("Database connection error: " . $e->getMessage());
    }
}

// Close database connection
function closeDBConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}

// Vehicle Number Validation Function
function validateVehicleType($vehicleNumber) {
    // Clean the vehicle number
    $vehicleNumber = strtoupper(trim($vehicleNumber));
    
    // Extract parts BEFORE removing hyphens/spaces to preserve structure
    $letterPart = '';
    $numberPart = 0;
    
    // Pattern 1: Letter prefix + number (e.g., AAA-1234, Q-5678, CAA-1111)
    if (preg_match('/^([A-Z]+)[\s\-]*(\d+)/', $vehicleNumber, $matches)) {
        $letterPart = $matches[1];
        $numberPart = intval($matches[2]);
    } 
    // Pattern 2: Pure number format (e.g., 200-1234, 80-5678, 1-2345)
    // Extract ONLY the first number group (before hyphen)
    elseif (preg_match('/^(\d+)[\s\-]/', $vehicleNumber, $matches)) {
        $numberPart = intval($matches[1]);
    }
    // Pattern 3: Single number only (e.g., 200, 1, 80)
    elseif (preg_match('/^(\d+)$/', $vehicleNumber, $matches)) {
        $numberPart = intval($matches[1]);
    }
    
    // HEAVY (Transport) validation
    $heavyNumbers = [21,22,23,24,25,26,27,28,29,30,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,60,61,62,63,67,68,70,71,72,73,74,75,76,77,78,79,325];
    $heavyLetters = ['N', 'LY', 'LZ', 'RA', 'RB', 'RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RJ', 'RK', 'RL', 'RM', 'RN', 'RP', 'RQ', 'RR', 'RS', 'RT', 'RU', 'RV', 'RW', 'RX', 'RY', 'RZ'];
    
    if (in_array($numberPart, $heavyNumbers)) {
        return 'transport';
    }
    if (in_array($letterPart, $heavyLetters)) {
        return 'transport';
    }
    if (strlen($letterPart) > 0 && $letterPart[0] === 'D') {
        return 'transport';
    }
    
    // BIKE (Motorcycle) validation
    $bikeNumbers = range(80, 155);
    $bikeLetters = ['M', 'T', 'V'];
    
    if (in_array($numberPart, $bikeNumbers)) {
        return 'motorcycle';
    }
    if (in_array($letterPart, $bikeLetters)) {
        return 'motorcycle';
    }
    if (strlen($letterPart) > 0 && $letterPart[0] === 'B') {
        return 'motorcycle';
    }
    
    // THREEWHEEL validation
    $threewheelNumbers = range(200, 208);
    $threewheelLetters = ['Q', 'Y'];
    
    if (in_array($numberPart, $threewheelNumbers)) {
        return 'three_wheeler';
    }
    if (in_array($letterPart, $threewheelLetters)) {
        return 'three_wheeler';
    }
    if (strlen($letterPart) > 0 && $letterPart[0] === 'A') {
        return 'three_wheeler';
    }
    
    // CAR validation (Default and specific patterns)
    $carNumbers = array_merge(range(1, 20), [31, 32], range(50, 59), [64, 65], range(250, 254), range(300, 302));
    $carLetters = ['K', 'S', 'LW', 'PA', 'PB', 'PC', 'PD', 'PE', 'PF', 'PG', 'PH', 'PJ', 'PK', 'PL', 'PM', 'PN', 'PP', 'PQ', 'PR', 'PS', 'PT', 'PU', 'PV', 'PW', 'PX', 'PY'];
    
    if (in_array($numberPart, $carNumbers)) {
        return 'car';
    }
    if (in_array($letterPart, $carLetters)) {
        return 'car';
    }
    if (strlen($letterPart) > 0 && $letterPart[0] === 'C') {
        return 'car';
    }
    
    // Default to car if no pattern matches
    return 'car';
}

// Store vehicle validation warning
function storeValidationWarning($conn, $vehicleId, $vehicleNumber, $selectedType, $expectedType, $userId, $areaId, $checkInTime) {
    $vehicleNumber = $conn->real_escape_string($vehicleNumber);
    $selectedType = $conn->real_escape_string($selectedType);
    $expectedType = $conn->real_escape_string($expectedType);
    $checkInTime = $conn->real_escape_string($checkInTime);
    
    $warningMessage = "Vehicle type mismatch: Selected '$selectedType' but expected '$expectedType' based on vehicle number pattern.";
    $warningMessage = $conn->real_escape_string($warningMessage);
    
    $sql = "INSERT INTO vehicle_validation_warnings 
            (vehicle_id, vehicle_number, selected_type, expected_type, warning_message, user_id, area_id, check_in_time, status) 
            VALUES 
            ($vehicleId, '$vehicleNumber', '$selectedType', '$expectedType', '$warningMessage', $userId, $areaId, '$checkInTime', 'pending')";
    
    $conn->query($sql);
}

// Cleanup expired sessions
function cleanupExpiredSessions() {
    $conn = getDBConnection();
    
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Find sessions that haven't been updated in the last SESSION_TIMEOUT seconds
    $timeoutSeconds = SESSION_TIMEOUT;
    
    // Exclude current user's session from cleanup if they're logged in
    $excludeCurrentUser = '';
    if (isset($_SESSION['user_id']) && isset($_SESSION['area_id'])) {
        $currentUserId = intval($_SESSION['user_id']);
        $currentAreaId = intval($_SESSION['area_id']);
        $excludeCurrentUser = " AND NOT (users_id = $currentUserId AND areas_id = $currentAreaId)";
    }
    
    $cleanupSql = "UPDATE areas_has_users 
                   SET is_active = '0' 
                   WHERE is_active = '1' 
                   AND TIMESTAMPDIFF(SECOND, loging_date, NOW()) > $timeoutSeconds
                   $excludeCurrentUser";
    $conn->query($cleanupSql);
    
    closeDBConnection($conn);
}

// Check and cleanup session if expired
function checkSessionTimeout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // If session variables are not set, return true (let other checks handle it)
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['area_id'])) {
        return true;
    }
    
    // Initialize last_activity if not set
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    }
    
    // Check if session has expired
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        // Session expired, cleanup
        $conn = getDBConnection();
        $userId = intval($_SESSION['user_id']);
        $areaId = intval($_SESSION['area_id']);
        
        $deactivateSql = "UPDATE areas_has_users 
                          SET is_active = '0' 
                          WHERE users_id = $userId 
                          AND areas_id = $areaId 
                          AND is_active = '1'";
        $conn->query($deactivateSql);
        closeDBConnection($conn);
        
        // Destroy session
        session_destroy();
        return false;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    // Update loging_date in database to prevent expiration on refresh
    // Also ensure is_active is '1' in case it was accidentally set to '0'
    $conn = getDBConnection();
    $userId = intval($_SESSION['user_id']);
    $areaId = intval($_SESSION['area_id']);
    
    // Update loging_date to current time to keep session active
    $updateDateSql = "UPDATE areas_has_users 
                     SET loging_date = NOW(), is_active = '1' 
                     WHERE users_id = $userId 
                     AND areas_id = $areaId";
    $updateResult = $conn->query($updateDateSql);
    
    // If update didn't affect any rows, the record might not exist - this is a problem
    if ($conn->affected_rows == 0) {
        // Try to check if record exists at all
        $checkSql = "SELECT id FROM areas_has_users 
                     WHERE users_id = $userId 
                     AND areas_id = $areaId";
        $checkResult = $conn->query($checkSql);
        if (!$checkResult || $checkResult->num_rows == 0) {
            // Record doesn't exist - session is invalid
            closeDBConnection($conn);
            session_destroy();
            return false;
        }
    }
    
    closeDBConnection($conn);
    
    return true;
}
?>

