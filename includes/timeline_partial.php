<?php
/**
 * timeline_partial.php
 * A reusable component to display a detailed action log timeline.
 * FINAL LOGIC CORRECTION: On the main Templates page, this now specifically finds and
 * displays the timeline for the checklist where the purpose is 'New Joiner'.
 *
 * EXPECTS:
 * @var mysqli $conn - An active database connection.
 * @var int $timeline_parent_id - The ID of the primary record (e.g., employee_id).
 * @var string $timeline_action_type - The specific type of log to show.
 * @var int $checklist_id (OPTIONAL) - The ID of the specific checklist being viewed.
 * @var function can - The permission checking function from auth.php
 */

if (!isset($timeline_parent_id) || !is_numeric($timeline_parent_id)) {
    echo "<div class='alert alert-warning'>Timeline cannot be displayed: Invalid or missing parent ID.</div>";
    return;
}
if (!isset($timeline_action_type)) {
    echo "<div class='alert alert-warning'>Timeline cannot be displayed: Action Type is not specified.</div>";
    return;
}

$action_permissions = [
    'Template Management' => 'view_timeline_template_events',
    'System Readiness' => 'view_timeline_readiness_events',
    'Exit Checklist' => 'view_timeline_exit_events',
    'Gatepass' => 'view_timeline_gatepass_events', 
    'Shipment' => 'view_timeline_shipment_events'
];

$timeline_events = [];
if (isset($action_permissions[$timeline_action_type]) && can($action_permissions[$timeline_action_type])) {
    try {
        $checklist_id_to_filter = null;

        // Case 1: A specific checklist_id is provided by the parent page. Use it directly.
        if (isset($checklist_id) && is_numeric($checklist_id)) {
            $checklist_id_to_filter = $checklist_id;
        } else {
            // Case 2: We are on a general page (like Templates). Find the specific checklist based on the business rule.
            $checklist_type_map = [
                'System Readiness' => 'system_readiness',
                'Exit Checklist' => 'exit_checklist'
            ];

            if (array_key_exists($timeline_action_type, $checklist_type_map)) {
                $checklist_type_for_query = $checklist_type_map[$timeline_action_type];
                
                // NEW LOGIC: Find the specific checklist where the purpose is 'New Joiner'.
                $stmt_find_nj = $conn->prepare(
                    "SELECT id FROM checklists WHERE employee_id = ? AND checklist_type = ? AND checklist_purpose = 'New Joiner' LIMIT 1"
                );
                if ($stmt_find_nj) {
                    $stmt_find_nj->bind_param("is", $timeline_parent_id, $checklist_type_for_query);
                    $stmt_find_nj->execute();
                    $result_nj = $stmt_find_nj->get_result();
                    if ($row_nj = $result_nj->fetch_assoc()) {
                        $checklist_id_to_filter = $row_nj['id'];
                    }
                    $stmt_find_nj->close();
                }
            }
        }

        // Now, build and execute the main query.
        $sql = "SELECT template_id, action_description, performed_by, timestamp, field_changed, old_value, new_value
                FROM action_logs
                WHERE joiner_id = ? AND action_type = ?";
        
        $types = "is";
        $params = [$timeline_parent_id, $timeline_action_type];

        // If a checklist ID was found (either passed in or by the 'New Joiner' rule), add it to the filter.
        if ($checklist_id_to_filter !== null) {
            $sql .= " AND template_id = ?";
            $types .= "i";
            $params[] = $checklist_id_to_filter;
        } else if (isset($checklist_type_map[$timeline_action_type])) {
            // If it's a checklist-related type but no 'New Joiner' was found, force no results.
            $sql .= " AND 1 = 0"; 
        }

        $sql .= " ORDER BY timestamp DESC"; // Newest first
        
        $log_query = $conn->prepare($sql);
        if ($log_query === false) throw new Exception("Failed to prepare timeline query: " . $conn->error);
        
        $log_query->bind_param($types, ...$params);
        $log_query->execute();
        $log_result = $log_query->get_result();
        while ($row = $log_result->fetch_assoc()) {
            $timeline_events[] = $row;
        }
        $log_query->close();

    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Could not load timeline events: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Grouping logic remains simple.
$grouped_events = [];
if (!empty($timeline_events)) {
    $title = $timeline_action_type;
    // Add the specific checklist ID to the title if relevant
    if($checklist_id_to_filter) {
        $title .= " (Checklist #" . $checklist_id_to_filter . ")";
    }
    $grouped_events[$title] = $timeline_events;
}
?>

<style>
    /* Your original styles */
    .timeline-compact { list-style-type: none; padding-left: 0; position: relative; }
    .timeline-compact:before { content: ' '; background: #e9ecef; display: inline-block; position: absolute; left: 12px; width: 4px; height: 100%; z-index: 1; }
    .timeline-compact > li { margin: 25px 0; padding-left: 40px; }
    .timeline-compact > li:before { content: ' '; background: white; display: inline-block; position: absolute; border-radius: 50%; border: 4px solid #0C5D58; left: 2px; width: 22px; height: 22px; z-index: 2; }
    .timeline-compact .milestone-title { font-weight: 700; margin-bottom: 10px; font-size: 1.1rem; color: #0C5D58; }
    .timeline-compact .event-list { padding: 15px; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef; }
    .timeline-compact .event-item { font-size: 0.9rem; color: #495057; position: relative; padding-left: 18px; margin-bottom: 10px; }
    .timeline-compact .event-item:last-child { margin-bottom: 0; }
    .timeline-compact .event-item:before { content: '\2713'; position: absolute; left: 0; color: #198754; font-weight: bold; }
    .timeline-compact .event-details { font-size: 0.75rem; color: #6c757d; display: block; margin-top: 2px; }
    .timeline-compact .change-detail { font-size: 0.85rem; color: #495057; background-color: #e9ecef; padding: 8px 12px; border-radius: 6px; margin-top: 8px; border-left: 3px solid #0C5D58; display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem; }
    .timeline-compact .change-detail em { color: #0C5D58; font-style: italic; font-weight: 500; }
</style>

<ul class="timeline-compact">
    <?php if (empty($grouped_events)): ?>
        <li>
            <div class="milestone-title">No History Found</div>
            <div class="event-list"><div class="event-item">There is no activity recorded that matches the criteria.</div></div>
        </li>
    <?php else: ?>
        <?php foreach ($grouped_events as $milestone_name => $events_in_group): ?>
            <li>
                <div class="milestone-title"><?php echo htmlspecialchars($milestone_name); ?></div>
                <div class="event-list">
                    <?php foreach ($events_in_group as $event): ?>
                        <div class="event-item">
                            <?php echo htmlspecialchars($event['action_description']); ?>
                            <?php if ($event['field_changed'] !== null): ?>
                                <div class="change-detail">
                                    <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $event['field_changed']))); ?></strong>
                                    <span>changed from</span>
                                    <em>"<?php echo htmlspecialchars($event['old_value'] ?: 'empty'); ?>"</em>
                                    <span>to</span>
                                    <em>"<?php echo htmlspecialchars($event['new_value'] ?: 'empty'); ?>"</em>.
                                </div>
                            <?php endif; ?>
                            <span class="event-details">
                                <?php echo date('M d, Y \a\t h:i A', strtotime($event['timestamp'])); ?> by <strong><?php echo htmlspecialchars($event['performed_by']); ?></strong>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </li>
        <?php endforeach; ?>
    <?php endif; ?>
</ul>