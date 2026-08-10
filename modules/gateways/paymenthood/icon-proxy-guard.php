<?php
/**
 * Shared hardening helpers for the PaymentHood payment-icon proxy.
 *
 * Both public entry points route through here:
 *   - icon-proxy.php
 *   - get-payment-profiles.php?proxy=1&u=...
 *
 * The allowlist lives in exactly one place on purpose. It previously existed
 * twice, with different hand-counted suffix lengths, and the two copies
 * disagreed about what was reachable.
 *
 * This file must stay dependency-free: icon-proxy.php runs without the WHMCS
 * bootstrap, so nothing here may rely on init.php.
 */

if (!function_exists('paymenthood_iconProxyHostAllowed')) {

    /** Maximum bytes accepted from upstream. Payment icons are a few KB. */
    define('PAYMENTHOOD_ICON_PROXY_MAX_BYTES', 2097152); // 2 MiB

    /**
     * Hosts allowed only as an exact match.
     *
     * *.blob.core.windows.net and *.azureedge.net are deliberately NOT
     * wildcarded. Both are shared, multi-tenant namespaces — anyone can
     * register an account in them — so a suffix match there is not an
     * ownership boundary, it is an open proxy. Pin the specific account.
     */
    function paymenthood_iconProxyExactHosts()
    {
        return array(
            'phpaymentstorageaccount.blob.core.windows.net',
        );
    }

    /**
     * Domains PaymentHood controls. Matched exactly and on any subdomain.
     */
    function paymenthood_iconProxySuffixDomains()
    {
        return array(
            'paymenthood.com',
        );
    }

    /**
     * Is this hostname on the allowlist?
     *
     * Suffix lengths are derived from the domain strings themselves. Nothing
     * here is hand-counted, so the check cannot silently stop matching (or
     * start over-matching) if a domain is edited.
     */
    function paymenthood_iconProxyHostAllowed($host)
    {
        $host = rtrim(strtolower((string) $host), '.');

        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }

        if (in_array($host, paymenthood_iconProxyExactHosts(), true)) {
            return true;
        }

        foreach (paymenthood_iconProxySuffixDomains() as $domain) {
            if ($host === $domain) {
                return true;
            }

            $suffix = '.' . $domain;
            if (strlen($host) > strlen($suffix)
                && substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a caller-supplied icon URL and rebuild it from the parts we
     * actually checked.
     *
     * Rebuilding matters: parse_url() and curl do not always agree on where
     * the host ends in a hostile URL. Handing curl the caller's original
     * string would mean validating one thing and fetching another.
     *
     * @return array|false ['url' => string, 'host' => string, 'path' => string]
     */
    function paymenthood_iconProxyValidateUrl($rawUrl)
    {
        $rawUrl = trim((string) $rawUrl);

        if ($rawUrl === '' || strlen($rawUrl) > 2048) {
            return false;
        }

        if (strpos($rawUrl, '//') === 0) {
            $rawUrl = 'https:' . $rawUrl;
        }

        // Control characters and whitespace are the usual way to desync a
        // parser from a client. Reject rather than normalise.
        if (preg_match('/[\x00-\x20\x7f]/', $rawUrl)) {
            return false;
        }

        $parts = @parse_url($rawUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        // Credentials make the effective host ambiguous between parser and
        // client (https://allowed.host@evil.example/). Never accept them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = rtrim(strtolower((string) $parts['host']), '.');
        if (!paymenthood_iconProxyHostAllowed($host)) {
            return false;
        }

        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        $url = 'https://' . $host . $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }
        // Fragment is intentionally dropped; it is never sent upstream anyway.

        return array('url' => $url, 'host' => $host, 'path' => $path);
    }

    /**
     * Map an upstream Content-Type onto a strict allowlist.
     *
     * @return string|false
     */
    function paymenthood_iconProxyResolveContentType($upstreamType, $path)
    {
        $raw = strtolower(trim((string) $upstreamType));
        $type = trim(strtok($raw, ';'));

        $allowed = array(
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'image/avif',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'image/svg+xml',
        );

        if (in_array($type, $allowed, true)) {
            return $type;
        }

        // Upstream said nothing useful — fall back to the file extension, but
        // only onto the same allowlist. Never emit "image/*": it is not a real
        // media type, and with nosniff the browser just drops the response.
        if ($type === '' || $type === 'application/octet-stream' || $type === 'binary/octet-stream') {
            $byExtension = array(
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'ico'  => 'image/x-icon',
                'svg'  => 'image/svg+xml',
            );

            $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
            if (isset($byExtension[$extension])) {
                return $byExtension[$extension];
            }
        }

        return false;
    }

    /**
     * Fetch an icon, re-validating the allowlist on every redirect hop.
     *
     * curl's own CURLOPT_FOLLOWLOCATION is never enabled here. It resolves a
     * Location header without consulting our allowlist, so an allowed host
     * answering "302 -> anywhere" would turn this into a server-side request
     * forgery primitive against whatever the web server can reach.
     *
     * @return array ok|status|body|type|url|error
     */
    function paymenthood_iconProxyFetch($url, $maxRedirects = 3)
    {
        $maxBytes = PAYMENTHOOD_ICON_PROXY_MAX_BYTES;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_MAXFILESIZE    => $maxBytes,
                CURLOPT_HTTPHEADER     => array(
                    'Accept: image/*',
                    'User-Agent: WHMCS-PaymentHood-IconProxy/1.0',
                ),
            ));

            if (defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
            }

            // CURLOPT_MAXFILESIZE only fires when upstream sends Content-Length.
            // Abort on oversized bodies even when it doesn't.
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $downloadTotal, $downloadNow) use ($maxBytes) {
                return ($downloadTotal > $maxBytes || $downloadNow > $maxBytes) ? 1 : 0;
            });

            $body     = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                return array('ok' => false, 'status' => $status, 'errno' => $errno, 'error' => $error);
            }

            if ($status >= 300 && $status < 400) {
                if ($location === '') {
                    return array('ok' => false, 'status' => $status, 'errno' => 0, 'error' => 'redirect without location');
                }

                $next = paymenthood_iconProxyValidateUrl($location);
                if ($next === false) {
                    return array('ok' => false, 'status' => $status, 'errno' => 0, 'error' => 'redirect target not allowed');
                }

                $url = $next['url'];
                continue;
            }

            if ($status < 200 || $status >= 300 || $body === '') {
                return array('ok' => false, 'status' => $status, 'errno' => $errno, 'error' => $error);
            }

            if (strlen($body) > $maxBytes) {
                return array('ok' => false, 'status' => $status, 'errno' => 0, 'error' => 'response too large');
            }

            return array(
                'ok'     => true,
                'status' => $status,
                'body'   => $body,
                'type'   => $type,
                'url'    => $url,
                'errno'  => 0,
                'error'  => '',
            );
        }

        return array('ok' => false, 'status' => 0, 'errno' => 0, 'error' => 'too many redirects');
    }

    /**
     * Emit response headers for a validated icon.
     */
    function paymenthood_iconProxySendHeaders($contentType, $length)
    {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (int) $length);
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');

        // An SVG loaded through <img> cannot run script, but this endpoint is a
        // plain URL: a merchant's customer can be linked straight at it, where
        // the same bytes render as a document and script would execute on the
        // merchant's own origin. The sandbox makes that inert.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        header('Content-Disposition: inline');

        // Deliberately no Access-Control-Allow-Origin. Icons are loaded via
        // <img>, which needs no CORS grant, and "*" would let any site read
        // proxied bytes cross-origin.
    }
}
