<?php
// Mulai session
session_start();
 
// Hapus semua variabel session
$_SESSION = array();
 
// Hancurkan session
session_destroy();
 
// Redirect ke halaman login
header("location: index.php");
exit;
?>