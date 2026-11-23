<?php
include '../config/koneksi.php';
include '../config/helper.php';

// Proteksi: Hanya Admin Kedai
if ($_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// --- LOGIKA PROSES TUKAR UKURAN ---
if (isset($_POST['proses_tukar'])) {
    $id_order = $_POST['id_order'];
    $id_detail_lama = $_POST['id_detail_lama'];
    $id_variant_baru = $_POST['id_variant_baru'];

    mysqli_begin_transaction($conn);

    try {
        // 1. Ambil info item lama yang dikembalikan
        $q_lama = mysqli_query($conn, "SELECT od.id_variant, p.nama_produk, v.ukuran 
                                       FROM order_details od 
                                       JOIN variants v ON od.id_variant = v.id_variant 
                                       JOIN products p ON v.id_produk = p.id_produk 
                                       WHERE od.id_order_detail = '$id_detail_lama'");
        $d_lama = mysqli_fetch_assoc($q_lama);
        $variant_id_lama = $d_lama['id_variant'];
        $nama_p_lama = $d_lama['nama_produk'];
        $uk_lama = $d_lama['ukuran'];

        // 2. Ambil info item baru (pengganti)
        $q_baru = mysqli_query($conn, "SELECT v.stok, v.ukuran FROM variants v WHERE v.id_variant = '$id_variant_baru'");
        $d_baru = mysqli_fetch_assoc($q_baru);
        $uk_baru = $d_baru['ukuran'];

        // Validasi stok pengganti
        if ($d_baru['stok'] < 1) {
            throw new Exception("Stok ukuran pengganti ($uk_baru) tidak tersedia di gudang!");
        }

        // 3. EKSEKUSI PERUBAHAN STOK GUDANG
        // Kembalikan yang lama ke rak (+1)
        mysqli_query($conn, "UPDATE variants SET stok = stok + 1 WHERE id_variant = '$variant_id_lama'");
        // Ambil yang baru dari rak (-1)
        mysqli_query($conn, "UPDATE variants SET stok = stok - 1 WHERE id_variant = '$id_variant_baru'");

        // 4. Update data di riwayat pesanan siswa tersebut
        mysqli_query($conn, "UPDATE order_details SET id_variant = '$id_variant_baru', tgl_terima = NOW() WHERE id_order_detail = '$id_detail_lama'");

        // 5. Catat ke Log Mutasi untuk Audit
        $log_in = "Tukar Balik: $nama_p_lama ($uk_lama) dari Nota #$id_order";
        $log_out = "Tukar Keluar: $nama_p_lama ($uk_baru) untuk Nota #$id_order";
        mysqli_query($conn, "INSERT INTO stock_logs (id_variant, jenis_aksi, qty, keterangan) VALUES ('$variant_id_lama', 'MASUK', 1, '$log_in')");
        mysqli_query($conn, "INSERT INTO stock_logs (id_variant, jenis_aksi, qty, keterangan) VALUES ('$id_variant_baru', 'KELUAR', 1, '$log_out')");

        // --- 6. SMART SYNC: RE-VALIDASI STATUS SELURUH SISWA (PENCEGAH BUG MORGAN) ---
        // Cek semua order yang belum diserahkan sepenuhnya
        $q_reval = mysqli_query($conn, "SELECT id_order FROM orders WHERE status_order IN ('ready', 'po')");
        while($ord = mysqli_fetch_assoc($q_reval)) {
            $curr_id = $ord['id_order'];
            $is_complete = true;
            
            // Cek ketersediaan fisik vs kebutuhan siswa
            $q_check = mysqli_query($conn, "SELECT v.stok, od.qty FROM order_details od 
                                            JOIN variants v ON od.id_variant = v.id_variant 
                                            WHERE od.id_order = '$curr_id' AND od.is_received = 0");
            
            while($item = mysqli_fetch_assoc($q_check)) {
                if($item['stok'] < $item['qty']) {
                    $is_complete = false;
                    break;
                }
            }
            
            // Tentukan status baru (Ready jika cukup, PO jika kurang)
            $new_status = ($is_complete) ? 'ready' : 'po';
            mysqli_query($conn, "UPDATE orders SET status_order = '$new_status' WHERE id_order = '$curr_id'");
        }

        mysqli_commit($conn);
        echo "<script>alert('Berhasil! Ukuran telah ditukar dan status antrean siswa lainnya telah diperbarui.'); window.location='riwayat_transaksi.php';</script>";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('Gagal: " . $e->getMessage() . "');</script>";
    }
}

// Cari Data Order
$order_found = null;
if (isset($_GET['id_nota'])) {
    $id_nota = preg_replace('/[^0-9]/', '', $_GET['id_nota']);
    $q = mysqli_query($conn, "SELECT * FROM orders WHERE id_order = '$id_nota'");
    $order_found = mysqli_fetch_assoc($q);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tukar Ukuran - Kedai Nusaputera</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php include 'components/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-area">
            <div>
                <h2>🔄 Tukar Ukuran Seragam</h2>
                <p>Retur barang yang sudah diambil dan ganti dengan ukuran yang tersedia.</p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start;">
            
            <!-- FORM PENCARIAN -->
            <div class="card">
                <h4 class="menu-label" style="margin-top:0;">1. Cari Pesanan</h4>
                <form method="GET">
                    <div style="display:flex; gap:10px; margin-bottom: 20px;">
                        <input type="text" name="id_nota" class="form-control" placeholder="ID Nota (Cth: 1)" value="<?php echo $_GET['id_nota'] ?? ''; ?>" required>
                        <button type="submit" class="btn btn-primary">CARI</button>
                    </div>
                </form>

                <?php if($order_found): ?>
                    <div style="background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Identitas</span>
                        <div style="font-weight: 800; font-size: 16px; margin-top:5px; color:var(--primary);"><?php echo strtoupper($order_found['nama_siswa']); ?></div>
                        <div style="font-size: 12px; margin-top: 5px;">Unit: <?php echo $order_found['jenjang_siswa']; ?> | Status: <b><?php echo strtoupper($order_found['status_order']); ?></b></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FORM EKSEKUSI TUKAR -->
            <div class="card">
                <h4 class="menu-label" style="margin-top:0;">2. Proses Pertukaran</h4>
                <?php if($order_found && $order_found['status_order'] == 'diambil'): ?>
                    <form method="POST">
                        <input type="hidden" name="id_order" value="<?php echo $order_found['id_order']; ?>">
                        
                        <label style="font-size:12px; font-weight:700;">Barang yang dikembalikan ke Gudang:</label>
                        <select name="id_detail_lama" class="form-control" required style="margin-bottom: 20px;">
                            <option value="">-- Pilih Item --</option>
                            <?php 
                            $ido = $order_found['id_order'];
                            $q_item = mysqli_query($conn, "SELECT od.*, p.nama_produk, v.ukuran 
                                                           FROM order_details od 
                                                           JOIN variants v ON od.id_variant = v.id_variant 
                                                           JOIN products p ON v.id_produk = p.id_produk 
                                                           WHERE od.id_order = '$ido' AND od.is_received = 1");
                            while($ri = mysqli_fetch_assoc($q_item)):
                            ?>
                                <option value="<?php echo $ri['id_order_detail']; ?>">
                                    <?php echo $ri['nama_produk']; ?> (Size Sekarang: <?php echo $ri['ukuran']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <label style="font-size:12px; font-weight:700;">Ukuran Baru (Pengganti):</label>
                        <select name="id_variant_baru" class="form-control" required style="margin-bottom: 25px;">
                            <option value="">-- Pilih Ukuran Pengganti --</option>
                            <?php 
                            $jenjang_s = $order_found['jenjang_siswa'];
                            $q_stok = mysqli_query($conn, "SELECT v.id_variant, p.nama_produk, v.ukuran, v.stok 
                                                           FROM variants v 
                                                           JOIN products p ON v.id_produk = p.id_produk 
                                                           WHERE v.stok > 0 
                                                           AND (p.jenjang = '$jenjang_s' OR p.jenjang = 'UMUM') 
                                                           ORDER BY p.nama_produk ASC");
                            while($rs = mysqli_fetch_assoc($q_stok)):
                            ?>
                                <option value="<?php echo $rs['id_variant']; ?>">
                                    <?php echo $rs['nama_produk']; ?> - SIZE <?php echo $rs['ukuran']; ?> (Tersedia: <?php echo $rs['stok']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <div style="background: #fff7ed; border: 1px solid #fed7aa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                            <p style="font-size: 12px; color: #c2410c; margin: 0; line-height:1.5;">
                                <i class="ph-bold ph-info"></i> <b>Sistem Smart Sync Aktif:</b><br>
                                Stok barang lama akan bertambah, stok barang baru akan berkurang. Status pesanan siswa lain yang terdampak akan diperbarui secara otomatis.
                            </p>
                        </div>

                        <button type="submit" name="proses_tukar" class="btn btn-primary" style="width: 100%; padding: 18px; font-weight: 800;" onclick="return confirm('Konfirmasi pertukaran ukuran?')">
                            EKSEKUSI TUKAR SEKARANG
                        </button>
                    </form>
                <?php elseif($order_found): ?>
                    <div style="text-align: center; padding: 50px; color: #64748b;">
                        <i class="ph ph-warning-octagon" style="font-size: 48px; opacity: 0.3;"></i>
                        <p style="margin-top:15px;">Ukuran hanya bisa ditukar jika barang sudah berstatus <b>DIAMBIL</b>.</p>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 50px; color: #94a3b8;">
                        <p>Silakan cari nomor nota terlebih dahulu untuk memproses penukaran.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</body>
</html>