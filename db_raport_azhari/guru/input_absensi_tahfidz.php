<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "guru") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$id_guru = $_SESSION['id_user'];
$error = $success = "";

// 1. Ambil ID Kelas yang diajar oleh Guru ini (sebagai Wali Kelas)
$query_kelas = "SELECT id_kelas, nama_kelas FROM tabel_kelas WHERE id_wali_kelas = ?";
$stmt_kelas = $conn->prepare($query_kelas);
$stmt_kelas->bind_param("i", $id_guru);
$stmt_kelas->execute();
$result_kelas = $stmt_kelas->get_result();

if ($result_kelas->num_rows == 0) {
    die("Anda tidak terdaftar sebagai Wali Kelas untuk kelas manapun.");
}
$kelas_info = $result_kelas->fetch_assoc();
$id_kelas = $kelas_info['id_kelas'];
$stmt_kelas->close();

// 2. Ambil ID Tahun Ajaran Aktif (KOREKSI SESUAI STRUKTUR TABEL USER)
// Menggunakan tabel: tabel_tahun_ajaran, Kolom: id_ta, Kolom Status: status = 'AKTIF'
$query_ta = "SELECT id_ta FROM tabel_tahun_ajaran WHERE status = 'AKTIF' LIMIT 1";
$result_ta = $conn->query($query_ta);
if ($result_ta->num_rows == 0) {
    die("Tidak ada Tahun Ajaran/Semester yang aktif. Harap set salah satu status 'AKTIF' di tabel_tahun_ajaran.");
}
// Variabel yang digunakan selanjutnya adalah ID Tahun Ajaran Aktif
$id_ta_aktif = $result_ta->fetch_assoc()['id_ta']; 

// =========================================================================
// 3. LOGIC SIMPAN / UPDATE DATA
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_all'])) {
    $conn->begin_transaction();
    $berhasil = 0;
    
    try {
        if (isset($_POST['nis'])) {
            foreach ($_POST['nis'] as $nis) {
                $nis_safe = sanitize_input($conn, $nis);
                
                // Ambil data Absensi
                $sakit = (int)($_POST['sakit'][$nis] ?? 0);
                $izin = (int)($_POST['izin'][$nis] ?? 0);
                $alpa = (int)($_POST['alpa'][$nis] ?? 0);
                
                // Ambil data Tahfidz
                $target_juz = (int)($_POST['target_juz'][$nis] ?? 0);
                $capaian_juz = (int)($_POST['capaian_juz'][$nis] ?? 0);
                $keterangan_tahfidz = sanitize_input($conn, $_POST['keterangan_tahfidz'][$nis] ?? '');
                
                // ----------------------------------------------------
                // A. Absensi: Cek apakah data sudah ada (UPDATE atau INSERT)
                // Menggunakan kolom id_ta
                // ----------------------------------------------------
                $sql_absensi_check = "SELECT id_absensi FROM tabel_absensi WHERE nis = ? AND id_ta = ?";
                $stmt_absensi_check = $conn->prepare($sql_absensi_check);
                $stmt_absensi_check->bind_param("si", $nis_safe, $id_ta_aktif);
                $stmt_absensi_check->execute();
                $result_absensi_check = $stmt_absensi_check->get_result();
                $stmt_absensi_check->close();

                if ($result_absensi_check->num_rows > 0) {
                    // Update
                    $sql_absensi = "UPDATE tabel_absensi SET sakit = ?, izin = ?, alpa = ?, tgl_input = NOW() WHERE nis = ? AND id_ta = ?";
                    $stmt_absensi = $conn->prepare($sql_absensi);
                    $stmt_absensi->bind_param("iiisi", $sakit, $izin, $alpa, $nis_safe, $id_ta_aktif);
                } else {
                    // Insert
                    $sql_absensi = "INSERT INTO tabel_absensi (nis, id_ta, sakit, izin, alpa, tgl_input) VALUES (?, ?, ?, ?, ?, NOW())";
                    $stmt_absensi = $conn->prepare($sql_absensi);
                    $stmt_absensi->bind_param("siiii", $nis_safe, $id_ta_aktif, $sakit, $izin, $alpa);
                }
                
                if (!$stmt_absensi->execute()) {
                     throw new Exception("Gagal menyimpan Absensi untuk NIS: {$nis_safe}. Error: " . $stmt_absensi->error);
                }
                $stmt_absensi->close();
                
                // ----------------------------------------------------
                // B. Tahfidz: Cek apakah data sudah ada (UPDATE atau INSERT)
                // Menggunakan kolom id_ta
                // ----------------------------------------------------
                $sql_tahfidz_check = "SELECT id_tahfidz FROM tabel_tahfidz WHERE nis = ? AND id_ta = ?";
                $stmt_tahfidz_check = $conn->prepare($sql_tahfidz_check);
                $stmt_tahfidz_check->bind_param("si", $nis_safe, $id_ta_aktif);
                $stmt_tahfidz_check->execute();
                $result_tahfidz_check = $stmt_tahfidz_check->get_result();
                $stmt_tahfidz_check->close();

                if ($result_tahfidz_check->num_rows > 0) {
                    // Update
                    $sql_tahfidz = "UPDATE tabel_tahfidz SET target_juz = ?, capaian_juz = ?, keterangan = ?, tgl_input = NOW() WHERE nis = ? AND id_ta = ?";
                    $stmt_tahfidz = $conn->prepare($sql_tahfidz);
                    $stmt_tahfidz->bind_param("iissi", $target_juz, $capaian_juz, $keterangan_tahfidz, $nis_safe, $id_ta_aktif);
                } else {
                    // Insert
                    $sql_tahfidz = "INSERT INTO tabel_tahfidz (nis, id_ta, target_juz, capaian_juz, keterangan, tgl_input) VALUES (?, ?, ?, ?, ?, NOW())";
                    $stmt_tahfidz = $conn->prepare($sql_tahfidz);
                    $stmt_tahfidz->bind_param("siiis", $nis_safe, $id_ta_aktif, $target_juz, $capaian_juz, $keterangan_tahfidz);
                }

                if (!$stmt_tahfidz->execute()) {
                     throw new Exception("Gagal menyimpan Tahfidz untuk NIS: {$nis_safe}. Error: " . $stmt_tahfidz->error);
                }
                $stmt_tahfidz->close();
                
                $berhasil++;
            }
        }
        
        $conn->commit();
        $success = "Berhasil memperbarui data Absensi & Tahfidz untuk {$berhasil} santri.";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Terjadi kesalahan: " . $e->getMessage();
    }
}


