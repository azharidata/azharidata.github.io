<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";

// --- LOGIC TAMBAH/EDIT TAHUN AJARAN ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] != 'set_aktif') {
    $semester = sanitize_input($conn, $_POST['semester']);
    $tahun_ajaran = sanitize_input($conn, $_POST['tahun_ajaran']);
    $id_ta = isset($_POST['id_ta']) ? sanitize_input($conn, $_POST['id_ta']) : null;

    if (empty($semester) || empty($tahun_ajaran)) {
        $error = "Semester dan Tahun Ajaran wajib diisi.";
    } else {
        if ($id_ta) {
            // Proses EDIT
            $sql = "UPDATE tabel_tahun_ajaran SET semester = ?, tahun_ajaran = ? WHERE id_ta = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $semester, $tahun_ajaran, $id_ta);
        } else {
            // Proses TAMBAH
            $sql = "INSERT INTO tabel_tahun_ajaran (semester, tahun_ajaran) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $semester, $tahun_ajaran);
        }
        
        if ($stmt->execute()) {
            $success = $id_ta ? "Tahun Ajaran berhasil diubah." : "Tahun Ajaran berhasil ditambahkan.";
        } else {
            $error = "Gagal menyimpan data Tahun Ajaran: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- LOGIC SET TAHUN AJARAN AKTIF ---
if (isset($_POST['action']) && $_POST['action'] == 'set_aktif' && isset($_POST['id_ta_aktif'])) {
    $id_ta_aktif = sanitize_input($conn, $_POST['id_ta_aktif']);

    // 1. Set semua ke NONAKTIF
    $sql_nonaktif = "UPDATE tabel_tahun_ajaran SET status = 'NONAKTIF'";
    $conn->query($sql_nonaktif);

    // 2. Set TA yang dipilih menjadi AKTIF
    $sql_aktif = "UPDATE tabel_tahun_ajaran SET status = 'AKTIF' WHERE id_ta = ?";
    $stmt_aktif = $conn->prepare($sql_aktif);
    $stmt_aktif->bind_param("i", $id_ta_aktif);
    
    if ($stmt_aktif->execute()) {
        $success = "Tahun Ajaran berhasil diaktifkan.";
    } else {
        $error = "Gagal mengaktifkan Tahun Ajaran: " . $stmt_aktif->error;
    }
    $stmt_aktif->close();
}

// --- LOGIC AMBIL DATA TAHUN AJARAN ---
$ta_data = [];
$sql_select = "SELECT id_ta, semester, tahun_ajaran, status FROM tabel_tahun_ajaran ORDER BY tahun_ajaran DESC, semester DESC";
$result = $conn->query($sql_select);
while ($row = $result->fetch_assoc()) {
    $ta_data[] = $row;
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Tahun Ajaran - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Kelola Data tahun ajaran dan semester</h2>
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
            <h3>Tambah Tahun Ajaran Baru</h3>
            <form method="POST" action="tahun_ajaran.php">
                <input type="hidden" name="id_ta" id="edit_id_ta">
                <div class="form-group">
                    <label>Semester:</label>
                    <select name="semester" id="edit_semester" required>
                        <option value="">-- Pilih Semester --</option>
                        <option value="GANJIL">GANJIL / الأول</option>
                        <option value="GENAP">GENAP / الثاني</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tahun Ajaran (Contoh: 2025-2026 M/1447 H):</label>
                    <input type="text" name="tahun_ajaran" id="edit_tahun_ajaran" required>
                </div>
                <input type="hidden" name="action" value="save">
                <button type="submit">Simpan Tahun Ajaran</button>
            </form>
        </div>
        
        <hr>

        <h3>Daftar Tahun Ajaran</h3>
        <form method="POST" action="tahun_ajaran.php">
            <input type="hidden" name="action" value="set_aktif">
            <table border="1" class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tahun Ajaran</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Aksi</th>
                        <th>Set Aktif</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ta_data as $ta): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ta['id_ta']); ?></td>
                        <td><?php echo htmlspecialchars($ta['tahun_ajaran']); ?></td>
                        <td><?php echo htmlspecialchars($ta['semester']); ?></td>
                        <td>
                            <span style="color: <?php echo $ta['status'] == 'AKTIF' ? 'green' : 'red'; ?>;">
                                <?php echo htmlspecialchars($ta['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="#" onclick="editTA(<?php echo $ta['id_ta']; ?>, '<?php echo $ta['semester']; ?>', '<?php echo $ta['tahun_ajaran']; ?>')">Ubah</a> 
                        </td>
                        <td>
                            <input type="radio" name="id_ta_aktif" value="<?php echo $ta['id_ta']; ?>" 
                                <?php echo $ta['status'] == 'AKTIF' ? 'checked disabled' : ''; ?>>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <br>
            <button type="submit" onclick="return confirm('Mengaktifkan TA baru akan menonaktifkan TA yang lama. Lanjutkan?')" class="btn-primary">Aktifkan Tahun Ajaran Terpilih</button>
        </form>
        
        <script>
        function editTA(id, semester, tahun_ajaran) {
            document.getElementById('edit_id_ta').value = id;
            document.getElementById('edit_semester').value = semester;
            document.getElementById('edit_tahun_ajaran').value = tahun_ajaran;
            document.querySelector('.form-section h3').textContent = 'Edit Tahun Ajaran';
            window.scrollTo(0, 0); 
        }
        </script>
    </div>
</body>
</html>