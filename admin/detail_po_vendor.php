<?php
include '../config/koneksi.php';
include '../config/helper.php';

$id_po = mysqli_real_escape_string($conn, $_GET['id']);
$role  = $_SESSION['role'] ?? 'admin';

// 1. Ambil Header PO
$q_header = mysqli_query($conn, "SELECT * FROM po_vendor WHERE id_po = '$id_po'");
$po = mysqli_fetch_assoc($q_header);

if (!$po) { header("Location: po_vendor.php"); exit; }

// --- FITUR 1: GENERATOR OTOMATIS DARI KEBUTUHAN SISWA (DEFISIT) ---
if (isset($_POST['generate_defisit']) && $role == 'admin') {
    $jenjang_po = $po['jenjang'];
    $filter_jenjang = ($jenjang_po != 'ALL') ? "AND o.jenjang_siswa = '$jenjang_po'" : "";
    
    $sql_kebutuhan = "
        SELECT v.id_variant, SUM(od.qty) as total_butuh, v.stok as stok_sekarang
        FROM order_details od
        JOIN orders o ON od.id_order = o.id_order
        JOIN variants v ON od.id_variant = v.id_variant
        WHERE od.is_received = 0 $filter_jenjang
        GROUP BY v.id_variant
    ";
    
    $q_keb = mysqli_query($conn, $sql_kebutuhan);
    $masuk = 0;

    while($row = mysqli_fetch_assoc($q_keb)) {
        $id_v = $row['id_variant'];
        $defisit = $row['total_butuh'] - $row['stok_sekarang'];

        if ($defisit > 0) {
            $cek = mysqli_query($conn, "SELECT id_po_item FROM po_vendor_items WHERE id_po='$id_po' AND id_variant='$id_v'");
            if (mysqli_num_rows($cek) == 0) {
                mysqli_query($conn, "INSERT INTO po_vendor_items (id_po, id_variant, qty_pesan) VALUES ('$id_po', '$id_v', '$defisit')");
                $masuk++;
            }
        }
    }
    echo "<script>alert('Berhasil! $masuk item defisit ditambahkan.'); window.location='detail_po_vendor.php?id=$id_po';</script>";
}

// --- FITUR 2: TAMBAH ITEM MANUAL ---
if (isset($_POST['tambah_item']) && $role == 'admin') {
    $id_v = $_POST['id_variant'];
    $qty  = $_POST['qty_pesan'];
    $cek = mysqli_query($conn, "SELECT id_po_item FROM po_vendor_items WHERE id_po='$id_po' AND id_variant='$id_v'");
    if(mysqli_num_rows($cek) > 0) {
        mysqli_query($conn, "UPDATE po_vendor_items SET qty_pesan = qty_pesan + $qty WHERE id_po='$id_po' AND id_variant='$id_v'");
    } else {
        mysqli_query($conn, "INSERT INTO po_vendor_items (id_po, id_variant, qty_pesan) VALUES ('$id_po', '$id_v', '$qty')");
    }
    header("Location: detail_po_vendor.php?id=$id_po");
}

// --- FITUR 3: UPDATE QTY DRAFT (EDIT SEBELUM PROSES) ---
if (isset($_POST['update_draft']) && $role == 'admin') {
    $id_pvi = $_POST['id_po_item'];
    $qty = $_POST['qty_pesan'];
    if ($qty > 0) mysqli_query($conn, "UPDATE po_vendor_items SET qty_pesan='$qty' WHERE id_po_item='$id_pvi'");
    else mysqli_query($conn, "DELETE FROM po_vendor_items WHERE id_po_item='$id_pvi'");
    header("Location: detail_po_vendor.php?id=$id_po");
}

