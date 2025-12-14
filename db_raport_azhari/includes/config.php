<?php
// Konfigurasi Database
define('DB_SERVER', 'localhost'); // Server database (biasanya localhost di XAMPP)
define('DB_USERNAME', 'root'); // Ketik ulang baris ini, JANGAN COPY-PASTE
define('DB_PASSWORD', ''); // Ketik ulang baris ini, JANGAN COPY-PASTE
define('DB_NAME', 'db_raport_azhari'); // Nama database yang telah dibuat

// Membuat koneksi ke database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// PENTING: SET CHARACTER SET KE UTF8MB4 untuk dukungan karakter Arab
$conn->set_charset("utf8mb4"); 


// Fungsi untuk membersihkan input data
function sanitize_input($conn, $data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    // Menggunakan real_escape_string untuk keamanan SQL Injection
    return $conn->real_escape_string($data);
}
?>