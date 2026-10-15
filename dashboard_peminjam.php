<?php
session_start();
include 'koneksi.php';

// Cek apakah user sudah login dan rolenya peminjam
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'peminjam') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Ambil riwayat peminjaman milik user yang sedang login
$query_riwayat = "SELECT borrowings.*, items.name as item_name, borrowing_details.quantity,
                         borrowing_details.item_condition_before, borrowing_details.item_condition_after
                  FROM borrowings 
                  JOIN borrowers ON borrowings.borrower_id = borrowers.id
                  JOIN borrowing_details ON borrowings.id = borrowing_details.borrowing_id
                  JOIN items ON borrowing_details.item_id = items.id
                  WHERE borrowers.user_id = $user_id ORDER BY borrowings.id DESC";
$riwayat = $conn->query($query_riwayat);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Peminjam - Inventaris Lab Sains</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-slate-100 flex min-h-screen app-shell">

    <!-- Sidebar Peminjam -->
    <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col justify-between hidden md:flex app-sidebar">
        <div>
            <div class="p-6 border-b border-slate-800">
                <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Portal Peminjam</span>
                <h1 class="text-xl font-bold text-white">Lab Sains</h1>
            </div>
            <nav class="p-4 space-y-1">
                <a href="dashboard_peminjam.php" class="app-nav-link is-active">🧪 Riwayat Saya</a>
                <a href="catalog_barang.php" class="app-nav-link">🔬 Katalog Barang Lab</a>
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
            <h2 class="text-lg font-semibold text-slate-800">Halo, <?php echo htmlspecialchars($_SESSION['name']); ?> 👋</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-emerald-600 uppercase">Peminjam</span></div>
        </header>

        <!-- Dashboard Body -->
        <main class="p-8 flex-1 space-y-6 app-main">
            <!-- Informasi Singkat -->
            <div class="bg-emerald-50 border border-emerald-200 p-6 rounded-xl flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-emerald-800 text-base">Butuh Meminjam Alat atau Bahan Laboratorium?</h3>
                    <p class="text-sm text-emerald-600 mt-1">Anda dapat meminjam alat untuk penggunaan di dalam lab (internal) maupun dibawa pulang (eksternal) melalui petugas.</p>
                </div>
            </div>

            <!-- Tabel Riwayat Peminjaman Pribadi -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100">
                    <h3 class="font-bold text-slate-800">Riwayat Peminjaman Barang Anda</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-hover align-middle w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-50 text-slate-700 uppercase text-xs">
                            <tr>
                                <th class="px-6 py-3">Nama Barang</th>
                                <th class="px-6 py-3">Jumlah</th>
                                <th class="px-6 py-3">Kondisi Awal</th>
                                <th class="px-6 py-3">Kondisi Akhir</th>
                                <th class="px-6 py-3">Tipe Peminjaman</th>
                                <th class="px-6 py-3">Batas Waktu</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($riwayat && $riwayat->num_rows > 0): ?>
                                <?php while ($row = $riwayat->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-6 py-4 font-medium text-slate-800"><?php echo htmlspecialchars($row['item_name']); ?></td>
                                    <td class="px-6 py-4"><?php echo $row['quantity']; ?> unit</td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-600">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['item_condition_before'] ?? 'belum dicatat'))); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($row['item_condition_after'])): ?>
                                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['item_condition_after']))); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400">Belum dikembalikan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 rounded-md text-xs font-semibold 
                                            <?php echo $row['borrow_type'] == 'internal_lab' ? 'bg-blue-50 text-blue-600' : 'bg-purple-50 text-purple-600'; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $row['borrow_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4"><?php echo date('d M Y, H:i', strtotime($row['due_date'])); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold 
                                            <?php 
                                                if($row['status'] == 'dikembalikan') echo 'bg-emerald-50 text-emerald-600';
                                                elseif($row['status'] == 'dipinjam') echo 'bg-amber-50 text-amber-600';
                                                else echo 'bg-red-50 text-red-600';
                                            ?>">
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-slate-400">Belum ada riwayat peminjaman barang.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>