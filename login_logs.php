<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<script>console.log('DEBUG: Starting login_logs.php');</script>";

include('config.php');
echo "<script>console.log('DEBUG: Config loaded');</script>";

include 'db.php';
echo "<script>console.log('DEBUG: DB loaded');</script>";

include_once('auth.php');
echo "<script>console.log('DEBUG: Auth loaded');</script>";

// Require authentication and get user context
$user = requireAuth();
echo "<script>console.log('DEBUG: User authenticated: ', " . json_encode($user) . ");</script>";

$station = null;

// Check if user has permission to view login logs
if ($user['role'] !== 'superuser' && $user['role'] !== 'station_admin') {
    echo "<script>console.log('DEBUG: User does not have permission, role: " . $user['role'] . "');</script>";
    echo "<div style='color: red; padding: 20px;'>Access denied. You do not have permission to view login logs. Your role: " . htmlspecialchars($user['role']) . "</div>";
    echo "<a href='admin.php'>Back to Admin</a>";
    // Don't redirect for debugging
    // header('Location: login.php');
    // exit;
}

// Get user's station context - use their first assigned station
if ($user['role'] === 'station_admin') {
    echo "<script>console.log('DEBUG: Getting stations for station admin');</script>";
    // Station admins: get their first assigned station
    $user_stations = getUserStations($user['id']);
    echo "<script>console.log('DEBUG: Station admin stations: ', " . json_encode($user_stations) . ");</script>";
    if (!empty($user_stations)) {
        $station = $user_stations[0];
        echo "<script>console.log('DEBUG: Selected station for station admin: ', " . json_encode($station) . ");</script>";
    } else {
        echo "<script>console.log('DEBUG: No stations found for station admin');</script>";
    }
} elseif ($user['role'] === 'superuser') {
    echo "<script>console.log('DEBUG: Getting stations for superuser');</script>";
    // Superusers: get their first assigned station, or first available station
    $user_stations = getUserStations($user['id']);
    echo "<script>console.log('DEBUG: Superuser stations: ', " . json_encode($user_stations) . ");</script>";
    if (!empty($user_stations)) {
        $station = $user_stations[0];
        echo "<script>console.log('DEBUG: Selected station for superuser: ', " . json_encode($station) . ");</script>";
    } else {
        echo "<script>console.log('DEBUG: No stations found for superuser');</script>";
    }
} else {
    echo "<script>console.log('DEBUG: Unknown user role: " . $user['role'] . "');</script>";
}

echo "<script>console.log('DEBUG: Final station: ', " . json_encode($station) . ");</script>";

include 'templates/header.php';

