<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Instantpayment_gateway extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'instantpayment_gateway';
        $this->tab = 'payments_gateways';
        $this->version = '1.2.0';
        $this->author = 'PayGate.to';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Instant Payment Gateway (PayGate.to)');
        $this->description = $this->l('Multi-Providers credit card hosted payment gateway with automatic customer geo location detection for highest conversion and maximum security.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayPayment')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('paymentReturn')
            && $this->initConfiguration()
            && $this->createPaymentDetailTable()
            && $this->installOrderState()
            && $this->removeLegacyLogs();
    }

    /**
     * "Awaiting card payment" order state, created once and reused on reinstall.
     * New orders wait in this state until PayGate confirms the payment.
     */
    public function installOrderState()
    {
        $idState = (int) Configuration::get('INP_OS_AWAITING');
        if ($idState && Validate::isLoadedObject(new OrderState($idState))) {
            return true;
        }

        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Awaiting card payment';
        }
        $state->module_name = $this->name;
        $state->color = '#4169E1';
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->delivery = false;
        $state->shipped = false;
        $state->paid = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;

        if (!$state->add()) {
            return false;
        }

        return Configuration::updateValue('INP_OS_AWAITING', (int) $state->id);
    }

    /**
     * Verify PayGate's RSA signature (X-PayGate-Signature / X-PayGate-Key-Id) on the current request.
     * $registeredCallback is the callback URL this module registered; the signed string is its
     * scheme, host and path plus the raw query string exactly as received.
     * https://paygate.to/docs/payment-gateway-api/#callback-event
     *
     * @return array verified (bool), reason (string)
     */
    public static function verifyPaygateSignature($registeredCallback)
    {
        $b64 = isset($_SERVER['HTTP_X_PAYGATE_SIGNATURE']) ? trim((string) $_SERVER['HTTP_X_PAYGATE_SIGNATURE']) : '';
        if ($b64 === '') {
            return ['verified' => false, 'reason' => 'signature_absent'];
        }
        if (!function_exists('openssl_verify')) {
            return ['verified' => false, 'reason' => 'openssl_unavailable'];
        }

        $keyId = isset($_SERVER['HTTP_X_PAYGATE_KEY_ID']) ? trim((string) $_SERVER['HTTP_X_PAYGATE_KEY_ID']) : '';
        if ($keyId === '') {
            $keyId = 'v1';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return ['verified' => false, 'reason' => 'unknown_key_id'];
        }
        $pemFile = dirname(__FILE__) . '/keys/paygate_callback_public_' . $keyId . '.pem';
        $pem = is_readable($pemFile) ? file_get_contents($pemFile) : false;
        if (!is_string($pem) || $pem === '') {
            return ['verified' => false, 'reason' => 'unknown_key_id'];
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return ['verified' => false, 'reason' => 'bad_public_key'];
        }

        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            return ['verified' => false, 'reason' => 'signature_not_base64'];
        }

        $parts = parse_url((string) $registeredCallback);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ['verified' => false, 'reason' => 'no_registered_callback'];
        }
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . (isset($parts['path']) ? $parts['path'] : '/');
        $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $signed = ($query === '') ? $base : $base . '?' . $query;

        $result = openssl_verify($signed, $raw, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result === 1) {
            return ['verified' => true, 'reason' => ''];
        }
        return ['verified' => false, 'reason' => ($result === -1) ? 'openssl_error' : 'signature_mismatch'];
    }

    /**
     * Debug log, written to PrestaShop's protected var/logs folder (never inside the public module folder).
     */
    public static function log($msg)
    {
        $dir = _PS_ROOT_DIR_ . '/var/logs';
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/instantpayment_gateway.log', $msg, FILE_APPEND);
        }
    }

    /**
     * Delete the debug logs that versions before 1.1.0 wrote inside the public module folder.
     */
    public function removeLegacyLogs()
    {
        foreach (['callback_log.txt', 'controllers/front/debug_log.txt'] as $file) {
            if (file_exists(dirname(__FILE__) . '/' . $file)) {
                @unlink(dirname(__FILE__) . '/' . $file);
            }
        }
        return true;
    }

    private function createPaymentDetailTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'instantorder_paymentdetail` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `order_id` INT(11) NOT NULL,
            `address_in` VARCHAR(255) DEFAULT NULL,
            `callback_address_in` VARCHAR(255) DEFAULT NULL,
            `polygon_address_in` VARCHAR(255) DEFAULT NULL,
            `callback_url` VARCHAR(255) DEFAULT NULL,
            `ipn_token` VARCHAR(255) DEFAULT NULL,
            `nonce` VARCHAR(255) DEFAULT NULL,
            `expected_value_coin` VARCHAR(255) DEFAULT NULL,
            `coin_rate_usd` VARCHAR(255) DEFAULT NULL,
            `value_coin` VARCHAR(255) DEFAULT NULL,
            `coin` VARCHAR(255) DEFAULT NULL,
            `txid_in` VARCHAR(255) DEFAULT NULL,
            `txid_out` VARCHAR(255) DEFAULT NULL,
            `status` VARCHAR(100) DEFAULT NULL,
            `created_at` DATETIME DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        return Db::getInstance()->execute($sql);
    }

    private function initConfiguration()
    {
        $defaults = [
            'INP_ENABLED' => 1,
            'INP_TITLE' => $this->l('Instant Credit Card Payment'),
            'INP_DESCRIPTION' => $this->l('Pay using configured payment provider.'),
            'INP_CUSTOM_DOMAIN' => 'checkout.paygate.to',
            'INP_ICON_URL' => '',
            'INP_API_URL' => 'checkout.paygate.to',
            'INP_API_DOMAIN' => '',
            'INP_AUTH_TYPE' => 'bearer',
            'INP_AUTH_VALUE' => '',
            'INP_WALLET' => '',
            'INP_TEST_MODE' => 1,
            'INP_REDIRECT_MODE' => 0,
            'INP_SUCCESS_KEY' => 'success',
            'INP_SUCCESS_VALUE' => '1',
            'INP_TOLERANCE' => '0.60',
        ];

        foreach ($defaults as $k => $v) {
            if (!Configuration::hasKey($k)) {
                if (!Configuration::updateValue($k, $v)) {
                    return false;
                }
            }
        }
        return true;
    }

    public function uninstall()
    {
        $keys = [
            'INP_ENABLED', 'INP_TITLE', 'INP_DESCRIPTION', 'INP_CUSTOM_DOMAIN', 'INP_ICON_URL',
            'INP_API_URL', 'INP_API_DOMAIN', 'INP_AUTH_TYPE', 'INP_AUTH_VALUE', 'INP_WALLET',
            'INP_TEST_MODE', 'INP_REDIRECT_MODE', 'INP_SUCCESS_KEY', 'INP_SUCCESS_VALUE', 'INP_TOLERANCE'
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
        return parent::uninstall();
    }

    /**
     * Show PayGate data on Order admin page
     */
    public function hookDisplayAdminOrderMainBottom($params)
    {
        $id_order = (int)$params['id_order'];
        if (!$id_order) {
            return '';
        }

        $data = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'instantorder_paymentdetail`
             WHERE order_id = ' . (int)$id_order
        );

        if (!$data) {
            return '<div class="card mt-3"><div class="card-body">
                        <strong>No PayGate transaction data found for this order.</strong>
                    </div></div>';
        }

        $this->context->smarty->assign([
            'paygate' => $data,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_data.tpl');
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitInstantPay')) {
            $fields = [
                'INP_ENABLED','INP_TITLE','INP_DESCRIPTION','INP_CUSTOM_DOMAIN','INP_ICON_URL',
                'INP_API_URL','INP_API_DOMAIN','INP_AUTH_TYPE','INP_AUTH_VALUE','INP_WALLET',
                'INP_TEST_MODE','INP_REDIRECT_MODE','INP_SUCCESS_KEY','INP_SUCCESS_VALUE','INP_TOLERANCE'
            ];

            foreach ($fields as $field) {
                $value = Tools::getValue($field);
                if (is_string($value)) {
                    $value = trim($value);
                }
                Configuration::updateValue($field, $value);
            }
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('Instant Payment Settings')],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable PayGate.to Payment Gateway'),
                        'name' => 'INP_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'name' => 'INP_TITLE',
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Description'),
                        'name' => 'INP_DESCRIPTION',
                        'rows' => 3,
                        'cols' => 40,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Custom Domain'),
                        'name' => 'INP_API_URL',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API Domain'),
                        'name' => 'INP_API_DOMAIN',
                        'placeholder' => 'api.paygate.to',
                        'desc' => $this->l('Your dedicated API endpoint from paygate.to (get it at paygate.to/white-label-api-custom-domain-guide/#dedicated-endpoint). Leave empty to use the shared api.paygate.to.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Wallet'),
                        'name' => 'INP_WALLET',
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Fees Tolerance'),
                        'name' => 'INP_TOLERANCE',
                        'desc' => $this->l('Select percentage to tolerate higher provider fees while detecting manipulation by bad customers at checkout page.'),
                        'options' => [
                            'query' => [
                                ['id' => '0.90', 'name' => '10%'],
                                ['id' => '0.80', 'name' => '20%'],
                                ['id' => '0.70', 'name' => '30%'],
                                ['id' => '0.60', 'name' => '40%'],
                                ['id' => '0.50', 'name' => '50%'],
                                ['id' => '0.40', 'name' => '60%'],
                                ['id' => '0.30', 'name' => '70%'],
                                ['id' => '0.20', 'name' => '80%'],
                                ['id' => '0.10', 'name' => '90%'],
                                ['id' => '0', 'name' => $this->l('Disabled amount detection')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Icon URL'),
                        'name' => 'INP_ICON_URL',
                    ]
                ],
                'submit' => ['title' => $this->l('Save'), 'name' => 'submitInstantPay']
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitInstantPay';

        $fields = [
            'INP_ENABLED','INP_TITLE','INP_DESCRIPTION','INP_CUSTOM_DOMAIN','INP_ICON_URL',
            'INP_API_URL','INP_API_DOMAIN','INP_AUTH_TYPE','INP_AUTH_VALUE','INP_WALLET',
            'INP_TEST_MODE','INP_REDIRECT_MODE','INP_SUCCESS_KEY','INP_SUCCESS_VALUE','INP_TOLERANCE'
        ];

        foreach ($fields as $field) {
            $value = Configuration::get($field);
            $helper->fields_value[$field] = ($value !== false) ? $value : '';
        }

        return $helper->generateForm([$fields_form]);
    }

    /**
     * API host: dedicated API endpoint from settings, or the shared api.paygate.to when empty/invalid
     */
    public static function apiHost()
    {
        $host = strtolower(trim((string)Configuration::get('INP_API_DOMAIN')));
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');
        if ($host === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return 'api.paygate.to';
        }
        return $host;
    }

    public function hookDisplayHeader($params)
    {
        $this->context->smarty->assign('cookie', $this->context->cookie);
    }

    public function hookPaymentOptions($params)
    {
        if (!Configuration::get('INP_ENABLED') || Configuration::get('INP_WALLET') == '' || !$this->active) {
            return [];
        }

        $cart = $this->context->cart;
        if (!$cart || !$cart->nbProducts()) {
            return [];
        }

        $title = Configuration::get('INP_TITLE');
        $description = Configuration::get('INP_DESCRIPTION');
        $icon_url = Configuration::get('INP_ICON_URL');

        $option = new PaymentOption();
        $option->setCallToActionText($title)
            ->setModuleName($this->name)
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($description);

        if (!empty($icon_url)) {
            if (filter_var($icon_url, FILTER_VALIDATE_URL)) {
                $option->setLogo($icon_url);
            } else {
                $option->setLogo($this->context->shop->getBaseURL(true) . ltrim($icon_url, '/'));
            }
        } elseif (file_exists($this->local_path . 'views/img/logo.png')) {
            $option->setLogo($this->getPathUri() . 'views/img/logo.png');
        }

        return [$option];
    }

    public function hookDisplayPayment($params)
    {
        $options = $this->hookPaymentOptions($params);
        if (!empty($options)) {
            foreach ($options as $option) {
                echo '<div class="payment-option">'.$option->getCallToActionText().'</div>';
            }
        }
    }

    public function hookPaymentReturn($params)
    {
        if ($this->active) {
            $this->context->smarty->assign([
                'status' => 'ok',
                'order' => isset($params['order']) ? $params['order'] : null
            ]);
            return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
        }
    }
}
