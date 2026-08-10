<?php
/**
 * Admin-side two-factor prompt for PaymentHood refunds.
 *
 * The PaymentHood refund and mark-as-refund endpoints are 2FA-protected. A
 * WHMCS gateway module cannot pause mid-call to ask the operator for a code,
 * so the exchange is split across two requests:
 *
 *   1. paymenthood_refund() attempts the refund. If PaymentHood answers with
 *      Invalid2FaException it records a one-shot session flag and returns an
 *      error, so WHMCS moves no money.
 *   2. This hook runs later in that same page render, sees the flag, and opens
 *      a modal. The code the operator types is written into WHMCS's own refund
 *      form, which is then resubmitted — so the retry goes through WHMCS's
 *      native refund path and its bookkeeping stays intact.
 *
 * NeedToActive2FaException is not recoverable here: the operator has no
 * authenticator enrolled, so this renders a message instead of a prompt.
 */

require_once __DIR__ . '/../../modules/addons/paymenthood/paymenthoodhandler.php';

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    try {
        $flag = PaymentHoodHandler::consumeRefund2faFlag();
        if (!$flag) {
            // No refund attempt tripped 2FA in this request. Emit nothing at
            // all, so admin pages are untouched in the normal case.
            return '';
        }

        $reason = isset($flag['reason']) ? (string) $flag['reason'] : '';
        $message = isset($flag['message']) ? (string) $flag['message'] : '';
        $invoiceId = isset($flag['invoiceId']) ? (int) $flag['invoiceId'] : 0;

        $config = json_encode([
            'reason'     => $reason,
            'message'    => $message,
            'invoiceId'  => $invoiceId,
            'consoleUrl' => PaymentHoodHandler::paymenthood_ConsoleUrl(),
            'needsSetup' => $reason === PaymentHoodHandler::REFUND_2FA_NEEDS_ACTIVATION,
        ], JSON_UNESCAPED_SLASHES);

        if ($config === false) {
            return '';
        }

        return <<<HTML
<style>
.ph2fa-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99998;display:flex;
 align-items:center;justify-content:center;padding:16px;}
.ph2fa-modal{background:#fff;color:#1f2937;border-radius:10px;max-width:440px;width:100%;
 box-shadow:0 20px 45px rgba(0,0,0,.25);font-family:inherit;overflow:hidden;}
