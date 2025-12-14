<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";
$import_results = [];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["csv_file"])) {
    $file_info = $_FILES["csv_file"];
    
    if ($file_info["error"] !== UPLOAD_ERR_OK) {
        $error = "Gagal mengunggah file. Kode error: " . $file_info["error"];
    } elseif ($file_info["type"] !== "text/csv" && pathinfo($file_info["name"], PATHINFO_EXTENSION) !== 'csv') {
        $error = "File harus berformat CSV.";
    } else {
        $file = $file_info["tmp_name"];
        $conn->begin_transaction();
        $total_rows = 0;
        $success_rows = 0;
        
        try {
            if (($handle = fopen($file, "r")) !== FALSE) {
                fgetcsv($handle); // Lewati header
                
                $sql_insert = "INSERT INTO tabel_guru (nama_guru, username, password, is_wali_kelas) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql_insert);

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $total_rows++;

                    if (count($data) < 4) {
                        $import_results[] = ["Data Kurang", "Gagal: Kolom kurang dari 4 (Nama, Username, Password, Wali Kelas)."];
                        continue;
                    }

                    $nama_guru = sanitize_input($conn, trim($data[0]));
                    $username = sanitize_input($conn, trim($data[1]));
                    $password_plain = trim($data[2]); // Password mentah
                    $is_wali = (int)sanitize_input($conn, trim($data[3])); // 0 atau 1

                    if (empty($nama_guru) || empty($username) || empty($password_plain)) {
                        $import_results[] = ["User: {$username}", "Gagal: Data Nama, Username, atau Password kosong."];
                        continue;
                    }
                    
                    // HASH PASSWORD
                    $hashed_password = password_hash($password_plain, PASSWORD_DEFAULT);
                    
                    // Eksekusi INSERT
                    $stmt->bind_param("sssi", $nama_guru, $username, $hashed_password, $is_wali);
                    
                    if ($stmt->execute()) {
                        $success_rows++;
                        $import_results[] = ["User: {$username} ({$nama_guru})", "Berhasil: Password di-hash dan ditambahkan."];
                    } else {
                        $error_msg = ($conn->errno == 1062) ? "Gagal: Username '{$username}' sudah terdaftar." : "Gagal: " . $stmt->error;
                        $import_results[] = ["User: {$username}", $error_msg];
                    }
                }
                fclose($handle);
                $stmt->close();
            }
            
            // Commit atau Rollback
            if ($success_rows > 0) {
                $conn->commit();
                $success = "Proses impor selesai. {$success_rows} dari {$total_rows} data Guru berhasil ditambahkan.";
            } elseif ($total_rows > 0) {
                 $conn->rollback();
                 $error = "Semua data Guru gagal diimpor. Periksa detail hasil impor.";
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
    <title>Import Data Guru - Admin</title>
    <link rel="stylesheet" href="/db_raport_azhari/assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Import Data Guru (CSV)</h2>
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
            <h3>Unggah File CSV Data Guru</h3>
            <p><strong>Panduan:</strong> Pastikan file CSV memiliki 4 kolom berurutan: Nama Guru, Username, Password, dan Status Wali Kelas (isi 1 jika ya, 0 jika tidak).</p>
            <form method="POST" action="import_guru.php" enctype="multipart/form-data">
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