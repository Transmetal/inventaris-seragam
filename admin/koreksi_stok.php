<?php
include '../config/koneksi.php';
include '../config/helper.php';

// Proteksi: Hanya Admin Kedai yang punya kewenangan koreksi stok
if ($_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// --- LOGIKA PROSES KOREKSI ---
if (isset($_POST['simpan_koreksi'])) {
    $id_variant   = $_POST['id_variant'];
    $jenis_aksi   = $_POST['jenis_aksi']; // MASUK, KELUAR, RUSAK
    $qty          = $_POST['qty'];
    $id_reason    = !empty($_POST['id_reason']) ? $_POST['id_reason'] : 'NULL';
    $keterangan   = mysqli_real_escape_string($conn, $_POST['keterangan']);

    mysqli_begin_transaction($conn);

    try {
        // 1. Ambil stok lama
        $q_old = mysqli_query($conn, "SELECT stok FROM variants WHERE id_variant = '$id_variant' FOR UPDATE");
        $d_old = mysqli_fetch_assoc($q_old);
        $stok_lama = $d_old['stok'];

        // 2. Hitung stok baru
        if ($jenis_aksi == 'MASUK') {
            $stok_baru = $stok_lama + $qty;
        } else {
            // KELUAR atau RUSAK mengurangi stok
            $stok_baru = $stok_lama - $qty;
            if ($stok_baru < 0) $stok_baru = 0; // Mencegah stok minus
        }

        // 3. Update stok di tabel variants
        mysqli_query($conn, "UPDATE variants SET stok = $stok_baru WHERE id_variant = '$id_variant'");

        // 4. Catat riwayat di stock_logs
        $q_log = "INSERT INTO stock_logs (id_variant, jenis_aksi, qty, id_reason, keterangan) 
                  VALUES ('$id_variant', '$jenis_aksi', '$qty', $id_reason, '$keterangan')";
        mysqli_query($conn, $q_log);

        mysqli_commit($conn);
        echo "<script>alert('Berhasil! Stok telah direvisi.'); window.location='koreksi_stok.php';</script>";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('Gagal: " . $e->getMessage() . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Koreksi Stok - Nusa Putera</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>

    <?php include 'components/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-area">
            <div>
                <h2>🛠️ Revisi & Koreksi Stok</h2>
                <p>Gunakan menu ini untuk mencatat barang rusak atau penyesuaian manual.</p>
            </div>
            <a href="stok_opname.php" class="btn btn-outline">Lihat Stok</a>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:30px; align-items: start;">
            
            <!-- FORM KOREKSI -->
            <div class="card">
                <h4 class="menu-label" style="color:var(--primary); margin-top:0;">Input Koreksi</h4>
                <form method="POST">
                    <label style="font-size:11px; font-weight:800;">PILIH BARANG & UKURAN</label>
                    <select name="id_variant" required>
                        <option value="">-- Pilih Barang --</option>
                        <?php
                        $q_v = mysqli_query($conn, "SELECT v.id_variant, p.nama_produk, v.ukuran, v.stok 
                                                   FROM variants v JOIN products p ON v.id_produk=p.id_produk 
                                                   ORDER BY p.nama_produk ASC");
                        while($rv = mysqli_fetch_assoc($q_v)) {
                            echo "<option value='{$rv['id_variant']}'>{$rv['nama_produk']} - Size {$rv['ukuran']} (Stok: {$rv['stok']})</option>";
                        }
                        ?>
                    </select>

                    <label style="font-size:11px; font-weight:800;">JENIS KOREKSI</label>
                    <select name="jenis_aksi" id="jenis_aksi" onchange="toggleReason(this.value)" required>
                        <option value="RUSAK">🔴 Barang Rusak / Cacat (Potong Stok)</option>
                        <option value="KELUAR">➖ Pengurangan Manual (Salah Input)</option>
                        <option value="MASUK">➕ Penambahan Manual (Retur/Temuan)</option>
                    </select>

                    <!-- Box Alasan Cacat (Hanya muncul jika pilih RUSAK) -->
                    <div id="box_reason">
                        <label style="font-size:11px; font-weight:800; color:#f43f5e;">ALASAN CACAT PRODUK</label>
                        <select name="id_reason">
                            <option value="">-- Pilih Alasan --</option>
                            <?php
                            $q_r = mysqli_query($conn, "SELECT * FROM defect_reasons");
                            while($rr = mysqli_fetch_assoc($q_r)) {
                                echo "<option value='{$rr['id_reason']}'>{$rr['nama_alasan']}</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <label style="font-size:11px; font-weight:800;">JUMLAH (QTY)</label>
                    <input type="number" name="qty" min="1" required placeholder="0">

                    <label style="font-size:11px; font-weight:800;">KETERANGAN / ALASAN DETAIL</label>
                    <textarea name="keterangan" rows="3" placeholder="Cth: Lengan sobek saat unboxing paket..."></textarea>

                    <button type="submit" name="simpan_koreksi" class="btn btn-primary" style="width:100%; justify-content:center; padding:15px; margin-top:10px;">
                        <i class="ph-bold ph-check-circle"></i> EKSEKUSI REVISI STOK
                    </button>
                </form>
            </div>

            <!-- LOG KOREKSI TERAKHIR -->
            <div class="card" style="padding:0; overflow:hidden;">
                <div style="padding:20px; background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                    <h4 style="margin:0;"><i class="ph ph-clock-counter-clockwise"></i> Log Koreksi Terakhir</h4>
                </div>
                <table style="width:100%; border-spacing:0;">
                    <thead>
                        <tr style="background:#f9fafb;">
                            <th style="padding-left:20px;">Waktu</th>
                            <th>Barang</th>
                            <th>Aksi</th>
                            <th style="padding-right:20px; text-align:right;">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_log = mysqli_query($conn, "SELECT l.*, v.ukuran, p.nama_produk, r.nama_alasan 
                                                      FROM stock_logs l 
                                                      JOIN variants v ON l.id_variant = v.id_variant 
                                                      JOIN products p ON v.id_produk = p.id_produk 
                                                      LEFT JOIN defect_reasons r ON l.id_reason = r.id_reason 
                                                      ORDER BY l.tgl_log DESC LIMIT 10");
                        while($rl = mysqli_fetch_assoc($q_log)):
                            $col = ($rl['jenis_aksi'] == 'MASUK') ? 'color:#059669;' : 'color:#ef4444;';
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding:15px 20px;">
                                <small><?php echo date('d/m/y H:i', strtotime($rl['tgl_log'])); ?></small>
                            </td>
                            <td>
                                <strong style="font-size:13px;"><?php echo $rl['nama_produk']; ?></strong><br>
                                <small class="badge bg-blue" style="font-size:9px;">SIZE <?php echo $rl['ukuran']; ?></small>
                            </td>
                            <td>
                                <span style="font-size:11px; font-weight:700; <?php echo $col; ?>">
                                    <?php echo $rl['jenis_aksi']; ?>
                                </span><br>
                                <small style="font-size:10px; color:#94a3b8;"><?php echo $rl['nama_alasan'] ?? 'Manual'; ?></small>
                            </td>
                            <td align="right" style="padding-right:20px; font-weight:800; <?php echo $col; ?>">
                                <?php echo ($rl['jenis_aksi'] == 'MASUK') ? '+' : '-'; ?> <?php echo $rl['qty']; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script>
    function toggleReason(val) {
        // Tampilkan alasan cacat hanya jika jenis koreksi adalah RUSAK
        document.getElementById('box_reason').style.display = (val === 'RUSAK') ? 'block' : 'none';
    }
    // Jalankan saat pertama load
    toggleReason(document.getElementById('jenis_aksi').value);
    </script>
</body>
</html>