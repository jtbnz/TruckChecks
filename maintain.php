<?php
include('config.php');
include 'db.php';

// Check if the user is logged in
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

// Handle ALL AJAX requests before any HTML output
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // ── TRUCKS ──────────────────────────────────────────────
    if ($_GET['ajax'] === 'get_trucks') {
        $stmt = $db->query('SELECT t.*, (SELECT COUNT(*) FROM lockers WHERE truck_id = t.id) as locker_count, (SELECT COUNT(*) FROM items i JOIN lockers l ON i.locker_id = l.id WHERE l.truck_id = t.id) as item_count FROM trucks t ORDER BY t.name');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'add_truck') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        $relief = isset($data['relief']) ? (int)$data['relief'] : 0;
        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Truck name is required']);
            exit;
        }
        // Check for duplicate
        $check = $db->prepare('SELECT id FROM trucks WHERE name = ?');
        $check->execute([$name]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'A truck with that name already exists']);
            exit;
        }
        $stmt = $db->prepare('INSERT INTO trucks (name, relief) VALUES (?, ?)');
        $stmt->execute([$name, $relief]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($_GET['ajax'] === 'update_truck') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $relief = isset($data['relief']) ? (int)$data['relief'] : 0;
        if (empty($name) || empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Truck name and ID are required']);
            exit;
        }
        // Check for duplicate (excluding self)
        $check = $db->prepare('SELECT id FROM trucks WHERE name = ? AND id != ?');
        $check->execute([$name, $id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'A truck with that name already exists']);
            exit;
        }
        $stmt = $db->prepare('UPDATE trucks SET name = ?, relief = ? WHERE id = ?');
        $stmt->execute([$name, $relief, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['ajax'] === 'delete_truck') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Truck ID is required']);
            exit;
        }
        // Get counts for warning
        $countStmt = $db->prepare('SELECT (SELECT COUNT(*) FROM lockers WHERE truck_id = ?) as lockers, (SELECT COUNT(*) FROM items i JOIN lockers l ON i.locker_id = l.id WHERE l.truck_id = ?) as items');
        $countStmt->execute([$id, $id]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('DELETE FROM trucks WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'deleted_lockers' => (int)$counts['lockers'], 'deleted_items' => (int)$counts['items']]);
        exit;
    }

    // ── LOCKERS ─────────────────────────────────────────────
    if ($_GET['ajax'] === 'get_lockers') {
        $truck_id = $_GET['truck_id'] ?? '';
        $query = 'SELECT l.*, t.name as truck_name, (SELECT COUNT(*) FROM items WHERE locker_id = l.id) as item_count FROM lockers l JOIN trucks t ON l.truck_id = t.id';
        $params = [];
        if (!empty($truck_id)) {
            $query .= ' WHERE l.truck_id = ?';
            $params[] = $truck_id;
        }
        $query .= ' ORDER BY t.name, l.name';
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'get_trucks_list') {
        $stmt = $db->query('SELECT id, name FROM trucks ORDER BY name');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'add_locker') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        $truck_id = (int)($data['truck_id'] ?? 0);
        $notes = trim($data['notes'] ?? '');
        if (empty($name) || empty($truck_id)) {
            echo json_encode(['success' => false, 'error' => 'Locker name and truck are required']);
            exit;
        }
        $stmt = $db->prepare('INSERT INTO lockers (name, truck_id, notes) VALUES (?, ?, ?)');
        $stmt->execute([$name, $truck_id, $notes]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($_GET['ajax'] === 'update_locker') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $truck_id = (int)($data['truck_id'] ?? 0);
        $notes = trim($data['notes'] ?? '');
        if (empty($name) || empty($truck_id) || empty($id)) {
            echo json_encode(['success' => false, 'error' => 'All fields are required']);
            exit;
        }
        $stmt = $db->prepare('UPDATE lockers SET name = ?, truck_id = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $truck_id, $notes, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['ajax'] === 'delete_locker') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Locker ID is required']);
            exit;
        }
        $countStmt = $db->prepare('SELECT COUNT(*) as items FROM items WHERE locker_id = ?');
        $countStmt->execute([$id]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('DELETE FROM lockers WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'deleted_items' => (int)$counts['items']]);
        exit;
    }

    // ── ITEMS ───────────────────────────────────────────────
    if ($_GET['ajax'] === 'get_items') {
        $truck_id = $_GET['truck_id'] ?? '';
        $locker_id = $_GET['locker_id'] ?? '';
        $query = 'SELECT i.*, l.name as locker_name, t.name as truck_name, t.id as truck_id FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id';
        $params = [];
        $where = [];
        if (!empty($truck_id)) {
            $where[] = 't.id = ?';
            $params[] = $truck_id;
        }
        if (!empty($locker_id)) {
            $where[] = 'l.id = ?';
            $params[] = $locker_id;
        }
        if (!empty($where)) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }
        $query .= ' ORDER BY t.name, l.name, i.name';
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['ajax'] === 'add_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        $locker_id = (int)($data['locker_id'] ?? 0);
        if (empty($name) || empty($locker_id)) {
            echo json_encode(['success' => false, 'error' => 'Item name and locker are required']);
            exit;
        }
        $stmt = $db->prepare('INSERT INTO items (name, locker_id) VALUES (?, ?)');
        $stmt->execute([$name, $locker_id]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($_GET['ajax'] === 'update_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $locker_id = (int)($data['locker_id'] ?? 0);
        if (empty($name) || empty($locker_id) || empty($id)) {
            echo json_encode(['success' => false, 'error' => 'All fields are required']);
            exit;
        }
        $stmt = $db->prepare('UPDATE items SET name = ?, locker_id = ? WHERE id = ?');
        $stmt->execute([$name, $locker_id, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['ajax'] === 'delete_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'Item ID is required']);
            exit;
        }
        $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

include 'templates/header.php';
$version = getVersion();
?>

<link rel="stylesheet" href="styles/maintain.css?id=<?php echo $version; ?>">

<div class="maintain-app">
    <h1>Maintain</h1>

    <!-- Tab Navigation -->
    <div class="tab-bar">
        <button class="tab-btn active" data-tab="trucks" onclick="switchTab('trucks')">
            <span class="tab-icon">&#x1F69A;</span>
            <span class="tab-label">Trucks</span>
        </button>
        <button class="tab-btn" data-tab="lockers" onclick="switchTab('lockers')">
            <span class="tab-icon">&#x1F512;</span>
            <span class="tab-label">Lockers</span>
        </button>
        <button class="tab-btn" data-tab="items" onclick="switchTab('items')">
            <span class="tab-icon">&#x1F4E6;</span>
            <span class="tab-label">Items</span>
        </button>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <!-- ═══════════════ TRUCKS TAB ═══════════════ -->
    <div id="tab-trucks" class="tab-panel active">
        <div class="panel-header">
            <span class="panel-count" id="truck-count">0 trucks</span>
            <button class="fab" onclick="showTruckForm()" title="Add Truck">+</button>
        </div>

        <!-- Add/Edit Form (hidden by default) -->
        <div id="truck-form-card" class="form-card hidden">
            <div class="form-card-header">
                <h3 id="truck-form-title">Add Truck</h3>
                <button class="form-close" onclick="hideTruckForm()">&times;</button>
            </div>
            <form id="truck-form" onsubmit="saveTruck(event)">
                <input type="hidden" id="truck-edit-id" value="">
                <div class="form-group">
                    <label for="truck-name">Truck Name</label>
                    <input type="text" id="truck-name" placeholder="Enter truck name" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <input type="checkbox" id="truck-relief">
                        <span class="toggle-switch"></span>
                        <span>Relief Truck</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideTruckForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="truck-save-btn">Add Truck</button>
                </div>
            </form>
        </div>

        <div id="truck-list" class="entity-list">
            <div class="loading-spinner">Loading...</div>
        </div>
    </div>

    <!-- ═══════════════ LOCKERS TAB ═══════════════ -->
    <div id="tab-lockers" class="tab-panel">
        <div class="panel-header">
            <div class="filter-bar">
                <select id="locker-truck-filter" onchange="loadLockers()">
                    <option value="">All Trucks</option>
                </select>
            </div>
            <span class="panel-count" id="locker-count">0 lockers</span>
            <button class="fab" onclick="showLockerForm()" title="Add Locker">+</button>
        </div>

        <div id="locker-form-card" class="form-card hidden">
            <div class="form-card-header">
                <h3 id="locker-form-title">Add Locker</h3>
                <button class="form-close" onclick="hideLockerForm()">&times;</button>
            </div>
            <form id="locker-form" onsubmit="saveLocker(event)">
                <input type="hidden" id="locker-edit-id" value="">
                <div class="form-group">
                    <label for="locker-name">Locker Name</label>
                    <input type="text" id="locker-name" placeholder="Enter locker name" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="locker-truck">Truck</label>
                    <select id="locker-truck" required>
                        <option value="">Select Truck</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="locker-notes">Notes</label>
                    <textarea id="locker-notes" placeholder="Optional notes" rows="2"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideLockerForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="locker-save-btn">Add Locker</button>
                </div>
            </form>
        </div>

        <div id="locker-list" class="entity-list">
            <div class="loading-spinner">Loading...</div>
        </div>
    </div>

    <!-- ═══════════════ ITEMS TAB ═══════════════ -->
    <div id="tab-items" class="tab-panel">
        <div class="panel-header">
            <div class="filter-bar">
                <select id="item-truck-filter" onchange="onItemTruckFilterChange()">
                    <option value="">All Trucks</option>
                </select>
                <select id="item-locker-filter" onchange="loadItems()">
                    <option value="">All Lockers</option>
                </select>
            </div>
            <span class="panel-count" id="item-count">0 items</span>
            <button class="fab" onclick="showItemForm()" title="Add Item">+</button>
        </div>

        <div id="item-form-card" class="form-card hidden">
            <div class="form-card-header">
                <h3 id="item-form-title">Add Item</h3>
                <button class="form-close" onclick="hideItemForm()">&times;</button>
            </div>
            <form id="item-form" onsubmit="saveItem(event)">
                <input type="hidden" id="item-edit-id" value="">
                <div class="form-group">
                    <label for="item-name">Item Name</label>
                    <input type="text" id="item-name" placeholder="Enter item name" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="item-truck-select">Truck</label>
                    <select id="item-truck-select" onchange="loadItemFormLockers()" required>
                        <option value="">Select Truck</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="item-locker-select">Locker</label>
                    <select id="item-locker-select" required>
                        <option value="">Select Truck First</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideItemForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="item-save-btn">Add Item</button>
                </div>
            </form>
        </div>

        <div id="item-list" class="entity-list">
            <div class="loading-spinner">Loading...</div>
        </div>
    </div>

    <div class="bottom-nav">
        <a href="admin.php" class="btn btn-secondary">Admin Page</a>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="modal-overlay" onclick="closeDeleteModal(event)">
    <div class="modal-dialog">
        <h3 id="delete-modal-title">Confirm Delete</h3>
        <p id="delete-modal-message"></p>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" id="delete-confirm-btn" onclick="confirmDelete()">Delete</button>
        </div>
    </div>
</div>

<script>
// ─── State ────────────────────────────────────────────────────
let currentTab = 'trucks';
let deleteTarget = { type: '', id: 0, name: '' };
let trucksCache = [];

// ─── Utilities ────────────────────────────────────────────────
function ajax(method, endpoint, data) {
    return new Promise(function(resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, 'maintain.php?ajax=' + endpoint, true);
        if (method === 'POST') {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch (e) {
                        console.error('AJAX parse error for ' + endpoint + ':', xhr.responseText.substring(0, 500));
                        showToast('Server error - check console', 'error');
                        reject('Invalid JSON from ' + endpoint);
                    }
                } else {
                    console.error('AJAX HTTP error for ' + endpoint + ': ' + xhr.status, xhr.responseText.substring(0, 500));
                    showToast('Server error (' + xhr.status + ')', 'error');
                    reject('HTTP ' + xhr.status);
                }
            }
        };
        xhr.send(data ? JSON.stringify(data) : null);
    });
}

function showToast(message, type) {
    var toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast toast-' + (type || 'success') + ' toast-show';
    setTimeout(function() {
        toast.className = 'toast';
    }, 3000);
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// ─── Tab Navigation ──────────────────────────────────────────
function switchTab(tab) {
    currentTab = tab;
    var tabs = document.querySelectorAll('.tab-btn');
    var panels = document.querySelectorAll('.tab-panel');

    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === tab);
    }
    for (var j = 0; j < panels.length; j++) {
        panels[j].classList.toggle('active', panels[j].id === 'tab-' + tab);
    }

    if (tab === 'trucks') loadTrucks();
    else if (tab === 'lockers') { loadTruckDropdowns(); loadLockers(); }
    else if (tab === 'items') { loadTruckDropdowns(); loadItems(); }
}

