<?php
/**
 * WHMCS Report: PaymentHood Transactions
 *
 * URL: /admin/reports.php?report=paymenthood_transactions
 *
 * Notes:
 * - WHMCS includes report files in a scope where PHP "use" import statements can cause a fatal error.
 * - Therefore this file uses fully-qualified class names (no "use" imports).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$debug = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
if ($debug && function_exists('logActivity')) {
    logActivity('PaymentHood report loaded: paymenthood_transactions');
}

// Resolve PaymentHood API base URL via the shared handler.
$handlerPath = ROOTDIR . '/modules/addons/paymenthood/paymenthoodhandler.php';
if (!class_exists('PaymentHoodHandler')) {
    if (!is_file($handlerPath)) {
        throw new \RuntimeException('PaymentHood handler not found at: ' . $handlerPath);
    }
    require_once $handlerPath;
}
if (!class_exists('PaymentHoodHandler') || !method_exists('PaymentHoodHandler', 'paymenthood_ConsoleUrl')) {
    throw new \RuntimeException('PaymentHoodHandler::paymenthood_ConsoleUrl() not available');
}

$paymenthoodConsoleUrl = rtrim((string) PaymentHoodHandler::paymenthood_ConsoleUrl(), '/');

$reportdata = [];
$reportdata['title'] = 'PaymentHood Transactions';

// Simple status filter via URL: /admin/reports.php?report=paymenthood_transactions&status=Unpaid
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

try {
    $customFields = \WHMCS\Database\Capsule::table('tblcustomfields')
        ->where('type', 'invoice')
        ->whereIn('fieldname', ['paymenthood_provider', 'paymenthood_app_id', 'paymenthood_payment_id'])
        ->pluck('id', 'fieldname');

    $providerFieldId = isset($customFields['paymenthood_provider']) ? (int) $customFields['paymenthood_provider'] : 0;
    $appIdFieldId = isset($customFields['paymenthood_app_id']) ? (int) $customFields['paymenthood_app_id'] : 0;
    $paymentIdFieldId = isset($customFields['paymenthood_payment_id']) ? (int) $customFields['paymenthood_payment_id'] : 0;

    if ($debug && function_exists('logActivity')) {
        logActivity(
            'PaymentHood report: providerFieldId=' . ($providerFieldId ?: 'NULL')
            . ' appIdFieldId=' . ($appIdFieldId ?: 'NULL')
            . ' paymentIdFieldId=' . ($paymentIdFieldId ?: 'NULL')
            . ' status=' . ($status ?: 'ALL')
        );
    }

    $query = \WHMCS\Database\Capsule::table('tblorders as o')
        ->leftJoin('tblclients as c', 'o.userid', '=', 'c.id')
        ->leftJoin('tblinvoices as i', 'o.invoiceid', '=', 'i.id')
        ->where('o.paymentmethod', 'paymenthood')
        ->select([
            'o.id as order_id',
            'o.userid as client_id',
            'o.amount as amount',
            'i.id as invoice_id',
            'i.status as invoice_status',
            'c.firstname',
            'c.lastname',
            'c.companyname',
        ])
        ->orderBy('o.id', 'desc');

    if ($providerFieldId > 0) {
        $query->leftJoin('tblcustomfieldsvalues as cf_provider', function ($join) use ($providerFieldId) {
            $join->on('cf_provider.relid', '=', 'i.id')
                ->where('cf_provider.fieldid', '=', $providerFieldId);
        });
        $query->addSelect('cf_provider.value as provider');
    }

    if ($appIdFieldId > 0) {
        $query->leftJoin('tblcustomfieldsvalues as cf_appid', function ($join) use ($appIdFieldId) {
            $join->on('cf_appid.relid', '=', 'i.id')
                ->where('cf_appid.fieldid', '=', $appIdFieldId);
        });
        $query->addSelect('cf_appid.value as paymenthood_app_id');
    }

    if ($paymentIdFieldId > 0) {
        $query->leftJoin('tblcustomfieldsvalues as cf_paymentid', function ($join) use ($paymentIdFieldId) {
            $join->on('cf_paymentid.relid', '=', 'i.id')
                ->where('cf_paymentid.fieldid', '=', $paymentIdFieldId);
        });
        $query->addSelect('cf_paymentid.value as paymenthood_payment_id');
    }

    if ($status !== '') {
        $query->where('i.status', $status);
    }

    $rows = $query->limit(500)->get();

    if ($debug && function_exists('logActivity')) {
        logActivity('PaymentHood report: rows=' . $rows->count());
    }

    $reportdata['tableheadings'] = ['Order ID', 'Invoice ID', 'Client', 'Payment Method', 'Provider', 'Amount', 'Details'];
    $reportdata['tablevalues'] = [];
    $reportdata['tablesort'] = true;

    foreach ($rows as $row) {
        $clientName = '';
        if (!empty($row->companyname)) {
            $clientName = (string) $row->companyname;
        } else {
            $clientName = trim((string) $row->firstname . ' ' . (string) $row->lastname);
        }
        if ($clientName === '') {
            $clientName = 'Client #' . (int) $row->client_id;
        }

        $provider = isset($row->provider) && $row->provider !== '' ? (string) $row->provider : '-';

        $detailsCell = '-';
        $paymenthoodAppId = isset($row->paymenthood_app_id) ? trim((string) $row->paymenthood_app_id) : '';
        $paymenthoodPaymentId = isset($row->paymenthood_payment_id) ? trim((string) $row->paymenthood_payment_id) : '';
        if ($paymenthoodAppId !== '' && $paymenthoodPaymentId !== '') {
            $detailsUrl = $paymenthoodConsoleUrl
                . '/' . rawurlencode($paymenthoodAppId)
                . '/payments/detail?appId=' . rawurlencode($paymenthoodAppId)
                . '&paymentId=' . rawurlencode($paymenthoodPaymentId);
            $detailsCell = '<a class="btn btn-default btn-sm" target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($detailsUrl) . '">Details</a>';
        }

        $invoiceCell = '-';
        if (!empty($row->invoice_id)) {
            $invoiceCell = '<a href="invoices.php?action=edit&id=' . (int) $row->invoice_id . '">#' . (int) $row->invoice_id . '</a>';
        }

        $reportdata['tablevalues'][] = [
            '<a href="orders.php?action=view&id=' . (int) $row->order_id . '">' . (int) $row->order_id . '</a>',
            $invoiceCell,
            '<a href="clientssummary.php?userid=' . (int) $row->client_id . '">' . htmlspecialchars($clientName) . '</a>',
            'PaymentHood',
            htmlspecialchars($provider),
            '$' . number_format((float) $row->amount, 2),
            $detailsCell,
        ];
    }

} catch (\Throwable $e) {
    if (function_exists('logActivity')) {
        logActivity('PaymentHood report error: ' . $e->getMessage());
    }

    $reportdata['tableheadings'] = ['Error'];
    $reportdata['tablevalues'] = [[htmlspecialchars($e->getMessage())]];
}
