<?php
require_once 'config.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn = getDBConnection();

// If user is logged in, deactivate their area assignment
if (isset($_SESSION['user_id']) && isset($_SESSION['area_id'])) {
    $userId = intval($_SESSION['user_id']);
    $areaId = intval($_SESSION['area_id']);
    
    // Deactivate the user's area assignment
    $deactivateSql = "UPDATE areas_has_users SET is_active = '0' WHERE users_id = $userId AND areas_id = $areaId AND is_active = '1'";
    $conn->query($deactivateSql);
}

// Destroy session
session_destroy();

closeDBConnection($conn);

// Redirect to login page
header("Location: login.php");
exit();
?>

