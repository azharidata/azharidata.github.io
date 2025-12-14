<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";
$import_results = []; // Menyimpan hasil impor per baris

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["csv_file"])) {
    $file_info = $_FILES["csv_file"];
    
    // Validasi dasar file
    if ($file_info["error"] !== UPLOAD_ERR_OK) {
        $error = "Gagal mengunggah file. Kode error: " . $file_info["error"];
    } elseif ($file_info["type"] !== "text/csv" && pathinfo($file_info["name"], PATHINFO_EXTENSION) !== 'csv') {
        $error = "File harus berformat CSV.";
    } else {
        $file = $file_info["tmp_name"];
        
        // Mulai transaksi database
        $conn->begin_transaction();
        $total_rows = 0;
        $success_rows = 0;
        
        try {
            // Buka file CSV
            if (($handle = fopen($file, "r")) !== FALSE) {
                // Lewati header (baris pertama)
                fgetcsv($handle); 
                
                // Ambil semua ID Kelas untuk lookup cepat
                $kelas_map = [];
                $res_kelas = $conn->query("SELECT id_kelas, nama_kelas FROM tabel_kelas");
                while ($row = $res_kelas->fetch_assoc()) {
                    $kelas_map[strtolower($row['nama_kelas'])] = $row['id_kelas'];
                }

                // Siapkan statement INSERT
                $sql_insert = "INSERT INTO tabel_santri (nis, nama_santri, id_kelas, alamat) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql_insert);

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $total_rows++;

                    // Validasi jumlah kolom (minimal 3: NIS, Nama, Kelas)
                    if (count($data) < 3) {
                        $import_results[] = ["NIS: N/A", "Gagal: Kolom kurang dari 3"];
                        continue;
                    }

                    // Ambil data dari kolom CSV
                    $nis = sanitize_input($conn, trim($data[0]));
                    $nama_santri = sanitize_input($conn, trim($data[1]));
                    $nama_kelas = sanitize_input($conn, trim($data[2]));
                    $alamat = sanitize_input($conn, (isset($data[3]) ? trim($data[3]) : ''));

                    $lookup_kelas = strtolower($nama_kelas);
                    $id_kelas = $kelas_map[$lookup_kelas] ?? null;

                    if (empty($nis) || empty($nama_santri) || empty($id_kelas)) {
                        $import_results[] = ["NIS: {$nis}", "Gagal: Data NIS, Nama, atau Nama Kelas ('{$nama_kelas}') tidak valid/tidak ditemukan."];
                        continue;
                    }
                    
                    // Eksekusi INSERT
                    $stmt->bind_param("ssis", $nis, $nama_santri, $id_kelas, $alamat);
                    if ($stmt->execute()) {
                        $success_rows++;
                        $import_results[] = ["NIS: {$nis}", "Berhasil: Ditambahkan ke Kelas {$nama_kelas}."];
                    } else {
                        // Error 1062: Duplicate entry (NIS sudah ada)
                        $error_msg = ($conn->errno == 1062) ? "Gagal: NIS sudah terdaftar." : "Gagal: " . $stmt->error;
                        $import_results[] = ["NIS: {$nis}", $error_msg];
                    }
                }
                fclose($handle);
                $stmt->close();
            }
            
            if ($total_rows > 0 && $success_rows > 0) {
                $conn->commit();
                $success = "Proses impor selesai. {$success_rows} dari {$total_rows} data Santri berhasil ditambahkan/diperbarui.";
            } elseif ($total_rows > 0 && $success_rows == 0) {
                 $conn->rollback();
                 $error = "Semua data gagal diimpor. Periksa detail hasil impor.";
            } else {
                $conn->rollback();
                $error = "File CSV kosong atau tidak ada data setelah header.";
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan fatal selama impor: " . $e->getMessage();
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Import Data Santri - Admin</title>
    <link rel="stylesheet" href="/db_raport_azhari/assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Import Data Santri (CSV)</h2>
            </div>
            <div>
                 <a href="index.php">Dashboard</a> | <a href="../logout.php">Logout</a>
            </div>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="success-message"><?php echo $success; ?></div>
        <?php elseif (!empty($error)): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="form-section">
            <h3>Unggah File CSV Data Santri</h3>
            <p><strong>Panduan:</strong> Pastikan file CSV Anda memiliki 4 kolom berurutan: NIS, Nama Santri, Nama Kelas (harus sama persis dengan yang ada di sistem), dan Alamat.</p>
            <form method="POST" action="import_santri.php" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="csv_file">Pilih File CSV:</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                </div>
                <button type="submit">Mulai Import</button>
            </form>
        </div>
        
        <hr>

        <?php if (!empty($import_results)): ?>
            <h3>Detail Hasil Impor (<?php echo $total_rows ?? 0; ?> Baris)</h3>
            <table border="1" class="data-table" style="font-size: 0.9em;">
                <thead>
                    <tr>
                        <th width="30%">Data Baris</th>
                        <th width="70%">Status/Pesan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($import_results as $result): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($result[0]); ?></td>
                        <td style="color: <?php echo strpos($result[1], 'Berhasil') !== false ? 'green' : 'red'; ?>;">
                            <?php echo htmlspecialchars($result[1]); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</body>
</html>