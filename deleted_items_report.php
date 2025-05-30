<?php
include_once('auth.php');
include_once('db.php');

// Require authentication and station context
$station = requireStation();
$user = getCurrentUser();

// Check if user has permission to view deleted items report
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    header('Location: login.php');
    exit;
}

include 'templates/header.php';

// Get filter parameters
$truck_filter = isset($_GET['truck_filter']) ? $_GET['truck_filter'] : '';
$locker_filter = isset($_GET['locker_filter']) ? $_GET['locker_filter'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

try {
    $db = get_db_connection();
    
    // Build WHERE clause with role-based filtering
    $where_conditions = [];
    $params = [];
    
    // Role-based filtering - station admins only see their station's data
    if ($user['role'] === 'station_admin') {
        $where_conditions[] = "t.station_id = ?";
        $params[] = $station['id'];
    }
    // Superusers see all deleted items (no additional filtering needed)
    
    // Apply user filters
    if ($truck_filter !== '') {
        $where_conditions[] = "t.id = ?";
        $params[] = $truck_filter;
    }
    
    if ($locker_filter !== '') {
        $where_conditions[] = "l.id = ?";
        $params[] = $locker_filter;
    }
    
    if ($date_from !== '') {
        $where_conditions[] = "i.deleted_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    
    if ($date_to !== '') {
        $where_conditions[] = "i.deleted_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    
    // Always filter for deleted items
    $where_conditions[] = "i.deleted_at IS NOT NULL";
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get deleted items with role-based filtering
    $sql = "SELECT i.*, t.name as truck_name, l.name as locker_name, s.name as station_name
            FROM items i
            JOIN lockers l ON i.locker_id = l.id
            JOIN trucks t ON l.truck_id = t.id
            LEFT JOIN stations s ON t.station_id = s.id
            $where_clause
            ORDER BY i.deleted_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $deleted_items = $stmt->fetchAll();
    
    // Get available trucks for filter dropdown (role-based)
    if ($user['role'] === 'station_admin') {
        $trucks_sql = "SELECT * FROM trucks WHERE station_id = ? ORDER BY name";
        $trucks_stmt = $db->prepare($trucks_sql);
        $trucks_stmt->execute([$station['id']]);
        $trucks = $trucks_stmt->fetchAll();
    } else {
        // Superuser sees all trucks
        $trucks = $db->query('SELECT * FROM trucks ORDER BY name')->fetchAll();
    }
    
    // Get available lockers for filter dropdown (role-based)
    if ($truck_filter) {
        $lockers_stmt = $db->prepare('SELECT * FROM lockers WHERE truck_id = ? ORDER BY name');
        $lockers_stmt->execute([$truck_filter]);
        $lockers = $lockers_stmt->fetchAll();
    } else {
        $lockers = [];
    }
    
} catch (Exception $e) {
    $error = "Error fetching deleted items: " . $e->getMessage();
}
?>

<style>
    .deleted-items-container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
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
    
    .access-info {
        background-color: #e7f3ff;
        border: 1px solid #b3d9ff;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .filters {
        background-color: #f9f9f9;
        padding: 20px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .filter-row {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .filter-group label {
        font-weight: bold;
        font-size: 12px;
    }
    
    .filter-group input, .filter-group select {
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 3px;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    
    .items-table th, .items-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    
    .items-table th {
        background-color: #12044C;
        color: white;
        font-weight: bold;
    }
    
    .items-table tr:nth-child(even) {
        background-color: #f2f2f2;
    }
    
    .button {
        background-color: #12044C;
        color: white;
        padding: 10px 15px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    
    .button:hover {
        background-color: #0056b3;
    }
    
    .button.secondary {
        background-color: #6c757d;
    }
    
    .button.secondary:hover {
        background-color: #545b62;
    }
    
    .role-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
    }
    
    .role-superuser {
        background-color: #dc3545;
        color: white;
    }
    
    .role-station_admin {
        background-color: #28a745;
        color: white;
    }
    
    @media (max-width: 768px) {
        .items-table {
            font-size: 12px;
        }
        
        .filter-row {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>

<div class="deleted-items-container">
    <div class="station-info">
        <div class="station-name"><?= htmlspecialchars($station['name']) ?></div>
        <?php if ($station['description']): ?>
            <div style="color: #666; margin-top: 5px;"><?= htmlspecialchars($station['description']) ?></div>
        <?php endif; ?>
    </div>

    <h1>Deleted Items Report</h1>
    
    <!-- Access Level Information -->
    <div class="access-info">
        <strong>Access Level:</strong> 
        <?php if ($user['role'] === 'superuser'): ?>
            <span class="role-badge role-superuser">Superuser</span> - Viewing deleted items from all stations
        <?php elseif ($user['role'] === 'station_admin'): ?>
            <span class="role-badge role-station_admin">Station Admin</span> - Viewing deleted items from your assigned station
        <?php endif; ?>
    </div>
    
    <?php if (isset($error)): ?>
        <div style="color: red; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Truck:</label>
                    <select name="truck_filter" onchange="this.form.submit()">
                        <option value="">All Trucks</option>
                        <?php foreach ($trucks as $truck): ?>
                            <option value="<?= $truck['id'] ?>" <?= $truck_filter == $truck['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($truck['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (!empty($lockers)): ?>
                <div class="filter-group">
                    <label>Locker:</label>
                    <select name="locker_filter">
                        <option value="">All Lockers</option>
                        <?php foreach ($lockers as $locker): ?>
                            <option value="<?= $locker['id'] ?>" <?= $locker_filter == $locker['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($locker['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="filter-group">
                    <label>Date From:</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Date To:</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="button">Apply Filter</button>
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <a href="deleted_items_report.php" class="button secondary">Clear</a>
                </div>
            </div>
            
            <!-- Hidden fields to preserve truck filter when changing locker -->
            <?php if ($truck_filter): ?>
                <input type="hidden" name="truck_filter" value="<?= htmlspecialchars($truck_filter) ?>">
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Results -->
    <?php if (!isset($error) && !empty($deleted_items)): ?>
        <p>Found <?= count($deleted_items) ?> deleted items</p>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Description</th>
                    <th>Truck</th>
                    <th>Locker</th>
                    <?php if ($user['role'] === 'superuser'): ?>
                        <th>Station</th>
                    <?php endif; ?>
                    <th>Deleted Date</th>
                    <th>Deleted By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deleted_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= htmlspecialchars($item['description'] ?: 'No description') ?></td>
                        <td><?= htmlspecialchars($item['truck_name']) ?></td>
                        <td><?= htmlspecialchars($item['locker_name']) ?></td>
                        <?php if ($user['role'] === 'superuser'): ?>
                            <td><?= htmlspecialchars($item['station_name'] ?: 'No station') ?></td>
                        <?php endif; ?>
                        <td><?= date('Y-m-d H:i:s', strtotime($item['deleted_at'])) ?></td>
                        <td><?= htmlspecialchars($item['deleted_by'] ?: 'Unknown') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
    <?php elseif (!isset($error)): ?>
        <p>No deleted items found matching your criteria.</p>
    <?php endif; ?>
    
    <div style="margin-top: 30px;">
        <a href="admin.php" class="button">Back to Admin</a>
    </div>
</div>

<script>
// Auto-submit form when truck selection changes to update locker dropdown
document.querySelector('select[name="truck_filter"]').addEventListener('change', function() {
    // Clear locker filter when truck changes
    const lockerSelect = document.querySelector('select[name="locker_filter"]');
    if (lockerSelect) {
        lockerSelect.value = '';
    }
    this.form.submit();
});
</script>

<?php include 'templates/footer.php'; ?>
