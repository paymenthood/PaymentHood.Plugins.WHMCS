<?php
// Injected into <head> when the invoice belongs to PaymentHood.
// Hides: gateway dropdown, "Payment Method:" label, Apply Credit section.
// Does NOT hide PaymentHood button, sandbox notice, or payment-session messages.
// Variables: $blockCreditMsg (string), $blockMethodMsg (string), $isUnpaid (bool)
?>
<style>
    /* Hide gateway dropdown itself */
    select[name="gateway"],
    select[name="paymentmethod"] {
        display: none !important;
    }

    /* Hide Apply Credit controls */
    form[action*="applycredit"],
    form[action*="addcredit"],
    [data-action="applycredit"],
    [data-action="addcredit"],
    a[href*="applycredit"],
    a[href*="addcredit"],
    button[name="applycredit"],
    input[name="applycredit"],
    button[name="addcredit"],
    input[name="addcredit"] {
        display: none !important;
    }
</style>

<script>
(function () {
    function hide(el) {
        if (el) el.style.setProperty('display', 'none', 'important');
    }

    function hasPaymentHoodContent(el) {
        if (!el) return false;
        var html = (el.innerHTML || '').toLowerCase();
        return html.indexOf('paymenthood') !== -1
            || !!el.querySelector('#paymenthood-section, #paymenthood-form, [id^="paymenthood"]');
    }

    function run() {
        // ── Hide the gateway <select> ──
        var sel = document.querySelector('select[name="gateway"], select[name="paymentmethod"]');
        hide(sel);

        // ── Hide the "Payment Method:" label ──
        if (sel && sel.id) {
            hide(document.querySelector('label[for="' + sel.id + '"]'));
        }
        document.querySelectorAll('label, strong, span, th, dt').forEach(function (el) {
            var text = (el.textContent || '').trim();
            if (/^payment\s*method/i.test(text)) {
                hide(el);
            }
        });

        // ── Hide Apply Credit section ──
        // Strategy: find any form/element that contains credit-related controls,
        // then walk up to the nearest .panel/.well/.card wrapper. Only hide
        // that wrapper if it does NOT contain PaymentHood elements.

        // 1) Find forms containing credit hidden inputs or credit fields
        document.querySelectorAll('form').forEach(function (form) {
            var hasCreditAction = form.querySelector(
                'input[name="action"][value="applycredit"], '
                + 'input[name="action"][value="addcredit"]'
            );
            var hasCreditField = form.querySelector(
                'input[name="creditamount"], input[name="credit"], '
                + 'input[name="applycredit"], input[name="addcredit"], '
                + 'button[name="applycredit"], button[name="addcredit"]'
            );

            if (!hasCreditAction && !hasCreditField) return;

            // Walk up to find the panel wrapper
            var panel = form.closest('.panel, .well, .card, .box, .credit-section, .apply-credit');
            if (panel && !hasPaymentHoodContent(panel)) {
                hide(panel);
            } else {
                // No safe panel found — just hide the form itself
                hide(form);
            }
        });

        // 2) Also catch standalone credit links/buttons outside forms
        var standaloneSelectors = [
            'a[href*="applycredit"]', 'a[href*="addcredit"]',
            '[data-action="applycredit"]', '[data-action="addcredit"]'
        ];
        standaloneSelectors.forEach(function (s) {
            document.querySelectorAll(s).forEach(function (el) {
                hide(el);
            });
        });
    }

    run();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        setTimeout(run, 0);
    }
})();
</script>

<?php if ($isUnpaid): ?>
<script>
if (window.PAYMENTHOOD_CONFIG) {
    window.PAYMENTHOOD_CONFIG.blockCreditApplication = true;
    window.PAYMENTHOOD_CONFIG.blockCreditMessage = <?= json_encode($blockCreditMsg) ?>;
    window.PAYMENTHOOD_CONFIG.blockPaymentMethodSwitch = true;
    window.PAYMENTHOOD_CONFIG.blockPaymentMethodMsg = <?= json_encode($blockMethodMsg) ?>;
}
</script>
<?php endif; ?>
