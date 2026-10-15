<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "db_inventaris_sains";

$conn = new mysqli($host, $user, $pass, $db);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Migrasi ringan untuk mendukung soft delete barang pada database yang sudah ada.
$items_soft_delete_ready = false;
$deleted_column = $conn->query("SHOW COLUMNS FROM items LIKE 'deleted_at'");
if ($deleted_column) {
    if ($deleted_column->num_rows > 0) {
        $items_soft_delete_ready = true;
    } else {
        $items_soft_delete_ready = $conn->query("ALTER TABLE items ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL") === true;
    }
    $deleted_column->free();
}
?>