<?php

require_once __DIR__ . '/../../modules/addons/paymenthood/paymenthoodhandler.php';

add_hook('ClientAreaPageCart', 1, function ($vars) {
    try {
        $action = $vars['action'] ?? '';
        if (strtolower((string) $action) !== 'checkout') {
            return [];
        }

        $isSandbox = PaymentHoodHandler::isSandboxModeEnabled();
        if (!$isSandbox) {
            return [];
        }

        $gatewaysVar = $vars['gateways'] ?? null;
        if (!is_array($gatewaysVar)) {
            return [];
        }

        // WHMCS can provide gateways in different shapes depending on orderform/template.
        // Your output shows: $vars['gateways']['gateways'][sysname] = gatewayInfo
        $isNested = isset($gatewaysVar['gateways']) && is_array($gatewaysVar['gateways']);
        $gatewayList = $isNested ? $gatewaysVar['gateways'] : $gatewaysVar;

        foreach ($gatewayList as $idx => $gateway) {
            if (!is_array($gateway)) {
                continue;
            }

            $sysname = strtolower((string) ($gateway['sysname'] ?? ($gateway['module'] ?? $idx)));
            PaymentHoodHandler::safeLogModuleCall('ClientAreaPageCart', [], [
                'sysname' => $sysname
            ]);
            if ($sysname !== 'paymenthood') {
                continue;
            }

            $sandboxLabel = 'PaymentHood (Sandbox)';
            // Different orderform templates may use different keys.
            if (array_key_exists('name', $gateway)) {
                $gateways[$idx]['name'] = $sandboxLabel;
            }
            if (array_key_exists('displayname', $gateway)) {
                $gateways[$idx]['displayname'] = $sandboxLabel;
            }
            if (array_key_exists('paymentmethod', $gateway)) {
                $gatewayList[$idx]['paymentmethod'] = $sandboxLabel;
            }

            // Always update the displayed name key if present.
            if (array_key_exists('name', $gateway)) {
                $gatewayList[$idx]['name'] = $sandboxLabel;
            }
            if (array_key_exists('displayname', $gateway)) {
                $gatewayList[$idx]['displayname'] = $sandboxLabel;
            }
        }

        if ($isNested) {
            $gatewaysVar['gateways'] = $gatewayList;
            return ['gateways' => $gatewaysVar];
        }

        return ['gateways' => $gatewayList];
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('paymenthood_checkout_label_hook_error', [], [
            'error' => $e->getMessage(),
        ]);

        return [];
    }
});
