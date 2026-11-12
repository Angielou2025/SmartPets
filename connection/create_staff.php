<?php
require '../connection/database.php';

$username = 'staff_admin';
$email = 'staff@gmail.com';
$password = 'Staff123@'; // Password requirement: Min 8 chars, 1 Upper, 1 Special
$role = 'Staff';

// Hash the password using BCRYPT
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// Check if email already exists
$checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
$checkStmt->execute([$email]);

if ($checkStmt->rowCount() > 0) {
    echo "May umiiral nang account gamit ang email na ito!";
} else {
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role, login_attempts) 
        VALUES (?, ?, ?, ?, 0)
    ");
    
    if ($stmt->execute([$username, $email, $hashed_password, $role])) {
        echo "Successfully created Staff Account!<br>";
        echo "Email: " . $email . "<br>";
        echo "Password: " . $password;
    } else {
        echo "Failed to create staff account.";
    }
}
?>