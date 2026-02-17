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

        // Only load on checkout page
        $filename = $_SERVER['PHP_SELF'] ?? '';
        if (strpos($filename, 'cart.php') === false && strpos($filename, 'checkout') === false) {
            return '';
        }

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

        return <<<HTML
<style>
.paymenthood-checkout-message {
    margin-top: 15px;
    padding: 12px 15px;
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    border-radius: 4px;
    color: #004085;
    font-size: 14px;
    line-height: 1.5;
    display: none;
}

.paymenthood-checkout-message.active {
    display: block;
}

.paymenthood-profiles-container {
    margin-top: 15px;
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    display: none;
}

.paymenthood-profiles-container.active {
    display: block;
}

.paymenthood-profiles-title {
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
    cursor: default;
    transition: none;
    text-align: center;
}

.paymenthood-profile-item:hover {
    border-color: #e0e0e0;
    box-shadow: none;
}

.paymenthood-profile-icon {
    width: 60px;
    height: 40px;
    margin: 0 auto 6px;
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
    margin-bottom: 4px;
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

                console.error('PaymentHood: Icon load failed', {
                    url: direct,
                    currentSrc: img.src,
                    complete: img.complete,
                    naturalWidth: img.naturalWidth,
                    naturalHeight: img.naturalHeight,
                    event: e
                });

                // Temporarily DON'T hide so you can inspect in DevTools
                // img.style.display = 'none';

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

    function initPaymentHoodProfiles() {
        // Find all payment method inputs (radio buttons or select dropdowns)
        var paymentInputs = document.querySelectorAll('input[name="paymentmethod"], select[name="paymentmethod"], select#paymentmethod');
        var paymentInputsArr = toArray(paymentInputs);

        if (paymentInputsArr.length === 0) {
            // Try alternative selectors
            paymentInputsArr = toArray(document.querySelectorAll('.payment-methods input[type="radio"], .payment-methods select'));
        }

        if (paymentInputsArr.length === 0) {
            // Might be rendered dynamically; we re-run later.
            return;
        }

        // Find an anchor near the PaymentHood option (for insertion)
        function findPaymenthoodAnchor() {
            for (var i = 0; i < paymentInputsArr.length; i++) {
                var el = paymentInputsArr[i];
                var id = (el && el.id) ? String(el.id).toLowerCase() : '';
                var val = (el && el.value) ? String(el.value) : '';
                if (isPaymentHoodMethod(val) || id.indexOf('paymenthood') !== -1) {
                    return el;
                }
            }
            // If only a select exists, anchor to it
            var select = document.querySelector('select[name="paymentmethod"], select#paymentmethod');
            return select || paymentInputsArr[0] || null;
        }

        // Create checkout message container if it doesn't exist and message is set
        var messageContainer = document.getElementById('paymenthood-checkout-message');
        if (!messageContainer && checkoutMessage) {
            messageContainer = document.createElement('div');
            messageContainer.id = 'paymenthood-checkout-message';
            messageContainer.className = 'paymenthood-checkout-message';
            messageContainer.innerHTML = checkoutMessage;
            
            // Find PaymentHood payment method container and append message
            var anchor = findPaymenthoodAnchor();
            if (anchor) {
                var parent = anchor.closest ? (anchor.closest('.payment-method') || anchor.closest('.radio') || anchor.closest('label')) : null;
                parent = parent || anchor.parentElement;
                if (parent && parent.parentElement) {
                    parent.parentElement.insertBefore(messageContainer, parent.nextSibling);
                }
            }
        }

        // Create container for payment profiles if it doesn't exist
        var container = document.getElementById('paymenthood-profiles-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'paymenthood-profiles-container';
            container.className = 'paymenthood-profiles-container';
            container.innerHTML = '<div class="paymenthood-profiles-loading">Loading payment methods...</div>';
            
            // Find PaymentHood payment method container and append
            var anchor = findPaymenthoodAnchor();
            if (anchor) {
                var parent = anchor.closest ? (anchor.closest('.payment-method') || anchor.closest('.radio') || anchor.closest('label')) : null;
                parent = parent || anchor.parentElement;
                if (parent && parent.parentElement) {
                    // Insert after message if it exists, otherwise after parent
                    var messageEl = document.getElementById('paymenthood-checkout-message');
                    var insertAfter = messageEl || parent;
                    insertAfter.parentElement.insertBefore(container, insertAfter.nextSibling);
                }
            }
        }

        // Add change event listeners
        paymentInputsArr.forEach(function(input) {
            if (!input || input.__phBoundChange) {
                return;
            }
            input.__phBoundChange = true;
            input.addEventListener('change', function() {
                handlePaymentMethodChange(this.value);
            });
        });

        // Check if PaymentHood is already selected
        var selected = getSelectedPaymentMethod();
        if (selected) {
            handlePaymentMethodChange(selected);
        }
    }

    function handlePaymentMethodChange(selectedMethod) {
        var container = document.getElementById('paymenthood-profiles-container');
        var messageContainer = document.getElementById('paymenthood-checkout-message');
        
        if (!container) {
            return;
        }

        if (isPaymentHoodMethod(selectedMethod)) {
            // Show message if it exists
            if (messageContainer) {
                messageContainer.classList.add('active');
            }
            
            container.classList.add('active');
            
            // Load profiles if not already loaded
            if (profiles.length === 0) {
                loadPaymentProfiles();
            }
        } else {
            // Hide message
            if (messageContainer) {
                messageContainer.classList.remove('active');
            }
            
            container.classList.remove('active');
        }
    }

    function loadPaymentProfiles() {
        var container = document.getElementById('paymenthood-profiles-container');

        fetch('{$ajaxUrl}')
            .then(function(response) {
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
                        profiles = parsed.profiles;
                        displayProfiles(profiles);
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

        console.log('PaymentHood: displayProfiles called with', profilesList);
        console.log('PaymentHood: Full profiles data:', JSON.stringify(profilesList, null, 2));

        // Only show supported providers (must have provider name)
        var supportedProfiles = (profilesList || []).filter(function(p) {
            return p && p.paymentProvider && p.paymentProvider.provider;
        });
        
        console.log('PaymentHood: Supported profiles count:', supportedProfiles.length);
        
        if (supportedProfiles.length === 0) {
            container.innerHTML = '<div class="paymenthood-profiles-error">No payment methods available</div>';
            return;
        }

        var html = '<div class="paymenthood-profiles-list">';

        supportedProfiles.forEach(function(profile) {
            var directIconUrl = normalizeUrl(getProviderIconUrl(profile));
            var proxyIconUrl = getProxiedIconUrl(directIconUrl);
            var providerName = (profile.paymentProvider && profile.paymentProvider.provider) ? profile.paymentProvider.provider : '';
            var profileName = profile.paymentProfileName;

            console.log('PaymentHood: Rendering profile', {
                provider: providerName,
                profileName: profileName,
                directIconUrl: directIconUrl,
                proxyIconUrl: proxyIconUrl,
                iconUri1: profile.paymentProvider ? profile.paymentProvider.iconUri1 : null,
                iconUri2: profile.paymentProvider ? profile.paymentProvider.iconUri2 : null,
                isDarkMode: isDarkMode()
            });

            html += '<div class="paymenthood-profile-item">';
            
            if (proxyIconUrl) {
                html += '<div class="paymenthood-profile-icon">'
                    + '<img data-ph-icon="1" src="' + escapeHtml(proxyIconUrl) + '"'
                    + ' data-direct-src="' + escapeHtml(directIconUrl) + '"'
                    + ' alt="' + escapeHtml(profileName || providerName) + '" loading="eager">'
                    + '</div>';
            } else {
                console.warn('PaymentHood: No icon URL for provider:', providerName);
            }
            
            if (profileName) {
                html += '<div class="paymenthood-profile-name">' + escapeHtml(profileName) + '</div>';
            }
            html += '<div class="paymenthood-profile-type">' + escapeHtml(providerName) + '</div>';
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
