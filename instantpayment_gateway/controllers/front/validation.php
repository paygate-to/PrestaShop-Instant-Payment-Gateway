<?php

class Instantpayment_gatewayValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        Instantpayment_gateway::log("=== START VALIDATION ===\n");

        $cart = $this->context->cart;
        $customer = new Customer((int)$cart->id_customer);

        if (!$cart || !(int)$cart->id || !Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=cart');
            return;
        }

        $total_products_tax_incl = $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        $total_products_tax_excl = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
        $total_shipping_tax_incl = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
        $total_shipping_tax_excl = $cart->getOrderTotal(false, Cart::ONLY_SHIPPING);
        $total_discounts_tax_incl = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        $total_discounts_tax_excl = $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS);
        $total_paid_tax_incl = $cart->getOrderTotal(true, Cart::BOTH);
        $total_paid_tax_excl = $cart->getOrderTotal(false, Cart::BOTH);

        $currency_iso = $this->context->currency->iso_code;
        $email = $customer->email;

        $wallet = trim(Configuration::get('INP_WALLET'));
        $hosted_domain = trim(Configuration::get('INP_API_URL'));

        Instantpayment_gateway::log("[" . date('Y-m-d H:i:s') . "] Cart total: {$total_paid_tax_incl} {$currency_iso}, wallet={$wallet}\n");

        if (empty($wallet) || empty($hosted_domain)) {
            Instantpayment_gateway::log("[" . date('Y-m-d H:i:s') . "] ERROR: payout wallet or hosted domain not configured\n");
            Tools::redirect('index.php?controller=order&conf=0');
            return;
        }
		$total = $total_paid_tax_incl;
		$coin_rate_usd = 1.0;
		

		$convert_url = 'https://' . Instantpayment_gateway::apiHost() . '/control/convert.php?value=' . $total. '&from=' . strtolower($currency_iso);

		Instantpayment_gateway::log("[".date('Y-m-d H:i:s')."] Calling convert API: {$convert_url}\n");



		// simple curl call

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $convert_url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$conv_resp = curl_exec($ch);

		$curl_err = curl_error($ch);

		curl_close($ch);



		if ($conv_resp === false || $curl_err) {

			Instantpayment_gateway::log("[".date('Y-m-d H:i:s')."] ERROR convert API failed: {$curl_err}\n");

			// fallback: set reference_total_usd = total (best effort) — or redirect with error

			$reference_total_usd = (float)$total;

		} else {

			Instantpayment_gateway::log("[".date('Y-m-d H:i:s')."] Convert API response: {$conv_resp}\n");

			$conv_dec = json_decode($conv_resp, true);
			
			
			/* echo '<pre>';
			print_r($conv_dec);
			echo '</pre>';
			die('trete'); */
			
			if (is_array($conv_dec) && isset($conv_dec['value_coin'])) {

				$reference_total_usd = (float) $conv_dec['value_coin'];

				if (isset($conv_dec['rate'])) {

					$coin_rate_usd = (float) $conv_dec['rate'];

				} else {

					// If rate is not provided, compute fallback if value_coin available

					$coin_rate_usd = $reference_total_usd > 0 ? ($reference_total_usd / (float)$total) : 1.0;

				}

			} else {

				// fallback

				$reference_total_usd = (float)$total;

			}

		}

        

        try {
            Instantpayment_gateway::log("Before manual order creation\n");

            $order = new Order();
            $order->id_cart = (int)$cart->id;
            $order->id_currency = (int)$this->context->currency->id;
            $order->id_customer = (int)$cart->id_customer;
            $order->id_carrier = (int)$cart->id_carrier;
            $order->id_lang = (int)$this->context->language->id;
            $order->id_address_delivery = (int)$cart->id_address_delivery;
            $order->id_address_invoice = (int)$cart->id_address_invoice;
            $order->id_shop = (int)$this->context->shop->id;
            $order->secure_key = $customer->secure_key;

            $order->module = $this->module->name;
            $order->payment = 'Instant Payment';
            $order->conversion_rate = 1.0;

            // ✅ Correct totals
            $order->total_products = (float)$total_products_tax_excl;
            $order->total_products_wt = (float)$total_products_tax_incl;
            $order->total_shipping = (float)$total_shipping_tax_incl;
            $order->total_discounts = (float)$total_discounts_tax_incl;
            $order->total_paid = (float)$total_paid_tax_incl;
            $order->total_paid_tax_incl = (float)$total_paid_tax_incl;
            $order->total_paid_tax_excl = (float)$total_paid_tax_excl;
            $order->total_paid_real = (float)$total_paid_tax_incl;

            $order->reference = Order::generateReference();

            $state = (int)Configuration::get('PS_OS_CHEQUE');
            
            $order->current_state = $state;

            if (!$order->add()) {
                throw new Exception('Failed to create order.');
            }

            $orderId = (int)$order->id;
            Instantpayment_gateway::log("Created order id={$orderId}\n");

            // ✅ Create OrderDetails
            $products = $cart->getProducts();
            foreach ($products as $product) {
                $detail = new OrderDetail();
                $detail->id_order = $orderId;
                $detail->product_id = $product['id_product'];
                $detail->product_attribute_id = $product['id_product_attribute'];
                $detail->product_name = pSQL($product['name']);
                $detail->product_quantity = (int)$product['cart_quantity'];
                $detail->product_price = (float)$product['price'];
                $detail->total_price_tax_incl = (float)$product['total_wt'];
                $detail->total_price_tax_excl = (float)$product['total'];
                $detail->id_shop = (int)$this->context->shop->id;
                $detail->id_warehouse = 0;
                $detail->add();
            }

            // ✅ Add OrderCarrier
            $orderCarrier = new OrderCarrier();
            $orderCarrier->id_order = $orderId;
            $orderCarrier->id_carrier = (int)$cart->id_carrier;
            $orderCarrier->shipping_cost_tax_incl = $order->total_shipping;
            $orderCarrier->shipping_cost_tax_excl = $order->total_shipping;
            $orderCarrier->add();

            // ✅ Add OrderPayment
            $orderPayment = new OrderPayment();
            $orderPayment->order_reference = $order->reference;
            $orderPayment->id_currency = $order->id_currency;
            $orderPayment->amount = $order->total_paid;
            $orderPayment->payment_method = 'Instant Payment';
            $orderPayment->conversion_rate = 1.0;
            $orderPayment->add();

            // ✅ Add order history
            $history = new OrderHistory();
            $history->id_order = $orderId;
            $history->changeIdOrderState($state, $orderId);
            $history->add();

            Instantpayment_gateway::log("Order fully created with totals, carrier, and payment.\n");

        } catch (Exception $e) {
            Instantpayment_gateway::log("Order creation error: " . $e->getMessage() . "\n");
            Tools::redirect('index.php?controller=order&conf=0');
            return;
        }

        // ✅ Continue PayGate redirect
        $nonce = bin2hex(random_bytes(16));
        $callback = _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/instantpayment_gateway/callback.php';
        $callback_url = $callback . '?number=' . $orderId . '&nonce=' . $nonce;
        $wallet_api = "https://" . Instantpayment_gateway::apiHost() . "/control/wallet.php?address=" . urlencode($wallet) . "&callback=" . urlencode($callback_url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $wallet_api);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $wallet_response = curl_exec($ch);
        curl_close($ch);

        $wallet_data = json_decode($wallet_response, true);
        if (!is_array($wallet_data) || empty($wallet_data['address_in'])) {
            Instantpayment_gateway::log("Wallet API invalid response: {$wallet_response}\n");
            Tools::redirect('index.php?controller=order&conf=0');
            return;
        }
		
		$plain_address_in = $wallet_data['address_in'];
		$address_in = $wallet_data['polygon_address_in'];
		$ipn_token = $wallet_data['ipn_token'];
		$insert = [

            'order_id' => pSQL((string)$orderId),

            'address_in' => pSQL($plain_address_in),

            'polygon_address_in' => pSQL($address_in),

            'callback_url' => pSQL($callback_url),

            'ipn_token' => pSQL($ipn_token),

            'status' => pSQL('pending'),

            // store expected USD value and conversion rate for later callback check

            'nonce' => pSQL($nonce),

            'expected_value_coin' => pSQL((string)$reference_total_usd),

            'coin_rate_usd' => (float)$coin_rate_usd ?? '',

            'created_at' => pSQL(date('Y-m-d H:i:s')),

        ];



        // If record for this order exists, update it; otherwise insert new

        $existingId = Db::getInstance()->getValue('SELECT id FROM `' . _DB_PREFIX_ . 'instantorder_paymentdetail` WHERE order_id = "' . pSQL((string)$orderId) . '"');

        if ($existingId) {

            Db::getInstance()->update('instantorder_paymentdetail', $insert, 'id = ' . (int)$existingId);

            $recordId = (int)$existingId;

            Instantpayment_gateway::log("[".date('Y-m-d H:i:s')."] Updated payment detail id={$recordId}\n");

        } else {

            Db::getInstance()->insert('instantorder_paymentdetail', $insert);

            $recordId = (int) Db::getInstance()->Insert_ID();

            Instantpayment_gateway::log("[".date('Y-m-d H:i:s')."] Inserted payment detail id={$recordId}\n");

        }
		
		$currencyUSD = 'USD';
        
        $checkout_url = "https://" . rtrim(str_replace(['https://','http://'], '', $hosted_domain), '/') . "/pay.php?address=" . $plain_address_in
            . "&amount=" . $total_paid_tax_incl
            . "&email=" . urlencode($email)
            . "&currency=" . $currency_iso;

        Instantpayment_gateway::log("[".date('Y-m-d H:i:s')."] Redirecting order {$orderId} to hosted checkout\n");

        Tools::redirect($checkout_url);
    }
}
