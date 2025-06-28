<?php
// php area jangan disentuh
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);

class NamecheapAPI {
    private $apiUser;
    private $apiKey;
    private $username;
    private $clientIp;
    private $sandbox;
    private $baseUrl;
    
    public function __construct($apiUser, $apiKey, $username, $clientIp, $sandbox = false) {
        $this->apiUser = $apiUser;
        $this->apiKey = $apiKey;
        $this->username = $username;
        $this->clientIp = $clientIp;
        $this->sandbox = $sandbox;
        $this->baseUrl = $sandbox ? 'https://api.sandbox.namecheap.com/xml.response' : 'https://api.namecheap.com/xml.response';
    }
    
    private function makeRequest($command, $params = []) {
        $baseParams = [
            'ApiUser' => $this->apiUser,
            'ApiKey' => $this->apiKey,
            'UserName' => $this->username,
            'Command' => $command,
            'ClientIp' => $this->clientIp
        ];
        
        $allParams = array_merge($baseParams, $params);
        $url = $this->baseUrl . '?' . http_build_query($allParams);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'BulkNSPointer/1.0 4FloorPride',
                'method' => 'GET'
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception('Gagal terhubung ke API Namecheap');
        }
        
        $xml = simplexml_load_string($response);
        
        if ($xml === false) {
            throw new Exception('Respons XML tidak valid dari API');
        }
        
        if ((string)$xml['Status'] === 'ERROR') {
            $errors = [];
            foreach ($xml->Errors->Error as $error) {
                $errors[] = '[' . (string)$error['Number'] . '] ' . (string)$error;
            }
            throw new Exception('API Error: ' . implode('; ', $errors));
        }
        
        return $xml;
    }
    
    public function testConnection() {
        try {
            $this->makeRequest('namecheap.domains.getList', [
                'ListType' => 'ALL',
                'Page' => '1',
                'PageSize' => '20'
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getDomainList() {
        $allDomains = [];
        $page = 1;
        $pageSize = 20;
        
        while (true) {
            try {
                $xml = $this->makeRequest('namecheap.domains.getList', [
                    'ListType' => 'ALL',
                    'Page' => (string)$page,
                    'PageSize' => (string)$pageSize
                ]);
                
                $domains = $xml->CommandResponse->DomainGetListResult->Domain ?? [];
                
                if (count($domains) === 0) {
                    break;
                }
                
                foreach ($domains as $domain) {
                    $allDomains[] = [
                        'name' => (string)$domain['Name'],
                        'user' => (string)$domain['User'],
                        'created' => (string)$domain['Created'],
                        'expires' => (string)$domain['Expires'],
                        'is_expired' => (string)$domain['IsExpired'] === 'true',
                        'is_locked' => (string)$domain['IsLocked'] === 'true',
                        'auto_renew' => (string)$domain['AutoRenew'] === 'true',
                        'whois_guard' => (string)$domain['WhoisGuard'],
                        'is_our_dns' => (string)$domain['IsOurDNS'] === 'true'
                    ];
                }
                
                $totalItems = (int)$xml->CommandResponse->Paging->TotalItems ?? 0;
                
                if (count($domains) < $pageSize || count($allDomains) >= $totalItems) {
                    break;
                }
                
                $page++;
                usleep(500000);
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'out of bounds') !== false || strpos($e->getMessage(), 'No domains') !== false) {
                    break;
                }
                throw $e;
            }
        }
        
        usort($allDomains, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $allDomains;
    }
    
    public function getDomainNameservers($domain) {
        $parts = explode('.', $domain, 2);
        if (count($parts) !== 2) {
            throw new Exception('Format domain tidak valid');
        }
        
        $sld = $parts[0];
        $tld = $parts[1];
        
        $xml = $this->makeRequest('namecheap.domains.dns.getList', [
            'SLD' => $sld,
            'TLD' => $tld
        ]);
        
        $nameservers = [];
        foreach ($xml->CommandResponse->DomainDNSGetListResult->Nameserver ?? [] as $ns) {
            $nameservers[] = (string)$ns;
        }
        
        return $nameservers;
    }
    
    public function setCustomNameservers($domain, $nameservers) {
        if (count($nameservers) < 2) {
            throw new Exception('Minimal 2 nameserver diperlukan');
        }
        
        $parts = explode('.', $domain, 2);
        if (count($parts) !== 2) {
            throw new Exception('Format domain tidak valid');
        }
        
        $sld = $parts[0];
        $tld = $parts[1];
        
        $xml = $this->makeRequest('namecheap.domains.dns.setCustom', [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameservers' => implode(',', $nameservers)
        ]);
        
        return true;
    }
    
    public function setDefaultNameservers($domain) {
        $parts = explode('.', $domain, 2);
        if (count($parts) !== 2) {
            throw new Exception('Format domain tidak valid');
        }
        
        $sld = $parts[0];
        $tld = $parts[1];
        
        $xml = $this->makeRequest('namecheap.domains.dns.setDefault', [
            'SLD' => $sld,
            'TLD' => $tld
        ]);
        
        return true;
    }
}

class ConfigManager {
    private $configFile = 'config.json';
    
    public function loadConfig() {
        if (file_exists($this->configFile)) {
            $config = json_decode(file_get_contents($this->configFile), true);
            return $config ?: [];
        }
        return [];
    }
    
    public function saveConfig($config) {
        return file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT)) !== false;
    }
    
    public function isConfigured() {
        $config = $this->loadConfig();
        return !empty($config['api_user']) && !empty($config['api_key']) && 
               !empty($config['username']) && !empty($config['client_ip']);
    }
}

