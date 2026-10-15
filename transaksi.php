<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_peminjaman'])) {
    $borrower_id = (int) ($_POST['borrower_id'] ?? 0);
    $user_id = (int) $_SESSION['user_id'];
    $borrow_type = $_POST['borrow_type'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $condition = $_POST['condition'] ?? '';
    $conditionColumns = ['baik' => 'good_quantity', 'rusak_ringan' => 'light_damage_quantity', 'rusak_berat' => 'heavy_damage_quantity'];

    if ($borrower_id > 0 && $item_id > 0 && $quantity > 0 && $due_date !== '' && isset($conditionColumns[$condition]) && in_array($borrow_type, ['internal_lab', 'eksternal'], true)) {
        $conn->begin_transaction();
        try {
            $itemStmt = $conn->prepare("SELECT id, good_quantity, light_damage_quantity, heavy_damage_quantity FROM items WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $itemStmt->bind_param('i', $item_id);
            $itemStmt->execute();
            $itemRow = $itemStmt->get_result()->fetch_assoc();
            $itemStmt->close();
            $stockColumn = $conditionColumns[$condition];
            if (!$itemRow || (int) $itemRow[$stockColumn] < $quantity) throw new RuntimeException('Stok barang dengan kondisi tersebut tidak mencukupi.');

            $borrowStmt = $conn->prepare("INSERT INTO borrowings (borrower_id, user_id, borrow_type, borrow_date, due_date, status, notes) VALUES (?, ?, ?, NOW(), ?, 'dipinjam', ?)");
            $borrowStmt->bind_param('iisss', $borrower_id, $user_id, $borrow_type, $due_date, $notes);
            if (!$borrowStmt->execute()) throw new RuntimeException($borrowStmt->error);
            $borrowing_id = $borrowStmt->insert_id;
            $borrowStmt->close();

            $detailStmt = $conn->prepare("INSERT INTO borrowing_details (borrowing_id, item_id, quantity, item_condition_before, status_item) VALUES (?, ?, ?, ?, 'dipinjam')");
            $detailStmt->bind_param('iiis', $borrowing_id, $item_id, $quantity, $condition);
            if (!$detailStmt->execute()) throw new RuntimeException($detailStmt->error);
            $detailStmt->close();

            $stockStmt = $conn->prepare("UPDATE items SET `{$stockColumn}` = `{$stockColumn}` - ?, available_quantity = available_quantity - ?, status = CASE WHEN available_quantity - ? = 0 THEN 'dipinjam' ELSE 'tersedia' END WHERE id = ?");
            $stockStmt->bind_param('iiii', $quantity, $quantity, $quantity, $item_id);
            if (!$stockStmt->execute()) throw new RuntimeException($stockStmt->error);
            $stockStmt->close();

            $description = "Dipinjam sebanyak {$quantity} unit (Kondisi: " . str_replace('_', ' ', $condition) . ').';
            $logStmt = $conn->prepare("INSERT INTO item_logs (item_id, user_id, action, description) VALUES (?, ?, 'perubahan_kondisi', ?)");
            $logStmt->bind_param('iis', $item_id, $user_id, $description);
            if (!$logStmt->execute()) throw new RuntimeException($logStmt->error);
            $logStmt->close();
            $conn->commit();
            $success = 'Transaksi peminjaman berhasil dicatat!';
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Gagal memproses peminjaman: ' . $e->getMessage();
        }
    } else {
        $error = 'Semua kolom wajib diisi dengan benar.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_pengembalian'])) {
    $borrowing_id = (int) ($_POST['borrowing_id'] ?? 0);
    $returnedGood = max(0, (int) ($_POST['returned_good_quantity'] ?? 0));
    $returnedLight = max(0, (int) ($_POST['returned_light_damage_quantity'] ?? 0));
    $returnedHeavy = max(0, (int) ($_POST['returned_heavy_damage_quantity'] ?? 0));
    $admin_id = (int) $_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        $detailStmt = $conn->prepare("SELECT bd.item_id, bd.quantity FROM borrowing_details bd JOIN borrowings b ON b.id = bd.borrowing_id WHERE bd.borrowing_id = ? AND bd.status_item = 'dipinjam' AND b.status IN ('dipinjam', 'terlambat') FOR UPDATE");
        $detailStmt->bind_param('i', $borrowing_id);
        $detailStmt->execute();
        $detail = $detailStmt->get_result()->fetch_assoc();
        $detailStmt->close();
        if (!$detail) throw new RuntimeException('Peminjaman aktif tidak ditemukan atau sudah diproses.');
        $returnedTotal = $returnedGood + $returnedLight + $returnedHeavy;
        if ($returnedTotal !== (int) $detail['quantity']) throw new RuntimeException('Jumlah kondisi yang dikembalikan harus sama dengan jumlah unit yang dipinjam.');

        $item_id = (int) $detail['item_id'];
        $stockStmt = $conn->prepare("UPDATE items SET good_quantity = good_quantity + ?, light_damage_quantity = light_damage_quantity + ?, heavy_damage_quantity = heavy_damage_quantity + ?, available_quantity = available_quantity + ?, status = 'tersedia' WHERE id = ?");
        $stockStmt->bind_param('iiiii', $returnedGood, $returnedLight, $returnedHeavy, $returnedTotal, $item_id);
        if (!$stockStmt->execute()) throw new RuntimeException($stockStmt->error);
        $stockStmt->close();

        $conditionAfter = $returnedGood === $returnedTotal ? 'baik' : ($returnedLight === $returnedTotal ? 'rusak_ringan' : ($returnedHeavy === $returnedTotal ? 'rusak_berat' : null));
        $updateDetail = $conn->prepare("UPDATE borrowing_details SET status_item = 'dikembalikan', item_condition_after = ?, returned_good_quantity = ?, returned_light_damage_quantity = ?, returned_heavy_damage_quantity = ? WHERE borrowing_id = ?");
        $updateDetail->bind_param('siiii', $conditionAfter, $returnedGood, $returnedLight, $returnedHeavy, $borrowing_id);
        if (!$updateDetail->execute()) throw new RuntimeException($updateDetail->error);
        $updateDetail->close();

        $updateBorrowing = $conn->prepare("UPDATE borrowings SET status = 'dikembalikan', return_date = NOW() WHERE id = ?");
        $updateBorrowing->bind_param('i', $borrowing_id);
        if (!$updateBorrowing->execute()) throw new RuntimeException($updateBorrowing->error);
        $updateBorrowing->close();

        $description = "Pengembalian: {$returnedGood} baik, {$returnedLight} rusak ringan, {$returnedHeavy} rusak berat.";
        $logStmt = $conn->prepare("INSERT INTO item_logs (item_id, user_id, action, description) VALUES (?, ?, 'perubahan_kondisi', ?)");
        $logStmt->bind_param('iis', $item_id, $admin_id, $description);
        if (!$logStmt->execute()) throw new RuntimeException($logStmt->error);
        $logStmt->close();
        $conn->commit();
        $success = $description;
    } catch (Throwable $e) {
        $conn->rollback();
        $error = 'Gagal memproses pengembalian: ' . $e->getMessage();
    }
}

