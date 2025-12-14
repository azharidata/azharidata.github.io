<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';

$error = $success = "";

// Fungsi untuk menghapus relasi mapel-kelas lama
function delete_mapel_kelas_relations($conn, $id_mapel) {
    $sql_del = "DELETE FROM tabel_mapel_kelas WHERE id_mapel = ?";
    $stmt_del = $conn->prepare($sql_del);
    $stmt_del->bind_param("i", $id_mapel);
    $stmt_del->execute();
    $stmt_del->close();
}

// Fungsi untuk menyimpan relasi mapel-kelas baru
function insert_mapel_kelas_relations($conn, $id_mapel, $selected_kelas) {
    if (!empty($selected_kelas) && is_array($selected_kelas)) {
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
            $stmt_insert->execute();
            $stmt_insert->close();
        }
    }
}


// --- LOGIC TAMBAH/EDIT MAPEL ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama_mapel = sanitize_input($conn, $_POST['nama_mapel']);
    $kategori = sanitize_input($conn, $_POST['kategori']);
    $kkm = sanitize_input($conn, $_POST['kkm']);
    $id_guru_pengampu = sanitize_input($conn, $_POST['id_guru_pengampu']);
    $selected_kelas = $_POST['id_kelas'] ?? []; // Array ID Kelas yang dipilih
    $id_mapel = isset($_POST['id_mapel']) ? sanitize_input($conn, $_POST['id_mapel']) : null;

    if (empty($nama_mapel) || empty($kategori) || empty($kkm) || empty($selected_kelas)) {
        $error = "Semua kolom utama dan minimal satu Kelas wajib diisi.";
    } else {
        $conn->begin_transaction(); // Mulai transaksi
        $is_success = false;
        $new_mapel_id = $id_mapel;

        try {
            if ($id_mapel) {
                // Proses EDIT: Update tabel_mapel
                $sql = "UPDATE tabel_mapel SET nama_mapel = ?, kategori = ?, kkm = ?, id_guru_pengampu = ? WHERE id_mapel = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssiii", $nama_mapel, $kategori, $kkm, $id_guru_pengampu, $id_mapel);
                $stmt->execute();
                $stmt->close();
                
                // Hapus relasi lama dan masukkan yang baru
                delete_mapel_kelas_relations($conn, $id_mapel);
                insert_mapel_kelas_relations($conn, $id_mapel, $selected_kelas);
                
                $success = "Mata Pelajaran dan relasi Kelas berhasil diubah.";
                $is_success = true;

            } else {
                // Proses TAMBAH: Insert tabel_mapel
                $sql = "INSERT INTO tabel_mapel (nama_mapel, kategori, kkm, id_guru_pengampu) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssii", $nama_mapel, $kategori, $kkm, $id_guru_pengampu);
                $stmt->execute();
                $new_mapel_id = $conn->insert_id;
                $stmt->close();

                // Tambahkan relasi ke tabel_mapel_kelas
                insert_mapel_kelas_relations($conn, $new_mapel_id, $selected_kelas);

                $success = "Mata Pelajaran dan relasi Kelas berhasil ditambahkan.";
                $is_success = true;
            }

            if ($is_success) {
                $conn->commit(); // Commit jika semua berhasil
            } else {
                $conn->rollback(); // Rollback jika ada kegagalan
                $error = "Gagal menyimpan data Mapel: Kesalahan tidak teridentifikasi.";
            }

        } catch (Exception $e) {
            $conn->rollback(); // Rollback jika terjadi exception
            $error = "Gagal menyimpan data Mapel: " . $e->getMessage();
        }
    }
}

// --- LOGIC HAPUS MAPEL ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id_hapus = sanitize_input($conn, $_GET['id']);
    $conn->begin_transaction();
    try {
        // 1. Hapus relasi di tabel_mapel_kelas
        delete_mapel_kelas_relations($conn, $id_hapus);

        // 2. Hapus Mapel di tabel_mapel
        $sql_delete = "DELETE FROM tabel_mapel WHERE id_mapel = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $id_hapus);
        
        if ($stmt_delete->execute()) {
            $conn->commit();
            $success = "Data Mata Pelajaran dan relasi Kelas berhasil dihapus.";
        } else {
            $conn->rollback();
            $error = "Gagal menghapus Mapel. Pastikan tidak ada nilai santri yang terikat dengan Mapel ini.";
        }
        $stmt_delete->close();

    } catch (Exception $e) {
        $conn->rollback();
        $error = "Gagal menghapus: " . $e->getMessage();
    }
    header("location: mapel.php?status=deleted&msg=" . urlencode($success ?? $error));
    exit;
}

