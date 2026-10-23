<?php
// FILE: kb/kb_edit.php

date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

$logged_in_username = $_SESSION['username'];
$kb_id = $_GET['id'] ?? null;
$message = '';
$msg_type = '';

if (!$kb_id) die("Invalid ID.");

// Fetch Existing Article Data with Author Full Name
$stmt = $conn->prepare("
    SELECT k.*, 
           u.firstname, u.lastname,
           GROUP_CONCAT(l.ticket_id SEPARATOR ', ') as linked_tickets 
    FROM kb_articles k 
    LEFT JOIN kb_ticket_links l ON k.id = l.kb_id 
    LEFT JOIN users u ON k.created_by_username = u.username
    WHERE k.id = ? 
    GROUP BY k.id
");
$stmt->bind_param("i", $kb_id);
$stmt->execute();
$art = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$art) die("Article not found.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new Exception("Security error.");
        
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $linked_tickets_raw = trim($_POST['linked_tickets'] ?? '');
        $existing_pdf = $_POST['existing_pdf'] ?? '';

        if (empty($title)) throw new Exception("Article Title is required.");
        if (empty($category_id)) throw new Exception("Please select a Category.");

        $new_pdf_filename = $existing_pdf;
        $extracted_text_update = ""; 
        $update_text = false;
        
        $changes = [];

        // --- HANDLE NEW PDF UPLOAD (Replacement) ---
        if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['pdf_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload error.");
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') throw new Exception("Only PDFs allowed.");

            $upload_dir = '../uploads/kb/';
            
            // Delete old file if it exists
            if (!empty($existing_pdf) && file_exists($upload_dir . $existing_pdf)) {
                unlink($upload_dir . $existing_pdf);
            }

            $new_pdf_filename = time() . '_' . preg_replace("/[^a-zA-Z0-9.-]/", "_", basename($file['name']));
            $target_path = $upload_dir . $new_pdf_filename;
            
            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                throw new Exception("Failed to save the updated PDF.");
            }

            $extracted = shell_exec("pdftotext -layout " . escapeshellarg($target_path) . " - 2>/dev/null");
            if ($extracted !== null) {
                $extracted_text_update = mb_convert_encoding($extracted, 'UTF-8', 'UTF-8');
                $update_text = true;
            }
            $changes[] = "Replaced attached PDF document";
        }

        // --- TRACK TEXT/FIELD CHANGES ---
        if ($title !== $art['title']) $changes[] = "Updated Title";
        if ($description !== $art['description']) $changes[] = "Updated Description";
        if ($category_id !== (int)$art['category_id']) $changes[] = "Changed Category";
        if ($linked_tickets_raw !== ($art['linked_tickets'] ?? '')) $changes[] = "Modified linked GLPI tickets";

        // --- UPDATE ARTICLE ---
        if ($update_text) {
            $stmt = $conn->prepare("UPDATE kb_articles SET category_id=?, title=?, description=?, pdf_filename=?, extracted_text=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("issssi", $category_id, $title, $description, $new_pdf_filename, $extracted_text_update, $kb_id);
        } else {
            $stmt = $conn->prepare("UPDATE kb_articles SET category_id=?, title=?, description=?, pdf_filename=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("isssi", $category_id, $title, $description, $new_pdf_filename, $kb_id);
        }
        $stmt->execute();
        $stmt->close();

        // --- LOG HISTORY IF CHANGED ---
        if (!empty($changes)) {
            $change_summary = implode(', ', $changes);
            $stmt_hist = $conn->prepare("INSERT INTO kb_article_history (article_id, changed_by_username, change_summary) VALUES (?, ?, ?)");
            $stmt_hist->bind_param("iss", $kb_id, $logged_in_username, $change_summary);
            $stmt_hist->execute();
            $stmt_hist->close();
        }

        // --- REPLACE LINKED TICKETS ---
        $conn->query("DELETE FROM kb_ticket_links WHERE kb_id = " . (int)$kb_id);
        
        if (!empty($linked_tickets_raw)) {
            $ticket_array = array_unique(array_filter(preg_split('/[\s,]+/', $linked_tickets_raw)));
            if (count($ticket_array) > 0) {
                $stmt_link = $conn->prepare("INSERT INTO kb_ticket_links (kb_id, ticket_id, added_by_username) VALUES (?, ?, ?)");
                foreach ($ticket_array as $t_id) {
                    $clean_id = trim($t_id);
                    $stmt_link->bind_param("iss", $kb_id, $clean_id, $logged_in_username);
                    $stmt_link->execute();
                }
                $stmt_link->close();
            }
        }

        $message = "Manual updated successfully.";
        $msg_type = "success";

        // Re-fetch updated article to reflect in UI
        $stmt_refresh = $conn->prepare("
            SELECT k.*, 
                   u.firstname, u.lastname,
                   GROUP_CONCAT(l.ticket_id SEPARATOR ', ') as linked_tickets 
            FROM kb_articles k 
            LEFT JOIN kb_ticket_links l ON k.id = l.kb_id 
            LEFT JOIN users u ON k.created_by_username = u.username
            WHERE k.id = ? 
            GROUP BY k.id
        ");
        $stmt_refresh->bind_param("i", $kb_id);
        $stmt_refresh->execute();
        $art = $stmt_refresh->get_result()->fetch_assoc();
        $stmt_refresh->close();

    } catch (Exception $e) {
        $message = $e->getMessage();
        $msg_type = "danger";
    }
}

// FETCH CATEGORIES FOR DROPDOWN
$cat_res = $conn->query("SELECT id, name FROM kb_categories ORDER BY name ASC");
$categories = $cat_res->fetch_all(MYSQLI_ASSOC);

// FETCH HISTORY LOGS WITH FULL NAMES
$hist_stmt = $conn->prepare("
    SELECT h.*, u.firstname, u.lastname 
    FROM kb_article_history h
    LEFT JOIN users u ON h.changed_by_username = u.username
    WHERE h.article_id = ? 
    ORDER BY h.changed_at DESC
");
$hist_stmt->bind_param("i", $kb_id);
$hist_stmt->execute();
$history_logs = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hist_stmt->close();

$pageTitle = "Edit KB Manual";
include_once '../includes/header.php';

// Format Dates and Names safely for Footer
$created_date = !empty($art['created_at']) ? date('d M Y, h:i A', strtotime($art['created_at'])) : 'Unknown';
$updated_date = !empty($art['updated_at']) ? date('d M Y, h:i A', strtotime($art['updated_at'])) : $created_date;

$author_fullname = trim(($art['firstname'] ?? '') . ' ' . ($art['lastname'] ?? ''));
$author_name = !empty($author_fullname) ? htmlspecialchars($author_fullname) : (!empty($art['created_by_username']) ? htmlspecialchars($art['created_by_username']) : 'System/Unknown');
?>

<div class="container-xl py-4" style="max-width: 800px;">
    <!-- ADMIN NAVIGATION TABS -->
    <div class="d-flex align-items-center mb-4 border-bottom pb-2 gap-3">
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_index" class="text-decoration-none text-muted fw-bold small"><i data-feather="arrow-left" style="width:14px;"></i> PORTAL</a>
        <div class="vr"></div>
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_create" class="btn btn-sm btn-light border text-secondary fw-medium"><i data-feather="plus" style="width:14px;"></i> New Article</a>
        <a href="<?php echo APP_BASE_PATH; ?>?route=kb_categories" class="btn btn-sm btn-light border text-secondary fw-medium"><i data-feather="folder" style="width:14px;"></i> Categories</a>
    </div>

    <h3 class="fw-bold mb-4 text-dark">Edit Manual Details</h3>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?> d-flex align-items-center rounded-3 shadow-sm border-0 mb-4">
            <i data-feather="<?= $msg_type === 'success' ? 'check-circle' : 'alert-triangle' ?>" class="me-2"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Nav Tabs -->
    <ul class="nav nav-tabs mb-3" id="editTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab"><i data-feather="edit-2" style="width:14px;" class="me-1"></i> Edit Details</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab"><i data-feather="clock" style="width:14px;" class="me-1"></i> Revision History <span class="badge bg-secondary ms-1"><?= count($history_logs) ?></span></button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="editTabsContent">
        
        <!-- EDIT DETAILS TAB -->
        <div class="tab-pane fade show active" id="details" role="tabpanel">
            <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
                <div class="card-body p-4">
                    <form action="" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="existing_pdf" value="<?= htmlspecialchars($art['pdf_filename'] ?? '') ?>">

                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Article Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control bg-light border-0" value="<?= htmlspecialchars($art['title']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Category <span class="text-danger">*</span></label>
                                <select name="category_id" class="form-select bg-light border-0" required>
                                    <option value="">Select...</option>
                                    <?php foreach($categories as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($art['category_id'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Brief Description</label>
                            <textarea name="description" class="form-control bg-light border-0" rows="2"><?= htmlspecialchars($art['description']) ?></textarea>
                        </div>

                        <div class="mb-4 p-3 bg-light rounded border border-light-subtle">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Replace PDF Document (Optional)</label>
                            
                            <?php if (!empty($art['pdf_filename'])): ?>
                                <div class="mb-3 p-2 bg-white rounded border border-success-subtle text-success fw-medium" style="font-size: 0.8rem;">
                                    <i data-feather="check-circle" style="width:14px;" class="me-1"></i> Currently attached: <?= htmlspecialchars($art['pdf_filename']) ?>
                                </div>
                            <?php else: ?>
                                <div class="mb-3 p-2 bg-white rounded border border-danger-subtle text-danger fw-medium" style="font-size: 0.8rem;">
                                    <i data-feather="alert-circle" style="width:14px;" class="me-1"></i> No PDF currently attached.
                                </div>
                            <?php endif; ?>
                            
                            <input type="file" name="pdf_file" class="form-control bg-white" accept=".pdf">
                            <div class="text-muted mt-2" style="font-size: 0.75rem;">Uploading a new file will overwrite the existing one and record it in the history log.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.9rem;">Linked GLPI Tickets</label>
                            <input type="text" name="linked_tickets" class="form-control bg-light border-0" value="<?= htmlspecialchars($art['linked_tickets'] ?? '') ?>">
                            <div class="text-muted mt-1" style="font-size: 0.75rem;">Enter comma-separated GLPI ticket numbers solved by this manual.</div>
                        </div>

                        <div class="text-end border-top pt-4">
                            <button type="submit" class="btn btn-dark fw-medium px-4 py-2">Save Changes</button>
                        </div>
                    </form>
                </div>
                
                <!-- Expanded Footer -->
                <div class="card-footer bg-light border-top py-3">
                    <div class="row text-center align-items-center" style="font-size: 0.9rem;">
                        <div class="col-md-4 border-end">
                            <i data-feather="user" style="width:16px; margin-top:-2px;" class="me-1 text-muted"></i> 
                            <span class="text-muted">Author:</span> <strong class="text-dark fs-6"><?= $author_name ?></strong>
                        </div>
                        <div class="col-md-4 border-end">
                            <i data-feather="calendar" style="width:14px; margin-top:-2px;" class="me-1 text-muted"></i> 
                            <span class="text-muted">Created:</span> <span class="fw-medium text-dark"><?= $created_date ?></span>
                        </div>
                        <div class="col-md-4 text-primary">
                            <i data-feather="clock" style="width:14px; margin-top:-2px;" class="me-1"></i> 
                            <span class="text-muted">Last Edit:</span> <span class="fw-bold"><?= $updated_date ?></span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- HISTORY TAB -->
        <div class="tab-pane fade" id="history" role="tabpanel">
            <div class="card shadow-sm border-0 rounded-3 overflow-hidden">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light text-muted text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                                <tr>
                                    <th class="ps-4" style="width: 25%;">Date & Time</th>
                                    <th style="width: 25%;">Editor</th>
                                    <th style="width: 50%;">Changes Made</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history_logs)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted fst-italic">No history logs found for this article.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history_logs as $log): ?>
                                    <tr>
                                        <td class="ps-4 fw-medium text-dark"><?= date('d M Y, h:i A', strtotime($log['changed_at'])) ?></td>
                                        <td>
                                            <?php 
                                                // Map history username back to full name if joined successfully
                                                $log_fullname = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?: $log['changed_by_username'];
                                            ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                <i data-feather="user" style="width:10px; margin-top:-2px;" class="me-1"></i> <?= htmlspecialchars($log_fullname) ?>
                                            </span>
                                        </td>
                                        <td class="text-muted"><?= htmlspecialchars($log['change_summary']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>document.addEventListener('DOMContentLoaded', () => { if(typeof feather !== 'undefined') feather.replace(); });</script>
<?php include_once '../includes/footer.php'; ?>