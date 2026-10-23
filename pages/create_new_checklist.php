<?php
// FILE: create_new_checklist.php

include_once '../includes/error_handler.php';
include_once '../includes/db_connect.php';
include_once '../includes/auth.php';

// --- AJAX HANDLER FOR DUPLICATE CHECK ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_duplicate') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $emp_id_to_check = (int)$_POST['employee_id'];
    $sql_check = "SELECT id, checklist_purpose FROM checklists WHERE employee_id = ? AND checklist_type = 'system_readiness' AND overall_status != 'Completed'";
    
    $stmt_check_dup = $conn->prepare($sql_check);
    $stmt_check_dup->bind_param("i", $emp_id_to_check);
    $stmt_check_dup->execute();
    $res_dup = $stmt_check_dup->get_result();
    
    if ($res_dup->num_rows > 0) {
        $row = $res_dup->fetch_assoc();
        echo json_encode(['status' => 'exists', 'checklist_id' => $row['id'], 'purpose' => $row['checklist_purpose']]);
    } else {
        echo json_encode(['status' => 'clear']);
    }
    
    $stmt_check_dup->close();
    exit;
}

checkPermission('create_template'); 

$pageTitle = "New Checklist for Employee";
$message = '';
$error = '';

// Initialize form data array
$form_data = [
    'employee_id' => '', 'emp_id' => '', 'full_name' => '',
    'department' => '', 'position' => '', 'email_id' => '',
    'checklist_purpose' => '', 'purpose_remarks' => ''
];

if (isset($_SESSION['form_success'])) {
    $message = $_SESSION['form_success'];
    unset($_SESSION['form_success']);
}
if (isset($_SESSION['form_error'])) {
    $error = $_SESSION['form_error'];
    unset($_SESSION['form_error']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['ajax_action'])) {
    // Repopulate form_data from POST to retain values on error
    foreach ($form_data as $key => &$value) {
        if (isset($_POST[$key])) {
            $value = trim($_POST[$key]);
        }
    }

    $employee_id = $form_data['employee_id'];
    $checklist_purpose = $form_data['checklist_purpose'];
    $purpose_remarks = $form_data['purpose_remarks'];
    $full_name = $form_data['full_name'];
    $emp_id = $form_data['emp_id'];
    $current_timestamp = date('Y-m-d H:i:s');

    if (empty($employee_id)) {
        $error = "An employee must be selected before a checklist can be created.";
    } elseif (empty($checklist_purpose)) {
        $error = "A checklist purpose must be selected.";
    }
    
    if ($checklist_purpose === 'Other' && empty($purpose_remarks)) {
        $error = "Remarks are required when the purpose is 'Other'.";
    }

    if (empty($error)) {
        $conn->begin_transaction();
        try {
            // Check for ANY active checklist for the employee (Backend fallback security)
            $sql_check = "SELECT id, checklist_purpose FROM checklists 
                          WHERE employee_id = ? 
                          AND checklist_type = 'system_readiness' 
                          AND overall_status != 'Completed'";

            $stmt_check_duplicate = $conn->prepare($sql_check);
            $stmt_check_duplicate->bind_param("i", $employee_id);
            $stmt_check_duplicate->execute();
            $result_duplicate = $stmt_check_duplicate->get_result();

            if ($result_duplicate->num_rows > 0) {
                $existing_checklist = $result_duplicate->fetch_assoc();
                $existing_checklist_id = $existing_checklist['id'];
                $existing_purpose = $existing_checklist['checklist_purpose'];
                
                throw new Exception(
                    "An active System Readiness checklist ('<strong>" . htmlspecialchars($existing_purpose) . "</strong>') already exists for this employee. A new one cannot be created until the existing one is completed. <a href='" . APP_BASE_PATH . "index.php?route=system_readiness_checklist&checklist_id={$existing_checklist_id}' class='alert-link text-decoration-underline'>Click here to view it.</a>"
                );
            }
            $stmt_check_duplicate->close();

            $stmt_insert_checklist = $conn->prepare("INSERT INTO checklists (employee_id, checklist_type, checklist_purpose, purpose_remarks, overall_status, created_at) VALUES (?, 'system_readiness', ?, ?, 'Not Started', ?)");
            $stmt_insert_checklist->bind_param("isss", $employee_id, $checklist_purpose, $purpose_remarks, $current_timestamp);
            $stmt_insert_checklist->execute();
            $new_checklist_id = $conn->insert_id;
            $stmt_insert_checklist->close();

            $conn->commit();
            log_system_event($conn, 'System Readiness', "Created '$checklist_purpose' checklist for employee: " . $full_name . ' (Emp ID: ' . $emp_id . ').');
            $_SESSION['form_success'] = "New system readiness checklist created successfully for " . htmlspecialchars($full_name);
            
            header("Location: " . APP_BASE_PATH . "index.php?route=system_readiness_checklist&checklist_id=" . $new_checklist_id);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to create checklist: " . $e->getMessage();
        }
    }
}

