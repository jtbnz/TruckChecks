<?php
include('config.php');
include('db.php');
include('auth.php');

// Get database connection
$pdo = get_db_connection();

// Ensure user is authenticated
requireAuth();

// Get user information
$user = getCurrentUser();
if (!$user) {
    header('Location: login.php');
    exit;
}

$userRole = $user['role'];
$userId = $user['id'];

// Check permissions
if ($userRole !== 'superuser' && $userRole !== 'station_admin') {
    die('Access denied. Insufficient permissions.');
}

// Get user's stations if station admin
$userStations = [];
if ($userRole === 'station_admin') {
    // Get stations directly from database to avoid potential redirect loops
    try {
        $stmt = $pdo->prepare("
            SELECT s.* 
            FROM stations s 
            JOIN user_stations us ON s.id = us.station_id 
            WHERE us.user_id = ? 
            ORDER BY s.name
        ");
        $stmt->execute([$userId]);
        $userStations = $stmt->fetchAll();
        
        if (empty($userStations)) {
            die('Access denied. No stations assigned.');
        }
    } catch (Exception $e) {
        die('Database error: ' . $e->getMessage());
    }
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_user'])) {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $email = trim($_POST['email']);
            $role = $_POST['role'];
            $stationIds = $_POST['station_ids'] ?? [];
            
            if (empty($username) || empty($password)) {
                throw new Exception("Username and password are required");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters long");
            }
            
            // Validate role permissions
            if ($userRole === 'station_admin' && $role !== 'station_admin') {
                throw new Exception("Station admins can only create other station admins");
            }
            
            // Validate station assignments
            if ($role === 'station_admin' && empty($stationIds)) {
                throw new Exception("Station admins must be assigned to at least one station");
            }
            
            if ($userRole === 'station_admin') {
                // Ensure station admin can only assign to their own stations
                $allowedStationIds = array_column($userStations, 'id');
                foreach ($stationIds as $stationId) {
                    if (!in_array($stationId, $allowedStationIds)) {
                        throw new Exception("You can only assign users to your own stations");
                    }
                }
            }
            
            // Check if user already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Username already exists");
            }
            
            // Create user
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $passwordHash, $email, $role, $userId]);
            $newUserId = $pdo->lastInsertId();
            
            // Assign to stations if station admin
            if ($role === 'station_admin' && !empty($stationIds)) {
                $stmt = $pdo->prepare("INSERT INTO user_stations (user_id, station_id, created_by) VALUES (?, ?, ?)");
                foreach ($stationIds as $stationId) {
                    $stmt->execute([$newUserId, $stationId, $userId]);
                }
            }
            
            $message = "User '$username' created successfully!";
            
        } elseif (isset($_POST['update_user'])) {
            $updateUserId = $_POST['user_id'];
            $email = trim($_POST['email']);
            $stationIds = $_POST['station_ids'] ?? [];
            
            // Get user to update
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$updateUserId]);
            $updateUser = $stmt->fetch();
            
            if (!$updateUser) {
                throw new Exception("User not found");
            }
            
            // Check permissions
            if ($userRole === 'station_admin') {
                // Station admin can only update users they created or users in their stations
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM user_stations us 
                    JOIN user_stations my_stations ON us.station_id = my_stations.station_id 
                    WHERE us.user_id = ? AND my_stations.user_id = ?
                ");
                $stmt->execute([$updateUserId, $userId]);
                if ($stmt->fetchColumn() == 0 && $updateUser['created_by'] != $userId) {
                    throw new Exception("You can only update users in your stations");
                }
                
                // Validate station assignments
                $allowedStationIds = array_column($userStations, 'id');
                foreach ($stationIds as $stationId) {
                    if (!in_array($stationId, $allowedStationIds)) {
                        throw new Exception("You can only assign users to your own stations");
                    }
                }
            }
            
            // Update user
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$email, $updateUserId]);
            
            // Update station assignments for station admins
            if ($updateUser['role'] === 'station_admin') {
                // Remove existing assignments
                if ($userRole === 'superuser') {
                    $stmt = $pdo->prepare("DELETE FROM user_stations WHERE user_id = ?");
                    $stmt->execute([$updateUserId]);
                } else {
                    // Station admin can only remove assignments to their own stations
                    $allowedStationIds = array_column($userStations, 'id');
                    $placeholders = str_repeat('?,', count($allowedStationIds) - 1) . '?';
                    $stmt = $pdo->prepare("DELETE FROM user_stations WHERE user_id = ? AND station_id IN ($placeholders)");
                    $stmt->execute(array_merge([$updateUserId], $allowedStationIds));
                }
                
                // Add new assignments
                if (!empty($stationIds)) {
                    $stmt = $pdo->prepare("INSERT INTO user_stations (user_id, station_id, created_by) VALUES (?, ?, ?)");
                    foreach ($stationIds as $stationId) {
                        $stmt->execute([$updateUserId, $stationId, $userId]);
                    }
                }
            }
            
            $message = "User updated successfully!";
            
        } elseif (isset($_POST['delete_user'])) {
            $deleteUserId = $_POST['user_id'];
            
            // Get user to delete
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$deleteUserId]);
            $deleteUser = $stmt->fetch();
            
            if (!$deleteUser) {
                throw new Exception("User not found");
            }
            
            // Check permissions
            if ($userRole === 'station_admin') {
                // Station admin can only delete users they created
                if ($deleteUser['created_by'] != $userId) {
                    throw new Exception("You can only delete users you created");
                }
            }
            
            // Don't allow deleting yourself
            if ($deleteUserId == $userId) {
                throw new Exception("You cannot delete your own account");
            }
            
            // Delete user (cascade will handle user_stations)
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$deleteUserId]);
            
            $message = "User deleted successfully!";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get users based on role
