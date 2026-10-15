<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_user'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $nik      = trim($_POST['nik'] ?? '');
    $alamat   = trim($_POST['alamat'] ?? '');
    $passwordInput = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';
    $status   = 'aktif';

    if ($name === '' || $email === '' || $passwordInput === '' || $alamat === '') {
        $error = 'Nama, email, password, NIK, dan alamat wajib diisi.';
    } elseif (!preg_match('/^[0-9]{16}$/', $nik)) {
        $error = 'NIK harus terdiri dari tepat 16 digit angka.';
    } elseif (!in_array($role, ['admin', 'peminjam'], true)) {
        $error = 'Role pengguna tidak valid.';
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Email tersebut sudah terdaftar di sistem!";
        } else {
            $nikCheck = $conn->prepare("SELECT id FROM users WHERE nik = ?");
            $nikCheck->bind_param('s', $nik);
            $nikCheck->execute();
            $nikCheck->store_result();
            if ($nikCheck->num_rows > 0) {
                $error = 'NIK tersebut sudah digunakan oleh pengguna lain.';
            } else {
                $password = password_hash($passwordInput, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, nik, alamat, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->bind_param('sssssss', $name, $email, $password, $nik, $alamat, $role, $status);
                if ($stmt->execute()) {
                    $success = 'Pengguna baru berhasil ditambahkan!';
                } else {
                    $error = 'Gagal menambahkan pengguna: ' . $stmt->error;
                }
                $stmt->close();
            }
            $nikCheck->close();
        }
        $check->close();
    }
}

// 2. Proses Edit Pengguna
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $id       = intval($_POST['id']);
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

// 3. Proses Hapus Pengguna
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id_user = intval($_GET['id']);

    if ($id_user === intval($_SESSION['user_id'])) {
        $error = "Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif!";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id_user);
        if ($stmt->execute()) {
            $success = "Pengguna berhasil dihapus dari sistem.";
        } else {
            $error = "Gagal menghapus pengguna.";
        }
        $stmt->close();
    }
}

// Ambil data user yang mau diedit jika ada parameter ?action=edit&id=...
$edit_data = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $q_edit = $conn->prepare("SELECT id, name, email, nik, alamat, role, status FROM users WHERE id = ?");
    $q_edit->bind_param("i", $edit_id);
    $q_edit->execute();
    $res_edit = $q_edit->get_result();
    if ($res_edit->num_rows > 0) {
        $edit_data = $res_edit->fetch_assoc();
    }
    $q_edit->close();
}

