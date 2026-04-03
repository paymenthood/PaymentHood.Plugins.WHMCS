<?php
// Variables: $manageUrl (string)
?>
<div class="alert alert-warning" role="alert">
    <strong>PaymentHood is not configured for this currency.</strong><br>
    You have not defined any payment gateway/profile for this app and currency yet.<br>
    Please configure your gateways in the PaymentHood Console:
    <a href="<?= htmlspecialchars($manageUrl) ?>" target="_blank" rel="noopener noreferrer">Manage PaymentHood Gateways</a>.
</div>
