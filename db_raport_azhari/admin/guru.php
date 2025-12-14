<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";

// --- LOGIC TAMBAH & EDIT GURU ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama = sanitize_input($conn, $_POST['nama_guru']);
    $username = sanitize_input($conn, $_POST['username']);
    $is_wali = isset($_POST['is_wali_kelas']) ? 1 : 0;
    $id_guru = isset($_POST['id_guru']) ? sanitize_input($conn, $_POST['id_guru']) : null;
    $password = $_POST['password'];

    // Validasi input
    if (empty($nama) || empty($username)) {
        $error = "Nama dan Username wajib diisi.";
    } else {
        if ($id_guru) {
            // Proses EDIT
            $sql = "UPDATE tabel_guru SET nama_guru = ?, username = ?, is_wali_kelas = ?";
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql .= ", password = ?";
                $stmt = $conn->prepare($sql . " WHERE id_guru = ?");
                $stmt->bind_param("ssisi", $nama, $username, $is_wali, $hashed_password, $id_guru);
            } else {
                $stmt = $conn->prepare($sql . " WHERE id_guru = ?");
                $stmt->bind_param("ssii", $nama, $username, $is_wali, $id_guru);
            }
            if ($stmt->execute()) {
                $success = "Data Guru berhasil diubah.";
            } else {
                $error = "Gagal mengubah data guru: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // Proses TAMBAH
            if (empty($password)) {
                $error = "Password wajib diisi untuk data guru baru.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO tabel_guru (nama_guru, username, password, is_wali_kelas) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $nama, $username, $hashed_password, $is_wali);
                if ($stmt->execute()) {
                    $success = "Data Guru berhasil ditambahkan.";
                } else {
                    $error = "Gagal menambahkan guru: " . ($conn->errno == 1062 ? "Username sudah ada." : $stmt->error);
                }
                $stmt->close();
            }
        }
    }
}

// --- LOGIC HAPUS GURU ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_hapus = sanitize_input($conn, $_GET['id']);
    // Cek apakah guru tidak terikat sebagai wali kelas atau pengampu mapel
    
    $sql_delete = "DELETE FROM tabel_guru WHERE id_guru = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $id_hapus);
    if ($stmt_delete->execute()) {
        $success = "Data Guru berhasil dihapus.";
    } else {
        $error = "Gagal menghapus guru. Pastikan guru tidak terikat dengan data Kelas atau Mata Pelajaran manapun.";
    }
    $stmt_delete->close();
    header("location: guru.php?status=deleted&msg=" . urlencode($success));
    exit;
}

// --- LOGIC AMBIL DATA GURU ---
$guru_data = [];
$sql_select = "SELECT id_guru, nama_guru, username, is_wali_kelas FROM tabel_guru ORDER BY nama_guru";
$result = $conn->query($sql_select);
while ($row = $result->fetch_assoc()) {
    $guru_data[] = $row;
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Data Guru - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
         <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);">  
                <h2>Kelola Data Guru & Wali Kelas</h2>
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
            <h3>Tambah Guru Baru</h3>
            <form method="POST" action="guru.php">
                <input type="hidden" name="id_guru" id="edit_id_guru">
                <div class="form-group">
                    <label>Nama Guru:</label>
                    <input type="text" name="nama_guru" id="edit_nama_guru" required>
                </div>
                <div class="form-group">
                    <label>Username (Login):</label>
                    <input type="text" name="username" id="edit_username" required>
                </div>
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="password" id="edit_password" placeholder="Isi hanya jika ingin mengganti">
                </div>
                <div class="form-group">
                    <input type="checkbox" name="is_wali_kelas" id="edit_is_wali_kelas">
                    <label for="edit_is_wali_kelas">Sebagai Wali Kelas</label>
                </div>
                <button type="submit">Simpan Guru</button>
            </form>
        </div>
        
        <hr>

        <h3>Daftar Guru Terdaftar</h3>
        <table border="1" class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Guru</th>
                    <th>Username</th>
                    <th>Wali Kelas?</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($guru_data as $guru): ?>
                <tr>
                    <td><?php echo htmlspecialchars($guru['id_guru']); ?></td>
                    <td><?php echo htmlspecialchars($guru['nama_guru']); ?></td>
                    <td><?php echo htmlspecialchars($guru['username']); ?></td>
                    <td><?php echo $guru['is_wali_kelas'] ? 'Ya' : 'Tidak'; ?></td>
                    <td>
                        <a href="#" onclick="editGuru(<?php echo $guru['id_guru']; ?>, '<?php echo $guru['nama_guru']; ?>', '<?php echo $guru['username']; ?>', <?php echo $guru['is_wali_kelas']; ?>)">Ubah</a> |
                        <a href="guru.php?action=delete&id=<?php echo $guru['id_guru']; ?>" onclick="return confirm('Yakin ingin menghapus guru ini? Data terkait akan ikut terhapus atau gagal.')">Hapus</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>
        // Fungsi sederhana untuk mengisi form saat tombol 'Ubah' diklik
        function editGuru(id, nama, username, is_wali) {
            document.getElementById('edit_id_guru').value = id;
            document.getElementById('edit_nama_guru').value = nama;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_password').placeholder = 'Kosongkan jika tidak diubah';
            document.getElementById('edit_is_wali_kelas').checked = is_wali == 1;
            document.querySelector('h3').textContent = 'Edit Data Guru';
            window.scrollTo(0, 0); // Gulir ke atas untuk melihat form
        }
        </script>
    </div>
</body>
</html>