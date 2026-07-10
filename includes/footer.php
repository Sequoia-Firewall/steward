<?php if (isLoggedIn()): ?>
  </main><!-- /.main-content -->
</div><!-- /.page-wrapper -->
<?php else: ?>
</main>
<?php endif; ?>

<!-- Global confirm modal (used by appConfirm() in money.js) -->
<div class="modal fade" id="appConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-circle text-danger"></i> <span id="appConfirmTitle"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2" id="appConfirmMsg"></p>
        <div id="appConfirmWarn" class="alert alert-warning py-2 small mb-0" style="display:none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span id="appConfirmWarnText"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="appConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/money.js"></script>
</body>
</html>
