<?php
// Pastikan koneksi dan sanitasi input sudah dimuat
require_once 'includes/config.php'; 

// =========================================================================
// 1. LOGIC PENGAMBILAN DATA INTI (SANTRI, TA, PENGATURAN)
// =========================================================================

// Ambil parameter dari URL dan validasi awal
$nis = $_GET['nis'] ?? ''; 
$id_ta_aktif = $_GET['ta'] ?? 0; 

if (empty($nis) || empty($id_ta_aktif)) {
    exit("Error: NIS Santri atau ID Tahun Ajaran tidak valid.");
}

// Data Santri (Termasuk TTD Wali Kelas dari tabel_guru)
$query_santri = "
    SELECT 
        s.id_santri, s.nama_santri, s.nis, s.alamat, k.id_kelas, k.nama_kelas, 
        g.nama_guru AS nama_wali_kelas,
        g.ttd_path AS ttd_wali_path -- Kolom NIP DIHAPUS dari SELECT list
    FROM tabel_santri s 
    JOIN tabel_kelas k ON s.id_kelas = k.id_kelas
    LEFT JOIN tabel_guru g ON k.id_wali_kelas = g.id_guru 
    WHERE s.nis = ?";
    
$stmt_santri = $conn->prepare($query_santri);
$stmt_santri->bind_param("s", $nis);
// BARIS 29: Perbaikan dilakukan di query di atas
$stmt_santri->execute();
$info_santri = $stmt_santri->get_result()->fetch_assoc();
$stmt_santri->close();

if (!$info_santri) {
    exit("Error: Data Santri tidak ditemukan di tabel_santri.");
}

// Ambil ID Santri dan ID Kelas
$id_santri = $info_santri['id_santri']; 
$id_kelas = $info_santri['id_kelas']; 

// Tentukan Jenjang Pendidikan (Marhalah) berdasarkan Nama Kelas
$nama_kelas = $info_santri['nama_kelas'] ?? '';

// Asumsi: Kelas yang mengandung angka 10, 11, atau 12 (atau nama yang spesifik) adalah Tsanawiyah (الثانوية)
if (preg_match('/10|11|12|TSANAWIYAH|MA|ALIYAH/i', $nama_kelas)) {
    $marhalah_arab = 'الثانوية'; 
} else {
    $marhalah_arab = 'الإعدادية'; // I'dadiyah (Default untuk SMP/MTs/Kelas 7-9)
}

// Teks Arab H3 lengkap yang dinamis
$h3_raport_title = 'كشف الدرجة امتحان النقل للمرحلة ' . $marhalah_arab . ' ( البنين )';


// Data Tahun Ajaran
$query_ta_info = "SELECT tahun_ajaran, semester FROM tabel_tahun_ajaran WHERE id_ta = ?"; 
$stmt_ta_info = $conn->prepare($query_ta_info);
$stmt_ta_info->bind_param("i", $id_ta_aktif);
$stmt_ta_info->execute();
$ta_aktif = $stmt_ta_info->get_result()->fetch_assoc();
$stmt_ta_info->close();

if (!$ta_aktif) {
    exit("Error: Data Tahun Ajaran tidak ditemukan.");
}

// =================================================================
// >>> PENAMBAHAN LOGIKA TANGGAL PENETAPAN BARU <<<
// =================================================================
// Format Tahun Ajaran dari 'YYYY/YYYY' menjadi hanya Tahun Kedua (akhir ajaran)
$ta_parts = explode('/', $ta_aktif['tahun_ajaran'] ?? '0000/0000');
$tahun_penetapan = end($ta_parts); 

// Ambil Nama Bulan dalam Bahasa Indonesia
$bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Asumsi: Raport dibagikan di bulan Juni (akhir semester genap) atau Desember (akhir semester ganjil)
// Namun, demi kepraktisan cetak, kita pakai tanggal hari ini
$tanggal_cetak_indo = date('d') . ' ' . $bulan_indo[(int)date('m')] . ' ' . date('Y');

// Penentuan Kota dan Tanggal Akhir (Tanggal harusnya diset di DB, tapi kita pakai default/hari ini)
$kota_penetapan = "Jepara"; 
$tanggal_penetapan = $kota_penetapan . ", " . $tanggal_cetak_indo;

// =================================================================
// >>> END PENAMBAHAN LOGIKA TANGGAL <<<
// =================================================================


