<?php
include_once('auth.php');

// Function to get real IP address
function getRealIpAddr() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {   // Check IP from shared internet
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {   // Check IP passed from proxy
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

// Function to get location info from IP using ipgeolocation.io API
function getLocationFromIP($ip) {
    $location = ['country' => '', 'city' => ''];
    
    // Skip location lookup for local IPs
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        $location['country'] = 'Local Network';
        $location['city'] = 'Local';
        return $location;
    }
    
    // Check if API key is defined and not empty
    if (!defined('IP_API_KEY') || empty(IP_API_KEY)) {
        return $location; // Return empty location if no API key
    }
    
    try {
        // Build API URL
        $api_url = "https://api.ipgeolocation.io/ipgeo?apiKey=" . urlencode(IP_API_KEY) . "&ip=" . urlencode($ip);
        
        // Set up context for the HTTP request
        $context = stream_context_create([
            'http' => [
                'timeout' => 5, // 5 second timeout
                'user_agent' => 'TruckChecks/1.0'
            ]
        ]);
        
        // Make the API request
        $response = file_get_contents($api_url, false, $context);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            
            if ($data && !isset($data['message'])) { // Check if response is valid and no error message
                $location['country'] = $data['country_name'] ?? '';
                $location['city'] = $data['city'] ?? '';
                
                // Add additional location data if available
                if (isset($data['state_prov']) && !empty($data['state_prov'])) {
                    $location['city'] = $data['city'] . ', ' . $data['state_prov'];
                }
            }
        }
    } catch (Exception $e) {
        // Log error but don't break the login process
        error_log("IP Geolocation API error: " . $e->getMessage());
    }
    
    return $location;
}

// Function to parse browser info
function getBrowserInfo($userAgent) {
    $browser_info = [];
    
    // Detect browser
    if (strpos($userAgent, 'Chrome') !== false) {
        $browser_info['browser'] = 'Chrome';
    } elseif (strpos($userAgent, 'Firefox') !== false) {
        $browser_info['browser'] = 'Firefox';
    } elseif (strpos($userAgent, 'Safari') !== false) {
        $browser_info['browser'] = 'Safari';
    } elseif (strpos($userAgent, 'Edge') !== false) {
        $browser_info['browser'] = 'Edge';
    } else {
        $browser_info['browser'] = 'Unknown';
    }
    
    // Detect OS
    if (strpos($userAgent, 'Windows') !== false) {
        $browser_info['os'] = 'Windows';
    } elseif (strpos($userAgent, 'Mac') !== false) {
        $browser_info['os'] = 'macOS';
    } elseif (strpos($userAgent, 'Linux') !== false) {
        $browser_info['os'] = 'Linux';
    } elseif (strpos($userAgent, 'Android') !== false) {
        $browser_info['os'] = 'Android';
    } elseif (strpos($userAgent, 'iOS') !== false) {
        $browser_info['os'] = 'iOS';
    } else {
        $browser_info['os'] = 'Unknown';
    }
    
    // Detect if mobile
    $browser_info['is_mobile'] = (strpos($userAgent, 'Mobile') !== false || strpos($userAgent, 'Android') !== false);
    
    return json_encode($browser_info);
}

// Function to log login attempt
function logLoginAttempt($success, $userId = null) {
    try {
        $pdo = get_db_connection();
        
        $ip = getRealIpAddr();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $sessionId = session_id();
        
        $location = getLocationFromIP($ip);
        $browserInfo = getBrowserInfo($userAgent);
        
        $stmt = $pdo->prepare("
            INSERT INTO login_log 
            (ip_address, user_agent, success, session_id, referer, accept_language, country, city, browser_info, user_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $ip,
            $userAgent,
            $success ? 1 : 0,
            $sessionId,
            $referer,
            $acceptLanguage,
            $location['country'],
            $location['city'],
            $browserInfo,
            $userId
        ]);
        
    } catch (Exception $e) {
        // Log error but don't break login process
        error_log("Login logging error: " . $e->getMessage());
    }
}

// Check if the user is already logged in
if ($auth->isAuthenticated()) {
    // Redirect based on user type
    $user = $auth->getCurrentUser();
    if ($user && isset($user['is_legacy']) && $user['is_legacy']) {
        header('Location: admin.php');
    } else {
        // Check if user needs to select a station
        $station = $auth->getCurrentStation();
        if (!$station) {
            header('Location: select_station.php?redirect=admin.php');
        } else {
            header('Location: admin.php');
        }
    }
    exit;
}

$error = '';
$loginMode = 'auto'; // auto, legacy, user

