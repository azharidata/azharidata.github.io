<?php
session_start();

// Cek apakah sudah login sebagai admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: ../index.php");
    exit;
}
require_once '../includes/config.php';
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Admin - Raport Azhari</title>
    <link rel="stylesheet" href="/db_raport_azhari/assets/css/style.css">
</head>
<body>
    <div class="container">
       <div class="header">
            <div class="logo-section">
                <img src="/db_raport_azhari/assets/img/logo.png" alt="Logo Pondok" style="width: 50px; height: 50px; border-radius: 50%; border: 3px solid var(--color-primary);"> 
                <h2>Dashboard Admin</h2>
            </div>
            
            <div>
                 <a href="index.php">Dashboard</a> | <a href="../logout.php">Logout</a>
            </div>
        </div>
        <div class="nav">
            <h3>Data Master</h3>
            <ul>
            <li><a href="guru.php">Kelola Data Guru & Wali Kelas</a></li>
            <li><a href="mapel.php">Kelola Mata Pelajaran</a></li>
            <li><a href="kelas.php">Kelola Data Kelas</a></li> 
            <li><a href="santri.php">Kelola Data Santri</a></li>
            <li><a href="tahun_ajaran.php">Kelola Tahun Ajaran / Semester</a></li>
            <li><a href="pengaturan_raport.php">pengaturan raport</a></li>
            </ul>
            
            <h3>Proses Raport</h3>
            <ul>
                <li><a href="nilai_review.php">Review & Cetak Raport</a></li>
                <li><a href="import_santri.php">Import Santri dari CSV</a></li>
                <li><a href="import_guru.php">Import Guru dari CSV</a></li> 
                <li><a href="import_mapel.php">Import Mata Pelajaran dari CSV</a></li>
            </ul>
        </div>
        <div class="content">
            <h3>Selamat Datang, Admin!</h3>
            <p>Gunakan menu navigasi di samping untuk mengelola data master dan memproses laporan nilai santri.</p>
        </div>
    </div>
</body>
</html>