// Pagination settings
$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter settings
$filter_success = isset($_GET['success']) ? $_GET['success'] : '';
$filter_ip = isset($_GET['ip']) ? trim($_GET['ip']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

try {
    $pdo = get_db_connection();
    
    // Build WHERE clause
    $where_conditions = [];
    $params = [];
    
    // Role-based filtering
    if ($user['role'] === 'station_admin' && $station) {
        // Station admins can only see logs for users from their station(s)
        $user_stations = getUserStations($user['id']);
        $station_ids = array_column($user_stations, 'id');
        
        if (!empty($station_ids)) {
            $placeholders = implode(',', array_fill(0, count($station_ids), '?'));
            $where_conditions[] = "ll.user_id IN (
                SELECT DISTINCT u.id 
                FROM users u 
                LEFT JOIN user_stations us ON u.id = us.user_id 
                WHERE us.station_id IN ($placeholders) OR u.role = 'superuser'
            )";
            $params = array_merge($params, $station_ids);
        }
    }
    // Superusers see all logs (no additional filtering needed)
    
    if ($filter_success !== '') {
        $where_conditions[] = "ll.success = ?";
        $params[] = $filter_success;
    }
    
    if ($filter_ip !== '') {
        $where_conditions[] = "ll.ip_address LIKE ?";
        $params[] = "%$filter_ip%";
    }
    
    if ($date_from !== '') {
        $where_conditions[] = "ll.login_time >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    
    if ($date_to !== '') {
        $where_conditions[] = "ll.login_time <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) FROM login_log ll $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);
    
    // Get login logs with user information
    $sql = "SELECT ll.*, u.username, u.role,
                   GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as station_names
            FROM login_log ll
            LEFT JOIN users u ON ll.user_id = u.id
            LEFT JOIN user_stations us ON u.id = us.user_id
            LEFT JOIN stations s ON us.station_id = s.id
            $where_clause
            GROUP BY ll.id
            ORDER BY ll.login_time DESC 
            LIMIT ? OFFSET ?";
    $params[] = $records_per_page;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $login_logs = $stmt->fetchAll();
    
    // Get statistics with role-based filtering
    $stats_where = $where_clause;
    $stats_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params
    
    $stats_sql = "SELECT 
        COUNT(*) as total_attempts,
        SUM(ll.success) as successful_logins,
        COUNT(DISTINCT ll.ip_address) as unique_ips,
        COUNT(CASE WHEN ll.login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h
        FROM login_log ll $stats_where";
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch();
    
} catch (Exception $e) {
    $error = "Error fetching login logs: " . $e->getMessage();
}
?>

<style>
    .login-logs-container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
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
    
    .logs-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    
    .logs-table th, .logs-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }
    
    .logs-table th {
        background-color: #12044C;
        color: white;
        font-weight: bold;
    }
    
    .logs-table tr:nth-child(even) {
        background-color: #f2f2f2;
    }
    
    .success {
        color: green;
        font-weight: bold;
    }
    
    .failed {
        color: red;
        font-weight: bold;
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
    
    .role-user {
        background-color: #6c757d;
        color: white;
    }
    
    .pagination {
        text-align: center;
        margin: 20px 0;
    }
    
    .pagination a, .pagination span {
        display: inline-block;
        padding: 8px 12px;
        margin: 0 2px;
        text-decoration: none;
        border: 1px solid #ddd;
        border-radius: 3px;
    }
    
    .pagination a:hover {
        background-color: #f5f5f5;
    }
    
    .pagination .current {
        background-color: #12044C;
        color: white;
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
    
    .stats {
        background-color: #e9ecef;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .stats-row {
        display: flex;
        gap: 30px;
        flex-wrap: wrap;
    }
    
    .stat-item {
        text-align: center;
    }
    
    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #12044C;
    }
    
    .stat-label {
        font-size: 12px;
        color: #666;
    }
    
    @media (max-width: 768px) {
        .logs-table {
            font-size: 12px;
        }
        
        .filter-row {
            flex-direction: column;
            align-items: stretch;
        }
        
        .stats-row {
            flex-direction: column;
            gap: 10px;
        }
    }
</style>

<div class="login-logs-container">
    <h1>Login Logs</h1>
    
    <!-- Access Level Information -->
    <div class="access-info">
        <strong>Access Level:</strong> 
        <?php if ($user['role'] === 'superuser'): ?>
            <span class="role-badge role-superuser">Superuser</span> - Viewing all login logs across all stations
            <?php if ($station): ?>
                <br><strong>Current Station Context:</strong> <?= htmlspecialchars($station['name']) ?>
            <?php else: ?>
                <br><strong>Station Context:</strong> All stations (no specific station selected)
            <?php endif; ?>
        <?php elseif ($user['role'] === 'station_admin'): ?>
            <span class="role-badge role-station_admin">Station Admin</span> - Viewing login logs for your assigned stations
            <?php if ($station): ?>
                <br><strong>Current Station:</strong> <?= htmlspecialchars($station['name']) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <?php if (isset($error)): ?>
        <div style="color: red; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <?php if (!isset($error)): ?>
        <div class="stats">
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['total_attempts']; ?></div>
                    <div class="stat-label">Total Attempts</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['successful_logins']; ?></div>
                    <div class="stat-label">Successful Logins</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['unique_ips']; ?></div>
                    <div class="stat-label">Unique IP Addresses</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['last_24h']; ?></div>
                    <div class="stat-label">Last 24 Hours</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Success Status:</label>
                    <select name="success">
                        <option value="">All</option>
                        <option value="1" <?php echo $filter_success === '1' ? 'selected' : ''; ?>>Successful</option>
                        <option value="0" <?php echo $filter_success === '0' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>IP Address:</label>
                    <input type="text" name="ip" value="<?php echo htmlspecialchars($filter_ip); ?>" placeholder="Enter IP address">
                </div>
                
                <div class="filter-group">
                    <label>Date From:</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Date To:</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="button">Filter</button>
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <a href="login_logs.php" class="button">Clear</a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Results -->
    <?php if (!isset($error) && !empty($login_logs)): ?>
        <p>Showing <?php echo count($login_logs); ?> of <?php echo $total_records; ?> records</p>
        
        <table class="logs-table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Stations</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Location</th>
                    <th>Browser</th>
                    <th>OS</th>
                    <th>Mobile</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($login_logs as $log): ?>
                    <?php
                    $browser_info = json_decode($log['browser_info'], true);
                    $browser = $browser_info['browser'] ?? 'Unknown';
                    $os = $browser_info['os'] ?? 'Unknown';
                    $is_mobile = $browser_info['is_mobile'] ?? false;
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d H:i:s', strtotime($log['login_time'])); ?></td>
                        <td><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?></td>
                        <td>
                            <?php if ($log['role']): ?>
                                <span class="role-badge role-<?php echo $log['role']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $log['role'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="role-badge role-user">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($log['station_names'] ?: 'None'); ?></td>
                        <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        <td class="<?php echo $log['success'] ? 'success' : 'failed'; ?>">
                            <?php echo $log['success'] ? 'SUCCESS' : 'FAILED'; ?>
                        </td>
                        <td>
                            <?php 
                            $location = '';
                            if ($log['city']) $location .= $log['city'];
                            if ($log['country']) {
                                if ($location) $location .= ', ';
                                $location .= $log['country'];
                            }
                            echo htmlspecialchars($location ?: 'Unknown');
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($browser); ?></td>
                        <td><?php echo htmlspecialchars($os); ?></td>
                        <td><?php echo $is_mobile ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo $filter_success !== '' ? '&success=' . $filter_success : ''; ?><?php echo $filter_ip !== '' ? '&ip=' . urlencode($filter_ip) : ''; ?><?php echo $date_from !== '' ? '&date_from=' . $date_from : ''; ?><?php echo $date_to !== '' ? '&date_to=' . $date_to : ''; ?>">First</a>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $filter_success !== '' ? '&success=' . $filter_success : ''; ?><?php echo $filter_ip !== '' ? '&ip=' . urlencode($filter_ip) : ''; ?><?php echo $date_from !== '' ? '&date_from=' . $date_from : ''; ?><?php echo $date_to !== '' ? '&date_to=' . $date_to : ''; ?>">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo $filter_success !== '' ? '&success=' . $filter_success : ''; ?><?php echo $filter_ip !== '' ? '&ip=' . urlencode($filter_ip) : ''; ?><?php echo $date_from !== '' ? '&date_from=' . $date_from : ''; ?><?php echo $date_to !== '' ? '&date_to=' . $date_to : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $filter_success !== '' ? '&success=' . $filter_success : ''; ?><?php echo $filter_ip !== '' ? '&ip=' . urlencode($filter_ip) : ''; ?><?php echo $date_from !== '' ? '&date_from=' . $date_from : ''; ?><?php echo $date_to !== '' ? '&date_to=' . $date_to : ''; ?>">Next</a>
                    <a href="?page=<?php echo $total_pages; ?><?php echo $filter_success !== '' ? '&success=' . $filter_success : ''; ?><?php echo $filter_ip !== '' ? '&ip=' . urlencode($filter_ip) : ''; ?><?php echo $date_from !== '' ? '&date_from=' . $date_from : ''; ?><?php echo $date_to !== '' ? '&date_to=' . $date_to : ''; ?>">Last</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
    <?php elseif (!isset($error)): ?>
        <p>No login logs found matching your criteria.</p>
    <?php endif; ?>
    
    <div style="margin-top: 30px;">
        <a href="admin.php" class="button">Back to Admin</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
