<?php
session_start();
include 'koneksi.php';

// Cek apakah user sudah login dan rolenya admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Ambil data statistik untuk Dashboard (Fitur 2)
$q_tersedia = $conn->query("SELECT SUM(available_quantity) as total FROM items WHERE deleted_at IS NULL")->fetch_assoc()['total'] ?? 0;
$q_dipinjam = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status IN ('dipinjam', 'terlambat') AND due_date >= NOW()")->fetch_assoc()['total'] ?? 0;
$q_terlambat = $conn->query("SELECT COUNT(*) as total FROM borrowings WHERE status IN ('dipinjam', 'terlambat') AND due_date < NOW()")->fetch_assoc()['total'] ?? 0;
$q_hilang = $conn->query("SELECT COALESCE(SUM(bd.quantity), 0) as total
    FROM borrowing_details bd
    JOIN borrowings b ON b.id = bd.borrowing_id
    WHERE bd.status_item = 'hilang'
       OR (bd.status_item = 'dipinjam' AND b.status IN ('dipinjam', 'terlambat') AND b.due_date < NOW())")->fetch_assoc()['total'] ?? 0;

// Ambil data barang terbaru
$items = $conn->query("SELECT items.*, categories.name as category_name FROM items JOIN categories ON items.category_id = categories.id WHERE items.deleted_at IS NULL ORDER BY items.id DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Inventaris Lab Sains</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-slate-100 flex min-h-screen app-shell">

    <!-- Sidebar Admin -->
    <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col justify-between hidden md:flex app-sidebar">
        <div>
            <div class="p-6 border-b border-slate-800">
                <span class="text-xs uppercase tracking-wider text-indigo-400 font-semibold">Panel Admin</span>
                <h1 class="text-xl font-bold text-white">Lab Sains</h1>
            </div>
            <nav class="p-4 space-y-1">
                <a href="dashboard_admin.php" class="app-nav-link is-active">📊 Dashboard</a>
                <a href="barang.php" class="app-nav-link">📦 Pendataan Barang</a>
                <a href="transaksi.php" class="app-nav-link">📋 Transaksi Peminjaman</a>
                <a href="riwayat_transaksi.php" class="app-nav-link">📜 Riwayat / Arsip</a>
                <a href="pengguna.php" class="app-nav-link">👥 Manajemen Pengguna</a>
                <a href="backup.php" class="app-nav-link">📁 Backup & Restore</a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800">
            <a href="logout.php" class="flex items-center gap-2 px-4 py-2 bg-red-600/20 text-red-400 hover:bg-red-600 hover:text-white rounded-lg text-sm font-medium transition text-center justify-center">🚪 Keluar Sistem</a>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col app-content">
        <!-- Top Navbar -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center app-header">
            <h2 class="text-lg font-semibold text-slate-800">Selamat Datang, <?php echo htmlspecialchars($_SESSION['name']); ?> 👑</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-indigo-600 uppercase">Admin</span></div>
        </header>

        <!-- Dashboard Body -->
        <main class="p-8 flex-1 space-y-6 app-main">
            <!-- Fitur 2: Grid Kartu Statistik Dashboard -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-slate-500 text-sm font-medium">Barang Tersedia</div>
                    <div class="text-3xl font-bold text-slate-800 mt-2"><?php echo $q_tersedia; ?> <span class="text-xs font-normal text-slate-400">unit</span></div>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-slate-500 text-sm font-medium">Sedang Dipinjam</div>
                    <div class="text-3xl font-bold text-amber-600 mt-2"><?php echo $q_dipinjam; ?> <span class="text-xs font-normal text-slate-400">transaksi</span></div>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-slate-500 text-sm font-medium">Terlambat Dikembalikan</div>
                    <div class="text-3xl font-bold text-red-600 mt-2"><?php echo $q_terlambat; ?> <span class="text-xs font-normal text-slate-400">transaksi</span></div>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-slate-500 text-sm font-medium">Barang Hilang / Lewat Batas</div>
                    <div class="text-3xl font-bold text-slate-700 mt-2"><?php echo $q_hilang; ?> <span class="text-xs font-normal text-slate-400">item</span></div>
                </div>
            </div>

            <!-- Tabel Data Barang Terbaru -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-800">Daftar Barang Laboratorium Terbaru</h3>
                    <a href="#" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">Lihat Semua →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-hover align-middle w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-50 text-slate-700 uppercase text-xs">
                            <tr>
                                <th class="px-6 py-3">Kode</th>
                                <th class="px-6 py-3">Nama Barang</th>
                                <th class="px-6 py-3">Kategori</th>
                                <th class="px-6 py-3">Stok Tersedia</th>
                                <th class="px-6 py-3">Kondisi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php while ($row = $items->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-mono font-medium text-slate-800"><?php echo htmlspecialchars($row['code']); ?></td>
                                <td class="px-6 py-4 font-medium text-slate-800"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($row['category_name']); ?></td>
                                <td class="px-6 py-4"><?php echo $row['available_quantity']; ?> / <?php echo $row['total_quantity']; ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1 text-xs">
                                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">Baik: <?php echo (int) $row['good_quantity']; ?></span>
                                        <span class="rounded-full bg-amber-50 px-2 py-1 text-amber-700">Ringan: <?php echo (int) $row['light_damage_quantity']; ?></span>
                                        <span class="rounded-full bg-red-50 px-2 py-1 text-red-700">Berat: <?php echo (int) $row['heavy_damage_quantity']; ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>