$borrowers =$conn->query("SELECT id, name, identity_number, type FROM borrowers ORDER BY name ASC");
$items =$conn->query("SELECT id, name, code, available_quantity, good_quantity, light_damage_quantity, heavy_damage_quantity FROM items WHERE available_quantity > 0 AND deleted_at IS NULL ORDER BY name ASC");
$itemOptions = $items ? $items->fetch_all(MYSQLI_ASSOC) : [];

// Pastikan query ini mencakup kolom item_condition_before secara eksplisit
$query_aktif = "SELECT b.id, br.name as borrower_name, br.identity_number,
                       u.nik AS borrower_nik, u.alamat AS borrower_address,
                       b.borrow_type, b.borrow_date, b.due_date, b.status, b.notes,
                       i.name as item_name, bd.quantity, bd.item_condition_before
                FROM borrowings b
                JOIN borrowers br ON b.borrower_id = br.id
                LEFT JOIN users u ON u.id = br.user_id
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
    fputcsv($output, ['ID Transaksi', 'Nama Peminjam', 'Nomor Identitas', 'NIK / KTP', 'Alamat', 'Nama Barang', 'Jumlah', 'Tipe Peminjaman', 'Tanggal Pinjam', 'Batas Waktu', 'Status', 'Kondisi Awal', 'Catatan'], ';');

    while ($row = $exportResult->fetch_assoc()) {
        $values = [
            $row['id'],
            $row['borrower_name'],
            $row['identity_number'],
            $row['borrower_nik'] ?: 'Belum diisi',
            $row['borrower_address'] ?: 'Belum diisi',
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
                <form id="borrow-form" action="" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
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

                    <div class="item-picker" id="item-picker">
                        <label for="item-search" class="block text-xs font-medium text-slate-700 mb-1">Pilih Barang</label>
                        <div class="item-picker-search-wrap">
                            <span class="item-picker-search-icon" aria-hidden="true">⌕</span>
                            <input type="search" id="item-search" required autocomplete="off" placeholder="Cari nama atau kode barang..." aria-autocomplete="list" aria-controls="item-picker-results" aria-expanded="false" class="item-picker-search">
                            <input type="hidden" name="item_id" id="item-id">
                        </div>
                        <div id="item-picker-results" class="item-picker-results" role="listbox" hidden>
                            <?php foreach ($itemOptions as $item): ?>
                                <?php $itemSearchText = strtolower($item['name'] . ' ' . $item['code']); ?>
                                <button type="button" class="item-picker-option" role="option"
                                    data-id="<?php echo (int) $item['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-code="<?php echo htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-search="<?php echo htmlspecialchars($itemSearchText, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-good="<?php echo (int) $item['good_quantity']; ?>"
                                    data-light="<?php echo (int) $item['light_damage_quantity']; ?>"
                                    data-heavy="<?php echo (int) $item['heavy_damage_quantity']; ?>">
                                    <span class="item-picker-option-copy">
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <small><?php echo htmlspecialchars($item['code']); ?></small>
                                    </span>
                                    <span class="item-picker-counts">
                                        <span class="item-count-good">Baik <?php echo (int) $item['good_quantity']; ?></span>
                                        <span class="item-count-light">Ringan <?php echo (int) $item['light_damage_quantity']; ?></span>
                                        <span class="item-count-heavy">Berat <?php echo (int) $item['heavy_damage_quantity']; ?></span>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                            <div id="item-picker-empty" class="item-picker-empty" hidden>Barang tidak ditemukan. Coba kata kunci lain.</div>
                        </div>
                        <div id="item-picker-selected" class="item-picker-selected" hidden></div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Jumlah</label>
                        <input type="number" name="quantity" id="borrow-quantity" min="1" value="1" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Kondisi Awal Saat Dipinjam</label>
                        <select name="condition" id="borrow-condition" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                            <option value="baik">Baik</option>
                            <option value="rusak_ringan">Rusak Ringan</option>
                            <option value="rusak_berat">Rusak Berat</option>
                        </select>
                        <small id="condition-stock-hint" class="mt-1 block text-xs text-slate-500">Pilih barang untuk melihat stok pada kondisi ini.</small>
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
                                <th class="px-4 py-3 text-center">Kondisi Saat Kembali</th>
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
                                        <div class="text-xs font-normal text-slate-500">NIK: <?php echo htmlspecialchars($row['borrower_nik'] ?: 'Belum diisi'); ?></div>
                                        <div class="text-xs font-normal text-slate-500" style="max-width: 16rem; white-space: normal">Alamat: <?php echo htmlspecialchars($row['borrower_address'] ?: 'Belum diisi'); ?></div>
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
                                        <form action="" method="POST" class="return-condition-form" data-return-quantity="<?php echo $returnQty = (int) $row['quantity']; ?>">
                                            <input type="hidden" name="borrowing_id" value="<?php echo $row['id']; ?>">
                                            <?php $borrowedCondition = $row['item_condition_before'] ?? 'baik'; ?>
                                            <label for="return-condition-<?php echo (int) $row['id']; ?>" class="return-condition-label">Pilih kondisi semua unit</label>
                                            <select id="return-condition-<?php echo (int) $row['id']; ?>" class="return-condition-select" data-return-preset>
                                                <option value="baik" <?php echo $borrowedCondition === 'baik' ? 'selected' : ''; ?>>Baik</option>
                                                <option value="rusak_ringan" <?php echo $borrowedCondition === 'rusak_ringan' ? 'selected' : ''; ?>>Rusak ringan</option>
                                                <option value="rusak_berat" <?php echo $borrowedCondition === 'rusak_berat' ? 'selected' : ''; ?>>Rusak berat</option>
                                            </select>
                                            <button type="button" class="return-condition-toggle" aria-expanded="false" data-return-toggle>Atur kondisi campuran</button>
                                            <div class="return-condition-mixed" data-return-mixed hidden>
                                                <label>Baik
                                                    <input type="number" name="returned_good_quantity" min="0" max="<?php echo $returnQty; ?>" value="<?php echo $borrowedCondition === 'baik' ? $returnQty : 0; ?>" required data-return-count="baik">
                                                </label>
                                                <label>Rusak ringan
                                                    <input type="number" name="returned_light_damage_quantity" min="0" max="<?php echo $returnQty; ?>" value="<?php echo $borrowedCondition === 'rusak_ringan' ? $returnQty : 0; ?>" required data-return-count="rusak_ringan">
                                                </label>
                                                <label>Rusak berat
                                                    <input type="number" name="returned_heavy_damage_quantity" min="0" max="<?php echo $returnQty; ?>" value="<?php echo $borrowedCondition === 'rusak_berat' ? $returnQty : 0; ?>" required data-return-count="rusak_berat">
                                                </label>
                                            </div>
                                            <p class="return-condition-summary" data-return-summary><?php echo $returnQty; ?> unit kembali dalam kondisi <?php echo htmlspecialchars(str_replace('_', ' ', $borrowedCondition)); ?>.</p>
                                            <button type="submit" name="proses_pengembalian"
                                                onclick="return confirm('Konfirmasi pengembalian barang untuk transaksi #<?php echo $row['id']; ?>?');"
                                                class="return-condition-submit bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition shadow-sm">
                                                Proses pengembalian
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
<script>
document.querySelectorAll('.return-condition-form').forEach((form) => {
    const quantity = Number(form.dataset.returnQuantity);
    const preset = form.querySelector('[data-return-preset]');
    const mixedFields = form.querySelector('[data-return-mixed]');
    const toggle = form.querySelector('[data-return-toggle]');
    const summary = form.querySelector('[data-return-summary]');
    const submit = form.querySelector('[type="submit"]');
    const counts = Array.from(form.querySelectorAll('[data-return-count]'));

    function applyPreset() {
        counts.forEach((input) => {
            input.value = input.dataset.returnCount === preset.value ? String(quantity) : '0';
        });
        updateSummary();
    }

    function updateSummary() {
        const total = counts.reduce((sum, input) => sum + (Number(input.value) || 0), 0);
        if (mixedFields.hidden) {
            summary.textContent = `Seluruh ${quantity} unit kembali dalam kondisi ${preset.options[preset.selectedIndex].text.toLowerCase()}.`;
            summary.classList.remove('is-invalid');
            submit.disabled = false;
            return;
        }

        summary.textContent = total === quantity
            ? `Jumlah sesuai: ${total} dari ${quantity} unit.`
            : `Jumlah kondisi ${total} dari ${quantity} unit. Sesuaikan agar totalnya ${quantity}.`;
        summary.classList.toggle('is-invalid', total !== quantity);
        submit.disabled = total !== quantity;
    }

    preset.addEventListener('change', applyPreset);
    toggle.addEventListener('click', () => {
        const opening = mixedFields.hidden;
        mixedFields.hidden = !opening;
        preset.disabled = opening;
        toggle.setAttribute('aria-expanded', String(opening));
        toggle.textContent = opening ? 'Pilih satu kondisi saja' : 'Atur kondisi campuran';
        if (!opening) applyPreset();
        else updateSummary();
    });
    counts.forEach((input) => input.addEventListener('input', updateSummary));
    updateSummary();
});

(() => {
    const picker = document.getElementById('item-picker');
    const search = document.getElementById('item-search');
    const itemId = document.getElementById('item-id');
    const results = document.getElementById('item-picker-results');
    const emptyState = document.getElementById('item-picker-empty');
    const selectedCard = document.getElementById('item-picker-selected');
    const condition = document.getElementById('borrow-condition');
    const quantity = document.getElementById('borrow-quantity');
    const stockHint = document.getElementById('condition-stock-hint');
    const options = Array.from(picker.querySelectorAll('.item-picker-option'));
    const stockKeys = {baik: 'good', rusak_ringan: 'light', rusak_berat: 'heavy'};
    let selectedOption = null;

    function showResults() {
        results.hidden = false;
        search.setAttribute('aria-expanded', 'true');
        filterResults();
    }

    function hideResults() {
        results.hidden = true;
        search.setAttribute('aria-expanded', 'false');
    }

    function filterResults() {
        const term = search.value.trim().toLocaleLowerCase();
        let visibleCount = 0;
        options.forEach((option) => {
            const matches = option.dataset.search.includes(term);
            option.hidden = !matches;
            if (matches) visibleCount++;
        });
        emptyState.hidden = visibleCount > 0;
    }

    function updateConditionStock() {
        if (!selectedOption) {
            condition.querySelectorAll('option').forEach((option) => { option.disabled = false; });
            quantity.removeAttribute('max');
            stockHint.textContent = 'Pilih barang untuk melihat stok pada kondisi ini.';
            return;
        }

        const stocks = {
            baik: Number(selectedOption.dataset.good),
            rusak_ringan: Number(selectedOption.dataset.light),
            rusak_berat: Number(selectedOption.dataset.heavy)
        };
        condition.querySelectorAll('option').forEach((option) => {
            option.disabled = stocks[option.value] === 0;
        });
        const stock = stocks[condition.value] || 0;
        quantity.max = String(stock);
        quantity.value = String(stock > 0 ? Math.min(Number(quantity.value) || 1, stock) : 1);
        stockHint.textContent = `Tersedia ${stock} unit dalam kondisi ${condition.options[condition.selectedIndex].text.toLowerCase()}.`;
    }

    function selectItem(option) {
        selectedOption = option;
        itemId.value = option.dataset.id;
        search.value = `${option.dataset.name} (${option.dataset.code})`;
        search.setCustomValidity('');
        selectedCard.replaceChildren();
        const selectedName = document.createElement('strong');
        selectedName.textContent = option.dataset.name;
        const selectedCode = document.createElement('span');
        selectedCode.textContent = option.dataset.code;
        const selectedCounts = document.createElement('div');
        [
            `Baik ${option.dataset.good}`,
            `Rusak ringan ${option.dataset.light}`,
            `Rusak berat ${option.dataset.heavy}`
        ].forEach((label) => {
            const count = document.createElement('em');
            count.textContent = label;
            selectedCounts.append(count);
        });
        selectedCard.append(selectedName, selectedCode, selectedCounts);
        selectedCard.hidden = false;

        const firstStockedCondition = [
            ['baik', option.dataset.good],
            ['rusak_ringan', option.dataset.light],
            ['rusak_berat', option.dataset.heavy]
        ].find(([, stock]) => Number(stock) > 0);
        if (firstStockedCondition) condition.value = firstStockedCondition[0];
        hideResults();
        updateConditionStock();
    }

    search.addEventListener('focus', () => {
        if (itemId.value) search.select();
        else showResults();
    });
    search.addEventListener('input', () => {
        itemId.value = '';
        selectedOption = null;
        selectedCard.hidden = true;
        search.setCustomValidity('');
        updateConditionStock();
        showResults();
    });
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hideResults();
    });
    options.forEach((option) => option.addEventListener('click', () => selectItem(option)));
    condition.addEventListener('change', updateConditionStock);
    document.addEventListener('click', (event) => {
        if (!picker.contains(event.target)) hideResults();
    });
    document.getElementById('borrow-form').addEventListener('submit', (event) => {
        if (!itemId.value) {
            search.setCustomValidity('Pilih barang dari daftar hasil pencarian.');
            search.reportValidity();
            event.preventDefault();
        }
    });
})();
</script>
</body>
</html>