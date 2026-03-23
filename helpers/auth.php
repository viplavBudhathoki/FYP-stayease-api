<?php
include 'connection.php';

function getUserIdByToken($token)
{
    global $con;

    $sql = "SELECT user_id FROM tokens WHERE token = ?";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['user_id'];
    }

    return null;
}

function isVendor($token)
{
    global $con;

    $user_id = getUserIdByToken($token);

    if (!$user_id) {
        return false;
    }

    $sql = "SELECT role FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['role'] === 'vendor';
    }

    return false;
}

function isAdmin($token)
{
    global $con;

    $user_id = getUserIdByToken($token);

    if (!$user_id) {
        return false;
    }

    $sql = "SELECT role FROM users WHERE user_id = ?";
    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['role'] === 'admin';
    }

    return false;
}