// Data Pengaturan Raport (Default & Pengambilan dari DB)
$pengaturan = [
    'nama_mudir' => '(Nama Mudir Belum Diset)',
    'kata_mutiara_arab' => 'لو أن الشكر والصبر بعيران ما باليت أيهما أركب', 
    'kata_mutiara_indo' => 'Seandainya syukur dan sabar itu adalah kendaraan, maka aku tidak peduli yang mana dari keduanya yang akan aku kendarai. (Umar bin Khotob)', 
    'logo_path' => '/db_raport_azhari/assets/img/logo.png', 
    'logo_path_azhari' => '/db_raport_azhari/assets/img/logo_azhari.png', 
    
    // <<< KEY PENGATURAN TTD/STEMPEL BARU >>>
    'ttd_mudir_path' => '/db_raport_azhari/assets/ttd/ttd_mudir.png',
    'stempel_mudir_path' => '/db_raport_azhari/assets/ttd/stempel_mudir.png', // Tambahan path stempel
    'ttd_height_mudir' => '50px',
    'stempel_width_mudir' => '80px',
    'ttd_height_wali' => '40px',
    'ttd_width_wali' => '100px',
    // <<< END KEY PENGATURAN TTD/STEMPEL BARU >>>

    'logo_width_pondok' => '100%', 
    'logo_width_azhari' => '100%',
];

$query_pengaturan = "SELECT key_setting, value_setting FROM tabel_pengaturan_raport";
$result_pengaturan = $conn->query($query_pengaturan);

while ($row = $result_pengaturan->fetch_assoc()) {
    $pengaturan[$row['key_setting']] = $row['value_setting'];
}

// Validasi dan penyesuaian nilai persentase logo (jika diambil dari DB dan bukan 100%)
$logo_width_pondok = htmlspecialchars($pengaturan['logo_width_pondok'] ?? '100%');
$logo_width_azhari = htmlspecialchars($pengaturan['logo_width_azhari'] ?? '100%');


// Data Nilai
$query_nilai = "
    SELECT 
        m.id_mapel, 
        m.nama_mapel, 
        m.kategori, 
        m.kkm, 
        nr.nilai_tuntas AS nilai_santri, 
        nr.nilai_remidial AS nilai_remidi, 
        nr.jenis_ujian,
        (
            SELECT ROUND(AVG(COALESCE(GREATEST(nra.nilai_tuntas, nra.nilai_remidial), 0)), 2)
            FROM tabel_nilai_raport nra
            JOIN tabel_santri sa ON nra.id_santri = sa.id_santri
            WHERE sa.id_kelas = tmk.id_kelas 
             AND nra.id_mapel = m.id_mapel 
             AND nra.id_ta = ? 
             AND nra.jenis_ujian = 'PAS'
        ) AS rata_rata_kelas
    FROM tabel_mapel m
    INNER JOIN tabel_mapel_kelas tmk ON m.id_mapel = tmk.id_mapel
    LEFT JOIN tabel_nilai_raport nr 
    ON m.id_mapel = nr.id_mapel AND nr.id_santri = ? AND nr.id_ta = ? AND nr.jenis_ujian = 'PAS' 
    WHERE tmk.id_kelas = ? 
    ORDER BY m.kategori, m.nama_mapel
";

$stmt_nilai = $conn->prepare($query_nilai); 
$stmt_nilai->bind_param("iiii", $id_ta_aktif, $id_santri, $id_ta_aktif, $id_kelas); 
$stmt_nilai->execute();
$result_nilai = $stmt_nilai->get_result();

// --- Pengelompokan Nilai Berdasarkan Kategori ---
$nilai_kelompok = [
    'AGAMA' => [],
    'BAHASA ARAB' => [],
    'WAJIB' => [], 
    'TAMBAHAN' => [],
];
$total_nilai_global = 0;
$total_mapel = 0;

while ($row = $result_nilai->fetch_assoc()) {
    $kategori = strtoupper($row['kategori'] ?? 'WAJIB');
    
    $nilai_santri_int = (int)($row['nilai_santri'] ?? 0);
    $nilai_remidi_int = (int)($row['nilai_remidi'] ?? 0);

    $nilai_akhir = max(
        $nilai_santri_int, 
        $nilai_remidi_int
    );
    
    $key = in_array($kategori, array_keys($nilai_kelompok)) ? $kategori : 'WAJIB';
    
    $row['nilai_akhir'] = $nilai_akhir; 
    $row['rata_rata_kelas'] = $row['rata_rata_kelas'] ?? 'N/A';
    $nilai_kelompok[$key][] = $row;
    
    if ($nilai_akhir > 0) { 
        $total_nilai_global += $nilai_akhir;
        $total_mapel++;
    }
}
$stmt_nilai->close();

// Rata-Rata & Predikat
$rata_rata_global = ($total_mapel > 0) ? round($total_nilai_global / $total_mapel, 2) : 0;
$predikat_global = 'N/A'; // Default

