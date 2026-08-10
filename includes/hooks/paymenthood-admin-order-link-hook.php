<?php
/**
 * "View in PaymentHood" button on the admin order detail page.
 *
 * Shown only when the order was paid through PaymentHood and a PaymentHood
 * payment id is known for its invoice. The invoice id is the reference number
 * shared between WHMCS and PaymentHood, and the callback stores the resulting
 * payment id on the invoice as the custom field `paymenthood_payment_id`.
 *
 * Target: {console}/{appId}/payments/{paymentId}
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../modules/addons/paymenthood/paymenthoodhandler.php';

/**
 * Build the button markup, or '' when it does not apply.
 *
 * Every bail-out after the page check is logged, because a silently absent
 * button is impossible to diagnose from the outside.
 */
function paymenthood_renderOrderPaymentLink($vars)
{
    try {
        // Identify the admin order page. $vars['filename'] is the normal
        // source; some admin contexts omit it, so fall back to the script name
        // rather than bailing out on a missing key.
        $filename = isset($vars['filename']) ? (string) $vars['filename'] : '';
        if ($filename === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $filename = basename((string) $_SERVER['SCRIPT_NAME']);
        }

        if (strpos($filename, 'orders') === false) {
            return '';
        }

        // Any single-order view, whatever the action. Requiring action=view
        // was too strict: the order page is also reached with other actions,
        // and with none at all. No id means the orders list, where there is
        // nothing to link to.
        $orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($orderId <= 0) {
            return '';
        }

        $bail = function ($reason, $context = []) use ($orderId) {
            PaymentHoodHandler::safeLogModuleCall('admin_order_link_skipped', array_merge([
                'orderId' => $orderId,
            ], $context), ['reason' => $reason]);
            return '';
        };

        $order = Capsule::table('tblorders')
            ->select('id', 'invoiceid', 'paymentmethod')
            ->where('id', $orderId)
            ->first();

        if (!$order) {
            return $bail('order not found');
        }

        $invoiceId = (int) ($order->invoiceid ?? 0);

        // The order carries a payment method, but so does the invoice, and they
        // can differ if the method was changed after ordering. Accept either.
        $isPaymentHood = strtolower((string) ($order->paymentmethod ?? '')) === 'paymenthood';
        if (!$isPaymentHood && $invoiceId > 0) {
            $invoiceMethod = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymentmethod');
            $isPaymentHood = strtolower((string) $invoiceMethod) === 'paymenthood';
        }

        if ($invoiceId <= 0) {
            return $bail('order has no invoice');
        }

        if (!$isPaymentHood) {
            return $bail('order is not paid via PaymentHood', [
                'orderPaymentMethod' => (string) ($order->paymentmethod ?? ''),
            ]);
        }

        // Payment id and app id are stored against the invoice by the callback.
        $fieldIds = Capsule::table('tblcustomfields')
            ->where('type', 'invoice')
            ->whereIn('fieldname', ['paymenthood_payment_id', 'paymenthood_app_id'])
            ->pluck('id', 'fieldname');

        $valueFor = function ($fieldName) use ($fieldIds, $invoiceId) {
            $fieldId = isset($fieldIds[$fieldName]) ? (int) $fieldIds[$fieldName] : 0;
            if ($fieldId <= 0) {
                return '';
            }

            return trim((string) Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', $invoiceId)
                ->value('value'));
        };

        $paymentId = $valueFor('paymenthood_payment_id');
        if ($paymentId === '') {
            // No payment recorded yet (unpaid, or paid before the field
            // existed). A button to nowhere is worse than no button.
            return $bail('no paymenthood_payment_id stored for this invoice', [
                'invoiceId'      => $invoiceId,
                'customFieldIds' => is_array($fieldIds) ? $fieldIds : (array) $fieldIds,
            ]);
        }

        // Prefer the app the payment was actually made under; fall back to the
        // currently configured one so older invoices still resolve.
        $appId = $valueFor('paymenthood_app_id');
        if ($appId === '') {
            $credentials = PaymentHoodHandler::getGatewayCredentials();
            $appId = trim((string) ($credentials['appId'] ?? ''));
        }

        if ($appId === '') {
            return $bail('no appId on the invoice and none configured on the gateway', [
                'invoiceId' => $invoiceId,
                'paymentId' => $paymentId,
            ]);
        }

        $detailUrl = PaymentHoodHandler::paymenthood_ConsoleUrl()
            . '/' . rawurlencode($appId)
            . '/payments/' . rawurlencode($paymentId);

        $payload = json_encode([
            'url'       => $detailUrl,
            'paymentId' => $paymentId,
            'invoiceId' => $invoiceId,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return '';
        }

        return <<<HTML
<style>
/* Inherits the admin theme's .btn styling; this only handles spacing and the
   dimmed payment id, so it sits in the page rather than on top of it. */
.ph-order-link{margin-left:8px;white-space:nowrap;}
.ph-order-link .ph-order-id{opacity:.75;font-weight:400;margin-left:5px;}
.ph-order-row{margin:12px 0;}
</style>
<script>
(function () {
    var CFG = {$payload};

    function makeButton() {
        var a = document.createElement('a');
        // Use the admin theme's own button classes so it looks native.
        a.className = 'btn btn-primary btn-sm ph-order-link';
        a.href = CFG.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.appendChild(document.createTextNode('View in PaymentHood'));

        var id = document.createElement('span');
        id.className = 'ph-order-id';
        id.appendChild(document.createTextNode('#' + CFG.paymentId));
        a.appendChild(id);

        return a;
    }

    // Ordered placements, best first. Each returns a plan or null.
    var placements = [
        // 1. Beside this order's invoice link. That is where an admin looks for
        //    payment information, and the invoice id is known server-side so it
        //    can be matched without depending on any theme's markup.
        function () {
            var link = document.querySelector(
                'a[href*="invoices.php"][href*="id=' + CFG.invoiceId + '"]'
            );
            if (!link || !link.parentNode) { return null; }
            return { host: link.parentNode, ref: link.nextSibling };
        },
        // 2. Inside the first content panel, as its own row.
        function () {
            var body = document.querySelector(
                '#main-body .panel-body, .contentarea .panel-body, .panel-body'
            );
            return body ? { host: body, ref: null, ownRow: true } : null;
        },
        // 3. End of the content area — still inside the card, not floating.
        function () {
            var area = document.querySelector('#main-body .contentarea, .contentarea, #main-body');
            return area ? { host: area, ref: null, ownRow: true } : null;
        },
        // 4. Straight after the page heading. Inside the content flow of
        //    essentially any layout, including themes not recognised above.
        function () {
            var heading = document.querySelector('h1, .pageheader, .page-header');
            if (!heading || !heading.parentNode) { return null; }
            return { host: heading.parentNode, ref: heading.nextSibling, ownRow: true };
        },
        // 5. Last resort: end of the document. Not pretty, but a reachable
        //    button beats a silently missing one.
        function () {
            return document.body ? { host: document.body, ref: null, ownRow: true } : null;
        }
    ];

    function build() {
        if (document.querySelector('.ph-order-link')) { return; }

        for (var i = 0; i < placements.length; i++) {
            var plan = null;
            try { plan = placements[i](); } catch (e) { plan = null; }
            if (!plan || !plan.host) { continue; }

            var node = makeButton();

            if (plan.ownRow) {
                var row = document.createElement('div');
                row.className = 'ph-order-row';
                row.appendChild(node);
                node = row;
            }

            plan.host.insertBefore(node, plan.ref || null);
            return;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
</script>
HTML;
    } catch (\Throwable $e) {
        // A convenience link must never break the order page.
        PaymentHoodHandler::safeLogModuleCall('admin_order_link_error', [
            'orderId' => isset($_GET['id']) ? (int) $_GET['id'] : null,
        ], [
            'error' => $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ]);
        return '';
    }
}

// Registered on both output points. Whichever fires first renders the button;
// the JS refuses to add a second one. Themes and WHMCS versions differ in
// which of these they call, and one missing hook must not mean no button.
add_hook('AdminAreaFooterOutput', 1, 'paymenthood_renderOrderPaymentLink');
add_hook('AdminAreaHeadOutput', 1, 'paymenthood_renderOrderPaymentLink');
