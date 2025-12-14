<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = "";
$ta_aktif = null;
$kelas_options = [];
$santri_review = [];
$selected_kelas = null;

// Ambil Tahun Ajaran Aktif
$sql_ta = "SELECT id_ta, tahun_ajaran, semester FROM tabel_tahun_ajaran WHERE status = 'AKTIF' LIMIT 1";
$res_ta = $conn->query($sql_ta);
if ($res_ta && $res_ta->num_rows > 0) {
    $ta_aktif = $res_ta->fetch_assoc();
} else {
    $error = "Error: Tahun Ajaran Aktif belum diset. Harap atur di menu 'Kelola Tahun Ajaran'.";
}

// Ambil Pilihan Kelas
$sql_kelas = "SELECT id_kelas, nama_kelas FROM tabel_kelas ORDER BY nama_kelas";
$res_kelas = $conn->query($sql_kelas);
while ($row = $res_kelas->fetch_assoc()) {
    $kelas_options[] = $row;
}

// --- LOGIC TAMPILKAN REVIEW NILAI ---
if ($ta_aktif && isset($_GET['kelas'])) {
    $id_ta_aktif = $ta_aktif['id_ta'];
    $selected_kelas = sanitize_input($conn, $_GET['kelas']);

    // Query Kompleks untuk mengambil semua data nilai per santri
    $sql_review = "
        SELECT 
            s.id_santri, s.nama_santri, s.nis, s.alamat,
            k.nama_kelas, tg_wali.nama_guru AS nama_wali_kelas,
            
            -- Hitung Total Nilai (mengambil nilai tertinggi dari tuntas/remidial untuk PAS)
            SUM(CASE 
                WHEN nr.jenis_ujian = 'PAS' THEN COALESCE(GREATEST(nr.nilai_tuntas, nr.nilai_remidial), nr.nilai_tuntas, nr.nilai_remidial, 0)
                ELSE 0 
            END) AS total_nilai_pas,
            
            -- Hitung Jumlah Mapel PAS yang dinilai
            COUNT(DISTINCT CASE 
                WHEN nr.jenis_ujian = 'PAS' AND COALESCE(nr.nilai_tuntas, nr.nilai_remidial) IS NOT NULL THEN nr.id_mapel 
                ELSE NULL 
            END) AS jumlah_mapel_pas
            
        FROM tabel_santri s
        INNER JOIN tabel_kelas k ON s.id_kelas = k.id_kelas
        LEFT JOIN tabel_guru tg_wali ON k.id_wali_kelas = tg_wali.id_guru
        LEFT JOIN tabel_nilai_raport nr ON s.id_santri = nr.id_santri AND nr.id_ta = ?
        
        WHERE s.id_kelas = ?
        GROUP BY s.id_santri
        ORDER BY s.nama_santri
    ";
    
    $stmt_review = $conn->prepare($sql_review);
    $stmt_review->bind_param("ii", $id_ta_aktif, $selected_kelas);
    $stmt_review->execute();
    $res_review = $stmt_review->get_result();
    
    $santri_review = [];
    while ($row = $res_review->fetch_assoc()) {
        $row['rata_rata'] = ($row['jumlah_mapel_pas'] > 0) ? round($row['total_nilai_pas'] / $row['jumlah_mapel_pas'], 2) : 0;
        // Penentuan Predikat Sederhana (LEMAH/ضعیف)
        $row['predikat'] = ($row['rata_rata'] >= 75) ? 'BAIK' : 'LEMAH/ضعیف'; 
        $santri_review[] = $row;
    }
    $stmt_review->close();

    // Logika Perhitungan Peringkat
    // Harus dihitung setelah semua rata-rata ditemukan
    usort($santri_review, function($a, $b) {
        return $b['rata_rata'] <=> $a['rata_rata']; // Urutkan descending berdasarkan rata-rata
    });

    foreach ($santri_review as $index => &$santri) {
        $santri['peringkat'] = $index + 1; // Peringkat (rتبة الطالب / PERINGKAT)
    }
    unset($santri); // Hapus referensi
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Review & Cetak Raport - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Review Nilai Dan Cetak</h2>
            </div>
            
            <div>
                 <a href="index.php">Dashboard</a> | <a href="../logout.php">Logout</a>
            </div>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php else: ?>
            <div class="info-ta">
                Tahun Ajaran Aktif: **<?php echo htmlspecialchars($ta_aktif['tahun_ajaran'] . " / " . $ta_aktif['semester']); ?>**
            </div>

            <form method="GET" action="nilai_review.php">
                <label>Pilih Kelas untuk Review:</label>
                <select name="kelas" required>
                    <option value="">-- Pilih Kelas --</option>
                    <?php foreach ($kelas_options as $kelas): ?>
                        <option value="<?php echo $kelas['id_kelas']; ?>" <?php echo $selected_kelas == $kelas['id_kelas'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Tampilkan Review</button>
            </form>

            <hr>

            <?php if (!empty($santri_review)): ?>
                <h3>Review Nilai Santri Kelas: <?php echo htmlspecialchars($santri_review[0]['nama_kelas']); ?></h3>
                
                <table border="1" class="data-table">
                    <thead>
                        <tr>
                            <th>N</th>
                            <th>Nama Santri (الإسم)</th>
                            <th>TOTAL NILAI (و المجموع درجاته)</th>
                            <th>RATA - RATA (المعدل التراكي)</th>
                            <th>PREDIKAT (التقدير / ضعيف)</th>
                            <th>PERINGKAT (رتبة الطالب)</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($santri_review as $santri): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($santri['nama_santri']); ?></td>
                            <td><?php echo htmlspecialchars($santri['total_nilai_pas']); ?></td>
                            <td><?php echo htmlspecialchars($santri['rata_rata']); ?></td>
                            <td><?php echo htmlspecialchars($santri['predikat']); ?></td>
                            <td><?php echo htmlspecialchars($santri['peringkat']); ?></td>
                            <td>
                            <a href="../raport_print.php?nis=<?php echo $santri['nis']; ?>&ta=<?php echo $id_ta_aktif; ?>" target="_blank" class="btn-print">Cetak Raport</a>                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif (isset($_GET['kelas'])): ?>
                <div class="warning-message">Tidak ada data nilai yang cukup untuk kelas ini pada Tahun Ajaran Aktif.</div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>