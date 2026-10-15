<?php
session_start();
include 'koneksi.php';

// Cek apakah user adalah admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// AMBIL HANYA TRANSAKSI YANG SUDAH SELESAI (DIKEMBALIKAN)
$query_riwayat = "SELECT b.id, br.name as borrower_name, br.identity_number, 
                         b.borrow_type, b.borrow_date, b.due_date, b.return_date, b.notes,
                         i.name as item_name, bd.quantity,
                         bd.item_condition_before, bd.item_condition_after
                  FROM borrowings b
                  JOIN borrowers br ON b.borrower_id = br.id
                  JOIN borrowing_details bd ON b.id = bd.borrowing_id
                  JOIN items i ON bd.item_id = i.id
                  WHERE b.status = 'dikembalikan'
                  ORDER BY b.return_date DESC, b.id DESC";

// Ekspor laporan transaksi yang telah dikembalikan dalam format CSV yang kompatibel dengan Excel.
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exportResult = $conn->query($query_riwayat);
    if (!$exportResult) {
        http_response_code(500);
        exit('Gagal mengambil data laporan.');
    }

    $filename = 'laporan_peminjaman_dikembalikan_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['sep=;'], ';');
    fputcsv($output, ['ID Transaksi', 'Nama Peminjam', 'Nomor Identitas', 'Nama Barang', 'Jumlah', 'Tipe Peminjaman', 'Tanggal Pinjam', 'Batas Waktu', 'Tanggal Dikembalikan', 'Kondisi Awal', 'Kondisi Akhir', 'Status', 'Catatan'], ';');

    while ($row = $exportResult->fetch_assoc()) {
        $values = [
            $row['id'],
            $row['borrower_name'],
            $row['identity_number'],
            $row['item_name'],
            $row['quantity'],
            ucfirst(str_replace('_', ' ', $row['borrow_type'])),
            !empty($row['borrow_date']) ? date('Y-m-d H:i:s', strtotime($row['borrow_date'])) : '',
            !empty($row['due_date']) ? date('Y-m-d H:i:s', strtotime($row['due_date'])) : '',
            !empty($row['return_date']) ? date('Y-m-d H:i:s', strtotime($row['return_date'])) : '',
            ucfirst(str_replace('_', ' ', $row['item_condition_before'] ?? 'belum dicatat')),
            ucfirst(str_replace('_', ' ', $row['item_condition_after'] ?? 'belum dicatat')),
            'Dikembalikan',
            $row['notes'],
        ];
        foreach ($values as &$value) {
            $value = (string) $value;
            if (preg_match('/^[\s]*[=+@-]/u', $value)) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values, ';');
    }

    fclose($output);
    $exportResult->free();
    exit;
}

// Pagination arsip (ekspor CSV di atas tetap memuat seluruh riwayat).
$perPage = 10;
$countQuery = "SELECT COUNT(*) AS total
               FROM borrowings b
               JOIN borrowers br ON b.borrower_id = br.id
               JOIN borrowing_details bd ON b.id = bd.borrowing_id
               JOIN items i ON bd.item_id = i.id
               WHERE b.status = 'dikembalikan'";
$countResult = $conn->query($countQuery);
$totalRows = $countResult ? (int) $countResult->fetch_assoc()['total'] : 0;
if ($countResult) $countResult->free();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$currentPage = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$offset = ($currentPage - 1) * $perPage;
$riwayat_list = $conn->query($query_riwayat . " LIMIT {$perPage} OFFSET {$offset}");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Arsip Transaksi - Inventaris Lab Sains</title>
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
                <a href="dashboard_admin.php" class="app-nav-link">📊 Dashboard</a>
                <a href="barang.php" class="app-nav-link">📦 Pendataan Barang</a>
                <a href="transaksi.php" class="app-nav-link">📋 Transaksi Peminjaman</a>
                <a href="riwayat_transaksi.php" class="app-nav-link is-active">📜 Riwayat / Arsip</a>
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
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center app-header">
            <h2 class="text-lg font-semibold text-slate-800">Arsip Riwayat Peminjaman Selesai</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-indigo-600 uppercase">Admin</span></div>
        </header>

        <main class="p-8 flex-1 space-y-6 app-main">
            <div id="arsip-riwayat" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <h3 class="font-bold text-slate-800">Daftar Seluruh Transaksi yang Telah Dikembalikan</h3>
                    <a href="riwayat_transaksi.php?export=excel" class="inline-flex justify-center items-center bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">📊 Ekspor Excel (.csv)</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-hover align-middle w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-50 text-slate-700 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Peminjam</th>
                                <th class="px-4 py-3">Barang & Qty</th>
                                <th class="px-4 py-3">Kondisi Awal</th>
                                <th class="px-4 py-3">Kondisi Akhir</th>
                                <th class="px-4 py-3">Tgl Pinjam</th>
                                <th class="px-4 py-3">Tgl Dikembalikan</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($riwayat_list && $riwayat_list->num_rows > 0): ?>
                                <?php while ($row = $riwayat_list->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-mono text-xs">#<?php echo $row['id']; ?></td>
                                    <td class="px-4 py-3 font-medium text-slate-800">
                                        <?php echo htmlspecialchars($row['borrower_name']); ?>
                                        <div class="text-xs font-normal text-slate-400"><?php echo htmlspecialchars($row['identity_number']); ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-800 font-medium">
                                        <?php echo htmlspecialchars($row['item_name']); ?> 
                                        <span class="text-xs text-indigo-600 font-semibold">(<?php echo $row['quantity']; ?> unit)</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['item_condition_before'] ?? 'belum dicatat'))); ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['item_condition_after'] ?? 'belum dicatat'))); ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs"><?php echo date('d M Y, H:i', strtotime($row['borrow_date'])); ?></td>
                                    <td class="px-4 py-3 text-xs"><?php echo date('d M Y, H:i', strtotime($row['return_date'])); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600">
                                            Dikembalikan
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500"><?php echo htmlspecialchars($row['notes']); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="px-6 py-8 text-center text-slate-400">Belum ada arsip riwayat peminjaman yang selesai.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 border-top px-4 py-3">
                    <?php
                        $firstRow = $totalRows === 0 ? 0 : $offset + 1;
                        $lastRow = min($offset + $perPage, $totalRows);
                    ?>
                    <small class="text-secondary">Menampilkan <?php echo $firstRow; ?>–<?php echo $lastRow; ?> dari <?php echo $totalRows; ?> transaksi yang dikembalikan</small>
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Navigasi halaman riwayat transaksi">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo max(1, $currentPage - 1); ?>#arsip-riwayat" aria-label="Halaman sebelumnya">Sebelumnya</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                                ?>
                                    <li class="page-item <?php echo $pageNumber === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $pageNumber; ?>#arsip-riwayat"><?php echo $pageNumber; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo min($totalPages, $currentPage + 1); ?>#arsip-riwayat" aria-label="Halaman berikutnya">Berikutnya</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>