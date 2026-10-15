INVENTARIS LAB SAINS — PETUNJUK INSTALASI XAMPP

1. Ekstrak folder Inventaris_Leb-main ke:
   C:\xampp\htdocs\Inventaris_Leb-main

2. Buka XAMPP Control Panel, lalu jalankan Apache dan MySQL.

3. Buka phpMyAdmin melalui http://localhost/phpmyadmin
   Buat database bernama:
   db_inventaris_sains

4. Pilih database db_inventaris_sains, buka tab Import, lalu impor file:
   db_inventaris_sains.sql
   File SQL ini berisi struktur dan data awal aplikasi.

5. Buka website di:
   http://localhost/Inventaris_Leb-main/

KONFIGURASI DATABASE
File koneksi.php menggunakan konfigurasi bawaan XAMPP:
- Host: localhost
- User: root
- Password: (kosong)
- Database: db_inventaris_sains

Jika konfigurasi MySQL berbeda, sesuaikan koneksi.php secara lokal.

AKUN CONTOH DARI DATA SQL
- Admin: ahmad.sucipto@lab.ac.id / password123
- Peminjam: budi.santoso@student.ac.id / password123

CATATAN
- Manajemen pengguna menyimpan NIK 16 digit dan alamat lengkap. Untuk database
   lama, kolom identitas dibuat otomatis saat aplikasi pertama kali dibuka.
- Data akun lama tetap ada; NIK dan alamatnya akan tampil sebagai "Belum diisi"
   sampai admin melengkapinya melalui menu Edit.
- Semua file PHP diperiksa menggunakan php -l. Pengujian integrasi login,
  CRUD, peminjaman/pengembalian, dan backup/restore tetap perlu dilakukan
  di XAMPP yang terhubung ke database, karena lingkungan pemeriksaan ini
  tidak memiliki ekstensi mysqli/database proyek yang siap dijalankan.
- Mekanisme autentikasi lama dipertahankan sesuai permintaan redesain. Untuk
  penggunaan produksi, perbarui penyimpanan password dan pengamanan aplikasi
  sebelum dipublikasikan ke internet.
