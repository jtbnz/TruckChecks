<?php
include_once('auth.php');
include_once('db.php');

// Handle AJAX station change request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_station') {
    header('Content-Type: application/json');
    
    try {
        requireAuth();
        $user = getCurrentUser();
        
        if ($user['role'] !== 'superuser') {
            throw new Exception('Only superusers can change stations');
        }
        
        $stationId = $_POST['station_id'] ?? '';
        if (empty($stationId)) {
            throw new Exception('Station ID is required');
        }
        
        if (setCurrentStation($stationId)) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Failed to set station');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

// Check if user can manage settings for this station
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    header('Location: select_station.php');
    exit;
}

$db = get_db_connection();
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    try {
        $db->beginTransaction();
        
        foreach ($_POST['settings'] as $key => $value) {
            // Validate and convert values based on type
            $settingType = $_POST['types'][$key] ?? 'string';
            
            switch ($settingType) {
                case 'boolean':
                    $value = isset($_POST['settings'][$key]) ? 'true' : 'false';
                    break;
                case 'integer':
                    $value = (int)$value;
                    if ($key === 'refresh_interval' && $value < 5000) {
                        throw new Exception("Refresh interval must be at least 5000ms (5 seconds)");
                    }
                    break;
                case 'string':
                    $value = trim($value);
                    break;
            }
            
            // Update or insert setting
            $stmt = $db->prepare("
                INSERT INTO station_settings (station_id, setting_key, setting_value, setting_type) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$station['id'], $key, $value, $settingType]);
        }
        
        $db->commit();
        $success = "Settings saved successfully!";
        
    } catch (Exception $e) {
        $db->rollback();
        $error = "Error saving settings: " . $e->getMessage();
    }
}

// Get current station settings
try {
    $stmt = $db->prepare("
        SELECT setting_key, setting_value, setting_type, description 
        FROM station_settings 
        WHERE station_id = ? 
        ORDER BY setting_key
    ");
    $stmt->execute([$station['id']]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row;
    }
} catch (Exception $e) {
    $error = "Error loading settings: " . $e->getMessage();
    $settings = [];
}

// Define available settings with defaults
$availableSettings = [
    'refresh_interval' => [
        'type' => 'integer',
        'default' => '30000',
        'description' => 'Page auto-refresh interval in milliseconds (minimum 5000)',
        'label' => 'Auto-Refresh Interval (ms)'
    ],
    'randomize_order' => [
        'type' => 'boolean',
        'default' => 'true',
        'description' => 'Randomize the order of locker items on check pages',
        'label' => 'Randomize Item Order'
    ],
    'is_demo' => [
        'type' => 'boolean',
        'default' => 'false',
        'description' => 'Enable demo mode for this station (shows demo banner)',
        'label' => 'Demo Mode'
    ],
    'ip_api_key' => [
        'type' => 'string',
        'default' => '',
        'description' => 'IP Geolocation API key for ipgeolocation.io (leave empty to disable)',
        'label' => 'IP Geolocation API Key'
    ]
];

// Ensure all settings exist with defaults
foreach ($availableSettings as $key => $config) {
    if (!isset($settings[$key])) {
        $settings[$key] = [
            'setting_key' => $key,
            'setting_value' => $config['default'],
            'setting_type' => $config['type'],
            'description' => $config['description']
        ];
    }
}

include 'templates/header.php';
?>

<style>
    .settings-container {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 15px;
        border-bottom: 2px solid #12044C;
    }

    .page-title {
        color: #12044C;
        margin: 0;
    }

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

    .settings-form {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .setting-group {
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }

    .setting-group:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }

    .setting-label {
        font-size: 16px;
        font-weight: bold;
        color: #333;
        margin-bottom: 5px;
        display: block;
    }

    .setting-description {
        font-size: 14px;
        color: #666;
        margin-bottom: 10px;
        font-style: italic;
    }

    .setting-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 14px;
        box-sizing: border-box;
    }

    .setting-checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 5px;
    }

    .setting-checkbox input[type="checkbox"] {
        width: auto;
        transform: scale(1.2);
    }

    .setting-checkbox label {
        margin: 0;
        font-weight: normal;
        cursor: pointer;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        font-size: 16px;
        transition: background-color 0.3s;
        margin-right: 10px;
    }

    .btn-primary {
        background-color: #12044C;
        color: white;
    }

    .btn-primary:hover {
        background-color: #0056b3;
    }

    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background-color: #545b62;
    }

    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
    }

    .alert-success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .alert-error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .alert-info {
        background-color: #cce7ff;
        border: 1px solid #b3d7ff;
        color: #004085;
    }

    .form-actions {
        text-align: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #eee;
    }

    .help-text {
        font-size: 12px;
        color: #888;
        margin-top: 5px;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .settings-container {
            padding: 10px;
        }

        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }

        .settings-form {
            padding: 20px;
        }
    }