$configManager = new ConfigManager();
$config = $configManager->loadConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'save_config':
                $newConfig = [
                    'api_user' => trim($_POST['api_user'] ?? ''),
                    'api_key' => trim($_POST['api_key'] ?? ''),
                    'username' => trim($_POST['username'] ?? ''),
                    'client_ip' => trim($_POST['client_ip'] ?? ''),
                    'sandbox' => ($_POST['sandbox'] ?? '0') === '1'
                ];
                
                if (empty($newConfig['api_user']) || empty($newConfig['api_key']) || 
                    empty($newConfig['username']) || empty($newConfig['client_ip'])) {
                    throw new Exception('Semua field wajib diisi');
                }
                
                if (!$configManager->saveConfig($newConfig)) {
                    throw new Exception('Gagal menyimpan konfigurasi');
                }
                
                echo json_encode(['success' => true, 'message' => 'Konfigurasi berhasil disimpan']);
                break;
                
            case 'test_connection':
                if (!$configManager->isConfigured()) {
                    throw new Exception('Silakan konfigurasi pengaturan API terlebih dahulu');
                }
                
                $api = new NamecheapAPI(
                    $config['api_user'],
                    $config['api_key'],
                    $config['username'],
                    $config['client_ip'],
                    $config['sandbox'] ?? false
                );
                
                if ($api->testConnection()) {
                    echo json_encode(['success' => true, 'message' => 'Koneksi API berhasil']);
                } else {
                    throw new Exception('Koneksi API gagal');
                }
                break;
                
            case 'get_domains':
                if (!$configManager->isConfigured()) {
                    throw new Exception('Silakan konfigurasi pengaturan API terlebih dahulu');
                }
                
                $api = new NamecheapAPI(
                    $config['api_user'],
                    $config['api_key'],
                    $config['username'],
                    $config['client_ip'],
                    $config['sandbox'] ?? false
                );
                
                $domains = $api->getDomainList();
                echo json_encode(['success' => true, 'domains' => $domains]);
                break;
                
            case 'check_nameservers':
                if (!$configManager->isConfigured()) {
                    throw new Exception('Silakan konfigurasi pengaturan API terlebih dahulu');
                }
                
                $domains = [];
                if (!empty($_POST['domains'])) {
                    $domains = array_filter(array_map('trim', explode(',', $_POST['domains'])));
                }
                
                if (isset($_FILES['domain_file']) && $_FILES['domain_file']['error'] === UPLOAD_ERR_OK) {
                    $fileContent = file_get_contents($_FILES['domain_file']['tmp_name']);
                    $fileDomains = array_filter(array_map('trim', explode("\n", $fileContent)));
                    $domains = array_merge($domains, $fileDomains);
                }
                
                if (empty($domains)) {
                    throw new Exception('Tidak ada domain yang dispesifikasi');
                }
                
                $api = new NamecheapAPI(
                    $config['api_user'],
                    $config['api_key'],
                    $config['username'],
                    $config['client_ip'],
                    $config['sandbox'] ?? false
                );
                
                $results = [];
                foreach ($domains as $domain) {
                    try {
                        $nameservers = $api->getDomainNameservers($domain);
                        $results[] = [
                            'domain' => $domain,
                            'nameservers' => $nameservers,
                            'status' => 'success'
                        ];
                    } catch (Exception $e) {
                        $results[] = [
                            'domain' => $domain,
                            'error' => $e->getMessage(),
                            'status' => 'error'
                        ];
                    }
                }
                
                echo json_encode(['success' => true, 'results' => $results]);
                break;
                
            case 'bulk_update':
                if (!$configManager->isConfigured()) {
                    throw new Exception('Silakan konfigurasi pengaturan API terlebih dahulu');
                }
                
                $domains = [];
                if (!empty($_POST['domains'])) {
                    $domains = array_filter(array_map('trim', explode(',', $_POST['domains'])));
                }
                
                if (isset($_FILES['domain_file']) && $_FILES['domain_file']['error'] === UPLOAD_ERR_OK) {
                    $fileContent = file_get_contents($_FILES['domain_file']['tmp_name']);
                    $fileDomains = array_filter(array_map('trim', explode("\n", $fileContent)));
                    $domains = array_merge($domains, $fileDomains);
                }
                
                $useDefault = ($_POST['use_default'] ?? '0') === '1';
                $nameservers = $useDefault ? [] : array_filter(array_map('trim', explode(',', $_POST['nameservers'] ?? '')));
                
                if (empty($domains)) {
                    throw new Exception('Tidak ada domain yang dispesifikasi');
                }
                
                if (!$useDefault && count($nameservers) < 2) {
                    throw new Exception('Minimal 2 nameserver diperlukan');
                }
                
                $api = new NamecheapAPI(
                    $config['api_user'],
                    $config['api_key'],
                    $config['username'],
                    $config['client_ip'],
                    $config['sandbox'] ?? false
                );
                
                $results = ['success' => [], 'failed' => []];
                
                foreach ($domains as $domain) {
                    try {
                        if ($useDefault) {
                            $api->setDefaultNameservers($domain);
                        } else {
                            $api->setCustomNameservers($domain, $nameservers);
                        }
                        $results['success'][] = $domain;
                    } catch (Exception $e) {
                        $results['failed'][] = ['domain' => $domain, 'error' => $e->getMessage()];
                    }
                    usleep(1500000);
                }
                
                echo json_encode(['success' => true, 'results' => $results]);
                break;
                
            default:
                throw new Exception('Aksi tidak valid');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// php area jangan disentuh
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$ BULK NS POINTER - 4FLOOR PRIDE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-gray: #6c757d;
            --secondary-gray: #495057;
            --light-gray: #f8f9fa;
            --dark-gray: #343a40;
            --border-gray: #dee2e6;
        }
        
        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header-bg {
            background: linear-gradient(135deg, var(--dark-gray) 0%, var(--secondary-gray) 100%);
            color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease-in-out;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background-color: var(--primary-gray);
            border-color: var(--primary-gray);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-gray);
            border-color: var(--secondary-gray);
        }
        
        .btn-outline-primary {
            color: var(--primary-gray);
            border-color: var(--primary-gray);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-gray);
            border-color: var(--primary-gray);
        }
        
        .text-primary {
            color: var(--primary-gray) !important;
        }
        
        .bg-primary {
            background-color: var(--primary-gray) !important;
        }
        
        .progress-bar {
            background-color: var(--primary-gray);
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            min-width: 300px;
        }
        
        .progress {
            height: 25px;
            margin: 20px 0;
        }
        
        .status-badge {
            font-size: 0.8em;
        }
        
        .domain-table {
            font-size: 0.9em;
        }
        
        .ns-cell {
            max-width: 300px;
            word-wrap: break-word;
        }
        
        .config-panel {
            background: var(--light-gray);
            border-left: 4px solid var(--primary-gray);
        }
        
        .user-info {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 10px 15px;
        }
        
        .file-upload-area {
            border: 2px dashed var(--border-gray);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: border-color 0.3s ease;
            cursor: pointer;
        }
        
        .file-upload-area:hover {
            border-color: var(--primary-gray);
        }
        
        .file-upload-area.dragover {
            border-color: var(--primary-gray);
            background-color: rgba(108, 117, 125, 0.1);
        }

        .file-selected {
            border-color: #28a745;
            background-color: rgba(40, 167, 69, 0.1);
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <h5 class="mb-3">Memproses...</h5>
            <div class="progress">
                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                     role="progressbar" style="width: 0%" id="progressBar"></div>
            </div>
            <p class="mb-0" id="loadingText">Menginisialisasi...</p>
        </div>
    </div>

    <div class="header-bg py-4 mb-4">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="mb-0"><i class="bi bi-globe2"></i> $ BULK NS POINTER</h1>
                    <p class="mb-0"><strong>4FLOOR PRIDE</strong> - LT4 NI BOS</p>
                </div>
                <div class="col-auto">
                    <?php if ($configManager->isConfigured()): ?>
                    <div class="user-info me-3 d-inline-block">
                        <i class="bi bi-person-circle"></i>
                        <small>
                            <strong><?= htmlspecialchars($config['api_user'] ?? 'User') ?></strong><br>
                            <span class="opacity-75">
                                <?= ($config['sandbox'] ?? false) ? 'Mode Sandbox' : 'Mode Production' ?>
                                | IP: <?= htmlspecialchars($config['client_ip'] ?? '') ?>
                            </span>
                        </small>
                    </div>
                    <?php endif; ?>
                    <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#configModal">
                        <i class="bi bi-gear"></i> Pengaturan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if (!$configManager->isConfigured()): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Konfigurasi Diperlukan:</strong> Silakan konfigurasi pengaturan API untuk memulai.
            <button class="btn btn-sm btn-warning ms-2" data-bs-toggle="modal" data-bs-target="#configModal">
                Konfigurasi Sekarang
            </button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-list-ul text-primary"></i> Portfolio Domain</h5>
                        <p class="card-text">Lihat semua domain di akun Namecheap Bosku dengan informasi lengkap.</p>
                        <button class="btn btn-primary" onclick="loadDomains()">
                            <i class="bi bi-arrow-clockwise"></i> Muat Domain
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-search text-success"></i> Cek Nameserver</h5>
                        <p class="card-text">Periksa konfigurasi nameserver untuk domain tertentu.</p>
                        
                        <div class="mb-3">
                            <input type="text" class="form-control mb-2" id="checkDomains" 
                                   placeholder="domain1.com, domain2.com">
                            <div class="text-center mb-2"><small class="text-muted">-- ATAU --</small></div>
                            <div class="file-upload-area" onclick="triggerFileInput('checkFile', this)">
                                <i class="bi bi-cloud-upload fs-2 text-muted"></i>
                                <p class="mb-0">Klik untuk upload file TXT</p>
                                <small class="text-muted">Satu domain per baris</small>
                            </div>
                            <input type="file" id="checkFile" accept=".txt" style="display: none;" onchange="handleFileSelect(this, this.parentElement.querySelector('.file-upload-area'))">
                        </div>
                        
                        <button class="btn btn-success w-100" onclick="checkNameservers()">
                            <i class="bi bi-search"></i> Cek Nameserver
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-arrow-repeat text-warning"></i> Update NS Custom</h5>
                        <p class="card-text">Atur nameserver custom untuk beberapa domain sekaligus.</p>
                        
                        <div class="mb-3">
                            <input type="text" class="form-control mb-2" id="bulkDomains" 
                                   placeholder="domain1.com, domain2.com">
                            <input type="text" class="form-control mb-2" id="customNameservers" 
                                   placeholder="ns1.example.com, ns2.example.com">
                            <div class="text-center mb-2"><small class="text-muted">-- ATAU --</small></div>
                            <div class="file-upload-area" onclick="triggerFileInput('bulkFile', this)">
                                <i class="bi bi-cloud-upload fs-2 text-muted"></i>
                                <p class="mb-0">Upload file domain TXT</p>
                            </div>
                            <input type="file" id="bulkFile" accept=".txt" style="display: none;" onchange="handleFileSelect(this, this.parentElement.querySelector('.file-upload-area'))">
                        </div>
                        
                        <button class="btn btn-warning w-100" onclick="bulkUpdate(false)">
                            <i class="bi bi-arrow-repeat"></i> Update Nameserver
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-house text-info"></i> Update NS Default</h5>
                        <p class="card-text">Reset beberapa domain untuk menggunakan nameserver default Namecheap.</p>
                        
                        <div class="mb-3">
                            <input type="text" class="form-control mb-2" id="defaultDomains" 
                                   placeholder="domain1.com, domain2.com">
                            <div class="text-center mb-2"><small class="text-muted">-- ATAU --</small></div>
                            <div class="file-upload-area" onclick="triggerFileInput('defaultFile', this)">
                                <i class="bi bi-cloud-upload fs-2 text-muted"></i>
                                <p class="mb-0">Upload file domain TXT</p>
                            </div>
                            <input type="file" id="defaultFile" accept=".txt" style="display: none;" onchange="handleFileSelect(this, this.parentElement.querySelector('.file-upload-area'))">
                        </div>
                        
                        <button class="btn btn-info w-100" onclick="bulkUpdate(true)">
                            <i class="bi bi-house"></i> Set NS Default
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="resultsContainer" class="mt-4"></div>
    </div>

    <div class="modal fade" id="configModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header header-bg">
                    <h5 class="modal-title"><i class="bi bi-gear"></i> Konfigurasi API</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="config-panel p-3 rounded mb-3">
                        <h6><i class="bi bi-info-circle"></i> Informasi yang Diperlukan</h6>
                        <ul class="mb-0">
                            <li>API User: Username Namecheap Bosku</li>
                            <li>API Key: Dari panel akun Namecheap Bosku</li>
                            <li>Client IP: Alamat IP saat ini (harus di-whitelist)</li>
                            <li>Tool di buat sebagai bentuk hobi dan kecintaan terhadap dunia code.</li>
                            <li>Copyright : VIC 502</li>
                        </ul>
                        <h6><i class="bi bi-info-circle"></i>NOTE:</h6>
                        <ul class="mb-0">
                            <li>Tool di buat sebagai bentuk hobi dan kecintaan terhadap dunia code.</li>
                            <li>Copyright : VIC 502</li>
                            <li>TIDAK DI PERJUAL BELIKAN!!!!</li>
                        </ul>
                    </div>
                    
                    <form id="configForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">API User</label>
                                <input type="text" class="form-control" name="api_user" 
                                       value="<?= htmlspecialchars($config['api_user'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">API Key</label>
                                <input type="password" class="form-control" name="api_key" 
                                       value="<?= htmlspecialchars($config['api_key'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" name="username" 
                                       value="<?= htmlspecialchars($config['username'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client IP</label>
                                <input type="text" class="form-control" name="client_ip" 
                                       value="<?= htmlspecialchars($config['client_ip'] ?? '') ?>" required>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="sandbox" value="1" 
                                           <?= ($config['sandbox'] ?? false) ? 'checked' : '' ?>>
                                    <label class="form-check-label">Gunakan Environment Sandbox (untuk testing)</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-info" onclick="testConnection()">Test Koneksi</button>
                    <button type="button" class="btn btn-primary" onclick="saveConfig()">Simpan Konfigurasi</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let progressInterval;

        function showLoading(text = 'Memproses...') {
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('loadingText').textContent = text;
            document.getElementById('progressBar').style.width = '0%';
            
            let progress = 0;
            progressInterval = setInterval(() => {
                progress += Math.random() * 15;
                if (progress > 90) progress = 90;
                document.getElementById('progressBar').style.width = progress + '%';
            }, 200);
        }

        function hideLoading() {
            clearInterval(progressInterval);
            document.getElementById('progressBar').style.width = '100%';
            setTimeout(() => {
                document.getElementById('loadingOverlay').style.display = 'none';
            }, 300);
        }

        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container');
            container.insertBefore(alertDiv, container.firstChild);
            
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        async function apiCall(action, data = {}, files = {}) {
            const formData = new FormData();
            formData.append('action', action);
            
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }
            
            for (const [key, file] of Object.entries(files)) {
                if (file) formData.append(key, file);
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`Server Error (${response.status}): ${response.statusText}`);
                }
                
                const responseText = await response.text();
                
                try {
                    return JSON.parse(responseText);
                } catch (jsonError) {
                    // Jika response bukan JSON, mngkn ada PHP error
                    if (responseText.includes('<!DOCTYPE') || responseText.includes('<html>')) {
                        throw new Error('Server mengembalikan halaman HTML. Kemungkinan ada PHP error.');
                    } else if (responseText.includes('Fatal error')) {
                        throw new Error('PHP Fatal Error detected di server');
                    } else if (responseText.includes('Warning:') || responseText.includes('Notice:')) {
                        throw new Error('PHP Warning/Notice menginterupsi response');
                    } else if (responseText.trim() === '') {
                        throw new Error('Server mengembalikan response kosong');
                    } else {
                        throw new Error(`Response tidak valid: ${responseText.substring(0, 100)}...`);
                    }
                }
            } catch (networkError) {
                if (networkError.name === 'TypeError') {
                    throw new Error('Koneksi terputus atau server tidak merespons');
                } else {
                    throw networkError;
                }
            }
        }

        function getErrorSolution(errorMsg) {
            const solutions = [
                {
                    pattern: /Server Error|PHP|Internal Server Error/i,
                    solution: '💡 <strong>Solusi:</strong> Refresh halaman dan coba lagi. Jika masih error, hubungi dev VIC 502.'
                },
                {
                    pattern: /tidak ditemukan|not found/i,
                    solution: '💡 <strong>Solusi:</strong> Pastikan domain benar dan ada di akun Namecheap Bosku.'
                },
                {
                    pattern: /tidak ada akses|permission|tidak ada izin/i,
                    solution: '💡 <strong>Solusi:</strong> Domain mungkin di akun lain atau terkunci. Cek ownership domain.'
                },
                {
                    pattern: /nameserver.*tidak valid|invalid nameserver/i,
                    solution: '💡 <strong>Solusi:</strong> Periksa penulisan nameserver, pastikan formatnya benar (ns1.domain.com).'
                },
                {
                    pattern: /rate limit|terlalu banyak/i,
                    solution: '💡 <strong>Solusi:</strong> Tunggu 5-10 menit sebelum melanjutkan bulk update.'
                },
                {
                    pattern: /TLD tidak didukung/i,
                    solution: '💡 <strong>Solusi:</strong> Domain dengan ekstensi ini tidak support custom nameserver.'
                },
                {
                    pattern: /koneksi terputus|timeout/i,
                    solution: '💡 <strong>Solusi:</strong> Periksa koneksi internet dan coba lagi.'
                },
                {
                    pattern: /format domain salah/i,
                    solution: '💡 <strong>Solusi:</strong> Periksa penulisan domain, pastikan tidak ada spasi atau karakter aneh.'
                }
            ];
            
            for (const sol of solutions) {
                if (sol.pattern.test(errorMsg)) {
                    return `<div class="ms-4 mt-1"><small class="text-info">${sol.solution}</small></div>`;
                }
            }
            
            return '<div class="ms-4 mt-1"><small class="text-info">💡 <strong>Solusi:</strong> Coba lagi atau skip domain ini.</small></div>';
        }

        function getDetailedErrorMessage(error, domain) {
            const errorMsg = error.message || error.toString();
            
            const errorPatterns = [
                {
                    pattern: /Unexpected token.*not valid JSON/i,
                    message: '⚠️ Server Error - Kemungkinan ada masalah PHP di backend'
                },
                {
                    pattern: /Server Error \(50\d\)/i,
                    message: '⚠️ Internal Server Error - Ada masalah di server'
                },
                {
                    pattern: /Server Error \(40\d\)/i,
                    message: '⚠️ Request Error - Permintaan tidak valid'
                },
                {
                    pattern: /Fatal error/i,
                    message: '⚠️ PHP Fatal Error - Script berhenti karena error kritis'
                },
                {
                    pattern: /PHP Warning/i,
                    message: '⚠️ PHP Warning - Ada warning yang menginterupsi proses'
                },
                {
                    pattern: /API Error.*\[2011150\]/i,
                    message: '⚠️ API Error - Domain tidak ditemukan atau tidak ada akses'
                },
                {
                    pattern: /API Error.*\[2019166\]/i,
                    message: '⚠️ API Error - TLD tidak didukung untuk operasi ini'
                },
                {
                    pattern: /API Error.*\[2030280\]/i,
                    message: '⚠️ Nameserver Error - Nameserver tidak valid atau tidak dapat diakses'
                },
                {
                    pattern: /API Error.*\[2016166\]/i,
                    message: '⚠️ Permission Error - Tidak ada izin untuk mengubah domain ini'
                },
                {
                    pattern: /API Error.*\[3031510\]/i,
                    message: '⚠️ Rate Limit - Terlalu banyak request, tunggu sebentar'
                },
                {
                    pattern: /koneksi terputus|server tidak merespons/i,
                    message: '⚠️📡 Koneksi Terputus - Periksa koneksi internet'
                },
                {
                    pattern: /timeout/i,
                    message: '⚠️ Timeout - Server terlalu lama merespons'
                },
                {
                    pattern: /Format domain tidak valid/i,
                    message: '⚠️ Format Domain Salah - Periksa penulisan domain'
                }
            ];
            
            for (const pattern of errorPatterns) {
                if (pattern.pattern.test(errorMsg)) {
                    return `${pattern.message}`;
                }
            }
            
            let cleanError = errorMsg
                .replace(/.*API Error:\s*/i, '')
                .replace(/\[\d+\]\s*/, '')
                .replace(/Unexpected token.*/, 'Response server tidak valid')
                .trim();
            
            if (cleanError.length > 80) {
                cleanError = cleanError.substring(0, 80) + '...';
            }
            
            return cleanError || 'Error tidak diketahui';
        }

        function triggerFileInput(inputId, area) {
            const input = document.getElementById(inputId);
            if (input) {
                input.click();
            }
        }

        function handleFileSelect(input, area) {
            if (input.files && input.files[0]) {
                area.classList.add('file-selected');
                area.innerHTML = `
                    <i class="bi bi-file-text fs-2 text-success"></i>
                    <p class="mb-0 text-success"><strong>${input.files[0].name}</strong></p>
                    <small class="text-muted">File berhasil dipilih (${(input.files[0].size / 1024).toFixed(1)} KB)</small>
                `;
            }
        }

        async function saveConfig() {
            showLoading('Menyimpan konfigurasi...');
            try {
                const form = document.getElementById('configForm');
                const formData = new FormData(form);
                const data = Object.fromEntries(formData.entries());
                
                const result = await apiCall('save_config', data);
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    const modal = bootstrap.Modal.getInstance(document.getElementById('configModal'));
                    if (modal) modal.hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert(result.error, 'danger');
                }
            } catch (error) {
                showAlert('Gagal menyimpan konfigurasi: ' + error.message, 'danger');
            } finally {
                hideLoading();
            }
        }

        async function testConnection() {
            showLoading('Menguji koneksi API...');
            try {
                const result = await apiCall('test_connection');
                
                if (result.success) {
                    showAlert(result.message, 'success');
                } else {
                    showAlert(result.error, 'danger');
                }
            } catch (error) {
                showAlert('Gagal menguji koneksi: ' + error.message, 'danger');
            } finally {
                hideLoading();
            }
        }

        async function loadDomains() {
            showLoading('Memuat daftar domain...');
            try {
                const result = await apiCall('get_domains');
                
                if (result.success) {
                    displayDomains(result.domains);
                    showAlert(`Berhasil memuat ${result.domains.length} domain`, 'success');
                } else {
                    showAlert(result.error, 'danger');
                }
            } catch (error) {
                showAlert('Gagal memuat domain: ' + error.message, 'danger');
            } finally {
                hideLoading();
            }
        }

        function displayDomains(domains) {
            const container = document.getElementById('resultsContainer');
            
            if (domains.length === 0) {
                container.innerHTML = '<div class="alert alert-info">Tidak ada domain ditemukan di akun Bosku.</div>';
                return;
            }
            
            let html = `
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Portfolio Domain (${domains.length} domain)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover domain-table mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Domain</th>
                                        <th>Status</th>
                                        <th>Berakhir</th>
                                        <th>Auto Renew</th>
                                        <th>WhoisGuard</th>
                                        <th>DNS</th>
                                    </tr>
                                </thead>
                                <tbody>
            `;
            
            domains.forEach(domain => {
                const status = domain.is_expired ? '<span class="badge bg-danger status-badge">Expired</span>' :
                              domain.is_locked ? '<span class="badge bg-warning status-badge">Terkunci</span>' :
                              '<span class="badge bg-success status-badge">Aktif</span>';
                
                const autoRenew = domain.auto_renew ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
                const whoisGuard = domain.whois_guard === 'ENABLED' ? '<i class="bi bi-shield-check text-success"></i>' : '<i class="bi bi-shield-x text-danger"></i>';
                const dnsStatus = domain.is_our_dns ? '<span class="badge bg-primary status-badge">Namecheap</span>' : '<span class="badge bg-secondary status-badge">Eksternal</span>';
                
                html += `
                    <tr>
                        <td><strong>${domain.name}</strong></td>
                        <td>${status}</td>
                        <td>${domain.expires.substr(0, 10)}</td>
                        <td class="text-center">${autoRenew}</td>
                        <td class="text-center">${whoisGuard}</td>
                        <td>${dnsStatus}</td>
                    </tr>
                `;
            });
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        }

        async function checkNameservers() {
            const domains = document.getElementById('checkDomains').value.trim();
            const file = document.getElementById('checkFile').files[0];
            
            if (!domains && !file) {
                showAlert('Silakan masukkan domain atau upload file', 'warning');
                return;
            }
            
            showLoading('Mengecek nameserver...');
            try {
                const result = await apiCall('check_nameservers', { domains }, { domain_file: file });
                
                if (result.success) {
                    displayNameserverResults(result.results);
                    showAlert('Pengecekan nameserver selesai', 'success');
                } else {
                    showAlert(result.error, 'danger');
                }
            } catch (error) {
                showAlert('Gagal mengecek nameserver: ' + error.message, 'danger');
            } finally {
                hideLoading();
            }
        }

        function displayNameserverResults(results) {
            const container = document.getElementById('resultsContainer');
            
            let html = `
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-search"></i> Hasil Pengecekan Nameserver</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Domain</th>
                                        <th>Nameserver</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
            `;
            
            results.forEach(result => {
                const status = result.status === 'success' ? 
                    '<span class="badge bg-success">OK</span>' : 
                    '<span class="badge bg-danger">Error</span>';
                
                const nameservers = result.status === 'success' ? 
                    result.nameservers.join('<br>') : 
                    `<span class="text-danger">${result.error}</span>`;
                
                html += `
                    <tr>
                        <td><strong>${result.domain}</strong></td>
                        <td class="ns-cell">${nameservers}</td>
                        <td>${status}</td>
                    </tr>
                `;
            });
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        }

        async function bulkUpdate(useDefault) {
            const domainInput = useDefault ? 
                document.getElementById('defaultDomains') : 
                document.getElementById('bulkDomains');
            
            const fileInput = useDefault ? 
                document.getElementById('defaultFile') : 
                document.getElementById('bulkFile');
            
            const nameserverInput = document.getElementById('customNameservers');
            
            if (!domainInput || !fileInput) {
                showAlert('Form tidak lengkap. Refresh halaman dan coba lagi.', 'danger');
                return;
            }
            
            const domainsText = domainInput.value.trim();
            const nameservers = useDefault ? '' : (nameserverInput ? nameserverInput.value.trim() : '');
            const file = fileInput.files && fileInput.files.length > 0 ? fileInput.files[0] : null;
            
            let domainList = [];
            
            if (domainsText) {
                domainList = domainsText.split(',').map(d => d.trim()).filter(d => d);
            }
            
            if (file) {
                try {
                    const fileContent = await readFileContent(file);
                    const fileDomains = fileContent.split('\n').map(d => d.trim()).filter(d => d);
                    domainList = domainList.concat(fileDomains);
                } catch (error) {
                    showAlert('Gagal membaca file: ' + error.message, 'danger');
                    return;
                }
            }
            
            if (domainList.length === 0) {
                showAlert('Silakan masukkan domain atau upload file', 'warning');
                return;
            }
            
            if (!useDefault && !nameservers) {
                showAlert('Silakan masukkan nameserver', 'warning');
                return;
            }
            
            if (!useDefault && nameservers.split(',').filter(ns => ns.trim()).length < 2) {
                showAlert('Minimal 2 nameserver diperlukan', 'warning');
                return;
            }
            
            const nsArray = useDefault ? ['Namecheap Default'] : nameservers.split(',').map(ns => ns.trim());
            const action = useDefault ? 'diatur ke nameserver default Namecheap' : `diatur ke nameserver custom: ${nameservers}`;
            
            if (!confirm(`Apakah Bosku yakin ingin ${action} untuk ${domainList.length} domain? Aksi ini tidak dapat dibatalkan.`)) {
                return;
            }
            
            await processDomainsRealTime(domainList, nsArray, useDefault);
        }

        function readFileContent(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = e => resolve(e.target.result);
                reader.onerror = () => reject(new Error('Gagal membaca file'));
                reader.readAsText(file);
            });
        }

        async function processDomainsRealTime(domainList, nameservers, useDefault) {
            const container = document.getElementById('resultsContainer');
            const action = useDefault ? 'Set Nameserver Default' : 'Update Nameserver Custom';
            const nsDisplay = nameservers.join(', ');
            
            container.innerHTML = `
                <div class="card">
                    <div class="card-header" style="background-color: var(--primary-gray); color: white;">
                        <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> ${action} - Progress Real-Time</h5>
                        <small>Target NS: ${nsDisplay}</small>
                    </div>
                    <div class="card-body">
                        <div class="progress mb-4" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 id="bulkProgressBar" role="progressbar" style="width: 0%">
                                <span id="progressText">0 / ${domainList.length}</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Status:</strong> <span id="currentStatus">Memulai proses...</span>
                        </div>
                        
                        <div id="domainResults" class="border rounded p-3" style="max-height: 400px; overflow-y: auto; background-color: #f8f9fa;">
                            <!-- Domain results akan muncul di sini secara real-time -->
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="bi bi-check-circle"></i> Berhasil: <span id="successCount">0</span></h6>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <h6 class="mb-0"><i class="bi bi-x-circle"></i> Gagal: <span id="failedCount">0</span></h6>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            const progressBar = document.getElementById('bulkProgressBar');
            const progressText = document.getElementById('progressText');
            const currentStatus = document.getElementById('currentStatus');
            const domainResults = document.getElementById('domainResults');
            const successCount = document.getElementById('successCount');
            const failedCount = document.getElementById('failedCount');
            
            let completed = 0;
            let successTotal = 0;
            let failedTotal = 0;
            
            for (let i = 0; i < domainList.length; i++) {
                const domain = domainList[i];
                
                currentStatus.textContent = `Memproses: ${domain}`;
                
                const processingDiv = document.createElement('div');
                processingDiv.id = `domain-${i}`;
                processingDiv.className = 'mb-2 p-2 border-start border-warning border-3';
                processingDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm text-warning me-2" role="status"></div>
                        <strong>${domain}</strong>
                        <span class="ms-2 text-muted">- Sedang diproses...</span>
                    </div>
                `;
                domainResults.appendChild(processingDiv);
                domainResults.scrollTop = domainResults.scrollHeight;
                
                try {
                    const result = await apiCall('bulk_update', { 
                        domains: domain, 
                        nameservers: useDefault ? '' : nameservers.join(','), 
                        use_default: useDefault ? '1' : '0' 
                    });
                    
                    completed++;
                    
                    if (result.success && result.results.success.length > 0) {
                        successTotal++;
                        processingDiv.className = 'mb-2 p-2 border-start border-success border-3 bg-light';
                        processingDiv.innerHTML = `
                            <div class="d-flex align-items-center">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <strong>${domain}</strong>
                                <span class="ms-2 text-success">- Berhasil diupdate ke: ${nsDisplay}</span>
                            </div>
                        `;
                    } else {
                        failedTotal++;
                        let errorMsg = 'Error tidak diketahui';
                        
                        if (result.error) {
                            errorMsg = getDetailedErrorMessage({ message: result.error }, domain);
                        } else if (result.results && result.results.failed.length > 0) {
                            errorMsg = getDetailedErrorMessage({ message: result.results.failed[0].error }, domain);
                        }
                        
                        processingDiv.className = 'mb-2 p-2 border-start border-danger border-3 bg-light';
                        processingDiv.innerHTML = `
                            <div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-x-circle text-danger me-2"></i>
                                    <strong>${domain}</strong>
                                    <span class="ms-2 text-danger">- GAGAL</span>
                                </div>
                                <div class="ms-4 mt-1">
                                    <small class="text-muted"><strong>Alasan:</strong> ${errorMsg}</small>
                                </div>
                                ${getErrorSolution(errorMsg)}
                            </div>
                        `;
                    }
                } catch (error) {
                    completed++;
                    failedTotal++;
                    const detailedError = getDetailedErrorMessage(error, domain);
                    
                    processingDiv.className = 'mb-2 p-2 border-start border-danger border-3 bg-light';
                    processingDiv.innerHTML = `
                        <div>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-x-circle text-danger me-2"></i>
                                <strong>${domain}</strong>
                                <span class="ms-2 text-danger">- ERROR</span>
                            </div>
                            <div class="ms-4 mt-1">
                                <small class="text-muted"><strong>Error:</strong> ${detailedError}</small>
                            </div>
                            ${getErrorSolution(detailedError)}
                        </div>
                    `;
                }
                
                const percentage = Math.round((completed / domainList.length) * 100);
                progressBar.style.width = percentage + '%';
                progressText.textContent = `${completed} / ${domainList.length}`;
                successCount.textContent = successTotal;
                failedCount.textContent = failedTotal;
                
                domainResults.scrollTop = domainResults.scrollHeight;
                
                if (i < domainList.length - 1) {
                    await new Promise(resolve => setTimeout(resolve, 1500));
                }
            }
            
            currentStatus.innerHTML = `
                <span class="text-success"><strong>✅ Proses Selesai!</strong></span> 
                ${successTotal} berhasil, ${failedTotal} gagal dari ${domainList.length} domain.
            `;
            
            progressBar.classList.remove('progress-bar-animated');
            
            showAlert(`Bulk update selesai! ${successTotal} berhasil, ${failedTotal} gagal`, 'info');
        }

        function displayBulkResults(results, useDefault) {
            const container = document.getElementById('resultsContainer');
            const action = useDefault ? 'Nameserver Default' : 'Nameserver Custom';
            
            let html = `
                <div class="card">
                    <div class="card-header" style="background-color: var(--primary-gray); color: white;">
                        <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Hasil Bulk Update - ${action}</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="bi bi-check-circle"></i> Berhasil (${results.success.length})</h6>
                                    </div>
                                    <div class="card-body">
            `;
            
            if (results.success.length > 0) {
                results.success.forEach(domain => {
                    html += `<div class="mb-1"><i class="bi bi-check text-success"></i> ${domain}</div>`;
                });
            } else {
                html += '<div class="text-muted">Tidak ada update yang berhasil</div>';
            }
            
            html += `
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <h6 class="mb-0"><i class="bi bi-x-circle"></i> Gagal (${results.failed.length})</h6>
                                    </div>
                                    <div class="card-body">
            `;
            
            if (results.failed.length > 0) {
                results.failed.forEach(failure => {
                    html += `<div class="mb-2"><i class="bi bi-x text-danger"></i> <strong>${failure.domain}</strong><br><small class="text-muted">${failure.error}</small></div>`;
                });
            } else {
                html += '<div class="text-muted">Tidak ada update yang gagal</div>';
            }
            
            html += `
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        }
    </script>
</body>
</html>
