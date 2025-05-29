<?php
// Authentication and session management for TruckChecks V4
// Handles both legacy password authentication and new user-based authentication

include_once('config.php');
include_once('db.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AuthManager {
    private $db;
    
    public function __construct() {
        $this->db = get_db_connection();
    }
    
    /**
     * Check if user is authenticated (supports both legacy and new auth)
     */
    public function isAuthenticated() {
        // Check for new user-based authentication
        if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
            return $this->validateUserSession();
        }
        
        // Check for legacy password-based authentication
        if (isset($_COOKIE['logged_in_' . DB_NAME]) && $_COOKIE['logged_in_' . DB_NAME] == 'true') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Validate user session token
     */
    private function validateUserSession() {
        try {
            $stmt = $this->db->prepare("
                SELECT us.*, u.username, u.role, u.is_active 
                FROM user_sessions us 
                JOIN users u ON us.user_id = u.id 
                WHERE us.session_token = ? AND us.expires_at > NOW() AND u.is_active = 1
            ");
            $stmt->execute([$_SESSION['session_token']]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                // Update last activity
                $this->updateSessionActivity($_SESSION['session_token']);
                return true;
            }
            
            // Invalid session, clean up
            $this->logout();
            return false;
            
        } catch (Exception $e) {
            error_log("Session validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authenticate user with username/password
     */
    public function authenticateUser($username, $password) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, password_hash, role, is_active 
                FROM users 
                WHERE username = ? AND is_active = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $this->createUserSession($user);
                $this->updateLastLogin($user['id']);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("User authentication error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authenticate with legacy password
     */
    public function authenticateLegacy($password) {
        if ($password === PASSWORD) {
            // Set legacy cookie
            setcookie('logged_in_' . DB_NAME, 'true', time() + (90 * 24 * 60 * 60), "/");
            return true;
        }
        return false;
    }
    
    /**
     * Create user session
     */
    private function createUserSession($user) {
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (90 * 24 * 60 * 60)); // 90 days
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_sessions 
                (user_id, session_token, expires_at, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'],
                $sessionToken,
                $expiresAt,
                $this->getRealIpAddr(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['session_token'] = $sessionToken;
            
            // Set cookie for convenience
            setcookie('user_session', $sessionToken, time() + (90 * 24 * 60 * 60), "/");
            
        } catch (Exception $e) {
            error_log("Session creation error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update last login timestamp
     */
    private function updateLastLogin($userId) {
        try {
            $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("Update last login error: " . $e->getMessage());
        }
    }
    
    /**
     * Update session activity
     */
    private function updateSessionActivity($sessionToken) {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_sessions 
                SET last_activity = NOW() 
                WHERE session_token = ?
            ");
            $stmt->execute([$sessionToken]);
        } catch (Exception $e) {
            error_log("Session activity update error: " . $e->getMessage());
        }
    }
    
    /**
     * Get current user
     */
    public function getCurrentUser() {
        if (isset($_SESSION['user_id'])) {
            try {
                $stmt = $this->db->prepare("
                    SELECT id, username, email, role, is_active, last_login 
                    FROM users 
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Get current user error: " . $e->getMessage());
                return null;
            }
        }
        
        // Legacy authentication - return pseudo user
        return [
            'id' => null,
            'username' => 'legacy_admin',
            'role' => 'superuser',
            'is_legacy' => true
        ];
    }
    
    /**
     * Get user's accessible stations
     */
    public function getUserStations($userId = null) {
        $userId = $userId ?? $_SESSION['user_id'] ?? null;
        
        if (!$userId) {
            // Legacy user has access to all stations
            try {
                $stmt = $this->db->prepare("SELECT * FROM stations ORDER BY name");
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Get all stations error: " . $e->getMessage());
                return [];
            }
        }
        
        try {
            $user = $this->getCurrentUser();
            if ($user && $user['role'] === 'superuser') {
                // Superusers have access to all stations
                $stmt = $this->db->prepare("SELECT * FROM stations ORDER BY name");
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Station admins only see their assigned stations
                $stmt = $this->db->prepare("
                    SELECT s.* 
                    FROM stations s 
                    JOIN user_stations us ON s.id = us.station_id 
                    WHERE us.user_id = ? 
                    ORDER BY s.name
                ");
                $stmt->execute([$userId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Get user stations error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Set current station in session
     */
    public function setCurrentStation($stationId) {
        $userStations = $this->getUserStations();
        $hasAccess = false;
        
        foreach ($userStations as $station) {
            if ($station['id'] == $stationId) {
                $hasAccess = true;
                break;
            }
        }
        
        if ($hasAccess) {
            $_SESSION['current_station_id'] = $stationId;
            
            // Update session record if user-based auth
            if (isset($_SESSION['session_token'])) {
                try {
                    $stmt = $this->db->prepare("
                        UPDATE user_sessions 
                        SET station_id = ? 
                        WHERE session_token = ?
                    ");
                    $stmt->execute([$stationId, $_SESSION['session_token']]);
                } catch (Exception $e) {
                    error_log("Update session station error: " . $e->getMessage());
                }
            }
            
            // Set long-term cookie for station preference
            setcookie('preferred_station', $stationId, time() + (365 * 24 * 60 * 60), "/");
            return true;
        }
        
        return false;
    }
    
    /**
     * Get current station
     */
    public function getCurrentStation() {
        // Check session first
        if (isset($_SESSION['current_station_id'])) {
            return $this->getStationById($_SESSION['current_station_id']);
        }
        
        // Check cookie preference
        if (isset($_COOKIE['preferred_station'])) {
            $station = $this->getStationById($_COOKIE['preferred_station']);
            if ($station) {
                $this->setCurrentStation($station['id']);
                return $station;
            }
        }
        
        // Default to first accessible station - use direct query to avoid redirect loops
        $user = $this->getCurrentUser();
        if ($user && $user['role'] === 'station_admin') {
            try {
                $stmt = $this->db->prepare("
                    SELECT s.* 
                    FROM stations s 
                    JOIN user_stations us ON s.id = us.station_id 
                    WHERE us.user_id = ? 
                    ORDER BY s.name 
                    LIMIT 1
                ");
                $stmt->execute([$user['id']]);
                $station = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($station) {
                    $this->setCurrentStation($station['id']);
                    return $station;
                }
            } catch (Exception $e) {
                error_log('Error getting station admin default station: ' . $e->getMessage());
            }
        } else {
            // For superusers, get all stations
            $stations = $this->getUserStations();
            if (!empty($stations)) {
                $this->setCurrentStation($stations[0]['id']);
                return $stations[0];
            }
        }
        
        return null;
    }
    
    /**
     * Get station by ID
     */
    private function getStationById($stationId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get station by ID error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if user has access to a specific station
     */
    public function hasStationAccess($stationId) {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        
        // Superusers have access to all stations
        if ($user['role'] === 'superuser') return true;
        
        // Check if user is assigned to this station
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM user_stations 
                WHERE user_id = ? AND station_id = ?
            ");
            $stmt->execute([$user['id'], $stationId]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get station-specific setting value
     */
    public function getStationSetting($stationId, $settingKey, $defaultValue = null) {
        try {
            $stmt = $this->db->prepare("
                SELECT setting_value, setting_type 
                FROM station_settings 
                WHERE station_id = ? AND setting_key = ?
            ");
            $stmt->execute([$stationId, $settingKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return $defaultValue;
            }
            
            // Convert value based on type
            switch ($result['setting_type']) {
                case 'boolean':
                    return $result['setting_value'] === 'true';
                case 'integer':
                    return (int)$result['setting_value'];
                case 'json':
                    return json_decode($result['setting_value'], true);
                default:
                    return $result['setting_value'];
            }
        } catch (Exception $e) {
            return $defaultValue;
        }
    }
    
    /**
     * Get all station settings as an associative array
     */
    public function getStationSettings($stationId) {
        try {
            $stmt = $this->db->prepare("
                SELECT setting_key, setting_value, setting_type 
                FROM station_settings 
                WHERE station_id = ?
            ");
            $stmt->execute([$stationId]);
            $settings = [];
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = $row['setting_value'];
                
                // Convert value based on type
                switch ($row['setting_type']) {
                    case 'boolean':
                        $value = $value === 'true';
                        break;
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                
                $settings[$row['setting_key']] = $value;
            }
            
            return $settings;
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Clean up user session
        if (isset($_SESSION['session_token'])) {
            try {
                $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE session_token = ?");
                $stmt->execute([$_SESSION['session_token']]);
            } catch (Exception $e) {
                error_log("Session cleanup error: " . $e->getMessage());
            }
        }
        
        // Clear session
        session_destroy();
        
        // Clear cookies
        setcookie('logged_in_' . DB_NAME, '', time() - 3600, "/");
        setcookie('user_session', '', time() - 3600, "/");
        
        // Note: Keep preferred_station cookie for convenience
    }
    
    /**
     * Get real IP address
     */
    private function getRealIpAddr() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    /**
     * Check if user is superuser
     */
    public function isSuperuser() {
        $user = $this->getCurrentUser();
        return $user && $user['role'] === 'superuser';
    }
    
    /**
     * Check if user is station admin
     */
    public function isStationAdmin() {
        $user = $this->getCurrentUser();
        return $user && ($user['role'] === 'station_admin' || $user['role'] === 'superuser');
    }
}

// Global auth instance
$auth = new AuthManager();

/**
 * Require authentication - use this at the top of protected pages
 */
function requireAuth() {
    global $auth;
    if (!$auth->isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Require station context - use this on pages that need a station selected
 */
function requireStation() {
    global $auth;
    requireAuth();
    
    $station = $auth->getCurrentStation();
    if (!$station) {
        header('Location: select_station.php');
        exit;
    }
    
    return $station;
}

/**
 * Require superuser access
 */
function requireSuperuser() {
    global $auth;
    requireAuth();
    
    if (!$auth->isSuperuser()) {
        header('Location: admin.php?error=access_denied');
        exit;
    }
}

/**
 * Get current station or redirect to selection
 */
function getCurrentStationOrRedirect() {
    global $auth;
    $station = $auth->getCurrentStation();
    if (!$station) {
        header('Location: select_station.php');
        exit;
    }
    return $station;
}

/**
 * Helper function to get station setting with fallback to config constants
 */
function getStationSetting($settingKey, $defaultValue = null) {
    global $auth;
    
    $station = $auth->getCurrentStation();
    if (!$station) {
        return $defaultValue;
    }
    
    return $auth->getStationSetting($station['id'], $settingKey, $defaultValue);
}

/**
 * Get current user
 */
function getCurrentUser() {
    global $auth;
    return $auth->getCurrentUser();
}

/**
 * Get user stations
 */
function getUserStations($userId = null) {
    global $auth;
    return $auth->getUserStations($userId);
}

/**
 * Set current station
 */
function setCurrentStation($stationId) {
    global $auth;
    return $auth->setCurrentStation($stationId);
}

/**
 * Get current station
 */
function getCurrentStation() {
    global $auth;
    return $auth->getCurrentStation();
}

/**
 * Check if user has station access
 */
function hasStationAccess($stationId) {
    global $auth;
    return $auth->hasStationAccess($stationId);
}
?>
