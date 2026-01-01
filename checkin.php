<?php
require_once 'config.php';

// Start session for user management (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in, redirect to login if not
if (!isset($_SESSION['user_id']) || !isset($_SESSION['area_id'])) {
    header("Location: login.php");
    exit();
}

$currentUserId = intval($_SESSION['user_id']);
$currentAreaId = intval($_SESSION['area_id']);
$currentUsername = $_SESSION['username'];

$conn = getDBConnection();

// Verify that user is still active in the assigned area
$verifySql = "SELECT ahu.id FROM areas_has_users ahu 
              WHERE ahu.users_id = $currentUserId 
              AND ahu.areas_id = $currentAreaId 
              AND ahu.is_active = '1'";
$verifyResult = $conn->query($verifySql);

if (!$verifyResult || $verifyResult->num_rows == 0) {
    // User is no longer active in this area, redirect to login
    session_destroy();
    header("Location: login.php");
    exit();
}

// Get current area information
$areaSql = "SELECT * FROM areas WHERE id = $currentAreaId";
$areaResult = $conn->query($areaSql);
$currentArea = null;
if ($areaResult && $areaResult->num_rows > 0) {
    $currentArea = $areaResult->fetch_assoc();
}

// Load vehicle types from database
$vehicleTypes = [];
$typesSql = "SELECT * FROM vehicle_types WHERE status = 'active' ORDER BY id";
$typesResult = $conn->query($typesSql);
if ($typesResult && $typesResult->num_rows > 0) {
    while ($row = $typesResult->fetch_assoc()) {
        $vehicleTypes[] = $row;
    }
}

