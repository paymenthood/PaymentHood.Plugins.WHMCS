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

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
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

        if (
            strpos($filename, 'cart.php') === false
            && strpos($filename, 'checkout') === false
            && strpos($filename, 'viewinvoice.php') === false
        ) {
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

                $sensitive = function ($settingName) {
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
    /* Styles handled by alert alert-info classes from theme */
    margin-bottom: 12px;
    font-weight: 600;
}

.paymenthood-profiles-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 10px;
}

.paymenthood-checkout-message {
    padding: 10px;
    background: #f8f9fa; /* Light gray to contrast on any background */
    border: 1px solid #dee2e6;
    border-radius: 4px;
    margin-bottom: 20px;
    font-size: 14px;
    color: #495057; /* Dark gray for readability */
    line-height: 1.5;
}

.paymenthood-profile-item {
    padding: 8px;
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 4px;
    cursor: default;
    text-align: center;
    user-select: none;
    pointer-events: none;
}

.paymenthood-profile-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
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
    color: #999;
    font-style: italic;
    /* Prevent layout shift during loading if possible */
    min-height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    
    /* Delay showing loading text to avoid flash on fast connections/cache */
    opacity: 0;
    animation: paymenthood-fade-in 0.3s ease 0.15s forwards;
}

.paymenthood-profiles-error {
    padding: 10px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    color: #856404;
    text-align: center;
}

/* Ensure our own content fades in smoothly - but only on first appearance */
.paymenthood-profiles-container:not(.paymenthood-animated), 
.paymenthood-checkout-message:not(.paymenthood-animated) {
    animation: paymenthood-fade-in 0.3s ease-out;
}

/* Mark as animated to prevent re-triggering on subsequent shows */
.paymenthood-profiles-container.paymenthood-animated,
.paymenthood-checkout-message.paymenthood-animated {
    /* No animation on subsequent shows */
}

