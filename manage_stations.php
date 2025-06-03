<?php
include_once('auth.php');

// Require superuser access
requireSuperuser();

$db = get_db_connection();
$error = '';
$success = '';

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_station_users') {
        $stationId = (int)$_GET['station_id'];
        
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.username, u.email, u.role, u.last_login,
                       us.created_at as assigned_at
                FROM users u
                JOIN user_stations us ON u.id = us.user_id
                WHERE us.station_id = ? AND u.is_active = 1
                ORDER BY u.username
            ");
            $stmt->execute([$stationId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'get_station_trucks') {
        $stationId = (int)$_GET['station_id'];
        
        try {
            $stmt = $db->prepare("
                SELECT t.id, t.name, t.relief,
                       COUNT(DISTINCT l.id) as locker_count,
                       COUNT(DISTINCT i.id) as item_count
                FROM trucks t
                LEFT JOIN lockers l ON t.id = l.truck_id
                LEFT JOIN items i ON l.id = i.locker_id
                WHERE t.station_id = ?
                GROUP BY t.id, t.name, t.relief
                ORDER BY t.name
            ");
            $stmt->execute([$stationId]);
            $trucks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'trucks' => $trucks]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_station'])) {
        $name = trim($_POST['station_name']);
        $description = trim($_POST['station_description']);
        
        if (empty($name)) {
            $error = "Station name is required.";
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO stations (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
                $success = "Station added successfully.";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "A station with this name already exists.";
                } else {
                    $error = "Error adding station: " . $e->getMessage();
                }
            }
        }
    }
    
    if (isset($_POST['edit_station'])) {
        $stationId = (int)$_POST['station_id'];
        $name = trim($_POST['station_name']);
        $description = trim($_POST['station_description']);
        
        if (empty($name)) {
            $error = "Station name is required.";
        } else {
            try {
                $stmt = $db->prepare("UPDATE stations SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $description, $stationId]);
                $success = "Station updated successfully.";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "A station with this name already exists.";
                } else {
                    $error = "Error updating station: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle station deletion
if (isset($_GET['delete_station'])) {
    $stationId = (int)$_GET['delete_station'];
    
    try {
        // Check if station has trucks
        $stmt = $db->prepare("SELECT COUNT(*) FROM trucks WHERE station_id = ?");
        $stmt->execute([$stationId]);
        $truckCount = $stmt->fetchColumn();
        
        if ($truckCount > 0) {
            $error = "Cannot delete station: it has $truckCount truck(s) assigned. Please reassign or delete the trucks first.";
        } else {
            // Delete user assignments first
            $stmt = $db->prepare("DELETE FROM user_stations WHERE station_id = ?");
            $stmt->execute([$stationId]);
            
            // Delete station
            $stmt = $db->prepare("DELETE FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            
            $success = "Station deleted successfully.";
        }
    } catch (Exception $e) {
        $error = "Error deleting station: " . $e->getMessage();
    }
}

// Get all stations
try {
    $stmt = $db->prepare("
        SELECT s.*, 
               COUNT(DISTINCT t.id) as truck_count,
               COUNT(DISTINCT us.user_id) as user_count
        FROM stations s
        LEFT JOIN trucks t ON s.id = t.station_id
        LEFT JOIN user_stations us ON s.id = us.station_id
        GROUP BY s.id, s.name, s.description, s.created_at, s.updated_at
        ORDER BY s.name
    ");
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error loading stations: " . $e->getMessage();
    $stations = [];
}

include 'templates/header.php';
?>

<style>
    .management-container {
        max-width: 1200px;
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

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        font-size: 14px;
        transition: background-color 0.3s;
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

    .btn-danger {
        background-color: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background-color: #c82333;
    }

    .btn-sm {
        padding: 5px 10px;
        font-size: 12px;
    }

    .stations-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .station-card {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .station-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .station-name {
        font-size: 18px;
        font-weight: bold;
        color: #12044C;
        margin: 0;
    }

    .station-actions {
        display: flex;
        gap: 5px;
    }

    .station-description {
        color: #666;
        margin-bottom: 15px;
        font-style: italic;
    }

    .station-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 15px;
    }

    .stat-item {
        text-align: center;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }

    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #12044C;
    }

    .stat-label {
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
    }

    .station-details {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #eee;
    }

    .detail-section {
        margin-bottom: 10px;
    }

    .detail-title {
        font-weight: bold;
        color: #333;
        margin-bottom: 5px;
    }

    .detail-content {
        display: none;
        background-color: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        max-height: 200px;
        overflow-y: auto;
    }

    .detail-content.show {
        display: block;
    }

    .form-container {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
    }

    .form-title {
        color: #12044C;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #333;
    }

    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 14px;
        box-sizing: border-box;
    }

    .form-group textarea {
        height: 80px;
        resize: vertical;
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

    .loading {
        text-align: center;
        padding: 20px;
        color: #666;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        .management-container {
            padding: 10px;
        }

        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }

        .stations-grid {
            grid-template-columns: 1fr;
        }

        .station-header {
            flex-direction: column;
            gap: 10px;
        }

        .station-actions {
            justify-content: center;
        }

        .station-stats {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="management-container">
    <div class="page-header">
        <h1 class="page-title">Station Management</h1>
        <a href="javascript:parent.location.href='admin.php?page=dashboard'" class="btn btn-secondary">← Back to Admin</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Add Station Form -->
    <div class="form-container">
        <h3 class="form-title">Add New Station</h3>
        <form method="post" action="">
            <div class="form-group">
                <label for="station_name">Station Name:</label>
                <input type="text" name="station_name" id="station_name" required>
            </div>
            <div class="form-group">
                <label for="station_description">Description:</label>
                <textarea name="station_description" id="station_description" placeholder="Optional description"></textarea>
            </div>
            <button type="submit" name="add_station" class="btn btn-primary">Add Station</button>
        </form>
    </div>

    <!-- Stations Grid -->
    <div class="stations-grid">
        <?php foreach ($stations as $station): ?>
            <div class="station-card">
                <div class="station-header">
                    <h3 class="station-name"><?= htmlspecialchars($station['name']) ?></h3>
                    <div class="station-actions">
                        <button class="btn btn-primary btn-sm" onclick="editStation(<?= $station['id'] ?>, '<?= htmlspecialchars($station['name']) ?>', '<?= htmlspecialchars($station['description']) ?>')">Edit</button>
                        <a href="?delete_station=<?= $station['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this station? This action cannot be undone.')">Delete</a>
                    </div>
                </div>

                <?php if ($station['description']): ?>
                    <div class="station-description"><?= htmlspecialchars($station['description']) ?></div>
                <?php endif; ?>

                <div class="station-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?= $station['truck_count'] ?></div>
                        <div class="stat-label">Trucks</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= $station['user_count'] ?></div>
                        <div class="stat-label">Users</div>
                    </div>
                </div>

                <div class="station-details">
                    <div class="detail-section">
                        <div class="detail-title">
                            <a href="#" onclick="toggleDetails('users-<?= $station['id'] ?>', <?= $station['id'] ?>); return false;">
                                View Users (<?= $station['user_count'] ?>)
                            </a>
                        </div>
                        <div class="detail-content" id="users-<?= $station['id'] ?>">
                            <div class="loading">Loading users...</div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <div class="detail-title">
                            <a href="#" onclick="toggleDetails('trucks-<?= $station['id'] ?>', <?= $station['id'] ?>); return false;">
                                View Trucks (<?= $station['truck_count'] ?>)
                            </a>
                        </div>
                        <div class="detail-content" id="trucks-<?= $station['id'] ?>">
                            <div class="loading">Loading trucks...</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 15px; font-size: 12px; color: #666;">
                    Created: <?= date('M j, Y', strtotime($station['created_at'])) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($stations)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <h3>No stations found</h3>
            <p>Add your first station using the form above.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Edit Station Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 500px;">
        <h3 style="margin-top: 0; color: #12044C;">Edit Station</h3>
        <form method="post" action="">
            <input type="hidden" name="station_id" id="edit_station_id">
            <div class="form-group">
                <label for="edit_station_name">Station Name:</label>
                <input type="text" name="station_name" id="edit_station_name" required>
            </div>
            <div class="form-group">
                <label for="edit_station_description">Description:</label>
                <textarea name="station_description" id="edit_station_description"></textarea>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="edit_station" class="btn btn-primary">Update Station</button>
            </div>
        </form>
    </div>
</div>

<script>
function editStation(id, name, description) {
    document.getElementById('edit_station_id').value = id;
    document.getElementById('edit_station_name').value = name;
    document.getElementById('edit_station_description').value = description;
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function toggleDetails(elementId, stationId) {
    const element = document.getElementById(elementId);
    
    if (element.classList.contains('show')) {
        element.classList.remove('show');
        return;
    }
    
    element.classList.add('show');
    
    // Load data if not already loaded
    if (element.innerHTML.includes('Loading')) {
        if (elementId.startsWith('users-')) {
            loadStationUsers(stationId, elementId);
        } else if (elementId.startsWith('trucks-')) {
            loadStationTrucks(stationId, elementId);
        }
    }
}

function loadStationUsers(stationId, elementId) {
    fetch(`?ajax=get_station_users&station_id=${stationId}`)
        .then(response => response.json())
        .then(data => {
            const element = document.getElementById(elementId);
            if (data.success) {
                if (data.users.length === 0) {
                    element.innerHTML = '<em>No users assigned to this station</em>';
                } else {
                    let html = '<ul style="margin: 0; padding-left: 20px;">';
                    data.users.forEach(user => {
                        html += `<li><strong>${user.username}</strong> (${user.role})`;
                        if (user.email) html += ` - ${user.email}`;
                        if (user.last_login) {
                            html += `<br><small>Last login: ${new Date(user.last_login).toLocaleDateString()}</small>`;
                        }
                        html += '</li>';
                    });
                    html += '</ul>';
                    element.innerHTML = html;
                }
            } else {
                element.innerHTML = `<em>Error loading users: ${data.message}</em>`;
            }
        })
        .catch(error => {
            document.getElementById(elementId).innerHTML = `<em>Error loading users: ${error.message}</em>`;
        });
}

function loadStationTrucks(stationId, elementId) {
    fetch(`?ajax=get_station_trucks&station_id=${stationId}`)
        .then(response => response.json())
        .then(data => {
            const element = document.getElementById(elementId);
            if (data.success) {
                if (data.trucks.length === 0) {
                    element.innerHTML = '<em>No trucks assigned to this station</em>';
                } else {
                    let html = '<ul style="margin: 0; padding-left: 20px;">';
                    data.trucks.forEach(truck => {
                        html += `<li><strong>${truck.name}</strong>`;
                        if (truck.relief == 1) html += ' <span style="color: #666;">(Relief)</span>';
                        html += `<br><small>${truck.locker_count} lockers, ${truck.item_count} items</small></li>`;
                    });
                    html += '</ul>';
                    element.innerHTML = html;
                }
            } else {
                element.innerHTML = `<em>Error loading trucks: ${data.message}</em>`;
            }
        })
        .catch(error => {
            document.getElementById(elementId).innerHTML = `<em>Error loading trucks: ${error.message}</em>`;
        });
}

// Close modal when clicking outside
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

<?php include 'templates/footer.php'; ?>
