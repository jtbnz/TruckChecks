<?php
// Check if security code is provided
if (!isset($_GET['code']) || empty($_GET['code'])) {
    http_response_code(400);
    die('Invalid security code');
}

$security_code = $_GET['code'];
$station_id = isset($_GET['station_id']) ? $_GET['station_id'] : null;
$station_name = isset($_GET['station_name']) ? $_GET['station_name'] : 'Unknown Station';

// Set cookie for 3 years (3 * 365 * 24 * 60 * 60 seconds)
$cookie_duration = 3 * 365 * 24 * 60 * 60;
$expires = time() + $cookie_duration;

// Determine if we're using HTTPS
$is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

// Debug logging
error_log("Setting security cookie - HTTPS: " . ($is_https ? 'true' : 'false') . ", Station ID: $station_id, Code: $security_code");

// Set the station-specific security code cookie
$cookie_name = 'security_code_station_' . $station_id;
$cookie_set = setcookie($cookie_name, $security_code, $expires, '/', '', $is_https, true);
error_log("Station cookie ($cookie_name) set result: " . ($cookie_set ? 'success' : 'failed'));

// Also set a general security code cookie for backward compatibility
$general_cookie_set = setcookie('security_code', $security_code, $expires, '/', '', $is_https, true);
error_log("General cookie set result: " . ($general_cookie_set ? 'success' : 'failed'));

// Also set it in session for immediate use
session_start();
$_SESSION['security_code'] = $security_code;
$_SESSION['security_code_station_' . $station_id] = $security_code;

// Also store in localStorage as a backup
echo "<script>
    localStorage.setItem('security_code_station_" . $station_id . "', '" . $security_code . "');
    localStorage.setItem('security_code', '" . $security_code . "');
    console.log('Security code stored in localStorage as backup');
</script>";

