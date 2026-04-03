<?php
// Variables: $formAction (string), $invoiceId (string), $gateway (string)
?>
<div id="paymenthood-section" style="display:none">
    <div id="paymenthood-checkout-message" class="paymenthood-checkout-message" style="display:none"></div>
    <div id="paymenthood-profiles-container" class="paymenthood-profiles-container">
        <div class="paymenthood-profiles-loading">Loading payment methods...</div>
    </div>

</div>
<form id="paymenthood-form" method="post" action="<?= htmlspecialchars($formAction) ?>" style="margin-top:15px;">
    <input type="hidden" name="invoiceid" value="<?= htmlspecialchars($invoiceId) ?>">
    <input type="hidden" name="paymentmethod" value="<?= htmlspecialchars($gateway) ?>">
    <button type="submit" class="btn btn-success btn-block">Pay Now with PaymentHood</button>
</form>