$message = '';
$messageType = '';
$selectedVehicleType = isset($_POST['vehicle_type_id']) ? intval($_POST['vehicle_type_id']) : 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['vehicle_number'])) {
    $vehicleNumber = trim($_POST['vehicle_number']);
    $vehicleTypeId = isset($_POST['vehicle_type_id']) ? intval($_POST['vehicle_type_id']) : 0;
    
    if (!empty($vehicleNumber) && $vehicleTypeId > 0) {
        // Sanitize input
        $vehicleNumber = $conn->real_escape_string($vehicleNumber);
        
        // Verify user is still active in the area
        $verifyActiveSql = "SELECT id FROM areas_has_users 
                           WHERE users_id = $currentUserId 
                           AND areas_id = $currentAreaId 
                           AND is_active = '1'";
        $verifyActiveResult = $conn->query($verifyActiveSql);
        
        if (!$verifyActiveResult || $verifyActiveResult->num_rows == 0) {
            $message = "You are no longer logged into this area. Please login again.";
            $messageType = 'error';
        } else {
            // Get current time in Sri Lanka timezone
            date_default_timezone_set('Asia/Colombo');
            $checkInTime = new DateTime('now', new DateTimeZone('Asia/Colombo'));
            $checkInTimeFormatted = $checkInTime->format('Y-m-d H:i:s');
            $currentTimeFormatted = $checkInTime->format('Y-m-d H:i:s');
            
            // Insert vehicle into database using foreign keys with Sri Lanka time
            $sql = "INSERT INTO vehicles (vehicle_number, vehicle_type_id, area_id, user_id, check_in_time, status, created_at, updated_at) 
                    VALUES ('$vehicleNumber', $vehicleTypeId, $currentAreaId, $currentUserId, '$checkInTimeFormatted', 'parked', '$currentTimeFormatted', '$currentTimeFormatted')";
            
            if ($conn->query($sql) === TRUE) {
                $newVehicleId = $conn->insert_id;
                
                // Validate vehicle type
                $expectedTypeCode = validateVehicleType($vehicleNumber);
                
                // Get selected type code
                $selectedTypeCode = '';
                foreach ($vehicleTypes as $vt) {
                    if ($vt['id'] == $vehicleTypeId) {
                        $selectedTypeCode = $vt['type_code'];
                        break;
                    }
                }
                
                // Debug log (remove after testing)
                error_log("Vehicle: $vehicleNumber | Selected: $selectedTypeCode | Expected: $expectedTypeCode");
                
                // If types don't match, store warning (but don't stop check-in)
                if ($expectedTypeCode !== $selectedTypeCode) {
                    // Get type names for better readability
                    $typeNames = [
                        'car' => 'Car',
                        'three_wheeler' => 'Three Wheeler',
                        'motorcycle' => 'Motorcycle',
                        'transport' => 'Transport'
                    ];
                    $selectedTypeName = isset($typeNames[$selectedTypeCode]) ? $typeNames[$selectedTypeCode] : $selectedTypeCode;
                    $expectedTypeName = isset($typeNames[$expectedTypeCode]) ? $typeNames[$expectedTypeCode] : $expectedTypeCode;
                    
                    storeValidationWarning($conn, $newVehicleId, $vehicleNumber, $selectedTypeName, $expectedTypeName, $currentUserId, $currentAreaId, $checkInTimeFormatted);
                }
                
                // Redirect to acknowledgment page
                header("Location: acknowledgment.php?id=" . $newVehicleId);
                exit();
            } else {
                $message = "Error: " . $conn->error;
                $messageType = 'error';
            }
        }
    } else {
        if (empty($vehicleNumber)) {
            $message = "Please enter vehicle number";
        } else if ($vehicleTypeId == 0) {
            $message = "Please select vehicle type";
        }
        $messageType = 'error';
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Success";
    $messageType = 'success';
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Vehicle Parking - Check In</title>
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

    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <span class="app-title"><?php echo htmlspecialchars($currentUsername); ?></span>
            <a href="logout.php" class="logout-btn" id="logout-btn" title="Logout">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </a>
        </div>
        <div class="header-right">
            <div style="display: flex; flex-direction: column; align-items: flex-end;">
                <span style="color:rgb(203, 210, 218); font-size: clamp(14px, 3.5vw, 18px); font-weight: 600;">
                    <?php echo $currentArea ? htmlspecialchars($currentArea['area_name']) : 'N/A'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Area Display -->

    <!-- Vehicle Number Display - Split into Letters and Numbers -->
    <div class="vehicle-display-container">
        <div class="input-group">
            <div style="display: flex; align-items: center; gap: 8px; ">
                <label class="input-label" id="first-field-label">LETTERS</label>
                <a type="button" id="swap-mode-btn" class="swap-btn" title="Switch to Numbers">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M8 3L4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4"/>
                    </svg>
                </a>
            </div>
            <input 
                type="text" 
                id="vehicle_letters" 
                class="vehicle-display vehicle-display-letters"
                readonly
                placeholder="ABC"
                maxlength="3"
            >
        </div>
        <div class="input-group">
            <label class="input-label">NUMBERS</label>
            <input 
                type="text" 
                id="vehicle_numbers" 
                class="vehicle-display vehicle-display-numbers"
                readonly
                placeholder="1234"
                maxlength="3"
            >
        </div>
    </div>

    <!-- Message Display -->
    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Keyboard Container - Both Keyboards Visible -->
    <div class="keyboard-container">
        <!-- Letters/Numbers Keyboard (First Field) -->
        <div class="keyboard-section">
            <div class="keyboard letters-keyboard" id="letters-keyboard">
            <div class="keyboard-row">
                <button class="key-btn" data-key="A">A</button>
                <button class="key-btn" data-key="B">B</button>
                <button class="key-btn" data-key="C">C</button>
                <button class="key-btn" data-key="D">D</button>
                <button class="key-btn" data-key="E">E</button>
                <button class="key-btn" data-key="F">F</button>
            </div>
            <div class="keyboard-row">
                <button class="key-btn" data-key="G">G</button>
                <button class="key-btn" data-key="H">H</button>
                <button class="key-btn" data-key="I">I</button>
                <button class="key-btn" data-key="J">J</button>
                <button class="key-btn" data-key="K">K</button>
                <button class="key-btn" data-key="L">L</button>
            </div>
            <div class="keyboard-row">
                <button class="key-btn" data-key="M">M</button>
                <button class="key-btn" data-key="N">N</button>
                <button class="key-btn" data-key="O">O</button>
                <button class="key-btn" data-key="P">P</button>
                <button class="key-btn" data-key="Q">Q</button>
                <button class="key-btn" data-key="R">R</button>
            </div>
            <div class="keyboard-row">
                <button class="key-btn" data-key="S">S</button>
                <button class="key-btn" data-key="T">T</button>
                <button class="key-btn" data-key="U">U</button>
                <button class="key-btn" data-key="V">V</button>
                <button class="key-btn" data-key="W">W</button>
                <button class="key-btn" data-key="X">X</button>
            </div>
            <div class="keyboard-row">
                <button class="key-btn" data-key="Y">Y</button>
                <button class="key-btn" data-key="Z">Z</button>
                <button class="key-btn delete-btn" data-action="delete-letters">✕</button>
            </div>
        </div>

        <!-- Numbers Keyboard -->
        <div class="keyboard-section" style="margin-top: 20px;">
            <div class="keyboard numbers-keyboard" id="numbers-keyboard">
            <div class="keyboard-row">
                <button class="key-btn" data-key="0">0</button>
                <button class="key-btn" data-key="1">1</button>
                <button class="key-btn" data-key="2">2</button>
                <button class="key-btn" data-key="3">3</button>
                <button class="key-btn" data-key="4">4</button>
                <button class="key-btn" data-key="5">5</button>
            </div>
            <div class="keyboard-row keyboard-row-numbers">
                <button class="key-btn" data-key="6">6</button>
                <button class="key-btn" data-key="7">7</button>
                <button class="key-btn" data-key="8">8</button>
                <button class="key-btn" data-key="9">9</button>
                <button class="key-btn delete-btn" data-action="delete">✕</button>
            </div>
        </div>
    </div>

    <!-- Vehicle Type Selection -->
    <div class="vehicle-type-container">
        <?php foreach ($vehicleTypes as $type): ?>
            <button class="vehicle-type-btn" 
                    data-type-id="<?php echo $type['id']; ?>" 
                    data-type-code="<?php echo $type['type_code']; ?>"
                    id="vehicle-type-<?php echo $type['id']; ?>-btn">
                <img src="<?php echo htmlspecialchars($type['icon_path']); ?>" 
                     alt="<?php echo htmlspecialchars($type['type_name']); ?>" 
                     class="vehicle-icon">
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Action Buttons -->
    <form method="POST" action="checkin.php" id="checkin-form" class="action-buttons">
        <input type="hidden" name="vehicle_number" id="vehicle_number_input">
        <input type="hidden" name="vehicle_type_id" id="vehicle_type_id_input" value="0">
        
        <button type="button" class="action-btn in-btn" id="in-btn">IN</button>
        <a href="exit.php" class="action-btn exit-btn">EXIT</a>
    </form>

    <style>
        .swap-btn {
            color:rgb(242, 244, 245);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            margin-bottom: 3px;
        }
    </style>

    <script>
        // Get input fields
        const vehicleLetters = document.getElementById('vehicle_letters');
        const vehicleNumbers = document.getElementById('vehicle_numbers');
        const vehicleNumberInput = document.getElementById('vehicle_number_input');
        const vehicleTypeIdInput = document.getElementById('vehicle_type_id_input');
        const swapModeBtn = document.getElementById('swap-mode-btn');
        const firstFieldLabel = document.getElementById('first-field-label');
        const lettersKeyboard = document.getElementById('letters-keyboard');
        let selectedVehicleTypeId = 0;
        let isNumbersMode = false; // false = letters mode, true = numbers mode

        // Function to update combined vehicle number
        function updateVehicleNumber() {
            const letters = vehicleLetters.value.trim();
            const numbers = vehicleNumbers.value.trim();
            const combined = letters + (letters && numbers ? '-' : '') + numbers;
            vehicleNumberInput.value = combined;
        }

        // Swap mode button
        swapModeBtn.addEventListener('click', function() {
            isNumbersMode = !isNumbersMode;
            
            if (isNumbersMode) {
                // Switch to numbers mode
                firstFieldLabel.textContent = 'NUMBERS';
                vehicleLetters.placeholder = '123';
                vehicleLetters.maxLength = 3;
                swapModeBtn.classList.add('numbers-mode');
                swapModeBtn.title = 'Switch to Letters';
                // Hide letters keyboard, show numbers keyboard for first field
                lettersKeyboard.style.display = 'none';
                // Auto-select first field when switching to numbers mode
                activeField = 'first';
                vehicleLetters.style.borderColor = '#BDE8F5';
                vehicleNumbers.style.borderColor = '#4988C4';
            } else {
                // Switch to letters mode
                firstFieldLabel.textContent = 'LETTERS';
                vehicleLetters.placeholder = 'ABC';
                vehicleLetters.maxLength = 3;
                swapModeBtn.classList.remove('numbers-mode');
                swapModeBtn.title = 'Switch to Numbers';
                lettersKeyboard.style.display = 'block';
                // Auto-select second field when switching to letters mode
                activeField = 'second';
                vehicleNumbers.style.borderColor = '#BDE8F5';
                vehicleLetters.style.borderColor = '#4988C4';
            }
            
            // Clear first field when switching modes
            vehicleLetters.value = '';
            updateVehicleNumber();
        });
        
        // Letters keyboard input (only active in letters mode)
        document.querySelectorAll('#letters-keyboard .key-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (isNumbersMode) return; // Ignore if in numbers mode
                
                if (this.dataset.action === 'delete-letters') {
                    // Delete last character from letters
                    vehicleLetters.value = vehicleLetters.value.slice(0, -1);
                } else if (this.dataset.key) {
                    // Add letter only if less than 3 characters
                    if (vehicleLetters.value.length < 3) {
                        const key = this.dataset.key;
                        vehicleLetters.value += key;
                    }
                }
                updateVehicleNumber();
            });
        });

        // Track which field is currently active
        let activeField = 'second'; // 'first' or 'second'
        
        // Make fields clickable to set active field
        vehicleLetters.addEventListener('click', function() {
            activeField = 'first';
            // Highlight active field
            vehicleLetters.style.borderColor = '#BDE8F5';
            vehicleNumbers.style.borderColor = '#4988C4';
        });
        
        vehicleNumbers.addEventListener('click', function() {
            activeField = 'second';
            // Highlight active field
            vehicleNumbers.style.borderColor = '#BDE8F5';
            vehicleLetters.style.borderColor = '#4988C4';
        });
        
        
        // Numbers keyboard input (works for both first and second field based on active field)
        document.querySelectorAll('#numbers-keyboard .key-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.dataset.action === 'delete') {
                    if (activeField === 'first' && vehicleLetters.value.length > 0) {
                        // Delete from first field
                        vehicleLetters.value = vehicleLetters.value.slice(0, -1);
                    } else if (activeField === 'second' && vehicleNumbers.value.length > 0) {
                        // Delete from second field
                    vehicleNumbers.value = vehicleNumbers.value.slice(0, -1);
                    }
                } else if (this.dataset.key) {
                    if (activeField === 'first') {
                        // Add to first field
                        if (isNumbersMode) {
                            // Numbers mode: allow numbers in first field (max 3)
                            if (vehicleLetters.value.length < 3) {
                                const key = this.dataset.key;
                                vehicleLetters.value += key;
                            }
                        } else {
                            // Letters mode: first field should use letters keyboard, not numbers
                            // This shouldn't happen, but just in case
                            return;
                        }
                    } else {
                        // Add to second field (always numbers, max 4)
                    if (vehicleNumbers.value.length < 4) {
                        const key = this.dataset.key;
                        vehicleNumbers.value += key;
                        }
                    }
                }
                updateVehicleNumber();
            });
        });

        // Vehicle type selection
        document.querySelectorAll('.vehicle-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.vehicle-type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                selectedVehicleTypeId = parseInt(this.dataset.typeId);
                vehicleTypeIdInput.value = selectedVehicleTypeId;
            });
        });

        // IN button
        document.getElementById('in-btn').addEventListener('click', function() {
            const firstField = vehicleLetters.value.trim();
            const secondField = vehicleNumbers.value.trim();
            
            if (firstField === '' && secondField === '') {
                alert('Please enter vehicle number');
                return;
            }
            
            // Validate based on mode
            if (isNumbersMode) {
                // Numbers mode: max 3 numbers in first field, max 4 numbers in second field
                if (firstField.length > 3) {
                    alert('Maximum 3 numbers allowed in first field');
                    return;
                }
                if (secondField.length > 4) {
                    alert('Maximum 4 numbers allowed in second field');
                    return;
                }
            } else {
                // Letters mode: max 3 letters in first field, max 4 numbers in second field
                if (firstField.length > 3) {
                alert('Maximum 3 letters allowed');
                return;
            }
                if (secondField.length > 4) {
                alert('Maximum 4 numbers allowed');
                return;
                }
            }
            
            if (selectedVehicleTypeId === 0) {
                alert('Please select vehicle type');
                return;
            }
            
            // Update vehicle number before submit
            updateVehicleNumber();
            
            document.getElementById('checkin-form').submit();
        });

        // Logout button - already handled by link, but add confirmation
        document.getElementById('logout-btn').addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to logout?')) {
                e.preventDefault();
            }
        });

        // Auto-hide success message and clear inputs
        setTimeout(function() {
            const message = document.querySelector('.message.success');
            if (message) {
                message.style.opacity = '0';
                setTimeout(() => {
                    message.remove();
                    vehicleLetters.value = '';
                    vehicleNumbers.value = '';
                    vehicleNumberInput.value = '';
                }, 300);
            }
        }, 3000);
    </script>
</body>
</html>
