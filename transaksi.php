<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$success = '';$error = '';

// 1. Proses Peminjaman Baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_peminjaman'])) {
    $borrower_id = intval($_POST['borrower_id']);
    $user_id     =$_SESSION['user_id']; 
    $borrow_type =$_POST['borrow_type']; 
    $due_date    =$_POST['due_date'];
    $notes       = trim($_POST['notes']);
    $item_id     = intval($_POST['item_id']);
    $quantity    = intval($_POST['quantity']);
    $condition   =$_POST['condition'];

    if ($borrower_id > 0 &&$item_id > 0 && $quantity > 0 && !empty($due_date)) {
        $stmt =$conn->prepare("CALL sp_create_borrowing(?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssiis", $borrower_id, $user_id, $borrow_type, $due_date, $notes, $item_id, $quantity, $condition);
        
        if ($stmt->execute()) {$success = "Transaksi peminjaman berhasil dicatat!";
        } else {
            $error = "Gagal memproses peminjaman: " . $stmt->error;
        }
        $stmt->close();
        while ($conn->more_results() &&$conn->next_result()) {;}
    } else {
        $error = "Semua kolom wajib diisi dengan benar!";
    }
}

// 2. Proses Simpan Pengembalian Barang Berdasarkan Kondisi Akhir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_pengembalian'])) {
    $borrowing_id = intval($_POST['borrowing_id']);
    $condition_after =$_POST['item_condition_after']; // 'baik', 'rusak_ringan', 'rusak_berat'
    $admin_id     =$_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        // Ambil detail item & jumlah yang dipinjam
        $q_detail =$conn->prepare("SELECT item_id, quantity FROM borrowing_details WHERE borrowing_id = ?");
        $q_detail->bind_param("i", $borrowing_id);$q_detail->execute();
        $res_detail =$q_detail->get_result();
        
        while ($detail =$res_detail->fetch_assoc()) {
            $itm_id  =$detail['item_id'];
            $qty_brw =$detail['quantity'];

            // ATURAN STOK BERDASARKAN KONDISI AKHIR:
            // Jika kembali dalam kondisi 'baik', stok 'available_quantity' dikembalikan.
            // Jika rusak, stok tidak otomatis masuk ke 'available_quantity' (bisa dicatat ke log kerusakan).
            if ($condition_after === 'baik') {$conn->query("UPDATE items SET available_quantity = available_quantity + $qty_brw, status = 'tersedia' WHERE id =$itm_id");
            } else {
                // Jika rusak, total quantity bisa dikurangi atau dicatat sebagai unit rusak (opsional sesuai kebijakan lab)
                $conn->query("UPDATE items SET `condition` = '$condition_after' WHERE id =$itm_id");
            }

            $conn->query("INSERT INTO item_logs (item_id, user_id, action, description) VALUES ($itm_id, $admin_id, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:$condition_after')");
        }
        $q_detail->close();

        // Update status transaksi peminjaman
        $conn->query("UPDATE borrowings SET status = 'dikembalikan', return_date = NOW() WHERE id = $borrowing_id");
        
        // Update detail item
        $conn->query("UPDATE borrowing_details SET status_item = 'dikembalikan', item_condition_after = '$condition_after' WHERE borrowing_id =$borrowing_id");

        $conn->commit();$success = "Pengembalian berhasil diproses dengan kondisi akhir: " . ucfirst(str_replace('_', ' ', $condition_after));
    } catch (Exception $e) {
        $conn->rollback();$error = "Gagal memproses pengembalian: " . $e->getMessage();
    }
}

$borrowers =$conn->query("SELECT id, name, identity_number, type FROM borrowers ORDER BY name ASC");
$items =$conn->query("SELECT id, name, code, available_quantity FROM items WHERE available_quantity > 0 AND deleted_at IS NULL ORDER BY name ASC");

// Pastikan query ini mencakup kolom item_condition_before secara eksplisit
$query_aktif = "SELECT b.id, br.name as borrower_name, br.identity_number, 
                       b.borrow_type, b.borrow_date, b.due_date, b.status, b.notes,
                       i.name as item_name, bd.quantity, bd.item_condition_before
                FROM borrowings b
                JOIN borrowers br ON b.borrower_id = br.id
                JOIN borrowing_details bd ON b.id = bd.borrowing_id
                JOIN items i ON bd.item_id = i.id
                WHERE b.status IN ('dipinjam', 'terlambat')
                ORDER BY b.due_date ASC, b.id ASC";

// Ekspor laporan transaksi yang masih dipinjam/terlambat dalam format CSV yang kompatibel dengan Excel.
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exportResult = $conn->query($query_aktif);
    if (!$exportResult) {
        http_response_code(500);
        exit('Gagal mengambil data laporan.');
    }

    $filename = 'laporan_peminjaman_aktif_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['sep=;'], ';');
    fputcsv($output, ['ID Transaksi', 'Nama Peminjam', 'Nomor Identitas', 'Nama Barang', 'Jumlah', 'Tipe Peminjaman', 'Tanggal Pinjam', 'Batas Waktu', 'Status', 'Kondisi Awal', 'Catatan'], ';');

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
            ucfirst(str_replace('_', ' ', $row['status'])),
            ucfirst(str_replace('_', ' ', $row['item_condition_before'] ?? 'belum dicatat')),
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

// Pagination daftar peminjaman aktif (ekspor Excel tetap mengambil seluruh baris).
$perPage = 10;
$countQuery = "SELECT COUNT(*) AS total
               FROM borrowings b
               JOIN borrowers br ON b.borrower_id = br.id
               JOIN borrowing_details bd ON b.id = bd.borrowing_id
               JOIN items i ON bd.item_id = i.id
               WHERE b.status IN ('dipinjam', 'terlambat')";
