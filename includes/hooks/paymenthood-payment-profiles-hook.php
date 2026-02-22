<?php
/**
 * PaymentHood Checkout Payment Profiles Hook
 * Displays available payment profiles when PaymentHood gateway is selected
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../modules/addons/paymenthood/paymenthoodhandler.php';

if (!defined('paymenthood_GATEWAY')) {
    define('paymenthood_GATEWAY', 'paymenthood');
}

add_hook('ClientAreaHeadOutput', 1, function($vars) {
    try {
        $gateway = paymenthood_GATEWAY;

        // Only load on checkout page or invoice page (where gateway panels live)
        $filename = $_SERVER['PHP_SELF'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        PaymentHoodHandler::safeLogModuleCall('hook_execution_start', [
            'filename' => $filename,
            'requestUri' => $requestUri,
            'gateway' => $gateway
        ], []);
        
        if (strpos($filename, 'cart.php') === false
            && strpos($filename, 'checkout') === false
            && strpos($filename, 'viewinvoice.php') === false) {
            PaymentHoodHandler::safeLogModuleCall('hook_skipped_wrong_page', [
                'filename' => $filename
            ], []);
            return '';
        }
        
        PaymentHoodHandler::safeLogModuleCall('hook_proceeding', [
            'filename' => $filename
        ], []);

        // Get gateway settings including checkout message
        // WHMCS may normalize gateway setting keys to lowercase in some contexts/versions.
        if (!function_exists('getGatewayVariables') && defined('ROOTDIR')) {
            $gatewayFunctionsPath = ROOTDIR . '/includes/gatewayfunctions.php';
            if (is_file($gatewayFunctionsPath)) {
                require_once $gatewayFunctionsPath;
            }
        }

        $hasGetGatewayVariables = function_exists('getGatewayVariables');
        $gatewayParams = $hasGetGatewayVariables ? (array) getGatewayVariables($gateway) : [];
        $checkoutMessageSource = 'empty';

        if (isset($gatewayParams['checkoutMessage'])) {
            $checkoutMessage = $gatewayParams['checkoutMessage'];
            $checkoutMessageSource = 'gatewayVars.checkoutMessage';
        } elseif (isset($gatewayParams['checkoutmessage'])) {
            $checkoutMessage = $gatewayParams['checkoutmessage'];
            $checkoutMessageSource = 'gatewayVars.checkoutmessage';
        } else {
            $checkoutMessage = '';
        }

        // Fallback to direct DB read (case-insensitive) if gateway variables didn't return it
        if (trim((string) $checkoutMessage) === '') {
            try {
                // Pick the most recent non-empty value (handles duplicated settings rows)
                $checkoutMessage = Capsule::table('tblpaymentgateways')
                    ->whereRaw('TRIM(LOWER(gateway)) = ?', [strtolower($gateway)])
                    ->whereRaw('LOWER(setting) = ?', ['checkoutmessage'])
                    ->whereRaw("TRIM(COALESCE(value,'')) <> ''")
                    ->orderByDesc('id')
                    ->value('value');

                if (trim((string) $checkoutMessage) !== '') {
                    $checkoutMessageSource = 'db.checkoutmessage.latestNonEmpty';
                } else {
                    // Fallback: in case the value is intentionally empty but present
                    $checkoutMessage = Capsule::table('tblpaymentgateways')
                        ->whereRaw('TRIM(LOWER(gateway)) = ?', [strtolower($gateway)])
                        ->whereRaw('LOWER(setting) = ?', ['checkoutmessage'])
                        ->orderByDesc('id')
                        ->value('value');
                    if ($checkoutMessage !== null) {
                        $checkoutMessageSource = 'db.checkoutmessage.latest';
                    }
                }
            } catch (\Throwable $e) {
                $checkoutMessage = '';
            }
        }

        $checkoutMessage = trim((string) $checkoutMessage);

        // WHMCS does not always persist gateway config defaults into tblpaymentgateways
        // until an admin saves the gateway settings. Fall back to the gateway module's
        // own declared Default value so it remains configurable in code.
        if ($checkoutMessage === '') {
            try {
                if (defined('ROOTDIR')) {
                    $gatewayModulePath = ROOTDIR . '/modules/gateways/' . $gateway . '.php';
                    if (is_file($gatewayModulePath)) {
                        require_once $gatewayModulePath;
                    }
                }

                $configFn = $gateway . '_config';
                if (function_exists($configFn)) {
                    $cfg = (array) $configFn();
                    $default = $cfg['checkoutMessage']['Default'] ?? '';
                    $default = is_string($default) ? trim($default) : '';
                    if ($default !== '') {
                        $checkoutMessage = $default;
                        $checkoutMessageSource = 'fallback.gatewayConfigDefault';
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Minimal debug to help diagnose missing message without logging the content
        $debug = [
            'source' => $checkoutMessageSource,
            'length' => strlen($checkoutMessage),
            'isEmpty' => ($checkoutMessage === ''),
            'hasGetGatewayVariables' => $hasGetGatewayVariables,
            'gatewayVarKeys' => array_values(array_unique(array_map('strval', array_keys($gatewayParams)))),
        ];

        // Only log when we're missing the stored value (or falling back), to reduce noise.
        $shouldLog = ($checkoutMessageSource === 'fallback.configDefault' || $checkoutMessageSource === 'empty');

        // If still empty, log what settings exist for this gateway (names + ids only)
        if ($shouldLog && $checkoutMessage === '') {
            try {
                $rows = Capsule::table('tblpaymentgateways')
                    ->select(['id', 'gateway', 'setting', 'value'])
                    ->whereRaw('TRIM(LOWER(gateway)) = ?', [strtolower($gateway)])
                    ->orderBy('id', 'asc')
                    ->get();

                $sensitive = function($settingName) {
                    $s = strtolower((string) $settingName);
                    return (strpos($s, 'token') !== false)
                        || (strpos($s, 'secret') !== false)
                        || (strpos($s, 'password') !== false)
                        || (strpos($s, 'key') !== false)
                        || (strpos($s, 'authorization') !== false);
                };

                $settingsSummary = [];
                foreach ($rows as $r) {
                    $isSensitive = $sensitive($r->setting ?? '');
                    $settingsSummary[] = [
                        'id' => (int) ($r->id ?? 0),
                        'setting' => (string) ($r->setting ?? ''),
                        'len' => $isSensitive ? null : strlen((string) ($r->value ?? '')),
                        'redacted' => $isSensitive,
                    ];
                }

                $debug['dbRowCount'] = count($settingsSummary);
                $debug['dbSettings'] = $settingsSummary;
            } catch (\Throwable $e) {
                $debug['dbInspectError'] = $e->getMessage();
            }
        }

        if ($shouldLog) {
            PaymentHoodHandler::safeLogModuleCall('checkout_message_resolve', [], $debug);
        }
        
        // Escape for JavaScript
        $checkoutMessageJs = json_encode($checkoutMessage);

        // Get base URL for AJAX endpoint
        $systemUrl = rtrim(\WHMCS\Config\Setting::getValue('SystemURL'), '/');
        $ajaxUrl = $systemUrl . '/modules/gateways/' . rawurlencode($gateway) . '/get-payment-profiles.php';
        $iconProxyBase = $systemUrl . '/modules/gateways/' . rawurlencode($gateway) . '/get-payment-profiles.php?proxy=1&u=';

        PaymentHoodHandler::safeLogModuleCall('hook_returning_html', [
            'ajaxUrl' => $ajaxUrl,
            'iconProxyBase' => $iconProxyBase,
            'checkoutMessageLength' => strlen($checkoutMessage)
        ], []);

        return <<<HTML
<style>
.paymenthood-checkout-message {
    margin-top: 15px;
    margin-bottom: 18px;
    padding: 12px 15px;
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    border-radius: 4px;
    color: #004085;
    font-size: 14px;
    line-height: 1.5;
}

.paymenthood-profiles-container {
    margin-top: 0;
}

.paymenthood-profiles-title {
    margin-top: 2px;
    font-weight: bold;
    margin-bottom: 10px;
    color: #333;
}

.paymenthood-profiles-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
}

.paymenthood-profile-item {
    padding: 8px;
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 4px;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    text-align: center;
    user-select: none;
}

.paymenthood-profile-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.paymenthood-profile-item:hover {
    border-color: #80bdff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.paymenthood-profile-item.selected {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.2);
    background: #f0f7ff;
}

.paymenthood-profile-icon {
    width: 60px;
    height: 40px;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: visible;
}

.paymenthood-profile-icon img {
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
    display: block;
}

.paymenthood-profile-name {
    font-size: 13px;
    font-weight: 600;
    color: #333;
    margin: 0;
    line-height: 1.2;
    word-break: break-word;
}

.paymenthood-profile-type {
    font-size: 11px;
    color: #666;
    display: none;
}

.paymenthood-profiles-loading {
    text-align: center;
    padding: 20px;
    color: #666;
}

.paymenthood-profiles-error {
    padding: 10px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    color: #856404;
    text-align: center;
}
</style>

<script>
(function() {
    var profiles = [];
    var checkoutMessage = {$checkoutMessageJs};

    // Send logs to server instead of browser console
    function logToServer(action, request, response) {
        fetch('{$ajaxUrl}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                logClient: true,
                action: action,
                request: request || {},
                response: response || {}
            })
        }).catch(function() {});
    }

    function toArray(nodeList) {
        try {
            return Array.prototype.slice.call(nodeList || []);
        } catch (e) {
            return [];
        }
    }

    function getSelectedPaymentMethod() {
        var select = document.querySelector('select[name="paymentmethod"], select#paymentmethod');
        if (select && select.value) {
            return select.value;
        }

        // Prefer real radio selection. If radios exist but none are checked yet,
        // do NOT fall back to the hidden input because it can be stale.
        var radios = document.querySelectorAll('input[name="paymentmethod"][type="radio"]');
        if (radios && radios.length) {
            var checkedRadio = document.querySelector('input[name="paymentmethod"][type="radio"]:checked');
            if (checkedRadio && checkedRadio.value) {
                return checkedRadio.value;
            }
            return '';
        }

        var checked = document.querySelector('input[name="paymentmethod"]:checked');
        if (checked && checked.value) {
            return checked.value;
        }
        var hidden = document.querySelector('input[name="paymentmethod"][type="hidden"]');
        if (hidden && hidden.value) {
            return hidden.value;
        }
        return '';
    }

    function isPaymentHoodMethod(value) {
        return String(value || '').toLowerCase().indexOf('paymenthood') !== -1;
    }

    function isDarkMode() {
        try {
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        } catch (e) {
            return false;
        }
    }

    function getProviderIconUrl(profile) {
        if (!profile || !profile.paymentProvider) {
            return '';
        }

        // Always use iconUri1 (light mode) since WHMCS checkout has light background
        var light = profile.paymentProvider.iconUri1 || '';
        var dark = profile.paymentProvider.iconUri2 || '';
        
        return light || dark || '';
    }

    function normalizeUrl(url) {
        url = String(url || '').trim();
        if (!url) {
            return '';
        }
        if (url.indexOf('//') === 0) {
            return 'https:' + url;
        }
        return url;
    }

    function getProxiedIconUrl(url) {
        if (!url) {
            return '';
        }
        var proxyBase = '{$iconProxyBase}';
        return proxyBase + encodeURIComponent(url);
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function decodeHtmlEntities(str) {
        // Decodes &lt; &gt; &amp; etc. Useful when the admin message is stored as entities.
        var s = String(str || '');
        var textarea = document.createElement('textarea');
        textarea.innerHTML = s;
        return textarea.value;
    }

    function sanitizeHtml(html) {
        // Lightweight sanitizer: remove dangerous elements and JS/event handler attributes.
        // This keeps basic formatting while preventing script execution.
        var container = document.createElement('div');
        container.innerHTML = String(html || '');

        // Remove dangerous elements
        var dangerous = container.querySelectorAll('script, style, iframe, object, embed, link, meta');
        for (var i = 0; i < dangerous.length; i++) {
            if (dangerous[i] && dangerous[i].parentNode) {
                dangerous[i].parentNode.removeChild(dangerous[i]);
            }
        }

        // Walk all nodes and strip dangerous attributes
        var all = container.getElementsByTagName('*');
        for (var j = 0; j < all.length; j++) {
            var el = all[j];
            if (!el || !el.attributes) {
                continue;
            }

            // Copy attributes first because we'll mutate
            var attrs = [];
            for (var k = 0; k < el.attributes.length; k++) {
                attrs.push(el.attributes[k].name);
            }

            attrs.forEach(function(name) {
                var lower = String(name || '').toLowerCase();
                // Remove inline event handlers and inline styles
                if (lower.indexOf('on') === 0 || lower === 'style') {
                    try { el.removeAttribute(name); } catch (e) {}
                    return;
                }

                // Prevent javascript: URLs
                if (lower === 'href' || lower === 'src') {
                    var val = '';
                    try { val = String(el.getAttribute(name) || ''); } catch (e) { val = ''; }
                    if (/^\s*javascript:/i.test(val)) {
                        try { el.removeAttribute(name); } catch (e) {}
                        return;
                    }
                }
            });

            // Ensure safe rel when target=_blank
            if (el.tagName && el.tagName.toLowerCase() === 'a') {
                var target = (el.getAttribute('target') || '').toLowerCase();
                if (target === '_blank') {
                    var rel = (el.getAttribute('rel') || '');
                    if (!/\bnoopener\b/i.test(rel)) {
                        rel = (rel ? rel + ' ' : '') + 'noopener';
                    }
                    if (!/\bnoreferrer\b/i.test(rel)) {
                        rel = (rel ? rel + ' ' : '') + 'noreferrer';
                    }
                    el.setAttribute('rel', rel.trim());
                }
            }
        }

        return container.innerHTML;
    }

    function getCheckoutMessageHtml() {
        var raw = String(checkoutMessage || '');

        // If the message looks like it contains encoded tags, decode entities first.
        if (raw.indexOf('&lt;') !== -1 || raw.indexOf('&#60;') !== -1 || raw.indexOf('&gt;') !== -1 || raw.indexOf('&#62;') !== -1) {
            raw = decodeHtmlEntities(raw);
        }

        return sanitizeHtml(raw);
    }

    function attachIconFallbackHandlers(container) {
        if (!container) {
            return;
        }

        var imgs = container.querySelectorAll('img[data-ph-icon="1"]');
        imgs.forEach(function(img) {
            if (img.__phBound) {
                return;
            }
            img.__phBound = true;

            img.addEventListener('error', function(e) {
                var direct = img.getAttribute('data-direct-src') || '';

                // Best-effort server log
                fetch('{$ajaxUrl}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        logError: true,
                        errorType: 'icon_load_failed',
                        errorMessage: 'Provider icon failed to load',
                        httpStatus: null,
                        responseText: JSON.stringify({ direct: direct }),
                        stack: null
                    })
                }).catch(function() {});
            });
        });
    }

    function showContainer() {
        // Safety: never force PaymentHood UI when another gateway is selected.
        // (Important because we sometimes schedule delayed re-shows to survive WHMCS re-renders.)
        if (!isInvoicePage()) {
            var currentSelected = null;
            try {
                currentSelected = getSelectedPaymentMethod();
            } catch (e) {
                currentSelected = null;
            }

            // Prefer the last known gateway from onGatewayChange, if available
            var lastKnown = (typeof window !== 'undefined') ? window.__phLastGateway : null;
            var effective = lastKnown || currentSelected;
            if (effective && !isPaymentHoodMethod(effective)) {
                return;
            }
        }

        var c = document.getElementById('paymenthood-profiles-container');
        var m = document.getElementById('paymenthood-checkout-message');
        var sep = document.getElementById('paymenthood-checkout-separator');
        
        // If containers don't exist (WHMCS may have removed them), recreate them
        if (!c || !c.parentElement) {
            logToServer('containers_missing_recreating', {
                profilesContainerExists: !!c,
                messageContainerExists: !!m
            }, {});
            
            // Call createCheckoutContainers to recreate them
            c = createCheckoutContainers();
            m = document.getElementById('paymenthood-checkout-message');
            sep = document.getElementById('paymenthood-checkout-separator');
            
            // If profiles were already loaded before, re-display them
            if (c && profiles && profiles.length > 0) {
                displayProfiles(profiles);
            } else if (c && !c.__phLoaded) {
                // Otherwise load them fresh
                c.__phLoaded = true;
                loadPaymentProfiles();
            }
        }

        // Ensure the <br> separator exists between message and profiles (older installs may not have it)
        if (!sep && m && c && m.parentElement && c.parentElement && m.parentElement === c.parentElement) {
            try {
                sep = document.createElement('br');
                sep.id = 'paymenthood-checkout-separator';
                sep.style.display = 'none';
                c.parentElement.insertBefore(sep, c);
            } catch (e) {
                sep = null;
            }
        }
        
        // Show our PaymentHood content (force visible)
        if (c) {
            c.style.display = 'block';
            c.classList.remove('w-hidden');
        }
        if (m && checkoutMessage) {
            m.style.display = 'block';
            m.classList.remove('w-hidden');
            if (!m.__phSet) {
                m.__phSet = true;
                m.innerHTML = getCheckoutMessageHtml();
            }
        }
        if (sep) {
            sep.style.display = 'block';
        }
        
        // Also show the shared creditCardInputFields container
        var ccContainer = document.getElementById('creditCardInputFields');
        if (ccContainer) {
            ccContainer.classList.remove('w-hidden');
            ccContainer.style.display = 'block';
            
            // HIDE ALL children EXCEPT our PaymentHood containers
            // This hides: "Enter New Card Information Below", card inputs, CVV, etc.
            var children = ccContainer.children;
            for (var i = 0; i < children.length; i++) {
                var child = children[i];
                // Only show our PaymentHood divs - skip them entirely
                if (child.id === 'paymenthood-profiles-container' || child.id === 'paymenthood-checkout-message' || child.id === 'paymenthood-checkout-separator') {
                    continue; // Don't hide or mark our own containers
                }
                // Hide everything else and remember original state
                if (!child.__phOriginalDisplay) {
                    child.__phOriginalDisplay = child.style.display || '';
                }
                child.style.display = 'none';
            }
        }
        
        logToServer('show_container', {
            containerFound: !!c,
            messageFound: !!m,
            ccContainerFound: !!ccContainer,
            containerDisplay: c ? c.style.display : null,
            messageDisplay: m ? m.style.display : null,
            profilesCount: profiles ? profiles.length : 0
        }, {});
    }

    function hideContainer() {
        var c = document.getElementById('paymenthood-profiles-container');
        var m = document.getElementById('paymenthood-checkout-message');
        var sep = document.getElementById('paymenthood-checkout-separator');
        
        // Hide our PaymentHood content
        if (c) {
            c.style.display = 'none';
        }
        if (m) {
            m.style.display = 'none';
        }
        if (sep) {
            sep.style.display = 'none';
        }
        
        // RESTORE all hidden children for other gateways (EXCEPT our PaymentHood divs)
        var ccContainer = document.getElementById('creditCardInputFields');
        if (ccContainer) {
            var children = ccContainer.children;
            for (var i = 0; i < children.length; i++) {
                var child = children[i];
                // Don't restore our PaymentHood containers - they have their own visibility control
                if (child.id === 'paymenthood-profiles-container' || child.id === 'paymenthood-checkout-message' || child.id === 'paymenthood-checkout-separator') {
                    continue;
                }
                if (child.__phOriginalDisplay !== undefined) {
                    child.style.display = child.__phOriginalDisplay;
                    delete child.__phOriginalDisplay;
                }
            }
        }
        
        logToServer('hide_container', {}, {});
    }

    function isInvoicePage() {
        var path = window.location.pathname || '';
        return path.indexOf('viewinvoice.php') !== -1;
    }

    function initPaymentHoodProfiles() {
        logToServer('init_profiles', {
            page: window.location.pathname,
            readyState: document.readyState
        }, {});
        
        // Look for the profiles container. On viewinvoice.php it is rendered by _link().
        // On the checkout page it may not exist yet, so we create it as a fallback.
        var container = document.getElementById('paymenthood-profiles-container');
        
        logToServer('container_check', {
            containerExists: !!container
        }, {});

        if (!container) {
            // Fallback: create containers in the shared creditCardInputFields (checkout page)
            container = createCheckoutContainers();
            logToServer('create_containers_result', {
                containerCreated: !!container
            }, {});
            if (!container) {
                return; // retry via MutationObserver / setTimeout
            }
        }

        // Populate the checkout message placeholder once
        var messageContainer = document.getElementById('paymenthood-checkout-message');
        if (messageContainer && checkoutMessage && !messageContainer.__phSet) {
            messageContainer.__phSet = true;
            messageContainer.innerHTML = getCheckoutMessageHtml();
        }

        // Fetch profiles only once
        if (!container.__phLoaded) {
            container.__phLoaded = true;
            logToServer('loading_profiles', {}, {});
            loadPaymentProfiles();
        } else {
            logToServer('profiles_already_loaded', {}, {});
        }

        if (isInvoicePage()) {
            // On viewinvoice.php, WHMCS already shows the _link() output inside
            // the Payment Details panel for the invoice's gateway. No radio buttons
            // to toggle — just make everything visible immediately.
            logToServer('invoice_page_show', {}, {});
            showContainer();
        } else {
            logToServer('checkout_page_setup', {}, {});
            // Checkout page: wire up gateway selection change listeners
            // WHMCS uses jQuery to handle gateway switching. Native addEventListener('change')
            // does NOT fire when jQuery's .prop('checked', true) or .trigger() is used.
            // We must use jQuery event binding for reliable detection.

            if (typeof jQuery !== 'undefined') {
                // Use jQuery delegated event — works for dynamically rendered radios too
                jQuery(document).off('change.paymenthood').on('change.paymenthood',
                    'input[name="paymentmethod"], select[name="paymentmethod"]',
                    function() {
                        onGatewayChange(jQuery(this).val());
                    }
                );
                // Also listen for click on radio labels (some templates use label clicks)
                jQuery(document).off('click.paymenthood').on('click.paymenthood',
                    'input[name="paymentmethod"]',
                    function() {
                        var val = jQuery(this).val();
                        setTimeout(function() { onGatewayChange(val); }, 50);
                    }
                );
            } else {
                // Fallback: native listeners
                var paymentInputs = toArray(document.querySelectorAll(
                    'input[name="paymentmethod"], select[name="paymentmethod"], select#paymentmethod'
                ));
                paymentInputs.forEach(function(input) {
                    if (input.__phBoundChange) { return; }
                    input.__phBoundChange = true;
                    input.addEventListener('change', function() {
                        onGatewayChange(this.value);
                    });
                });
            }

            // Apply visibility for the currently selected gateway
            var selected = getSelectedPaymentMethod();
            logToServer('initial_gateway', {
                selected: selected
            }, {});
            onGatewayChange(selected);

            // Ultimate fallback: poll every 500ms to detect gateway changes
            // (handles edge cases where neither jQuery nor native events fire)
            if (!window.__phPollStarted) {
                window.__phPollStarted = true;
                var lastGateway = selected;
                setInterval(function() {
                    var current = getSelectedPaymentMethod();
                    if (current !== lastGateway) {
                        lastGateway = current;
                        onGatewayChange(current);
                    }
                    
                    // Ensure our content and shared container stay visible when PaymentHood is selected
                    if (isPaymentHoodMethod(current)) {
                        var c = document.getElementById('paymenthood-profiles-container');
                        // If WHMCS cleared our nodes, rebuild them
                        if (!c || !c.parentElement) {
                            showContainer();
                            c = document.getElementById('paymenthood-profiles-container');
                        }
                        if (c && c.style.display === 'none') {
                            c.style.display = 'block';
                        }
                        
                        var ccContainer = document.getElementById('creditCardInputFields');
                        if (ccContainer) {
                            // WHMCS templates may hide via class or inline style
                            if (ccContainer.classList.contains('w-hidden')) {
                                ccContainer.classList.remove('w-hidden');
                            }
                            if (ccContainer.classList.contains('hidden')) {
                                ccContainer.classList.remove('hidden');
                            }
                            if (ccContainer.classList.contains('d-none')) {
                                ccContainer.classList.remove('d-none');
                            }
                            if (ccContainer.hasAttribute('hidden')) {
                                ccContainer.removeAttribute('hidden');
                            }

                            var display = '';
                            try {
                                display = window.getComputedStyle(ccContainer).display;
                            } catch (e) {
                                display = ccContainer.style.display;
                            }

                            if (display === 'none') {
                                ccContainer.style.display = 'block';
                            }
                        }
                    }
                }, 500);
            }
        }
    }

    /**
     * Fallback for checkout pages where _link() hasn't rendered containers.
     * Inserts PaymentHood profiles into WHMCS's shared creditCardInputFields container.
     */
    function createCheckoutContainers() {
        logToServer('create_checkout_containers', {}, {});
        
        // WHMCS uses a single shared container for ALL gateway details
        var ccContainer = document.getElementById('creditCardInputFields');
        
        if (!ccContainer) {
            logToServer('no_creditCardInputFields', {}, {});
            return null;
        }
        
        logToServer('found_creditCardInputFields', {
            className: ccContainer.className,
            hasContent: ccContainer.innerHTML.length > 0
        }, {});

        // Clear any existing content (from other gateways) - WHMCS will manage this
        // But check if our containers already exist first
        var existingContainer = document.getElementById('paymenthood-profiles-container');
        if (existingContainer) {
            logToServer('containers_already_exist', {}, {});
            return existingContainer;
        }

        var msgDiv = document.createElement('div');
        msgDiv.id = 'paymenthood-checkout-message';
        msgDiv.className = 'paymenthood-checkout-message';
        msgDiv.style.display = 'none';

        var sep = document.createElement('br');
        sep.id = 'paymenthood-checkout-separator';
        sep.style.display = 'none';

        var container = document.createElement('div');
        container.id = 'paymenthood-profiles-container';
        container.className = 'paymenthood-profiles-container';
        container.style.display = 'none';
        container.innerHTML = '<div class="paymenthood-profiles-loading">Loading payment methods...</div>';

        // Append to the shared creditCardInputFields container
        ccContainer.appendChild(msgDiv);
        ccContainer.appendChild(sep);
        ccContainer.appendChild(container);
        
        logToServer('containers_inserted', {
            parentId: ccContainer.id,
            parentClass: ccContainer.className
        }, {});

        return container;
    }

    function cancelPendingShows() {
        if (typeof window === 'undefined') {
            return;
        }
        var timers = window.__phPendingShowTimeouts;
        if (timers && timers.length) {
            for (var i = 0; i < timers.length; i++) {
                try {
                    clearTimeout(timers[i]);
                } catch (e) {}
            }
        }
        window.__phPendingShowTimeouts = [];
    }

    function scheduleShowIfStillSelected(token, delayMs) {
        if (typeof window === 'undefined') {
            return;
        }
        var id = setTimeout(function() {
            // Only show if this is the latest gateway-change sequence
            if (window.__phGatewayToken !== token) {
                return;
            }
            // And only if PaymentHood is STILL selected
            var current = null;
            try {
                current = getSelectedPaymentMethod();
            } catch (e) {
                current = null;
            }
            if (!isInvoicePage() && current && !isPaymentHoodMethod(current)) {
                return;
            }
            showContainer();
        }, delayMs);

        if (!window.__phPendingShowTimeouts) {
            window.__phPendingShowTimeouts = [];
        }
        window.__phPendingShowTimeouts.push(id);
    }

    function onGatewayChange(selectedMethod) {
        var isPaymentHood = isPaymentHoodMethod(selectedMethod);

        if (typeof window !== 'undefined') {
            window.__phLastGateway = selectedMethod;
            // Increment token for every gateway change; used to cancel delayed shows
            window.__phGatewayToken = (window.__phGatewayToken || 0) + 1;
        }

        // Cancel any pending delayed showContainer calls from a previous selection
        cancelPendingShows();
        
        logToServer('gateway_change', {
            selectedMethod: selectedMethod,
            isPaymentHood: isPaymentHood
        }, {});

        if (isPaymentHood) {
            // WHMCS sometimes re-renders the shared creditCardInputFields shortly after
            // the change event, which can hide/clear our content. Re-apply visibility.
            showContainer();
            var token = (typeof window !== 'undefined') ? window.__phGatewayToken : 0;
            scheduleShowIfStillSelected(token, 250);
            scheduleShowIfStillSelected(token, 1000);
        } else {
            hideContainer();
        }
    }

    function loadPaymentProfiles() {
        var container = document.getElementById('paymenthood-profiles-container');
        
        logToServer('fetch_profiles', {
            url: '{$ajaxUrl}'
        }, {});

        fetch('{$ajaxUrl}')
            .then(function(response) {
                logToServer('fetch_response', {
                    status: response.status
                }, {});
                return response.text().then(function(text) {
                    var parsed;
                    try {
                        parsed = text ? JSON.parse(text) : null;
                    } catch (e) {
                        parsed = null;
                    }

                    if (!parsed) {
                        // Not JSON at all
                        showError('Invalid response from server');
                        return;
                    }

                    if (parsed.success && parsed.profiles) {
                        logToServer('profiles_received', {
                            count: parsed.profiles.length
                        }, {});
                        profiles = parsed.profiles;
                        displayProfiles(profiles);
                        // After rendering profiles, ensure container is visible
                        // if PaymentHood is currently selected (handles race condition)
                        var current = getSelectedPaymentMethod();
                        logToServer('after_display', {
                            currentGateway: current
                        }, {});
                        if (isPaymentHoodMethod(current) || isInvoicePage()) {
                            showContainer();
                        }
                        return;
                    }

                    // Error case: show message + status
                    var status = parsed.httpStatus ? (' (HTTP ' + parsed.httpStatus + ')') : '';
                    var message = (parsed.error ? parsed.error : 'Failed to load payment methods') + status;

                    // Log details to server
                    fetch('{$ajaxUrl}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            logError: true,
                            errorType: 'fetch_profiles_failed',
                            errorMessage: message,
                            httpStatus: parsed.httpStatus || null,
                            responseText: text,
                            stack: null
                        })
                    }).catch(function() {});

                    showError(message);
                });
            })
            .catch(function(error) {
                // Network error (DNS, blocked, etc.)
                fetch('{$ajaxUrl}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        logError: true,
                        errorType: 'fetch_profiles_network_error',
                        errorMessage: error && error.message ? error.message : 'Network error',
                        httpStatus: null,
                        responseText: null,
                        stack: error && error.stack ? error.stack : null
                    })
                }).catch(function() {});

                showError('Failed to load payment methods. Please try again.');
            });
    }

    var selectedProfileId = null;

    function selectProfile(profileId) {
        selectedProfileId = profileId;

        // Update visual selection
        var items = document.querySelectorAll('.paymenthood-profile-item');
        items.forEach(function(el) {
            if (el.getAttribute('data-profile-id') === String(profileId)) {
                el.classList.add('selected');
            } else {
                el.classList.remove('selected');
            }
        });

        // Persist selection to session via POST
        fetch('{$ajaxUrl}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profileId: profileId })
        }).catch(function() {});
    }

    function displayProfiles(profilesList) {
        var container = document.getElementById('paymenthood-profiles-container');
        
        logToServer('display_profiles', {
            count: profilesList ? profilesList.length : 0
        }, {});

        // Show profiles when we have at least a name to display.
        // Some profiles may not include paymentProvider.provider but do have paymentProfileName.
        var supportedProfiles = (profilesList || []).filter(function(p) {
            return p && (p.paymentProfileName || (p.paymentProvider && p.paymentProvider.provider));
        });
        
        logToServer('supported_profiles', {
            count: supportedProfiles.length
        }, {});

        if (supportedProfiles.length === 0) {
            container.innerHTML = '<div class="paymenthood-profiles-error">No payment methods available</div>';
            return;
        }

        var html = '<div class="paymenthood-profiles-title">You will be redirected to our secure payment page</div>';
        html += '<div class="paymenthood-profiles-list">';

        supportedProfiles.forEach(function(profile) {
            var profileId = profile.paymentProfileId || profile.checkoutMethod || '';
            var directIconUrl = normalizeUrl(getProviderIconUrl(profile));
            var proxyIconUrl = getProxiedIconUrl(directIconUrl);
            var providerName = (profile.paymentProvider && profile.paymentProvider.provider) ? profile.paymentProvider.provider : '';
            var profileName = profile.paymentProfileName;
            var displayName = profileName || '';

            html += '<div class="paymenthood-profile-item" data-profile-id="' + escapeHtml(String(profileId)) + '">';

            html += '<div class="paymenthood-profile-header">';
            if (proxyIconUrl) {
                html += '<div class="paymenthood-profile-icon">'
                    + '<img data-ph-icon="1" src="' + escapeHtml(proxyIconUrl) + '"'
                    + ' data-direct-src="' + escapeHtml(directIconUrl) + '"'
                    + ' alt="' + escapeHtml(displayName) + '" loading="eager">'
                    + '</div>';
            }

            if (displayName) {
                html += '<div class="paymenthood-profile-name">' + escapeHtml(displayName) + '</div>';
            }
            html += '</div>';

            // Optional: keep provider label (currently hidden via CSS)
            if (providerName && profileName && providerName !== profileName) {
                html += '<div class="paymenthood-profile-type">' + escapeHtml(providerName) + '</div>';
            }
            html += '</div>';
        });

        html += '</div>';
        container.innerHTML = html;

        attachIconFallbackHandlers(container);

        // Bind click handlers for selection
        var items = container.querySelectorAll('.paymenthood-profile-item');
        items.forEach(function(el) {
            el.addEventListener('click', function() {
                selectProfile(el.getAttribute('data-profile-id'));
            });
        });

        // Auto-select first profile
        if (supportedProfiles.length > 0) {
            var firstId = supportedProfiles[0].paymentProfileId || supportedProfiles[0].checkoutMethod || '';
            selectProfile(firstId);
        }
    }

    // React to OS/theme changes
    try {
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var onSchemeChange = function() {
                if (profiles && profiles.length > 0) {
                    displayProfiles(profiles);
                }
            };
            if (typeof mq.addEventListener === 'function') {
                mq.addEventListener('change', onSchemeChange);
            } else if (typeof mq.addListener === 'function') {
                mq.addListener(onSchemeChange);
            }
        }
    } catch (e) {
        // no-op
    }

    function showError(message) {
        var container = document.getElementById('paymenthood-profiles-container');
        container.innerHTML = '<div class="paymenthood-profiles-error">' + message + '</div>';
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPaymentHoodProfiles);
    } else {
        initPaymentHoodProfiles();
    }

    // Also try after a short delay to handle dynamic content
    setTimeout(initPaymentHoodProfiles, 500);

    // Watch for checkout templates that render payment methods late/re-render sections
    try {
        if (window.MutationObserver && document.body) {
            var phInitTimer = null;
            var scheduleInit = function() {
                if (phInitTimer) {
                    clearTimeout(phInitTimer);
                }
                phInitTimer = setTimeout(initPaymentHoodProfiles, 100);
            };
            var mo = new MutationObserver(function() {
                scheduleInit();
            });
            mo.observe(document.body, { childList: true, subtree: true });
        }
    } catch (e) {
        // no-op
    }
})();
</script>
HTML;

    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('paymenthood_profiles_hook_error', [], [
            'error' => $e->getMessage(),
        ]);
        return '';
    }
});