// Check if session has not already been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the version session variable is not set
if (!isset($_SESSION['version'])) {
    // Get the latest Git tag version
    $version = trim(exec('git describe --tags $(git rev-list --tags --max-count=1)'));
    // Set the session variable
    $_SESSION['version'] = $version;
} else {
    // Use the already set session variable
    $version = $_SESSION['version'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Code Set - TruckChecks</title>
    <link rel="stylesheet" href="styles/styles.css?id=<?php echo $version; ?>">
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 20px;
            background-color: #f8f9fa;
        }
        
        .success-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .success-icon {
            font-size: 48px;
            color: #28a745;
            margin-bottom: 20px;
        }
        
        .success-title {
            font-size: 24px;
            font-weight: bold;
            color: #155724;
            margin-bottom: 15px;
        }
        
        .success-message {
            font-size: 16px;
            color: #155724;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .security-code-display {
            font-size: 20px;
            font-weight: bold;
            color: #12044C;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border: 1px solid #dee2e6;
        }
        
        .info-box {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }
        
        .info-box h4 {
            margin-top: 0;
            color: #12044C;
        }
        
        .info-box ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .info-box li {
            margin: 5px 0;
        }
        
        .button-container {
            margin-top: 30px;
        }
        
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #12044C;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            margin: 5px;
        }
        
        .button:hover {
            background-color: #0056b3;
        }
        
        .button.secondary {
            background-color: #6c757d;
        }
        
        .button.secondary:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✅</div>
        <div class="success-title">Security Code Set Successfully!</div>
        
        <div class="success-message">
            Your security code for <strong><?= htmlspecialchars($station_name) ?></strong> has been stored on this device and will remain valid for 3 years.
        </div>
        
        <div class="security-code-display">
            Security Code: <?= htmlspecialchars($security_code) ?><br>
            <small style="font-size: 14px; color: #666;">Station: <?= htmlspecialchars($station_name) ?></small>
        </div>
        
        <div class="info-box">
            <h4>What happens next?</h4>
            <ul>
                <li>The security code is now stored on this mobile device</li>
                <li>You can use this device to complete truck checks</li>
                <li>The code will automatically be used when needed</li>
                <li>This code will remain valid until <?= date('F j, Y', $expires) ?></li>
            </ul>
        </div>
        
        <div class="info-box">
            <h4>Important Notes:</h4>
            <ul>
                <li>Keep this device secure as it contains your security code</li>
                <li>If you clear your browser data, you'll need to scan the QR code again</li>
                <li>This code works across all stations in the TruckChecks system</li>
                <li>Contact your administrator if you have any issues</li>
            </ul>
        </div>
        
        <div id="storage-status" style="background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 20px 0; text-align: left;">
            <h4>Checking storage status...</h4>
        </div>
        
        <div class="button-container">
            <a href="index.php" class="button">Go to TruckChecks</a>
            <a href="javascript:window.close();" class="button secondary">Close Window</a>
        </div>
    </div>
    
    <script>
        // Auto-redirect to main page after 10 seconds if not manually closed
        setTimeout(function() {
            if (confirm('Would you like to go to the TruckChecks main page now?')) {
                window.location.href = 'index.php';
            }
        }, 10000);
        
        // Show confirmation that the code was set
        console.log('=== SECURITY CODE STORAGE DEBUG ===');
        console.log('Security code set successfully for 3 years');
        console.log('Station ID: <?= $station_id ?>');
        console.log('Security Code: <?= $security_code ?>');
        
        // Check localStorage storage
        console.log('\n--- localStorage Storage ---');
        console.log('Station-specific localStorage:', localStorage.getItem('security_code_station_<?= $station_id ?>'));
        console.log('General localStorage:', localStorage.getItem('security_code'));
        
        // Check cookie storage
        console.log('\n--- Cookie Storage ---');
        console.log('All cookies:', document.cookie);
        console.log('Expected station cookie name: security_code_station_<?= $station_id ?>');
        
        // Function to get specific cookie
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }
        
        console.log('Station cookie value:', getCookie('security_code_station_<?= $station_id ?>'));
        console.log('General cookie value:', getCookie('security_code'));
        
        // Display storage status on page
        setTimeout(function() {
            const statusDiv = document.getElementById('storage-status');
            let statusHTML = '<h4>Storage Status:</h4>';
            
            // Check localStorage
            const stationLocal = localStorage.getItem('security_code_station_<?= $station_id ?>');
            const generalLocal = localStorage.getItem('security_code');
            statusHTML += '<p><strong>localStorage (survives browser restart):</strong></p>';
            statusHTML += '<ul>';
            statusHTML += '<li>Station-specific: ' + (stationLocal ? '✅ Stored (' + stationLocal.substring(0,8) + '...)' : '❌ Not found') + '</li>';
            statusHTML += '<li>General: ' + (generalLocal ? '✅ Stored (' + generalLocal.substring(0,8) + '...)' : '❌ Not found') + '</li>';
            statusHTML += '</ul>';
            
            // Check cookies
            const stationCookie = getCookie('security_code_station_<?= $station_id ?>');
            const generalCookie = getCookie('security_code');
            statusHTML += '<p><strong>Cookies (may not persist):</strong></p>';
            statusHTML += '<ul>';
            statusHTML += '<li>Station-specific: ' + (stationCookie ? '✅ Stored (' + stationCookie.substring(0,8) + '...)' : '❌ Not found') + '</li>';
            statusHTML += '<li>General: ' + (generalCookie ? '✅ Stored (' + generalCookie.substring(0,8) + '...)' : '❌ Not found') + '</li>';
            statusHTML += '</ul>';
            
            // Add recommendation
            if (stationLocal || generalLocal) {
                statusHTML += '<p style="color: green; font-weight: bold;">✅ Security code should persist across browser restarts!</p>';
            } else {
                statusHTML += '<p style="color: red; font-weight: bold;">⚠️ Warning: Security code may not persist across browser restarts!</p>';
            }
            
            statusDiv.innerHTML = statusHTML;
        }, 100);
        
        // Optional: Show a brief notification
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('TruckChecks Security Code Set', {
                body: 'Your security code has been stored on this device for 3 years.',
                icon: 'images/scania.png'
            });
        }
    </script>
</body>
</html>
