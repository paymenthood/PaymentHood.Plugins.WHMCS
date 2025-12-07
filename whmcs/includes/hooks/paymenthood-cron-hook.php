<?php
use WHMCS\Database\Capsule;
require_once __DIR__ . '/../../modules/addons/paymenthood/paymenthoodhandler.php';

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        PaymentHoodHandler::safeLogModuleCall('AfterCronJob', [], [], 'Cron executed');
        // Process unpaid invoices
        PaymentHoodHandler::processUnpaidInvoices();

        // Log execution
    } catch (\Throwable $ex) {
        PaymentHoodHandler::safeLogModuleCall('AfterCronJob Exception', [], [], $ex->getMessage());
    }
});