if ($rata_rata_global > 0) {
    if ($rata_rata_global <= 30) {
        $predikat_global = '(SANGAT LEMAH) | ضعيف جدا ';
    } elseif ($rata_rata_global <= 40) {
        $predikat_global = '(LEMAH) | ضعيف ';
    } elseif ($rata_rata_global <= 65) {
        $predikat_global = '(DITERIMA) | مقبول ';
    } elseif ($rata_rata_global <= 75) {
        $predikat_global = '(BAIK) | جيد ';
    } elseif ($rata_rata_global <= 85) {
        $predikat_global = '(SANGAT BAGUS) | جيد جدا ';
    } else { // 86 hingga 100
        $predikat_global = '(ISTIMEWA) | ممتاز ';
    }
}
// =================================================================
// >>> END PENYESUAIAN <<<
// =================================================================
// FUNGSI KONVERSI ANGKA KE TERBILANG ARAB (Ta'rib al-A'dad)
function numberToWords($number) {
    // Fungsi ini disederhanakan dan mungkin tidak sempurna untuk semua bilangan,
    // tetapi memadai untuk kebutuhan dasar seperti yang ada pada kode asli.
    $number = floor($number);
    if ($number == 0) { return "صفر"; }

    $units = [1 => 'واحد', 2 => 'اثنان', 3 => 'ثلاثة', 4 => 'أربعة', 5 => 'خمسة', 6 => 'ستة', 7 => 'ثمانية', 8 => 'ثمانية', 9 => 'تسعة', 10 => 'عشرة'];
    $tens = [11 => 'أحد عشر', 12 => 'اثنا عشر', 13 => 'ثلاثة عشر', 14 => 'أربعة عشر', 15 => 'خمسة عشر', 16 => 'ستة عشر', 17 => 'سبعة عشر', 18 => 'ثمانية عشر', 19 => 'تسعة عشر'];
    $decades = [20 => 'عشرون', 30 => 'ثلاثون', 40 => 'أربعون', 50 => 'خمسون', 60 => 'ستون', 70 => 'سبعون', 80 => 'ثمانون', 90 => 'تسعون'];
    $hundreds = 'مائة'; 

    $result = [];
    $num_str = (string)$number;
    $len = strlen($num_str);

    for ($i = $len - 1; $i >= 0; $i -= 3) {
        $chunk = (int)substr($num_str, max(0, $i - 2), min(3, $len - $i + 2));
        $position = ($len - $i - 1) / 3;

        if ($chunk == 0) continue;

        $word = '';
        $h = floor($chunk / 100);
        $t = $chunk % 100;

        if ($h > 0) {
            if ($h == 1) { $word .= $hundreds; } else { $word .= $units[$h] . ' مائة'; }
        }

        if ($t > 0) {
            if ($h > 0) $word .= ' و';
            
            if ($t <= 10) { $word .= $units[$t]; } elseif ($t >= 11 && $t <= 19) { $word .= $tens[$t]; } else {
                $u = $t % 10;
                $d = floor($t / 10) * 10;
                if ($u > 0) { $word .= $units[$u] . ' و'; }
                $word .= $decades[$d];
            }
        }

        $position_words = [0 => '', 1 => ' ألف', 2 => ' مليون'];

        if ($position > 0 && isset($position_words[$position])) {
            if ($chunk == 1 && $position == 1) { $word = 'ألف'; } elseif ($chunk < 3 && $position == 1) { $word = str_replace('اثنان', 'ألفان', $word); } else { $word .= $position_words[$position]; }
        }

        array_unshift($result, $word);
    }
    return implode(' و', array_filter($result));
}
$terbilang_total = numberToWords($total_nilai_global);

// LOGIKA Pemilihan Kata Mutiara Dinamis
$kata_mutiara_key_base = 'kata_mutiara'; 
if ($rata_rata_global >= 90) { $kata_mutiara_key_base = 'mutiara_90_100';
} elseif ($rata_rata_global >= 80) { $kata_mutiara_key_base = 'mutiara_80_89';
} elseif ($rata_rata_global >= 70) { $kata_mutiara_key_base = 'mutiara_70_79';
} elseif ($rata_rata_global >= 60) { $kata_mutiara_key_base = 'mutiara_60_69';
} elseif ($rata_rata_global >= 50) { $kata_mutiara_key_base = 'mutiara_50_59';
} else { $kata_mutiara_key_base = 'kata_mutiara'; } 

// Penyesuaian Kunci Kata Mutiara
$arab_key = ($kata_mutiara_key_base == 'kata_mutiara') ? 'kata_mutiara_arab' : $kata_mutiara_key_base . '_arab';
$indo_key = ($kata_mutiara_key_base == 'kata_mutiara') ? 'kata_mutiara_indo' : $kata_mutiara_key_base . '_indo';

$pengaturan['kata_mutiara_arab'] = $pengaturan[$arab_key] ?? $pengaturan['kata_mutiara_arab']; 
$pengaturan['kata_mutiara_indo'] = $pengaturan[$indo_key] ?? $pengaturan['kata_mutiara_indo'];


// --- LOGIKA KELULUSAN / KENAIKAN KELAS ---
$mapel_gagal_wajib = 0; 
$alasan_nilai = [];

// Fallback KKM
$kkm_wajib = 70; 

// Cari KKM untuk penentuan kelulusan
if (!empty($nilai_kelompok['WAJIB'])) {
    $kkm_wajib = (int)$nilai_kelompok['WAJIB'][0]['kkm'];
} elseif (!empty($nilai_kelompok['AGAMA'])) {
     $kkm_wajib = (int)$nilai_kelompok['AGAMA'][0]['kkm'];
} 

