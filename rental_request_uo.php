<td>
  <?= ucfirst($row['payment_method']) ?>
  <br>
  <small class="text-muted">Amount to collect: ₱<?= number_format($row['amount_due'], 2) ?></small>
</td>
