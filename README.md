# azharidata.github.io
<?php
// Mulai session
session_start();

// Include file konfigurasi
require_once 'includes/config.php';

$username = $password = $error = "";

// Cek jika form disubmit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Ambil dan bersihkan input
    $username = sanitize_input($conn, $_POST['username']);
    $password = sanitize_input($conn, $_POST['password']);

    // Query untuk mencari user di tabel_guru
    $sql = "SELECT id_guru, username, password FROM tabel_guru WHERE username = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $param_username);
        $param_username = $username;

        if ($stmt->execute()) {
            $stmt->store_result();
            
            if ($stmt->num_rows == 1) {
                // User ditemukan, bind hasil
                $stmt->bind_result($id, $user, $hashed_password);
                if ($stmt->fetch()) {
                    
                    // Verifikasi password (gunakan password_verify jika password di-hash)
                    // Untuk contoh ini, kita asumsikan password di-hash.
                    // Jika Anda belum menggunakan hash, ganti 'password_verify' dengan perbandingan string biasa.
                    if (password_verify($password, $hashed_password)) {
                        
                        // Login berhasil, buat session
                        $_SESSION["loggedin"] = true;
                        $_SESSION["id_user"] = $id;
                        $_SESSION["username"] = $user;
                        
                        // Cek apakah user adalah 'admin' atau 'guru'
                        // Untuk contoh sederhana, kita asumsikan admin memiliki username 'admin'
                        if ($username === 'admin') {
                            $_SESSION["role"] = "admin";
                            header("location: admin/index.php"); // Arahkan ke Dashboard Admin
                        } else {
                            $_SESSION["role"] = "guru";
                            header("location: guru/index.php");  // Arahkan ke Dashboard Guru
                        }
                        exit;
                    } else {
                        // Password salah
                        $error = "Username atau Password salah.";
                    }
                }
            } else {
                // User tidak ditemukan
                $error = "Username atau Password salah.";
            }
        } else {
            $error = "Terjadi kesalahan pada database.";
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login Raport Azhari</title>
    <link rel="stylesheet" href="/db_raport_azhari/assets/css/style.css"> 
</head>

<body class="login-body"> 
    
    <div class="login-container"> 
        
       <div class="logo-section">
            <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
            
            <h2>Raport AZHARI IBS AL MUTTAQIN</h2>
        </div>
        
        <?php if (!empty($login_err)): ?>
            <div class="error-message"><?php echo $login_err; ?></div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <button type="submit">Login</button>
            </div>
        </form>

    </div> 
</body>
</html>
