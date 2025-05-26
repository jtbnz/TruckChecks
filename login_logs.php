<?php
include('config.php');
include('db.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}

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
    
    if ($filter_success !== '') {
        $where_conditions[] = "success = ?";
        $params[] = $filter_success;
    }
    
    if ($filter_ip !== '') {
        $where_conditions[] = "ip_address LIKE ?";
        $params[] = "%$filter_ip%";
    }
    
    if ($date_from !== '') {
        $where_conditions[] = "login_time >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    
    if ($date_to !== '') {
        $where_conditions[] = "login_time <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) FROM login_log $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);
    
    // Get login logs
    $sql = "SELECT * FROM login_log $where_clause ORDER BY login_time DESC LIMIT ? OFFSET ?";
    $params[] = $records_per_page;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $login_logs = $stmt->fetchAll();
    
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
    
    <?php if (isset($error)): ?>
        <div style="color: red; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <?php if (!isset($error)): ?>
        <?php
        // Get some quick stats
        $stats_sql = "SELECT 
            COUNT(*) as total_attempts,
            SUM(success) as successful_logins,
            COUNT(DISTINCT ip_address) as unique_ips,
            COUNT(CASE WHEN login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h
            FROM login_log";
        $stats_stmt = $pdo->prepare($stats_sql);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch();
        ?>
        
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
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Location</th>
                    <th>Browser</th>
                    <th>OS</th>
                    <th>Mobile</th>
                    <th>Session ID</th>
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
                        <td><?php echo htmlspecialchars(substr($log['session_id'], 0, 8)) . '...'; ?></td>
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
