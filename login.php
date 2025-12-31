<?php
require_once 'config.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to checkin
if (isset($_SESSION['user_id']) && isset($_SESSION['area_id'])) {
    header("Location: checkin.php");
    exit();
}

$conn = getDBConnection();
$error = '';
$success = '';

// Check for success message from redirect
if (isset($_GET['cleared']) && isset($_GET['user'])) {
    $success = "User '" . htmlspecialchars($_GET['user']) . "' area(s) cleared successfully";
}

// Handle clear area form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clear_area']) && isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($password)) {
        // Sanitize username
        $username = $conn->real_escape_string($username);
        
        // Check if user exists and password matches
        $userSql = "SELECT id, username, status FROM users WHERE username = '$username' AND password = '$password' AND status = 'active'";
        $userResult = $conn->query($userSql);
        
        if ($userResult && $userResult->num_rows > 0) {
            $user = $userResult->fetch_assoc();
            $userId = $user['id'];
            
            // Deactivate all active areas for this user
            $clearSql = "UPDATE areas_has_users SET is_active = '0' WHERE users_id = $userId AND is_active = '1'";
            $clearResult = $conn->query($clearSql);
            
            if ($clearResult) {
                $affectedRows = $conn->affected_rows;
                if ($affectedRows > 0) {
                    $success = "User '{$user['username']}' area(s) cleared successfully";
                    // Reload areas list after clearing
                    header("Location: login.php?cleared=1&user=" . urlencode($user['username']));
                    exit();
                } else {
                    $error = "User '{$user['username']}' has no active area(s)";
                }
            } else {
                $error = "Error clearing area: " . $conn->error;
            }
        } else {
            $error = "Invalid username or password";
        }
    } else {
        $error = "Please enter username and password";
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username']) && isset($_POST['password']) && !isset($_POST['clear_area'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $areaId = isset($_POST['area_id']) ? intval($_POST['area_id']) : 0;
    
    if (!empty($username) && !empty($password) && $areaId > 0) {
        // Sanitize username
        $username = $conn->real_escape_string($username);
        
        // Check if user exists and password matches
        $userSql = "SELECT id, username, status FROM users WHERE username = '$username' AND password = '$password' AND status = 'active'";
        $userResult = $conn->query($userSql);
        
        if ($userResult && $userResult->num_rows > 0) {
            $user = $userResult->fetch_assoc();
            $userId = $user['id'];
            
            // Check if area exists and is active
            $areaSql = "SELECT id, area_code, area_name FROM areas WHERE id = $areaId AND status = 'active'";
            $areaResult = $conn->query($areaSql);
            
            if ($areaResult && $areaResult->num_rows > 0) {
                // Check if area is already assigned to another active user
                $checkAreaSql = "SELECT users_id FROM areas_has_users WHERE areas_id = $areaId AND is_active = '1' AND users_id != $userId";
                $checkAreaResult = $conn->query($checkAreaSql);
                
                if ($checkAreaResult && $checkAreaResult->num_rows > 0) {
                    $error = "This area is already being used by another user";
                } else {
                    // Deactivate any previous active logins for this user
                    $deactivateSql = "UPDATE areas_has_users SET is_active = '0' WHERE users_id = $userId AND is_active = '1'";
                    $conn->query($deactivateSql);
                    
                    // Check if there's an existing record for this user-area combination
                    $existingSql = "SELECT id FROM areas_has_users WHERE users_id = $userId AND areas_id = $areaId";
                    $existingResult = $conn->query($existingSql);
                    
                    if ($existingResult && $existingResult->num_rows > 0) {
                        // Update existing record
                        $updateSql = "UPDATE areas_has_users SET is_active = '1', loging_date = NOW() WHERE users_id = $userId AND areas_id = $areaId";
                        $conn->query($updateSql);
                    } else {
                        // Insert new record
                        $insertSql = "INSERT INTO areas_has_users (areas_id, users_id, loging_date, is_active) VALUES ($areaId, $userId, NOW(), '1')";
                        $conn->query($insertSql);
                    }
                    
                    // Set session variables
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['area_id'] = $areaId;
                    
                    // Redirect to checkin page
                    header("Location: checkin.php");
                    exit();
                }
            } else {
                $error = "Invalid area selected";
            }
        } else {
            $error = "Invalid username or password";
        }
    } else {
        if (empty($username) || empty($password)) {
            $error = "Please enter username and password";
        } else if ($areaId == 0) {
            $error = "Please select an area";
        }
    }
}