// --- LOGIC AMBIL DATA MASTER (Guru, Kelas, dan Mapel) ---

// 1. Ambil data semua Guru
$guru_options = [];
$sql_guru = "SELECT id_guru, nama_guru FROM tabel_guru ORDER BY nama_guru";
$result_guru = $conn->query($sql_guru);
while ($row = $result_guru->fetch_assoc()) {
    $guru_options[] = $row;
}

// 2. Ambil data semua Kelas
$kelas_options = [];
$sql_kelas = "SELECT id_kelas, nama_kelas FROM tabel_kelas ORDER BY nama_kelas";
$result_kelas = $conn->query($sql_kelas);
while ($row = $result_kelas->fetch_assoc()) {
    $kelas_options[] = $row;
}

// 3. Ambil data Mapel beserta Guru Pengampu
$mapel_data = [];
$sql_mapel = "
    SELECT 
        tm.id_mapel, tm.nama_mapel, tm.kategori, tm.kkm, tm.id_guru_pengampu, tg.nama_guru 
    FROM tabel_mapel tm
    LEFT JOIN tabel_guru tg ON tm.id_guru_pengampu = tg.id_guru
    ORDER BY tm.kategori, tm.nama_mapel
";
$result_mapel = $conn->query($sql_mapel);

// 4. Ambil data relasi Kelas untuk setiap Mapel
while ($mapel = $result_mapel->fetch_assoc()) {
    $sql_relasi = "
        SELECT tk.id_kelas, tk.nama_kelas 
        FROM tabel_mapel_kelas tmk
        JOIN tabel_kelas tk ON tmk.id_kelas = tk.id_kelas
        WHERE tmk.id_mapel = ?
    ";
    $stmt_relasi = $conn->prepare($sql_relasi);
    $stmt_relasi->bind_param("i", $mapel['id_mapel']);
    $stmt_relasi->execute();
    $result_relasi = $stmt_relasi->get_result();
    
    $mapel['kelas_terpilih'] = [];
    $mapel['nama_kelas_terpilih'] = [];

    while ($relasi = $result_relasi->fetch_assoc()) {
        $mapel['kelas_terpilih'][] = (int)$relasi['id_kelas'];
        $mapel['nama_kelas_terpilih'][] = htmlspecialchars($relasi['nama_kelas']);
    }
    $stmt_relasi->close();

    $mapel_data[] = $mapel;
}
$conn->close();