</style>

<div class="settings-container">
    <div class="page-header">
        <h1 class="page-title">Station Settings</h1>
        <a href="admin.php" class="btn btn-secondary">← Back to Admin</a>
    </div>

    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if ($station['description']): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong>Station-Specific Settings:</strong> These settings apply only to <?= htmlspecialchars($station['name']) ?>. 
        Other stations have their own independent settings.
    </div>

    <form method="post" action="" class="settings-form">
        <?php foreach ($availableSettings as $key => $config): ?>
            <div class="setting-group">
                <label class="setting-label"><?= htmlspecialchars($config['label']) ?></label>
                <div class="setting-description"><?= htmlspecialchars($config['description']) ?></div>
                
                <input type="hidden" name="types[<?= $key ?>]" value="<?= $config['type'] ?>">
                
                <?php if ($config['type'] === 'boolean'): ?>
                    <div class="setting-checkbox">
                        <input type="checkbox" 
                               name="settings[<?= $key ?>]" 
                               id="setting_<?= $key ?>"
                               value="true"
                               <?= ($settings[$key]['setting_value'] === 'true') ? 'checked' : '' ?>>
                        <label for="setting_<?= $key ?>">Enable this setting</label>
                    </div>
                <?php elseif ($config['type'] === 'integer'): ?>
                    <input type="number" 
                           name="settings[<?= $key ?>]" 
                           id="setting_<?= $key ?>"
                           value="<?= htmlspecialchars($settings[$key]['setting_value']) ?>"
                           class="setting-input"
                           <?= $key === 'refresh_interval' ? 'min="5000" step="1000"' : '' ?>>
                    <?php if ($key === 'refresh_interval'): ?>
                        <div class="help-text">Minimum 5000ms (5 seconds). Current value refreshes the Status page every <?= number_format($settings[$key]['setting_value'] / 1000, 1) ?> seconds.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <input type="text" 
                           name="settings[<?= $key ?>]" 
                           id="setting_<?= $key ?>"
                           value="<?= htmlspecialchars($settings[$key]['setting_value']) ?>"
                           class="setting-input"
                           <?= $key === 'ip_api_key' ? 'placeholder="Enter your ipgeolocation.io API key"' : '' ?>>
                    <?php if ($key === 'ip_api_key'): ?>
                        <div class="help-text">Get your free API key from <a href="https://ipgeolocation.io/" target="_blank">ipgeolocation.io</a></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
            <a href="admin.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

    <div style="margin-top: 30px; padding: 20px; background-color: #f8f9fa; border-radius: 5px;">
        <h4 style="margin-top: 0; color: #12044C;">Setting Information</h4>
        <ul style="margin: 0; padding-left: 20px;">
            <li><strong>Auto-Refresh Interval:</strong> Controls how often the main truck status page refreshes automatically</li>
            <li><strong>Randomize Item Order:</strong> When enabled, items in locker check lists appear in random order</li>
            <li><strong>Demo Mode:</strong> Shows a demo banner and may modify behavior for demonstration purposes</li>
            <li><strong>IP Geolocation:</strong> Enables location tracking for login attempts (requires API key)</li>
        </ul>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
