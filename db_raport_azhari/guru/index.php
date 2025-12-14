<?php
session_start();

// Cek apakah sudah login sebagai guru
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "guru") {
    header("location: ../index.php");
    exit;
}

require_once '../includes/config.php';
$id_guru = $_SESSION["id_user"];

// Ambil data guru
$nama_guru = "";
$sql_guru = "SELECT nama_guru FROM tabel_guru WHERE id_guru = ?";
if ($stmt_guru = $conn->prepare($sql_guru)) {
    $stmt_guru->bind_param("i", $id_guru);
    $stmt_guru->execute();
    $stmt_guru->bind_result($nama_guru);
    $stmt_guru->fetch();
    $stmt_guru->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Guru - Raport Azhari</title>
    <link rel="stylesheet" href="/db_raport_azhari/assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Selamat Datang, <?php echo htmlspecialchars($nama_guru); ?> (Guru)</h2>
            <a href="../logout.php">Logout</a>
        </div>
        <div class="nav">
            <ul>
                <li><a href="input_nilai.php">Input Nilai Ujian (PTS/PAS)</a></li>
                <?php 
                // Di sini Anda perlu menambahkan pengecekan apakah guru ini adalah wali kelas
                // if (is_wali_kelas($id_guru)) { ...
                ?>
                <li><a href="input_absensi_tahfidz.php">Input Absensi & Tahfidz</a></li>
        </div>
        <div class="content">
            <h3>Panduan Singkat</h3>
            <p>Silakan gunakan menu di samping untuk mulai menginputkan nilai mata pelajaran yang Anda ampu.</p>
        </div>
    </div>
</body>
</html>