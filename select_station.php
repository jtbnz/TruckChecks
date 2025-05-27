<?php
include_once('auth.php');

// Require authentication but not station context
requireAuth();

$user = $auth->getCurrentUser();
$stations = $auth->getUserStations();
$error = '';
$success = '';

// Handle station selection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['station_id'])) {
    $stationId = (int)$_POST['station_id'];
    
    if ($auth->setCurrentStation($stationId)) {
        $success = "Station selected successfully!";
        // Redirect to intended page or admin
        $redirect = $_GET['redirect'] ?? 'admin.php';
        header("Location: $redirect");
        exit;
    } else {
        $error = "You don't have access to the selected station.";
    }
}

// Handle AJAX request for station change
if (isset($_GET['ajax']) && $_GET['ajax'] === 'change_station') {
    header('Content-Type: application/json');
    
    if (isset($_POST['station_id'])) {
        $stationId = (int)$_POST['station_id'];
        
        if ($auth->setCurrentStation($stationId)) {
            echo json_encode(['success' => true, 'message' => 'Station changed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Access denied to selected station']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No station selected']);
    }
    exit;
}

include 'templates/header.php';
?>

<style>
    .station-selector {
        max-width: 600px;
        margin: 40px auto;
        padding: 20px;
        background-color: #f9f9f9;
        border-radius: 10px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .station-title {
        text-align: center;
        margin-bottom: 20px;
        color: #12044C;
    }

    .user-info {
        text-align: center;
        margin-bottom: 30px;
        padding: 15px;
        background-color: #e9ecef;
        border-radius: 5px;
    }

    .station-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .station-card {
        padding: 20px;
        background-color: white;
        border: 2px solid #ddd;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
    }

    .station-card:hover {
        border-color: #12044C;
        background-color: #f8f9fa;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .station-card.selected {
        border-color: #12044C;
        background-color: #12044C;
        color: white;
    }

    .station-name {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 8px;
    }

    .station-description {
        font-size: 14px;
        color: #666;
        margin-bottom: 15px;
    }

    .station-card.selected .station-description {
        color: #ccc;
    }

    .station-stats {
        font-size: 12px;
        color: #888;
    }

    .station-card.selected .station-stats {
        color: #ddd;
    }

    .select-button {
        width: 100%;
        padding: 15px;
        background-color: #12044C;
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        transition: background-color 0.3s;
        margin-top: 20px;
    }

    .select-button:hover {
        background-color: #0056b3;
    }

    .select-button:disabled {
        background-color: #ccc;
        cursor: not-allowed;
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

    .success-message {
        color: green;
        text-align: center;
        margin-bottom: 15px;
        padding: 10px;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 5px;
    }

    .no-stations {
        text-align: center;
        padding: 40px;
        color: #666;
    }

    .back-link {
        text-align: center;
        margin-top: 20px;
    }

    .back-link a {
        color: #12044C;
        text-decoration: none;
    }

    .back-link a:hover {
        text-decoration: underline;
    }

    /* Mobile-specific styles */
    @media (max-width: 768px) {
        .station-selector {
            width: 95%;
            margin: 20px auto;
            padding: 15px;
        }

        .station-grid {
            grid-template-columns: 1fr;
        }

        .station-card {
            padding: 15px;
        }

        .select-button {
            padding: 18px;
            font-size: 18px;
        }
    }
</style>

<div class="station-selector">
    <h2 class="station-title">Select Station</h2>
    
    <div class="user-info">
        <strong>Welcome, <?= htmlspecialchars($user['username']) ?></strong><br>
        <small>Role: <?= htmlspecialchars(ucfirst($user['role'])) ?></small>
        <?php if (isset($user['is_legacy']) && $user['is_legacy']): ?>
            <br><small style="color: #666;">(Legacy Authentication)</small>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success-message"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (empty($stations)): ?>
        <div class="no-stations">
            <h3>No Stations Available</h3>
            <p>You don't have access to any stations. Please contact your administrator.</p>
        </div>
    <?php else: ?>
        <form method="post" action="" id="stationForm">
            <div class="station-grid">
                <?php foreach ($stations as $station): ?>
                    <?php
                    // Get station statistics
                    try {
                        $stmt = $auth->db->prepare("
                            SELECT COUNT(*) as truck_count 
                            FROM trucks 
                            WHERE station_id = ?
                        ");
                        $stmt->execute([$station['id']]);
                        $truckCount = $stmt->fetchColumn();
                    } catch (Exception $e) {
                        $truckCount = 0;
                    }
                    ?>
                    <div class="station-card" onclick="selectStation(<?= $station['id'] ?>)" data-station-id="<?= $station['id'] ?>">
                        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
                        <?php if ($station['description']): ?>
                            <div class="station-description"><?= htmlspecialchars($station['description']) ?></div>
                        <?php endif; ?>
                        <div class="station-stats">
                            <?= $truckCount ?> truck<?= $truckCount != 1 ? 's' : '' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="station_id" id="selectedStationId" value="">
            <button type="submit" class="select-button" id="selectButton" disabled>
                Select Station
            </button>
        </form>
    <?php endif; ?>

    <div class="back-link">
        <a href="logout.php">← Logout</a>
    </div>
</div>

<script>
let selectedStationId = null;

function selectStation(stationId) {
    // Remove previous selection
    document.querySelectorAll('.station-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Add selection to clicked card
    const selectedCard = document.querySelector(`[data-station-id="${stationId}"]`);
    selectedCard.classList.add('selected');
    
    // Update form
    selectedStationId = stationId;
    document.getElementById('selectedStationId').value = stationId;
    document.getElementById('selectButton').disabled = false;
}

// Auto-select if only one station
<?php if (count($stations) === 1): ?>
    selectStation(<?= $stations[0]['id'] ?>);
<?php endif; ?>
</script>

<?php include 'templates/footer.php'; ?>
