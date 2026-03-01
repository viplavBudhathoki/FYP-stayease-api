<?php
// Include the database connection file to access $con
include 'connection.php';

// Function to get user ID from a token
function getUserIdByToken($token)
{
    global $con; // Use the global database connection

    // Query to find user_id associated with the given token
    $sql = "SELECT user_id FROM tokens WHERE token ='$token'";

    // Execute the query
    $result = mysqli_query($con, $sql);

    // If a row is found, return the user_id
    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result); // Fetch the row as associative array
        return $row['user_id']; // Return the user_id
    }

    // If no user found for the token, return null
    return null;
}

// Function to check if a user is a vendor
function isVendor($token){
    global $con; // Use global DB connection

    // Get user_id using token
    $user_id = getUserIdByToken($token);

    // If token is invalid or user not found, return false
    if (!$user_id) {
        return false;
    }

    // Query to get user details by user_id
    $sql = "SELECT * FROM users WHERE user_id = '$user_id'";

    // Execute the query
    $result = mysqli_query($con, $sql);

    // If a user is found, check if the role is 'vendor'
    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result); // Fetch user data
        return $row['role'] == 'vendor';   // Return true if role is vendor
    }

    // If no user found, return false
    return false;
}

// Function to check if a user is an admin
function isAdmin($token)
{
    global $con; // Use global DB connection

    // Get user_id using token
    $user_id = getUserIdByToken($token);

    // If token invalid or user not found, return false
    if (!$user_id) {    
        return false;
    }

    // Query to get user details by user_id
    $sql = "SELECT * FROM users WHERE user_id ='$user_id'";

    // Execute the query
    $result = mysqli_query($con, $sql);

    // If user found, check if role is 'admin'
    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result); // Fetch user data
        return $row['role'] == 'admin';     // Return true if role is admin
    }

    // If no user found, return false
    return false;
}
