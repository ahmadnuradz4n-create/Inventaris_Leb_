<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['backup_csrf_token'])) {
    $_SESSION['backup_csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';
$maxUploadBytes = 100 * 1024 * 1024;
$mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$mysqlPath = 'C:\\xampp\\mysql\\bin\\mysql.exe';

function backup_ini_value($value)
{
    return '"' . str_replace(
        ["\\", '"', "\r", "\n"],
        ["\\\\", '\\"', '\\r', '\\n'],
        (string) $value
    ) . '"';
}

function create_mysql_defaults_file($host, $user, $password)
{
    $path = tempnam(sys_get_temp_dir(), 'inv-mysql-');
    if ($path === false) {
        throw new RuntimeException('Tidak dapat membuat file konfigurasi sementara.');
    }

    $contents = "[client]\n"
        . 'host=' . backup_ini_value($host) . "\n"
        . 'user=' . backup_ini_value($user) . "\n"
        . 'password=' . backup_ini_value($password) . "\n"
        . "default-character-set=utf8mb4\n";

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        @unlink($path);
        throw new RuntimeException('Tidak dapat menulis konfigurasi MySQL sementara.');
    }

    @chmod($path, 0600);
    return $path;
}

function run_mysql_command(array $command, $inputFile = null)
{
    $stdoutPath = tempnam(sys_get_temp_dir(), 'inv-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'inv-err-');
    if ($stdoutPath === false || $stderrPath === false) {
        if ($stdoutPath) @unlink($stdoutPath);
        if ($stderrPath) @unlink($stderrPath);
        throw new RuntimeException('Tidak dapat membuat file proses sementara.');
    }

    $descriptors = [
        0 => $inputFile ? ['file', $inputFile, 'r'] : ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];

    try {
        $process = proc_open($command, $descriptors, $pipes, __DIR__, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Tidak dapat menjalankan utilitas MySQL.');
        }
        if (!$inputFile && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $exitCode = proc_close($process);
        $output = trim((string) file_get_contents($stdoutPath));
        $errors = trim((string) file_get_contents($stderrPath));
        return [$exitCode, trim($output . "\n" . $errors)];
    } finally {
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['backup_csrf_token'], $submittedToken)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (isset($_POST['create_backup'])) {
        $dumpFile = tempnam(sys_get_temp_dir(), 'inventaris-backup-');
        $defaultsFile = null;

        try {
            if (!is_file($mysqldumpPath)) {
                throw new RuntimeException('mysqldump tidak ditemukan. Pastikan XAMPP/MySQL terpasang di C:\\xampp.');
            }
            if ($dumpFile === false) {
                throw new RuntimeException('Tidak dapat membuat file backup sementara.');
            }

            $defaultsFile = create_mysql_defaults_file($host, $user, $pass);
            $command = [
                $mysqldumpPath,
                '--defaults-extra-file=' . $defaultsFile,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                '--add-drop-table',
                '--default-character-set=utf8mb4',
                '--result-file=' . $dumpFile,
                $db,
            ];
            [$exitCode, $commandOutput] = run_mysql_command($command);

            if ($exitCode !== 0 || !is_file($dumpFile) || filesize($dumpFile) === 0) {
                throw new RuntimeException('Backup gagal dibuat.' . ($commandOutput !== '' ? ' Detail: ' . $commandOutput : ''));
            }

            $downloadName = preg_replace('/[^A-Za-z0-9_-]/', '_', $db) . '_backup_' . date('Y-m-d_H-i-s') . '.sql';
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($dumpFile));
            header('X-Content-Type-Options: nosniff');
            readfile($dumpFile);
            @unlink($dumpFile);
            @unlink($defaultsFile);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if ($dumpFile) @unlink($dumpFile);
            if ($defaultsFile) @unlink($defaultsFile);
        }
    } elseif (isset($_POST['restore_backup'])) {
        $defaultsFile = null;
        try {
            if (!is_file($mysqlPath)) {
                throw new RuntimeException('mysql tidak ditemukan. Pastikan XAMPP/MySQL terpasang di C:\\xampp.');
            }
            if (empty($_POST['confirm_restore'])) {
                throw new RuntimeException('Centang konfirmasi sebelum menjalankan restore.');
            }
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Pilih file backup SQL yang valid untuk diunggah.');
            }

            $upload = $_FILES['backup_file'];
            if ($upload['size'] <= 0 || $upload['size'] > $maxUploadBytes) {
                throw new RuntimeException('Ukuran file harus lebih dari 0 dan tidak boleh melebihi 100 MB.');
            }
            if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'sql' || !is_uploaded_file($upload['tmp_name'])) {
                throw new RuntimeException('File restore harus berupa file .sql yang diunggah melalui formulir ini.');
            }

            $defaultsFile = create_mysql_defaults_file($host, $user, $pass);
            $command = [
                $mysqlPath,
                '--defaults-extra-file=' . $defaultsFile,
                '--default-character-set=utf8mb4',
                '--database=' . $db,
            ];
            [$exitCode, $commandOutput] = run_mysql_command($command, $upload['tmp_name']);

            if ($exitCode !== 0) {
                throw new RuntimeException('Restore gagal atau dihentikan oleh MySQL.' . ($commandOutput !== '' ? ' Detail: ' . $commandOutput : ''));
            }

            $success = 'Restore database berhasil. Muat ulang halaman atau login kembali jika data pengguna berubah.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if ($defaultsFile) @unlink($defaultsFile);
        }
    } elseif (isset($_POST['truncate_data'])) {
        if (($_POST['confirm_truncate_text'] ?? '') !== 'HAPUS DATA' || empty($_POST['confirm_truncate_checkbox'])) {
            $error = 'Centang konfirmasi dan ketik HAPUS DATA dengan tepat untuk mengonfirmasi penghapusan.';
        } else {
            $foreignKeysDisabled = false;
            try {
                $tablesResult = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                if (!$tablesResult) {
                    throw new RuntimeException('Daftar tabel database tidak dapat dibaca.');
                }

                $tablesToClear = [];
                $usersTableExists = false;
                while ($tableRow = $tablesResult->fetch_row()) {
                    $tableName = $tableRow[0];
                    if (strtolower($tableName) === 'users') {
                        $usersTableExists = true;
                        continue;
                    }
                    $tablesToClear[] = $tableName;
                }
                $tablesResult->free();

                if (!$usersTableExists) {
                    throw new RuntimeException('Tabel users tidak ditemukan; penghapusan dibatalkan untuk melindungi akun.');
                }

                if (!$conn->query('SET FOREIGN_KEY_CHECKS = 0')) {
                    throw new RuntimeException('Tidak dapat menonaktifkan pemeriksaan relasi tabel.');
                }
                $foreignKeysDisabled = true;

                foreach ($tablesToClear as $tableName) {
                    $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
                    if (!$conn->query('TRUNCATE TABLE ' . $quotedTable)) {
                        throw new RuntimeException('Gagal mengosongkan tabel ' . $tableName . ': ' . $conn->error);
                    }
                }

                $success = 'Reset selesai. ' . count($tablesToClear) . ' tabel selain users telah dikosongkan; data akun pengguna tetap disimpan.';
            } catch (Throwable $e) {
                $error = 'Reset tidak berhasil diselesaikan sepenuhnya. ' . $e->getMessage();
            } finally {
                if ($foreignKeysDisabled) {
                    $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup &amp; Restore - Inventaris Lab Sains</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-slate-100 flex min-h-screen app-shell">
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
                <a href="pengguna.php" class="app-nav-link">👥 Manajemen Pengguna</a>
                <a href="backup.php" class="app-nav-link is-active">📁 Backup &amp; Restore</a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800">
            <a href="logout.php" class="flex items-center gap-2 px-4 py-2 bg-red-600/20 text-red-400 hover:bg-red-600 hover:text-white rounded-lg text-sm font-medium transition text-center justify-center">🚪 Keluar Sistem</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 app-content">
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center app-header">
            <h2 class="text-lg font-semibold text-slate-800">Backup &amp; Restore Database</h2>
            <div class="text-sm text-slate-500">Role: <span class="font-semibold text-indigo-600 uppercase">Admin</span></div>
        </header>

        <main class="p-4 md:p-8 flex-1 space-y-6 max-w-5xl w-full mx-auto app-main">
            <?php if ($success !== ''): ?>
                <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-lg">✅ <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg">⚠️ <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 md:p-8">
                <div class="flex items-start gap-4">
                    <div class="text-3xl">📥</div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800 text-lg">Buat Backup Database</h3>
                        <p class="text-sm text-slate-600 mt-2">Unduh backup lengkap database <strong><?php echo htmlspecialchars($db, ENT_QUOTES, 'UTF-8'); ?></strong>, termasuk struktur tabel, data, trigger, prosedur, dan event.</p>
                        <form method="POST" class="mt-5">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['backup_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="create_backup" value="1" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm transition shadow-sm">Unduh Backup SQL</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="bg-white rounded-xl border border-red-200 shadow-sm p-6 md:p-8">
                <div class="flex items-start gap-4">
                    <div class="text-3xl">⚠️</div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800 text-lg">Restore Database</h3>
                        <p class="text-sm text-slate-600 mt-2">Restore menjalankan isi file SQL terhadap database aplikasi. Proses dapat menimpa atau menghapus data saat ini. Buat backup terbaru terlebih dahulu dan unggah hanya file SQL tepercaya dari database ini.</p>
                        <form method="POST" enctype="multipart/form-data" class="mt-5 space-y-4" onsubmit="return confirm('Restore dapat menimpa atau menghapus data sekarang. Lanjutkan?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['backup_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div>
                                <label for="backup_file" class="block text-sm font-medium text-slate-700 mb-1">File backup (.sql, maks. 100 MB)</label>
                                <input id="backup_file" type="file" name="backup_file" accept=".sql,application/sql,text/plain" required class="block w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                            </div>
                            <label class="flex items-start gap-2 text-sm text-red-700">
                                <input type="checkbox" name="confirm_restore" value="1" required class="mt-1">
                                <span>Saya memahami restore dapat mengganti data database dan saya sudah memilih file backup yang benar.</span>
                            </label>
                            <button type="submit" name="restore_backup" value="1" class="bg-red-600 hover:bg-red-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm transition shadow-sm">Restore Database</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="bg-white rounded-xl border border-red-300 shadow-sm p-6 md:p-8">
                <div class="flex items-start gap-4">
                    <div class="text-3xl">🧹</div>
                    <div class="flex-1">
                        <h3 class="font-bold text-red-800 text-lg">Kosongkan Semua Data Selain User</h3>
                        <p class="text-sm text-slate-600 mt-2">Tindakan ini menghapus semua isi tabel selain <strong>users</strong>, termasuk barang, kategori, peminjam, transaksi peminjaman, riwayat, dan log. Akun pengguna tetap disimpan. Nomor ID tabel yang dikosongkan juga akan diatur ulang.</p>
                        <p class="text-sm text-red-700 font-semibold mt-2">Penghapusan permanen dan tidak dapat dibatalkan. Unduh backup terlebih dahulu.</p>
                        <form method="POST" class="mt-5 space-y-4" onsubmit="return confirm('PERINGATAN: Semua data selain akun user akan dihapus permanen. Lanjutkan?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['backup_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div>
                                <label for="confirm_truncate_text" class="block text-sm font-medium text-slate-700 mb-1">Ketik HAPUS DATA untuk mengaktifkan reset</label>
                                <input id="confirm_truncate_text" type="text" name="confirm_truncate_text" required autocomplete="off" class="w-full max-w-sm px-3 py-2 border border-red-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                            </div>
                            <label class="flex items-start gap-2 text-sm text-red-700">
                                <input type="checkbox" name="confirm_truncate_checkbox" value="1" required class="mt-1">
                                <span>Saya memahami semua data selain akun pada tabel users akan dihapus permanen.</span>
                            </label>
                            <button type="submit" name="truncate_data" value="1" class="bg-red-700 hover:bg-red-800 text-white font-medium px-5 py-2.5 rounded-lg text-sm transition shadow-sm">Hapus Semua Data Selain User</button>
                        </form>
                    </div>
                </div>
            </section>

            <p class="text-xs text-slate-500">Fitur ini membackup database saja, bukan file kode PHP atau konfigurasi aplikasi. Simpan file backup di lokasi aman di luar folder web.</p>
        </main>
    </div>
</body>
</html>