// Hitung Mapel Gagal Wajib
foreach ($nilai_kelompok as $kategori => $mapel_list) {
    // Kriteria Gagal hanya didasarkan pada mapel WAJIB (Termasuk AGAMA/BHS ARAB jika itu inti)
    if (in_array($kategori, ['WAJIB', 'AGAMA', 'BAHASA ARAB'])) { 
        foreach ($mapel_list as $mapel) {
            $nilai_akhir = $mapel['nilai_akhir'];
            $kkm_mapel = (int)($mapel['kkm'] ?? $kkm_wajib); 

            if ($nilai_akhir > 0 && $nilai_akhir < $kkm_mapel) {
                $mapel_gagal_wajib++;
            }
        }
    }
}

// 2. DATA ABSENSI & TAHFIDZ
$query_absen_tahfidz = "
    SELECT 
        a.sakit, a.izin, a.alpa,
        t.target_juz, t.capaian_juz, t.keterangan
    FROM tabel_absensi a
    LEFT JOIN tabel_tahfidz t ON a.nis = t.nis AND a.id_ta = t.id_ta
    WHERE a.nis = ? AND a.id_ta = ?
";

$stmt_at = $conn->prepare($query_absen_tahfidz);
$stmt_at->bind_param("si", $nis, $id_ta_aktif);
$stmt_at->execute();
$data_at = $stmt_at->get_result()->fetch_assoc();
$stmt_at->close();

$absensi = [
    'sakit' => $data_at['sakit'] ?? 0,
    'izin' => $data_at['izin'] ?? 0,
    'alpa' => $data_at['alpa'] ?? 0
];

$tahfidz = [
    'target_juz' => (float)($data_at['target_juz'] ?? 0),
    'capaian_juz' => (float)($data_at['capaian_juz'] ?? 0),
    'keterangan' => htmlspecialchars($data_at['keterangan'] ?? '-')
];


// B. Kriteria Gagal Berdasarkan Absensi (Asumsi Alpa limit 5 hari)
$alpa_limit = 5; 
$status_absen = 'LULUS';
if ($absensi['alpa'] > $alpa_limit) {
    $status_absen = 'TIDAK_NAIK_ALPA'; 
    $alasan_nilai[] = "Absensi Alpa melebihi batas ({$alpa_limit} hari: {$absensi['alpa']} hari).";
}


// C. Kriteria Gagal Berdasarkan Tahfidz
$status_tahfidz = 'LULUS';
if ($tahfidz['capaian_juz'] > 0 && $tahfidz['target_juz'] > 0 && $tahfidz['capaian_juz'] < $tahfidz['target_juz']) {
    $status_tahfidz = 'BERSYARAT_TAHFIDZ';
    $alasan_nilai[] = "Capaian Setoran Tahfidz ({$tahfidz['capaian_juz']} Juz) tidak mencapai Target ({$tahfidz['target_juz']} Juz).";
}

// --- PENENTUAN STATUS KELULUSAN AKHIR ---
$final_status_indo = '';
$final_status_arab = '';
$keterangan_akhir = [];

if ($status_absen === 'TIDAK_NAIK_ALPA') {
    $final_status_indo = 'TIDAK NAIK KELAS';
    $final_status_arab = 'لم يرتق إلى الصف التالي (غياب)';
    $keterangan_akhir[] = "Tidak Naik Kelas karena Alpha melebihi batas: {$absensi['alpa']} hari.";
} elseif ($mapel_gagal_wajib > 0) {
    $final_status_indo = 'TIDAK NAIK KELAS';
    $final_status_arab = 'لم يرتق إلى الصف التالي (الدرجات)';
    $keterangan_akhir[] = "Tidak Naik Kelas karena Terdapat {$mapel_gagal_wajib} mata pelajaran wajib yang gagal.";
} elseif ($status_tahfidz === 'BERSYARAT_TAHFIDZ') {
    $final_status_indo = 'NAIK KELAS BERSYARAT';
    $final_status_arab = 'الترقية مشروطة';
    $keterangan_akhir[] = "Naik Kelas Bersyarat: Capaian Tahfidz kurang dari target.";
} else {
    $final_status_indo = 'NAIK KELAS';
    $final_status_arab = 'تمت ترقيته إلى الصف التالي';
    $keterangan_akhir[] = 'Santri dinyatakan Lulus dan Naik Kelas.';
}

$keterangan_akhir_display = implode(' | ', $keterangan_akhir);
if (empty($keterangan_akhir_display)) {
    $keterangan_akhir_display = '-';
}


// 3. LOGIKA PERINGKAT
$total_santri_kelas = 0;
$peringkat_santri = "N/A / N/A";

// Menghitung Total Santri dalam Kelas yang Sama
$query_total_santri = "
    SELECT COUNT(id_santri) AS total 
    FROM tabel_santri 
    WHERE id_kelas = ?
";
$stmt_ts = $conn->prepare($query_total_santri);
$stmt_ts->bind_param("i", $id_kelas);
$stmt_ts->execute();
$result_ts = $stmt_ts->get_result()->fetch_assoc();
$total_santri_kelas = $result_ts['total'] ?? 0;
$stmt_ts->close();


