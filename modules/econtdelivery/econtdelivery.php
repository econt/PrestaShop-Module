<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class EcontDelivery extends Module {

    private $endpoints = array(
        'prod' => array(
            'name' => 'prod',
            'URL' => 'https://delivery.econt.com',
            'label' => 'Реална',
            'ee_URL' => 'https://ee.econt.com'
        ),
        'test' => array(
            'name' => 'test',
            'URL' => 'https://delivery-demo.econt.com',
            'label' => 'Тестова',
            'ee_URL' => 'https://demo.econt.com/ee'
        )
    );
    private $econtDeliveryPaymentModule = array(
        'name' => 'econtdeliverypayment',
        'tab' => 'payments_gateways'
    );
    private $hooks = array(
        'actionCarrierUpdate',

        'displayCarrierExtraContent',

        'actionValidateStepComplete',
        'actionValidateOrder',

        'actionDispatcherBefore',

        'actionOrderStatusPostUpdate',
        'displayAdminOrderTabShip',
        'displayAdminOrderMain'
    );

    private function recursiveCopyDir($sourceDir, $destinationDir) {
        $dir = opendir($sourceDir);
        if (!$dir) return;

        @mkdir($destinationDir);
        while (($file = @readdir($dir))) {
            if (( $file != '.' ) && ( $file != '..' )) {
                if ( is_dir($sourceDir . DIRECTORY_SEPARATOR . $file) ) $this->recursiveCopyDir($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
                else @copy($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
            }
        }
        closedir($dir);
    }
    private function recursiveRemoveDir($dirName) {
        if (!is_dir($dirName)) return;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $dirContent) {
            ($dirContent->isDir() && !$dirContent->isLink()) ? @rmdir($dirContent->getPathname()) : @unlink($dirContent->getPathname());
        }
        @rmdir($dirName);
    }
    public static function getProtectionHash($params) {
        return hash_hmac('sha1', serialize($params), Configuration::get('ECONT_DELIVERY_SECRET'));
    }
    public static function updateOrderStatus($order, $langID) {
        $orderHistoryRows = $order->getHistory($langID);
        return (
            (is_array($orderHistoryRows) && !empty($orderHistoryRows))
            &&  (
                count($orderHistoryRows) === 1
                &&  intval($orderHistoryRows[0]['id_employee']) === 0
                &&  intval($orderHistoryRows[0]['id_order_state']) === intval(Configuration::get('PS_OS_BANKWIRE'))
            )
        );
    }

    public function __construct() {
        $this->name = 'econtdelivery';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.228'; // change from zip generator
        $this->author = 'ITPartner';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Достави с Еконт');
        $this->description = $this->l('Модул за доставка с Еконт Експрес.');

        $this->confirmUninstall = $this->l('Сигурни ли сте, че искате да премахнете модула?');
    }

    public function install() {
        if (Shop::isFeatureActive()) Shop::setContext(Shop::CONTEXT_ALL);
        if (!parent::install()) return false;

        $carrier = new Carrier();
        $carrier->name = $this->l('Еконт Експрес');
        $carrier->active = true;
        $carrier->deleted = 0;
        $carrier->url = 'https://www.econt.com/services/track-shipment/@';
        $carrier->shipping_handling = true;
        $carrier->range_behavior = 0;
        $carrier->delay = array(
            Configuration::get('PS_LANG_DEFAULT') => $this->trans('Един работен ден', [], 'EcontDelivery')
        );
        $carrier->shipping_external = true;
        $carrier->is_module = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;

        $econtDeliveryOrderInfoTable = _DB_PREFIX_ . 'econt_delivery_order_info';
        $engine = _MYSQL_ENGINE_;
        Db::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `{$econtDeliveryOrderInfoTable}` (
                `id_order` INT(10) UNSIGNED NOT NULL,
                `info` MEDIUMTEXT NOT NULL,
                PRIMARY KEY (`id_order`)
            )
            ENGINE={$engine}
        ");
        Db::getInstance()->execute(sprintf("
            CREATE TABLE IF NOT EXISTS `%secont_delivery_offices` (
                `id` INT(11) NOT NULL,
                `env` VARCHAR(512) NOT NULL DEFAULT '' COLLATE 'utf8mb4_general_ci',
                `code` VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8mb4_general_ci',
                `full_address` VARCHAR(512) NULL DEFAULT NULL COLLATE 'utf8mb4_general_ci',
                PRIMARY KEY (`id`, `env`) USING BTREE
            )
            COLLATE='utf8mb4_general_ci'
            ENGINE={$engine}
        ", _DB_PREFIX_));

        if ($carrier->add()) {
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group',array(
                    'id_carrier' => (int) $carrier->id,
                    'id_group' => (int) $group['id_group']
                ));
            }

            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '1000000';
            $rangePrice->add();

            $rangeWeight = new RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '1000000';
            $rangeWeight->add();

            $zones = Zone::getZones(true);
            foreach ($zones as $z) {
                Db::getInstance()->insert('carrier_zone', array('id_carrier' => (int) $carrier->id, 'id_zone' => (int) $z['id_zone']));
                Db::getInstance()->insert('delivery', array('id_carrier' => $carrier->id, 'id_range_price' => (int) $rangePrice->id, 'id_range_weight' => NULL, 'id_zone' => (int) $z['id_zone'], 'price' => '0'), true);
                Db::getInstance()->insert('delivery', array('id_carrier' => $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int) $rangeWeight->id, 'id_zone' => (int) $z['id_zone'], 'price' => '0'), true);
            }

            copy(dirname(__FILE__) . '/logo.png', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.png'); //assign carrier logo

            Configuration::updateValue('ECONT_DELIVERY_CARRIER_ID', $carrier->id);
            Configuration::updateValue('ECONT_DELIVERY_CARRIER_ID_REF', $carrier->id);

            Configuration::updateValue('ECONT_DELIVERY_PAYMENT_TITLE', $this->l('EcontPay'));
        }

        $this->enablePaymentModule();

        foreach ($this->hooks as $hook) if (!$this->registerHook($hook)) return false;

        return true;
    }
    public function uninstall() {
        if (!parent::uninstall()) return false;

        $carrier = new Carrier(Configuration::get('ECONT_DELIVERY_CARRIER_ID'));
        $carrier->delete();

        try {
            $this->disablePaymentModule();

            $paymentModule = Module::getInstanceByName($this->econtDeliveryPaymentModule['name']);
            if (!$paymentModule) throw new Exception($this->l('Проблем при създаване на инстанция за "EcontPay"'));
            $paymentModule->uninstall();
            $this->recursiveRemoveDir(dirname(__DIR__) . DIRECTORY_SEPARATOR . $this->econtDeliveryPaymentModule['name']);
        } catch (Exception $exception) {
            return false;
        }

        foreach ($this->hooks as $hook) if (!$this->unregisterHook($hook)) return false;

        return true;
    }


    public function getContent() {
        $output = '';

        if (Tools::isSubmit('refreshOffices')) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([]));
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            foreach ($this->endpoints as $endpoint) {
                curl_setopt($curl, CURLOPT_URL, sprintf('%s/services/Nomenclatures/NomenclaturesService.getOffices.json', $this->endpoints[$endpoint['name']]['ee_URL']));
                $response = json_decode(curl_exec($curl), true);
                if (!empty($response['offices'])) Db::getInstance()->insert('econt_delivery_offices', array_map(function ($office) use ($endpoint) {
                    return [
                        'id' => $office['id'],
                        'env' => $endpoint['name'],
                        'code' => $office['code'],
                        'full_address' => $office['address']['fullAddress'],
                    ];
                }, $response['offices']), false, true, Db::REPLACE);
            }
            curl_close($curl);
        } elseif (Tools::isSubmit('submitEcontDeliverySettings')) {
            $newEndpointURL = $this->endpoints[Tools::getValue('ECONT_DELIVERY_ENDPOINT')]['URL'];
            $newSecret = Tools::getValue('ECONT_DELIVERY_SECRET');

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "{$newEndpointURL}/services/PluginsService.logEvent.json");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: {$newSecret}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([[
                'plugin_type' => 'prestashop',
                'action' => 'activate'
            ]]));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $res = curl_exec($curl);
            $response = json_decode($res, true);
            curl_close($curl);

            try {
                if (!is_array($response)) throw new Exception($this->l('Възникна грешка при свързване към услугите Еконт'));
                if ($response['type'] == 'ExAccessDenied') throw new Exception($this->l('Кода за свързване не е коректен.'));

                // configurations
                Configuration::updateValue('ECONT_DELIVERY_ENDPOINT', $newEndpointURL);
                Configuration::updateValue('ECONT_DELIVERY_SECRET', $newSecret);

                // payment module
                (Tools::getValue('ECONT_DELIVERY_PAYMENT') ? $this->enablePaymentModule() : $this->disablePaymentModule());

                // payment
                Configuration::updateValue('ECONT_DELIVERY_PAYMENT_TITLE', 'EcontPay');

                Configuration::updateValue('ECONT_DELIVERY_MESSAGE_PLACEHOLDER', Tools::getValue('ECONT_DELIVERY_MESSAGE_PLACEHOLDER'));

                $output .= $this->displayConfirmation($this->trans('Settings updated.', array(), 'Admin.Notifications.Success'));
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name);
            } catch (Exception $exception) {
                $output .=  $this->displayError($exception->getMessage());
            }
        }

        $helper = new HelperForm();
        $helper->submit_action = 'submitEcontDeliverySettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $endpointOptionsQuery = array();
        foreach ($this->endpoints as $endpoint) {
            $endpointOptionsQuery[] = array(
                'idq' => $endpoint['name'],
                'name' => $this->l($endpoint['label']),
            );
        }
        $endPointName = reset($this->endpoints)['name'];
        $currentEndpointURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint['URL'] !== $currentEndpointURL) continue;

            $endPointName = $endpoint['name'];
        }

        $secret = Configuration::get('ECONT_DELIVERY_SECRET');
        $helper->tpl_vars = array(
            'fields_value' => array(
                'ECONT_DELIVERY_ENDPOINT' => $endPointName,
                'ECONT_DELIVERY_SECRET' => $secret,
                'ECONT_DELIVERY_SECRET_HIDDEN' => $secret,
                'ECONT_DELIVERY_PAYMENT' => ((intval(Module::getModuleIdByName($this->econtDeliveryPaymentModule['name'])) <= 0 || !Module::isEnabled($this->econtDeliveryPaymentModule['name'])) ? 0 : 1),

                // payment
                'ECONT_DELIVERY_PAYMENT_TITLE' => 'EcontPay',

                'ECONT_DELIVERY_MESSAGE_PLACEHOLDER' => Configuration::get('ECONT_DELIVERY_MESSAGE_PLACEHOLDER'),
                'ECONT_DELIVERY_UPDATE_OFFICES_DATA' => ''
            )
        );

        ob_start() ;?>
        <!--suppress CssUnusedSymbol -->
        <style>
            #configuration_form #ECONT_DELIVERY_ENDPOINT {
                width: 297px !important;
            }
            #configuration_form #ECONT_DELIVERY_MESSAGE_PLACEHOLDER {
                width: 500px;
            }
            #configuration_form #ECONT_DELIVERY_SECRET {
                width: 250px !important;
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                $('#ECONT_DELIVERY_SECRET').val($('#ECONT_DELIVERY_SECRET_HIDDEN').val());

                var $updateOfficesContainer = $("#configuration_form #ECONT_DELIVERY_UPDATE_OFFICES_DATA").parents('.form-group');
                $updateOfficesContainer.removeClass("hide");
                $updateOfficesContainer.append(
                    $("<label></label>").attr({
                        "class": "control-label col-lg-4"
                    }),
                    $("<div></div>").attr({
                        "class": "col-lg-8"
                    }).append(
                        $("<button></button>").attr({
                            "type": "submit",
                            "name": "refreshOffices",
                            "value": 1,
                            "class": "btn btn-default"
                        }).append(
                            $("<i></i>").attr({"class": "process-icon-update"}),
                            <?=json_encode($this->trans('Обнови офиси', [], 'EcontDelivery'))?>
                        )
                    )
                );
            });
        </script>
        <?php $outputOther = ob_get_contents();
        ob_end_clean();
        return $output . $helper->generateForm(array(
            array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->displayName,
                        'icon' => 'icon-share'
                    ),
                    'input' => array(
                        array(
                            'type' => 'select',
                            'name' => 'ECONT_DELIVERY_ENDPOINT',
                            'label' => $this->l("Среда"),
                            'options' => array(
                                'query' => $endpointOptionsQuery,
                                'id' => 'idq',
                                'name' => 'name'
                            )
                        ),
                        array(
                            'type' => 'password',
                            'name' => 'ECONT_DELIVERY_SECRET',
                            'label' => $this->l("Код за свързване"),
                            'maxlength' => 100,
                        ),
                        array(
                            'type' => 'hidden',
                            'name' => 'ECONT_DELIVERY_SECRET_HIDDEN'
                        ),

                        // payment
                        array(
                            'type' => 'switch',
                            'label' => $this->l('EcontPay'),
                            'name' => 'ECONT_DELIVERY_PAYMENT',
                            'required' => false,
                            'is_bool' => true,
                            'values' => array(
                                array(
                                    'value' => 1,
                                    'label' => $this->l('Active')
                                ),
                                array(
                                    'value' => 0,
                                    'label' => $this->l('Inactive')
                                )
                            )
                        ),

                        [
                            'type' => 'text',
                            'name' => 'ECONT_DELIVERY_MESSAGE_PLACEHOLDER',
                            'label' => $this->trans('Допълнение към адреса (placeholder)', [], 'EcontDelivery')
                        ],
                        [
                            'type' => 'hidden',
                            'name' => 'ECONT_DELIVERY_UPDATE_OFFICES_DATA'
                        ]
                    ),
                    'submit' => array(
                        'title' => $this->trans('Save', array(), 'Admin.Actions')
                    )
                )
            )
        )) . $outputOther;
    }

    private function enablePaymentModule() {
        $this->recursiveCopyDir(__DIR__ . DIRECTORY_SEPARATOR . $this->econtDeliveryPaymentModule['name'], dirname(__DIR__) . DIRECTORY_SEPARATOR . $this->econtDeliveryPaymentModule['name']);
        if (!in_array($this->econtDeliveryPaymentModule['name'], Module::getModulesDirOnDisk())) throw new Exception($this->l('Модулът "EcontPay" не е намерен'));

        if (intval(Module::getModuleIdByName($this->econtDeliveryPaymentModule['name'])) <= 0) {
            $paymentModule = Module::getInstanceByName($this->econtDeliveryPaymentModule['name']);
            if (!$paymentModule->install()) throw new Exception($this->l('Възникна грешка при инсталиране на модула "EcontPay"'));
        }

        $paymentModule = Module::getInstanceByName($this->econtDeliveryPaymentModule['name']);
        if (!$paymentModule) throw new Exception($this->l('Проблем при създаване на инстанция за "EcontPay"'));
        $paymentModule->enable(true);

        return true;
    }
    private function disablePaymentModule() {
        $paymentModule = Module::getInstanceByName($this->econtDeliveryPaymentModule['name']);
        if (!$paymentModule) throw new Exception($this->l('Проблем при създаване на инстанция за "EcontPay"'));
        $paymentModule->disable(true);

        return true;
    }

    /** @param Cart $cart */
    public function getOrderShippingCost($cart) {
        if ($cart->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return 0;

        $order = null;
        if($cart->id) $order = Order::getByCartId($cart->id);
        if(!$order && !empty($_REQUEST['id_order'])) $order = new Order($_REQUEST['id_order']);

        $orderId = @intval($order->id);
        if ($orderId > 0) {
            $customerInfo = @json_decode(Db::getInstance()->getRow(sprintf("SELECT * FROM %secont_delivery_order_info WHERE id_order = {$orderId}", _DB_PREFIX_))['info'], true);
            if ($order->module === 'ps_cashondelivery') return @$customerInfo['shipping_price_cod'];
            elseif ($order->module === $this->econtDeliveryPaymentModule['name']) return @$customerInfo['shipping_price_cod_e'];
            else return @$customerInfo['shipping_price'];
        } elseif (($customersInfo = self::storage('customersInfo')[$cart->id])) {
            $cartPrice = round($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2);
            $cartWeight = floatval($cart->getTotalWeight());
            foreach ($customersInfo as $customerInfo) {
                if (
                        $customerInfo['id_address'] != $cart->id_address_delivery
                    ||  $customerInfo['id_currency'] != $cart->id_currency
                    ||  $customerInfo['weight'] != $cartWeight
                    ||  $customerInfo['price'] != $cartPrice
                ) continue;

                $controllerClassName = get_class($this->context->controller);
                if ($_REQUEST['source'] === 'EcontDelivery') {
                    if ($_REQUEST['paymentModule'] == 'ps_cashondelivery') $controllerClassName = 'Ps_CashondeliveryValidationModuleFrontController';
                    elseif ($_REQUEST['paymentModule'] == $this->econtDeliveryPaymentModule['name']) $controllerClassName = 'EcontDeliveryPaymentValidationModuleFrontController';
                }

                if ($controllerClassName === 'Ps_CashondeliveryValidationModuleFrontController') return $customerInfo['shipping_price_cod'];
                elseif ($controllerClassName === 'EcontDeliveryPaymentValidationModuleFrontController') return $customerInfo['shipping_price_cod_e'];
                else return $customerInfo['shipping_price'];
            }
        }

        return 0;
    }

    public function syncOrder($orderID, $paymentToken = '') {
        if (!($deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT'))) return;
        if (!($deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET'))) return;

        if (!($orderID = intval($orderID))) return;
        $pref = _DB_PREFIX_;
        $defLangID = Configuration::get('PS_LANG_DEFAULT');
        $orderData = Db::getInstance()->getRow("
            SELECT 
                o.*,
                i.info as econt_delivery_info,
                osl.name as order_status,
                cr.iso_code as currency_code
            FROM {$pref}orders o
            JOIN {$pref}cart c ON c.id_cart = o.id_cart
            JOIN {$pref}econt_delivery_order_info i ON i.id_order = o.id_order
            JOIN (SELECT id_lang FROM {$pref}lang ORDER BY id_lang = $defLangID DESC LIMIT 1) as dl
            JOIN {$pref}order_state_lang osl ON osl.id_order_state = o.current_state AND osl.id_lang = dl.id_lang
            JOIN {$pref}currency cr ON cr.id_currency = o.id_currency
            WHERE o.id_order = $orderID
        ",false);

        if (empty($orderData) || $orderData['id_carrier'] != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return;

        $customerInfo = json_decode($orderData['econt_delivery_info'],true);
        if (!$customerInfo || empty($customerInfo['id'])) return;

        $o = new Order($orderID);

        $order = array(
            'customerInfo' => array(
                'id' => $customerInfo['id']
            ),
            'orderNumber' => $orderData['id_order'],
            'shipmentDescription' => sprintf("Order #{$orderData['id_order']}"),
            'status' => $orderData['order_status'],
            'orderTime' => $orderData['date_add'],
            'currency' => $orderData['currency_code'],
            'cod' => in_array($orderData['module'], array('ps_cashondelivery', $this->econtDeliveryPaymentModule['name'])),
            'partialDelivery' => 1,
            'items' => array(),
            'paymentToken' => $paymentToken
        );

        $link = new Link();

        $d = $o->total_products_wt + $o->total_wrapping_tax_incl - $o->total_discounts_tax_incl;

        foreach($o->getProducts() as $product) {
            $order['items'][] = array(
                'name' => $product['product_name'],
                'SKU' => $product['reference'],
                'URL' => $link->getProductLink($product['product_id']),
                'count' =>$product['product_quantity'],
                'totalPrice' => $product['total_price_tax_incl'],
                'totalWeight' => $product['product_weight'] * $product['product_quantity']
            );
            $d -= $product['total_price_tax_incl'];
        }

        if(round($d,2) != 0) {
            $order['items'][] = array(
                'name' => 'Отстъпка',
                'SKU' => '',
                'URL' => '',
                'count' => 1,
                'totalPrice' => $d,
                'totalWeight' => 0
            );
            $order['partialDelivery'] = 0;
        }

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/OrdersService.updateOrder.json");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: {$deliverySecret}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($order));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            curl_exec($curl);
            curl_close($curl);
        } catch (Exception $exception) {}
    }

    public function hookActionCarrierUpdate($params) {
        if ($params['carrier']->id_reference == Configuration::get('ECONT_DELIVERY_CARRIER_ID_REF')) {
            Configuration::updateValue('ECONT_DELIVERY_CARRIER_ID', $params['carrier']->id);
        }
    }
    public function hookActionOrderStatusPostUpdate($params) {
        $this->syncOrder($params['id_order']);
    }
    public function hookActionDispatcherBefore() {
        if (
                isset($_REQUEST['confirmDeliveryOption'])
            &&  $_REQUEST['confirmDeliveryOption']
            &&  $econtDeliveryInfo = json_decode($_REQUEST['econt_delivery_info'],true)
        ) $this->context->cookie->{"econt_delivery_info-".$this->context->cookie->id_cart} = $_REQUEST['econt_delivery_info'];
        if (
                isset($_REQUEST['controller'])
            &&  $_REQUEST['controller'] == 'AdminOrders'
            &&  isset($_REQUEST['submitAddressShipping'])
            &&  !empty($_REQUEST['id_order']) && !empty($_REQUEST['econt_delivery_customer_info'])
        ) {
            Db::getInstance()->insert('econt_delivery_order_info',array(
                'id_order' => $_REQUEST['id_order'],
                'info' => Db::getInstance()->escape($_REQUEST['econt_delivery_customer_info']),
            ),false,false,Db::REPLACE);
            $this->syncOrder($_REQUEST['id_order']);
        }
    }

    public function hookDisplayCarrierExtraContent() {
        $context = $this->context;

        $address = new Address(intval($context->cart->id_address_delivery));
        $customerInfo = [];
        if ($customerInfoId = self::pregMatchCustomerInfoId($address->other)) $customerInfo = self::storage('customersInfo')[$context->cart->id][$customerInfoId];

        $frameParams = [
            'id_shop' => intval(substr(Configuration::get('ECONT_DELIVERY_SECRET'), 0, strpos(Configuration::get('ECONT_DELIVERY_SECRET'), '@'))),

            'customer_company' => $customerInfo['name'] ?: $address->company,
            // 'hide_sender_face' => 1
            // 'name_field_text' => ''
            'customer_name' =>   $customerInfo['face'] ?: ($address->firstname . ' ' . $address->lastname),
            'customer_email' => $customerInfo['email'] ?: $context->customer->email,
            'customer_phone' =>  $customerInfo['phone'] ?: ($address->phone ?: $address->phone_mobile),

            'customer_country' => $customerInfo['country_code'] ?: ($address->id_country ? Country::getIsoById($address->id_country) : $this->context->country->iso_code),
            'customer_zip' => $customerInfo['zip'] ?: '',
            'customer_post_code' => $customerInfo['post_code'] ?: $address->postcode,
            'customer_city_name' => $customerInfo['city_name'] ?: $address->city,
            'customer_address' => $customerInfo['address'] ?: "{$address->address1}, {$address->address2}",

            // 'id_order' => 12345
            'pack_count' => 1,
            'order_currency' => $this->context->currency->iso_code,
            'order_total' => round($this->context->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2),
            'order_weight' => floatval($this->context->cart->getTotalWeight()),

            'ignore_history' => 1,
            // 'confirm_txt' => ''
            // 'autoConfirmMode' => 1

            'lang' => $this->context->language->iso_code,

            'module' => 'prestashop-custom'
        ];
        if ($customerInfo['office_code']) $frameParams['customer_office_code'] = $customerInfo['office_code'];
        elseif ($address->address2 === $this->trans('Доставка до офис на Еконт', [], 'EcontDelivery')) $frameParams['customer_office_code'] = intval($address->address1);

        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $frameURL = $deliveryBaseURL . '/customer_info.php?' . http_build_query($frameParams, null, '&');

        ob_start();?>
        <iframe id="econt_delivery_customer_info_frame" style="width:100%;height:612px;border:none;margin-top: 15px;" src="<?=$frameURL?>"></iframe>
        <input type="hidden" id="econt_delivery_info" name="econt_delivery_info">
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var $hiddenInput = $('#econt_delivery_info');
                var shippingInfo = null;
                var $frame = $('#econt_delivery_customer_info_frame');
                var $tRBtn = $('.delivery-options input:radio');
                var $econtRadio = $tRBtn.filter(function(i,el){return parseInt(el.value) == <?php echo Configuration::get('ECONT_DELIVERY_CARRIER_ID')?>;});
                var $confirmBtn = $('.continue[name=confirmDeliveryOption]');
                $econtRadio.parents('.delivery-option').find('.carrier-price').text('---');
                $('.delivery-options .custom-radio span').click(function() {
                    let inp = $(this).siblings('input')[0];
                    if (parseInt(inp.value) == <?php echo Configuration::get('ECONT_DELIVERY_CARRIER_ID')?>) {
                        $frame.parent().show();
                        $confirmBtn.attr({disabled:true});
                    } else {
                        $frame.parent().hide();
                        $confirmBtn.attr({disabled:false});
                    }
                });
                $tRBtn.change(function(e){
                    e.stopPropagation();
                    if(parseInt(this.value) == <?php echo Configuration::get('ECONT_DELIVERY_CARRIER_ID')?>) {
                        $frame.parent().show();
                        $confirmBtn.attr({disabled:true});
                    } else {
                        $frame.parent().hide();
                        $confirmBtn.attr({disabled:false});
                    }
                });
                $('.delivery-options input:radio:checked').each(function(){
                    if(parseInt(this.value) == <?php echo Configuration::get('ECONT_DELIVERY_CARRIER_ID')?>) {
                        $econtRadio = $(this);
                        $frame.parent().show();
                        $confirmBtn.attr({disabled:true});
                    }
                });

                $("#checkout-payment-step .payment-options input[type=radio]").click(function() {
                    var $this = $(this);
                    $.ajax({
                        type: "post",
                        cache: false,
                        dataType: "json",
                        url: "<?php echo $this->context->link->getPageLink('cart', null, null, [
                            'ajax' => 1,
                            'action' => 'refresh',
                            'source' => 'EcontDelivery'
                        ]); ?>",
                        data: {
                            "paymentModule": $this.attr("data-module-name")
                        },
                        success: function(data) {
                            for (var dataKey in data) {
                                $("#js-checkout-summary .js-" + dataKey.replaceAll("_", "-")).html(data[dataKey]);
                            }
                        }
                    });
                });

                var deliveryMessagePlaceholder = <?=json_encode(Configuration::get('ECONT_DELIVERY_MESSAGE_PLACEHOLDER'))?>;
                if (deliveryMessagePlaceholder) $("#checkout-delivery-step #delivery_message").attr({"placeholder": deliveryMessagePlaceholder});

                $(window).unbind('message.econtShipping');
                $(window).bind('message.econtShipping', function(e) {
                    if(e.originalEvent.origin.indexOf('<?=$deliveryBaseURL?>') == 0) {
                        if(e.originalEvent.data['shipment_error']) {
                            alert(e.originalEvent.data['shipment_error'])
                        } else {
                            shippingInfo = e.originalEvent.data;

                            shippingInfo["shipping_price"] = (Math.round(shippingInfo["shipping_price"] * 100) / 100).toFixed(2);
                            shippingInfo["shipping_price_cod"] = (Math.round(shippingInfo["shipping_price_cod"] * 100) / 100).toFixed(2);
                            shippingInfo["shipping_price_cod_e"] = (Math.round(shippingInfo["shipping_price_cod_e"] * 100) / 100).toFixed(2);

                            $frame.parent().hide();
                            $confirmBtn.attr({disabled:false});
                            var labelTxt = shippingInfo['shipping_price'] + ' ' + shippingInfo['shipping_price_currency_sign'];
                            if ((shippingInfo['shipping_price'] != shippingInfo['shipping_price_cod']) || (shippingInfo['shipping_price'] != shippingInfo['shipping_price_cod_e'])) {
                                labelTxt += ' (+ '
                                    + (Math.round((shippingInfo['shipping_price_cod'] - shippingInfo['shipping_price']) * 100) / 100).toFixed(2) + ' '
                                    + shippingInfo['shipping_price_currency_sign'] + ' '
                                    + <?php echo  json_encode($this->trans('наложен платеж', [], 'EcontDelivery')); ?>
                                + ')';
                            }
                            $econtRadio.parents('.delivery-option').find('.carrier-price').html(labelTxt);
                            $hiddenInput.val(JSON.stringify(e.originalEvent.data));

                            sessionStorage.setItem("lastEcontShippingInfo", JSON.stringify(shippingInfo));
                        }
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    public function hookDisplayAdminOrderMain($params) {
        $params['order'] = new Order($params['id_order']);
        return $this->hookDisplayAdminOrderTabShip($params);
    }
    public function hookDisplayAdminOrderTabShip($params) {
        /** @var Order $order */
        $order = $params['order'];

        if ($order->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return '';

        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET');
        if(!($order->id && $deliveryBaseURL && $deliverySecret)) return '';

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/OrdersService.getTrace.json");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$deliverySecret}"
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
            'orderNumber' => $order->id
        )));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);

        $response = json_decode(curl_exec($curl), true);
        $responseInfo = curl_getinfo($curl);
        if ($responseInfo['http_code'] !== 200) $response['error'] = $response;

        curl_close($curl);

        if (empty($response['error']) && (isset($order->shipping_number) && @$order->shipping_number != $response['shipmentNumber'])) {
            $order->setWsShippingNumber($response['shipmentNumber']);
            @$order->shipping_number = $response['shipmentNumber'];
            $order->update();
        }
        $customer = $order->getCustomer();
        $address = new Address($order->id_address_delivery);
        $currency = $this->context->currency->id == $order->id_currency ? $this->context->currency : Currency::getCurrency($order->id_currency);
        if($address->id_country) {
            $countryCode = Country::getIsoById($address->id_country);
        } else {
            $countryCode = $this->context->country->iso_code;
        }

        $secret = explode('@', Configuration::get('ECONT_DELIVERY_SECRET'));
        $secret = @reset($secret);
        $customerInfoParams = array(
            'id_shop' => intval($secret),
            'order_weight' => $order->getTotalWeight(),
            'order_total' => $order->getTotalProductsWithTaxes(),
            'order_currency' => $currency->iso_code,
            'customer_company' => $customer->company,
            'customer_name' => "{$customer->firstname} {$customer->lastname}",
            'customer_email' => $customer->email,
            'customer_phone' =>  $address->phone ? $address->phone : $address->phone_mobile,
            'customer_country' => $countryCode,
            'customer_city_name' => $address->city,
            'customer_post_code' => $address->postcode,
            'customer_address' => $address->address1.' '.$address->address2,

            'ignore_history' => 1,
            'default_css' => 1
        );

        $prepareLoadingFrameURL = $deliveryBaseURL. '/create_label.php?' . http_build_query(array('order_number' => $order->id, 'token' => $deliverySecret));
        $customerInfoFrameURL = $deliveryBaseURL.'/customer_info.php?' . http_build_query($customerInfoParams, null, '&');

        ob_start();?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                $(document.body).delegate('a', 'click', function(e) {
                    if (
                            this.href.indexOf('generateDeliverySlipPDF') > 0
                        ||  this.href.indexOf('generate-delivery-slip-pdf') > 0
                    ) {
                        var num = $('.shipping_number_show').text().trim();
                        if (!num) num = '<?=@$order->shipping_number?>';
                        if (num) {
                            window.open(<?=json_encode(@$response['pdfURL']);?>);
                        } else {
                            $('<a href="<?=$prepareLoadingFrameURL?>">Generate Label</a>').appendTo(document.body).fancybox({width:700,height:'100%',type:'iframe',title:''}).trigger('click').remove()
                        }
                        e.preventDefault();
                    }
                });

                $('.changeEcontAddress').click(function() {
                    $('<a href="<?=$customerInfoFrameURL?>">Change Econt Address</a>').appendTo(document.body).fancybox({width:550,height:630,type:'iframe',title:''}).trigger('click').remove()
                });

                $(window).unbind('message.econtShipping');
                $(window).bind('message.econtShipping', function(e){
                    if(e.originalEvent.origin.indexOf('<?php echo $deliveryBaseURL?>') == 0) {
                        let messageData = e['originalEvent']['data'];
                        if (!messageData) return;

                        if (messageData['event'] == 'cancel') {
                            $('.fancybox-close').trigger('click');
                        } else if(messageData['event'] == 'confirm') {
                            if (messageData['printPdf'] === true && messageData['shipmentStatus']['pdfURL']) window.open(messageData['shipmentStatus']['pdfURL'], '_blank');
                            $('.fancybox-close').trigger('click');
                            location.reload();
                        } else if(!messageData['event']) {
                            if(messageData['shipment_error']) {
                                alert(messageData['shipment_error']);
                            } else {
                                let $changeDeliveryBtn = $('.btn.btn-default[name=submitAddressShipping]');
                                $('<input type="hidden" name="econt_delivery_customer_info" />').appendTo($changeDeliveryBtn.parent()).val(JSON.stringify(messageData));
                                $('.fancybox-close').trigger('click');
                                $changeDeliveryBtn.trigger('click');
                            }
                        }
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }
    public function hookActionAdminGenerateDeliverySlipPDFBefore() {
        $orderID = intval($_REQUEST['id_order']);
        if(!empty($_REQUEST['id_order']) && !$orderID) return;
        $pref = _DB_PREFIX_;
        $orderData = Db::getInstance()->getRow("
            SELECT 
                o.id AS id,
                i.info AS econt_delivery_info,
                o.id_carrier AS id_carrier
            FROM {$pref}orders AS o
            JOIN {$pref}econt_delivery_order_info AS i ON TRUE
                AND i.id_order = o.id_order
            WHERE TRUE
                AND o.id_order = {$orderID}
        ", false);
        if(empty($orderData) || $orderData['id_carrier'] != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return;
    }

    private static $countries = [
        'BGR' => 'BG',
        'GRC' => 'GR',
        'CAN' => 'CA',
        'CYP' => 'CY',
        'MKD' => 'MK',
        'ROU' => 'RO',
        'TUR' => 'TR'
    ];
    private static $econtAddressFields = ['alias', 'id_customer', 'id_country', 'firstname', 'lastname', 'company', 'city', 'postcode', 'address1', 'address2', 'phone'];
    public static function getAddressChecksum($address) {
        $addressData = [];
        foreach (self::$econtAddressFields as $fieldName) $addressData[$fieldName] = (string)$address->$fieldName;
        return sha1(serialize($addressData));
    }
    public function hookActionValidateStepComplete($params) {
        /** @var CartCore $cart */
        $cart = $params['cart'];
        if ($params['step_name'] != 'delivery' || $cart->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return;

        try {
            $customerInfo = json_decode($params['request_params']['econt_delivery_info'], true);
            if (!$customerInfo) throw new PrestaShopException($this->trans('Липсват данни за доставка от "Достави с Еконт"', [], 'EcontDelivery'));

            $address = new Address();

            // address label
            $address->id_customer = $cart->id_customer;
            $address->alias =  $this->trans((!empty(trim($customerInfo['office_code'] ?? '')) ? 'Доставка до офис' : 'Доставка до адрес'), [], 'EcontDelivery');

            // receiver data
            $customerInfo_name = trim($customerInfo['name']);
            $customerInfo_face = trim($customerInfo['face']);
            if (!empty($customerInfo_name) && !empty($customerInfo_face)) {
                $address->company = $customerInfo_name;
                $customerInfo_name = $customerInfo_face;
            }
            $customerInfo_name = trim(preg_replace('/\s+/', ' ', ($customerInfo_name ?: $customerInfo_face)));
            if (!(($firstWhitespacePos = strpos($customerInfo_name, ' ')) !== false)) $address->firstname = $customerInfo_name;
            else {
                $address->firstname = substr($customerInfo_name, 0, $firstWhitespacePos);
                $address->lastname = substr($customerInfo_name, strrpos($customerInfo_name, ' ') + 1);
            }

            // destination
            $address->id_country = Country::getByIso(self::$countries[$customerInfo['country_code']] ?: 'BG');
            $address->postcode = trim($customerInfo['post_code']);
            if (empty($address->postcode)) $address->postcode = trim($customerInfo['zip']);
            $address->city = trim($customerInfo['city_name']);

            // address
            if (empty(trim($customerInfo['office_code'] ?? ''))) $address->address1 = trim($customerInfo['address']);
            else {
                $address->address1 = $customerInfo['office_name'];

                $env = 'test';
                foreach ($this->endpoints as $endpoint) {
                    if ($endpoint['URL'] === Configuration::get('ECONT_DELIVERY_ENDPOINT')) {
                        $env = $endpoint['name'];
                        break;
                    }
                }
                $db = Db::getInstance();
                $officeAddress = trim($db->getValue(sprintf("
                    SELECT o.full_address FROM `%s`.`%secont_delivery_offices` AS o WHERE o.code = '%s' AND o.env = '%s'
                ",
                    _DB_NAME_,
                    _DB_PREFIX_,
                    $db->escape($customerInfo['office_code']),
                    $db->escape($env)
                )));
                if (!empty($officeAddress)) $address->address1 .= ", {$officeAddress}";
            }
            $address->other = "EcontCustomerInfoID: {$customerInfo['id']}";

            // phone
            $address->phone = trim($customerInfo['phone']);

            $customerAddresses = $this->context->customer->getAddresses($this->context->language->id);
            if (!empty($customerAddresses)) {
                $addressChecksum = self::getAddressChecksum($address);
                foreach ($customerAddresses as $customerAddress) {
                    if ($addressChecksum !== self::getAddressChecksum((object)$customerAddress)) continue;

                    $address->id = $customerAddress['id_address'];
                    break;
                }
            }
            if ($address->id) $address->update();
            else $address->add();
            $cart->updateDeliveryAddressId($cart->id_address_delivery, $address->id);
            $cart->id_address_delivery = $address->id;
            $cart->setDeliveryOption([$cart->id_address_delivery => "{$cart->id_carrier},"]);
            $cart->update();

            $customerInfo['id_customer'] = $cart->id_customer;
            $customerInfo['id_cart'] = $cart->id;
            $customerInfo['id_address'] = $cart->id_address_delivery;
            $customerInfo['id_currency'] = $cart->id_currency;
            $customerInfo['price'] = round($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2);
            $customerInfo['weight'] = floatval($cart->getTotalWeight());

            $customersInfo = self::storage('customersInfo');
            $customersInfo[$customerInfo['id_cart']][$customerInfo['id']] = $customerInfo;
            self::storage('customersInfo', $customersInfo);
        } catch (Throwable $error) {
            if ($error instanceof PrestaShopException) $errorMessage = trim($error->getMessage());
            $this->context->controller->errors[] = !empty($errorMessage) ? $errorMessage : $this->trans('Възникна грешка! Моля свържете се с администратор', [], 'EcontDelivery');
            $params['completed'] = false;
        }
    }

    public function hookActionValidateOrder($params) {
        /** @var Order $order */
        $order = $params['order'];
        if (
                $order
            &&  $order->id
            &&  $order->id_carrier == Configuration::get('ECONT_DELIVERY_CARRIER_ID')
            &&  ($customerInfo = self::storage('customersInfo')[$order->id_cart][self::pregMatchCustomerInfoId((new Address($order->id_address_delivery))->other)])
        ) Db::getInstance()->insert('econt_delivery_order_info', array(
            'id_order' => intval($order->id),
            'info' => Db::getInstance()->escape(json_encode($customerInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
        ), false, false, Db::REPLACE);
    }

    public static function pregMatchCustomerInfoId($string) {
        preg_match('/^EcontCustomerInfoID: ([a-z0-9]{32})$/i', $string, $maches);
        return @$maches[1];
    }
    public static function storage() {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        $args = func_get_args();
        if ($args[0] && func_num_args() > 1) @$_SESSION['econt_delivery'][$args[0]] = $args[1];

        return @($args[0] ? $_SESSION['econt_delivery'][$args[0]] : $_SESSION['econt_delivery']);
    }

}