// --- FITUR 4: UPDATE BARANG DATANG + NOTIFIKASI WA READY ---
if (isset($_POST['update_datang']) && $role == 'admin') {
    $id_pvi = $_POST['id_po_item'];
    $id_var = $_POST['id_variant_update'];
    $dtg    = (int)$_POST['jumlah_datang'];

    if (empty($id_var) || $dtg <= 0) {
        echo "<script>alert('Input tidak valid.'); window.location='detail_po_vendor.php?id=$id_po';</script>"; exit;
    }

    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "UPDATE po_vendor_items SET qty_datang = qty_datang + $dtg WHERE id_po_item='$id_pvi'");
        mysqli_query($conn, "UPDATE variants SET stok = stok + $dtg WHERE id_variant='$id_var'");
        
        $log_desc = "Penerimaan PO #$id_po (" . $po['nama_vendor'] . ")";
        mysqli_query($conn, "INSERT INTO stock_logs (id_variant, jenis_aksi, qty, keterangan) VALUES ('$id_var', 'MASUK', '$dtg', '$log_desc')");

        // --- LOGIKA OTOMATISASI READY & KIRIM WA ---
        $q_waiting = mysqli_query($conn, "SELECT DISTINCT o.id_order, o.nama_siswa, o.nomor_hp 
                                          FROM orders o 
                                          JOIN order_details od ON o.id_order = od.id_order 
                                          WHERE od.id_variant = '$id_var' AND od.is_received = 0 AND o.status_order = 'po'");
        
        while($wait = mysqli_fetch_assoc($q_waiting)) {
            $id_o = $wait['id_order'];
            $no_hp = $wait['nomor_hp'];
            $nama_s = $wait['nama_siswa'];
            $siap_diambil = true;
            
            $q_cek = mysqli_query($conn, "SELECT v.stok, od.qty, p.nama_produk, v.ukuran 
                                          FROM order_details od 
                                          JOIN variants v ON od.id_variant = v.id_variant 
                                          JOIN products p ON v.id_produk = p.id_produk
                                          WHERE od.id_order = '$id_o' AND od.is_received = 0");
            
            $rincian_list = "";
            while($c = mysqli_fetch_assoc($q_cek)){
                $rincian_list .= "\n- {$c['nama_produk']} ({$c['ukuran']}) x{$c['qty']}";
                if($c['stok'] < $c['qty']) { $siap_diambil = false; }
            }

            if($siap_diambil) {
                // Update Status
                mysqli_query($conn, "UPDATE orders SET status_order = 'ready' WHERE id_order = '$id_o'");

                // --- KIRIM WA VIA FONNTE ---
                $token_fonnte = "SxQcVAsZoXzEHA7JdxVG"; // <--- GANTI TOKEN ANDA DI SINI
                $target_wa = preg_replace('/[^0-9]/', '', $no_hp);
                if (substr($target_wa, 0, 1) === '0') { $target_wa = '62' . substr($target_wa, 1); }

                $pesan_wa = "Halo Ayah/Bunda *$nama_s*,\n\nKabar gembira! Pesanan seragam Anda sudah *READY* dan siap diambil di Kedai.\n\n*Rincian Barang:*$rincian_list\n\nLihat Nota Digital Anda:\nhttps://kedai.nusaputera.id/view_nota.php?id=$id_o\n\nSilakan datang ke kedai pada jam operasional sekolah. Terima kasih!";

                $curl = curl_init();
                curl_setopt_array($curl, array(
                  CURLOPT_URL => 'https://api.fonnte.com/send',
                  CURLOPT_RETURNTRANSFER => true,
                  CURLOPT_CUSTOMREQUEST => 'POST',
                  CURLOPT_POSTFIELDS => array('target' => $target_wa, 'message' => $pesan_wa),
                  CURLOPT_HTTPHEADER => array("Authorization: $token_fonnte"),
                  CURLOPT_SSL_VERIFYPEER => false,
                ));
                curl_exec($curl);
                curl_close($curl);
            }
        }

        $q_stat = mysqli_query($conn, "SELECT SUM(qty_pesan) as p, SUM(qty_datang) as d FROM po_vendor_items WHERE id_po = '$id_po'");
        $s = mysqli_fetch_assoc($q_stat);
        $st_baru = ($s['d'] >= $s['p']) ? 'selesai' : 'parsial';
        mysqli_query($conn, "UPDATE po_vendor SET status = '$st_baru' WHERE id_po = '$id_po'");

        mysqli_commit($conn);
        $log_msg = "Admin menerima kedatangan stok sebanyak $dtg pcs dari PO #$id_po.";
mysqli_query($conn, "INSERT INTO internal_messages (sender_id, receiver_role, pesan) VALUES (1, 'manager', '$log_msg')");
        header("Location: detail_po_vendor.php?id=$id_po");
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola PO - <?php echo $po['nama_vendor']; ?></title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        .generator-box { background: linear-gradient(to right, #eff6ff, #fff); border: 1px solid #bfdbfe; border-left: 5px solid #2563eb; border-radius: 12px; padding: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .table-po th { background: #f8fafc; padding: 15px; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; border-bottom: 2px solid #e2e8f0; }
        .table-po td { padding: 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .action-group { display: flex; gap: 8px; justify-content: center; align-items: center; }
        .input-qty-small { width: 65px; text-align: center; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: bold; }
        .btn-update { background: #2563eb; color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    </style>
</head>
<body>
    <?php include 'components/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-area">
            <div>
                <h2><i class="ph-fill ph-list-numbers"></i> Kelola Item PO</h2>
                <p>Vendor: <b><?php echo $po['nama_vendor']; ?></b> | Unit: <b><?php echo $po['jenjang']; ?></b></p>
            </div>
            <a href="po_vendor.php" class="btn btn-outline">Kembali</a>
        </div>

        <?php if($role == 'admin' && $po['status'] == 'draft'): ?>
        <div class="generator-box">
            <div>
                <h4 style="margin:0; color:#1e40af;">⚡ Generator Otomatis (Re-Stock)</h4>
                <p style="margin:5px 0 0; font-size:13px; color:#3b82f6;">Sistem akan menghitung pesanan siswa yang stoknya kurang.</p>
            </div>
            <form method="POST">
                <button type="submit" name="generate_defisit" class="btn" style="background:#2563eb; color:white; padding:12px 25px;">
                    <i class="ph-bold ph-magic-wand"></i> TARIK KEKURANGAN STOK
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: 1.6fr 1fr; gap:30px; align-items: start;">
            <div class="card" style="padding:0; overflow:hidden;">
                <div style="padding:20px 25px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                    <h4 style="margin:0;">📦 Daftar Barang Pesanan</h4>
                    <span class="badge <?php echo ($po['status']=='selesai')?'bg-green':'bg-orange'; ?>"><?php echo strtoupper($po['status']); ?></span>
                </div>

                <table class="table-po" style="width:100%; border-spacing:0;">
                    <thead>
                        <tr>
                            <th align="left">Item & Size</th>
                            <th style="text-align:center;">Pesan</th>
                            <th style="text-align:center;">Masuk</th>
                            <th style="text-align:center;">Sisa</th>
                            <?php if($role == 'admin'): ?><th style="text-align:center;">Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_items = mysqli_query($conn, "SELECT pvi.*, v.ukuran, p.nama_produk FROM po_vendor_items pvi JOIN variants v ON pvi.id_variant = v.id_variant JOIN products p ON v.id_produk = p.id_produk WHERE pvi.id_po = '$id_po'");
                        while($row = mysqli_fetch_assoc($q_items)):
                            $sisa = $row['qty_pesan'] - $row['qty_datang'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo $row['nama_produk']; ?></strong><br>
                                <span class="badge bg-blue" style="font-size:10px;">SIZE <?php echo $row['ukuran']; ?></span>
                            </td>
                            <td align="center">
                                <?php if($po['status'] == 'draft' && $role == 'admin'): ?>
                                    <form method="POST" class="action-group">
                                        <input type="hidden" name="id_po_item" value="<?php echo $row['id_po_item']; ?>">
                                        <input type="number" name="qty_pesan" value="<?php echo $row['qty_pesan']; ?>" style="width:55px; text-align:center; padding:5px;">
                                        <button type="submit" name="update_draft" style="border:none; background:none; color:green; cursor:pointer;">💾</button>
                                    </form>
                                <?php else: echo "<b>".$row['qty_pesan']."</b>"; endif; ?>
                            </td>
                            <td align="center" style="font-weight:bold; color:#059669;"><?php echo $row['qty_datang']; ?></td>
                            <td align="center" style="font-weight:bold; color:<?php echo ($sisa>0)?'#dc2626':'#9ca3af'; ?>;"><?php echo $sisa; ?></td>
                            <?php if($role == 'admin'): ?>
                            <td align="center">
                                <?php if($sisa > 0): ?>
                                <form method="POST" class="action-group">
                                    <input type="hidden" name="id_po_item" value="<?php echo $row['id_po_item']; ?>">
                                    <input type="hidden" name="id_variant_update" value="<?php echo $row['id_variant']; ?>">
                                    <input type="number" name="jumlah_datang" min="1" max="<?php echo $sisa; ?>" class="input-qty-small" required>
                                    <button type="submit" name="update_datang" class="btn-update">OK</button>
                                </form>
                                <?php else: ?><span class="badge bg-green">LENGKAP</span><?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <?php if($role == 'admin' && $po['status'] != 'selesai'): ?>
            <div class="card" style="padding: 20px;">
                <h4 style="margin:0 0 15px;">➕ Tambah Item Manual</h4>
                <form method="POST">
                    <select name="id_variant" required class="form-control" style="margin-bottom:10px;">
                        <option value="">-- Pilih Barang --</option>
                        <?php
                        $filter = ($po['jenjang'] != 'ALL') ? "WHERE p.jenjang = '{$po['jenjang']}' OR p.jenjang = 'UMUM'" : "";
                        $q_var = mysqli_query($conn, "SELECT v.id_variant, p.nama_produk, v.ukuran FROM variants v JOIN products p ON v.id_produk = p.id_produk $filter ORDER BY p.nama_produk ASC");
                        while($v = mysqli_fetch_assoc($q_var)){ echo "<option value='{$v['id_variant']}'>{$v['nama_produk']} [{$v['ukuran']}]</option>"; }
                        ?>
                    </select>
                    <input type="number" name="qty_pesan" min="1" placeholder="Jumlah Pesanan" required class="form-control" style="margin-bottom:15px;">
                    <button type="submit" name="tambah_item" class="btn btn-primary" style="width:100%;">MASUKKAN KE LIST</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>