if ($total_santri_kelas > 0) {
    // Menghitung Peringkat Santri saat ini di Kelasnya
    $query_ranking = "
        SELECT
            r.id_santri,
            r.rata_rata_nilai,
            (
                SELECT COUNT(DISTINCT r2.rata_rata_nilai) 
                FROM (
                    SELECT 
                        tn.id_santri, 
                        ROUND(SUM(COALESCE(GREATEST(tn.nilai_tuntas, tn.nilai_remidial), 0)) / COUNT(tn.id_mapel), 2) AS rata_rata_nilai
                    FROM tabel_nilai_raport tn
                    WHERE tn.id_ta = ? AND tn.jenis_ujian = 'PAS'
                    AND tn.id_santri IN (SELECT id_santri FROM tabel_santri WHERE id_kelas = ?)
                    GROUP BY tn.id_santri
                ) r2
                WHERE r2.rata_rata_nilai > r.rata_rata_nilai
            ) + 1 AS peringkat
        FROM (
            SELECT 
                tn.id_santri, 
                ROUND(SUM(COALESCE(GREATEST(tn.nilai_tuntas, tn.nilai_remidial), 0)) / COUNT(tn.id_mapel), 2) AS rata_rata_nilai
            FROM tabel_nilai_raport tn
            WHERE tn.id_ta = ? AND tn.jenis_ujian = 'PAS'
            AND tn.id_santri IN (SELECT id_santri FROM tabel_santri WHERE id_kelas = ?)
            GROUP BY tn.id_santri
        ) r
        WHERE r.id_santri = ?
    ";

    $stmt_ranking = $conn->prepare($query_ranking);
    $stmt_ranking->bind_param("iiiii", $id_ta_aktif, $id_kelas, $id_ta_aktif, $id_kelas, $id_santri);
    $stmt_ranking->execute();
    $result_ranking = $stmt_ranking->get_result()->fetch_assoc();
    $stmt_ranking->close();

    $peringkat_ke = $result_ranking['peringkat'] ?? 'N/A';
    $peringkat_santri = "{$peringkat_ke} / {$total_santri_kelas}";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Raport - <?php echo htmlspecialchars($info_santri['nama_santri']); ?></title>
    
    <style>
        body {
            font-family: 'Times New Roman', serif;
            font-size: 10pt; 
            line-height: 1.3; 
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* OPTIMASI CSS UNTUK PRINT A4 LANDSCAPE */
        @page {
            size: A4 landscape;
            margin: 5mm; /* Margin lebih kecil agar konten muat */
        }
        .raport-page {
            width: 287mm; /* Lebar total A4 dikurangi margin */
            height: 195mm; /* Tinggi total A4 dikurangi margin */
            padding: 2mm; 
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        /* END OPTIMASI CSS */
        
        .text-arabic {
            font-family: 'Traditional Arabic', 'Arial', serif;
            direction: rtl;
        }

        /* --- STYLES UNTUK HEADER (2 LOGO & JUDUL) --- */
        .header-pondok-container {
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            margin-bottom: 5px; 
            border-bottom: 3px double black;
            padding-bottom: 3px; 
            height: 70px; /* Sedikit lebih kecil */
        }
        .logo-column-kiri {
            flex: 0 0 auto; 
            width: 60px; /* Sedikit lebih kecil */
            text-align: left;
        }
        .title-column {
            flex-grow: 1; 
            text-align: center;
        }
        .title-column h3 {
            margin: 0;
            font-size: 28pt; /* Sedikit lebih kecil */
            font-weight: bold;
            line-height: 1.1;
            text-align: right; 
        }
        .header-pondok-right {
            flex: 0 0 auto;
            text-align: right;
            width: 250px; 
            display: flex;
            flex-direction: column;
            align-items: flex-end; 
        }
        .logo-azhari-container {
            width: 30%;
            text-align: right;
            margin-bottom: 5px;
        }
        
        .logo-raport {
            width: 55px; /* Sedikit lebih kecil */
            height: auto;
            border-radius: 50%; 
            border: 1px solid #ccc; 
            vertical-align: middle;
        }
        .logo-azhari {
            width: 50px; /* Sedikit lebih kecil */
            height: auto;
            border-radius: 0; 
            border: none;
            vertical-align: middle;
        }
        
        /* --- INFO SANTRI (Biodata) --- */
        .info-santri-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px; /* Margin diperkecil */
            font-size: 9.5pt; /* Font diperkecil */
        }
        .info-santri-table td {
            padding: 1px 3px; 
            vertical-align: top;
        }
        .info-santri-table td:nth-child(1),
        .info-santri-table td:nth-child(4) { 
            width: 17%; 
            white-space: nowrap; 
        }
        .info-santri-table td:nth-child(2),
        .info-santri-table td:nth-child(5) { 
            width: 3%; 
            text-align: center; 
        }
        .info-santri-table td:nth-child(3),
        .info-santri-table td:nth-child(6) { 
            width: 30%; 
            font-size: 10.5pt; /* Data diperkecil */
            font-weight: bold;
        }
        .info-santri-table td.data-kiri {
             text-align: right !important; 
             direction: rtl; 
        }

        .info-santri-table td.text-arabic1 {
             text-align: left !important; 
             direction: rtl; 
        }

        .info-santri-table td.data-simetris-kanan {
             text-align: right !important; 
             direction: ltr;
        }
        /* --------------------------------------------- */


        /* --- STYLES UNTUK TABEL NILAI --- */
        .nilai-container {
            display: flex;
            flex-wrap: nowrap;
            gap: 3px; 
            margin-bottom: 5px;
            max-width: 100%;
        }
        
        .mapel-group-wrapper {
            flex: 1 1 25%; 
            min-width: 23%; 
            box-sizing: border-box;
        }

        .horizontal-mapel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt; /* Font Nilai diperkecil */
            margin-bottom: 3px;
        }
        .horizontal-mapel-table th, .horizontal-mapel-table td {
            border: 1px solid black;
            padding: 1px 2px; /* Padding diperkecil */
            text-align: center;
            vertical-align: top;
        }
        .horizontal-mapel-table th {
            background-color: #f0f0f0;
            font-size: 9pt;
        }
        .horizontal-mapel-table .label-cell {
            text-align: center;
            font-weight: bold;
            background-color: #e0e0e0;
        }
        .horizontal-mapel-table .value-cell {
            font-weight: bold;
            width: 30%; 
        }
        
        .summary-block {
            margin-top: 5px;
            width: 100%;
            flex-grow: 1;
        }

        .result-summary-table, .absensi-table, .tahfidz-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt; /* Font diperkecil */
        }
        .result-summary-table th, .result-summary-table td,
        .absensi-table th, .absensi-table td,
        .tahfidz-table th, .tahfidz-table td {
            border: 1px solid black;
            padding: 3px; 
            text-align: center;
            vertical-align: top;
        }
        
        .kata-mutiara-box {
            padding: 3px; 
            text-align: center;
            border: 1px solid black;
        }

        .signature-block {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px; /* Margin diperkecil */
        }
        .signature-block td {
            width: 33.33%;
            text-align: center;
            padding-top: 3px;
            padding-bottom: 3px;
            border: none;
            vertical-align: top;
        }
        .signature-block .signature-line {
            min-width: 100px; 
            margin-top: 5px; /* Jarak dikurangi */
        }
        .signature-cell {
            position: relative; 
            height: 90px; /* Tinggi dikurangi */
        }

        /* --- STYLE BARU UNTUK TTD OTOMATIS DAN STEMPEL --- */
        .ttd-image {
            display: block; 
            margin: 0 auto; 
            z-index: 10;
        }
        .stempel-image {
            position: absolute; 
            top: 60%; 
            left: 35%;
            transform: translate(-50%, -50%);
            opacity: 0.7; 
            z-index: 5;
        }
        /* --- END STYLE BARU --- */
    </style>