.ph2fa-head{padding:18px 20px 0;font-size:17px;font-weight:600;}
.ph2fa-body{padding:12px 20px 4px;font-size:13px;line-height:1.55;}
.ph2fa-body p{margin:0 0 12px;}
.ph2fa-code{width:100%;box-sizing:border-box;padding:11px 13px;font-size:22px;letter-spacing:.35em;
 text-align:center;border:1px solid #cbd5e1;border-radius:7px;font-family:monospace;}
.ph2fa-code:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.18);}
.ph2fa-err{color:#b91c1c;font-size:12px;min-height:16px;margin:7px 2px 0;}
.ph2fa-foot{padding:12px 20px 18px;display:flex;gap:9px;justify-content:flex-end;}
.ph2fa-btn{padding:9px 17px;border-radius:7px;border:1px solid transparent;font-size:13px;
 font-weight:600;cursor:pointer;}
.ph2fa-btn-primary{background:#2563eb;color:#fff;}
.ph2fa-btn-primary:disabled{background:#93b4f5;cursor:not-allowed;}
.ph2fa-btn-plain{background:#f1f5f9;color:#334155;border-color:#cbd5e1;}
.ph2fa-note{padding:11px 13px;border-radius:7px;background:#fef3c7;color:#78350f;font-size:12.5px;
 line-height:1.5;margin:0 0 12px;}
</style>
<script>
(function () {
    var CONFIG = {$config};

    // WHMCS renders the refund form differently across versions and admin
    // themes, so identify it by the fields it must contain rather than by a
    // fixed id or selector.
    function findRefundForm() {
        var forms = document.getElementsByTagName('form');
        for (var i = 0; i < forms.length; i++) {
            var form = forms[i];
            if (form.querySelector('[name="refundamount"],[name="refund_amount"],[name="refundtransid"]')) {
                return form;
            }
            var action = form.querySelector('[name="action"],[name="sub"]');
            if (action && String(action.value || '').toLowerCase().indexOf('refund') !== -1) {
                return form;
            }
        }
        return null;
    }

    function applyCodeToForm(form, code) {
        var input = form.querySelector('input[name="paymenthood_otpcode"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'paymenthood_otpcode';
            form.appendChild(input);
        }
        input.value = code;
    }

    // Safety net: if the modal cannot resubmit the form itself, the code is
    // still attached to the next refund the operator submits by hand.
    function armPendingCode(code) {
        try { window.sessionStorage.setItem('paymenthood_otpcode', code); } catch (e) {}
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.querySelector) { return; }
            if (form !== findRefundForm()) { return; }
            var pending = '';
            try { pending = window.sessionStorage.getItem('paymenthood_otpcode') || ''; } catch (e) {}
            if (pending) {
                applyCodeToForm(form, pending);
                try { window.sessionStorage.removeItem('paymenthood_otpcode'); } catch (e) {}
            }
        }, true);
    }

    function buildModal() {
        var backdrop = document.createElement('div');
        backdrop.className = 'ph2fa-backdrop';

        var setupNote = CONFIG.needsSetup
            ? '<div class="ph2fa-note">' + escapeHtml(CONFIG.message) + '</div>'
            : '';

        var prompt = CONFIG.needsSetup
            ? '<p>Once Google Authenticator is enabled on your PaymentHood operator account, '
              + 'return here and submit the refund again.</p>'
              + '<p><a href="' + escapeAttr(CONFIG.consoleUrl) + '" target="_blank" rel="noopener noreferrer">'
              + 'Open the PaymentHood console</a></p>'
            : '<p>' + escapeHtml(CONFIG.message) + '</p>'
              // No maxlength: it would truncate a pasted value before the
              // digit-stripper below runs, silently eating part of the code.
              // The input handler caps the length instead.
              + '<input class="ph2fa-code" id="ph2fa-code" type="text" inputmode="numeric" '
              + 'autocomplete="one-time-code" placeholder="000000" aria-label="Authenticator code">'
              + '<div class="ph2fa-err" id="ph2fa-err"></div>';

        var buttons = CONFIG.needsSetup
            ? '<button type="button" class="ph2fa-btn ph2fa-btn-plain" id="ph2fa-close">Close</button>'
            : '<button type="button" class="ph2fa-btn ph2fa-btn-plain" id="ph2fa-close">Cancel</button>'
              + '<button type="button" class="ph2fa-btn ph2fa-btn-primary" id="ph2fa-submit" disabled>Confirm refund</button>';

        backdrop.innerHTML =
            '<div class="ph2fa-modal" role="dialog" aria-modal="true" aria-labelledby="ph2fa-title">'
            + '<div class="ph2fa-head" id="ph2fa-title">'
            + (CONFIG.needsSetup ? 'Two-factor authentication required' : 'Enter your authenticator code')
            + '</div>'
            + '<div class="ph2fa-body">' + setupNote + prompt + '</div>'
            + '<div class="ph2fa-foot">' + buttons + '</div>'
            + '</div>';

        return backdrop;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    function open() {
        if (document.getElementById('ph2fa-code') || document.querySelector('.ph2fa-backdrop')) {
            return;
        }

        var backdrop = buildModal();
        document.body.appendChild(backdrop);

        var close = function () {
            if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
        };

        var closeBtn = backdrop.querySelector('#ph2fa-close');
        if (closeBtn) { closeBtn.addEventListener('click', close); }

        document.addEventListener('keydown', function onEsc(event) {
            if (event.key === 'Escape') { close(); document.removeEventListener('keydown', onEsc); }
        });

        if (CONFIG.needsSetup) {
            return;
        }

        var codeInput = backdrop.querySelector('#ph2fa-code');
        var submitBtn = backdrop.querySelector('#ph2fa-submit');
        var errorBox = backdrop.querySelector('#ph2fa-err');

        var normalise = function () {
            codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 8);
            submitBtn.disabled = codeInput.value.length < 6;
        };

        codeInput.addEventListener('input', normalise);
        codeInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !submitBtn.disabled) {
                event.preventDefault();
                submitBtn.click();
            }
        });

        submitBtn.addEventListener('click', function () {
            var code = codeInput.value.replace(/\D/g, '');
            if (code.length < 6) { return; }

            var form = findRefundForm();
            armPendingCode(code);

            if (!form) {
                errorBox.textContent = 'Could not find the refund form. Close this dialog, '
                    + 'reopen Refund and submit again — your code will be sent with it.';
                return;
            }

            applyCodeToForm(form, code);
            try { window.sessionStorage.removeItem('paymenthood_otpcode'); } catch (e) {}

            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });

        codeInput.focus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', open);
    } else {
        open();
    }
})();
</script>
HTML;
    } catch (\Throwable $e) {
        // A refund prompt must never take the admin area down.
        PaymentHoodHandler::safeLogModuleCall('admin_refund_2fa_hook_error', [], [
            'error' => $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ]);
        return '';
    }
});
