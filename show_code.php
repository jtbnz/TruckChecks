<?php
require 'vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

include 'db.php';
include_once('auth.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

// Check if user has permission (superuser or station admin)
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

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

$IS_DEMO = isset($_SESSION['IS_DEMO']) && $_SESSION['IS_DEMO'] === true;

// Handle form submission for setting new code
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_code'])) {
    $new_code = $_POST['security_code'];
    if (!empty($new_code)) {
        try {
            // Check if station_settings table exists and has a security_code entry for this station
            $stmt = $db->prepare('SELECT COUNT(*) FROM station_settings WHERE station_id = ? AND setting_key = "security_code"');
            $stmt->execute([$station['id']]);
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                // Update existing code for this station
                $stmt = $db->prepare('UPDATE station_settings SET setting_value = ? WHERE station_id = ? AND setting_key = "security_code"');
                $stmt->execute([$new_code, $station['id']]);
            } else {
                // Insert new code for this station
                $stmt = $db->prepare('INSERT INTO station_settings (station_id, setting_key, setting_value, setting_type, description) VALUES (?, "security_code", ?, "string", "Station-specific security code for additional verification")');
                $stmt->execute([$station['id'], $new_code]);
            }
            
            $success_message = "Security code has been set successfully for " . htmlspecialchars($station['name']) . "!";
        } catch (Exception $e) {
            $error_message = "Error setting security code: " . $e->getMessage();
        }
    }
}

// Get current security code for this station
try {
    $stmt = $db->prepare('SELECT setting_value FROM station_settings WHERE station_id = ? AND setting_key = "security_code"');
    $stmt->execute([$station['id']]);
    $current_code = $stmt->fetchColumn();
} catch (Exception $e) {
    $current_code = null;
    $error_message = "Station settings table not found. Please run the V4Changes.sql script to create the required tables.";
}

include 'templates/header.php';
?>

<body class="<?php echo $IS_DEMO ? 'demo-mode' : ''; ?>">

