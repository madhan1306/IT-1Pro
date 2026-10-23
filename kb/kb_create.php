<?php
// FILE: kb/kb_create.php

date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

$logged_in_username = $_SESSION['username'];
$logged_in_user_fullname = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: $logged_in_username;
$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new Exception("Security token validation failed.");

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $linked_tickets_raw = trim($_POST['linked_tickets'] ?? '');
        
        if (empty($title)) throw new Exception("Article Title is required.");
        if (empty($category_id)) throw new Exception("Please select a Category.");

        $pdf_filename = null;
        $extracted_text = '';

        if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['pdf_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("File upload error.");
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') throw new Exception("Only PDF documents allowed.");

            $upload_dir = '../uploads/kb/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $pdf_filename = time() . '_' . preg_replace("/[^a-zA-Z0-9.-]/", "_", basename($file['name']));
            $target_path = $upload_dir . $pdf_filename;

            if (!move_uploaded_file($file['tmp_name'], $target_path)) throw new Exception("Failed to save the PDF.");

            $extracted = shell_exec("pdftotext -layout " . escapeshellarg($target_path) . " - 2>/dev/null");
            if ($extracted !== null) $extracted_text = mb_convert_encoding($extracted, 'UTF-8', 'UTF-8');
        }

        // Insert KB Article with Author
        $stmt = $conn->prepare("INSERT INTO kb_articles (category_id, title, description, extracted_text, pdf_filename, status, created_by_username) VALUES (?, ?, ?, ?, ?, 'Published', ?)");
        $stmt->bind_param("isssss", $category_id, $title, $description, $extracted_text, $pdf_filename, $logged_in_username);
        $stmt->execute();
        $new_kb_id = $conn->insert_id;
        $stmt->close();

        // Record Initial Creation History
        $stmt_hist = $conn->prepare("INSERT INTO kb_article_history (article_id, changed_by_username, change_summary) VALUES (?, ?, 'Initial article creation')");
        $stmt_hist->bind_param("is", $new_kb_id, $logged_in_username);
        $stmt_hist->execute();
        $stmt_hist->close();

        // Process Linked Tickets
        if (!empty($linked_tickets_raw)) {
            $ticket_array = array_unique(array_filter(preg_split('/[\s,]+/', $linked_tickets_raw)));
            if (count($ticket_array) > 0) {
                $stmt_link = $conn->prepare("INSERT INTO kb_ticket_links (kb_id, ticket_id, added_by_username) VALUES (?, ?, ?)");
                foreach ($ticket_array as $t_id) {
                    $clean_id = trim($t_id);
                    $stmt_link->bind_param("iss", $new_kb_id, $clean_id, $logged_in_username);
                    $stmt_link->execute();
                }
                $stmt_link->close();
            }
        }

        $message = "Knowledge Base manual published successfully!";
        $msg_type = "success";

    } catch (Exception $e) {
        $message = $e->getMessage();
        $msg_type = "danger";
    }
}

// Fetch Categories for Dropdown
$cat_res = $conn->query("SELECT id, name FROM kb_categories ORDER BY name ASC");
$categories = $cat_res->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Create KB Article";
include_once '../includes/header.php';
?>

<div class="container-xl py-4" style="max-width: 800px;">
    <!-- ADMIN NAVIGATION TABS -->
    <div class="d-flex align-items-center mb-4 border-bottom pb-2 gap-3">
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_index" class="text-decoration-none text-muted fw-bold small"><i data-feather="arrow-left" style="width:14px;"></i> PORTAL</a>
        <div class="vr"></div>
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_create" class="btn btn-sm btn-dark fw-medium"><i data-feather="plus" style="width:14px;"></i> New Article</a>
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_categories" class="btn btn-sm btn-light border text-secondary fw-medium"><i data-feather="folder" style="width:14px;"></i> Categories</a>
    </div>

    <h3 class="fw-bold mb-4 text-dark">Upload New Manual</h3>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?> d-flex align-items-center rounded-3 shadow-sm border-0 mb-4">
            <i data-feather="<?= $msg_type === 'success' ? 'check-circle' : 'alert-triangle' ?>" class="me-2"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
        <div class="card-body p-4">
            <form action="" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Article Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control bg-light border-0" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Category <span class="text-danger">*</span></label>
                        <select name="category_id" class="form-select bg-light border-0" required>
                            <option value="">Select...</option>
                            <?php foreach($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Brief Description</label>
                    <textarea name="description" class="form-control bg-light border-0" rows="2"></textarea>
                </div>

                <div class="mb-4 p-3 bg-light rounded border border-light-subtle">
                    <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">PDF Document</label>
                    <input type="file" name="pdf_file" class="form-control bg-white" accept=".pdf">
                    <div class="text-muted mt-2" style="font-size: 0.75rem;">Text will be extracted automatically for searching.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Link GLPI Tickets</label>
                    <input type="text" name="linked_tickets" class="form-control bg-light border-0" placeholder="e.g., 76453, 76488">
                </div>

                <div class="text-end border-top pt-4">
                    <button type="submit" class="btn btn-dark fw-medium px-4 py-2">Publish Manual</button>
                </div>
            </form>
        </div>
        
        <!-- Expanded Footer -->
        <div class="card-footer bg-light border-top py-3">
            <div class="row text-center align-items-center" style="font-size: 0.9rem;">
                <div class="col-md-6 border-end">
                    <i data-feather="user" style="width:16px; margin-top:-2px;" class="me-1 text-muted"></i> 
                    <span class="text-muted">Author:</span> <strong class="text-dark fs-6"><?= htmlspecialchars($logged_in_user_fullname) ?></strong>
                </div>
                <div class="col-md-6">
                    <i data-feather="clock" style="width:14px; margin-top:-2px;" class="me-1 text-muted"></i> 
                    <span class="text-muted">Created at:</span> <span class="fw-medium text-dark"><?= date('d M Y, h:i A') ?></span>
                </div>
            </div>
        </div>

    </div>
</div>

<script>document.addEventListener('DOMContentLoaded', () => { if(typeof feather !== 'undefined') feather.replace(); });</script>
<?php include_once '../includes/footer.php'; ?>