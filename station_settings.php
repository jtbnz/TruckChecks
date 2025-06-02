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
        
        // Handle training_nights specially
        if (isset($_POST['training_days']) && is_array($_POST['training_days'])) {
            $_POST['settings']['training_nights'] = implode(',', $_POST['training_days']);
        } elseif (!isset($_POST['settings']['training_nights'])) {
            $_POST['settings']['training_nights'] = '';
        }
        
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

// Define available settings with defaults and categories
$availableSettings = [
    // General Settings
    'refresh_interval' => [
        'type' => 'integer',
        'default' => '30000',
        'description' => 'Page auto-refresh interval in milliseconds (minimum 5000)',
        'label' => 'Auto-Refresh Interval (ms)',
        'category' => 'general'
    ],
    'randomize_order' => [
        'type' => 'boolean',
        'default' => 'true',
        'description' => 'Randomize the order of locker items on check pages',
        'label' => 'Randomize Item Order',
        'category' => 'general'
    ],
    'ip_api_key' => [
        'type' => 'string',
        'default' => '',
        'description' => 'IP Geolocation API key for ipgeolocation.io (leave empty to disable)',
        'label' => 'IP Geolocation API Key',
        'category' => 'general'
    ],
    
    // Email Automation Settings (in order)
    'email_automation_enabled' => [
        'type' => 'boolean',
        'default' => 'true',
        'description' => 'Enable automated email sending for this station',
        'label' => 'Enable Email Automation',
        'category' => 'email'
    ],
    'send_email_check_time' => [
        'type' => 'string',
        'default' => '19:30',
        'description' => 'Time of day to send automated email checks (HH:MM format)',
        'label' => 'Email Check Time',
        'category' => 'email',
        'depends_on' => 'email_automation_enabled'
    ],
    'training_nights' => [
        'type' => 'string',
        'default' => '1,2',
        'description' => 'Training nights as comma-separated day numbers (1=Monday, 7=Sunday)',
        'label' => 'Training Nights',
        'category' => 'email',
        'depends_on' => 'email_automation_enabled'
    ],
    'alternate_training_night_enabled' => [
        'type' => 'boolean',
        'default' => 'true',
        'description' => 'Enable alternate training night for public holidays',
        'label' => 'Enable Alternate Training Night',
        'category' => 'email',
        'depends_on' => 'email_automation_enabled'
    ],
    'alternate_training_night' => [
        'type' => 'string',
        'default' => '2',
        'description' => 'Alternate training night when regular night falls on public holiday (1=Monday, 7=Sunday)',
        'label' => 'Alternate Training Night',
        'category' => 'email',
        'depends_on' => 'alternate_training_night_enabled'
    ],
    
    // Super Admin Settings
    'is_demo' => [
        'type' => 'boolean',
        'default' => 'false',
        'description' => 'Enable demo mode for this station (shows demo banner)',
        'label' => 'Demo Mode',
        'category' => 'superadmin'
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

/**
 * Render a setting field
 */
function renderSettingField($key, $config, $settings) {
    $dependsOn = $config['depends_on'] ?? null;
    $dependsClass = $dependsOn ? "depends-on-{$dependsOn}" : '';
    
    echo '<div class="setting-group ' . $dependsClass . '">';
    echo '<label class="setting-label">' . htmlspecialchars($config['label']) . '</label>';
    echo '<div class="setting-description">' . htmlspecialchars($config['description']) . '</div>';
    echo '<input type="hidden" name="types[' . $key . ']" value="' . $config['type'] . '">';
    
    if ($config['type'] === 'boolean') {
        echo '<div class="setting-checkbox">';
        echo '<input type="checkbox" name="settings[' . $key . ']" id="setting_' . $key . '" value="true"';
        echo ($settings[$key]['setting_value'] === 'true') ? ' checked' : '';
        echo '>';
        echo '<label for="setting_' . $key . '">Enable this setting</label>';
        echo '</div>';
    } elseif ($config['type'] === 'integer') {
        echo '<input type="number" name="settings[' . $key . ']" id="setting_' . $key . '"';
        echo ' value="' . htmlspecialchars($settings[$key]['setting_value']) . '" class="setting-input"';
        if ($key === 'refresh_interval') {
            echo ' min="5000" step="1000"';
        }
        echo '>';
        if ($key === 'refresh_interval') {
            echo '<div class="help-text">Minimum 5000ms (5 seconds). Current value refreshes the Status page every ' . number_format($settings[$key]['setting_value'] / 1000, 1) . ' seconds.</div>';
        }
    } elseif ($key === 'send_email_check_time') {
        echo '<input type="time" name="settings[' . $key . ']" id="setting_' . $key . '"';
        echo ' value="' . htmlspecialchars($settings[$key]['setting_value']) . '" class="setting-input">';
        echo '<div class="help-text">Time when automated emails will be sent (24-hour format)</div>';
    } elseif ($key === 'training_nights') {
        echo '<div class="checkbox-group">';
        $days = ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'];
        $selectedDays = explode(',', $settings[$key]['setting_value']);
        foreach ($days as $dayNum => $dayName) {
            echo '<label class="day-checkbox">';
            echo '<input type="checkbox" name="training_days[]" value="' . $dayNum . '"';
            echo in_array($dayNum, $selectedDays) ? ' checked' : '';
            echo '>';
            echo '<span>' . $dayName . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<input type="hidden" name="settings[' . $key . ']" id="training_nights_hidden" value="' . htmlspecialchars($settings[$key]['setting_value']) . '">';
        echo '<div class="help-text">Select which nights are training nights for this station</div>';
    } elseif ($key === 'alternate_training_night') {
        echo '<select name="settings[' . $key . ']" id="setting_' . $key . '" class="setting-input">';
        $days = ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'];
        foreach ($days as $dayNum => $dayName) {
            echo '<option value="' . $dayNum . '"';
            echo ($settings[$key]['setting_value'] == $dayNum) ? ' selected' : '';
            echo '>' . $dayName . '</option>';
        }
        echo '</select>';
        echo '<div class="help-text">Backup training night when regular training night falls on a public holiday</div>';
    } else {
        echo '<input type="text" name="settings[' . $key . ']" id="setting_' . $key . '"';
        echo ' value="' . htmlspecialchars($settings[$key]['setting_value']) . '" class="setting-input"';
        if ($key === 'ip_api_key') {
            echo ' placeholder="Enter your ipgeolocation.io API key"';
        }
        echo '>';
        if ($key === 'ip_api_key') {
            echo '<div class="help-text">Get your free API key from <a href="https://ipgeolocation.io/" target="_blank">ipgeolocation.io</a></div>';
        }
    }
    
    echo '</div>';
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

    .settings-section {
        margin-bottom: 40px;
        padding-bottom: 30px;
        border-bottom: 2px solid #e9ecef;
    }

    .settings-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }

    .section-title {
        color: #12044C;
        font-size: 20px;
        margin: 0 0 20px 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #dee2e6;
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

    .checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 10px;
        margin: 10px 0;
    }

    .day-checkbox {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 5px;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .day-checkbox:hover {
        background-color: #f8f9fa;
    }

    .day-checkbox input[type="checkbox"] {
        margin: 0;
    }

    .day-checkbox input[type="checkbox"]:checked + span {
        font-weight: bold;
        color: #12044C;
    }

    /* Disabled state styling */
    .setting-input:disabled {
        background-color: #f8f9fa;
        color: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .setting-group.disabled {
        opacity: 0.6;
    }

    .setting-group.disabled .setting-label {
        color: #6c757d;
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
        <!-- General Settings Section -->
        <div class="settings-section">
            <h3 class="section-title">General Settings</h3>
            <?php 
            foreach ($availableSettings as $key => $config) {
                if ($config['category'] !== 'general') continue;
                renderSettingField($key, $config, $settings);
            }
            ?>
        </div>

        <!-- Email Automation Settings Section -->
        <div class="settings-section">
            <h3 class="section-title">Email Automation</h3>
            <?php 
            foreach ($availableSettings as $key => $config) {
                if ($config['category'] !== 'email') continue;
                renderSettingField($key, $config, $settings);
            }
            ?>
        </div>

        <!-- Super Admin Settings Section -->
        <?php if ($user['role'] === 'superuser'): ?>
        <div class="settings-section">
            <h3 class="section-title">Super Admin Settings</h3>
            <?php 
            foreach ($availableSettings as $key => $config) {
                if ($config['category'] !== 'superadmin') continue;
                renderSettingField($key, $config, $settings);
            }
            ?>
        </div>
        <?php endif; ?>

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

<script>
// Handle email automation enable/disable functionality
function toggleEmailAutomation() {
    const emailAutomationCheckbox = document.getElementById('setting_email_automation_enabled');
    const dependentElements = document.querySelectorAll('.depends-on-email_automation_enabled');
    
    if (emailAutomationCheckbox) {
        const isEnabled = emailAutomationCheckbox.checked;
        
        dependentElements.forEach(function(element) {
            const inputs = element.querySelectorAll('input, select, .day-checkbox input');
            
            if (isEnabled) {
                element.classList.remove('disabled');
                inputs.forEach(function(input) {
                    input.disabled = false;
                });
            } else {
                element.classList.add('disabled');
                inputs.forEach(function(input) {
                    input.disabled = true;
                });
            }
        });
        
        // Also trigger alternate training night toggle
        toggleAlternateTrainingNight();
    }
}

// Handle alternate training night enable/disable functionality
function toggleAlternateTrainingNight() {
    const emailAutomationCheckbox = document.getElementById('setting_email_automation_enabled');
    const enableCheckbox = document.getElementById('setting_alternate_training_night_enabled');
    const alternateSelect = document.getElementById('setting_alternate_training_night');
    const settingGroup = alternateSelect ? alternateSelect.closest('.setting-group') : null;
    
    if (enableCheckbox && alternateSelect && settingGroup) {
        // Check if email automation is enabled first
        const emailAutomationEnabled = emailAutomationCheckbox ? emailAutomationCheckbox.checked : true;
        
        if (emailAutomationEnabled && enableCheckbox.checked) {
            alternateSelect.disabled = false;
            settingGroup.classList.remove('disabled');
        } else {
            alternateSelect.disabled = true;
            settingGroup.classList.add('disabled');
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize email automation toggle
    toggleEmailAutomation();
    
    // Add event listeners
    const emailAutomationCheckbox = document.getElementById('setting_email_automation_enabled');
    if (emailAutomationCheckbox) {
        emailAutomationCheckbox.addEventListener('change', toggleEmailAutomation);
    }
    
    const alternateTrainingNightCheckbox = document.getElementById('setting_alternate_training_night_enabled');
    if (alternateTrainingNightCheckbox) {
        alternateTrainingNightCheckbox.addEventListener('change', toggleAlternateTrainingNight);
    }
});
</script>

<?php include 'templates/footer.php'; ?>