</head>
<body>
    <div class="raport-page">
        
        <div class="header-pondok-container">
            
            <div class="logo-column-kiri">
                <img src="<?php echo htmlspecialchars($pengaturan['logo_path']); ?>" 
                     alt="Logo Pondok" 
                     class="logo-raport"
                     style="width: <?php echo $logo_width_pondok; ?>;" 
                >
            </div>

            <div class="title-column">
                <h3 class="text-arabic">
                    <?php echo htmlspecialchars($h3_raport_title); ?>
                </h3>
            </div>
            
            <div class="header-pondok-right">
                
                <div class="logo-azhari-container">
                    <img src="<?php echo htmlspecialchars($pengaturan['logo_path_azhari']); ?>" 
                          alt="Logo Azhari" 
                          class="logo-azhari"
                          style="width: <?php echo $logo_width_azhari; ?>;"
                    >
                </div>

            </div>
        </div>
        
        <table class="info-santri-table">
            <tr>
                <td>NAMA | الإسم</td>
                <td>:</td>
                <td class="data-kiri text-arabic"><?php echo htmlspecialchars($info_santri['nama_santri'] ?? 'N/A'); ?></td>
                
                <td class="text-arabic1">الصف | KELAS</td>
                <td>:</td>
                <td class="data-simetris-kanan"><?php echo htmlspecialchars($info_santri['nama_kelas'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td>NIS | الرقم التسجيل</td>
                <td>:</td>
                <td class="data-kiri"><?php echo htmlspecialchars($info_santri['nis'] ?? 'N/A'); ?></td>
                
                <td class="text-arabic1">الفصل | SEMESTER</td>
                <td>:</td>
                <td class="data-simetris-kanan"><?php echo htmlspecialchars($ta_aktif['semester'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td>ALAMAT | عنوان</td>
                <td>:</td>
                <td class="data-kiri text-arabic"><?php echo htmlspecialchars($info_santri['alamat'] ?? 'N/A'); ?></td>
                
                <td class="text-arabic1">لعام الدراسي | TAHUN PELAJARAN</td>
                <td>:</td>
                <td class="data-simetris-kanan"><?php echo htmlspecialchars($ta_aktif['tahun_ajaran'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td>MADZHAB | مذهب</td>
                <td>:</td>
                <td class="data-kiri text-arabic"><?php echo htmlspecialchars('الشافعية | SYAFI\'I'); ?></td> 
                
                <td class="text-arabic1">دولة | NEGARA</td>
                <td>:</td>
                <td class="data-simetris-kanan text-arabic"><?php echo htmlspecialchars('INDONESIA | الأندونسية'); ?></td>
            </tr>
        </table>
        
        <div class="nilai-container">
            <?php
            $mapel_groups = [
                'AGAMA' => 'MAPEL AGAMA | مجموعة المواد الدينية', 
                'BAHASA ARAB' => 'MAPEL BAHASA ARAB | المود اللغة العربية', 
                'WAJIB' => 'MAPEL WAJIB / المود الواجبة', 
                'TAMBAHAN' => 'MAPEL TAMBAHAN | المواد الثقافية', 
            ];

            foreach ($mapel_groups as $key => $title):
                if (!empty($nilai_kelompok[$key])):
            ?>
            <div class="mapel-group-wrapper">
                <table class="horizontal-mapel-table">
                    <thead>
                        <tr>
                            <th colspan="2" style="background-color: #d3eafc;"><?php echo htmlspecialchars($title); ?></th>
                        </tr>
                        <tr>
                            <th width="70%">Mata Pelajaran</th>
                            <th width="30%">Nilai Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $subtotal_nilai = 0;
                        foreach ($nilai_kelompok[$key] as $mapel): 
                            $nilai_akhir = $mapel['nilai_akhir'];
                            $subtotal_nilai += $nilai_akhir;
                        ?>
                        <tr>
                            <td style="text-align: left;"><?php echo htmlspecialchars($mapel['nama_mapel']); ?></td>
                            <td class="value-cell"><?php echo $nilai_akhir; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr>
                            <td class="label-cell" style="text-align: center;">SUBTOTAL NILAI</td>
                            <td class="value-cell" style="background-color: #f7f7f7;"><?php echo $subtotal_nilai; ?></td>
                        </tr>
                        <tr>
                            <td class="label-cell" style="text-align: center;">KKM</td>
                            <td class="value-cell"><?php echo htmlspecialchars($nilai_kelompok[$key][0]['kkm'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="label-cell" style="text-align: center;">RATA-RATA KELAS</td>
                            <td class="value-cell"><?php echo htmlspecialchars($nilai_kelompok[$key][0]['rata_rata_kelas'] ?? 'N/A'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php 
                endif;
            endforeach; 
            ?>
        </div>
        
        <div class="summary-block">
            
            <table class="result-summary-table" style="width: 100%; margin-bottom: 5px;">
                <tr>
                    <th colspan="4" style="text-align: center; background-color: #d0d0d0;">HASIL AKHIR UJIAN | نتيجة الامتحان</th>
                </tr>
                <tr>
                    <th width="30%">TOTAL NILAI ANGKA | القيمة العددية الإجمالية</th>
                    <td width="20%"><strong><?php echo $total_nilai_global; ?></strong></td>
                    <th width="30%">RATA-RATA | المعدل التراكمي</th>
                    <td width="20%"><strong><?php echo $rata_rata_global; ?></strong></td>
                </tr>
                <tr>
                    <th style="background-color: #f5f5f5;">TOTAL NILAI HURUF | إجمالي قيمة الأحرف</th>
                    <td colspan="3" class="text-arabic" style="direction: rtl; font-size: 10.5pt; font-weight: bold; padding-left: 5px; text-align: center;">
                        <?php echo htmlspecialchars($terbilang_total); ?>
                    </td>
                </tr>
                <tr>
                    <th style="background-color: #f5f5f5;">PREDIKAT | التقدير</th>
                    <td colspan="3"><strong><?php echo htmlspecialchars($predikat_global); ?></strong></td>
                </tr>
            </table>

            <div style="display: flex; gap: 5px; margin-top: 5px;">
                
                <table class="absensi-table" style="width: 30%;">
                    <tr>
                        <th colspan="3" style="background-color: #d0d0d0;">ABSENSI | التحقق من الحضور</th>
                    </tr>
                    <tr>
                        <th>SAKIT</th>
                        <th>IZIN</th>
                        <th>ALPA</th>
                    </tr>
                    <tr>
                        <td><?php echo $absensi['sakit'] ?? 0; ?></td>
                        <td><?php echo $absensi['izin'] ?? 0; ?></td>
                        <td><?php echo $absensi['alpa'] ?? 0; ?></td>
                    </tr>
                </table>
                
                <table class="tahfidz-table" style="width: 45%;">
                    <tr>
                        <th colspan="3" style="background-color: #d0d0d0;">LAPORAN HAFALAN SANTRI | تقرير حفظ الطالب</th>
                    </tr>
                    <tr>
                        <th width="35%">TARGET JUZ</th>
                        <th width="35%">CAPAIAN JUZ</th>
                        <th width="30%">KETERANGAN</th>
                    </tr>
                    <tr>
                        <td><?php echo $tahfidz['target_juz'] ?? 'N/A'; ?></td>
                        <td><?php echo $tahfidz['capaian_juz'] ?? 'N/A'; ?></td>
                        <td><?php echo htmlspecialchars($tahfidz['keterangan'] ?? 'N/A'); ?></td>
                    </tr>
                </table>

                    <table class="absensi-table" style="width: 25%;">
                    <tr>
                        <th style="background-color: #f5f5f5;">PERINGKAT | رتبة الطالب</th>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; font-size: 10pt;">**<?php echo htmlspecialchars($peringkat_santri); ?>**</td>
                    </tr>
                </table>
            </div>
            
            <table class="result-summary-table" style="width: 100%; margin-top: 5px; border: 2px solid black;">
                <tr>
                    <th width="30%" style="background-color: #d0d0d0; font-size: 10.5pt;">STATUS KELULUSAN</th>
                    <td width="35%" class="text-arabic" style="direction: rtl; font-size: 10.5pt; font-weight: bold; background-color: #fceceb;">
                        <?php echo htmlspecialchars($final_status_arab); ?>
                    </td>
                    <td width="35%" style="font-weight: bold; font-size: 10.5pt; background-color: #fceceb;">
                        <?php echo htmlspecialchars($final_status_indo); ?>
                    </td>
                </tr>
                <tr>
                    <th style="background-color: #f5f5f5;">KETERANGAN AKHIR</th>
                    <td colspan="3" style="text-align: center; font-size: 9pt;">
                        <?php echo htmlspecialchars($keterangan_akhir_display); ?>
                    </td>
                </tr>
            </table>

            <div class="kata-mutiara-box" style="margin-top: 5px;">
                <p class="text-arabic" style="direction: rtl; font-style: italic; margin: 0; font-size: 9.5pt;">
                    <?php echo htmlspecialchars($pengaturan['kata_mutiara_arab']); ?>
                </p>
                <p style="font-style: italic; margin: 0; font-size: 8.5pt; color: #555;">
                    (Terjemahan: <?php echo htmlspecialchars($pengaturan['kata_mutiara_indo']); ?>)
                </p>
            </div>
        </div>
        
        <table class="signature-block">
            <tr>
                <td>
                    <div>Wali Santri | ولي الطالب</div>
                    <div style="height: 50px;"></div> 
                    <div class="signature-line">(................................)</div>
                </td>
                
                <td class="signature-cell">
                    <div>Wali Kelas | ولي الفصل</div>
                    
                    <?php 
                    $ttd_wali_path = $info_santri['ttd_wali_path'] ?? ''; 
                    if (!empty($ttd_wali_path)): 
                    ?>
                        <img src="<?php echo htmlspecialchars($ttd_wali_path); ?>" 
                            alt="TTD Wali Kelas" 
                            class="ttd-image"
                            style="height: <?php echo htmlspecialchars($pengaturan['ttd_height_wali'] ?? '40px'); ?>; 
                                   width: <?php echo htmlspecialchars($pengaturan['ttd_width_wali'] ?? '100px'); ?>;"
                        >
                    <?php else: ?>
                        <div style="height: 50px;"></div>
                    <?php endif; ?>
                    
                    <div class="signature-line">(<?php echo htmlspecialchars($info_santri['nama_wali_kelas'] ?? 'NAMA WALI KELAS'); ?>)</div>
                </td>
                
                <td class="signature-cell">
                    <div style="font-size: 9pt; margin-bottom: 2px;">
                        <?php echo htmlspecialchars($tanggal_penetapan); ?>
                    </div>
                    
                    <div>Kepala Sekolah | رئيس المدرسية</div>

                    <?php if (!empty($pengaturan['ttd_mudir_path'])): ?>
                        <img src="<?php echo htmlspecialchars($pengaturan['ttd_mudir_path']); ?>" 
                            alt="TTD Mudir" 
                            class="ttd-image"
                            style="height: <?php echo htmlspecialchars($pengaturan['ttd_height_mudir'] ?? '50px'); ?>;"
                        >
                    <?php else: ?>
                        <div style="height: 50px;"></div>
                    <?php endif; ?>

                    <?php if (!empty($pengaturan['stempel_mudir_path'])): ?>
                        <img src="<?php echo htmlspecialchars($pengaturan['stempel_mudir_path']); ?>" 
                            alt="Stempel Mudir" 
                            class="stempel-image"
                            style="width: <?php echo htmlspecialchars($pengaturan['stempel_width_mudir'] ?? '80px'); ?>;"
                        >
                    <?php endif; ?>

                    <div class="signature-line">(<?php echo htmlspecialchars($pengaturan['nama_mudir']); ?>)</div>
                </td>
            </tr>
        </table>
        </div>
</body>
</html>