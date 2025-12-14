<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "guru") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';
$id_guru = $_SESSION["id_user"];

$selected_mapel = $selected_kelas = $selected_ujian = $tahun_ajaran_aktif = null;

// Ambil Tahun Ajaran Aktif
$sql_ta = "SELECT id_ta, tahun_ajaran, semester FROM tabel_tahun_ajaran WHERE status = 'AKTIF' LIMIT 1";
$res_ta = $conn->query($sql_ta);
if ($res_ta && $res_ta->num_rows > 0) {
    $tahun_ajaran_aktif = $res_ta->fetch_assoc();
} else {
    // Handle error: TA tidak ditemukan
}

// 1. Ambil Mata Pelajaran yang diampu oleh Guru
$mapel_options = [];
$sql_mapel = "SELECT id_mapel, nama_mapel FROM tabel_mapel WHERE id_guru_pengampu = ?";
$stmt_mapel = $conn->prepare($sql_mapel);
$stmt_mapel->bind_param("i", $id_guru);
$stmt_mapel->execute();
$res_mapel = $stmt_mapel->get_result();
while ($row = $res_mapel->fetch_assoc()) {
    $mapel_options[] = $row;
}
$stmt_mapel->close();

// 2. Ambil Kelas yang diajar untuk Mapel tersebut (Ini adalah query yang disederhanakan)
// Idealnya, harus ada tabel penghubung Guru-Mapel-Kelas
$kelas_options = [];
// Untuk sementara, kita ambil semua kelas yang ada.
$sql_kelas = "SELECT id_kelas, nama_kelas FROM tabel_kelas ORDER BY nama_kelas";
$res_kelas = $conn->query($sql_kelas);
while ($row = $res_kelas->fetch_assoc()) {
    $kelas_options[] = $row;
}

// 3. LOGIC SUBMIT NILAI
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_nilai'])) {
    $selected_mapel = sanitize_input($conn, $_POST['id_mapel']);
    $selected_kelas = sanitize_input($conn, $_POST['id_kelas']);
    $selected_ujian = sanitize_input($conn, $_POST['jenis_ujian']);
    $id_ta_aktif = $tahun_ajaran_aktif['id_ta'] ?? 0;

    foreach ($_POST['nilai'] as $id_santri => $nilai_data) {
        $nilai_tuntas = sanitize_input($conn, $nilai_data['tuntas']);
        $nilai_remidial = sanitize_input($conn, $nilai_data['remidial']);

        if (!empty($nilai_tuntas) || !empty($nilai_remidial)) {
            // Cek apakah nilai untuk santri ini sudah ada
            $sql_check = "SELECT id_nilai FROM tabel_nilai_raport WHERE id_santri = ? AND id_mapel = ? AND id_ta = ? AND jenis_ujian = ?";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("iiss", $id_santri, $selected_mapel, $id_ta_aktif, $selected_ujian);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                // UPDATE
                $sql_save = "UPDATE tabel_nilai_raport SET nilai_tuntas = ?, nilai_remidial = ? WHERE id_santri = ? AND id_mapel = ? AND id_ta = ? AND jenis_ujian = ?";
                $stmt_save = $conn->prepare($sql_save);
                $stmt_save->bind_param("iiiiis", $nilai_tuntas, $nilai_remidial, $id_santri, $selected_mapel, $id_ta_aktif, $selected_ujian);
            } else {
                // INSERT
                $sql_save = "INSERT INTO tabel_nilai_raport (id_santri, id_mapel, id_ta, jenis_ujian, nilai_tuntas, nilai_remidial) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_save = $conn->prepare($sql_save);
                $stmt_save->bind_param("iisssi", $id_santri, $selected_mapel, $id_ta_aktif, $selected_ujian, $nilai_tuntas, $nilai_remidial);
            }
            
            if (!$stmt_save->execute()) {
                echo "<div class='error-message'>Gagal menyimpan nilai untuk Santri ID $id_santri: " . $stmt_save->error . "</div>";
            }
            $stmt_check->close();
            $stmt_save->close();
        }
    }
    // Refresh halaman atau tampilkan pesan sukses
    header("location: input_nilai.php?success=1&mapel=$selected_mapel&kelas=$selected_kelas&ujian=$selected_ujian");
    exit;
}

