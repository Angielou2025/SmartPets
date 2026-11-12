<?php
// Koneksyon sa database
$conn = new mysqli("localhost", "root", "", "a_smart_pet");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Admin Credentials
$username = 'Sraff';
$password = 'Staff123@'; // Palitan ito ng gusto mong password
$email = 'staff@gmail.com';
$role = 'Staff';

// I-hash ang password para maging compatible sa password_verify() ng PHP
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// Prepare Statement
$stmt = $conn->prepare("INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $username, $hashed_password, $email, $role);

if ($stmt->execute()) {
    echo "Successfully created Admin account!<br>";
    echo "<strong>Username:</strong> " . $username . "<br>";
    echo "<strong>Password:</strong> " . $password;
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>