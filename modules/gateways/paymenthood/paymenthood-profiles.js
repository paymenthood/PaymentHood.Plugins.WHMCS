(function() {
    var config = window.PAYMENTHOOD_CONFIG || {};
    var profiles = [];
    var checkoutMessage = config.checkoutMessage || '';
    var ajaxUrl = config.ajaxUrl || '';
    var iconProxyBase = config.iconProxyBase || '';
    var templateUrl = config.templateUrl || '';

    // ── HTML Template engine ────────────────────────────────────────────
    // Fetches paymenthood-profiles.html once, caches parsed <template>
    // elements, and provides getTemplate(id) to clone them.
    var _templateDoc = null;
    var _templatePromise = null;

    function loadTemplates() {
        if (_templatePromise) { return _templatePromise; }
        _templatePromise = fetch(templateUrl)
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                _templateDoc = parser.parseFromString(html, 'text/html');
            });
        return _templatePromise;
    }

    /** Clone a <template> by its id and optionally replace {{placeholders}} */
    function getTemplate(id, replacements) {
        if (!_templateDoc) { return null; }
        var tpl = _templateDoc.getElementById(id);
        if (!tpl || !tpl.content) { return null; }
        if (replacements) {
            // Substitute against the template's RAW markup, before anything is
            // turned into elements. Materialising first (appendChild of the
            // clone) makes the browser immediately fetch the literal
            // "{{proxyIconUrl}}" as a URL — a guaranteed 404 on every render —
            // because an <img src> starts loading even while detached.
            var markup = tpl.innerHTML;
            Object.keys(replacements).forEach(function(key) {
                // Escape the replacement value for safe insertion into HTML
                markup = markup.split('{{' + key + '}}').join(escapeHtml(replacements[key]));
            });
            var tmp = document.createElement('div');
            tmp.innerHTML = markup;
            // Return a DocumentFragment
            var frag = document.createDocumentFragment();
            while (tmp.firstChild) { frag.appendChild(tmp.firstChild); }
            return frag;
        }
        return tpl.content.cloneNode(true);
    }

    /** Shortcut: get a template's outer HTML string (for innerHTML assignment) */
    function getTemplateHtml(id, replacements) {
        var frag = getTemplate(id, replacements);
        if (!frag) { return ''; }
        var tmp = document.createElement('div');
        tmp.appendChild(frag);
        return tmp.innerHTML;
    }
    // ────────────────────────────────────────────────────────────────────

    // Logging to server is disabled; function kept as no-op to avoid call-site changes.
    function logToServer(action, request, response) {}

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

    /** Is this the synthesized card row (as opposed to a hosted-page provider)? */
    function isCardProfile(profile) {
        if (!profile) {
            return false;
        }
        var method = String(profile.checkoutMethod || '').toLowerCase();
        var provider = String((profile.paymentProvider && profile.paymentProvider.provider) || '').toLowerCase();
        return method === 'creditcard' || provider === 'creditcard';
    }

    function getProxiedIconUrl(url) {
        if (!url) {
            return '';
        }
        return iconProxyBase + encodeURIComponent(url);
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

                // The proxy failed. Retry once against the icon's own URL so a
                // proxy problem degrades to a slower icon instead of no icon.
                // The flag stops a loop when the direct URL fails too.
                if (direct && !img.__phFellBack && img.src !== direct) {
                    img.__phFellBack = true;
                    img.src = direct;
                } else if (img.getAttribute('data-ph-builtin-fallback') === '1' && !img.__phBuiltin) {
                    // Both URLs failed on a card row: swap in the built-in
                    // artwork so the row is never left without an icon.
                    img.__phBuiltin = true;
                    var builtin = getTemplate('ph-tpl-profile-icon-builtin');
                    if (builtin && img.parentNode) {
                        img.parentNode.replaceChild(builtin, img);
                    }
                }

                // Only report the first failure per image.
                if (img.__phLogged) {
                    return;
                }
                img.__phLogged = true;

                // Best-effort server log
                fetch(ajaxUrl, {
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

        var section = document.getElementById('paymenthood-section');
        var c = document.getElementById('paymenthood-profiles-container');

        // If the wrapper doesn't exist yet, create it
        if (!section || !section.parentElement) {
            section = createCheckoutContainers();
            c = document.getElementById('paymenthood-profiles-container');

            if (c && profiles && profiles.length > 0) {
                displayProfiles(profiles);
            } else if (c && !c.__phLoaded) {
                c.__phLoaded = true;
                loadPaymentProfiles();
            }
        }

        if (section) {
            section.style.display = 'block';
        }

        // Show checkout message inside the wrapper only when there is content
        var m = document.getElementById('paymenthood-checkout-message');
        if (m && checkoutMessage) {
            m.style.display = 'block';
            if (!m.__phSet) {
                m.__phSet = true;
                m.innerHTML = getCheckoutMessageHtml();
            }
        }

        logToServer('show_container', {
            sectionFound: !!section,
            messageFound: !!m,
            profilesCount: profiles ? profiles.length : 0
        }, {});
    }

    function hideContainer() {
        var section = document.getElementById('paymenthood-section');
        if (section) { section.style.display = 'none'; }
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
        var container = document.getElementById('paymenthood-section');

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

        // Fetch profiles only once (use #paymenthood-profiles-container as the load-state tracker)
        var profilesEl = document.getElementById('paymenthood-profiles-container');
        if (profilesEl && !profilesEl.__phLoaded) {
            profilesEl.__phLoaded = true;
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
        // Insert #paymenthood-section (single wrapper) as a sibling AFTER
        // #creditCardInputFields, NOT inside it. Themes hide #creditCardInputFields
        // for redirect gateways; placing our wrapper outside prevents interference.
        var ccContainer = document.getElementById('creditCardInputFields');
        if (!ccContainer || !ccContainer.parentElement) {
            logToServer('no_creditcardfields_found', {}, {});
            return null;
        }

        var targetParent = ccContainer.parentElement;
        var existing = document.getElementById('paymenthood-section');

        // Already in the right place?
        if (existing && existing.parentElement === targetParent) {
            logToServer('containers_already_in_place', {}, {});
            return existing;
        }

        // Move if it exists elsewhere (e.g. rendered by WHMCS in the wrong spot)
        if (existing && existing.parentElement) {
            logToServer('moving_containers', {
                toParentId: targetParent.id || '',
                toParentClass: String(targetParent.className || '').substring(0, 80)
            }, {});
            targetParent.insertBefore(existing, ccContainer.nextSibling);
            return existing;
        }

        // Create the wrapper with both children inside
        logToServer('inserting_after_cc', {
            parentId: targetParent.id || '',
            parentClass: String(targetParent.className || '').substring(0, 80)
        }, {});

        var section = document.createElement('div');
        section.id = 'paymenthood-section';
        section.style.display = 'none';

        var msgDiv = document.createElement('div');
        msgDiv.id = 'paymenthood-checkout-message';
        msgDiv.className = 'paymenthood-checkout-message';
        msgDiv.style.display = 'none';

        var profilesDiv = document.createElement('div');
        profilesDiv.id = 'paymenthood-profiles-container';
        profilesDiv.className = 'paymenthood-profiles-container';
        if (!profiles || profiles.length === 0) {
            profilesDiv.innerHTML = getTemplateHtml('ph-tpl-loading') || 'Loading payment methods...';
        }

        section.appendChild(msgDiv);
        section.appendChild(profilesDiv);
        targetParent.insertBefore(section, ccContainer.nextSibling);

        logToServer('containers_inserted', {
            parentId: targetParent.id || '',
            parentClass: String(targetParent.className || '').substring(0, 80)
        }, {});

        return section;
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
             container.innerHTML = getTemplateHtml('ph-tpl-loading') || 'Loading payment methods...';
        }
        
        logToServer('fetch_profiles', {
            url: ajaxUrl
        }, {});

        fetch(ajaxUrl)
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
                    fetch(ajaxUrl, {
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
                fetch(ajaxUrl, {
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
            container.innerHTML = getTemplateHtml('ph-tpl-no-profiles')
                || '<div class="paymenthood-profiles-error">No payment methods available</div>';
            return;
        }

        // Build from templates
        var wrapperFrag = getTemplate('ph-tpl-profiles-wrapper');
        if (!wrapperFrag) {
            // Fallback if templates haven't loaded yet
            container.innerHTML = '';
            return;
        }

        // Temporarily attach so we can querySelector inside
        var wrapperDiv = document.createElement('div');
        wrapperDiv.appendChild(wrapperFrag);
        var listEl = wrapperDiv.querySelector('[data-ph-list]');

        supportedProfiles.forEach(function(profile) {
            var profileId = profile.paymentProfileId || profile.checkoutMethod || '';
            var directIconUrl = normalizeUrl(getProviderIconUrl(profile));
            var proxyIconUrl = getProxiedIconUrl(directIconUrl);
            var providerName = (profile.paymentProvider && profile.paymentProvider.provider) ? profile.paymentProvider.provider : '';
            var profileName = profile.paymentProfileName;
            var displayName = profileName || '';

            var itemFrag = getTemplate('ph-tpl-profile-item', {
                profileId: String(profileId),
                displayName: displayName,
                providerName: (providerName && profileName && providerName !== profileName) ? providerName : ''
            });

            if (itemFrag) {
                var iconSlot = itemFrag.querySelector('[data-ph-icon-slot]');
                if (iconSlot) {
                    var iconFrag = null;

                    if (proxyIconUrl) {
                        iconFrag = getTemplate('ph-tpl-profile-icon', {
                            proxyIconUrl: proxyIconUrl,
                            directIconUrl: directIconUrl,
                            alt: displayName
                        });
                        // Mark card rows so a failed load can drop back to the
                        // built-in artwork. Other providers must not, or a
                        // failed PayPal logo would render as a credit card.
                        if (iconFrag && isCardProfile(profile)) {
                            var imgEl = iconFrag.querySelector('img');
                            if (imgEl) {
                                imgEl.setAttribute('data-ph-builtin-fallback', '1');
                            }
                        }
                    } else if (isCardProfile(profile)) {
                        // The API returned no artwork for the card row. Draw our
                        // own rather than leaving the row iconless.
                        iconFrag = getTemplate('ph-tpl-profile-icon-builtin');
                    }

                    if (iconFrag) {
                        iconSlot.appendChild(iconFrag);
                    }
                }
                listEl.appendChild(itemFrag);
            }
        });

        container.innerHTML = wrapperDiv.innerHTML;

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
        container.innerHTML = getTemplateHtml('ph-tpl-error', { message: message })
            || '<div class="paymenthood-profiles-error">' + escapeHtml(message) + '</div>';
    }

    // Initialize: load templates first, then proceed with profile init
    function boot() {
        loadTemplates()
            .then(function() { initPaymentHoodProfiles(); })
            .catch(function() {
                // Templates failed to load — proceed anyway (inline fallbacks will be used)
                initPaymentHoodProfiles();
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

// ── Block Apply Credit when a PaymentHood payment is in-flight ──────────────
// Activated only when the PHP hook sets blockCreditApplication: true in
// window.PAYMENTHOOD_CONFIG (invoice page, invoice is Unpaid, payment exists).
(function () {
    if (!(window.PAYMENTHOOD_CONFIG && window.PAYMENTHOOD_CONFIG.blockCreditApplication)) {
        return;
    }

    var msg = window.PAYMENTHOOD_CONFIG.blockCreditMessage ||
        'A PaymentHood payment is in progress for this invoice. Please wait for it to complete or expire before applying credit.';

    var SELECTORS = [
        'form[action*="applycredit"]',
        'form[action*="addcredit"]',
        '[data-action="applycredit"]',
        '[data-action="addcredit"]',
        'a[href*="applycredit"]',
        'a[href*="addcredit"]',
        'button[name="applycredit"]',
        'input[name="applycredit"]',
        'button[value="applycredit"]',
        'input[value="applycredit"]',
        'button[name="addcredit"]',
        'input[name="addcredit"]'
    ];

    function hideCreditControl(el) {
        el.classList.add('paymenthood-credit-blocked');
        var parent = el.closest(
            '.apply-credit, .creditbox, .invoice-apply-credit, .balance-credit, .panel, .well, form, .btn, .button'
        );
        if (parent) {
            parent.classList.add('paymenthood-credit-blocked');
        }
    }

    function block() {
        // Hide via CSS class
        SELECTORS.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                hideCreditControl(el);
            });
        });

        // Also intercept forms that contain credit application controls/fields.
        document.querySelectorAll('form').forEach(function (form) {
            var hidden = form.querySelector('input[name="action"][value="applycredit"], input[name="action"][value="addcredit"]');
            var button = form.querySelector('button[name="applycredit"], input[name="applycredit"], button[name="addcredit"], input[name="addcredit"]');
            var creditField = form.querySelector('input[name="credit"], input[name="creditamount"], input[name="applyCredit"], input[name="addcredit"]');

            if (hidden || button || creditField) {
                hideCreditControl(form);
                if (!form._phCreditBlocked) {
                    form._phCreditBlocked = true;
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        alert(msg);
                    }, true);
                }
            }
        });
    }

    // Run immediately, on DOMContentLoaded, and on any DOM mutation
    block();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', block);
    }
    var observer = new MutationObserver(block);
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();

// ── Block payment method switching when PaymentHood payment is in-flight ─────
// Activated only when the PHP hook sets blockPaymentMethodSwitch: true.
(function () {
    if (!(window.PAYMENTHOOD_CONFIG && window.PAYMENTHOOD_CONFIG.blockPaymentMethodSwitch)) {
        return;
    }

    var msg = window.PAYMENTHOOD_CONFIG.blockPaymentMethodMsg ||
        'A PaymentHood payment is already in progress. You cannot switch payment methods until it completes or expires.';

    function isPaymentHoodValue(value) {
        return String(value || '').toLowerCase().indexOf('paymenthood') !== -1;
    }

    function lockElement(el) {
        if (!el) {
            return;
        }
        el.classList.add('paymenthood-method-locked');
        if ('disabled' in el) {
            el.disabled = true;
        }
    }

    function intercept(el) {
        if (!el || el._phMethodLocked) {
            return;
        }

        el._phMethodLocked = true;
        el.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            alert(msg);
        }, true);
        el.addEventListener('change', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            alert(msg);
        }, true);
    }

    function findPaymentHoodOption(selectEl) {
        return Array.prototype.find.call(selectEl.options || [], function (option) {
            return isPaymentHoodValue(option.value);
        }) || null;
    }

    function forceHide(el) {
        if (!el) {
            return;
        }
        el.style.setProperty('display', 'none', 'important');
    }

    function hidePaymentMethodControl(el) {
        if (!el) {
            return;
        }

        forceHide(el);

        // Walk up the DOM aggressively to hide the entire containing row/section.
        // WHMCS templates wrap the gateway <select> in various container elements;
        // we climb until we find a recognisable wrapper or hit 5 levels up.
        var node = el.parentElement;
        var levels = 0;
        while (node && levels < 5) {
            // If we hit <form>, <body>, or main content area, stop — don't hide
            // the entire page.
            if (node.tagName === 'FORM' || node.tagName === 'BODY') {
                break;
            }

            var cls = (node.className || '').toLowerCase();
            var isRow = cls.indexOf('row') !== -1
                || cls.indexOf('form-group') !== -1
                || cls.indexOf('col-') !== -1
                || cls.indexOf('payment-method') !== -1
                || cls.indexOf('gateway') !== -1
                || node.tagName === 'TR'
                || node.tagName === 'LI';

            if (isRow) {
                forceHide(node);
                break;
            }

            // Also hide if this node is a small wrapper (div/span/td) that only
            // wraps the gateway control itself.
            var children = node.children || [];
            if (children.length <= 2) {
                forceHide(node);
            }

            node = node.parentElement;
            levels++;
        }
    }

    function selectPaymentHoodRadio() {
        var paymentHoodRadio = document.querySelector(
            'input[type="radio"][name="paymentmethod"][value="paymenthood"], '
            + 'input[type="radio"][name="gateway"][value="paymenthood"]'
        );

        if (paymentHoodRadio) {
            paymentHoodRadio.checked = true;
        }
    }

    function lockMethods() {
        // Hide the entire payment method selection area — no notice banner needed
        // because the customer should only see the PaymentHood continue-to-payment form.

        // Hide all payment method controls (selects, radios, buttons, data-attrs).
        document.querySelectorAll(
            'input[type="radio"][name="paymentmethod"], '
            + 'input[type="radio"][name="gateway"], '
            + 'select[name="paymentmethod"], '
            + 'select[name="gateway"], '
            + 'button[name="paymentmethod"], '
            + 'input[name="paymentmethod"], '
            + '[data-gateway], [data-paymentmethod]'
        ).forEach(function (el) {
            var val = el.value || el.getAttribute('data-gateway') || el.getAttribute('data-paymentmethod') || '';
            if (el.tagName === 'SELECT') {
                // Remove all non-PaymentHood options from the dropdown
                Array.prototype.slice.call(el.options || []).forEach(function (option) {
                    if (!isPaymentHoodValue(option.value)) {
                        option.remove();
                    }
                });

                var paymentHoodOption = findPaymentHoodOption(el);
                if (paymentHoodOption) {
                    el.value = paymentHoodOption.value;
                }

                // Hide the entire gateway selector area — the customer should
                // not see a payment method choice at all.
                hidePaymentMethodControl(el);

                // Also hide any associated <label> for this select
                if (el.id) {
                    var label = document.querySelector('label[for="' + el.id + '"]');
                    forceHide(label);
                }
                var parentLabel = el.closest('label');
                forceHide(parentLabel);

                intercept(el);
                return;
            }

            if (!isPaymentHoodValue(val)) {
                if ((el.type === 'radio' || el.type === 'checkbox') && el.checked) {
                    el.checked = false;
                    selectPaymentHoodRadio();
                }

                // Completely hide non-PaymentHood options
                forceHide(el);

                // Also hide the parent label/container.
                var parent = el.closest('label, .gateway-option, .payment-method-option, li, .form-group, .panel, .well');
                if (parent) {
                    forceHide(parent);
                }
            }
        });

        // Hide links/buttons that would switch away.
        document.querySelectorAll(
            '[data-gateway], [data-paymentmethod], a[href*="paymentmethod="], a[href*="gateway="], button[data-gateway], button[data-paymentmethod]'
        ).forEach(function (el) {
            var val = el.getAttribute('data-gateway') || el.getAttribute('data-paymentmethod') || el.href || '';
            if (!isPaymentHoodValue(val)) {
                forceHide(el);
            }
        });

        document.querySelectorAll('form').forEach(function (form) {
            var paymentMethodField = form.querySelector('select[name="paymentmethod"], select[name="gateway"], input[name="paymentmethod"], input[name="gateway"]');
            if (!paymentMethodField) {
                return;
            }

            var value = paymentMethodField.value || paymentMethodField.getAttribute('value') || '';
            if (!isPaymentHoodValue(value)) {
                if (paymentMethodField.tagName === 'SELECT') {
                    var paymentHoodOption = findPaymentHoodOption(paymentMethodField);
                    if (paymentHoodOption) {
                        paymentMethodField.value = paymentHoodOption.value;
                    }
                }

                intercept(form);
            }
        });
    }

    lockMethods();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', lockMethods);
    }
    var observer = new MutationObserver(lockMethods);
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
