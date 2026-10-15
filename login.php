<?php
session_start();
include 'koneksi.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email    = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {
        // Cari user berdasarkan email dan status aktif
        $stmt = $conn->prepare("SELECT id, name, email, password, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Cek status akun
            if ($user['status'] === 'nonaktif') {
                $error = "Akun Anda telah dinonaktifkan. Hubungi Administrator.";
            } 
            // Verifikasi Password
            elseif ($password === $user['password']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name']    = $user['name'];
                $_SESSION['email']   = $user['email'];
                $_SESSION['role']    = $user['role'];

                // Redirect berdasarkan Role (Hanya Admin dan Peminjam)
                if ($user['role'] === 'admin') {
                    header("Location: dashboard_admin.php");
                } else {
                    header("Location: dashboard_peminjam.php");
                }
                exit;
            } else {
                $error = "Password yang Anda masukkan salah!";
            }
        } else {
            $error = "Email tidak terdaftar di sistem!";
        }
        $stmt->close();
    } else {
        $error = "Email dan Password wajib diisi!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Inventaris Lab Sains</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md bg-white rounded-xl shadow-lg border border-slate-100 p-8 mx-4 login-card">
        <!-- Logo / Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 bg-indigo-50 text-indigo-600 rounded-full mb-3 text-2xl font-bold">
                🔬
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Inventaris Lab Sains</h1>
            <p class="text-sm text-slate-500 mt-1">Silakan masuk ke akun Anda</p>
        </div>

        <!-- Pesan Error -->
        <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg flex items-center gap-2">
                <span>⚠️</span>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Form Login -->
        <form action="" method="POST" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input type="email" name="email" required 
                    placeholder="nama@lab.ac.id" 
                    class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <input type="password" name="password" required 
                    placeholder="••••••••" 
                    class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition">
            </div>

            <button type="submit" 
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-lg text-sm transition shadow-sm hover:shadow">
                Masuk
            </button>
        </form>

        <!-- Informasi Akun Dummy untuk Uji Coba -->
        <div class="mt-8 pt-6 border-t border-slate-100 text-xs text-slate-400">
            <p class="font-semibold text-slate-500 mb-1">Akun Uji Coba (Dari Database):</p>
            <ul class="space-y-1">
                <li>👑 Admin: <span class="text-slate-600">ahmad.sucipto@lab.ac.id</span> / <span class="text-slate-600">password123</span></li>
                <li>🧪 Peminjam: <span class="text-slate-600">budi.santoso@student.ac.id</span> / <span class="text-slate-600">password123</span></li>
            </ul>
        </div>
    </div>

</body>
</html>