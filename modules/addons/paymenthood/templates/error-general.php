<?php
// Variables: $errorMessage (string), $details (string|null), $actionUrl (string|null), $actionLabel (string|null)
?>
<div class="alert alert-danger">
	<div><?= htmlspecialchars($errorMessage) ?></div>
	<?php if (!empty($details)): ?>
		<div style="margin-top:8px;"><strong>Details:</strong> <?= nl2br(htmlspecialchars($details)) ?></div>
	<?php endif; ?>
	<?php if (!empty($actionUrl)): ?>
		<div style="margin-top:12px;">
			<a href="<?= htmlspecialchars($actionUrl) ?>" target="_blank" rel="noopener"
			   style="padding:8px 16px;background:#007bff;color:#fff;border-radius:4px;text-decoration:none;display:inline-block;">
				<?= htmlspecialchars($actionLabel ?? 'Open PaymentHood') ?>
			</a>
		</div>
	<?php endif; ?>
</div>