include_once '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary-color: #0f766e;
        --primary-hover: #0d9488;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --border-color: #e2e8f0;
        --text-main: #334155;
        --text-light: #64748b;
        --radius: 12px;
        --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color) !important;
        color: var(--text-main);
        font-size: 0.875rem;
    }
    
    h1, h2, h3, h4, h5, h6 { font-weight: 600; color: #1e293b; }
    
    .modern-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--card-shadow);
        border: 1px solid var(--border-color);
        overflow: hidden;
        margin-bottom: 24px;
    }
    .modern-card-header {
        padding: 16px 20px;
        background: #ffffff;
        border-bottom: 1px solid var(--border-color);
        font-weight: 600;
        color: var(--primary-color);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.95rem;
    }
    .modern-card-body { padding: 24px; }
    
    .form-label { font-weight: 600; font-size: 0.75rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
    .form-control, .form-select {
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 0.875rem;
        border: 1px solid #cbd5e1;
        transition: all 0.2s;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
    }
    .form-control[readonly] { background-color: #f1f5f9; color: #475569; border-color: #e2e8f0; }

    .btn-primary-modern {
        background-color: var(--primary-color);
        color: white;
        border: none;
        border-radius: 20px;
        padding: 8px 24px;
        font-weight: 600;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    .btn-primary-modern:hover { background-color: var(--primary-hover); color: white; transform: translateY(-1px); }
    .btn-primary-modern:disabled { background-color: #94a3b8; cursor: not-allowed; transform: none; }

    /* Custom Search Dropdown */
    .search-results-dropdown {
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        border-radius: 8px;
        border: 1px solid var(--border-color);
        overflow: hidden;
        margin-top: 4px;
    }
    .search-results-dropdown .list-group-item {
        border: none;
        border-bottom: 1px solid #f1f5f9;
        padding: 10px 16px;
        font-size: 0.875rem;
        transition: background-color 0.15s;
    }
    .search-results-dropdown .list-group-item:last-child { border-bottom: none; }
    .search-results-dropdown .list-group-item-action:hover { background-color: #f0fdfa; color: var(--primary-color); }
</style>

<div class="container-xl pt-4 pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <div class="mb-4">
                <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness" class="text-decoration-none text-muted small fw-bold d-inline-flex align-items-center transition-colors hover-primary mb-2">
                    <i data-feather="arrow-left" style="width:14px;" class="me-1"></i> BACK TO LIST
                </a>
                <div>
                    <h5 class="fw-bold m-0 text-dark">New System Readiness Checklist</h5>
                    <div class="text-muted small mt-1">Select an employee to begin</div>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm border-0 rounded-3 d-flex align-items-start gap-3 mb-4">
                    <i data-feather="alert-circle" class="mt-1 flex-shrink-0"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="alert alert-success shadow-sm border-0 rounded-3 d-flex align-items-start gap-3 mb-4">
                    <i data-feather="check-circle" class="mt-1 flex-shrink-0"></i>
                    <div><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <div class="modern-card">
                <div class="modern-card-header">
                    <i data-feather="user-plus" style="width:16px;"></i> Employee Selection
                </div>
                <div class="modern-card-body">
                    <form id="createExistingEmployeeForm" method="POST" action="<?= APP_BASE_PATH ?>index.php?route=create_new_checklist">
                        <input type="hidden" id="employee_id" name="employee_id" value="<?php echo htmlspecialchars($form_data['employee_id']); ?>">
                        <input type="hidden" id="emp_id" name="emp_id" value="<?php echo htmlspecialchars($form_data['emp_id']); ?>">
                        
                        <div class="row g-4">
                            <div class="col-12">
                                <label for="search_term" class="form-label">Search Employee <span class="text-danger">*</span></label>
                                <div class="position-relative">
                                    <i data-feather="search" class="position-absolute text-muted" style="left: 14px; top: 50%; transform: translateY(-50%); width: 16px;"></i>
                                    <input type="text" class="form-control ps-5 py-2 fw-medium" id="search_term" placeholder="Type name or Employee ID (min 2 chars)..." autocomplete="off" value="<?php echo htmlspecialchars($form_data['full_name']); ?>">
                                    <div id="searchResults" class="list-group position-absolute w-100 search-results-dropdown" style="z-index: 1000; display: none;"></div>
                                </div>
                                <div class="form-text text-muted small mt-1">Select from the dropdown to auto-fill details.</div>
                            </div>
                            
                            <div class="col-md-12">
                                <label for="checklist_purpose" class="form-label">Checklist Purpose <span class="text-danger">*</span></label>
                                <select class="form-select" id="checklist_purpose" name="checklist_purpose" <?php echo empty($form_data['employee_id']) ? 'disabled' : ''; ?>>
                                    <option value="" disabled <?php echo empty($form_data['checklist_purpose']) ? 'selected' : ''; ?>>Select the reason for this checklist...</option>
                                    <option value="Asset Replacement" <?php echo ($form_data['checklist_purpose'] == 'Asset Replacement') ? 'selected' : ''; ?>>Asset Replacement</option>
                                    <option value="Department Change" <?php echo ($form_data['checklist_purpose'] == 'Department Change') ? 'selected' : ''; ?>>Department Change</option>
                                    <option value="Other" <?php echo ($form_data['checklist_purpose'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="col-12 <?php echo ($form_data['checklist_purpose'] == 'Other') ? '' : 'd-none'; ?>" id="remarks_wrapper">
                                <label for="purpose_remarks" class="form-label">Specify Reason <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="purpose_remarks" name="purpose_remarks" rows="2" placeholder="Briefly describe the reason..."><?php echo htmlspecialchars($form_data['purpose_remarks']); ?></textarea>
                            </div>

                            <div class="col-12 mt-4">
                                <div class="p-4 bg-light rounded-3 border">
                                    <div class="d-flex align-items-center mb-3">
                                        <i data-feather="info" class="text-muted me-2" style="width: 14px;"></i>
                                        <h6 class="fw-bold m-0 text-muted" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Auto-Filled Details</h6>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="full_name" class="form-label">Full Name</label>
                                            <input type="text" class="form-control fw-medium" id="full_name" name="full_name" value="<?php echo htmlspecialchars($form_data['full_name']); ?>" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="email_id" class="form-label">Email ID</label>
                                            <input type="text" class="form-control" id="email_id" name="email_id" value="<?php echo htmlspecialchars($form_data['email_id']); ?>" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="department" class="form-label">Department</label>
                                            <input type="text" class="form-control" id="department" name="department" value="<?php echo htmlspecialchars($form_data['department']); ?>" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="position" class="form-label">Position</label>
                                            <input type="text" class="form-control" id="position" name="position" value="<?php echo htmlspecialchars($form_data['position']); ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    
                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <a href="<?= APP_BASE_PATH ?>index.php?route=system_readiness" class="btn btn-light border rounded-pill px-4 fw-medium text-muted">Cancel</a>
                            <button type="submit" class="btn btn-primary-modern d-flex align-items-center shadow-sm" id="createChecklistBtn" <?php echo empty($form_data['employee_id']) ? 'disabled' : ''; ?>>
                                Generate Checklist <i data-feather="arrow-right" class="ms-2" style="width:16px;"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="alertModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header border-bottom-0"><h5 class="modal-title fw-bold text-danger" id="alertModalLabel"><i data-feather="alert-circle" class="me-2"></i>Attention</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="alertModalBody"></div><div class="modal-footer border-top-0"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Close</button></div></div></div></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    feather.replace();
    
    function showAlertModal(msg) {
        document.getElementById('alertModalBody').innerHTML = msg;
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }

    const searchInput = document.getElementById('search_term');
    const searchResults = document.getElementById('searchResults');
    const employeeIdInput = document.getElementById('employee_id');
    const empIdInput = document.getElementById('emp_id');
    const fullNameInput = document.getElementById('full_name');
    const departmentInput = document.getElementById('department');
    const positionInput = document.getElementById('position');
    const emailIdInput = document.getElementById('email_id');
    const purposeSelect = document.getElementById('checklist_purpose');
    const remarksWrapper = document.getElementById('remarks_wrapper'); 
    const remarksTextarea = document.getElementById('purpose_remarks');
    const createBtn = document.getElementById('createChecklistBtn');

    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    }

    purposeSelect.addEventListener('change', function() {
        if (this.value === 'Other') {
            remarksWrapper.classList.remove('d-none');
            remarksTextarea.required = true;
        } else {
            remarksWrapper.classList.add('d-none');
            remarksTextarea.required = false;
        }
    });

    const searchEmployees = async () => {
        const searchTerm = searchInput.value.trim();
        searchResults.innerHTML = ''; 
        
        if (searchTerm.length < 2) {
            searchResults.style.display = 'none';
            return;
        }

        searchResults.style.display = 'block';
        searchResults.innerHTML = '<div class="list-group-item text-muted text-center py-3"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Searching...</div>';

        try {
            const response = await fetch(`<?= APP_BASE_PATH ?>index.php?route=get_employee_details&term=${encodeURIComponent(searchTerm)}`);
            const employees = await response.json();

            searchResults.innerHTML = ''; 

            if (employees.length > 0) {
                employees.forEach(emp => {
                    const item = document.createElement('a');
                    item.href = '#';
                    item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                    item.innerHTML = `
                        <div>
                            <div class="fw-bold text-dark">${emp.full_name}</div>
                            <div class="text-muted small">${emp.department} &bull; ${emp.position}</div>
                        </div>
                        <span class="badge bg-light text-secondary border font-monospace">${emp.emp_id}</span>
                    `;
                    
                    item.dataset.id = emp.id;
                    item.dataset.empId = emp.emp_id;
                    item.dataset.fullName = emp.full_name;
                    item.dataset.department = emp.department;
                    item.dataset.position = emp.position;
                    item.dataset.emailId = emp.email_id;
                    searchResults.appendChild(item);
                });
            } else {
                searchResults.innerHTML = '<div class="list-group-item text-muted text-center py-3">No matching employees found.</div>';
            }
        } catch (error) {
            console.error('Error fetching employees:', error);
            searchResults.innerHTML = '<div class="list-group-item text-danger text-center py-3">Error retrieving data.</div>';
        }
    };

    searchResults.addEventListener('click', function(e) {
        e.preventDefault();
        const target = e.target.closest('.list-group-item-action');
        if (target) {
            const empId = target.dataset.id;
            
            // 1. Perform AJAX duplicate check BEFORE letting them fill the form
            const fd = new FormData();
            fd.append('ajax_action', 'check_duplicate');
            fd.append('employee_id', empId);

            fetch('<?= APP_BASE_PATH ?>index.php?route=create_new_checklist', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'exists') {
                    // Throw alert and block selection
                    showAlertModal("An active System Readiness checklist ('<strong>" + data.purpose + "</strong>') already exists for this employee. <br><br><a href='<?= APP_BASE_PATH ?>index.php?route=system_readiness_checklist&checklist_id=" + data.checklist_id + "' class='fw-bold text-decoration-underline'>Click here to view Checklist #" + data.checklist_id + "</a>");
                    
                    searchInput.value = '';
                    employeeIdInput.value = '';
                    empIdInput.value = '';
                    fullNameInput.value = '';
                    departmentInput.value = '';
                    positionInput.value = '';
                    emailIdInput.value = '';
                    
                    createBtn.disabled = true;
                    purposeSelect.disabled = true;
                    purposeSelect.value = '';
                    remarksWrapper.classList.add('d-none');
                    remarksTextarea.required = false;
                    
                    searchResults.style.display = 'none';
                    searchResults.innerHTML = '';
                } 
                else if (data.status === 'clear') {
                    // Safe to proceed, fill out the form
                    searchInput.value = `${target.dataset.fullName} (${target.dataset.empId})`;
                    employeeIdInput.value = target.dataset.id;
                    empIdInput.value = target.dataset.empId;
                    fullNameInput.value = target.dataset.fullName;
                    departmentInput.value = target.dataset.department;
                    positionInput.value = target.dataset.position;
                    emailIdInput.value = target.dataset.emailId;

                    searchResults.style.display = 'none';
                    searchResults.innerHTML = '';
                    
                    createBtn.disabled = false;
                    purposeSelect.disabled = false;
                    purposeSelect.value = '';
                    remarksWrapper.classList.add('d-none');
                    remarksTextarea.required = false;
                    
                    // Add slight highlight effect to auto-filled fields to show they populated
                    const inputsToHighlight = [fullNameInput, emailIdInput, departmentInput, positionInput];
                    inputsToHighlight.forEach(input => {
                        input.style.transition = 'background-color 0.3s ease';
                        input.style.backgroundColor = '#e0f2fe'; // slight primary tint
                        setTimeout(() => {
                            input.style.backgroundColor = ''; // revert to CSS default
                        }, 600);
                    });
                }
            })
            .catch(err => {
                console.error("Duplicate check failed:", err);
                showAlertModal("An error occurred while verifying employee status.");
            });
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // Re-open if clicking back into search with text
    searchInput.addEventListener('focus', function() {
        if (searchResults.innerHTML.trim() !== '') {
            searchResults.style.display = 'block';
        }
    });

    searchInput.addEventListener('input', debounce(searchEmployees, 400));
});
</script>

<?php include_once '../includes/footer.php'; ?>