// Detect login mode based on form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        // User-based authentication
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        if ($auth->authenticateUser($username, $password)) {
            $user = $auth->getCurrentUser();
            logLoginAttempt(true, $user['id']);
            
            // Redirect to station selection or admin
            $station = $auth->getCurrentStation();
            if (!$station) {
                header('Location: select_station.php?redirect=admin.php');
            } else {
                header('Location: admin.php');
            }
            exit;
        } else {
            logLoginAttempt(false);
            $error = "Invalid username or password.";
        }
    } elseif (isset($_POST['password'])) {
        // Legacy password authentication
        $password = $_POST['password'];
        
        if ($auth->authenticateLegacy($password)) {
            logLoginAttempt(true);
            header('Location: admin.php');
            exit;
        } else {
            logLoginAttempt(false);
            $error = "Incorrect password.";
        }
    }
}

// Check if we should show user login form (if users table exists)
$showUserLogin = false;
try {
    $db = get_db_connection();
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $userCount = $stmt->fetchColumn();
    $showUserLogin = $userCount > 0;
} catch (Exception $e) {
    // Users table doesn't exist, use legacy mode only
    $showUserLogin = false;
}

include 'templates/header.php';
?>

<style>
    .login-container {
        max-width: 450px;
        margin: 40px auto;
        padding: 20px;
        background-color: #f9f9f9;
        border-radius: 10px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .login-title {
        text-align: center;
        margin-bottom: 20px;
        color: #12044C;
    }

    .login-tabs {
        display: flex;
        margin-bottom: 20px;
        border-bottom: 1px solid #ddd;
    }

    .login-tab {
        flex: 1;
        padding: 10px;
        text-align: center;
        cursor: pointer;
        background-color: #e9ecef;
        border: none;
        border-bottom: 2px solid transparent;
        transition: all 0.3s;
    }

    .login-tab.active {
        background-color: white;
        border-bottom-color: #12044C;
        color: #12044C;
        font-weight: bold;
    }

    .login-tab:hover {
        background-color: #f8f9fa;
    }

    .login-form {
        display: flex;
        flex-direction: column;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }

    .form-group input {
        width: 100%;
        padding: 12px;
        font-size: 16px;
        border: 1px solid #ccc;
        border-radius: 5px;
        box-sizing: border-box;
    }

    .login-button {
        padding: 15px;
        background-color: #12044C;
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        transition: background-color 0.3s;
        margin-top: 10px;
    }

    .login-button:hover {
        background-color: #0056b3;
    }

    .error-message {
        color: red;
        text-align: center;
        margin-bottom: 15px;
        padding: 10px;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        border-radius: 5px;
    }

    .login-mode {
        display: none;
    }

    .login-mode.active {
        display: block;
    }

    .login-info {
        margin-top: 20px;
        padding: 15px;
        background-color: #e9ecef;
        border-radius: 5px;
        font-size: 14px;
        color: #666;
    }

    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .login-container {
            width: 95%;
            margin: 20px auto;
            padding: 15px;
        }

        .form-group input {
            padding: 15px;
            font-size: 18px;
        }

        .login-button {
            padding: 18px;
            font-size: 18px;
        }

        .login-tab {
            font-size: 14px;
            padding: 8px;
        }
    }
</style>

<div class="login-container">
    <h2 class="login-title">Login</h2>
    
    <?php if ($error): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($showUserLogin): ?>
        <!-- Login Mode Tabs -->
        <div class="login-tabs">
            <button class="login-tab active" onclick="switchLoginMode('user')">User Login</button>
            <button class="login-tab" onclick="switchLoginMode('legacy')">Legacy Login</button>
        </div>
        
        <!-- User Login Form -->
        <div class="login-mode active" id="userLogin">
            <form method="post" action="" class="login-form">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" name="username" id="username" required>
                </div>
                <div class="form-group">
                    <label for="user_password">Password:</label>
                    <input type="password" name="password" id="user_password" required>
                </div>
                <button type="submit" class="login-button">Login</button>
            </form>
        </div>
        
        <!-- Legacy Login Form -->
        <div class="login-mode" id="legacyLogin">
            <form method="post" action="" class="login-form">
                <div class="form-group">
                    <label for="legacy_password">Admin Password:</label>
                    <input type="password" name="password" id="legacy_password" required>
                </div>
                <button type="submit" class="login-button">Login</button>
            </form>
            
            <div class="login-info">
                <strong>Legacy Mode:</strong> Use the original admin password from your configuration.
            </div>
        </div>
    <?php else: ?>
        <!-- Legacy Only Login Form -->
        <form method="post" action="" class="login-form">
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" name="password" id="password" required>
            </div>
            <button type="submit" class="login-button">Login</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($showUserLogin): ?>
<script>
function switchLoginMode(mode) {
    // Update tabs
    document.querySelectorAll('.login-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Update forms
    document.querySelectorAll('.login-mode').forEach(form => {
        form.classList.remove('active');
    });
    
    if (mode === 'user') {
        document.getElementById('userLogin').classList.add('active');
    } else {
        document.getElementById('legacyLogin').classList.add('active');
    }
}
</script>
<?php endif; ?>

<?php include 'templates/footer.php'; ?>