// ─── TRUCKS ──────────────────────────────────────────────────
function loadTrucks() {
    ajax('GET', 'get_trucks').then(function(trucks) {
        trucksCache = trucks;
        document.getElementById('truck-count').textContent = trucks.length + ' truck' + (trucks.length !== 1 ? 's' : '');
        var list = document.getElementById('truck-list');

        if (trucks.length === 0) {
            list.innerHTML = '<div class="empty-state"><p>No trucks yet</p><p class="empty-hint">Tap + to add your first truck</p></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < trucks.length; i++) {
            var t = trucks[i];
            html += '<div class="entity-card" data-id="' + t.id + '">' +
                '<div class="entity-card-body" onclick="editTruck(' + t.id + ')">' +
                    '<div class="entity-title">' + escapeHtml(t.name) +
                    (t.relief == 1 ? ' <span class="badge-relief">Relief</span>' : '') +
                    '</div>' +
                    '<div class="entity-meta">' + t.locker_count + ' locker' + (t.locker_count != 1 ? 's' : '') + ' &middot; ' + t.item_count + ' item' + (t.item_count != 1 ? 's' : '') + '</div>' +
                '</div>' +
                '<button class="entity-delete" onclick="requestDelete(\'truck\', ' + t.id + ', \'' + escapeHtml(t.name).replace(/'/g, "\\'") + '\', ' + t.locker_count + ', ' + t.item_count + ')" title="Delete">&times;</button>' +
            '</div>';
        }
        list.innerHTML = html;
    });
}

function showTruckForm(id) {
    var card = document.getElementById('truck-form-card');
    card.classList.remove('hidden');
    document.getElementById('truck-edit-id').value = id || '';
    document.getElementById('truck-form-title').textContent = id ? 'Edit Truck' : 'Add Truck';
    document.getElementById('truck-save-btn').textContent = id ? 'Update Truck' : 'Add Truck';

    if (!id) {
        document.getElementById('truck-name').value = '';
        document.getElementById('truck-relief').checked = false;
    }
    document.getElementById('truck-name').focus();
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideTruckForm() {
    document.getElementById('truck-form-card').classList.add('hidden');
    document.getElementById('truck-form').reset();
    document.getElementById('truck-edit-id').value = '';
}

function editTruck(id) {
    var truck = null;
    for (var i = 0; i < trucksCache.length; i++) {
        if (trucksCache[i].id == id) { truck = trucksCache[i]; break; }
    }
    if (!truck) return;
    showTruckForm(id);
    document.getElementById('truck-name').value = truck.name;
    document.getElementById('truck-relief').checked = truck.relief == 1;
}

function saveTruck(e) {
    e.preventDefault();
    var id = document.getElementById('truck-edit-id').value;
    var name = document.getElementById('truck-name').value.trim();
    var relief = document.getElementById('truck-relief').checked ? 1 : 0;
    var endpoint = id ? 'update_truck' : 'add_truck';
    var payload = { name: name, relief: relief };
    if (id) payload.id = parseInt(id);

    ajax('POST', endpoint, payload).then(function(res) {
        if (res.success) {
            showToast(id ? 'Truck updated' : 'Truck added', 'success');
            hideTruckForm();
            loadTrucks();
        } else {
            showToast(res.error || 'Failed to save truck', 'error');
        }
    });
}

// ─── LOCKERS ─────────────────────────────────────────────────
function loadTruckDropdowns() {
    ajax('GET', 'get_trucks_list').then(function(trucks) {
        trucksCache = trucks;
        var selectors = ['locker-truck-filter', 'locker-truck', 'item-truck-filter', 'item-truck-select'];
        for (var s = 0; s < selectors.length; s++) {
            var sel = document.getElementById(selectors[s]);
            if (!sel) continue;
            var currentVal = sel.value;
            var isFilter = selectors[s].indexOf('filter') !== -1;
            var placeholder = isFilter ? 'All Trucks' : 'Select Truck';
            sel.innerHTML = '<option value="">' + placeholder + '</option>';
            for (var i = 0; i < trucks.length; i++) {
                var opt = document.createElement('option');
                opt.value = trucks[i].id;
                opt.textContent = trucks[i].name;
                if (trucks[i].id == currentVal) opt.selected = true;
                sel.appendChild(opt);
            }
        }
    });
}

function loadLockers() {
    var truckId = document.getElementById('locker-truck-filter').value;
    var url = 'get_lockers';
    if (truckId) url += '&truck_id=' + truckId;

    ajax('GET', url).then(function(lockers) {
        document.getElementById('locker-count').textContent = lockers.length + ' locker' + (lockers.length !== 1 ? 's' : '');
        var list = document.getElementById('locker-list');

        if (lockers.length === 0) {
            list.innerHTML = '<div class="empty-state"><p>No lockers found</p><p class="empty-hint">Tap + to add a locker</p></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < lockers.length; i++) {
            var l = lockers[i];
            html += '<div class="entity-card" data-id="' + l.id + '">' +
                '<div class="entity-card-body" onclick="editLocker(' + l.id + ', this.parentNode)">' +
                    '<div class="entity-title">' + escapeHtml(l.name) + '</div>' +
                    '<div class="entity-meta">' + escapeHtml(l.truck_name) + ' &middot; ' + l.item_count + ' item' + (l.item_count != 1 ? 's' : '') +
                    (l.notes ? ' &middot; <em>' + escapeHtml(l.notes) + '</em>' : '') +
                    '</div>' +
                '</div>' +
                '<button class="entity-delete" onclick="requestDelete(\'locker\', ' + l.id + ', \'' + escapeHtml(l.name).replace(/'/g, "\\'") + '\', ' + l.item_count + ')" title="Delete">&times;</button>' +
            '</div>';
        }
        list.innerHTML = html;
    });
}

function showLockerForm(id) {
    var card = document.getElementById('locker-form-card');
    card.classList.remove('hidden');
    document.getElementById('locker-edit-id').value = id || '';
    document.getElementById('locker-form-title').textContent = id ? 'Edit Locker' : 'Add Locker';
    document.getElementById('locker-save-btn').textContent = id ? 'Update Locker' : 'Add Locker';

    if (!id) {
        document.getElementById('locker-name').value = '';
        document.getElementById('locker-truck').value = '';
        document.getElementById('locker-notes').value = '';
    }
    document.getElementById('locker-name').focus();
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideLockerForm() {
    document.getElementById('locker-form-card').classList.add('hidden');
    document.getElementById('locker-form').reset();
    document.getElementById('locker-edit-id').value = '';
}

function editLocker(id, cardEl) {
    // Fetch current data from the card
    ajax('GET', 'get_lockers').then(function(lockers) {
        var locker = null;
        for (var i = 0; i < lockers.length; i++) {
            if (lockers[i].id == id) { locker = lockers[i]; break; }
        }
        if (!locker) return;
        showLockerForm(id);
        document.getElementById('locker-name').value = locker.name;
        document.getElementById('locker-truck').value = locker.truck_id;
        document.getElementById('locker-notes').value = locker.notes || '';
    });
}

function saveLocker(e) {
    e.preventDefault();
    var id = document.getElementById('locker-edit-id').value;
    var name = document.getElementById('locker-name').value.trim();
    var truck_id = document.getElementById('locker-truck').value;
    var notes = document.getElementById('locker-notes').value.trim();
    var endpoint = id ? 'update_locker' : 'add_locker';
    var payload = { name: name, truck_id: parseInt(truck_id), notes: notes };
    if (id) payload.id = parseInt(id);

    ajax('POST', endpoint, payload).then(function(res) {
        if (res.success) {
            showToast(id ? 'Locker updated' : 'Locker added', 'success');
            hideLockerForm();
            loadLockers();
        } else {
            showToast(res.error || 'Failed to save locker', 'error');
        }
    });
}

// ─── ITEMS ───────────────────────────────────────────────────
function onItemTruckFilterChange() {
    var truckId = document.getElementById('item-truck-filter').value;
    var lockerSelect = document.getElementById('item-locker-filter');
    lockerSelect.innerHTML = '<option value="">All Lockers</option>';

    if (truckId) {
        ajax('GET', 'get_lockers&truck_id=' + truckId).then(function(lockers) {
            for (var i = 0; i < lockers.length; i++) {
                var opt = document.createElement('option');
                opt.value = lockers[i].id;
                opt.textContent = lockers[i].name;
                lockerSelect.appendChild(opt);
            }
        });
    }
    loadItems();
}

function loadItems() {
    var truckId = document.getElementById('item-truck-filter').value;
    var lockerId = document.getElementById('item-locker-filter').value;
    var url = 'get_items';
    var params = [];
    if (truckId) params.push('truck_id=' + truckId);
    if (lockerId) params.push('locker_id=' + lockerId);
    if (params.length) url += '&' + params.join('&');

    ajax('GET', url).then(function(items) {
        document.getElementById('item-count').textContent = items.length + ' item' + (items.length !== 1 ? 's' : '');
        var list = document.getElementById('item-list');

        if (items.length === 0) {
            list.innerHTML = '<div class="empty-state"><p>No items found</p><p class="empty-hint">Tap + to add an item</p></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            html += '<div class="entity-card" data-id="' + item.id + '">' +
                '<div class="entity-card-body" onclick="editItem(' + item.id + ')">' +
                    '<div class="entity-title">' + escapeHtml(item.name) + '</div>' +
                    '<div class="entity-meta">' + escapeHtml(item.truck_name) + ' &middot; ' + escapeHtml(item.locker_name) + '</div>' +
                '</div>' +
                '<button class="entity-delete" onclick="requestDelete(\'item\', ' + item.id + ', \'' + escapeHtml(item.name).replace(/'/g, "\\'") + '\')" title="Delete">&times;</button>' +
            '</div>';
        }
        list.innerHTML = html;
    });
}

function showItemForm(id) {
    var card = document.getElementById('item-form-card');
    card.classList.remove('hidden');
    document.getElementById('item-edit-id').value = id || '';
    document.getElementById('item-form-title').textContent = id ? 'Edit Item' : 'Add Item';
    document.getElementById('item-save-btn').textContent = id ? 'Update Item' : 'Add Item';

    if (!id) {
        document.getElementById('item-name').value = '';
        document.getElementById('item-truck-select').value = '';
        document.getElementById('item-locker-select').innerHTML = '<option value="">Select Truck First</option>';
    }
    document.getElementById('item-name').focus();
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideItemForm() {
    document.getElementById('item-form-card').classList.add('hidden');
    document.getElementById('item-form').reset();
    document.getElementById('item-edit-id').value = '';
}

function loadItemFormLockers() {
    var truckId = document.getElementById('item-truck-select').value;
    var lockerSelect = document.getElementById('item-locker-select');

    if (!truckId) {
        lockerSelect.innerHTML = '<option value="">Select Truck First</option>';
        return;
    }

    lockerSelect.innerHTML = '<option value="">Loading...</option>';
    ajax('GET', 'get_lockers&truck_id=' + truckId).then(function(lockers) {
        lockerSelect.innerHTML = '<option value="">Select Locker</option>';
        for (var i = 0; i < lockers.length; i++) {
            var opt = document.createElement('option');
            opt.value = lockers[i].id;
            opt.textContent = lockers[i].name;
            lockerSelect.appendChild(opt);
        }
    });
}

function editItem(id) {
    var truckId = document.getElementById('item-truck-filter').value;
    var lockerId = document.getElementById('item-locker-filter').value;
    var url = 'get_items';
    var params = [];
    if (truckId) params.push('truck_id=' + truckId);
    if (lockerId) params.push('locker_id=' + lockerId);
    if (params.length) url += '&' + params.join('&');

    ajax('GET', url).then(function(items) {
        var item = null;
        for (var i = 0; i < items.length; i++) {
            if (items[i].id == id) { item = items[i]; break; }
        }
        if (!item) return;

        showItemForm(id);
        document.getElementById('item-name').value = item.name;
        document.getElementById('item-truck-select').value = item.truck_id;

        // Load lockers for that truck, then set locker
        ajax('GET', 'get_lockers&truck_id=' + item.truck_id).then(function(lockers) {
            var sel = document.getElementById('item-locker-select');
            sel.innerHTML = '<option value="">Select Locker</option>';
            for (var i = 0; i < lockers.length; i++) {
                var opt = document.createElement('option');
                opt.value = lockers[i].id;
                opt.textContent = lockers[i].name;
                if (lockers[i].id == item.locker_id) opt.selected = true;
                sel.appendChild(opt);
            }
        });
    });
}

function saveItem(e) {
    e.preventDefault();
    var id = document.getElementById('item-edit-id').value;
    var name = document.getElementById('item-name').value.trim();
    var locker_id = document.getElementById('item-locker-select').value;
    var endpoint = id ? 'update_item' : 'add_item';
    var payload = { name: name, locker_id: parseInt(locker_id) };
    if (id) payload.id = parseInt(id);

    ajax('POST', endpoint, payload).then(function(res) {
        if (res.success) {
            showToast(id ? 'Item updated' : 'Item added', 'success');
            hideItemForm();
            loadItems();
        } else {
            showToast(res.error || 'Failed to save item', 'error');
        }
    });
}

// ─── DELETE ──────────────────────────────────────────────────
function requestDelete(type, id, name, count1, count2) {
    deleteTarget = { type: type, id: id };
    document.getElementById('delete-modal-title').textContent = 'Delete ' + type.charAt(0).toUpperCase() + type.slice(1);

    var msg = 'Are you sure you want to delete <strong>' + escapeHtml(name) + '</strong>?';
    if (type === 'truck' && (count1 > 0 || count2 > 0)) {
        msg += '<br><br><span class="delete-warning">This will also delete ' + count1 + ' locker' + (count1 != 1 ? 's' : '') + ' and ' + count2 + ' item' + (count2 != 1 ? 's' : '') + '.</span>';
    } else if (type === 'locker' && count1 > 0) {
        msg += '<br><br><span class="delete-warning">This will also delete ' + count1 + ' item' + (count1 != 1 ? 's' : '') + '.</span>';
    }
    document.getElementById('delete-modal-message').innerHTML = msg;
    document.getElementById('delete-modal').classList.add('visible');
}

function closeDeleteModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('delete-modal').classList.remove('visible');
}

function confirmDelete() {
    var type = deleteTarget.type;
    var id = deleteTarget.id;
    closeDeleteModal();

    ajax('POST', 'delete_' + type, { id: id }).then(function(res) {
        if (res.success) {
            showToast(type.charAt(0).toUpperCase() + type.slice(1) + ' deleted', 'success');
            if (type === 'truck') loadTrucks();
            else if (type === 'locker') loadLockers();
            else if (type === 'item') loadItems();
        } else {
            showToast(res.error || 'Failed to delete', 'error');
        }
    });
}

// ─── Init ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    loadTrucks();
});
</script>

<?php include 'templates/footer.php'; ?>
