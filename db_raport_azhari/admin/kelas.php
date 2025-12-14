<?php
session_start();
// Autentikasi Admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";

// --- LOGIC TAMBAH/EDIT KELAS ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama_kelas = sanitize_input($conn, $_POST['nama_kelas']);
    $id_wali_kelas = sanitize_input($conn, $_POST['id_wali_kelas']);
    $id_kelas = isset($_POST['id_kelas']) ? sanitize_input($conn, $_POST['id_kelas']) : null;

    if (empty($nama_kelas) || empty($id_wali_kelas)) {
        $error = "Nama Kelas dan Wali Kelas wajib diisi.";
    } else {
        if ($id_kelas) {
            // Proses EDIT
            $sql = "UPDATE tabel_kelas SET nama_kelas = ?, id_wali_kelas = ? WHERE id_kelas = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sii", $nama_kelas, $id_wali_kelas, $id_kelas);
        } else {
            // Proses TAMBAH
            $sql = "INSERT INTO tabel_kelas (nama_kelas, id_wali_kelas) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $nama_kelas, $id_wali_kelas);
        }
        
        if ($stmt->execute()) {
            $success = $id_kelas ? "Data Kelas berhasil diubah." : "Data Kelas berhasil ditambahkan.";
        } else {
            $error = "Gagal menyimpan data Kelas: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- LOGIC HAPUS KELAS ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_hapus = sanitize_input($conn, $_GET['id']);
    
    // 1. Cek apakah ada Santri yang terikat dengan Kelas ini
    $sql_check = "SELECT COUNT(*) AS count FROM tabel_santri WHERE id_kelas = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $id_hapus);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if ($result_check['count'] > 0) {
        // Jika ada Santri terikat, batalkan dan buat error
        $msg = "Gagal menghapus Kelas. Terdapat {$result_check['count']} Santri yang masih terikat. Harap pindahkan atau hapus Santri tersebut terlebih dahulu.";
        $status_type = 'error';
    } else {
        // 2. Jika tidak ada Santri, lakukan penghapusan
        $sql_delete = "DELETE FROM tabel_kelas WHERE id_kelas = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $id_hapus);
        
        if ($stmt_delete->execute()) {
            $msg = "Data Kelas berhasil dihapus.";
            $status_type = 'success';
        } else {
            $msg = "Gagal menghapus Kelas. Error database: " . $stmt_delete->error;
            $status_type = 'error';
        }
        $stmt_delete->close();
    }
    
    // Redirect untuk menampilkan pesan
    header("location: kelas.php?status={$status_type}&msg=" . urlencode($msg));
    exit;
}

// --- LOGIC AMBIL DATA MASTER (Guru/Wali Kelas dan Kelas) ---
$guru_options = [];
// Ambil Guru yang bertindak sebagai Wali Kelas
$sql_guru = "SELECT id_guru, nama_guru FROM tabel_guru WHERE is_wali_kelas = 1 ORDER BY nama_guru"; 
$result_guru = $conn->query($sql_guru);
while ($row = $result_guru->fetch_assoc()) {
    $guru_options[] = $row;
}

$kelas_data = [];
$sql_kelas = "
    SELECT tk.id_kelas, tk.nama_kelas, tk.id_wali_kelas, tg.nama_guru 
    FROM tabel_kelas tk
    LEFT JOIN tabel_guru tg ON tk.id_wali_kelas = tg.id_guru
    ORDER BY tk.nama_kelas
";
$result_kelas = $conn->query($sql_kelas);
while ($row = $result_kelas->fetch_assoc()) {
    $kelas_data[] = $row;
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Data Kelas - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
         <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Kelola Data Kelas</h2>        
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
            <h3>Tambah Kelas Baru</h3>
            <form method="POST" action="kelas.php">
                <input type="hidden" name="id_kelas" id="edit_id_kelas">
                <div class="form-group">
                    <label>Nama Kelas (Contoh: VII A, VIII B):</label>
                    <input type="text" name="nama_kelas" id="edit_nama_kelas" required>
                </div>
                <div class="form-group">
                    <label>Wali Kelas:</label>
                    <select name="id_wali_kelas" id="edit_id_wali_kelas" required>
                        <option value="">-- Pilih Wali Kelas --</option>
                        <?php foreach ($guru_options as $guru): ?>
                            <option value="<?php echo $guru['id_guru']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Simpan Kelas</button>
            </form>
        </div>
        
        <hr>

        <h3>Daftar Kelas Terdaftar</h3>
        <table border="1" class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Kelas</th>
                    <th>Wali Kelas</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kelas_data as $kelas): ?>
                <tr>
                    <td><?php echo htmlspecialchars($kelas['id_kelas']); ?></td>
                    <td><?php echo htmlspecialchars($kelas['nama_kelas']); ?></td>
                    <td><?php echo htmlspecialchars($kelas['nama_guru'] ?? '- Belum Ditentukan -'); ?></td>
                    <td>
                        <a href="#" onclick="editKelas(<?php echo $kelas['id_kelas']; ?>, '<?php echo $kelas['nama_kelas']; ?>', <?php echo $kelas['id_wali_kelas'] ?? '0'; ?>)">Ubah</a> |
                        <a href="kelas.php?action=delete&id=<?php echo $kelas['id_kelas']; ?>" onclick="return confirm('Yakin ingin menghapus Kelas ini? Semua santri di kelas ini harus dipindahkan terlebih dahulu.')">Hapus</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>
        // Fungsi untuk mengisi form saat tombol 'Ubah' diklik
        function editKelas(id, nama, id_wali) {
            document.getElementById('edit_id_kelas').value = id;
            document.getElementById('edit_nama_kelas').value = nama;
            document.getElementById('edit_id_wali_kelas').value = id_wali;
            document.querySelector('.form-section h3').textContent = 'Edit Data Kelas';
            window.scrollTo(0, 0);
        }
        </script>
    </div>
</body>
</html>