@keyframes paymenthood-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
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

    function getSelectedPaymentMethod() {
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

        var select = document.querySelector('select[name="paymentmethod"], select#paymentmethod');
        if (select && select.value) {
            return select.value;
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

    // ---- Visibility management (theme-agnostic) ----------------------------
    // Containers are placed as siblings AFTER #creditCardInputFields,
    // NOT inside it. This way no theme can hide them by hiding
    // #creditCardInputFields (which they do for redirect gateways).
    // We manage show/hide entirely ourselves.
    // -----------------------------------------------------------------------

    function showContainer() {
        // Safety: never force PaymentHood UI when another gateway is selected.
        if (!isInvoicePage()) {
            var currentSelected = getSelectedPaymentMethod();
            if (!isPaymentHoodMethod(currentSelected)) {
                return;
            }
        }

        var c = document.getElementById('paymenthood-profiles-container');
        var m = document.getElementById('paymenthood-checkout-message');
        var sep = document.getElementById('paymenthood-checkout-separator');
        
        // If containers don't exist, recreate them
        if (!c || !c.parentElement) {
            c = createCheckoutContainers();
            m = document.getElementById('paymenthood-checkout-message');
            sep = document.getElementById('paymenthood-checkout-separator');
            
            if (c && profiles && profiles.length > 0) {
                displayProfiles(profiles);
            } else if (c && !c.__phLoaded) {
                c.__phLoaded = true;
                loadPaymentProfiles();
            }
        }

        // Show our PaymentHood containers
        if (c) {
            c.style.display = 'block';
        }
        if (m && checkoutMessage) {
            m.style.display = 'block';
            if (!m.__phSet) {
                m.__phSet = true;
                m.innerHTML = getCheckoutMessageHtml();
            }
        }
        if (sep) {
            sep.style.display = 'block';
        }

        logToServer('show_container', {
            containerFound: !!c,
            messageFound: !!m,
            profilesCount: profiles ? profiles.length : 0
        }, {});
    }

    function hideContainer() {
        var c = document.getElementById('paymenthood-profiles-container');
        var m = document.getElementById('paymenthood-checkout-message');
        var sep = document.getElementById('paymenthood-checkout-separator');

        if (c) { c.style.display = 'none'; }
        if (m) { m.style.display = 'none'; }
        if (sep) { sep.style.display = 'none'; }
        
        logToServer('hide_container', {}, {});
    }

    function isInvoicePage() {
        var path = window.location.pathname || '';
        return path.indexOf('viewinvoice.php') !== -1;
    }

    function initPaymentHoodProfiles() {
        // Guard: once we've successfully initialized, don't re-run.
        // The retries at 500ms/1500ms are only for when #creditCardInputFields
        // isn't in the DOM yet on first attempt.
        if (window.__phInitComplete) {
            return;
        }

        logToServer('init_profiles', {
            page: window.location.pathname,
            readyState: document.readyState
        }, {});
        
        // Look for the profiles container. On viewinvoice.php it is rendered by _link()
        // and is already in the correct location for that page.
        // On the checkout page, WHMCS treats PaymentHood as a redirect-style gateway, so
        // it does NOT render _link() output inside #creditCardInputFields. We must ensure
        // our containers are moved/inserted there via createCheckoutContainers().
        var container = document.getElementById('paymenthood-profiles-container');

        logToServer('container_check', {
            containerExists: !!container
        }, {});

        if (!isInvoicePage()) {
            // Always run on checkout — this moves the container into #creditCardInputFields
            // if it exists elsewhere, or creates it fresh if it doesn't exist yet.
            container = createCheckoutContainers();
            logToServer('create_containers_result', {
                containerCreated: !!container
            }, {});
            if (!container) {
                return; // #creditCardInputFields not in DOM yet; retry via setTimeout
            }
            // Successfully created containers - mark init as complete
            window.__phInitComplete = true;
        } else if (!container) {
            // Invoice page and no container at all — unexpected, nothing to do
            return;
        } else {
            // Invoice page with existing container - mark init as complete
            window.__phInitComplete = true;
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
            // Delegate everything to apply() so the same logic runs on first
            // load, user-driven gateway changes, AND postCheckoutReload.
            bind();
            apply();
            // WHMCS often selects the first radio silently (no change event).
            // Poll every 80 ms until a gateway is selected, then run apply() once.
            var __phPollCount = 0;
            var __phPoll = setInterval(function() {
                __phPollCount++;
                if (__phPollCount > 75) { // 6-second hard stop
                    clearInterval(__phPoll);
                    return;
                }
                if (getSelectedPaymentMethod()) {
                    clearInterval(__phPoll);
                    apply();
                }
            }, 80);
        }
    }

    function createCheckoutContainers() {
        // Place containers as siblings AFTER #creditCardInputFields, NOT inside it.
        // Both WHMCS and Lagom2 hide #creditCardInputFields for redirect gateways.
        // By placing our elements outside it, theme gateway-switching logic cannot
        // interfere with our visibility.
        var ccContainer = document.getElementById('creditCardInputFields');
        if (!ccContainer || !ccContainer.parentElement) {
            logToServer('no_creditcardfields_found', {}, {});
            return null;
        }

        var targetParent = ccContainer.parentElement;

        var existingContainer = document.getElementById('paymenthood-profiles-container');
        var existingMsg       = document.getElementById('paymenthood-checkout-message');
        var existingSep       = document.getElementById('paymenthood-checkout-separator');

        // Already placed as a sibling of ccContainer?
        if (existingContainer && existingContainer.parentElement === targetParent) {
            logToServer('containers_already_in_place', {}, {});
            return existingContainer;
        }

        // Move if they exist elsewhere (e.g. rendered by WHMCS in the wrong spot)
        if (existingContainer && existingContainer.parentElement) {
            logToServer('moving_containers', {
                toParentId: targetParent.id || '',
                toParentClass: String(targetParent.className || '').substring(0, 80)
            }, {});
            // Insert in order after ccContainer
            var refNode = ccContainer.nextSibling;
            if (existingMsg) { targetParent.insertBefore(existingMsg, refNode); }
            if (existingSep) { targetParent.insertBefore(existingSep, refNode); }
            targetParent.insertBefore(existingContainer, refNode);
            return existingContainer;
        }

        // Create fresh containers
        logToServer('inserting_after_cc', {
            parentId: targetParent.id || '',
            parentClass: String(targetParent.className || '').substring(0, 80)
        }, {});

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
        if (!profiles || profiles.length === 0) {
            container.innerHTML = '<div class="paymenthood-profiles-loading">Loading payment methods...</div>';
        }

        // Insert right after #creditCardInputFields
        var ref = ccContainer.nextSibling;
        targetParent.insertBefore(msgDiv, ref);
        targetParent.insertBefore(sep, ref);
        targetParent.insertBefore(container, ref);

        logToServer('containers_inserted', {
            parentId: targetParent.id || '',
            parentClass: String(targetParent.className || '').substring(0, 80)
        }, {});

        return container;
    }

    // ── MASTER EVENT HANDLER ────────────────────────────────────────────────
    // Lightweight: just reads the current radio state and shows/hides.
    // Does NOT do any DOM creation or mutation — that is handled once during
    // init and lazily in showContainer().
    function apply() {
        if (isInvoicePage()) {
            showContainer();
            return;
        }

        var method = getSelectedPaymentMethod();

        logToServer('apply', { method: method }, {});

        // If no gateway is selected yet, wait for the next event/poll.
        if (!method) {
            return;
        }

        if (isPaymentHoodMethod(method)) {
            showContainer();
        } else {
            hideContainer();
        }
    }

    // Wire events exactly once (delegated, so they survive DOM replacement).
    var __phBound = false;
    function bind() {
        if (__phBound) { return; }
        __phBound = true;

        if (typeof jQuery !== 'undefined') {
            jQuery(document)
                .on('paymentmethodchange change',
                    'input[name="paymentmethod"], select[name="paymentmethod"]',
                    function() { setTimeout(apply, 0); })
                .on('postCheckoutReload', function() {
                    // postCheckoutReload may wipe our containers from the DOM.
                    // Recreate them once, then apply.
                    createCheckoutContainers();
                    setTimeout(apply, 0);
                });
        }

        // Native fallback (also catches non-jQuery environments)
        document.addEventListener('change', function(e) {
            if (e.target && e.target.name === 'paymentmethod') {
                setTimeout(apply, 0);
            }
        });

        // For themes like Lagom2 that use custom gateway selection UI (clickable
        // cards/tabs) which may not fire native change events on radio buttons:
        // Lightweight poll that ONLY reads radio state — no DOM mutation.
        var __phLastMethod = getSelectedPaymentMethod() || '';
        setInterval(function() {
            var current = getSelectedPaymentMethod();
            if (current && current !== __phLastMethod) {
                __phLastMethod = current;
                apply();
            }
        }, 300);
    }
    // ────────────────────────────────────────────────────────────────────────

    function loadPaymentProfiles() {
        var container = document.getElementById('paymenthood-profiles-container');
        
        // Show loading state if container is empty
        if (container && !container.hasChildNodes()) {
             container.innerHTML = '<div class="paymenthood-profiles-loading">Loading payment methods...</div>';
        }
        
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

        var html = '<div class="alert alert-info paymenthood-profiles-title" role="alert">You will be redirected to our secure payment page</div>';
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
