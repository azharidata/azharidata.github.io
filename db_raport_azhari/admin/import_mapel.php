<?php
session_start();
// Autentikasi Admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";
$import_results = [];

// Daftar kategori yang valid sesuai ENUM di tabel_mapel
$valid_kategori = ['AGAMA', 'BAHASA ARAB', 'TAMBAHAN', 'WAJIB'];

// ====================================================================
// FUNGSI UTILITY (Diambil dari mapel.php)
// ====================================================================

/**
 * Menyimpan relasi Mata Pelajaran ke Kelas yang terpilih.
 * @param mysqli $conn Koneksi database.
 * @param int $id_mapel ID Mata Pelajaran yang baru di-insert.
 * @param array $selected_kelas Array of int ID Kelas.
 */
function insert_mapel_kelas_relations($conn, $id_mapel, $selected_kelas) {
    if (!empty($selected_kelas) && is_array($selected_kelas)) {
        // Pastikan semua nilai adalah integer dan valid
        $selected_kelas = array_filter($selected_kelas, fn($id) => $id > 0);
        
        if (empty($selected_kelas)) return;

        $values = [];
        $types = "";
        $params = [];
        
        // Buat placeholder untuk query INSERT batch
        foreach ($selected_kelas as $id_kelas) {
            $values[] = "(?, ?)";
            $types .= "ii";
            $params[] = $id_mapel;
            $params[] = $id_kelas;
        }

        if (!empty($values)) {
            $sql_insert = "INSERT INTO tabel_mapel_kelas (id_mapel, id_kelas) VALUES " . implode(", ", $values);
            $stmt_insert = $conn->prepare($sql_insert);
            
            // Binding parameter secara dinamis
            $stmt_insert->bind_param($types, ...$params);
            
            if (!$stmt_insert->execute()) {
                 // Throw exception agar ditangkap oleh try-catch utama dan di-rollback
                 throw new Exception("Gagal menyimpan relasi Kelas: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }
    }
}


// ====================================================================
// LOGIC IMPORT CSV
// ====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["csv_file"])) {
    $file_info = $_FILES["csv_file"];
    
    // Validasi file
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
                // Lewati header
                fgetcsv($handle); 

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $total_rows++;

                    // --- [PERUBAHAN UTAMA: Validasi Kolom] ---
                    // Wajib 5 kolom: Nama Mapel, Kategori, KKM, ID Guru Pengampu, ID Kelas
                    if (count($data) < 5) { 
                        $import_results[] = ["Data Baris {$total_rows}", "Gagal: Kolom kurang dari 5 (Nama Mapel, Kategori, KKM, ID Guru, ID Kelas)."];
                        continue;
                    }

                    $nama_mapel = sanitize_input($conn, trim($data[0]));
                    $kategori = strtoupper(sanitize_input($conn, trim($data[1]))); 
                    $kkm = (int)sanitize_input($conn, trim($data[2]));
                    $id_guru_pengampu_input = sanitize_input($conn, trim($data[3] ?? '')); 
                    $id_kelas_input = sanitize_input($conn, trim($data[4])); // Kolom ke-5: ID Kelas
                    
                    // Proses ID Kelas (split string ID kelas, contoh: "1|2|3" atau "1,2,3")
                    // Menggunakan pipe '|' sebagai delimiter yang disarankan
                    $id_kelas_array = array_filter(array_map('trim', explode('|', $id_kelas_input))); 
                    $selected_kelas = array_map('intval', $id_kelas_array);

                    // --- [PERUBAHAN UTAMA: Validasi Konten] ---
                    if (empty($nama_mapel) || !in_array($kategori, $valid_kategori) || !is_numeric($kkm) || empty($selected_kelas)) {
                        $import_results[] = ["Mapel: {$nama_mapel}", "Gagal: Data tidak lengkap, Kategori ('{$kategori}') tidak valid, KKM bukan angka, atau ID Kelas tidak ditemukan/kosong."];
                        continue;
                    }
                    
                    // --- Dynamic SQL handling id_guru_pengampu (NULLable) ---
                    if (empty($id_guru_pengampu_input)) {
                        // INSERT tanpa kolom id_guru_pengampu
                        $sql_insert = "INSERT INTO tabel_mapel (nama_mapel, kategori, kkm) VALUES (?, ?, ?)";
                        $stmt = $conn->prepare($sql_insert);
                        $stmt->bind_param("ssi", $nama_mapel, $kategori, $kkm);
                        $message_suffix = " (Pengampu: NULL)";
                    } else {
                        // INSERT dengan id_guru_pengampu
                        $id_guru_pengampu = (int)$id_guru_pengampu_input;
                        $sql_insert = "INSERT INTO tabel_mapel (nama_mapel, kategori, kkm, id_guru_pengampu) VALUES (?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql_insert);
                        $stmt->bind_param("ssii", $nama_mapel, $kategori, $kkm, $id_guru_pengampu);
                        $message_suffix = " (Pengampu ID: {$id_guru_pengampu})";
                    }
                    
                    // Eksekusi INSERT ke tabel_mapel
                    if ($stmt->execute()) {
                        $new_mapel_id = $conn->insert_id; // Ambil ID mapel yang baru
                        $stmt->close();
                        
                        // --- [PERUBAHAN UTAMA: Insert Relasi Kelas] ---
                        insert_mapel_kelas_relations($conn, $new_mapel_id, $selected_kelas);

                        $success_rows++;
                        $kelas_ids = implode('|', $selected_kelas);
                        $import_results[] = ["Mapel: {$nama_mapel}", "Berhasil: Ditambahkan. Kelas ID: [{$kelas_ids}], KKM: {$kkm}{$message_suffix}."];
                        
                    } else {
                        // Error 1062: Duplicate entry
                        $error_msg = ($conn->errno == 1062) ? "Gagal: Nama Mata Pelajaran '{$nama_mapel}' sudah ada." : "Gagal: " . $stmt->error;
                        $import_results[] = ["Mapel: {$nama_mapel}", $error_msg];
                        $stmt->close();
                    }
                }
                fclose($handle);
            }
            
            // Commit atau Rollback
            if ($total_rows > 0 && $success_rows > 0) {
                $conn->commit();
                $success = "Proses impor selesai. {$success_rows} dari {$total_rows} data Mapel berhasil ditambahkan (termasuk relasi Kelas).";
            } else {
                // Rollback jika ada total row tapi semua gagal (success_rows == 0)
                $conn->rollback();
                $error = $error ?? "File CSV kosong atau tidak ada data yang valid setelah header. Semua perubahan dibatalkan.";
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
    <title>Import Data Mata Pelajaran - Admin</title>
    <link rel="stylesheet" href="/db_raport_azhari/assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Import Data Mata Pelajaran (CSV)</h2>
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
            <h3>Unggah File CSV Data Mata Pelajaran</h3>
            
            <p><strong>Panduan CSV (Wajib 5 Kolom):</strong></p>
            <ol>
                <li>Nama Mata Pelajaran</li>
                <li>Kategori (Wajib sesuai daftar di bawah)</li>
                <li>KKM (Angka)</li>
                <li>ID Guru Pengampu (Angka ID Guru / Kosongkan)</li>
                <li><strong>ID Kelas (Angka ID Kelas / Dipisahkan `|` jika banyak, Contoh: `1|2|3`)</strong></li>
            </ol>

            <p><strong>Kategori yang Valid (HARUS SAMA PERSIS):</strong></p>
            <ul>
                <?php foreach ($valid_kategori as $kategori): ?>
                    <li><code><?php echo htmlspecialchars($kategori); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <form method="POST" action="import_mapel.php" enctype="multipart/form-data">
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