if ($userRole === 'superuser') {
    // Superuser sees all users
    $stmt = $pdo->query("
        SELECT u.*, 
               GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') as station_names,
               creator.username as created_by_username
        FROM users u 
        LEFT JOIN user_stations us ON u.id = us.user_id 
        LEFT JOIN stations s ON us.station_id = s.id 
        LEFT JOIN users creator ON u.created_by = creator.id
        GROUP BY u.id 
        ORDER BY u.role, u.username
    ");
    $users = $stmt->fetchAll();
} else {
    // Station admin sees only users in their stations or users they created
    $stationIds = array_column($userStations, 'id');
    $placeholders = str_repeat('?,', count($stationIds) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.*, 
               GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') as station_names,
               creator.username as created_by_username
        FROM users u 
        LEFT JOIN user_stations us ON u.id = us.user_id 
        LEFT JOIN stations s ON us.station_id = s.id 
        LEFT JOIN users creator ON u.created_by = creator.id
        WHERE (us.station_id IN ($placeholders) OR u.created_by = ?)
        GROUP BY u.id 
        ORDER BY u.username
    ");
    $stmt->execute(array_merge($stationIds, [$userId]));
    $users = $stmt->fetchAll();
}

// Get available stations
if ($userRole === 'superuser') {
    $stmt = $pdo->query("SELECT * FROM stations ORDER BY name");
    $stations = $stmt->fetchAll();
} else {
    $stations = $userStations;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - TruckChecks</title>
    <link rel="stylesheet" href="styles/styles.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #12044C;
        }
        
        .header h1 {
            margin: 0;
            color: #12044C;
        }
        
        .header .subtitle {
            color: #666;
            margin-top: 5px;
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
        
        .section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section h2 {
            margin: 0 0 20px 0;
            color: #12044C;
            font-size: 20px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
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
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 8px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin-right: 8px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #12044C;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
            margin-right: 10px;
        }
        
        .btn:hover {
            background-color: #0056b3;
        }
        
        .btn-danger {
            background-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-secondary {
            background-color: #6c757d;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .users-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        
        .users-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .role-superuser {
            background-color: #dc3545;
            color: white;
        }
        
        .role-station-admin {
            background-color: #28a745;
            color: white;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .users-table {
                font-size: 12px;
            }
            
            .users-table th,
            .users-table td {
                padding: 8px 4px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>User Management</h1>
            <div class="subtitle">
                <?php if ($userRole === 'superuser'): ?>
                    Manage all system users and their permissions
                <?php else: ?>
                    Manage users for your assigned stations
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Create User Section -->
        <div class="section">
            <h2>Create New User</h2>
            <form method="post">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" name="username" id="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" name="password" id="password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email (optional):</label>
                        <input type="email" name="email" id="email">
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role:</label>
                        <select name="role" id="role" required onchange="toggleStationSelection()">
                            <?php if ($userRole === 'superuser'): ?>
                                <option value="superuser">Superuser</option>
                            <?php endif; ?>
                            <option value="station_admin" selected>Station Admin</option>
                        </select>
                    </div>
                </div>
                
                <div id="station-selection" class="form-group">
                    <label>Assign to Stations:</label>
                    <div class="checkbox-group">
                        <?php foreach ($stations as $station): ?>
                            <div class="checkbox-item">
                                <input type="checkbox" name="station_ids[]" value="<?= $station['id'] ?>" id="station_<?= $station['id'] ?>">
                                <label for="station_<?= $station['id'] ?>"><?= htmlspecialchars($station['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" name="create_user" class="btn">Create User</button>
            </form>
        </div>

        <!-- Users List Section -->
        <div class="section">
            <h2>Existing Users</h2>
            <?php if (empty($users)): ?>
                <p>No users found.</p>
            <?php else: ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Stations</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $listUser): ?>
                            <tr>
                                <td><?= htmlspecialchars($listUser['username']) ?></td>
                                <td><?= htmlspecialchars($listUser['email'] ?? '') ?></td>
                                <td>
                                    <span class="role-badge role-<?= str_replace('_', '-', $listUser['role']) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $listUser['role'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($listUser['station_names'] ?? 'None') ?></td>
                                <td><?= htmlspecialchars($listUser['created_by_username'] ?? 'System') ?></td>
                                <td>
                                    <?php if ($listUser['id'] != $userId): ?>
                                        <button class="btn btn-secondary" onclick="editUser(<?= $listUser['id'] ?>)">Edit</button>
                                        <?php if ($userRole === 'superuser' || $listUser['created_by'] == $userId): ?>
                                            <button class="btn btn-danger" onclick="deleteUser(<?= $listUser['id'] ?>, '<?= htmlspecialchars($listUser['username']) ?>')">Delete</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <em>Current User</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Back to Admin Section -->
        <div class="section">
            <a href="admin.php" class="btn btn-secondary">← Back to Admin</a>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit User</h2>
            <form method="post" id="editForm">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group">
                    <label for="edit_email">Email:</label>
                    <input type="email" name="email" id="edit_email">
                </div>
                
                <div id="edit_station_selection" class="form-group">
                    <label>Assign to Stations:</label>
                    <div class="checkbox-group" id="edit_stations_list">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
                
                <button type="submit" name="update_user" class="btn">Update User</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Delete User</h2>
            <p>Are you sure you want to delete user <strong id="delete_username"></strong>?</p>
            <p>This action cannot be undone.</p>
            <form method="post" id="deleteForm">
                <input type="hidden" name="user_id" id="delete_user_id">
                <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        const users = <?= json_encode($users) ?>;
        const stations = <?= json_encode($stations) ?>;
        
        function toggleStationSelection() {
            const role = document.getElementById('role').value;
            const stationSelection = document.getElementById('station-selection');
            
            if (role === 'station_admin') {
                stationSelection.style.display = 'block';
            } else {
                stationSelection.style.display = 'none';
            }
        }
        
        function editUser(userId) {
            const user = users.find(u => u.id == userId);
            if (!user) return;
            
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_email').value = user.email || '';
            
            // Populate stations checkboxes
            const stationsList = document.getElementById('edit_stations_list');
            stationsList.innerHTML = '';
            
            if (user.role === 'station_admin') {
                document.getElementById('edit_station_selection').style.display = 'block';
                
                // Get user's current stations
                const userStationNames = user.station_names ? user.station_names.split(', ') : [];
                
                stations.forEach(station => {
                    const div = document.createElement('div');
                    div.className = 'checkbox-item';
                    
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'station_ids[]';
                    checkbox.value = station.id;
                    checkbox.id = 'edit_station_' + station.id;
                    checkbox.checked = userStationNames.includes(station.name);
                    
                    const label = document.createElement('label');
                    label.htmlFor = 'edit_station_' + station.id;
                    label.textContent = station.name;
                    
                    div.appendChild(checkbox);
                    div.appendChild(label);
                    stationsList.appendChild(div);
                });
            } else {
                document.getElementById('edit_station_selection').style.display = 'none';
            }
            
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function deleteUser(userId, username) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_username').textContent = username;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === editModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }
        
        // Initialize station selection visibility
        toggleStationSelection();
    </script>
</body>
</html>
