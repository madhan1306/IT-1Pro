<?php
// FILE: kb/kb_categories.php

date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// FUTURE PERMISSIONS - Uncomment when ready
// checkPermission('manage_kb'); 

$message = '';
$msg_type = '';

// --- HANDLE CREATE / EDIT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("Security token mismatch.");

    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $cat_id = $_POST['cat_id'] ?? null;

    if ($_POST['action'] === 'save') {
        if (empty($name)) {
            $message = "Category name is required.";
            $msg_type = "danger";
        } else {
            if ($cat_id) { // Update
                $stmt = $conn->prepare("UPDATE kb_categories SET name=?, description=? WHERE id=?");
                $stmt->bind_param("ssi", $name, $description, $cat_id);
                $msg = "Category updated.";
            } else { // Insert
                $stmt = $conn->prepare("INSERT INTO kb_categories (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
                $msg = "Category created.";
            }
            
            try {
                if ($stmt->execute()) {
                    $message = $msg;
                    $msg_type = "success";
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) {
                    $message = "A category with this name already exists.";
                    $msg_type = "danger";
                } else {
                    $message = "Database error.";
                    $msg_type = "danger";
                }
            }
            $stmt->close();
        }
    }

    // --- HANDLE DELETE ---
    if ($_POST['action'] === 'delete' && $cat_id) {
        // FUTURE: checkPermission('delete_kb');
        $stmt = $conn->prepare("DELETE FROM kb_categories WHERE id=?");
        $stmt->bind_param("i", $cat_id);
        $stmt->execute();
        $stmt->close();
        $message = "Category deleted. Associated articles are now Uncategorized.";
        $msg_type = "success";
    }
}

// FETCH CATEGORIES WITH ARTICLE COUNT
$query = "
    SELECT c.*, COUNT(a.id) as article_count 
    FROM kb_categories c 
    LEFT JOIN kb_articles a ON c.id = a.category_id 
    GROUP BY c.id 
    ORDER BY c.name ASC
";
$res = $conn->query($query);
$categories = $res->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Manage KB Categories";
include_once '../includes/header.php';
?>

<div class="container-xl py-4" style="max-width: 900px;">
    <!-- ADMIN NAVIGATION TABS -->
    <div class="d-flex align-items-center mb-4 border-bottom pb-2 gap-3">
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_index" class="text-decoration-none text-muted fw-bold small"><i data-feather="arrow-left" style="width:14px;"></i> PORTAL</a>
        <div class="vr"></div>
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_create" class="btn btn-sm btn-light border text-secondary fw-medium"><i data-feather="plus" style="width:14px;"></i> New Article</a>
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_categories" class="btn btn-sm btn-dark fw-medium"><i data-feather="folder" style="width:14px;"></i> Categories</a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold m-0 text-dark">Manage Categories</h3>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?> d-flex align-items-center rounded-3 shadow-sm border-0 mb-4">
            <i data-feather="<?= $msg_type === 'success' ? 'check-circle' : 'alert-triangle' ?>" class="me-2"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ADD/EDIT FORM -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-light">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark text-uppercase mb-3" style="font-size: 0.8rem; letter-spacing: 0.05em;">Add / Edit Category</h6>
                    <form action="" method="POST" id="catForm">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="cat_id" id="cat_id" value="">

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.85rem;">Category Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="cat_name" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.85rem;">Description</label>
                            <textarea name="description" id="cat_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100 fw-medium">Save</button>
                            <button type="button" class="btn btn-light border w-100 fw-medium" onclick="resetForm()">Reset</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- CATEGORY LIST -->
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-uppercase text-secondary" style="font-size: 0.75rem;">Category Name</th>
                                <th class="text-center text-uppercase text-secondary" style="font-size: 0.75rem;">Articles</th>
                                <th class="text-end text-uppercase text-secondary" style="font-size: 0.75rem;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td class="px-3 py-3">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($cat['name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($cat['description']) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary rounded-pill"><?= $cat['article_count'] ?></span>
                                </td>
                                <td class="text-end px-3">
                                    <button class="btn btn-sm btn-light border text-primary me-1" onclick="editCat(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>', '<?= htmlspecialchars(addslashes($cat['description'])) ?>')">
                                        <i data-feather="edit-2" style="width:14px;"></i>
                                    </button>
                                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this category? Articles inside it will not be deleted.');">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light border text-danger">
                                            <i data-feather="trash-2" style="width:14px;"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => { if(typeof feather !== 'undefined') feather.replace(); });

function editCat(id, name, desc) {
    document.getElementById('cat_id').value = id;
    document.getElementById('cat_name').value = name;
    document.getElementById('cat_desc').value = desc;
}
function resetForm() {
    document.getElementById('catForm').reset();
    document.getElementById('cat_id').value = '';
}
</script>
<?php include_once '../includes/footer.php'; ?>