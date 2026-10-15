<?php
session_start();

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: dashboard_admin.php');
        exit;
    }

    if (($_SESSION['role'] ?? '') === 'peminjam') {
        header('Location: dashboard_peminjam.php');
        exit;
    }
}

header('Location: login.php');
exit;
