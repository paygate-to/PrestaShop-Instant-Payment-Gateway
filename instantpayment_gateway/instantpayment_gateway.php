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
        $this->version = '1.0.0';
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
            && $this->createPaymentDetailTable();
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
            'INP_API_URL' => 'https://checkout.paygate.to',
            'INP_AUTH_TYPE' => 'bearer',
            'INP_AUTH_VALUE' => '',
            'INP_WALLET' => '',
            'INP_TEST_MODE' => 1,
            'INP_REDIRECT_MODE' => 0,
            'INP_SUCCESS_KEY' => 'success',
            'INP_SUCCESS_VALUE' => '1',
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
            'INP_API_URL', 'INP_AUTH_TYPE', 'INP_AUTH_VALUE', 'INP_WALLET',
            'INP_TEST_MODE', 'INP_REDIRECT_MODE', 'INP_SUCCESS_KEY', 'INP_SUCCESS_VALUE'
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
                'INP_API_URL','INP_AUTH_TYPE','INP_AUTH_VALUE','INP_WALLET',
                'INP_TEST_MODE','INP_REDIRECT_MODE','INP_SUCCESS_KEY','INP_SUCCESS_VALUE'
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
                        'label' => $this->l('Wallet'),
                        'name' => 'INP_WALLET',
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
            'INP_API_URL','INP_AUTH_TYPE','INP_AUTH_VALUE','INP_WALLET',
            'INP_TEST_MODE','INP_REDIRECT_MODE','INP_SUCCESS_KEY','INP_SUCCESS_VALUE'
        ];

        foreach ($fields as $field) {
            $value = Configuration::get($field);
            $helper->fields_value[$field] = ($value !== false) ? $value : '';
        }

        return $helper->generateForm([$fields_form]);
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
