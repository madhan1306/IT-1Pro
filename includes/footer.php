</div> <div class="toast-container" id="globalToastContainer"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
    // 1. Global Toast Function
    window.showToast = function(msg, type = 'success') {
        const toastContainer = document.getElementById('globalToastContainer');
        if (!toastContainer) return;

        const bgClass = type === 'success' ? 'text-bg-success' : 'text-bg-danger';
        
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center ${bgClass} border-0 shadow show`;
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body fw-medium">${msg}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastContainer.appendChild(toastEl);
        
        // Auto remove after 3.5 seconds
        setTimeout(() => { toastEl.remove(); }, 3500);
    };

    document.addEventListener("DOMContentLoaded", function() {
        // Initialize Feather Icons cleanly
        if (typeof feather !== 'undefined') {
            feather.replace();
        }

        // 2. Auto-Trigger Toasts from PHP Sessions globally
        <?php
            $globalMsg = null;
            $globalType = 'success';

            if (isset($_SESSION['form_success'])) {
                $globalMsg = $_SESSION['form_success'];
                unset($_SESSION['form_success']);
            } elseif (isset($_SESSION['form_error'])) {
                $globalMsg = $_SESSION['form_error'];
                $globalType = 'danger';
                unset($_SESSION['form_error']);
            } elseif (isset($_SESSION['page_message'])) {
                $globalMsg = $_SESSION['page_message']['text'] ?? '';
                $globalType = (isset($_SESSION['page_message']['type']) && $_SESSION['page_message']['type'] === 'error') ? 'danger' : ($_SESSION['page_message']['type'] ?? 'success');
                unset($_SESSION['page_message']);
            }
        ?>
        
        const phpAutoMessage = <?php echo json_encode($globalMsg); ?>;
        const phpAutoType = <?php echo json_encode($globalType); ?>;
        
        if (phpAutoMessage) {
            window.showToast(phpAutoMessage, phpAutoType);
        }
    });
</script>

<footer class="mt-auto text-center py-3 w-100" style="background-color: var(--card-bg); border-top: 1px solid var(--border-color);">
    <div class="container-fluid">
        <p class="text-muted mb-0" style="font-size: 0.75rem; font-weight: 500; letter-spacing: 0.02em;">
            &copy; <?php echo date("Y"); ?> IT One Pro - V2.2 All rights reserved. Powered by <span class="fw-bold" style="color: var(--primary-color);">Sun Technologies</span>.
        </p>
    </div>
</footer>

</body>
</html>