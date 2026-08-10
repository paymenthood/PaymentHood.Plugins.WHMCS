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
// Guarded: WHMCS can include a hook file more than once, and a redeclare is a
// fatal that would take the hook (and the page) down.
if (!function_exists('paymenthood_renderOrderPaymentLink')) {

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

        // Emit the reason to the browser console as well as the module log.
        // A button that is simply absent gives nobody anything to work from;
        // this makes every skip visible where the page is being inspected.
        $bail = function ($reason, $context = []) use ($orderId) {
            PaymentHoodHandler::safeLogModuleCall('admin_order_link_skipped', array_merge([
                'orderId' => $orderId,
            ], $context), ['reason' => $reason]);

            $payload = json_encode([
                'orderId' => $orderId,
                'reason'  => $reason,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                return '';
            }

            return '<script>try{console.warn("[PaymentHood] order link skipped:",'
                . $payload . ');}catch(e){}</script>';
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

        // The gateway's display name, used to locate the Payment Method cell on
        // the page. Admins can rename the gateway, so read it rather than
        // assuming "PaymentHood".
        $displayName = trim((string) Capsule::table('tblpaymentgateways')
            ->where('gateway', 'paymenthood')
            ->where('setting', 'name')
            ->value('value'));

        $names = array_values(array_unique(array_filter([
            $displayName,
            'PaymentHood',
            'paymenthood',
        ])));

        $payload = json_encode([
            'url'       => $detailUrl,
            'paymentId' => $paymentId,
            'invoiceId' => $invoiceId,
            'names'     => $names,
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

    // Ordered placements, best first. Each returns a plan or null. The list
    // ends with document.body, so SOME placement always succeeds — an earlier
    // version stopped at theme-specific selectors and rendered nothing at all
    // on layouts that had none of them.
    var placements = [
        {
            name: 'payment method cell',
            // Directly after the gateway name in the Payment Method row, which
            // is where the payment actually belongs on this page.
            find: function () {
                // Editable form first: some order views render a select.
                var select = document.querySelector('select[name="paymentmethod"], select[name="gateway"]');
                if (select && select.parentNode) {
                    return { host: select.parentNode, ref: select.nextSibling };
                }

                // Read-only: the leaf cell whose entire text is the gateway's
                // display name. Leaf-only avoids matching an outer container
                // that merely contains the name somewhere inside it.
                var cells = document.querySelectorAll('td, th');
                for (var i = 0; i < cells.length; i++) {
                    var cell = cells[i];
                    if (cell.querySelector('table, td, th')) { continue; }

                    var text = (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
                    for (var j = 0; j < CFG.names.length; j++) {
                        if (text === String(CFG.names[j]).toLowerCase()) {
                            return { host: cell, ref: null };
                        }
                    }
                }
                return null;
            }
        },
        {
            name: 'beside invoice link',
            // Where an admin looks for payment info, and locatable in any
            // theme because the invoice id is known server-side.
            find: function () {
                var link = document.querySelector(
                    'a[href*="invoices.php"][href*="id=' + CFG.invoiceId + '"]'
                );
                if (!link || !link.parentNode) { return null; }
                return { host: link.parentNode, ref: link.nextSibling };
            }
        },
        {
            name: 'order summary table',
            find: function () {
                var cell = document.querySelector(
                    'table td a[href*="invoices.php"], table td a[href*="clientssummary.php"]'
                );
                if (!cell) { return null; }
                var row = cell.closest ? cell.closest('tr') : null;
                if (!row || !row.parentNode) { return null; }
                return { host: row.parentNode, ref: row.nextSibling, ownRow: true, asRow: true };
            }
        },
        {
            name: 'panel body',
            find: function () {
                var body = document.querySelector('.panel-body, .card-body, .widget-content');
                return body ? { host: body, ref: body.firstChild, ownRow: true } : null;
            }
        },
        {
            name: 'content area',
            find: function () {
                var area = document.querySelector(
                    '#main-body .contentarea, .contentarea, #main-body, #content, .content-wrapper'
                );
                return area ? { host: area, ref: area.firstChild, ownRow: true } : null;
            }
        },
        {
            name: 'after page heading',
            find: function () {
                var heading = document.querySelector('h1, .pageheader, .page-header, h2');
                if (!heading || !heading.parentNode) { return null; }
                return { host: heading.parentNode, ref: heading.nextSibling, ownRow: true };
            }
        },
        {
            name: 'document body (fallback)',
            find: function () {
                return document.body ? { host: document.body, ref: document.body.firstChild, ownRow: true } : null;
            }
        }
    ];

    function build() {
        if (document.querySelector('.ph-order-link')) { return; }

        for (var i = 0; i < placements.length; i++) {
            var plan = null;
            try { plan = placements[i].find(); } catch (e) { plan = null; }
            if (!plan || !plan.host) { continue; }

            var node = makeButton();

            if (plan.asRow) {
                // Host is a table section; a bare <div> there is invalid and
                // browsers hoist it out of the table.
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.colSpan = 2;
                td.appendChild(node);
                tr.appendChild(td);
                node = tr;
            } else if (plan.ownRow) {
                var row = document.createElement('div');
                row.className = 'ph-order-row';
                row.appendChild(node);
                node = row;
            }

            try {
                plan.host.insertBefore(node, plan.ref || null);
            } catch (e) {
                continue;
            }

            // Says which placement won, so a bad position can be reported
            // without guessing at the theme's markup.
            if (window.console && console.log) {
                console.log('[PaymentHood] order link placed via: ' + placements[i].name);
            }
            return;
        }

        if (window.console && console.warn) {
            console.warn('[PaymentHood] order link could not be placed');
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

} // function_exists guard

// Registered on both output points. Whichever fires first renders the button;
// the JS refuses to add a second one. Themes and WHMCS versions differ in
// which of these they call, and one missing hook must not mean no button.
add_hook('AdminAreaFooterOutput', 1, 'paymenthood_renderOrderPaymentLink');
add_hook('AdminAreaHeadOutput', 1, 'paymenthood_renderOrderPaymentLink');