$countResult = $conn->query($countQuery);
$totalRows = $countResult ? (int) $countResult->fetch_assoc()['total'] : 0;
if ($countResult) $countResult->free();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$currentPage = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$offset = ($currentPage - 1) * $perPage;
$transaksi_aktif = $conn->query($query_aktif . " LIMIT {$perPage} OFFSET {$offset}");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaksi Aktif & Pengembalian - Inventaris Lab Sains</title>
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
                <a href="transaksi.php" class="app-nav-link is-active">📋 Transaksi Peminjaman</a>
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
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center app-header">
            <h2 class="text-lg font-semibold text-slate-800">Peminjaman Aktif & Pengembalian Berdasarkan Kondisi Barang</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-indigo-600 uppercase">Admin</span></div>
        </header>

        <main class="p-8 flex-1 space-y-6 app-main">
            <?php if (!empty($success)): ?>
                <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-lg flex items-center gap-2">
                    <span>✅</span><span><?php echo $success; ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="p-4 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg flex items-center gap-2">
                    <span>⚠️</span><span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <!-- Form Peminjaman Baru -->
            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h3 class="font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100">➕ Catat Peminjaman Baru (Dengan Kondisi Awal)</h3>
                <form action="" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Pilih Peminjam</label>
                        <select name="borrower_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                            <option value="">-- Peminjam --</option>
                            <?php while ($b =$borrowers->fetch_assoc()): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name'] . ' (' .$b['identity_number'] . ')'); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Tipe Peminjaman</label>
                        <select name="borrow_type" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                            <option value="internal_lab">Dalam Lab</option>
                            <option value="eksternal">Bawa Pulang / Eksternal</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Batas Waktu</label>
                        <input type="datetime-local" name="due_date" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Pilih Barang</label>
                        <select name="item_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                            <option value="">-- Barang Tersedia --</option>
                            <?php while ($item =$items->fetch_assoc()): ?>
                                <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name'] . ' (Tersedia: ' .$item['available_quantity'] . ')'); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Jumlah</label>
                        <input type="number" name="quantity" min="1" value="1" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Kondisi Awal Saat Dipinjam</label>
                        <select name="condition" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                            <option value="baik">Baik</option>
                            <option value="rusak_ringan">Rusak Ringan</option>
                            <option value="rusak_berat">Rusak Berat</option>
                        </select>
                    </div>

                    <div class="md:col-span-3">
                        <label class="block text-xs font-medium text-slate-700 mb-1">Catatan</label>
                        <textarea name="notes" rows="2" placeholder="Keperluan praktikum..." class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>

                    <div class="md:col-span-3 flex justify-end">
                        <button type="submit" name="tambah_peminjaman" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-lg text-sm transition shadow-sm">
                            Simpan Peminjaman
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tabel Peminjaman Aktif & Panel Pengembalian Berdasarkan Kondisi -->
            <div id="transaksi-aktif" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <h3 class="font-bold text-slate-800">Daftar Peminjaman Aktif & Form Pengembalian Kondisi Barang</h3>
                    <a href="transaksi.php?export=excel" class="inline-flex justify-center items-center bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">📊 Ekspor Excel (.csv)</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-hover align-middle w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-50 text-slate-700 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Peminjam</th>
                                <th class="px-4 py-3">Barang & Qty</th>
                                <th class="px-4 py-3">Kondisi Awal</th>
                                <th class="px-4 py-3">Batas Waktu</th>
                                <th class="px-4 py-3 text-center">Aksi Pengembalian (Pilih Kondisi Akhir)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($transaksi_aktif &&$transaksi_aktif->num_rows > 0): ?>
                                <?php while ($row =$transaksi_aktif->fetch_assoc()): ?>
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
                                        <span class="px-2.5 py-1 rounded-full font-semibold bg-blue-50 text-blue-600">
                                            <?php echo ucfirst($row['item_condition_before']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs"><?php echo date('d M Y, H:i', strtotime($row['due_date'])); ?></td>
                                    <td class="px-4 py-3">
                                        <!-- Form Inline untuk Memilih Kondisi Saat Pengembalian -->
                                        <form action="" method="POST" class="flex items-center justify-center gap-2">
                                            <input type="hidden" name="borrowing_id" value="<?php echo $row['id']; ?>">
                                            <select name="item_condition_after" required class="px-2 py-1.5 border border-slate-300 rounded-lg text-xs bg-white focus:ring-2 focus:ring-indigo-500">
                                                <option value="baik">Kembali: Baik</option>
                                                <option value="rusak_ringan">Kembali: Rusak Ringan</option>
                                                <option value="rusak_berat">Kembali: Rusak Berat</option>
                                            </select>
                                            <button type="submit" name="proses_pengembalian" 
                                                onclick="return confirm('Konfirmasi pengembalian barang untuk transaksi #<?php echo $row['id']; ?>?');"
                                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition shadow-sm">
                                                Proses
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-slate-400">Tidak ada peminjaman aktif saat ini.</td>
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
                    <small class="text-secondary">Menampilkan <?php echo $firstRow; ?>–<?php echo $lastRow; ?> dari <?php echo $totalRows; ?> transaksi aktif</small>
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Navigasi halaman transaksi">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo max(1, $currentPage - 1); ?>#transaksi-aktif" aria-label="Halaman sebelumnya">Sebelumnya</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                                ?>
                                    <li class="page-item <?php echo $pageNumber === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $pageNumber; ?>#transaksi-aktif"><?php echo $pageNumber; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo min($totalPages, $currentPage + 1); ?>#transaksi-aktif" aria-label="Halaman berikutnya">Berikutnya</a>
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