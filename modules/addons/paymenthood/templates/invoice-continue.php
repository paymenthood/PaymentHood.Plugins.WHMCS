<?php
// Variables: $redirectUrl (string)
?>
<div class="alert alert-info">A payment session is already in progress for this invoice.</div>
<a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn btn-primary btn-block">Continue to Payment</a>
