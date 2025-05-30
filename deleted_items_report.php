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
        $where_conditions[] = "log.station_id = ?";
        $params[] = $station['id'];
    }
    // Superusers see all deleted items (no additional filtering needed)
    
    // Apply user filters
    if ($truck_filter !== '') {
        $where_conditions[] = "log.truck_name = ?";
        $params[] = $truck_filter;
    }
    
    if ($locker_filter !== '') {
        $where_conditions[] = "log.locker_name = ?";
        $params[] = $locker_filter;
    }
    
    if ($date_from !== '') {
        $where_conditions[] = "log.deleted_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    
    if ($date_to !== '') {
        $where_conditions[] = "log.deleted_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Get deleted items from locker_item_deletion_log table
    $sql = "SELECT log.*, s.name as station_name
            FROM locker_item_deletion_log log
            LEFT JOIN stations s ON log.station_id = s.id
            $where_clause
            ORDER BY log.deleted_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $deleted_items = $stmt->fetchAll();
    
    // Get available trucks for filter dropdown (role-based)
    if ($user['role'] === 'station_admin') {
        // For station admins, get trucks from deletion log for their station
        $trucks_sql = "SELECT DISTINCT log.truck_name 
                      FROM locker_item_deletion_log log 
                      WHERE log.station_id = ? 
                      ORDER BY log.truck_name";
        $trucks_stmt = $db->prepare($trucks_sql);
        $trucks_stmt->execute([$station['id']]);
        $trucks = $trucks_stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Superuser sees all trucks from deletion log
        $trucks_sql = "SELECT DISTINCT truck_name FROM locker_item_deletion_log ORDER BY truck_name";
        $trucks = $db->query($trucks_sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Get available lockers for filter dropdown (based on selected truck)
    $lockers = [];
    if ($truck_filter) {
        if ($user['role'] === 'station_admin') {
            $lockers_sql = "SELECT DISTINCT log.locker_name 
                           FROM locker_item_deletion_log log 
                           WHERE log.truck_name = ? AND log.station_id = ? 
                           ORDER BY log.locker_name";
            $lockers_stmt = $db->prepare($lockers_sql);
            $lockers_stmt->execute([$truck_filter, $station['id']]);
            $lockers = $lockers_stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $lockers_sql = "SELECT DISTINCT locker_name 
                           FROM locker_item_deletion_log 
                           WHERE truck_name = ? 
                           ORDER BY locker_name";
            $lockers_stmt = $db->prepare($lockers_sql);
            $lockers_stmt->execute([$truck_filter]);
            $lockers = $lockers_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
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
                        <?php foreach ($trucks as $truck_name): ?>
                            <option value="<?= htmlspecialchars($truck_name) ?>" <?= $truck_filter == $truck_name ? 'selected' : '' ?>>
                                <?= htmlspecialchars($truck_name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (!empty($lockers)): ?>
                <div class="filter-group">
                    <label>Locker:</label>
                    <select name="locker_filter">
                        <option value="">All Lockers</option>
                        <?php foreach ($lockers as $locker_name): ?>
                            <option value="<?= htmlspecialchars($locker_name) ?>" <?= $locker_filter == $locker_name ? 'selected' : '' ?>>
                                <?= htmlspecialchars($locker_name) ?>
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
                    <th>Truck</th>
                    <th>Locker</th>
                    <?php if ($user['role'] === 'superuser'): ?>
                        <th>Station</th>
                    <?php endif; ?>
                    <th>Deleted Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deleted_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td><?= htmlspecialchars($item['truck_name']) ?></td>
                        <td><?= htmlspecialchars($item['locker_name']) ?></td>
                        <?php if ($user['role'] === 'superuser'): ?>
                            <td><?= htmlspecialchars($item['station_name'] ?: 'No station') ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($item['deleted_at']): ?>
                                <span class="utc-time" data-utc="<?= htmlspecialchars($item['deleted_at']) ?>">
                                    <?= date('Y-m-d H:i:s', strtotime($item['deleted_at'])) ?>
                                </span>
                            <?php else: ?>
                                Unknown
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
    <?php elseif (!isset($error)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <h3>No Deleted Items Found</h3>
            <p>No deleted items found matching your criteria.</p>
        </div>
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

// Convert UTC times to local browser time
document.addEventListener('DOMContentLoaded', function() {
    const utcTimes = document.querySelectorAll('.utc-time');
    utcTimes.forEach(function(element) {
        const utcDateStr = element.getAttribute('data-utc');
        if (utcDateStr) {
            const utcDate = new Date(utcDateStr + ' UTC');
            element.textContent = utcDate.toLocaleString();
        }
    });
});
</script>

<?php include 'templates/footer.php'; ?>
