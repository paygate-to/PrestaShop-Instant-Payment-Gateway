<?php
// modules/instantpayment_gateway/callback.php

require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../init.php';

// === JSON response helper ===
function json_response($status, $message, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => $status,
        'message' => $message
    ]);
    exit;
}

// === Logger ===
function cb_log($msg)
{
    $file = __DIR__ . '/callback_log.txt';
    file_put_contents($file, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// === Start ===
cb_log("CALLBACK HIT: " . print_r($_GET, true));

try {
    
	
	// === Validate parameters ===
    $order_id   = isset($_GET['number']) ? (int) $_GET['number'] : 0;
    $address_in = isset($_GET['address_in']) ? trim($_GET['address_in']) : '';
    $nonce_in   = isset($_GET['nonce']) ? trim($_GET['nonce']) : '';
	$order = new Order($order_id);
    if (!$order_id) { json_response('error', 'Missing order ID.', 400); $order->setCurrentState(Configuration::get('PS_OS_ERROR')); return false; }
    if (!$nonce_in) { json_response('error', 'Missing nonce.', 400); $order->setCurrentState(Configuration::get('PS_OS_ERROR')); return false; }

    // === Validate order ===
    
    if (!Validate::isLoadedObject($order)) {
        json_response('error', 'Invalid order ID.', 404);
		return false;
    }

    // === Fetch payment record ===
    $table = _DB_PREFIX_ . 'instantorder_paymentdetail';
    $paymentData = Db::getInstance()->getRow('SELECT * FROM `' . pSQL($table) . '` WHERE order_id = ' . (int)$order_id);
    if (!$paymentData) {
		$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
        json_response('error', 'No payment record found for this order.', 404);
		return false;
    }

    // === Validate nonce ===
    if (empty($paymentData['nonce']) || $paymentData['nonce'] !== $nonce_in) {
		$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
        json_response('error', 'Invalid nonce.', 403);
		return false;
    }

    // === Verify payment via PayGate ===
    $value_coin = $_GET['value_coin'];
    $coin = $_GET['coin'];
    $txid_out = $_GET['txid_out'];

    $order_total = (float)$order->total_paid;
    $currency = new Currency($order->id_currency);
    $order_total_usd = Tools::convertPriceFull($order_total, $currency, new Currency(Currency::getIdByIsoCode('USD')));
    $threshold = $order_total_usd * 0.6;

    // Convert coin → USD
    $convert_url = "https://api.paygate.to/control/convert.php?value=" . urlencode($value_coin) . "&from=" . urlencode($coin);
    $convert_response = @file_get_contents($convert_url);
    $paid_usd = $value_coin;
    if ($convert_response) {
        $conv = json_decode($convert_response, true);
        if (isset($conv['value_coin'])) $paid_usd = (float)$conv['value_coin'];
    }

    if ($paid_usd < $threshold) {
        Db::getInstance()->update('instantorder_paymentdetail', [
            'status' => 'failed',
            'txid_out' => pSQL($txid_out),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ' . (int)$paymentData['id']);

        $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
        $message = new Message();
		$message->id_order = (int)$order->id;
		$message->message = 'Payment below threshold. TXID: ' . pSQL($txid_out);
		$message->private = false;
		$message->add();

		$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
        json_response('failed', 'Payment received is less than 60% of the order total.', 400);
		return false;
    }

    // === Mark as paid ===
    Db::getInstance()->update('instantorder_paymentdetail', [
        'value_coin' => pSQL($value_coin),
        'coin'       => pSQL($coin),
        'txid_out'   => pSQL($txid_out),
        'status'     => 'paid',
        'nonce'      => $_GET['nonce'],
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ' . (int)$paymentData['id']);

    $paid_state = (int)Configuration::get('PS_OS_PAYMENT');
    if ($paid_state <= 0) $paid_state = 2;

    $history = new OrderHistory();
    $history->id_order = (int)$order->id;
    $history->changeIdOrderState($paid_state, (int)$order->id);
    $history->addWithemail(true);
	$message = new Message();
	$message->id_order = (int)$order->id;
	$message->message = 'Payment confirmed. TXID: ' . pSQL($txid_out);
	$message->private = false;
	$message->add();
    json_response('success', 'Order marked as paid successfully.', 200);
	return true;

} catch (Exception $e) {
    cb_log("Error: " . $e->getMessage());
    json_response('error', $e->getMessage(), 500);
}