// 4. LOGIC TAMPILKAN DAFTAR SANTRI DAN DATA NILAI YANG SUDAH DIINPUT
$santri_data = [];
$nilai_terinput = [];
if (isset($_GET['mapel']) && isset($_GET['kelas']) && isset($_GET['ujian'])) {
    $selected_mapel = sanitize_input($conn, $_GET['mapel']);
    $selected_kelas = sanitize_input($conn, $_GET['kelas']);
    $selected_ujian = sanitize_input($conn, $_GET['ujian']);
    $id_ta_aktif = $tahun_ajaran_aktif['id_ta'] ?? 0;

    $sql_santri = "
        SELECT 
            s.id_santri, s.nama_santri, s.nis,
            nr.nilai_tuntas, nr.nilai_remidial
        FROM tabel_santri s
        LEFT JOIN tabel_nilai_raport nr 
            ON s.id_santri = nr.id_santri 
            AND nr.id_mapel = ?
            AND nr.id_ta = ?
            AND nr.jenis_ujian = ?
        WHERE s.id_kelas = ?
        ORDER BY s.nama_santri
    ";
    $stmt_santri = $conn->prepare($sql_santri);
    $stmt_santri->bind_param("iisi", $selected_mapel, $id_ta_aktif, $selected_ujian, $selected_kelas);
    $stmt_santri->execute();
    $res_santri = $stmt_santri->get_result();
    while ($row = $res_santri->fetch_assoc()) {
        $santri_data[] = $row;
    }
    $stmt_santri->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Input Nilai Ujian - Guru</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <a href="index.php">Kembali ke Dashboard</a> | <a href="../logout.php">Logout</a>

        <h2>Input Nilai Ujian (PTS/PAS)</h2>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">Nilai berhasil disimpan atau diperbarui.</div>
        <?php endif; ?>

        <?php if (!$tahun_ajaran_aktif): ?>
             <div class="error-message">Error: Tahun Ajaran Aktif belum diset oleh Admin.</div>
        <?php else: ?>
            <div class="info-ta">
                Tahun Ajaran Aktif: **<?php echo htmlspecialchars($tahun_ajaran_aktif['tahun_ajaran'] . " / " . $tahun_ajaran_aktif['semester']); ?>**
            </div>

            <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <label>Pilih Mata Pelajaran:</label>
                <select name="mapel" required>
                    <option value="">-- Pilih Mapel --</option>
                    <?php foreach ($mapel_options as $mapel): ?>
                        <option value="<?php echo $mapel['id_mapel']; ?>" <?php echo $selected_mapel == $mapel['id_mapel'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mapel['nama_mapel']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Pilih Kelas:</label>
                <select name="kelas" required>
                    <option value="">-- Pilih Kelas --</option>
                    <?php foreach ($kelas_options as $kelas): ?>
                        <option value="<?php echo $kelas['id_kelas']; ?>" <?php echo $selected_kelas == $kelas['id_kelas'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Jenis Ujian:</label>
                <select name="ujian" required>
                    <option value="">-- Pilih Ujian --</option>
                    <option value="PTS" <?php echo $selected_ujian == 'PTS' ? 'selected' : ''; ?>>PTS</option>
                    <option value="PAS" <?php echo $selected_ujian == 'PAS' ? 'selected' : ''; ?>>PAS</option>
                </select>

                <button type="submit">Tampilkan Santri</button>
            </form>

            <hr>

            <?php if (!empty($santri_data)): ?>
                <h3>Input Nilai untuk Kelas **<?php echo htmlspecialchars($selected_kelas); ?>** - Mapel **<?php echo htmlspecialchars($selected_mapel); ?>** (<?php echo htmlspecialchars($selected_ujian); ?>)</h3>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="id_mapel" value="<?php echo htmlspecialchars($selected_mapel); ?>">
                    <input type="hidden" name="id_kelas" value="<?php echo htmlspecialchars($selected_kelas); ?>">
                    <input type="hidden" name="jenis_ujian" value="<?php echo htmlspecialchars($selected_ujian); ?>">
                    
                    <table border="1">
                        <thead>
                            <tr>
                                <th>N</th>
                                <th>Nama Santri</th>
                                <th>NIS</th>
                                <th>Nilai Santri (الدور الأولى)</th>
                                <th>Nilai Remedial (الدور الثاني)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($santri_data as $santri): ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td><?php echo htmlspecialchars($santri['nama_santri']); ?></td>
                                <td><?php echo htmlspecialchars($santri['nis']); ?></td>
                                <td>
                                    <input type="number" name="nilai[<?php echo $santri['id_santri']; ?>][tuntas]" 
                                           value="<?php echo htmlspecialchars($santri['nilai_tuntas']); ?>" min="0" max="100" style="width: 70px;">
                                </td>
                                <td>
                                    <input type="number" name="nilai[<?php echo $santri['id_santri']; ?>][remidial]" 
                                           value="<?php echo htmlspecialchars($santri['nilai_remidial']); ?>" min="0" max="100" style="width: 70px;">
                                </td>
                                <td>
                                    <?php 
                                        if (!is_null($santri['nilai_tuntas'])) {
                                            echo '<span style="color: green;">Sudah diinput</span>';
                                        } else {
                                            echo '<span style="color: red;">Belum diinput</span>';
                                        }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <br>
                    <button type="submit" name="submit_nilai">Kirim/Ubah Nilai</button>
                </form>
            <?php elseif (isset($_GET['mapel']) && isset($_GET['kelas'])): ?>
                <div class="warning-message">Tidak ada data santri ditemukan di kelas ini atau input belum lengkap.</div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>