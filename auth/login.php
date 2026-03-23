<?php

include '../helpers/connection.php';

if (isset($_POST['email'], $_POST['password'])) {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($email === '' || $password === '') {
        echo json_encode([
            "success" => false,
            "message" => "Email and password are required"
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

    if (mysqli_num_rows($result) == 0) {
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        die();
    }

    $user = mysqli_fetch_assoc($result);
    $hashed_password = $user['password'];

    $is_password_correct = password_verify($password, $hashed_password);

    if (!$is_password_correct) {
        echo json_encode([
            "success" => false,
            "message" => "Wrong password"
        ]);
        die();
    }

    $token = bin2hex(random_bytes(32));
    $user_id = $user['user_id'];

    $sql = "INSERT INTO tokens (token, user_id) VALUES (?, ?)";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare token query"
        ]);
        die();
    }

    mysqli_stmt_bind_param($stmt, "si", $token, $user_id);
    $result = mysqli_stmt_execute($stmt);

    if (!$result) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to insert token (query error)"
        ]);
        die();
    }

    echo json_encode([
        "success" => true,
        "message" => "User logged in successfully",
        "token" => $token,
        "user" => [
            "user_id" => $user["user_id"],
            "full_name" => $user["full_name"],
            "email" => $user["email"],
            "role" => $user["role"]
        ]
    ]);
    die();

} else {
    echo json_encode([
        "success" => false,
        "message" => "Email and password are required"
    ]);
    die();
}