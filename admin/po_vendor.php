<?php
include '../config/koneksi.php';
include '../config/helper.php';

// Pastikan role sudah terdefinisi
$role = $_SESSION['role'] ?? 'admin';

// --- LOGIKA 1: PROSES PEMBATALAN PO ---
if (isset($_GET['cancel_id']) && $role == 'admin') {
    $id_batal = mysqli_real_escape_string($conn, $_GET['cancel_id']);
    mysqli_query($conn, "UPDATE po_vendor SET status = 'batal' WHERE id_po = '$id_batal'");
    echo "<script>alert('PO berhasil dibatalkan.'); window.location='po_vendor.php';</script>";
}

// --- LOGIKA 2: PROSES BUAT PO BARU (HANYA ADMIN) ---
if (isset($_POST['buat_po']) && $role == 'admin') {
    $no_po      = mysqli_real_escape_string($conn, $_POST['no_po']);
    // PERBAIKAN: Input form name='nama_po' tapi kita simpan ke variabel $nama_vendor
    $nama_vendor = mysqli_real_escape_string($conn, $_POST['nama_po']); 
    $jenjang    = $_POST['jenjang'];
    $keterangan = mysqli_real_escape_string($conn, $_POST['keterangan']);
    
    // Proses Upload PDF
    $nama_file_pdf = "";
    if (!empty($_FILES['draft_pdf']['name'])) {
        $ext = pathinfo($_FILES['draft_pdf']['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) == 'pdf') {
            $nama_file_pdf = "PO_" . time() . ".pdf";
            $target_path = "../uploads/po/" . $nama_file_pdf;
            
            // Buat folder jika belum ada
            if (!is_dir('../uploads/po/')) {
                mkdir('../uploads/po/', 0777, true);
            }
            move_uploaded_file($_FILES['draft_pdf']['tmp_name'], $target_path);
        } else {
            echo "<script>alert('Gagal: File harus format PDF!');</script>";
        }
    }

    // PERBAIKAN SQL: Menggunakan kolom 'nama_vendor' bukan 'nama_po'
    $q_ins = "INSERT INTO po_vendor (no_po, nama_vendor, jenjang, status, keterangan, draft_pdf, tgl_pesan) 
              VALUES ('$no_po', '$nama_vendor', '$jenjang', 'draft', '$keterangan', '$nama_file_pdf', NOW())";
    
    if (mysqli_query($conn, $q_ins)) {
        $id_baru = mysqli_insert_id($conn);
        $log_msg = "Admin membuat PO Baru: $nama_vendor untuk jenjang $jenjang.";
        mysqli_query($conn, "INSERT INTO internal_messages (sender_id, receiver_role, pesan) VALUES (1, 'manager', '$log_msg')");
        echo "<script>alert('PO Berhasil Dibuat!'); window.location='detail_po_vendor.php?id=$id_baru';</script>";
    } else {
        echo "Error: " . mysqli_error($conn); // Tampilkan error jika ada masalah lain
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PO Vendor (Supply Chain) - Nusa Putera</title>
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>

    <!-- PANGGIL SIDEBAR FIX -->
    <?php include 'components/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-area">
            <div>
                <h2>📦 PO Vendor (Supply Chain)</h2>
                <p>Manajemen pemesanan stok borongan dan monitoring kedatangan barang.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 2fr; gap:30px; align-items: start;">
            
            <!-- KOLOM KIRI: FORM INPUT PO (HANYA ADMIN) -->
            <?php if($role == 'admin'): ?>
            <div class="card">
                <h4 style="margin-top:0; color:var(--primary);"><i class="ph-bold ph-plus-circle"></i> Buat PO Baru</h4>
                <form method="POST" enctype="multipart/form-data">
                    <label style="font-size:11px; font-weight:700;">NOMOR PO (OPSIONAL)</label>
                    <input type="text" name="no_po" placeholder="Cth: PO/2026/01">
                    
                    <label style="font-size:11px; font-weight:700;">NAMA PESANAN / VENDOR</label>
                    <!-- Name tetap nama_po untuk form, tapi di PHP diproses sebagai nama_vendor -->
                    <input type="text" name="nama_po" placeholder="Cth: Borongan Batik CV Jaya" required>

                    <label style="font-size:11px; font-weight:700;">UNIT JENJANG</label>
                    <select name="jenjang" required>
                        <option value="ALL">SEMUA JENJANG</option>
                        <option value="TK">TK</option>
                        <option value="SD">SD</option>
                        <option value="SMP">SMP</option>
                        <option value="SMA">SMA</option>
                        <option value="SMK">SMK</option>
                    </select>

                    <label style="font-size:11px; font-weight:700;">UPLOAD DRAFT PO (PDF)</label>
                    <input type="file" name="draft_pdf" accept=".pdf" style="padding:10px; background:white;">

                    <label style="font-size:11px; font-weight:700;">KETERANGAN</label>
                    <textarea name="keterangan" rows="2" placeholder="Catatan tambahan..."></textarea>

                    <button type="submit" name="buat_po" class="btn btn-primary" style="width:100%; justify-content:center;">
                        <i class="ph-bold ph-arrow-right"></i> LANJUT ISI ITEM
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div class="card" style="background:#f8fafc; border:1px dashed #cbd5e1;">
                <p style="text-align:center; color:#64748b; font-size:13px; margin:0;">
                    <i class="ph ph-lock" style="font-size:24px;"></i><br>
                    Menu pengajuan PO hanya dapat diakses oleh akun <b>Admin</b>.
                </p>
            </div>
            <?php endif; ?>

            <!-- KOLOM KANAN: MONITORING PO AKTIF -->
            <div class="card" style="padding:0; overflow:hidden;">
                <div style="padding:20px; border-bottom:1px solid #f1f5f9; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
                    <h4 style="margin:0;">📋 Monitoring Progres PO</h4>
                    <span class="badge bg-blue">Real-time Tracking</span>
                </div>
                
                <table style="width:100%; border-spacing:0;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding-left:20px;">PO Info</th>
                            <th style="text-align:center;">Draft</th>
                            <th style="text-align:center;">Kedatangan</th>
                            <th style="text-align:center; padding-right:20px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_po = mysqli_query($conn, "SELECT * FROM po_vendor WHERE status != 'batal' ORDER BY tgl_pesan DESC");
                        
                        if(mysqli_num_rows($q_po) == 0) echo "<tr><td colspan='4' align='center' style='padding:40px; color:#94a3b8;'>Tidak ada PO aktif.</td></tr>";
                        
                        while($row = mysqli_fetch_assoc($q_po)):
                            $id_po = $row['id_po'];
                            // Hitung progres
                            $q_stat = mysqli_query($conn, "SELECT SUM(qty_pesan) as tp, SUM(qty_datang) as td FROM po_vendor_items WHERE id_po = $id_po");
                            $st = mysqli_fetch_assoc($q_stat);
                            $persen = ($st['tp'] > 0) ? round(($st['td'] / $st['tp']) * 100) : 0;
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 15px 20px;">
                                <strong style="color:var(--text-main);"><?php echo $row['no_po'] ?: '#PO-'.$row['id_po']; ?></strong><br>
                                <!-- PERBAIKAN DISPLAY: Menggunakan $row['nama_vendor'] -->
                                <small style="color:var(--text-muted);"><?php echo $row['nama_vendor']; ?> (<?php echo $row['jenjang']; ?>)</small>
                            </td>
                            <td align="center">
                                <?php if($row['draft_pdf']): ?>
                                    <a href="../uploads/po/<?php echo $row['draft_pdf']; ?>" target="_blank" title="Lihat Draft PDF" style="color:var(--primary);"><i class="ph-bold ph-file-pdf" style="font-size:20px;"></i></a>
                                <?php else: echo "-"; endif; ?>
                            </td>
                            <td align="center">
                                <div style="width:80px; background:#e2e8f0; height:6px; border-radius:10px; margin-bottom:5px; overflow:hidden;">
                                    <div style="width:<?php echo $persen; ?>%; background:<?php echo ($persen==100)?'#10b981':'var(--primary)'; ?>; height:100%;"></div>
                                </div>
                                <small style="font-weight:800;"><?php echo $persen; ?>%</small>
                            </td>
                            <td align="center" style="padding-right:20px;">
                                <div style="display:flex; gap:5px; justify-content:center;">
                                    <a href="detail_po_vendor.php?id=<?php echo $id_po; ?>" class="btn btn-outline" style="padding:8px; border-radius:8px;">
                                        <i class="ph ph-magnifying-glass"></i>
                                    </a>
                                    <?php if($role == 'admin' && $row['status'] != 'selesai'): ?>
                                    <a href="po_vendor.php?cancel_id=<?php echo $id_po; ?>" class="btn" style="background:#fee2e2; color:#ef4444; padding:8px; border-radius:8px;" onclick="return confirm('Batalkan pesanan ini?')">
                                        <i class="ph ph-x-circle"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TABEL PO DIBATALKAN -->
        <div class="header-area" style="margin-top:50px; margin-bottom:15px;">
            <h3>🚫 Arsip PO Dibatalkan</h3>
        </div>
        <div class="card" style="padding:0; overflow:hidden; opacity:0.7;">
            <table style="width:100%; border-spacing:0;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="padding-left:25px;">No PO</th>
                        <th>Vendor</th>
                        <th>Keterangan</th>
                        <th style="text-align:center; padding-right:25px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $q_batal = mysqli_query($conn, "SELECT * FROM po_vendor WHERE status = 'batal' ORDER BY tgl_pesan DESC");
                    if(mysqli_num_rows($q_batal) == 0) echo "<tr><td colspan='4' align='center' style='padding:30px; color:#94a3b8;'>Tidak ada data pembatalan.</td></tr>";
                    while($rb = mysqli_fetch_assoc($q_batal)):
                    ?>
                    <tr>
                        <td style="padding:15px 25px;"><b><?php echo $rb['no_po'] ?: '#PO-'.$rb['id_po']; ?></b></td>
                        <!-- PERBAIKAN DISPLAY: Menggunakan $rb['nama_vendor'] -->
                        <td><?php echo $rb['nama_vendor']; ?></td>
                        <td><small><?php echo $rb['keterangan'] ?: '-'; ?></small></td>
                        <td align="center" style="padding-right:25px;"><span class="badge bg-red">CANCELLED</span></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>