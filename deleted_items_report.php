<?php
// Include password file
include('config.php');
include 'db.php';
include 'templates/header.php';

// Check if the user is logged in
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

// Get filter parameters
$table_filter = $_GET['table'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT * FROM audit_log WHERE 1=1";
$params = [];

if (!empty($table_filter)) {
    $query .= " AND table_name = ?";
    $params[] = $table_filter;
}

if (!empty($date_from)) {
    $query .= " AND deleted_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $query .= " AND deleted_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$query .= " ORDER BY deleted_at DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $audit_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error fetching audit records: " . $e->getMessage();
    $audit_records = [];
}

// Get available tables for filter
$available_tables = ['items', 'lockers', 'trucks', 'checks'];
?>

<h1>Deleted Items Report</h1>

<?php if (isset($error_message)): ?>
    <div class="error-message" style="color: red; margin: 20px 0; padding: 10px; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;">
        <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>

<div class="filter-section" style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
    <h2>Filter Options</h2>
    <form method="GET" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: end;">
        <div>
            <label for="table">Table:</label>
            <select name="table" id="table">
                <option value="">All Tables</option>
                <?php foreach ($available_tables as $table): ?>
                    <option value="<?= $table ?>" <?= $table_filter === $table ? 'selected' : '' ?>>
                        <?= ucfirst($table) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label for="date_from">From Date:</label>
            <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        
        <div>
            <label for="date_to">To Date:</label>
            <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        
        <div>
            <button type="submit" class="button touch-button">Apply Filter</button>
            <a href="deleted_items_report.php" class="button touch-button" style="background-color: #6c757d;">Clear</a>
        </div>
    </form>
</div>

<div class="stats-section" style="margin: 20px 0; padding: 15px; background-color: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;">
    <h2>Summary</h2>
    <p><strong>Total Records:</strong> <?= count($audit_records) ?></p>
    <?php if (!empty($table_filter)): ?>
        <p><strong>Filtered by Table:</strong> <?= htmlspecialchars(ucfirst($table_filter)) ?></p>
    <?php endif; ?>
    <?php if (!empty($date_from) || !empty($date_to)): ?>
        <p><strong>Date Range:</strong> 
            <?= !empty($date_from) ? htmlspecialchars($date_from) : 'Beginning' ?> 
            to 
            <?= !empty($date_to) ? htmlspecialchars($date_to) : 'Now' ?>
        </p>
    <?php endif; ?>
</div>

<?php if (!empty($audit_records)): ?>
    <div class="audit-records">
        <h2>Audit Records</h2>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left;">ID</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left;">Table</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left;">Record ID</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left;">Deleted At</th>
                        <th style="border: 1px solid #dee2e6; padding: 8px; text-align: left;">Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_records as $record): ?>
                        <tr>
                            <td style="border: 1px solid #dee2e6; padding: 8px;"><?= htmlspecialchars($record['id']) ?></td>
                            <td style="border: 1px solid #dee2e6; padding: 8px;"><?= htmlspecialchars(ucfirst($record['table_name'])) ?></td>
                            <td style="border: 1px solid #dee2e6; padding: 8px;"><?= htmlspecialchars($record['record_id']) ?></td>
                            <td style="border: 1px solid #dee2e6; padding: 8px;"><?= htmlspecialchars($record['deleted_at']) ?></td>
                            <td style="border: 1px solid #dee2e6; padding: 8px; max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                                <?php
                                $data = json_decode($record['row_data'], true);
                                if ($data) {
                                    $display_data = [];
                                    foreach ($data as $key => $value) {
                                        if ($key !== 'id') { // Skip ID as it's already shown
                                            $display_data[] = $key . ': ' . $value;
                                        }
                                    }
                                    echo htmlspecialchars(implode(', ', $display_data));
                                } else {
                                    echo htmlspecialchars($record['row_data']);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="no-records" style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
        <p>No audit records found matching the current filters.</p>
    </div>
<?php endif; ?>

<div class="button-container" style="margin-top: 20px;">
    <a href="admin.php" class="button touch-button">Back to Admin</a>
</div>

<?php include 'templates/footer.php'; ?>