<style>
    .station-info {
        text-align: center;
        margin-bottom: 30px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }

    .station-name {
        font-size: 18px;
        font-weight: bold;
        color: #12044C;
    }

    .form-section {
        max-width: 500px;
        margin: 0 auto;
        padding: 20px;
        background-color: #f9f9f9;
        border-radius: 10px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .input-container {
        margin-bottom: 20px;
    }

    .input-container label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }

    .input-container input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 16px;
        box-sizing: border-box;
    }

    .info-section {
        max-width: 500px;
        margin: 20px auto;
        padding: 15px;
        border-radius: 5px;
    }

    .success-message {
        color: green;
        margin: 20px auto;
        padding: 10px;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 5px;
        max-width: 500px;
    }

    .error-message {
        color: red;
        margin: 20px auto;
        padding: 10px;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        border-radius: 5px;
        max-width: 500px;
    }

    .input-with-button {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .input-with-button input {
        flex: 1;
    }

    .generate-btn {
        background-color: #28a745;
        white-space: nowrap;
        padding: 10px 15px;
        font-size: 14px;
    }

    .generate-btn:hover {
        background-color: #218838;
    }

    @media (max-width: 600px) {
        .input-with-button {
            flex-direction: column;
            gap: 10px;
        }
        
        .input-with-button input {
            width: 100%;
        }
        
        .generate-btn {
            width: 100%;
        }
    }
</style>

<div class="station-info">
    <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
    <?php if ($station['description']): ?>
        <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
    <?php endif; ?>
</div>

<h1>Security Code Management</h1>

<?php if (isset($success_message)): ?>
    <div class="success-message">
        <?= htmlspecialchars($success_message) ?>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="error-message">
        <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>

<div class="info-section" style="background-color: #e7f3ff; border: 1px solid #b3d9ff;">
    <h2>Current Security Code</h2>
    <?php if ($current_code): ?>
        <div style="font-size: 24px; font-weight: bold; color: #12044C; margin: 10px 0;">
            <?= htmlspecialchars($current_code) ?>
        </div>
    <?php else: ?>
        <p style="color: #666;">No security code is currently set.</p>
    <?php endif; ?>
</div>

<?php if ($current_code && !empty($current_code)): ?>
<div class="info-section" style="background-color: #f0f8ff; border: 1px solid #b3d9ff; text-align: center;">
    <h2>Security Code QR Code</h2>
    <p>Scan this QR code with a mobile device to store the security code for 3 years:</p>
    
    <?php
    // Create QR code data that will set a cookie on the mobile device with station information
    $current_directory = dirname($_SERVER['REQUEST_URI']);
    $qr_data = 'https://' . $_SERVER['HTTP_HOST'] . $current_directory . '/set_security_cookie.php?code=' . urlencode($current_code) . '&station_id=' . urlencode($station['id']) . '&station_name=' . urlencode($station['name']);
    
    // Generate the QR code
    $result = Builder::create()
        ->writer(new PngWriter())
        ->data($qr_data)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
        ->size(300)
        ->margin(10)
        ->build();

    // Get the QR code as a Base64-encoded string
    $qrcode_base64 = base64_encode($result->getString());
    ?>
    
    <div style="margin: 20px 0;">
        <img src="data:image/png;base64,<?= $qrcode_base64 ?>" alt="Security Code QR Code" style="border: 1px solid #ccc; border-radius: 5px;">
    </div>
    
    <div style="margin: 15px 0;">
        <a href="data:image/png;base64,<?= $qrcode_base64 ?>" download="security_code_qr_<?= preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($station['name'])) ?>.png" class="button touch-button" style="background-color: #28a745;">
            📱 Download QR Code
        </a>
    </div>
    
    <?php
    // Detect if user is on mobile device
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $is_mobile = preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', $user_agent);
    ?>
    
    <?php if (!$is_mobile): ?>
    <div style="margin: 15px 0; padding: 15px; background-color: #e8f4fd; border: 1px solid #b3d9ff; border-radius: 5px;">
        <p style="margin-bottom: 10px; font-weight: bold; color: #12044C;">For Desktop Users:</p>
        <a href="<?= htmlspecialchars($qr_data) ?>" target="_blank" class="button touch-button" style="background-color: #17a2b8; margin-bottom: 10px;">
            🔗 Click to Register Security Code
        </a>
        <p style="font-size: 12px; color: #666; margin: 0;">This link will open in a new tab and register the security code on this device for 3 years.</p>
    </div>
    <?php endif; ?>
    
    <div style="font-size: 14px; color: #666; margin-top: 15px;">
        <p><strong>Instructions:</strong></p>
        <ol style="text-align: left; display: inline-block;">
            <li>Download and print this QR code</li>
            <li>Scan with mobile device camera or QR code app</li>
            <li>The security code will be stored on the device for 3 years</li>
            <li>Use the mobile device to complete truck checks</li>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php if (!isset($error_message) || strpos($error_message, 'Settings table not found') === false): ?>
<div class="form-section">
    <h2>Set New Security Code</h2>
    <form method="POST">
        <div class="input-container">
            <label for="security_code">New Security Code:</label>
            <div class="input-with-button">
                <input type="text" name="security_code" id="security_code" placeholder="Enter new security code" required>
                <button type="button" onclick="generateRandomCode()" class="button touch-button generate-btn">🎲 Generate Random</button>
            </div>
            <small style="color: #666; margin-top: 5px; display: block;">
                💡 <strong>Tip:</strong> Click "Generate Random" for a secure 16-character alphanumeric code
            </small>
        </div>
        <div class="button-container">
            <button type="submit" name="set_code" class="button touch-button">Set Security Code</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="info-section" style="background-color: #fff3cd; border: 1px solid #ffeaa7;">
    <h3>About Security Codes</h3>
    <p>Security codes can be used for additional verification when accessing certain features of the system. The code is stored in the database and can be updated as needed.</p>
    <p><strong>Note:</strong> This security code is specific to <strong><?= htmlspecialchars($station['name']) ?></strong> station only.</p>
    <p><strong>Security Recommendation:</strong> Use a random 16-character alphanumeric code for maximum security. Avoid using predictable patterns or dictionary words.</p>
</div>

<div class="button-container" style="margin-top: 20px;">
    <a href="javascript:parent.location.href='admin.php?page=dashboard'" class="button touch-button">← Back to Admin</a>
</div>

<script>
function generateRandomCode() {
    // Generate a random 16-character alphanumeric code
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < 16; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    
    // Set the generated code in the input field
    document.getElementById('security_code').value = result;
    
    // Optional: Show a brief confirmation
    const button = document.querySelector('.generate-btn');
    const originalText = button.innerHTML;
    button.innerHTML = '✅ Generated!';
    button.style.backgroundColor = '#28a745';
    
    setTimeout(() => {
        button.innerHTML = originalText;
        button.style.backgroundColor = '#28a745';
    }, 1500);
}

// Optional: Generate a code automatically when the page loads if no current code exists
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!$current_code): ?>
    // Auto-generate a code suggestion for new setups
    generateRandomCode();
    <?php endif; ?>
});
</script>

<?php include 'templates/footer.php'; ?>

</body>
</html>
