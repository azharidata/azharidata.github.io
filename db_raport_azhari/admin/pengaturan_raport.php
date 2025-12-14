<?php
session_start();
// Autentikasi Admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php'; // Pastikan path benar

$error = $success = "";

// Cek apakah fungsi sanitize_input tersedia
if (!function_exists('sanitize_input')) {
    // Fallback definition (hanya jika config.php tidak mendefinisikannya)
    function sanitize_input($conn, $data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $conn->real_escape_string($data);
    }
}


// Kunci pengaturan BARU yang akan dikelola di tabel_pengaturan_raport
$keys_to_manage = [
    'nama_mudir', 
    
    // PENGATURAN TTD/STEMPEL MUDIR
    'ttd_mudir_path', 
    'ttd_height_mudir',     // Tinggi TTD Mudir (e.g., 50px)
    'stempel_mudir_path',   // Path Stempel Mudir
    'stempel_width_mudir',  // Lebar Stempel Mudir (e.g., 80px)
    
    // PENGATURAN UKURAN TTD WALI KELAS DEFAULT
    'ttd_height_wali',      // Tinggi TTD Wali Kelas Default
    'ttd_width_wali',       // Lebar TTD Wali Kelas Default
    
    // PENGATURAN LOGO
    'logo_path',        
    'logo_width_pondok', 
    'logo_path_azhari', 
    'logo_width_azhari', 
    
    // KATA MUTIARA (Semua rentang)
    'kata_mutiara_arab', 'kata_mutiara_indo', 
    'mutiara_arab_50_59', 'mutiara_indo_50_59', 
    'mutiara_arab_60_69', 'mutiara_indo_60_69', 
    'mutiara_arab_70_79', 'mutiara_indo_70_79', 
    'mutiara_arab_80_89', 'mutiara_indo_80_89', 
    'mutiara_arab_90_100', 'mutiara_indo_90_100', 
];

// FUNGSI UNTUK VALIDASI UKURAN (Contoh: 50px, 80%)
function validate_dimension($value, $type = 'px') {
    $value = trim($value);
    
    if ($type === 'px') {
        // Harus berupa angka diikuti 'px'
        if (preg_match('/^(\d+)(px)$/i', $value, $matches)) {
            return strtolower($matches[1] . $matches[2]);
        }
        return '50px'; // Default fallback
    } elseif ($type === '%') {
        // Harus berupa angka diikuti '%'
        if (preg_match('/^(\d{1,3})%$/', $value, $matches) && $matches[1] <= 100) {
            return $matches[1] . '%';
        }
        return '100%'; // Default fallback
    }
    return $value;
}


// 1. Handle POST Request (Update Settings)
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $conn->begin_transaction();
    try {
        $stmt_update = $conn->prepare("INSERT INTO tabel_pengaturan_raport (key_setting, value_setting) VALUES (?, ?) 
                                     ON DUPLICATE KEY UPDATE value_setting = VALUES(value_setting)");
        $all_success = true;
        
        // Kunci yang wajib diisi
        $required_keys = ['nama_mudir', 'logo_path', 'logo_path_azhari', 'ttd_mudir_path']; 

        foreach ($keys_to_manage as $key) {
            
            $value = sanitize_input($conn, $_POST[$key] ?? '');
            
            // Logika validasi khusus dimensi (px atau %)
            if ($key === 'logo_width_pondok' || $key === 'logo_width_azhari') {
                $value = validate_dimension($value, '%');
            } elseif (in_array($key, ['ttd_height_mudir', 'stempel_width_mudir', 'ttd_height_wali', 'ttd_width_wali'])) {
                $value = validate_dimension($value, 'px');
            }


            // Validasi wajib isi
            if (in_array($key, $required_keys) && empty(trim($value))) {
                 $error = "Field Nama Mudir, Path Logo Pondok, Path Logo Azhari, dan Path TTD Mudir harus diisi.";
                 $all_success = false;
                 break;
            }
            
            // Bind dan eksekusi
            $stmt_update->bind_param("ss", $key, $value);
            if (!$stmt_update->execute()) {
                throw new Exception("Gagal menyimpan key '{$key}': " . $stmt_update->error);
            }
        } // Penutup foreach

        if ($all_success) {
            $conn->commit();
            $success = "Pengaturan Raport berhasil diperbarui!";
            header("Location: pengaturan_raport.php?success=1");
            exit;
        } else {
            $conn->rollback();
        }
        
        if (isset($stmt_update) && $stmt_update !== false) {
            $stmt_update->close();
        }

    } catch (Exception $e) {
        if (isset($stmt_update) && $stmt_update !== false) {
             $stmt_update->close();
        }
        $conn->rollback();
        $error = "Terjadi kesalahan: " . $e->getMessage();
    } 
} 

// Cek jika ada success dari redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Pengaturan Raport berhasil diperbarui!";
}

