<?php
include '../config/koneksi.php';
include '../config/helper.php';

// Proteksi: Hanya Admin & Manager yang bisa akses
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'manager') {
    header("Location: index.php");
    exit;
}

// --- LOGIC: PROSES KONFIRMASI PENYERAHAN BARANG & KIRIM WA ---
if (isset($_POST['konfirmasi_penyerahan'])) {
    $id_order = $_POST['id_order'];
    $nama_penerima = mysqli_real_escape_string($conn, $_POST['nama_penerima']);

    mysqli_begin_transaction($conn);

    try {
        // 1. Ambil data dasar order untuk Log & WA
        $q_ord = mysqli_query($conn, "SELECT nama_siswa, nomor_hp, jenjang_siswa, kelas_calon FROM orders WHERE id_order = '$id_order'");
        $d_ord = mysqli_fetch_assoc($q_ord);
        $nama_siswa = $d_ord['nama_siswa'];
        $no_hp = $d_ord['nomor_hp'];

        // 2. Ambil detail item yang akan diserahkan
        $q_items = mysqli_query($conn, "SELECT od.qty, p.nama_produk, v.ukuran, od.id_variant, od.id_order_detail, od.is_received 
                                        FROM order_details od 
                                        JOIN variants v ON od.id_variant=v.id_variant 
                                        JOIN products p ON v.id_produk=p.id_produk 
                                        WHERE od.id_order = '$id_order'");
        
        $rincian_wa = "";
        while ($item = mysqli_fetch_assoc($q_items)) {
            $rincian_wa .= "\n- {$item['nama_produk']} ({$item['ukuran']}) x{$item['qty']}";
            
            // 3. Potong Stok Gudang JIKA barang belum pernah ditandai diterima
            if ($item['is_received'] == 0) {
                mysqli_query($conn, "UPDATE variants SET stok = stok - {$item['qty']} WHERE id_variant = '{$item['id_variant']}'");
                mysqli_query($conn, "UPDATE order_details SET is_received = 1, tgl_terima = NOW() WHERE id_order_detail = '{$item['id_order_detail']}'");
                
                // Catat Log Riwayat Mutasi
                $ket_log = "Penyerahan Barang: $nama_siswa (Nota #$id_order)";
                mysqli_query($conn, "INSERT INTO stock_logs (id_variant, jenis_aksi, qty, keterangan, tgl_log) 
                                     VALUES ('{$item['id_variant']}', 'KELUAR', {$item['qty']}, '$ket_log', NOW())");
            }
        }

        // 4. Update Status Order Utama menjadi 'DIAMBIL'
        mysqli_query($conn, "UPDATE orders SET status_order = 'diambil', nama_pengambil = '$nama_penerima', tgl_ambil = NOW() WHERE id_order = '$id_order'");

        // --- 5. INTEGRASI FONNTE (NOTIFIKASI BARANG DIAMBIL) ---
        $token_fonnte = "SxQcVAsZoXzEHA7JdxVG"; // GANTI DENGAN TOKEN ANDA
        
        if (!empty($no_hp)) {
            $target_wa = preg_replace('/[^0-9]/', '', $no_hp);
            if (substr($target_wa, 0, 1) === '0') { $target_wa = '62' . substr($target_wa, 1); }

            $pesan_wa = "Halo Ayah/Bunda *$nama_siswa*,\n\nStatus Pesanan: *SUDAH DIAMBIL*\n\nSeragam sekolah telah diserahkan kepada: *$nama_penerima* pada hari ini.\n\n*Rincian Barang:*$rincian_wa\n\nTerima kasih telah berbelanja di Kedai Nusa Putera!";

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

        mysqli_commit($conn);
        $log_msg = "Admin menyerahkan pesanan fisik Nota #$id_order kepada: $nama_penerima.";
mysqli_query($conn, "INSERT INTO internal_messages (sender_id, receiver_role, pesan) VALUES (1, 'manager', '$log_msg')");
        echo "<script>alert('Berhasil! Barang diserahkan & Notifikasi WA telah dikirim.'); window.location='validasi_ambil.php';</script>";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('Gagal: " . $e->getMessage() . "');</script>";
    }
}


// --- LOGIC: CARI DATA ORDER BERDASARKAN SCAN ---
$data_order = null;
if (isset($_GET['scan_kode'])) {
    $kode = preg_replace('/[^0-9]/', '', $_GET['scan_kode']);
    if (!empty($kode)) {
        $q = mysqli_query($conn, "SELECT * FROM orders WHERE id_order = '$kode'");
        $data_order = mysqli_fetch_assoc($q);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Penyerahan Barang - Nusa Putera</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        .main-content { padding: 30px 50px; }
        .card-success { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 20px; border-radius: 15px; text-align: center; }
        .label-sm { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 5px; }
    </style>
</head>
<body>

    <?php include 'components/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-area">
            <div>
                <h2>📱 Penyerahan & Validasi Barang</h2>
                <p>Otoritas Kedai: Scan nota untuk proses serah terima fisik barang.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1.2fr; gap:30px; align-items: start;">
            
            <!-- KOLOM SCANNER -->
            <div class="card" style="text-align:center;">
                <h4 class="menu-label" style="color:var(--primary); margin-top:0;">Scan Barcode Nota</h4>
                <div id="reader" style="width:100%; border-radius:20px; overflow:hidden; border:2px dashed #cbd5e1 !important; margin-bottom:20px;"></div>
                <form method="GET" style="display:flex; gap:10px;">
                    <input type="text" name="scan_kode" id="hasil_scan" placeholder="Input Manual ID Order (Cth: 12)" value="<?php echo $_GET['scan_kode'] ?? ''; ?>" style="margin:0;">
                    <button type="submit" class="btn btn-primary">CARI DATA</button>
                </form>
            </div>

            <!-- KOLOM HASIL VALIDASI -->
            <div>
                <?php if($data_order): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 style="margin:0; letter-spacing: -1px;">📦 Pesanan #<?php echo $data_order['id_order']; ?></h3>
                        <span class="badge <?php echo ($data_order['status_order'] == 'diambil') ? 'bg-green' : 'bg-orange'; ?>" style="font-weight: 800;">
                            STATUS: <?php echo strtoupper($data_order['status_order']); ?>
                        </span>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; background: #f8fafc; padding: 15px; border-radius: 10px;">
                        <div>
                            <span class="label-sm">Nama Siswa</span>
                            <div style="font-weight: 800; font-size: 16px;"><?php echo strtoupper($data_order['nama_siswa']); ?></div>
                        </div>
                        <div>
                            <span class="label-sm">Unit / Kelas</span>
                            <div style="font-weight: 600;"><?php echo $data_order['jenjang_siswa']; ?> - <?php echo $data_order['kelas_calon']; ?></div>
                        </div>
                    </div>

                    <!-- KASUS 1: BARANG BELUM DIAMBIL -->
                    <?php if($data_order['status_order'] != 'diambil'): ?>
                        <div style="border: 1px solid #e2e8f0; border-radius:15px; overflow: hidden;">
                            <div style="padding:15px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                <h4 style="margin:0; font-size:13px; color:var(--primary);">Rincian Barang yang Diserahkan:</h4>
                            </div>
                            <div style="padding:20px;">
                                <ul style="padding-left:20px; font-size:13px; margin-bottom:20px; line-height:1.8;">
                                    <?php
                                    $id_o = $data_order['id_order'];
                                    $q_items_list = mysqli_query($conn, "SELECT p.nama_produk, v.ukuran, od.qty FROM order_details od JOIN variants v ON od.id_variant=v.id_variant JOIN products p ON v.id_produk=p.id_produk WHERE od.id_order='$id_o'");
                                    while($it = mysqli_fetch_assoc($q_items_list)) {
                                        echo "<li><b>{$it['nama_produk']}</b> (Size: {$it['ukuran']}) - {$it['qty']} Pcs</li>";
                                    }
                                    ?>
                                </ul>

                                <form method="POST">
                                    <input type="hidden" name="id_order" value="<?php echo $data_order['id_order']; ?>">
                                    
                                    <div class="input-group">
                                        <label class="label-sm">Nama Penerima Barang (Wajib)</label>
                                        <input type="text" name="nama_penerima" placeholder="Cth: Ibu Kandung / Siswa Sendiri" required style="border:2px solid var(--primary); font-weight:bold;">
                                    </div>
                                    
                                    <button type="submit" name="konfirmasi_penyerahan" class="btn btn-primary" style="width:100%; justify-content:center; padding:18px; margin-top:10px; border-radius:12px;" onclick="return confirm('Konfirmasi penyerahan fisik? Stok gudang akan langsung dipotong.')">
                                        <i class="ph-bold ph-hand-pointing"></i> KONFIRMASI PENYERAHAN SEKARANG
                                    </button>
                                </form>
                            </div>
                        </div>

                    <!-- KASUS 2: SUDAH DIAMBIL -->
                    <?php else: ?>
                        <div class="card-success">
                            <i class="ph-fill ph-check-circle" style="font-size:54px; color:#10b981;"></i>
                            <h3 style="margin:10px 0; color:#064e3b;">BARANG SUDAH DIAMBIL</h3>
                            <p style="font-size:14px; color:#065f46; line-height:1.5;">
                                Diterima oleh: <b><?php echo strtoupper($data_order['nama_pengambil']); ?></b><br>
                                Waktu: <?php echo date('d/m/Y H:i', strtotime($data_order['tgl_ambil'])); ?> WIB
                            </p>
                        </div>
                    <?php endif; ?>

                </div>
                <?php elseif(isset($_GET['scan_kode'])): ?>
                    <div class="card" style="text-align:center; color:#94a3b8; border:2px dashed #cbd5e1; padding: 40px;">
                        <i class="ph ph-magnifying-glass" style="font-size: 48px; opacity: 0.3; margin-bottom:15px; display:block;"></i>
                        <p>Data pesanan tidak ditemukan. Pastikan ID Order benar.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function onScanSuccess(decodedText) {
            window.location.href = "validasi_ambil.php?scan_kode=" + decodedText;
        }
        let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: 250 }, false);
        html5QrcodeScanner.render(onScanSuccess);
    </script>
</body>
</html>