// Tampilkan status dari redirect (setelah hapus)
if (isset($_GET['status']) && isset($_GET['msg'])) {
    if ($_GET['status'] == 'deleted') {
        $success = urldecode($_GET['msg']);
    } else {
        $error = urldecode($_GET['msg']);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kelola Mata Pelajaran - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Gaya tambahan untuk form kelas */
        .checkbox-group {
            border: 1px solid #ccc;
            padding: 10px;
            max-height: 150px;
            overflow-y: auto;
            background-color: #f9f9f9;
        }
        .checkbox-group label {
            display: block;
            margin-bottom: 5px;
            cursor: pointer;
            font-weight: normal;
        }
        .data-table td:nth-child(6) {
            max-width: 200px;
            white-space: normal;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
               <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Kelola Data Mata Pelajaran</h2>
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
            <h3>Tambah Mata Pelajaran Baru</h3>
            <form method="POST" action="mapel.php">
                <input type="hidden" name="id_mapel" id="edit_id_mapel">
                
                <div class="form-group">
                    <label>Nama Mata Pelajaran:</label>
                    <input type="text" name="nama_mapel" id="edit_nama_mapel" required>
                </div>
                
                <div class="form-group">
                    <label>Kategori (Sesuai Raport):</label>
                    <select name="kategori" id="edit_kategori" required>
                        <option value="">-- Pilih Kategori --</option>
                        <option value="AGAMA">المود الدينية / AGAMA</option>
                        <option value="BAHASA ARAB">المود اللغة العربية / BAHASA ARAB</option>
                        <option value="TAMBAHAN">المواد الثقافية / MAPEL TAMBAHAN</option>
                        <option value="WAJIB">Mapel wajib</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>KKM:</label>
                    <input type="number" name="kkm" id="edit_kkm" required min="0" max="100">
                </div>
                
                <div class="form-group">
                    <label>Guru Pengampu:</label>
                    <select name="id_guru_pengampu" id="edit_id_guru_pengampu" required>
                        <option value="">-- Pilih Guru --</option>
                        <?php foreach ($guru_options as $guru): ?>
                            <option value="<?php echo $guru['id_guru']; ?>"><?php echo htmlspecialchars($guru['nama_guru']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Kelas yang Menggunakan Mapel ini (Wajib pilih min. 1):</label>
                    <div class="checkbox-group" id="kelas_checkbox_group">
                        <?php foreach ($kelas_options as $kelas): ?>
                            <label>
                                <input type="checkbox" name="id_kelas[]" value="<?php echo $kelas['id_kelas']; ?>" class="kelas-checkbox" data-id="<?php echo $kelas['id_kelas']; ?>">
                                <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit">Simpan Mata Pelajaran</button>
            </form>
        </div>
        
        <hr>

        <h3>Daftar Mata Pelajaran</h3>
        <table border="1" class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Mapel</th>
                    <th>Kategori</th>
                    <th>KKM</th>
                    <th>Guru Pengampu</th>
                    <th style="width: 20%;">Kelas yang Terpilih</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mapel_data as $mapel): ?>
                <tr>
                    <td><?php echo htmlspecialchars($mapel['id_mapel']); ?></td>
                    <td><?php echo htmlspecialchars($mapel['nama_mapel']); ?></td>
                    <td><?php echo htmlspecialchars($mapel['kategori']); ?></td>
                    <td><?php echo htmlspecialchars($mapel['kkm']); ?></td>
                    <td><?php echo htmlspecialchars($mapel['nama_guru'] ?? '- Tidak Ada -'); ?></td>
                    <td><?php echo empty($mapel['nama_kelas_terpilih']) ? '—' : implode(', ', $mapel['nama_kelas_terpilih']); ?></td>
                    <td>
                        <?php 
                        // Encode array kelas terpilih ke JSON untuk digunakan di JavaScript
                        $kelas_terpilih_json = json_encode($mapel['kelas_terpilih']);
                        ?>
                        <a href="#" onclick='editMapel(
                            <?php echo $mapel['id_mapel']; ?>, 
                            "<?php echo htmlspecialchars($mapel['nama_mapel']); ?>", 
                            "<?php echo htmlspecialchars($mapel['kategori']); ?>", 
                            <?php echo $mapel['kkm']; ?>, 
                            <?php echo $mapel['id_guru_pengampu']; ?>,
                            <?php echo $kelas_terpilih_json; ?>
                        )'>Ubah</a> |
                        <a href="mapel.php?action=delete&id=<?php echo $mapel['id_mapel']; ?>" onclick="return confirm('Yakin ingin menghapus Mapel ini? Ini juga akan menghapus relasi kelasnya.')">Hapus</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>
        function editMapel(id, nama, kategori, kkm, id_guru, kelas_terpilih) {
            // 1. Reset Form Utama
            document.querySelector('.form-section h3').textContent = 'Edit Mata Pelajaran';
            document.getElementById('edit_id_mapel').value = id;
            document.getElementById('edit_nama_mapel').value = nama;
            document.getElementById('edit_kategori').value = kategori;
            document.getElementById('edit_kkm').value = kkm;
            document.getElementById('edit_id_guru_pengampu').value = id_guru;
            
            // 2. Reset semua checkbox Kelas
            document.querySelectorAll('.kelas-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });

            // 3. Set checkbox Kelas yang terpilih
            if (kelas_terpilih && Array.isArray(kelas_terpilih)) {
                kelas_terpilih.forEach(kelas_id => {
                    const checkbox = document.querySelector(`.kelas-checkbox[value="${kelas_id}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }

            // Scroll ke atas
            window.scrollTo(0, 0); 
        }
        
        // Cek pesan status pada saat load
        window.onload = function() {
            const params = new URLSearchParams(window.location.search);
            if (params.has('status') || params.has('msg')) {
                // Hapus parameter dari URL setelah pesan ditampilkan (opsional, untuk tampilan bersih)
                history.replaceState(null, null, window.location.pathname);
            }
        }
        </script>
    </div>
</body>
</html>