// 2. Fetch Current Settings (untuk mengisi formulir)
$query_pengaturan = "SELECT key_setting, value_setting FROM tabel_pengaturan_raport";
$result_pengaturan = $conn->query($query_pengaturan);

$current_settings = [];
while ($row = $result_pengaturan->fetch_assoc()) {
    $current_settings[$row['key_setting']] = $row['value_setting'];
}

// Pastikan semua kunci di-inisialisasi
foreach ($keys_to_manage as $key) {
    if (!isset($current_settings[$key])) {
        // Default untuk dimensi jika kosong
        if ($key === 'logo_width_pondok' || $key === 'logo_width_azhari') {
            $current_settings[$key] = '100%';
        } elseif ($key === 'ttd_height_mudir') {
            $current_settings[$key] = '50px';
        } elseif ($key === 'stempel_width_mudir') {
            $current_settings[$key] = '80px';
        } elseif ($key === 'ttd_height_wali') {
            $current_settings[$key] = '40px';
        } elseif ($key === 'ttd_width_wali') {
            $current_settings[$key] = '100px';
        } else {
            $current_settings[$key] = '';
        }
    }
}

// Tutup koneksi
if (isset($conn)) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pengaturan Raport - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css"> 
    <style>
        /* CSS Tambahan untuk Form */
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 8px; margin-bottom: 15px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .form-group textarea { min-height: 80px; resize: vertical; font-family: Arial, sans-serif; }
        .form-section { padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; }
        .logo-group { display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap; }
        .logo-field { flex: 1 1 45%; }
        .logo-field input[type="text"] { margin-bottom: 5px; }
        .logo-field .input-group { display: flex; }
        .logo-field .input-group input { flex: 1; margin-bottom: 0; }
        .logo-field .input-group span { padding: 8px; border: 1px solid #ccc; border-left: none; background-color: #eee; border-radius: 0 4px 4px 0; line-height: 1.5; }
        .signature-field { border-top: 1px solid #ccc; padding-top: 15px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-section">
                <h2>Pengaturan Cetak Raport</h2>
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
            <p>Atur data dinamis seperti Nama Mudir, Tanda Tangan, Stempel, dan Ukuran elemen cetak.</p>
            <form method="POST" action="pengaturan_raport.php">
                
                <h3>Data Pimpinan & Tanda Tangan</h3>
                <div class="form-group">
                    <label for="nama_mudir">Nama Kepala/Pimpinan Pesantren (Mudir)</label>
                    <input type="text" name="nama_mudir" id="nama_mudir" 
                            value="<?php echo htmlspecialchars($current_settings['nama_mudir'] ?? ''); ?>" required>
                </div>
                
                <div class="signature-field">
                    <h4>Path & Ukuran Tanda Tangan Otomatis (TTD/Stempel harus .PNG)</h4>

                    <fieldset style="border: 1px solid #007bff; padding: 10px; margin-bottom: 20px;">
                        <legend style="font-weight: bold; padding: 0 5px; color: #007bff;">Mudir/Kepala Sekolah</legend>
                        <div class="form-group">
                            <label for="ttd_mudir_path">Path Lokasi TTD Mudir</label>
                            <input type="text" name="ttd_mudir_path" id="ttd_mudir_path" 
                                    value="<?php echo htmlspecialchars($current_settings['ttd_mudir_path'] ?? ''); ?>" required>
                            <small>Contoh: /db_raport_azhari/assets/ttd/ttd_mudir.png</small>
                        </div>
                        <div class="form-group">
                            <label for="stempel_mudir_path">Path Lokasi Stempel Mudir (Optional)</label>
                            <input type="text" name="stempel_mudir_path" id="stempel_mudir_path" 
                                    value="<?php echo htmlspecialchars($current_settings['stempel_mudir_path'] ?? ''); ?>">
                            <small>Contoh: /db_raport_azhari/assets/ttd/stempel_mudir.png</small>
                        </div>
                        
                        <div class="logo-group">
                            <div class="logo-field">
                                <label for="ttd_height_mudir">Tinggi TTD Mudir (px)</label>
                                <input type="text" name="ttd_height_mudir" id="ttd_height_mudir" 
                                        value="<?php echo htmlspecialchars($current_settings['ttd_height_mudir'] ?? '50px'); ?>" placeholder="e.g., 50px">
                            </div>
                            <div class="logo-field">
                                <label for="stempel_width_mudir">Lebar Stempel Mudir (px)</label>
                                <input type="text" name="stempel_width_mudir" id="stempel_width_mudir" 
                                        value="<?php echo htmlspecialchars($current_settings['stempel_width_mudir'] ?? '80px'); ?>" placeholder="e.g., 80px">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset style="border: 1px solid #28a745; padding: 10px;">
                        <legend style="font-weight: bold; padding: 0 5px; color: #28a745;">Wali Kelas (Pengaturan Ukuran Default)</legend>
                        <small style="display: block; margin-bottom: 10px; font-weight: bold;">
                            PERHATIAN: Path TTD Wali Kelas harus diatur di **tabel `tabel_guru`** untuk setiap guru yang menjabat sebagai Wali Kelas.
                        </small>
                        
                        <div class="logo-group">
                            <div class="logo-field">
                                <label for="ttd_height_wali">Tinggi TTD Wali Kelas (px)</label>
                                <input type="text" name="ttd_height_wali" id="ttd_height_wali" 
                                        value="<?php echo htmlspecialchars($current_settings['ttd_height_wali'] ?? '40px'); ?>" placeholder="e.g., 40px">
                            </div>
                            <div class="logo-field">
                                <label for="ttd_width_wali">Lebar TTD Wali Kelas (px)</label>
                                <input type="text" name="ttd_width_wali" id="ttd_width_wali" 
                                        value="<?php echo htmlspecialchars($current_settings['ttd_width_wali'] ?? '100px'); ?>" placeholder="e.g., 100px">
                            </div>
                        </div>
                    </fieldset>
                </div>
                
                
                <h3>Lokasi & Ukuran Logo</h3>
                <div class="logo-group">
                    
                    <div class="logo-field">
                        <label for="logo_path">Path Lokasi Logo Pondok (Kiri)</label>
                        <input type="text" name="logo_path" id="logo_path" 
                            value="<?php echo htmlspecialchars($current_settings['logo_path'] ?? ''); ?>" required>
                        <small>Contoh: /db_raport_azhari/assets/img/logo.png</small>

                        <div class="form-group">
                            <label for="logo_width_pondok">Lebar Logo Pondok (%)</label>
                            <div class="input-group">
                                <input type="text" name="logo_width_pondok" id="logo_width_pondok" 
                                        value="<?php echo htmlspecialchars($current_settings['logo_width_pondok'] ?? '100%'); ?>" 
                                        pattern="[0-9]{1,3}%" title="Masukkan angka 1-100 diikuti tanda persen (e.g., 60%)" required>
                                <span>%</span>
                            </div>
                        </div>
                    </div>

                    <div class="logo-field">
                        <label for="logo_path_azhari">Path Lokasi Logo Azhari (Kanan)</label>
                        <input type="text" name="logo_path_azhari" id="logo_path_azhari" 
                            value="<?php echo htmlspecialchars($current_settings['logo_path_azhari'] ?? ''); ?>" required>
                        <small>Contoh: /db_raport_azhari/assets/img/logo_azhari.png</small>
                        
                        <div class="form-group">
                            <label for="logo_width_azhari">Lebar Logo Azhari (%)</label>
                            <div class="input-group">
                                <input type="text" name="logo_width_azhari" id="logo_width_azhari" 
                                        value="<?php echo htmlspecialchars($current_settings['logo_width_azhari'] ?? '100%'); ?>"
                                        pattern="[0-9]{1,3}%" title="Masukkan angka 1-100 diikuti tanda persen (e.g., 60%)" required>
                                <span>%</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                
                <h3>Kata Mutiara (Dinamis Berdasarkan Rata-rata Nilai Global)</h3>
                
                <?php 
                $ranges = [
                    '< 50 (Rendah/Gagal/Default)' => 'kata_mutiara', 
                    '50 - 59' => 'mutiara_50_59',
                    '60 - 69' => 'mutiara_60_69',
                    '70 - 79' => 'mutiara_70_79',
                    '80 - 89' => 'mutiara_80_89',
                    '90 - 100 (Tertinggi/Istimewa)' => 'mutiara_90_100',
                ];

                foreach ($ranges as $label => $base_key):
                    $arab_key = ($base_key == 'kata_mutiara') ? 'kata_mutiara_arab' : $base_key . '_arab';
                    $indo_key = ($base_key == 'kata_mutiara') ? 'kata_mutiara_indo' : $base_key . '_indo';
                ?>
                    <fieldset style="border: 1px solid #ccc; padding: 10px; margin-bottom: 20px;">
                        <legend style="font-weight: bold; padding: 0 5px;">Rata-rata Nilai: <?php echo $label; ?></legend>
                        <div class="form-group">
                            <label for="<?php echo $arab_key; ?>">Teks Arab</label>
                            <textarea name="<?php echo $arab_key; ?>" id="<?php echo $arab_key; ?>" style="direction: rtl; text-align: right; font-family: 'Traditional Arabic', 'Arial', serif;" required><?php echo htmlspecialchars($current_settings[$arab_key] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="<?php echo $indo_key; ?>">Terjemahan Indonesia</label>
                            <textarea name="<?php echo $indo_key; ?>" id="<?php echo $indo_key; ?>" required><?php echo htmlspecialchars($current_settings[$indo_key] ?? ''); ?></textarea>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <button type="submit">Simpan Pengaturan Raport</button>
            </form>
        </div>
    </div>
</body>
</html>