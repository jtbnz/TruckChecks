<?php
include('config.php');
include('db.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
function logLoginAttempt($success) {
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
            (ip_address, user_agent, success, session_id, referer, accept_language, country, city, browser_info) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $browserInfo
        ]);
        
    } catch (Exception $e) {
        // Log error but don't break login process
        error_log("Login logging error: " . $e->getMessage());
    }
}

// Check if the user is already logged in
if (isset($_COOKIE['logged_in_' . DB_NAME]) && $_COOKIE['logged_in_' . DB_NAME] == 'true') {
    header('Location: admin.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($_POST['password'] === PASSWORD) {
        // Log successful login
        logLoginAttempt(true);
        
        // Set a cookie to remember the user for 90 days
        setcookie('logged_in_' . DB_NAME, 'true', time() + (90 * 24 * 60 * 60), "/");

        // Redirect to a protected page
        header('Location: admin.php');
        exit;
    } else {
        // Log failed login attempt
        logLoginAttempt(false);
        
        $error = "Incorrect password.";
    }
}

include 'templates/header.php';
?>

<style>
    .login-container {
        max-width: 400px;
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
    }

    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .login-container {
            width: 90%;
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
    }
</style>

<div class="login-container">
    <h2 class="login-title">Login</h2>
    
    <?php if (isset($error)): ?>
        <div class="error-message"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="post" action="" class="login-form">
        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" name="password" id="password" required>
        </div>
        <button type="submit" class="login-button">Login</button>
    </form>
</div>

<?php include 'templates/footer.php'; ?>
