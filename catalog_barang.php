<?php
session_start();
include 'koneksi.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'peminjam') {
    header('Location: login.php');
    exit;
}

$categoryResult = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
$categories = $categoryResult ? $categoryResult->fetch_all(MYSQLI_ASSOC) : [];
$categoryId = (int) ($_GET['category_id'] ?? 0);
$categoryIds = array_map(static fn($category) => (int) $category['id'], $categories);
if ($categoryId !== 0 && !in_array($categoryId, $categoryIds, true)) {
    $categoryId = 0;
}

$search = trim($_GET['search'] ?? '');
$searchTerm = '%' . $search . '%';
$perPage = 12;

$countStmt = $conn->prepare("SELECT COUNT(*) AS total
    FROM items i
    JOIN categories c ON c.id = i.category_id
    WHERE i.deleted_at IS NULL AND i.available_quantity > 0
      AND (? = 0 OR i.category_id = ?)
      AND (? = '' OR i.name LIKE ? OR i.code LIKE ?)");
$countStmt->bind_param('iisss', $categoryId, $categoryId, $search, $searchTerm, $searchTerm);
$countStmt->execute();
$totalItems = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = max(1, (int) ceil($totalItems / $perPage));
$currentPage = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
$offset = ($currentPage - 1) * $perPage;

$itemsStmt = $conn->prepare("SELECT i.id, i.code, i.name, i.total_quantity, i.available_quantity,
        i.`condition`, i.location, c.name AS category_name
    FROM items i
    JOIN categories c ON c.id = i.category_id
    WHERE i.deleted_at IS NULL AND i.available_quantity > 0
      AND (? = 0 OR i.category_id = ?)
      AND (? = '' OR i.name LIKE ? OR i.code LIKE ?)
    ORDER BY c.name ASC, i.name ASC, i.id DESC
    LIMIT ? OFFSET ?");
$itemsStmt->bind_param('iisssii', $categoryId, $categoryId, $search, $searchTerm, $searchTerm, $perPage, $offset);
$itemsStmt->execute();
$items = $itemsStmt->get_result();

function catalog_page_url($page, $categoryId, $search)
{
    $params = ['page' => $page];
    if ($categoryId > 0) $params['category_id'] = $categoryId;
    if ($search !== '') $params['search'] = $search;
    return 'catalog_barang.php?' . http_build_query($params) . '#daftar-katalog';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katalog Barang - Inventaris Lab Sains</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-slate-100 flex min-h-screen app-shell">
    <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col justify-between hidden md:flex app-sidebar">
        <div>
            <div class="p-6 border-b border-slate-800">
                <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Portal Peminjam</span>
                <h1 class="text-xl font-bold text-white">Lab Sains</h1>
            </div>
            <nav class="p-4 space-y-1">
                <a href="dashboard_peminjam.php" class="app-nav-link">🧪 Riwayat Saya</a>
                <a href="catalog_barang.php" class="app-nav-link is-active">🔬 Katalog Barang Lab</a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800">
            <a href="logout.php" class="flex items-center gap-2 px-4 py-2 bg-red-600/20 text-red-400 hover:bg-red-600 hover:text-white rounded-lg text-sm font-medium text-center justify-center">🚪 Keluar Sistem</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col app-content">
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center app-header">
            <h2 class="text-lg font-semibold text-slate-800">Katalog Barang Laboratorium</h2>
            <div class="text-sm text-slate-500">Halo, <span class="font-semibold text-emerald-600"><?php echo htmlspecialchars($_SESSION['name']); ?></span></div>
        </header>

        <main class="p-4 md:p-8 flex-1 space-y-6 app-main">
            <section class="catalog-intro rounded-4 p-4 p-md-5">
                <span class="badge text-bg-success mb-2">Katalog tersedia</span>
                <h1 class="h3 fw-bold mb-2">Temukan alat dan bahan laboratorium</h1>
                <p class="mb-0 text-secondary">Lihat stok yang tersedia sebelum menghubungi petugas. Peminjaman tetap diproses oleh petugas; katalog ini tidak memiliki tombol peminjaman.</p>
            </section>

            <section id="daftar-katalog" class="bg-white rounded-4 border shadow-sm overflow-hidden">
                <div class="p-4 p-md-5 border-bottom">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                        <div>
                            <h3 class="h5 fw-bold mb-1">Barang yang tersedia</h3>
                            <p class="text-secondary small mb-0">Menampilkan <?php echo $totalItems; ?> barang<?php echo $categoryId > 0 ? ' pada kategori terpilih' : ''; ?>.</p>
                        </div>
                        <form method="GET" action="catalog_barang.php" class="row g-2 align-items-center">
                            <input type="hidden" name="page" value="1">
                            <div class="col-12 col-sm-auto">
                                <select name="category_id" class="form-select" aria-label="Filter kategori">
                                    <option value="0">Semua kategori</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo (int) $category['id']; ?>" <?php echo $categoryId === (int) $category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-auto">
                                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Cari barang atau kode..." aria-label="Cari barang">
                            </div>
                            <div class="col-12 col-sm-auto"><button class="btn btn-primary w-100" type="submit">Cari</button></div>
                        </form>
                    </div>
                </div>

                <?php if ($totalItems > 0): ?>
                    <div class="row g-3 p-3 p-md-4">
                        <?php while ($item = $items->fetch_assoc()): ?>
                            <?php
                                $condition = str_replace('_', ' ', $item['condition']);
                                $conditionClass = $item['condition'] === 'baik' ? 'text-bg-success' : ($item['condition'] === 'rusak_ringan' ? 'text-bg-warning' : 'text-bg-danger');
                            ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <article class="catalog-item-card h-100 rounded-4 border bg-white p-4">
                                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                        <span class="badge text-bg-primary-subtle text-primary-emphasis"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                        <span class="badge <?php echo $conditionClass; ?>"><?php echo htmlspecialchars(ucfirst($condition)); ?></span>
                                    </div>
                                    <div class="small text-secondary font-monospace mb-1"><?php echo htmlspecialchars($item['code']); ?></div>
                                    <h4 class="h5 fw-bold mb-3"><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <div class="catalog-stock d-flex justify-content-between align-items-center rounded-3 px-3 py-2 mb-3">
                                        <span class="small text-secondary">Stok tersedia</span>
                                        <strong class="text-success"><?php echo (int) $item['available_quantity']; ?> <span class="small fw-normal">/ <?php echo (int) $item['total_quantity']; ?> unit</span></strong>
                                    </div>
                                    <div class="small text-secondary">📍 <?php echo htmlspecialchars($item['location'] ?: 'Lokasi belum dicatat'); ?></div>
                                </article>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 px-3">
                        <div class="display-6 mb-2">🔎</div>
                        <h3 class="h5 fw-bold">Barang tidak ditemukan</h3>
                        <p class="text-secondary mb-0">Coba kata kunci atau kategori lain.</p>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 border-top px-4 py-3">
                    <?php
                        $firstItem = $totalItems === 0 ? 0 : $offset + 1;
                        $lastItem = min($offset + $perPage, $totalItems);
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                    ?>
                    <small class="text-secondary">Menampilkan <?php echo $firstItem; ?>–<?php echo $lastItem; ?> dari <?php echo $totalItems; ?> barang</small>
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Navigasi halaman katalog">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars(catalog_page_url(max(1, $currentPage - 1), $categoryId, $search)); ?>">Sebelumnya</a></li>
                                <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                    <li class="page-item <?php echo $pageNumber === $currentPage ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars(catalog_page_url($pageNumber, $categoryId, $search)); ?>"><?php echo $pageNumber; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars(catalog_page_url(min($totalPages, $currentPage + 1), $categoryId, $search)); ?>">Berikutnya</a></li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
