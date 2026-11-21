<script>
  // Phone reveal click handler
  $(document).on('click', '.phone-reveal-btn', function () {
      var btn = $(this);
      var recordId = btn.data('record-id');
      var source = btn.data('source');
      var table = btn.data('table');
      if (!recordId) return;
      btn.prop('disabled', true).find('i').toggleClass('bi-eye bi-hourglass-top');
      $.post('/callnowCRM/php_scripts/phone_reveal.php', {record_id: record_id, source: source, table: table, csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'}, function (resp) {
          btn.prop('disabled', false).find('i').toggleClass('bi-hourglass-top bi-eye');
          if (resp.success && resp.mobile) {
              btn.closest('td').find('.phone-masked').replaceWith('<a href="tel:' + resp.mobile + '" class="text-decoration-none font-monospace fw-bold text-success"><i class="bi bi-telephone me-1"></i>' + resp.mobile + '</a>');
              btn.remove();
          } else {
              alert('Cannot reveal number: ' + (resp.error || 'Access denied'));
          }
      }, 'json').fail(function () {
          btn.prop('disabled', false).find('i').toggleClass('bi-hourglass-top bi-eye');
          alert('Server error while revealing number.');
      });
  });
</script>