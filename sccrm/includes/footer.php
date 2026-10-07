        </div>
        <footer class="px-4 py-3 text-center" style="border-top:1px solid rgba(0,0,0,0.05);font-size:13px;color:#95a5a6;">
            &copy; <?= date('Y') ?> SCIT CRM — Social Communication IT. All rights reserved.
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>window.SCCRM_BASE = <?= json_encode($SCCRM_BASE ?? '/sccrm') ?>;</script>
    <script src="<?= htmlspecialchars($SCCRM_BASE ?? '/sccrm') ?>/js/script.js"></script>
</body>
</html>
