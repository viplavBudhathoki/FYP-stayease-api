<?php

include '../helpers/connection.php';

if (isset($_POST['email'], $_POST['password'], $_POST['full_name'])) {

    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);

    if ($email === '' || $password === '' || $full_name === '') {
        echo json_encode([
            "success" => false,
            "message" => "Email, password and full_name are required"
        ]);
        die();
    }

    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare query"
        ]);
        die();
    }

    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        echo json_encode([
            "success" => false,
            "message" => "Error in query"
        ]);
        die();
    }

    if (mysqli_num_rows($result) > 0) {
        echo json_encode([
            "success" => false,
            "message" => "Email is already registered"
        ]);
        die();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (email, password, full_name, role) VALUES (?, ?, ?, 'user')";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare insert query"
        ]);
        die();
    }

    mysqli_stmt_bind_param($stmt, "sss", $email, $hashed_password, $full_name);
    $result = mysqli_stmt_execute($stmt);

    if (!$result) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to register (query error)"
        ]);
        die();
    }

    echo json_encode([
        "success" => true,
        "message" => "User registered successfully"
    ]);
    die();

} else {
    echo json_encode([
        "success" => false,
        "message" => "Email, password and full_name are required"
    ]);
    die();
}