<?php
// FILE: /pages/portal_card.partial.php (Included by portals.php)
// Requires: $portal, APP_BASE_PATH

// Ensure $portal is set
if (!isset($portal)) return;
?>
<a href="<?php echo htmlspecialchars($portal['url']); ?>" class="portal-btn" target="_blank" rel="noopener noreferrer" title="<?php echo htmlspecialchars($portal['name']); ?>">
    <?php if (!empty($portal['icon_path'])): ?>
        <img src="<?php echo APP_BASE_PATH . htmlspecialchars($portal['icon_path']); ?>" alt="<?php echo htmlspecialchars($portal['name']); ?> Icon" class="portal-btn-icon">
    <?php else: ?>
        <span class="portal-btn-icon-placeholder">
            <i data-feather="globe"></i>
        </span>
    <?php endif; ?>
    <span class="portal-btn-name"><?php echo htmlspecialchars($portal['name']); ?></span>
</a>