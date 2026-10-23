<?php

include_once '../includes/error_handler.php';
include_once 'includes/db_connect.php';
include_once 'includes/auth.php';

// Check if a gatepass ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Error: Invalid Gatepass ID.");
}

$id = (int)$_GET['id'];

// Fetch the main gatepass request details
$stmt = $conn->prepare("SELECT * FROM gatepass_requests WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Error: Gatepass not found.");
}
$gatepass = $result->fetch_assoc();
$stmt->close();

// Fetch associated items
$gatepass_items = [];
$items_stmt = $conn->prepare("SELECT * FROM gatepass_items WHERE gatepass_id = ? ORDER BY id ASC");
$items_stmt->bind_param("i", $id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
while ($item = $items_result->fetch_assoc()) {
    $gatepass_items[] = $item;
}
$items_stmt->close();

// Fetch vendor details if applicable
$vendor_details = null;
if ($gatepass['gatepass_type'] === 'Vendor' && !empty($gatepass['vendor_id'])) {
    $vendor_stmt = $conn->prepare("SELECT * FROM gp_vendors WHERE id = ?");
    $vendor_stmt->bind_param("i", $gatepass['vendor_id']);
    $vendor_stmt->execute();
    $vendor_result = $vendor_stmt->get_result();
    if ($vendor_result->num_rows === 1) {
        $vendor_details = $vendor_result->fetch_assoc();
    }
    $vendor_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gatepass <?php echo htmlspecialchars($gatepass['gatepass_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: sans-serif;
        }
        .gatepass-container {
            max-width: 800px;
            margin: 2rem auto;
            background: #fff;
            border: 1px solid #dee2e6;
            padding: 2rem;
        }
        .gatepass-header {
            text-align: center;
            border-bottom: 2px solid #0C5D58;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        .gatepass-header h2 {
            color: #0C5D58;
            margin: 0;
            font-weight: bold;
        }
        .gatepass-section h5 {
            background-color: #e9ecef;
            padding: 0.5rem;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
            color: #343a40;
            font-size: 1rem;
        }
        .detail-label {
            font-weight: bold;
            color: #495057;
        }
        .detail-value {
            padding-left: 10px;
        }
        .detail-row {
            border-bottom: 1px solid #f1f1f1;
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }
        .items-table th {
            background-color: #f8f9fa;
        }
        .print-button-container {
            text-align: center;
            padding: 1rem;
        }

        @media print {
            body {
                background-color: #fff;
            }
            .gatepass-container {
                margin: 0;
                border: none;
                padding: 0;
            }
            .print-button-container {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-button-container">
        <button class="btn btn-primary" onclick="window.print();">
            <i data-feather="printer" class="me-1"></i> Download PDF / Print
        </button>
    </div>
    <div class="gatepass-container">
        <div class="gatepass-header">
            <h2>GATEPASS</h2>
        </div>

        <div class="row mb-4">
            <div class="col-6">
                <span class="detail-label">Gatepass No:</span>
                <span class="detail-value"><?php echo htmlspecialchars($gatepass['gatepass_no']); ?></span>
            </div>
            <div class="col-6 text-end">
                 <span class="detail-label">Date:</span>
                 <span class="detail-value"><?php echo date('d-M-Y', strtotime($gatepass['created_at'])); ?></span>
            </div>
            <div class="col-6">
                <span class="detail-label">Type:</span>
                <span class="detail-value"><?php echo htmlspecialchars($gatepass['gatepass_type']); ?></span>
            </div>
             <div class="col-6 text-end">
                <span class="detail-label">Status:</span>
                <span class="detail-value fw-bold"><?php echo htmlspecialchars($gatepass['status']); ?></span>
            </div>
        </div>

        <?php if ($gatepass['gatepass_type'] === 'Employee'): ?>
        <div class="gatepass-section">
            <h5>Employee Details</h5>
            <div class="row detail-row"><div class="col-4 detail-label">Full Name</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['employee_name'] ?: 'N/A'); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">Department</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['department'] ?: 'N/A'); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">Position</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['position'] ?: 'N/A'); ?></div></div>
        </div>
        <?php endif; ?>

        <?php if ($gatepass['gatepass_type'] === 'Vendor' && $vendor_details): ?>
        <div class="gatepass-section">
            <h5>Vendor Details</h5>
            <div class="row detail-row"><div class="col-4 detail-label">Vendor Name</div><div class="col-8 detail-value"><?php echo htmlspecialchars($vendor_details['vendor_name'] ?: 'N/A'); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">Pickup Person</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['pickup_person'] ?: 'N/A'); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">Mobile No.</div><div class="col-8 detail-value"><?php echo htmlspecialchars($vendor_details['contact_mobile'] ?: 'N/A'); ?></div></div>
        </div>
        <?php endif; ?>

        <?php if ($gatepass['gatepass_type'] === 'Site-to-Site'): ?>
        <div class="gatepass-section">
            <h5>Movement Details</h5>
            <div class="row detail-row"><div class="col-4 detail-label">From Site</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['from_location'] ?: 'N/A'); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">To Site</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['to_location'] ?: 'N/A'); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">Requested By</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['requested_by'] ?: 'N/A'); ?></div></div>
        </div>
        <?php endif; ?>

        <div class="gatepass-section">
            <h5>Asset Details</h5>
            <table class="table table-bordered table-sm items-table">
                <thead><tr><th>Sl. No.</th><th>Product Description</th><th>Qty</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($gatepass_items as $index => $item): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo nl2br(htmlspecialchars($item['description'])); ?></td>
                        <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                        <td><?php echo htmlspecialchars($item['remarks']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="row detail-row mt-3"><div class="col-4 detail-label">Reason</div><div class="col-8 detail-value"><?php echo nl2br(htmlspecialchars($gatepass['reason'] ?: 'N/A')); ?></div></div>
            <div class="row detail-row"><div class="col-4 detail-label">Ticket No.</div><div class="col-8 detail-value"><?php echo htmlspecialchars($gatepass['ticket_no'] ?: 'N/A'); ?></div></div>
        </div>

        <div class="gatepass-section">
            <h5>Authorization</h5>
            <div class="row mt-5">
                <div class="col-6 text-center">
                    <hr class="mx-auto" style="width: 80%;">
                    <p class="mb-0">Prepared By</p>
                    <p class="fw-bold"><?php echo htmlspecialchars($gatepass['prepared_by'] ?: 'N/A'); ?></p>
                </div>
                <div class="col-6 text-center">
                     <hr class="mx-auto" style="width: 80%;">
                    <p class="mb-0">Approved By</p>
                    <p class="fw-bold"><?php echo htmlspecialchars($gatepass['approved_by'] ?: 'Pending'); ?></p>
                </div>
            </div>
        </div>

    </div>

    <script>
      feather.replace();
    </script>
</body>
</html>