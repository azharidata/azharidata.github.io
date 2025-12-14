<?php
session_start();
// Autentikasi Admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";

// --- LOGIC TAMBAH/EDIT SANTRI ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nis = sanitize_input($conn, $_POST['nis']);
    $nama = sanitize_input($conn, $_POST['nama_santri']);
    $id_kelas = sanitize_input($conn, $_POST['id_kelas']);
    $alamat = sanitize_input($conn, $_POST['alamat']);
    $id_santri = isset($_POST['id_santri']) ? sanitize_input($conn, $_POST['id_santri']) : null;

    if (empty($nis) || empty($nama) || empty($id_kelas)) {
        $error = "NIS, Nama, dan Kelas wajib diisi.";
    } else {
        if ($id_santri) {
            // Proses EDIT
            $sql = "UPDATE tabel_santri SET nis = ?, nama_santri = ?, id_kelas = ?, alamat = ? WHERE id_santri = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssisi", $nis, $nama, $id_kelas, $alamat, $id_santri);
        } else {
            // Proses TAMBAH
            $sql = "INSERT INTO tabel_santri (nis, nama_santri, id_kelas, alamat) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssis", $nis, $nama, $id_kelas, $alamat);
        }
        
        if ($stmt->execute()) {
            $success = $id_santri ? "Data Santri berhasil diubah." : "Data Santri berhasil ditambahkan.";
        } else {
            $error = "Gagal menyimpan data Santri: " . ($conn->errno == 1062 ? "NIS sudah ada." : $stmt->error);
        }
        $stmt->close();
    }
}

// --- LOGIC HAPUS SANTRI ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_hapus = sanitize_input($conn, $_GET['id']);
    
    // Gunakan Transaksi untuk memastikan semua data terkait dihapus (Foreign Key)
    $conn->begin_transaction();
    try {
        // Hapus data terkait
        $conn->query("DELETE FROM tabel_nilai_raport WHERE id_santri = $id_hapus");
        $conn->query("DELETE FROM tabel_tahfidz WHERE id_santri = $id_hapus");
        $conn->query("DELETE FROM tabel_absensi WHERE id_santri = $id_hapus");
        
        // Hapus Santri Utama
        $sql_delete = "DELETE FROM tabel_santri WHERE id_santri = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $id_hapus);
        $stmt_delete->execute();
        $stmt_delete->close();
        
        $conn->commit();
        $success = "Data Santri dan data terkait berhasil dihapus.";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Gagal menghapus Santri: " . $e->getMessage();
    }
    
    header("location: santri.php");
    exit;
}

// --- LOGIC AMBIL DATA MASTER (Kelas dan Santri) ---
$kelas_options = [];
$sql_kelas = "SELECT id_kelas, nama_kelas FROM tabel_kelas ORDER BY nama_kelas";
$result_kelas = $conn->query($sql_kelas);
while ($row = $result_kelas->fetch_assoc()) {
    $kelas_options[] = $row;
}

$santri_data = [];
$sql_santri = "
    SELECT ts.id_santri, ts.nis, ts.nama_santri, tk.nama_kelas, ts.alamat, ts.id_kelas
    FROM tabel_santri ts
    INNER JOIN tabel_kelas tk ON ts.id_kelas = tk.id_kelas
    ORDER BY tk.nama_kelas, ts.nama_santri
";
$result_santri = $conn->query($sql_santri);
while ($row = $result_santri->fetch_assoc()) {
    $santri_data[] = $row;
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Data Santri - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Kelola Data Santri</h2>
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
            <h3>Tambah Santri Baru</h3>
            <form method="POST" action="santri.php">
                <input type="hidden" name="id_santri" id="edit_id_santri">
                <div class="form-group">
                    <label>NIS (Nomor Induk Santri):</label>
                    <input type="text" name="nis" id="edit_nis" required>
                </div>
                <div class="form-group">
                    <label>Nama Lengkap Santri:</label>
                    <input type="text" name="nama_santri" id="edit_nama_santri" required>
                </div>
                <div class="form-group">
                    <label>Kelas:</label>
                    <select name="id_kelas" id="edit_id_kelas" required>
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach ($kelas_options as $kelas): ?>
                            <option value="<?php echo $kelas['id_kelas']; ?>"><?php echo htmlspecialchars($kelas['nama_kelas']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Alamat:</label>
                    <textarea name="alamat" id="edit_alamat"></textarea>
                </div>
                <button type="submit">Simpan Santri</button>
            </form>
        </div>
        
        <hr>

        <h3>Daftar Santri Terdaftar</h3>
        <table border="1" class="data-table">
            <thead>
                <tr>
                    <th>N</th>
                    <th>NIS</th>
                    <th>Nama Santri</th>
                    <th>Kelas</th>
                    <th>Alamat</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($santri_data as $santri): ?>
                <tr>
                    <td><?php echo $no++; ?></td>
                    <td><?php echo htmlspecialchars($santri['nis']); ?></td>
                    <td><?php echo htmlspecialchars($santri['nama_santri']); ?></td>
                    <td><?php echo htmlspecialchars($santri['nama_kelas']); ?></td>
                    <td><?php echo htmlspecialchars($santri['alamat']); ?></td>
                    <td>
                        <a href="#" onclick="editSantri(<?php echo $santri['id_santri']; ?>, '<?php echo $santri['nis']; ?>', '<?php echo $santri['nama_santri']; ?>', '<?php echo $santri['id_kelas']; ?>', '<?php echo htmlspecialchars(addslashes($santri['alamat'])); ?>')">Ubah</a> |
                        <a href="santri.php?action=delete&id=<?php echo $santri['id_santri']; ?>" onclick="return confirm('Yakin ingin menghapus Santri ini? Semua nilai dan data terkait akan ikut terhapus.')">Hapus</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>
        // Fungsi untuk mengisi form saat tombol 'Ubah' diklik
        function editSantri(id, nis, nama, id_kelas, alamat) {
            document.getElementById('edit_id_santri').value = id;
            document.getElementById('edit_nis').value = nis;
            document.getElementById('edit_nama_santri').value = nama;
            document.getElementById('edit_id_kelas').value = id_kelas;
            document.getElementById('edit_alamat').value = alamat;
            document.querySelector('.form-section h3').textContent = 'Edit Data Santri: ' + nama;
            window.scrollTo(0, 0); 
        }
        </script>
    </div>
</body>
</html>