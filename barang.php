<?php
session_start();
include 'koneksi.php';

// Cek apakah user adalah admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

function build_category_code_prefix($categoryName, $categoryId)
{
    $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($categoryName), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) > 1) {
        $letters = '';
        foreach (array_slice($words, 0, 3) as $word) $letters .= $word[0];
    } else {
        $letters = substr($words[0] ?? 'BRG', 0, 3);
    }

    return $letters . '-C' . str_pad((string) $categoryId, 2, '0', STR_PAD_LEFT) . '-';
}

function next_category_item_code($conn, $categoryId, $categoryName)
{
    $prefix = build_category_code_prefix($categoryName, $categoryId);
    $pattern = $prefix . '%';
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) AS last_number FROM items WHERE code LIKE ?");
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $lastNumber = (int) ($stmt->get_result()->fetch_assoc()['last_number'] ?? 0);
    $stmt->close();

    return $prefix . str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
}

function barang_page_url($overrides = [])
{
    $params = $_GET;
    unset($params['edit']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'barang.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

$success = '';
$error = '';
$user_id = (int) $_SESSION['user_id'];

if (!$items_soft_delete_ready) {
    $error = 'Kolom soft delete tidak tersedia. Periksa izin ALTER TABLE pada database.';
}

if (empty($_SESSION['barang_csrf_token'])) {
    $_SESSION['barang_csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['updated'])) $success = 'Data barang berhasil diperbarui.';
if (isset($_GET['deleted'])) $success = 'Barang dihapus secara soft delete. ID dan riwayat tetap tersimpan.';
if (isset($_GET['restored'])) $success = 'Barang berhasil dipulihkan.';

// Proses edit, soft delete, dan pemulihan barang.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_barang']) || isset($_POST['soft_delete_barang']) || isset($_POST['restore_barang']))) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['barang_csrf_token'], $submittedToken)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $getItem = $conn->prepare("SELECT id, code, name, category_id, total_quantity, available_quantity, `condition`, location, deleted_at FROM items WHERE id = ?");
        $getItem->bind_param('i', $item_id);
        $getItem->execute();
        $currentItem = $getItem->get_result()->fetch_assoc();
        $getItem->close();

        if (!$currentItem) {
            $error = 'Data barang tidak ditemukan.';
        } elseif (isset($_POST['soft_delete_barang'])) {
            if ($currentItem['deleted_at'] !== null) {
                $error = 'Barang ini sudah dihapus sebelumnya.';
            } else {
                $activeBorrowing = $conn->prepare("SELECT COUNT(*) AS total FROM borrowing_details bd JOIN borrowings b ON b.id = bd.borrowing_id WHERE bd.item_id = ? AND b.status IN ('dipinjam', 'terlambat')");
                $activeBorrowing->bind_param('i', $item_id);
                $activeBorrowing->execute();
                $activeCount = (int) $activeBorrowing->get_result()->fetch_assoc()['total'];
                $activeBorrowing->close();

                if ($activeCount > 0) {
                    $error = 'Barang tidak dapat dihapus karena masih tercatat dalam peminjaman aktif.';
                } else {
                    $conn->begin_transaction();
                    try {
                        $deleteStmt = $conn->prepare("UPDATE items SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                        $deleteStmt->bind_param('i', $item_id);
                        if (!$deleteStmt->execute() || $deleteStmt->affected_rows !== 1) throw new RuntimeException('Status barang tidak berhasil diubah.');
                        $deleteStmt->close();
                        $description = "Barang '{$currentItem['name']}' (kode '{$currentItem['code']}') dihapus secara soft delete.";
                        $logStmt = $conn->prepare("INSERT INTO item_logs (item_id, user_id, action, description) VALUES (?, ?, 'hapus', ?)");
                        $logStmt->bind_param('iis', $item_id, $user_id, $description);
                        if (!$logStmt->execute()) throw new RuntimeException($logStmt->error);
                        $logStmt->close();
                        $conn->commit();
                        header('Location: barang.php?deleted=1');
                        exit;
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = 'Gagal menghapus barang: ' . $e->getMessage();
                    }
                }
            }
        } elseif (isset($_POST['restore_barang'])) {
            if ($currentItem['deleted_at'] === null) {
                $error = 'Barang ini masih aktif.';
            } else {
                $conn->begin_transaction();
                try {
                    $restoreStmt = $conn->prepare("UPDATE items SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
                    $restoreStmt->bind_param('i', $item_id);
                    if (!$restoreStmt->execute() || $restoreStmt->affected_rows !== 1) throw new RuntimeException('Barang tidak berhasil dipulihkan.');
                    $restoreStmt->close();
                    $description = "Barang '{$currentItem['name']}' (kode '{$currentItem['code']}') dipulihkan.";
                    $logStmt = $conn->prepare("INSERT INTO item_logs (item_id, user_id, action, description) VALUES (?, ?, 'pulihkan', ?)");
                    $logStmt->bind_param('iis', $item_id, $user_id, $description);
                    if (!$logStmt->execute()) throw new RuntimeException($logStmt->error);
                    $logStmt->close();
                    $conn->commit();
                    header('Location: barang.php?restored=1');
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Gagal memulihkan barang: ' . $e->getMessage();
                }
            }
        } else {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $category_id = (int) ($_POST['category_id'] ?? 0);
            $quantity = (int) ($_POST['total_quantity'] ?? 0);
            $condition = $_POST['condition'] ?? '';
            $location = trim($_POST['location'] ?? '');
            $validConditions = ['baik', 'rusak_ringan', 'rusak_berat'];

            if ($currentItem['deleted_at'] !== null) {
                $error = 'Pulihkan barang terlebih dahulu sebelum mengeditnya.';
            } elseif ($code === '' || $name === '' || $category_id <= 0 || $quantity <= 0 || !in_array($condition, $validConditions, true)) {
                $error = 'Lengkapi data barang dengan benar.';
            } else {
                if ($category_id !== (int) $currentItem['category_id']) {
                    $categoryStmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
                    $categoryStmt->bind_param('i', $category_id);
                    $categoryStmt->execute();
                    $categoryRow = $categoryStmt->get_result()->fetch_assoc();
                    $categoryStmt->close();
                    if (!$categoryRow) {
                        $error = 'Kategori barang tidak ditemukan.';
                    } else {
                        $code = next_category_item_code($conn, $category_id, $categoryRow['name']);
                    }
                }

                $check = $conn->prepare("SELECT id FROM items WHERE code = ? AND id <> ?");
                if ($error === '') {
                    $check->bind_param('si', $code, $item_id);
                    $check->execute();
                    $check->store_result();
                    if ($check->num_rows > 0) $error = "Kode barang '{$code}' sudah digunakan.";
                    $check->close();
                } else {
                    $check->close();
                }

                $checkedOut = (int) $currentItem['total_quantity'] - (int) $currentItem['available_quantity'];
                if ($error === '' && $quantity < $checkedOut) {
                    $error = "Jumlah total tidak boleh kurang dari {$checkedOut} unit yang sedang dipinjam/tidak tersedia.";
                }
                if ($error === '') {
                    $available = $quantity - $checkedOut;
                    $stmt = $conn->prepare("UPDATE items SET category_id = ?, code = ?, name = ?, total_quantity = ?, available_quantity = ?, `condition` = ?, location = ? WHERE id = ? AND deleted_at IS NULL");
                    $stmt->bind_param('issiissi', $category_id, $code, $name, $quantity, $available, $condition, $location, $item_id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        $description = "Data barang '{$name}' (kode '{$code}') diperbarui.";
                        $logStmt = $conn->prepare("INSERT INTO item_logs (item_id, user_id, action, description) VALUES (?, ?, 'edit', ?)");
                        $logStmt->bind_param('iis', $item_id, $user_id, $description);
                        $logStmt->execute();
                        $logStmt->close();
                        header('Location: barang.php?edit=' . $item_id . '&updated=1');
                        exit;
                    }
                    $error = 'Gagal memperbarui barang: ' . $stmt->error;
                    $stmt->close();
                }
            }
        }
    }
}

// Proses Tambah Barang
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_barang'])) {
    $name        = trim($_POST['name']);
    $category_id = intval($_POST['category_id']);
    $quantity    = intval($_POST['total_quantity']);
    $condition   =$_POST['condition'];
    $location    = trim($_POST['location']);

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['barang_csrf_token'], (string) $_POST['csrf_token'])) {
        $error = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (!empty($name) && $category_id > 0 && $quantity > 0) {
        $categoryStmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
        $categoryStmt->bind_param('i', $category_id);
        $categoryStmt->execute();
        $categoryRow = $categoryStmt->get_result()->fetch_assoc();
        $categoryStmt->close();

        if (!$categoryRow) {
            $error = 'Kategori barang tidak ditemukan.';
        } else {
            // Kode dibuat otomatis dengan prefix dan urutan khusus untuk kategori terpilih.
            $code = next_category_item_code($conn, $category_id, $categoryRow['name']);
        // Cek apakah kode barang sudah ada
        $check =$conn->prepare("SELECT id FROM items WHERE code = ?");
        $check->bind_param("s", $code);
        $check->execute();$check->store_result();

        if ($check->num_rows > 0) {$error = "Kode barang '{$code}' sudah terdaftar di sistem!";
        } else {
            // Mulai transaksi
            $conn->begin_transaction();

            try {
                // 1. Insert ke tabel items (available_quantity disamakan dengan total_quantity saat pertama dibuat)
                $stmt =$conn->prepare("INSERT INTO items (category_id, code, name, total_quantity, available_quantity, `condition`, status, location) VALUES (?, ?, ?, ?, ?, ?, 'tersedia', ?)");
                $stmt->bind_param("issiiss", $category_id,$code, $name,$quantity, $quantity,$condition, $location);$stmt->execute();
                $item_id =$stmt->insert_id;
                $stmt->close();

                // 2. Catat ke tabel item_logs (Fitur 8)
                $log_desc = "Barang baru '{$name}' dengan kode '{$code}' ditambahkan sebanyak {$quantity} unit.";
                $log_stmt =$conn->prepare("INSERT INTO item_logs (item_id, user_id, action, description) VALUES (?, ?, 'tambah', ?)");
                $log_stmt->bind_param("iis", $item_id, $user_id,$log_desc);
                $log_stmt->execute();$log_stmt->close();

                $conn->commit();$success = "Data barang berhasil ditambahkan!";
            } catch (Exception $e) {
                $conn->rollback();$error = "Gagal menyimpan data: " . $e->getMessage();
            }
        }
        $check->close();
        }
    } else {
        $error = "Semua kolom wajib diisi dengan benar!";
    }
}

// Ambil data barang yang akan diedit.
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $editStmt = $conn->prepare("SELECT * FROM items WHERE id = ? AND deleted_at IS NULL");
    $editStmt->bind_param('i', $edit_id);
    $editStmt->execute();
    $edit_data = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();
}

// Ambil kategori dan contoh kode berikutnya untuk dropdown tambah barang.
$categoryResult = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$categoryRows = $categoryResult ? $categoryResult->fetch_all(MYSQLI_ASSOC) : [];
$newCodePreview = !empty($categoryRows)
    ? next_category_item_code($conn, (int) $categoryRows[0]['id'], $categoryRows[0]['name'])
    : '';

// Navigasi kategori dan pagination barang memakai state terpisah.
$categoryPageSize = 4;
$categoryTotalPages = max(1, (int) ceil(count($categoryRows) / $categoryPageSize));
$categoryPage = max(1, min((int) ($_GET['category_page'] ?? 1), $categoryTotalPages));
$categoryOffset = ($categoryPage - 1) * $categoryPageSize;
$visibleCategories = array_slice($categoryRows, $categoryOffset, $categoryPageSize);
$selectedCategoryId = (int) ($_GET['category_id'] ?? 0);
$validCategoryIds = array_map(static fn($category) => (int) $category['id'], $categoryRows);
if ($selectedCategoryId !== 0 && !in_array($selectedCategoryId, $validCategoryIds, true)) {
    $selectedCategoryId = 0;
}
$selectedCategoryName = '';
foreach ($categoryRows as $categoryRow) {
    if ((int) $categoryRow['id'] === $selectedCategoryId) {
        $selectedCategoryName = $categoryRow['name'];
        break;
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$itemsPerPage = 10;
$countItemsStmt = $conn->prepare("SELECT COUNT(*) AS total FROM items JOIN categories ON items.category_id = categories.id WHERE (? = 0 OR items.category_id = ?) AND (? = '' OR items.name LIKE ? OR items.code LIKE ?)");
$searchTerm = '%' . $search . '%';
$countItemsStmt->bind_param('iisss', $selectedCategoryId, $selectedCategoryId, $search, $searchTerm, $searchTerm);
$countItemsStmt->execute();
$totalItems = (int) $countItemsStmt->get_result()->fetch_assoc()['total'];
$countItemsStmt->close();
$itemsTotalPages = max(1, (int) ceil($totalItems / $itemsPerPage));
$itemsPage = max(1, min((int) ($_GET['item_page'] ?? 1), $itemsTotalPages));
$itemsOffset = ($itemsPage - 1) * $itemsPerPage;

$itemsStmt = $conn->prepare("SELECT items.*, categories.name as category_name FROM items JOIN categories ON items.category_id = categories.id WHERE (? = 0 OR items.category_id = ?) AND (? = '' OR items.name LIKE ? OR items.code LIKE ?) ORDER BY categories.name ASC, items.name ASC, items.id DESC LIMIT ? OFFSET ?");
$itemsStmt->bind_param('iisssii', $selectedCategoryId, $selectedCategoryId, $search, $searchTerm, $searchTerm, $itemsPerPage, $itemsOffset);
$itemsStmt->execute();
$items = $itemsStmt->get_result();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendataan Barang - Inventaris Lab Sains</title>
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
                <a href="barang.php" class="app-nav-link is-active">📦 Pendataan Barang</a>
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
            <h2 class="text-lg font-semibold text-slate-800">Pendataan Barang Laboratorium</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-indigo-600 uppercase">Admin</span></div>
        </header>

        <!-- Content Body -->
        <main class="p-8 flex-1 space-y-6 app-main">

            <!-- Notifikasi Pesan -->
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

            <!-- Form Tambah Barang & Tabel Data -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Kolom Kiri: Form Tambah atau Edit Barang -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm h-fit">
                    <h3 class="font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100"><?php echo $edit_data ? '✏️ Edit Data Barang' : '➕ Tambah Barang Baru'; ?></h3>
                    <form action="" method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['barang_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($edit_data): ?>
                            <input type="hidden" name="item_id" value="<?php echo (int) $edit_data['id']; ?>">
                        <?php endif; ?>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Kode / Barcode Barang</label>
                            <input type="text" id="item_code" name="code" required readonly value="<?php echo htmlspecialchars($edit_data['code'] ?? $newCodePreview, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Kode otomatis sesuai kategori" 
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <small class="text-secondary">Kode akan dibuat otomatis dari kategori dan nomor urut berikutnya.</small>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Nama Barang</label>
                            <input type="text" name="name" required value="<?php echo htmlspecialchars($edit_data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Contoh: Gelas Ukur 50ml" 
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Kategori</label>
                            <select name="category_id" id="category_id" required onchange="updateItemCodePreview()" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                                <?php if (empty($categoryRows)): ?>
                                    <option value="" selected>Tidak ada kategori tersedia</option>
                                <?php else: ?>
                                    <?php foreach ($categoryRows as $categoryIndex => $cat): ?>
                                        <?php $nextCode = next_category_item_code($conn, (int) $cat['id'], $cat['name']); ?>
                                        <?php $isSelected = isset($edit_data['category_id']) ? (int) $edit_data['category_id'] === (int) $cat['id'] : $categoryIndex === 0; ?>
                                        <option value="<?php echo (int) $cat['id']; ?>" data-next-code="<?php echo htmlspecialchars($nextCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isSelected ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Jumlah Total</label>
                                <input type="number" name="total_quantity" min="1" required value="<?php echo htmlspecialchars((string) ($edit_data['total_quantity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="10" 
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Kondisi</label>
                                <select name="condition" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                                    <option value="baik" <?php echo ($edit_data['condition'] ?? 'baik') === 'baik' ? 'selected' : ''; ?>>Baik</option>
                                    <option value="rusak_ringan" <?php echo ($edit_data['condition'] ?? '') === 'rusak_ringan' ? 'selected' : ''; ?>>Rusak Ringan</option>
                                    <option value="rusak_berat" <?php echo ($edit_data['condition'] ?? '') === 'rusak_berat' ? 'selected' : ''; ?>>Rusak Berat</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Lokasi Penyimpanan</label>
                            <input type="text" name="location" value="<?php echo htmlspecialchars($edit_data['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Contoh: Rak A-2" 
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <button type="submit" name="<?php echo $edit_data ? 'update_barang' : 'tambah_barang'; ?>" value="1"
                            class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-lg text-sm transition shadow-sm">
                            <?php echo $edit_data ? 'Simpan Perubahan' : 'Simpan Barang'; ?>
                        </button>
                        <?php if ($edit_data): ?>
                            <a href="barang.php" class="block text-center text-sm text-slate-500 hover:text-slate-700">Batal edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Kolom Kanan: Daftar Tabel Barang -->
                <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                    <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <h3 class="font-bold text-slate-800">Definisi & Stok Inventaris Lab</h3>
                        <!-- Form Pencarian -->
                        <form action="" method="GET" class="flex gap-2 w-full sm:w-auto">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama / kode..." 
                                class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full">
                            <?php if ($selectedCategoryId > 0): ?><input type="hidden" name="category_id" value="<?php echo $selectedCategoryId; ?>"><?php endif; ?>
                            <input type="hidden" name="category_page" value="<?php echo $categoryPage; ?>">
                            <input type="hidden" name="item_page" value="1">
                            <button type="submit" class="bg-slate-800 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-slate-700 transition">Cari</button>
                        </form>
                    </div>

                    <div class="border-bottom bg-light px-4 py-3">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                            <div>
                                <div class="fw-semibold text-dark mb-2">Pilih kategori</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="<?php echo htmlspecialchars(barang_page_url(['category_id' => null, 'item_page' => 1]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang" class="btn btn-sm <?php echo $selectedCategoryId === 0 ? 'btn-primary' : 'btn-outline-primary'; ?>">Semua kategori</a>
                                    <?php foreach ($visibleCategories as $category): ?>
                                        <a href="<?php echo htmlspecialchars(barang_page_url(['category_id' => (int) $category['id'], 'item_page' => 1]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang" class="btn btn-sm <?php echo $selectedCategoryId === (int) $category['id'] ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($categoryTotalPages > 1): ?>
                                <nav aria-label="Navigasi halaman kategori">
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item <?php echo $categoryPage <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(barang_page_url(['category_page' => max(1, $categoryPage - 1)]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang" aria-label="Kategori sebelumnya">‹</a>
                                        </li>
                                        <?php for ($categoryPageNumber = 1; $categoryPageNumber <= $categoryTotalPages; $categoryPageNumber++): ?>
                                            <li class="page-item <?php echo $categoryPageNumber === $categoryPage ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo htmlspecialchars(barang_page_url(['category_page' => $categoryPageNumber]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang"><?php echo $categoryPageNumber; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $categoryPage >= $categoryTotalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(barang_page_url(['category_page' => min($categoryTotalPages, $categoryPage + 1)]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang" aria-label="Kategori berikutnya">›</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                        <small class="d-block text-secondary mt-2">
                            <?php if ($selectedCategoryId > 0): ?>
                                Menampilkan kategori: <?php echo htmlspecialchars($selectedCategoryName); ?>
                            <?php else: ?>
                                Kategori halaman <?php echo $categoryPage; ?> dari <?php echo $categoryTotalPages; ?>; barang dikelompokkan menurut kategori.
                            <?php endif; ?>
                        </small>
                    </div>

                    <div id="daftar-barang" class="overflow-x-auto flex-1">
                        <table class="table table-hover align-middle w-full text-left text-sm text-slate-600">
                            <thead class="bg-slate-50 text-slate-700 uppercase text-xs">
                                <tr>
                                    <th class="px-4 py-3">Kode</th>
                                    <th class="px-4 py-3">Nama Barang</th>
                                    <th class="px-4 py-3 text-center">Stok (Tersedia/Total)</th>
                                    <th class="px-4 py-3">Kondisi</th>
                                    <th class="px-4 py-3">Lokasi</th>
                                    <th class="px-4 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($items &&$items->num_rows > 0): ?>
                                    <?php $currentCategoryId = null; ?>
                                    <?php while ($row =$items->fetch_assoc()): ?>
                                    <?php if ($currentCategoryId !== (int) $row['category_id']): $currentCategoryId = (int) $row['category_id']; ?>
                                    <tr class="table-light">
                                        <th colspan="6" class="px-4 py-3 text-indigo-700">
                                            <span class="badge text-bg-primary me-2">Kategori</span>
                                            <?php echo htmlspecialchars($row['category_name']); ?>
                                        </th>
                                    </tr>
                                    <?php endif; ?>
                                    <tr class="hover:bg-slate-50 <?php echo $row['deleted_at'] !== null ? 'bg-slate-50 opacity-70' : ''; ?>">
                                        <td class="px-4 py-3 font-mono font-medium text-slate-800"><?php echo htmlspecialchars($row['code']); ?></td>
                                        <td class="px-4 py-3 font-medium text-slate-800">
                                            <?php echo htmlspecialchars($row['name']); ?>
                                            <?php if ($row['deleted_at'] !== null): ?><span class="ml-1 rounded bg-rose-100 px-2 py-0.5 text-xs text-rose-700">Dihapus</span><?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="font-semibold text-slate-700"><?php echo $row['available_quantity']; ?></span> / 
                                            <span class="text-slate-400"><?php echo $row['total_quantity']; ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold 
                                                <?php 
                                                    if($row['condition'] == 'baik') echo 'bg-emerald-50 text-emerald-600';
                                                    elseif($row['condition'] == 'rusak_ringan') echo 'bg-amber-50 text-amber-600';
                                                    else echo 'bg-red-50 text-red-600';
                                                ?>">
                                                <?php echo ucfirst($row['condition']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-xs font-mono text-slate-500"><?php echo htmlspecialchars($row['location']); ?></td>
                                        <td class="px-4 py-3 text-center whitespace-nowrap">
                                            <?php if ($row['deleted_at'] === null): ?>
                                                <a href="barang.php?edit=<?php echo (int) $row['id']; ?>" class="inline-block rounded bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600">Edit</a>
                                                <form action="" method="POST" class="inline-block" onsubmit="return confirm('Hapus barang ini secara non-permanen? Riwayatnya tetap tersimpan.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['barang_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int) $row['id']; ?>">
                                                    <button type="submit" name="soft_delete_barang" value="1" class="rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">Hapus</button>
                                                </form>
                                            <?php else: ?>
                                                <form action="" method="POST" class="inline-block" onsubmit="return confirm('Pulihkan barang ini?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['barang_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int) $row['id']; ?>">
                                                    <button type="submit" name="restore_barang" value="1" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">Pulihkan</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-8 text-center text-slate-400">Belum ada data barang ditemukan.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 border-top px-4 py-3">
                        <?php
                            $firstItem = $totalItems === 0 ? 0 : $itemsOffset + 1;
                            $lastItem = min($itemsOffset + $itemsPerPage, $totalItems);
                            $startItemPage = max(1, $itemsPage - 2);
                            $endItemPage = min($itemsTotalPages, $itemsPage + 2);
                        ?>
                        <small class="text-secondary">Menampilkan <?php echo $firstItem; ?>–<?php echo $lastItem; ?> dari <?php echo $totalItems; ?> barang</small>
                        <?php if ($itemsTotalPages > 1): ?>
                            <nav aria-label="Navigasi halaman barang">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $itemsPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(barang_page_url(['item_page' => max(1, $itemsPage - 1)]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang">Sebelumnya</a>
                                    </li>
                                    <?php for ($itemPageNumber = $startItemPage; $itemPageNumber <= $endItemPage; $itemPageNumber++): ?>
                                        <li class="page-item <?php echo $itemPageNumber === $itemsPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(barang_page_url(['item_page' => $itemPageNumber]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang"><?php echo $itemPageNumber; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $itemsPage >= $itemsTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(barang_page_url(['item_page' => min($itemsTotalPages, $itemsPage + 1)]), ENT_QUOTES, 'UTF-8'); ?>#daftar-barang">Berikutnya</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>
<script>
function updateItemCodePreview() {
    const categorySelect = document.getElementById('category_id');
    const codeInput = document.getElementById('item_code');
    const selectedOption = categorySelect && categorySelect.options[categorySelect.selectedIndex];
    if (selectedOption && selectedOption.dataset.nextCode) {
        codeInput.value = selectedOption.dataset.nextCode;
    }
}
</script>
</body>
</html>