<?php

function createNotification(
    mysqli $con,
    int $userId,
    string $title,
    string $message,
    string $type = 'system',
    ?int $relatedId = null
): bool {
    $allowedTypes = [
        'booking',
        'booking_update',
        'booking_cancel',
        'check_in',
        'check_out',
        'payment',
        'message',
        'offer',
        'review',
        'system'
    ];

    if ($userId <= 0 || trim($title) === '' || trim($message) === '') {
        return false;
    }

    if (!in_array($type, $allowedTypes, true)) {
        $type = 'system';
    }

    $title = trim($title);
    $message = trim($message);

    $sql = "
        INSERT INTO notifications (
            user_id,
            title,
            message,
            type,
            related_id
        )
        VALUES (?, ?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "isssi",
        $userId,
        $title,
        $message,
        $type,
        $relatedId
    );

    $executed = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $executed;
}

function createNotificationForMany(
    mysqli $con,
    array $userIds,
    string $title,
    string $message,
    string $type = 'system',
    ?int $relatedId = null
): bool {
    $success = true;

    foreach ($userIds as $userId) {
        $userId = (int) $userId;

        if ($userId <= 0) {
            continue;
        }

        $created = createNotification(
            $con,
            $userId,
            $title,
            $message,
            $type,
            $relatedId
        );

        if (!$created) {
            $success = false;
        }
    }

    return $success;
}

function getUnreadNotificationCount(mysqli $con, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $sql = "
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ?
          AND is_read = 0
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $count = 0;

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $count = (int) ($row['unread_count'] ?? 0);
    }

    mysqli_stmt_close($stmt);

    return $count;
}