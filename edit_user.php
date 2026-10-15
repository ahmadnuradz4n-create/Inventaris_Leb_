<?php
session_start();
include 'koneksi.php';

// Cek apakah user adalah admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$success = '';
$error = '';

// Ambil ID dari parameter URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: pengguna.php");
    exit;
}

$id = intval($_GET['id']);

// Proses Simpan Perubahan Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $nik      = trim($_POST['nik'] ?? '');
    $alamat   = trim($_POST['alamat'] ?? '');
    $role     = $_POST['role'] ?? '';
    $status   = $_POST['status'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $alamat === '') {
        $error = 'Nama, email, dan alamat wajib diisi.';
    } elseif (!preg_match('/^[0-9]{16}$/', $nik)) {
        $error = 'NIK harus terdiri dari tepat 16 digit angka.';
    } elseif (!in_array($role, ['admin', 'peminjam'], true) || !in_array($status, ['aktif', 'nonaktif'], true)) {
        $error = 'Role atau status pengguna tidak valid.';
    } else {
        $check = $conn->prepare("SELECT id, email, nik FROM users WHERE (email = ? OR nik = ?) AND id != ?");
        $check->bind_param('ssi', $email, $nik, $id);
        $check->execute();
        $duplicates = $check->get_result();
        while ($duplicate = $duplicates->fetch_assoc()) {
            if ($duplicate['email'] === $email) $error = 'Email sudah digunakan oleh pengguna lain.';
            if ($duplicate['nik'] === $nik) $error = 'NIK sudah digunakan oleh pengguna lain.';
        }

        if ($error === '') {
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, nik = ?, alamat = ?, password = ?, role = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('sssssssi', $name, $email, $nik, $alamat, $hashed_password, $role, $status, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, nik = ?, alamat = ?, role = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssssssi', $name, $email, $nik, $alamat, $role, $status, $id);
            }

            if ($stmt->execute()) {
                $success = "Data pengguna berhasil diperbarui!";
            } else {
                $error = "Gagal memperbarui pengguna: " . $stmt->error;
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Ambil data user berdasarkan ID
$stmt_get = $conn->prepare("SELECT id, name, email, nik, alamat, role, status FROM users WHERE id = ?");
$stmt_get->bind_param("i", $id);
$stmt_get->execute();
$result = $stmt_get->get_result();

if ($result->num_rows === 0) {
    header("Location: pengguna.php");
    exit;
}
$user = $result->fetch_assoc();
$stmt_get->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pengguna - Inventaris Lab Sains</title>
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
                <a href="transaksi.php" class="app-nav-link">📋 Transaksi Aktif</a>
                <a href="riwayat_transaksi.php" class="app-nav-link">📜 Riwayat / Arsip</a>
                <a href="pengguna.php" class="app-nav-link is-active">👥 Manajemen Pengguna</a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800">
            <a href="logout.php" class="flex items-center gap-2 px-4 py-2 bg-red-600/20 text-red-400 hover:bg-red-600 hover:text-white rounded-lg text-sm font-medium transition text-center justify-center">🚪 Keluar Sistem</a>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col app-content">
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center app-header">
            <h2 class="text-lg font-semibold text-slate-800">Edit Akun Pengguna</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-indigo-600 uppercase">Admin</span></div>
        </header>

        <main class="p-8 flex-1 space-y-6 max-w-4xl app-main">
            <!-- Tombol Kembali -->
            <div>
                <a href="pengguna.php" class="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                    ← Kembali ke Manajemen Pengguna
                </a>
            </div>

            <?php if (!empty($success)): ?>
                <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-lg flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span>✅</span><span><?php echo $success; ?></span>
                    </div>
                    <a href="pengguna.php" class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded-lg hover:bg-emerald-700 font-medium transition">Lihat Daftar User</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="p-4 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg flex items-center gap-2">
                    <span>⚠️</span><span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <!-- Form Edit -->
            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h3 class="font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100">✏️ Form Perubahan Data: <?php echo htmlspecialchars($user['name']); ?></h3>
                
                <form action="" method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Nama Lengkap</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">NIK / Nomor KTP</label>
                            <input type="text" name="nik" value="<?php echo htmlspecialchars($user['nik'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" pattern="[0-9]{16}" minlength="16" maxlength="16" required placeholder="16 digit NIK" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Alamat Lengkap</label>
                            <textarea name="alamat" rows="2" maxlength="500" required placeholder="Jalan, RT/RW, kelurahan, kecamatan, kota/kabupaten" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($user['alamat'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Password Baru <span class="text-slate-400 font-normal">(Opsional)</span></label>
                            <input type="password" name="password" placeholder="Kosongkan jika tidak diubah" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Role / Hak Akses</label>
                            <select name="role" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                                <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="peminjam" <?php echo $user['role'] == 'peminjam' ? 'selected' : ''; ?>>Peminjam</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Status Akun</label>
                            <select name="status" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                                <option value="aktif" <?php echo $user['status'] == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                <option value="nonaktif" <?php echo $user['status'] == 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <a href="pengguna.php" class="px-5 py-2.5 border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium rounded-lg text-sm transition">
                            Batal
                        </a>
                        <button type="submit" name="update_user" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-lg text-sm transition shadow-sm">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>