// 4. Ambil Data Santri Kelas Wali Kelas Beserta Data Absensi & Tahfidz mereka
// Menggunakan kolom id_ta
$query_santri_data = "
    SELECT 
        s.nis, 
        s.nama_santri,
        a.sakit, a.izin, a.alpa,
        t.target_juz, t.capaian_juz, t.keterangan AS keterangan_tahfidz
    FROM tabel_santri s
    LEFT JOIN tabel_absensi a ON s.nis = a.nis AND a.id_ta = ?
    LEFT JOIN tabel_tahfidz t ON s.nis = t.nis AND t.id_ta = ?
    WHERE s.id_kelas = ?
    ORDER BY s.nama_santri ASC
";

$stmt_data = $conn->prepare($query_santri_data);
$stmt_data->bind_param("iii", $id_ta_aktif, $id_ta_aktif, $id_kelas);
$stmt_data->execute();
$santri_list = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_data->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Input Absensi & Tahfidz</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ... CSS tetap sama ... */
        .data-table.input-form td {
            padding: 5px;
            vertical-align: middle;
        }
        .data-table.input-form input[type="number"], 
        .data-table.input-form input[type="text"] {
            width: 50px;
            padding: 3px;
            text-align: center;
        }
        .data-table.input-form input[type="text"] {
            width: 150px;
            text-align: left;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            background-color: white;
            z-index: 10;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Input Absensi & Tahfidz (Wali Kelas)</h2>
            </div>
            <div>
                 <a href="index.php">Dashboard Guru</a> | <a href="../logout.php">Logout</a>
            </div>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="success-message"><?php echo $success; ?></div>
        <?php elseif (!empty($error)): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>

        <h3>Kelas yang Anda Wali: <?php echo htmlspecialchars($kelas_info['nama_kelas'] ?? 'N/A'); ?></h3>
        <p>Silakan input total akumulasi Absensi dan Capaian Tahfidz Santri untuk Semester Aktif.</p>

        <form method="POST" action="input_absensi_tahfidz.php">
            <table class="data-table input-form">
                <thead class="sticky-header">
                    <tr>
                        <th rowspan="2">No.</th>
                        <th rowspan="2" width="20%">NIS / Nama Santri</th>
                        <th colspan="3">ABSENSI (Total Hari)</th>
                        <th colspan="3">LAPORAN TAHFIDZ</th>
                    </tr>
                    <tr>
                        <th width="8%">Sakit</th>
                        <th width="8%">Izin</th>
                        <th width="8%">Alpa</th>
                        <th width="10%">Target Juz</th>
                        <th width="10%">Capaian Juz</th>
                        <th width="20%">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (!empty($santri_list)):
                        $no = 1;
                        foreach ($santri_list as $santri):
                    ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td>
                            <input type="hidden" name="nis[]" value="<?php echo htmlspecialchars($santri['nis']); ?>">
                            <?php echo htmlspecialchars($santri['nis'] . ' - ' . $santri['nama_santri']); ?>
                        </td>
                        
                        <td><input type="number" name="sakit[<?php echo $santri['nis']; ?>]" min="0" value="<?php echo htmlspecialchars($santri['sakit'] ?? 0); ?>"></td>
                        <td><input type="number" name="izin[<?php echo $santri['nis']; ?>]" min="0" value="<?php echo htmlspecialchars($santri['izin'] ?? 0); ?>"></td>
                        <td><input type="number" name="alpa[<?php echo $santri['nis']; ?>]" min="0" value="<?php echo htmlspecialchars($santri['alpa'] ?? 0); ?>"></td>
                        
                        <td><input type="number" name="target_juz[<?php echo $santri['nis']; ?>]" min="0" max="30" value="<?php echo htmlspecialchars($santri['target_juz'] ?? 0); ?>"></td>
                        <td><input type="number" name="capaian_juz[<?php echo $santri['nis']; ?>]" min="0" max="30" value="<?php echo htmlspecialchars($santri['capaian_juz'] ?? 0); ?>"></td>
                        <td><input type="text" name="keterangan_tahfidz[<?php echo $santri['nis']; ?>]" value="<?php echo htmlspecialchars($santri['keterangan_tahfidz'] ?? ''); ?>"></td>
                    </tr>
                    <?php 
                        endforeach;
                    else:
                    ?>
                    <tr>
                        <td colspan="8">Tidak ada data santri di kelas ini.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if (!empty($santri_list)): ?>
                <button type="submit" name="save_all" style="margin-top: 20px;">SIMPAN SEMUA DATA (ABSENSI & TAHFIDZ)</button>
            <?php endif; ?>
        </form>

    </div>
</body>
</html>