// Ambil daftar seluruh pengguna dari database
$users_list = $conn->query("SELECT id, name, email, nik, alamat, role, status, created_at FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pengguna - Inventaris Lab Sains</title>
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
                <a href="riwayat_transaksi.php" class="app-nav-link">📜 Riwayat / Arsip</a>
                <a href="pengguna.php" class="app-nav-link is-active">👥 Manajemen Pengguna</a>
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
            <h2 class="text-lg font-semibold text-slate-800">Manajemen Akun Pengguna Sistem</h2>
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

            <?php if ($edit_data): ?>
                <!-- Form Edit Pengguna -->
                <div class="bg-amber-50 p-6 rounded-xl border border-amber-200 shadow-sm">
                    <div class="flex justify-between items-center mb-4 pb-2 border-b border-amber-200">
                        <h3 class="font-bold text-amber-900">✏️ Edit Akun Pengguna: <?php echo htmlspecialchars($edit_data['name']); ?></h3>
                        <a href="pengguna.php" class="text-xs bg-slate-200 hover:bg-slate-300 text-slate-700 px-3 py-1 rounded-lg transition font-medium">Batal Edit</a>
                    </div>
                    <form action="pengguna.php" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Nama Lengkap</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($edit_data['name']); ?>" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($edit_data['email']); ?>" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">NIK / Nomor KTP</label>
                            <input type="text" name="nik" value="<?php echo htmlspecialchars($edit_data['nik'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" minlength="16" required placeholder="16 digit NIK" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Alamat Lengkap</label>
                            <textarea name="alamat" rows="2" maxlength="500" required placeholder="Jalan, RT/RW, kelurahan, kecamatan, kota/kabupaten" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500"><?php echo htmlspecialchars($edit_data['alamat'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Password Baru <span class="text-xs text-slate-400 font-normal">(Opsional)</span></label>
                            <input type="password" name="password" placeholder="Kosongkan jika tetap" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Role</label>
                            <select name="role" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500">
                                <option value="admin" <?php echo $edit_data['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="peminjam" <?php echo $edit_data['role'] == 'peminjam' ? 'selected' : ''; ?>>Peminjam</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Status Akun</label>
                            <select name="status" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-amber-500">
                                <option value="aktif" <?php echo $edit_data['status'] == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                <option value="nonaktif" <?php echo $edit_data['status'] == 'nonaktif' ? 'selected' : ''; ?>>Nonaktif</option>
                            </select>
                        </div>

                        <div class="md:col-span-3 flex justify-end">
                            <button type="submit" name="edit_user" class="bg-amber-600 hover:bg-amber-700 text-white font-medium px-6 py-2.5 rounded-lg text-sm transition shadow-sm">
                                Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Form Tambah Pengguna Baru -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100">➕ Tambah Akun Pengguna Baru</h3>
                    <form action="" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Nama Lengkap</label>
                            <input type="text" name="name" required placeholder="Contoh: Dr. Ahmad" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Email</label>
                            <input type="email" name="email" required placeholder="Contoh: ahmad@lab.ac.id" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">NIK / Nomor KTP</label>
                            <input type="text" name="nik" required inputmode="numeric" pattern="[0-9]{16}" minlength="16" maxlength="16" placeholder="16 digit NIK" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 mb-1">Alamat Lengkap</label>
                            <textarea name="alamat" rows="2" maxlength="500" required placeholder="Jalan, RT/RW, kelurahan, kecamatan, kota/kabupaten" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Password</label>
                            <input type="password" name="password" required placeholder="••••••••" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Role / Hak Akses</label>
                            <select name="role" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                                <option value="admin">Admin</option>
                                <option value="peminjam">Peminjam</option>
                            </select>
                        </div>

                        <div class="md:col-span-3 flex justify-end">
                            <button type="submit" name="tambah_user" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-lg text-sm transition shadow-sm">
                                Simpan Pengguna Baru
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Tabel Daftar Pengguna -->
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100">
                    <h3 class="font-bold text-slate-800">Daftar Akun yang Terdaftar di Database</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-hover align-middle w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-50 text-slate-700 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Nama Lengkap</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">NIK / KTP</th>
                                <th class="px-4 py-3">Alamat</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Terdaftar Sejak</th>
                                <th class="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($users_list && $users_list->num_rows > 0): ?>
                                <?php while ($row = $users_list->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-mono text-xs">#<?php echo $row['id']; ?></td>
                                    <td class="px-4 py-3 font-medium text-slate-800"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td class="px-4 py-3 font-mono text-xs"><?php echo htmlspecialchars($row['nik'] ?: 'Belum diisi'); ?></td>
                                    <td class="px-4 py-3 text-xs" style="max-width: 18rem; white-space: normal" title="<?php echo htmlspecialchars($row['alamat'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($row['alamat'] ?: 'Belum diisi'); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $row['role'] == 'admin' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600'; ?>">
                                            <?php echo ucfirst($row['role']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $row['status'] == 'aktif' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'; ?>">
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs"><?php echo date('d M Y, H:i', strtotime($row['created_at'])); ?></td>
                                    <td class="px-4 py-3 text-center space-x-1">
                                        <a href="pengguna.php?action=edit&id=<?php echo $row['id']; ?>" 
                                           class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition shadow-sm inline-block">
                                            ✏️ Edit
                                        </a>
                                        <?php if (intval($row['id']) !== intval($_SESSION['user_id'])): ?>
                                            <a href="pengguna.php?action=delete&id=<?php echo $row['id']; ?>" 
                                               onclick="return confirm('Yakin ingin menghapus akun ini?');"
                                               class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition shadow-sm inline-block">
                                                🗑️ Hapus
                                            </a>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">Akun Anda</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="px-6 py-8 text-center text-slate-400">Belum ada pengguna lain yang terdaftar.</td>
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