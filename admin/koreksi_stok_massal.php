<?php
include '../config/koneksi.php';
include '../config/helper.php';

// Proteksi Akses: Hanya Admin Kedai
if ($_SESSION['role'] != 'admin') {
    header("Location: index.php"); 
    exit;
}

// Ambil filter jenjang dari URL (Default ke SD)
$f_jenjang = $_GET['jenjang'] ?? 'SD';

// --- LOGIKA PROSES UPDATE MASSAL & RE-VALIDASI STATUS ---
if (isset($_POST['simpan_massal'])) {
    $adjustments = $_POST['adj']; // Array [id_variant => qty_perubahan]
    
    mysqli_begin_transaction($conn);

    try {
        foreach ($adjustments as $id_var => $qty) {
            $qty = (int)$qty;
            if ($qty == 0) continue; // Abaikan jika tidak ada perubahan angka

            // 1. Update Stok di tabel variants (Bisa bertambah atau berkurang)
            mysqli_query($conn, "UPDATE variants SET stok = stok + ($qty) WHERE id_variant = '$id_var'");

            // 2. Catat riwayat di stock_logs untuk Laporan Audit Mutasi
            $aksi = ($qty > 0) ? 'MASUK' : 'KELUAR';
            $abs_qty = abs($qty);
            $ket_log = "Inject Stok Massal (Admin: " . $_SESSION['nama_lengkap'] . ")";
            
            mysqli_query($conn, "INSERT INTO stock_logs (id_variant, jenis_aksi, qty, keterangan, tgl_log) 
                                 VALUES ('$id_var', '$aksi', '$abs_qty', '$ket_log', NOW())");
        }

        // --- FITUR CERDAS: SINKRONISASI STATUS PESANAN SISWA (PO <-> READY) ---
        // Setelah stok berubah, kita cek semua pesanan yang belum diserahkan
        $q_orders = mysqli_query($conn, "SELECT id_order FROM orders WHERE status_order IN ('ready', 'po')");
        
        while($ord = mysqli_fetch_assoc($q_orders)) {
            $id_o = $ord['id_order'];
            $semua_item_lengkap = true;

            // Cek setiap item di dalam order tersebut
            $q_cek_stok = mysqli_query($conn, "
                SELECT v.stok, od.qty 
                FROM order_details od 
                JOIN variants v ON od.id_variant = v.id_variant 
                WHERE od.id_order = '$id_o' AND od.is_received = 0
            ");

            while($item = mysqli_fetch_assoc($q_cek_stok)) {
                // Jika stok gudang lebih kecil dari yang dipesan siswa, tandai tidak lengkap
                if($item['stok'] < $item['qty']) {
                    $semua_item_lengkap = false;
                    break;
                }
            }

            // Update status berdasarkan ketersediaan stok terbaru
            $status_baru = ($semua_item_lengkap) ? 'ready' : 'po';
            mysqli_query($conn, "UPDATE orders SET status_order = '$status_baru' WHERE id_order = '$id_o'");
        }

        mysqli_commit($conn);
        // Tambahkan trigger notifikasi stok manual
$pesan_stok = "Admin (".$_SESSION['username'].") telah melakukan Inject Stok Massal (Koreksi) pada gudang di tanggal " . date('d/m/Y H:i');
mysqli_query($conn, "INSERT INTO internal_messages (sender_id, receiver_role, pesan) VALUES ('1', 'manager', '$pesan_stok')");
        echo "<script>alert('Stok Berhasil Diperbarui & Status Pesanan Siswa Telah Disinkronkan!'); window.location='koreksi_stok_massal.php?jenjang=$f_jenjang';</script>";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('Terjadi Kesalahan: " . $e->getMessage() . "');</script>";
    }
}

// --- AMBIL DATA PRODUK BERDASARKAN JENJANG ---
$sql = "SELECT p.nama_produk, v.ukuran, v.stok, v.id_variant, p.jenjang
        FROM variants v 
        JOIN products p ON v.id_produk = p.id_produk 
        WHERE (p.jenjang = '$f_jenjang' OR p.jenjang = 'UMUM')
        AND p.is_active = 1
        ORDER BY p.kategori ASC, p.nama_produk ASC, v.ukuran ASC";
$res = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Inject Stok Sisa - Kedai Nusaputera</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        .main-content { padding: 30px 50px; }
        .inp-adj { 
            width: 80px; text-align: center; font-weight: bold; 
            border: 2px solid #e2e8f0; border-radius: 8px; padding: 8px; 
            font-size: 16px;
        }
        .inp-adj:focus { border-color: var(--primary); outline: none; background: #fff; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom th { background: #f8fafc; padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        .table-custom td { padding: 15px; border-bottom: 1px solid #f1f5f9; }
        
        .stok-badge { background: #f1f5f9; padding: 5px 10px; border-radius: 6px; font-weight: 800; font-size: 15px; }
        .helper-text { font-size: 11px; color: #94a3b8; margin-top: 5px; }
    </style>
</head>
<body>

    <?php include 'components/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-area">
            <div>
                <h2>📦 Input / Inject Stok Sisa</h2>
                <p>Kelola saldo stok fisik gudang secara manual untuk Unit: <b><?php echo $f_jenjang; ?></b></p>
            </div>
            
            <div class="jenjang-selector">
                <?php 
                foreach(['ALL', 'TK', 'SD', 'SMP', 'SMA', 'SMK'] as $j) {
                    if($j == 'ALL') continue; // Matikan mode ALL agar input lebih fokus
                    $act = ($f_jenjang == $j) ? 'active' : '';
                    echo "<a href='?jenjang=$j' class='jenjang-btn $act'>$j</a>";
                }
                ?>
            </div>
        </div>

        <div class="card" style="background: #fdf2f2; border: 1px solid #fecaca; margin-bottom: 25px; padding: 15px 20px;">
            <p style="margin:0; font-size: 13px; color: #b91c1c;">
                <i class="ph-bold ph-warning-circle"></i> <b>Perhatian:</b> Gunakan menu ini hanya untuk penyesuaian stok sisa atau temuan barang. Gunakan angka positif (misal: 10) untuk menambah, dan angka negatif (misal: -5) untuk mengurangi stok.
            </p>
        </div>

        <form method="POST">
            <div class="card" style="padding:0; overflow:hidden;">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama Barang & Ukuran</th>
                            <th style="text-align:center;">Stok Saat Ini</th>
                            <th style="text-align:center; width: 200px;">Perubahan Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if(mysqli_num_rows($res) == 0) {
                            echo "<tr><td colspan='3' align='center' style='padding:50px; color:#94a3b8;'>Belum ada data produk untuk unit $f_jenjang.</td></tr>";
                        }
                        while($row = mysqli_fetch_assoc($res)): 
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #1e293b;"><?php echo $row['nama_produk']; ?></div>
                                <div style="display:flex; gap:5px; align-items:center; margin-top:5px;">
                                    <span class="badge bg-blue" style="font-size:10px;">SIZE <?php echo $row['ukuran']; ?></span>
                                    <span class="badge" style="background:#f1f5f9; color:#64748b; font-size:10px;"><?php echo $row['jenjang']; ?></span>
                                </div>
                            </td>
                            <td align="center">
                                <span class="stok-badge"><?php echo $row['stok']; ?></span>
                            </td>
                            <td align="center">
                                <input type="number" name="adj[<?php echo $row['id_variant']; ?>]" value="0" class="inp-adj">
                                <div class="helper-text">Ketik +/- (Cth: 5 atau -5)</div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <div class="sticky-submit" style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 25px; text-align: right;">
                    <button type="submit" name="simpan_massal" class="btn btn-primary" style="padding: 15px 50px; font-weight: bold; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.2);">
                        <i class="ph-bold ph-check-circle"></i> UPDATE STOK & SINKRON STATUS
                    </button>
                </div>
            </div>
        </form>
    </div>

</body>
</html>