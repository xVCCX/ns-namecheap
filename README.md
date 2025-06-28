🛠️ Requirements

PHP 7.4+ (atau yang lebih baru)
Web Server (Apache/Nginx)
Namecheap API Account dengan API key
Domain yang ada di akun Namecheap bossku

Login ke Namecheap
Account → Profile → Tools
Namecheap API Access → Enable API
Catat:

API User: Username kamu
API Key: Yang di-generate
Username: Username kamu lagi
Client IP: IP server kamu (harus di-whitelist)



Konfigurasi di Tool

Buka tool di browser
Klik ⚙️ Pengaturan
Isi semua data API
Test Koneksi - pastikan sukses
Simpan

Method 1: Input Manual
Domain: domain1.com, domain2.com, domain3.com
Nameserver: ns1.cloudflare.com, ns2.cloudflare.com

Method 2: Upload File
Buat file domains.txt:
domain1.com
domain2.com
domain3.com
domain4.com

v1.0.0

Basic bulk nameserver update
File upload support
Namecheap API integration

⚠️ Disclaimer

Use at your own risk - Backup domain settings sebelum bulk update
Test dulu dengan beberapa domain sebelum bulk besar
Rate limit Namecheap bisa berubah kapan saja