// Load active areas from database
$areas = [];
$areasSql = "SELECT * FROM areas WHERE status = 'active' ORDER BY area_code";
$areasResult = $conn->query($areasSql);
if ($areasResult && $areasResult->num_rows > 0) {
    while ($row = $areasResult->fetch_assoc()) {
        // Check if area is already assigned to an active user
        $checkActiveSql = "SELECT users_id, u.username FROM areas_has_users ahu 
                          JOIN users u ON ahu.users_id = u.id 
                          WHERE ahu.areas_id = {$row['id']} AND ahu.is_active = '1'";
        $checkActiveResult = $conn->query($checkActiveSql);
        
        if ($checkActiveResult && $checkActiveResult->num_rows > 0) {
            $activeUser = $checkActiveResult->fetch_assoc();
            $row['is_locked'] = true;
            $row['locked_by'] = $activeUser['username'];
        } else {
            $row['is_locked'] = false;
            $row['locked_by'] = null;
        }
        
        $areas[] = $row;
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="si">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Login - Parking System</title>
    <link rel="stylesheet" href="style.css">
    <?php include 'protection.php'; ?>
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0F2854;
        }
        
        .login-box {
            background: #1C4D8D;
            border: 3px solid #4988C4;
            border-radius: 15px;
            padding: clamp(25px, 5vw, 40px);
            width: 100%;
            max-width: 450px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }
        
        .login-title {
            text-align: center;
            font-size: clamp(24px, 6vw, 32px);
            font-weight: 700;
            color: #BDE8F5;
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            color: #BDE8F5;
            font-size: clamp(14px, 3.5vw, 16px);
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: clamp(12px, 3vw, 16px);
            background: #0F2854;
            border: 2px solid #4988C4;
            border-radius: 8px;
            color: #BDE8F5;
            font-size: clamp(16px, 4vw, 18px);
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #BDE8F5;
            box-shadow: 0 0 0 3px rgba(189, 232, 245, 0.2);
        }
        
        .form-select {
            width: 100%;
            padding: clamp(12px, 3vw, 16px);
            background: #0F2854;
            border: 2px solid #4988C4;
            border-radius: 8px;
            color: #BDE8F5;
            font-size: clamp(16px, 4vw, 18px);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23BDE8F5' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 40px;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #BDE8F5;
            box-shadow: 0 0 0 3px rgba(189, 232, 245, 0.2);
        }
        
        .form-select option {
            background: #0F2854;
            color: #BDE8F5;
            padding: 10px;
        }
        
        .form-select option:disabled {
            color: #f44336;
            font-style: italic;
        }
        
        .login-btn {
            width: 100%;
            padding: clamp(14px, 3.5vw, 18px);
            background: #4988C4;
            border: 2px solid #4988C4;
            border-radius: 10px;
            color: #FFFDE1;
            font-size: clamp(18px, 4.5vw, 22px);
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 10px;
        }
        
        .login-btn:hover {
            background: #1C4D8D;
            border-color: #BDE8F5;
        }
        
        .login-btn:active {
            transform: scale(0.98);
        }
        
        .error-message {
            background: #b71c1c;
            color: #ef5350;
            border: 1px solid #f44336;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease-out;
        }
        
        .area-locked-info {
            font-size: 12px;
            color: #f44336;
            margin-top: 5px;
            font-style: italic;
        }
        
        .success-message {
            background: #1b5e20;
            color: #81c784;
            border: 1px solid #4caf50;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease-out;
        }
        
        .form-buttons {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 10px;
        }
        
        .form-buttons .login-btn {
            width: 100%;
        }
        
        .clear-area-btn {
            width: 100%;
            padding: clamp(14px, 3.5vw, 18px);
            background: #f44336;
            border: 2px solid #f44336;
            border-radius: 10px;
            color: #FFFDE1;
            font-size: clamp(18px, 4.5vw, 22px);
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .clear-area-btn:hover {
            background: #d32f2f;
            border-color: #d32f2f;
        }
        
        .clear-area-btn:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1 class="login-title">Login</h1>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-input" 
                        required 
                        autocomplete="username"
                        placeholder="Enter username"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input" 
                        required 
                        autocomplete="current-password"
                        placeholder="Enter password"
                    >
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="area_id">Select Area</label>
                    <select id="area_id" name="area_id" class="form-select">
                        <option value="">-- Select Area --</option>
                        <?php foreach ($areas as $area): ?>
                            <option 
                                value="<?php echo $area['id']; ?>" 
                                <?php echo $area['is_locked'] ? 'disabled' : ''; ?>
                                <?php echo (isset($_POST['area_id']) && $_POST['area_id'] == $area['id']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($area['area_name']); ?>
                                <?php if ($area['is_locked']): ?>
                                    (Locked by <?php echo htmlspecialchars($area['locked_by']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php 
                    $selectedArea = null;
                    if (isset($_POST['area_id'])) {
                        foreach ($areas as $area) {
                            if ($area['id'] == $_POST['area_id'] && $area['is_locked']) {
                                $selectedArea = $area;
                                break;
                            }
                        }
                    }
                    if ($selectedArea): 
                    ?>
                        <div class="area-locked-info">
                            This area is currently locked by <?php echo htmlspecialchars($selectedArea['locked_by']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-buttons">
                    <button type="submit" name="login" class="login-btn">Login</button>
                    <button type="submit" name="clear_area" value="1" class="clear-area-btn" id="clear-area-btn">Clear Area</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Handle Clear Area button - remove required from area field
        document.getElementById('clear-area-btn').addEventListener('click', function(e) {
            const areaSelect = document.getElementById('area_id');
            areaSelect.removeAttribute('required');
        });
        
        // Handle Login button - add required to area field
        document.querySelector('button[name="login"]').addEventListener('click', function(e) {
            const areaSelect = document.getElementById('area_id');
            areaSelect.setAttribute('required', 'required');
        });
    </script>
</body>
</html>

