# Balance Live Android — LiveKit JavaScript bersama Browser

APK ini memakai WebView untuk membuka aplikasi PHP dari server. Browser biasa dan APK menjalankan **kode LiveKit JavaScript yang sama**; APK tidak membuat room atau publish audio lewat LiveKit Android Native.

## Arsitektur
- Server PHP: autentikasi, room, token LiveKit, chat, moderasi, database, dan UI.
- LiveKit JavaScript (`livekit-client`): satu-satunya jalur koneksi audio untuk browser dan APK.
- Android Kotlin + WebView: memuat halaman HTTPS dan meneruskan permintaan izin mikrofon WebView ke izin runtime Android. Instalasi baru otomatis memakai `https://serayalaras-sejahtera.com`; URL tersimpan tetap dipakai.
- `config.php`, `room_v4.sqlite`, dan `uploads/` tetap berada di server.

## Yang berubah
- Menghapus LiveKit Android SDK dan bridge `NativeLiveKit` dari APK.
- `index_android.php` tidak lagi memilih jalur audio native. Browser dan APK memakai `LivekitClient.Room` yang sama.
- Meter membaca `localParticipant.audioLevel` dan level peserta remote dari koneksi JavaScript yang sama.
- Saat halaman meminta `getUserMedia`, Android menampilkan izin `RECORD_AUDIO`, lalu meneruskan persetujuan ke WebView.
- Dialog JavaScript `confirm()` (misalnya konfirmasi bersihkan semua chat) memakai dialog native Android dengan tombol BATAL/OK.
- Tombol unggah foto HTML (`input type=file`) membuka pemilih dokumen/foto Android; pengguna dapat memilih satu atau beberapa foto sesuai pengaturan halaman. Menggunakan URI picker sehingga tidak memerlukan izin akses seluruh galeri pada Android modern.
- Version APK dinaikkan menjadi 1.1.0 (versionCode 2) agar dapat dipasang sebagai pembaruan.

## Deploy server
1. Backup `index.php` yang sedang aktif.
2. Upload file `index_android.php` dari ZIP ini ke folder web.
3. Pasang isi file tersebut sebagai `index.php` (atau salin ke endpoint PHP yang memang dibuka APK).
4. Jangan mengganti atau menghapus `config.php`, `room_v4.sqlite`, maupun folder `uploads/`.
5. Pastikan domain HTTPS dan `LK_URL`, `LK_KEY`, `LK_SECRET` di `config.php` benar.
6. Browser dan APK harus mengakses versi halaman PHP yang sama.

## Build APK
1. Extract seluruh isi ZIP termasuk folder tersembunyi `.github`.
2. Push semua isi project ke repository GitHub.
3. Jalankan workflow **Build Balance Live APK** di tab Actions.
4. Unduh artifact `BalanceLive-debug-apk`, lalu instal `app-debug.apk`.

## Catatan kompatibilitas audio
- WebView Android harus mendukung WebRTC dan memakai Android System WebView/Chrome yang diperbarui.
- Pengguna harus memberi izin mikrofon Android saat diminta. Untuk unggah foto, pilih foto melalui pemilih foto/dokumen Android; izin galeri luas tidak diminta oleh APK ini.
- Halaman wajib HTTPS; mikrofon tidak akan bekerja di origin HTTP biasa.
- Audio playback dapat memerlukan interaksi pertama pengguna; tombol unlock audio di room tetap tersedia.
- Kode PHP/JavaScript di server dapat diperbarui tanpa build APK. Perubahan izin Android, perilaku WebView native, ikon, atau konfigurasi APK tetap memerlukan build APK baru.
- Jangan pernah menaruh `LK_SECRET` atau `LK_KEY` di dalam APK.

## Perbaikan sesi latar belakang (versi source patch)
- APK memulai Android foreground service bertipe `microphone` + `mediaPlayback` setelah mikrofon LiveKit berhasil dipublikasikan. Android menampilkan notifikasi sesi audio aktif.
- Service menahan partial wake lock dan mengirim heartbeat PHP terautentikasi setiap 10 detik, terpisah dari timer JavaScript WebView.
- Endpoint `?a=heartbeat` hanya memperbarui status aktif untuk token login yang valid.
- Pembersihan room host offline diberi toleransi 180 detik agar gangguan jaringan singkat tidak langsung mengakhiri siaran.
- Saat pengguna keluar dari room, service dihentikan. Menutup task dari Recent Apps juga menghentikan service.

**Catatan:** LiveKit audio tetap dijalankan oleh LiveKit JavaScript di WebView (sesuai arsitektur proyek asal). Foreground service + wake lock + heartbeat membantu menjaga proses dan status online, tetapi tidak dapat menjamin semua vendor Android/ROM akan mempertahankan WebView/WebRTC tanpa putus. Uji pada perangkat target; optimasi baterai vendor mungkin tetap perlu diatur oleh pengguna.

## Deploy patch
1. Backup `index.php` aktif.
2. Upload `index_android.php` dari folder `balance-main` sebagai file PHP yang dipakai APK (misalnya `index.php`).
3. Build APK baru dari source project ini, lalu instal.
4. Beri izin mikrofon saat diminta. Saat mic aktif, notifikasi "Balance Live sedang aktif" harus muncul.
5. Uji dengan layar mati/minimize selama lebih dari 2 menit, lalu pastikan siaran dan status host tetap hidup.
