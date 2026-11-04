<?php /** @noinspection PhpUnused */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EcontDelivery extends Module {
    /** @var array carts whose prices were asked from Econt without the cache in this request */
    private static $freshPriceCarts = [];


    private $endpoints = array(
        'prod' => array(
            'name' => 'prod',
            'URL' => 'https://delivery.econt.com',
            'label' => 'Реална',
        ),
        'test' => array(
            'name' => 'test',
            'URL' => 'https://delivery-demo.econt.com',
            'label' => 'Тестова',
        ),
//        'dev' => array(
//            'name' => 'dev',
//            'URL' => 'http//delivery.econt.com.local',
//            'label' => 'Developing',
//        )
    );
    private $econtDeliveryPaymentModule = array(
        'name' => 'econtdeliverypayment',
        'tab' => 'payments_gateways'
    );
    private $hooks = array(
        'actionCarrierUpdate',
        'displayCarrierExtraContent',
        'actionDispatcherBefore',
        'displayShoppingCart',
        'displayCheckoutSubtotalDetails',
        'displayCheckoutSummaryTop',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'displayAdminOrderTabShip',
        'displayAdminOrderMain',
        'actionValidateStepComplete'
    );

    private function getAdminBaseURL() {
        return rtrim($this->context->link->getAdminBaseLink(), " \t\n\r\0\x0B/");
    }
    private function getCookiesString() {
        $cookies = [];
        foreach ($_COOKIE as $cookieName => $cookieValue) $cookies[] = "{$cookieName}={$cookieValue}";
        return implode('; ', $cookies);
    }
    private function recursiveCopyDir($sourceDir, $destinationDir) {
        $dir = opendir($sourceDir);
        @mkdir($destinationDir);
        while (($file = readdir($dir))) {
            if (( $file != '.' ) && ( $file != '..' )) {
                if ( is_dir($sourceDir . DIRECTORY_SEPARATOR . $file) ) $this->recursiveCopyDir($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
                else @copy($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
            }
        }
        closedir($dir);
    }
    private function recursiveRemoveDir($dirName) {
        if (!is_dir($dirName)) return false;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $dirContent) {
            ($dirContent->isDir() && !$dirContent->isLink()) ? @rmdir($dirContent->getPathname()) : @unlink($dirContent->getPathname());
        }

        return @rmdir($dirName);
    }
    // Произходът (scheme://host[:port]) на адреса на Еконт, с който се сравнява произходът на съобщенията от формата
    public static function getDeliveryOrigin($deliveryBaseURL) {
        $url = parse_url((string)$deliveryBaseURL);
        if (empty($url['scheme']) || empty($url['host'])) return '';
        return strtolower($url['scheme']) . '://' . strtolower($url['host']) . (isset($url['port']) ? ':' . $url['port'] : '');
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
        $this->version = '2.0.4'; // change from zip generator
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
            Configuration::get('PS_LANG_DEFAULT') => $this->l('един работен ден')
        );
        $carrier->shipping_external = true;
        $carrier->is_module = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;

        $econtDeliveryOrderInfoTable = _DB_PREFIX_ . 'econt_delivery_order_info';
        $econtDeliveryCartInfoTable = _DB_PREFIX_.'econt_delivery_cart_info';

        $engine = _MYSQL_ENGINE_;
        Db::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `{$econtDeliveryOrderInfoTable}` (
                `id_order` INT(10) UNSIGNED NOT NULL,
                `info` MEDIUMTEXT NOT NULL,
                PRIMARY KEY (`id_order`)
            )
            ENGINE={$engine}
        ");

        Db::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `$econtDeliveryCartInfoTable` (
                `id_cart` INT(10) UNSIGNED NOT NULL,
                `info` MEDIUMTEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cart`)
            )
            ENGINE=$engine
        ");

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

        try {
            $this->enablePaymentModule();
        } catch (Exception $exception) {
            return false;
        }

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

        if (Tools::isSubmit('submitEcontDeliverySettings')) {
            $newEndpointURL = $this->endpoints[Tools::getValue('ECONT_DELIVERY_ENDPOINT')]['URL'];
            // Празно поле: остава сегашният код (ключът не се връща в страницата)
            $newSecret = Tools::getValue('ECONT_DELIVERY_SECRET');
            if ($newSecret === '') $newSecret = Configuration::get('ECONT_DELIVERY_SECRET');

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "{$newEndpointURL}/services/PluginsService.logEvent.json");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
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

        $helper->tpl_vars = array(
            'fields_value' => array(
                'ECONT_DELIVERY_ENDPOINT' => $endPointName,
                'ECONT_DELIVERY_SECRET' => '',
                'ECONT_DELIVERY_PAYMENT' => ((intval(Module::getModuleIdByName($this->econtDeliveryPaymentModule['name'])) <= 0 || !Module::isEnabled($this->econtDeliveryPaymentModule['name'])) ? 0 : 1),

                // payment
                'ECONT_DELIVERY_PAYMENT_TITLE' => 'EcontPay'
            )
        );

        ob_start() ;?>
        <style>
            #configuration_form #ECONT_DELIVERY_SECRET {
                width: 170px;
            }

            #configuration_form #ECONT_DELIVERY_PAYMENT_LOGO {
                width: 510px !important;
            }
            #configuration_form #ECONT_DELIVERY_PAYMENT_LOGO_IMAGE {
                margin-top: 10px;
                padding: 10px;
                background-color: #dbd9d9;
                border-radius: 3px;
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                let logoImageBaseURL = '<?=$this->getPathUri() . '/view/img'?>';
                function iconFieldChange() {
                    if (!icon) {
                        icon = document.createElement('img');
                        icon.setAttribute('id', 'ECONT_DELIVERY_PAYMENT_LOGO_IMAGE')
                        iconField.parentNode.insertBefore(icon, iconField.nextSibling);
                    }

                    let selectedIndexValue = iconField.options[iconField.selectedIndex].value;
                    if (selectedIndexValue === '') icon.classList.add('hide');
                    else {
                        icon.setAttribute('src', logoImageBaseURL + '/econt_payment_logo_' + selectedIndexValue + '.png');
                        icon.classList.remove('hide');
                    }
                }
                let icon;
                let iconField = document.querySelector('#ECONT_DELIVERY_PAYMENT_LOGO')
                iconField.addEventListener('change', iconFieldChange);
                iconFieldChange();
            })
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
                            'style' => 'width: 170px',
                            'desc' => Configuration::get('ECONT_DELIVERY_SECRET') ? $this->l('Кодът е въведен. Оставете полето празно, за да остане същият.') : ''
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
                        )
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

    public function getOrderShippingCost($cart, $shipping_cost) {
        // Only the order of this cart (by id_cart), also in the admin; an order id from the request is not used
        $order = null;
        if($cart->id) {
            $id_order = (int)Db::getInstance()->getValue('SELECT id_order FROM '._DB_PREFIX_.'orders WHERE id_cart = '.(int)$cart->id);
            if ($id_order) {
                $order = new Order($id_order);
            }
        }
        // Check if we have an existing order with delivery info
        if($orderID = @intval($order->id)) {
            $pref = _DB_PREFIX_;
            $row = Db::getInstance()->getRow("SELECT * FROM {$pref}econt_delivery_order_info WHERE id_order = $orderID");
            $econtDeliveryInfo = json_decode($row['info'],true);
            $price = ($order->module == 'ps_cashondelivery' ? $econtDeliveryInfo['shipping_price_cod'] : $econtDeliveryInfo['shipping_price']);
            return (float)$price;
        }
        
        // Check DATABASE for cart delivery info (only data with prices from the Econt server)
        $cartData = $this->getCartDeliveryInfo($cart->id);
        if($cartData) {
            $econtDeliveryInfo = json_decode($cartData, true);
            if($econtDeliveryInfo && isset($econtDeliveryInfo['shipping_price']) && !empty($econtDeliveryInfo['econt_server_prices'])) {
                // The prices follow the cart (a changed cart is asked from Econt again, otherwise they come from the
                // cache). When a payment module creates the order they are asked without the cache, once per request:
                // the Econt form keeps its customer info id when the customer changes the address in it.
                $controller = $this->context->controller;
                $creatingOrder = $controller instanceof ModuleFrontController && isset($controller->module) && $controller->module instanceof PaymentModule;
                $fresh = $creatingOrder && empty(self::$freshPriceCarts[(int)$cart->id]);
                $checked = $this->withServerPrices($econtDeliveryInfo, $cart->id, $fresh);
                if($fresh) self::$freshPriceCarts[(int)$cart->id] = true;

                if(!$checked) {
                    if($creatingOrder) return false;
                } else {
                    if($checked['shipping_price'] != $econtDeliveryInfo['shipping_price'] || $checked['shipping_price_cod'] != ($econtDeliveryInfo['shipping_price_cod'] ?? null)) {
                        $this->saveCartDeliveryInfo($cart->id, json_encode($checked));
                    }
                    $econtDeliveryInfo = $checked;
                }

                $price = $econtDeliveryInfo['shipping_price'];
                // Check for cash on delivery
                $controllerClass = get_class($this->context->controller);
                if($controllerClass == 'Ps_CashondeliveryValidationModuleFrontController' 
                   || (isset($_REQUEST['payment_method']) && strpos($_REQUEST['payment_method'], 'cashondelivery') !== false)) {
                    $price = isset($econtDeliveryInfo['shipping_price_cod']) ? $econtDeliveryInfo['shipping_price_cod'] : $price;
                }
                return (float)$price;
            }
        }

        // Without checked data Econt is not available when a payment module creates the order, so it cannot become
        // an Econt order with free shipping. On the checkout page it stays available, so the Econt form can be shown.
        $controller = $this->context->controller;
        if($controller instanceof ModuleFrontController && isset($controller->module) && $controller->module instanceof PaymentModule) return false;

        return 0;
    }

    public function getOrderShippingCostExternal($params) {
        return $this->getOrderShippingCost($params);
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
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: {$deliverySecret}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($order));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = json_decode(curl_exec($curl), true);

            curl_close($curl);
        } catch (Exception $exception) {
            $this->logger("Curl error syncing order $orderID: " . $exception->getMessage());
        }
    }

    /**
     * The delivery step with Econt is complete only with data from the Econt form whose prices come from the Econt
     * server. PrestaShop calls the hook only for the module of the selected carrier.
     */
    public function hookActionValidateStepComplete($params) {
        if(($params['step_name'] ?? '') !== 'delivery') return;

        $cartData = $this->getCartDeliveryInfo((int)$this->context->cart->id);
        $econtDeliveryInfo = $cartData ? json_decode($cartData, true) : null;
        if(!empty($econtDeliveryInfo['econt_server_prices'])) return;

        $params['completed'] = false;
        $this->context->controller->errors[] = $this->l('Моля, изберете адрес или офис за доставка във формата на Еконт.');
    }

    public function hookActionCarrierUpdate($params) {
        if ($params['carrier']->id_reference == Configuration::get('ECONT_DELIVERY_CARRIER_ID_REF')) {
            Configuration::updateValue('ECONT_DELIVERY_CARRIER_ID', $params['carrier']->id);
        }
    }
    public function hookActionCartUpdateQuantityBefore($params) {
        $this->context->cookie->{"econt_delivery_info-".$params['cart']->id} = null;
    }
    public function hookActionValidateOrder($params) {
        $cartId = (int)$params['cart']->id;
        $cartData = $this->getCartDeliveryInfo($cartId);
        
        if((intval($params['order']->id)) && $cartData && ($econtDeliveryInfo = json_decode($cartData, true))) {
            Db::getInstance()->insert('econt_delivery_order_info',array(
                'id_order' => (int) $params['order']->id,
                'info' => Db::getInstance()->escape(json_encode($econtDeliveryInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
            ),false,false,Db::REPLACE);
        }
    }

    public function hookActionOrderStatusPostUpdate($params) {
        $this->syncOrder($params['id_order']);
    }
    public function hookDisplayCheckoutSummaryTop($params) {
        if($params['smarty'] && round($params['smarty']->tpl_vars['cart']->value['subtotals']['shipping']['amount'],2) == 0) {
            $params['smarty']->tpl_vars['cart']->value['subtotals']['shipping'] = null;
        }
    }
    public function hookDisplayShoppingCart($params) {
        if($params['smarty'] && round($params['smarty']->tpl_vars['cart']->value['subtotals']['shipping']['amount'],2) == 0) {
            $params['smarty']->tpl_vars['cart']->value['subtotals']['shipping'] = null;
        }
    }
    public function hookDisplayCheckoutSubtotalDetails($params) {
        if($params['smarty'] && round($params['smarty']->tpl_vars['cart']->value['subtotals']['shipping']['amount'],2) == 0) {
            $params['smarty']->tpl_vars['cart']->value['subtotals']['shipping'] = null;
        }
    }
    public function hookActionDispatcherBefore($a) {
        // Handle AJAX save request from frontend
        if(isset($_REQUEST['ajax_econt_save']) && isset($_REQUEST['econt_data'])) {
            $cartId = (int)$this->context->cookie->id_cart;
            $econtDeliveryInfo = json_decode($_REQUEST['econt_data'], true);
            if($econtDeliveryInfo && isset($econtDeliveryInfo['shipping_price']) && $cartId) {
                // The prices from the browser are replaced with prices from the Econt server, without the cache: the
                // Econt form keeps its customer info id when the customer changes the address in it.
                // Data that cannot be checked is not saved and does not replace data checked earlier.
                $econtDeliveryInfo = $this->withServerPrices($econtDeliveryInfo, $cartId, true);
                if($econtDeliveryInfo) $this->saveCartDeliveryInfo($cartId, json_encode($econtDeliveryInfo));
                if(isset($_REQUEST['ajax'])) {
                    die(json_encode($econtDeliveryInfo ? ['success' => true, 'price' => $econtDeliveryInfo['shipping_price']] : ['success' => false]));
                }
            }
        }

        // Also check regular POST
        if(!empty($_REQUEST['econt_delivery_info'])) {
            $cartId = (int)$this->context->cookie->id_cart;
            $econtDeliveryInfo = json_decode($_REQUEST['econt_delivery_info'], true);
            if($econtDeliveryInfo && isset($econtDeliveryInfo['shipping_price']) && $cartId) {
                $econtDeliveryInfo = $this->withServerPrices($econtDeliveryInfo, $cartId, true);
                if($econtDeliveryInfo) $this->saveCartDeliveryInfo($cartId, json_encode($econtDeliveryInfo));
            }
        }
        
        if(isset($_REQUEST['controller']) && $_REQUEST['controller'] == 'AdminOrders' && isset($_REQUEST['submitAddressShipping']) && !empty($_REQUEST['id_order']) && !empty($_REQUEST['econt_delivery_customer_info'])) {
            $this->saveAdminOrderDeliveryInfo();
        }
    }

    // Нови данни от формата на Еконт за поръчка от админа (смяна на адреса). Hook-ът се вика и в магазина, и преди
    // контролера, затова тук се проверяват служителят, правото и token-ът на AdminOrders. Цените се взимат от Еконт.
    private function saveAdminOrderDeliveryInfo() {
        $employee = $this->context->employee;
        if (
                !defined('_PS_ADMIN_DIR_')
            ||  !Validate::isLoadedObject($employee) || !$employee->isLoggedBack() || !$employee->can('edit', 'AdminOrders')
            ||  !hash_equals((string)Tools::getAdminTokenLite('AdminOrders'), (string)Tools::getValue('token'))
        ) return;

        $order = new Order((int)$_REQUEST['id_order']);
        if (!Validate::isLoadedObject($order) || $order->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return;

        $econtDeliveryInfo = $this->withServerPrices(json_decode((string)$_REQUEST['econt_delivery_customer_info'], true), $order->id_cart, true);
        if (!$econtDeliveryInfo) return;

        Db::getInstance()->insert('econt_delivery_order_info',array(
            'id_order' => (int)$order->id,
            'info' => Db::getInstance()->escape(json_encode($econtDeliveryInfo)),
        ),false,false,Db::REPLACE);
        $this->syncOrder($order->id);
    }

    public function hookDisplayCarrierExtraContent() {
        $address = new Address(intval($this->context->cart->id_address_delivery));
        if($address->id_country) {
            $countryCode = Country::getIsoById($address->id_country);
        } else {
            $countryCode = $this->context->country->iso_code;
        }

        $secret = explode('@', Configuration::get('ECONT_DELIVERY_SECRET'));
        $secret = @reset($secret);
        $frameParams = array(
            'id_shop' => intval($secret),
            'order_weight' => $this->context->cart->getTotalWeight(),
            'order_total' => $this->context->cart->getOrderTotal(),
            'order_currency' => $this->context->currency->iso_code,
            'customer_company' => $this->context->customer->company,
            'customer_name' => "{$this->context->customer->firstname} {$this->context->customer->lastname}",
            'customer_email' => $this->context->customer->email,
            'customer_phone' =>  $address->phone ? $address->phone : $address->phone_mobile,
            'customer_country' => $countryCode,
            'customer_city_name' => $address->city,
            'customer_post_code' => $address->postcode,
            'customer_address' => $address->address1.' '.$address->address2,
            'ignore_history' => 1
        );

        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $frameURL = $deliveryBaseURL.'/customer_info.php?'.http_build_query($frameParams,null,'&');
        $deliveryMethodPriceCD = $this->l('наложен платеж');

        ob_start();?>
        <iframe id="econt_delivery_customer_info_frame" style="width:100%;height:800px;border:none;margin-top: 15px;" src="<?=$frameURL?>"></iframe>
        <input type="hidden" id="econt_delivery_info" name="econt_delivery_info">
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                let $hiddenInput = $('#econt_delivery_info');
                let shippingInfo = null;
                let $frame = $('#econt_delivery_customer_info_frame');
                let $tRBtn = $('.delivery-options input:radio');
                let $econtRadio = $tRBtn.filter(function(i,el){return parseInt(el.value) == <?php echo Configuration::get('ECONT_DELIVERY_CARRIER_ID')?>;});
                let $confirmBtn = $('.continue[name=confirmDeliveryOption]');
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
                $(window).unbind('message.econtShipping');
                $(window).bind('message.econtShipping', function(e){
                    if(e.originalEvent.origin === <?=json_encode(self::getDeliveryOrigin($deliveryBaseURL))?>) {
                        if(e.originalEvent.data['shipment_error']) {
                            alert(e.originalEvent.data['shipment_error'])
                        } else {
                            shippingInfo = e.originalEvent.data;
                            $frame.parent().hide();
                            $confirmBtn.attr({disabled:false});
                            let labelTxt = shippingInfo['shipping_price'] + shippingInfo['shipping_price_currency_sign'];
                            if(
                                    (shippingInfo['shipping_price'] != shippingInfo['shipping_price_cod'])
                                ||  (shippingInfo['shipping_price'] != shippingInfo['shipping_price_cod_e'])
                            ) {
                                labelTxt += ' (+ ' + (shippingInfo['shipping_price_cod'] - shippingInfo['shipping_price']).toFixed(2) + shippingInfo['shipping_price_currency_sign'] + ' ' + <?php echo json_encode($deliveryMethodPriceCD)?> + ')'
                            }
                            $econtRadio.parents('.delivery-option').find('.carrier-price').text(labelTxt);
                            $hiddenInput.val(JSON.stringify(e.originalEvent.data));
                            
                            $.ajax({
                                url: '/module/econtdelivery/ajax?ajax_function=set_econt_shipping_info',
                                type: 'POST',
                                data: e.originalEvent.data,
                                dataType: 'json',
                            });
                        }
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }
    
    public function setEcontShippingInfo() {
        $json = array();

        if(isset($_POST['shipping_price'])) {
            unset($_POST['ajax_function']);
            $_SESSION['econt_delivery_info-'.$this->context->cookie->id_cart] = json_encode($_POST);
            $this->context->cookie->{"econt_delivery_info-".$this->context->cookie->id_cart} = json_encode($_POST);
            $json['success'] = true;
        }

        echo json_encode($json);
        exit;
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
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
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

        if (empty($response['error'])) {
            if ($order->shipping_number != $response['shipmentNumber']) {
                $order->setWsShippingNumber($response['shipmentNumber']);
                $order->shipping_number = $response['shipmentNumber'];
                $order->update();
            }
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

        // Формата за товарителница се отваря със сесия за поръчката от Еконт, ключът не стига до браузъра (ITDEV-1191)
        $prepareLoadingFrameURL = '';
        $prepareLoadingError = '';
        if (empty($order->shipping_number)) {
            $labelSession = $this->createLabelSession($deliveryBaseURL, $deliverySecret, $order->id);
            if (!empty($labelSession['token'])) {
                $prepareLoadingFrameURL = $deliveryBaseURL . '/create_label.php?' . http_build_query(array('order_number' => $order->id, 'token' => $labelSession['token']));
            } else {
                $prepareLoadingError = $labelSession['error'];
            }
        }
        $customerInfoFrameURL = $deliveryBaseURL.'/customer_info.php?' . http_build_query($customerInfoParams, null, '&');

        ob_start();?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                $(document.body).delegate('a', 'click', function(e) {
                    if (
                            this.href.indexOf('generateDeliverySlipPDF') > 0
                        ||  this.href.indexOf('generate-delivery-slip-pdf') > 0
                    ) {
                        let num = $('.shipping_number_show').text().trim();
                        if (!num) num = <?=json_encode((string)$order->shipping_number)?>;
                        if (num) {
                            window.open(<?=json_encode($response['pdfURL']);?>);
                        } else if (<?=json_encode($prepareLoadingFrameURL)?>) {
                            $('<a>Generate Label</a>').attr('href', <?=json_encode($prepareLoadingFrameURL)?>).appendTo(document.body).fancybox({width:700,height:'100%',type:'iframe',title:''}).trigger('click').remove()
                        } else {
                            alert(<?=json_encode($prepareLoadingError)?>);
                        }
                        e.preventDefault();
                    }
                });

                //$('#addressShipping select[name=id_address]')
                //    .after($('<button class="btn btn-default changeEcontAddress" type="button""><i class="icon-truck"></i> <?//=$this->l('Промяна на данните за доставка с Еконт')?>//</button>'))
                //    .after($('<br>'));
                //
                //$('#addressShipping .justify-content-between .dropdown-menu').append(
                //    $('<a></a').attr({
                //        'href': '#',
                //        'class': 'dropdown-item changeEcontAddress'
                //    }).text('<?//=$this->l('Промяна на данните за доставка с Еконт')?>//')
                //);

                $('.changeEcontAddress').click(function() {
                    $('<a href="<?=$customerInfoFrameURL?>">Change Econt Address</a>').appendTo(document.body).fancybox({width:550,height:630,type:'iframe',title:''}).trigger('click').remove()
                });

                $(window).unbind('message.econtShipping');
                $(window).bind('message.econtShipping', function(e){
                    if(e.originalEvent.origin === <?=json_encode(self::getDeliveryOrigin($deliveryBaseURL))?>) {
                        let messageData = e['originalEvent']['data'];
                        if (!messageData) return;

                        if (messageData['event'] == 'cancel') {
                            $('.fancybox-close').trigger('click');
                        } else if(messageData['event'] == 'confirm') {
                            if (messageData['printPdf'] === true && /^https:\/\//i.test(messageData['shipmentStatus']['pdfURL'] || '')) window.open(messageData['shipmentStatus']['pdfURL'], '_blank');
                            $('.fancybox-close').trigger('click');
                            window.location.href = window.location.href;
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
    // Сесия за товарителница за поръчката (OrdersService.createLabelSession), която create_label.php приема в token вместо ключа
    private function createLabelSession($deliveryBaseURL, $deliverySecret, $orderId) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/OrdersService.createLabelSession.json");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$deliverySecret}"
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
            'orderNumber' => (string)$orderId
        )));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);

        $response = json_decode(curl_exec($curl), true);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (is_string($response) && $response !== '') return array('token' => $response);

        return array('error' => !empty($response['message']) ? $response['message'] : ($curlError ?: 'Unknown error'));
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
        ",false);
        if(empty($orderData) || $orderData['id_carrier'] != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return;
    }

    private function ensureCartTableExists() {
        static $checked = false;
        if(!$checked) {
            $pref = _DB_PREFIX_;
            $engine = _MYSQL_ENGINE_;
            Db::getInstance()->execute("
                CREATE TABLE IF NOT EXISTS {$pref}econt_delivery_cart_info (
                    id_cart INT(10) UNSIGNED NOT NULL,
                    info MEDIUMTEXT NOT NULL,
                    date_add DATETIME NOT NULL,
                    PRIMARY KEY (id_cart)
                ) ENGINE=$engine
            ");
            $checked = true;
        }
    }
    
    /**
     * Puts the shipping prices from the Econt server in the data from the Econt form.
     *
     * The prices in the form data come from the browser, so they are not trusted. The server asks
     * OrdersService.getPrice with the cart total (without shipping), weight and currency. Econt has no getPrice for
     * the EcontPay price, so that one is kept only between the prices without and with cash on delivery
     * (Econt blocks the EcontPay amount with its own price anyway).
     *
     * The Econt form keeps its customer info id when the customer changes the address in it, so the address from the
     * form is in the cache key too, and $fresh asks Econt even if the prices are in the cache.
     *
     * @param array $econtDeliveryInfo data from the Econt form
     * @param int $cartId the cart from the cookie: actionDispatcherBefore runs before the context has the cart
     * @param bool $fresh ask Econt even if the prices are in the cache
     * @return array|null the data with the server prices, or null if the prices cannot be checked
     */
    private function withServerPrices($econtDeliveryInfo, $cartId, $fresh = false) {
        if(!is_array($econtDeliveryInfo) || !is_scalar($econtDeliveryInfo['id'] ?? null) || trim((string)$econtDeliveryInfo['id']) === '') return null;

        $cart = new Cart((int)$cartId);
        if(!Validate::isLoadedObject($cart)) return null;
        $cartCurrency = new Currency((int)$cart->id_currency);

        // getOrderTotal() reads the cart and the currency from the context, which do not have them yet here.
        // They are set only for the calculation and then put back.
        $contextCart = $this->context->cart;
        $contextCurrency = $this->context->currency;
        if(!Validate::isLoadedObject($contextCart)) $this->context->cart = $cart;
        if(!Validate::isLoadedObject($contextCurrency)) $this->context->currency = $cartCurrency;
        $orderTotal = round((float)$cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2);
        $orderWeight = round((float)$cart->getTotalWeight(), 3);
        $this->context->cart = $contextCart;
        $this->context->currency = $contextCurrency;

        $customerInfoId = trim((string)$econtDeliveryInfo['id']);
        $currency = $cartCurrency->iso_code;

        // One request to Econt per address and cart, not on every step of the checkout
        $address = [];
        foreach(['country_code', 'city_name', 'post_code', 'office_code', 'address', 'zip'] as $field) {
            $address[] = is_scalar($econtDeliveryInfo[$field] ?? null) ? (string)$econtDeliveryInfo[$field] : '';
        }
        $cacheKey = md5(implode('|', [$customerInfoId, implode('|', $address), $orderTotal, $orderWeight, $currency]));
        $cached = json_decode((string)$this->context->cookie->econt_delivery_server_prices, true);
        $prices = (!$fresh && is_array($cached) && ($cached['key'] ?? '') === $cacheKey) ? $cached['prices'] : null;

        if(!$prices) {
            $price = $this->getEcontPrice($customerInfoId, $orderTotal, $orderWeight, $currency, false);
            if($price === null) return null;

            // Like the Econt form: an order without a total has no cash on delivery
            $priceCod = $orderTotal > 0 ? $this->getEcontPrice($customerInfoId, $orderTotal, $orderWeight, $currency, true) : $price;
            if($priceCod === null) return null;

            $prices = ['shipping_price' => $price, 'shipping_price_cod' => $priceCod];
            $this->context->cookie->econt_delivery_server_prices = json_encode(['key' => $cacheKey, 'prices' => $prices]);
        }

        $minPrice = min($prices['shipping_price'], $prices['shipping_price_cod']);
        $maxPrice = max($prices['shipping_price'], $prices['shipping_price_cod']);
        $priceCodE = round((float)($econtDeliveryInfo['shipping_price_cod_e'] ?? $maxPrice), 2);

        $econtDeliveryInfo['shipping_price'] = $prices['shipping_price'];
        $econtDeliveryInfo['shipping_price_cod'] = $prices['shipping_price_cod'];
        $econtDeliveryInfo['shipping_price_cod_e'] = min(max($priceCodE, $minPrice), $maxPrice);
        // getOrderShippingCost() and the delivery step take only data with this mark (not data saved before 2.0.4)
        $econtDeliveryInfo['econt_server_prices'] = 1;

        return $econtDeliveryInfo;
    }

    /**
     * Shipping price (receiverDueAmount) from OrdersService.getPrice. Without an order number Econt calculates it
     * on a temporary order and does not save anything.
     *
     * @return float|null null on a connection error or an error from Econt
     */
    private function getEcontPrice($customerInfoId, $orderTotal, $orderWeight, $currency, $cod) {
        if(!($deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT'))) return null;
        if(!($deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET'))) return null;

        $request = [
            'cod' => (bool)$cod,
            'currency' => $currency,
            'customerInfo' => ['id' => $customerInfoId],
            'items' => [[
                'name' => 'Order',
                'count' => 1,
                'totalPrice' => $orderTotal,
                'totalWeight' => $orderWeight
            ]]
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/OrdersService.getPrice.json");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$deliverySecret}"
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($request));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $result = is_string($response) ? json_decode($response, true) : null;

        if($httpCode !== 200 || !isset($result['receiverDueAmount']) || !is_numeric($result['receiverDueAmount'])) {
            $this->logger(sprintf('getPrice failed [%d] %s %s', $httpCode, $curlError, $result['message'] ?? ''));
            return null;
        }

        return round((float)$result['receiverDueAmount'], 2);
    }

    private function saveCartDeliveryInfo($cartId, $data) {
        $this->ensureCartTableExists();
        $pref = _DB_PREFIX_;
        $escapedData = Db::getInstance()->escape($data);
        Db::getInstance()->execute("
            REPLACE INTO {$pref}econt_delivery_cart_info (id_cart, info, date_add) 
            VALUES (".(int)$cartId.", '".$escapedData."', NOW())
        ");
        
        // Clear PrestaShop shipping cache to force recalculation
        Cache::clean('getPackageShippingCost_' . $cartId . '_*');
    }
    
    private function getCartDeliveryInfo($cartId) {
        $this->ensureCartTableExists();
        $pref = _DB_PREFIX_;
        $row = Db::getInstance()->getRow("SELECT info FROM {$pref}econt_delivery_cart_info WHERE id_cart = ".(int)$cartId);
        return $row ? $row['info'] : null;
    }
    
    private function clearCartDeliveryInfo($cartId) {
        $this->ensureCartTableExists();
        $pref = _DB_PREFIX_;
        Db::getInstance()->execute("DELETE FROM {$pref}econt_delivery_cart_info WHERE id_cart = ".(int)$cartId);
    }

    public function logger($message)
    {
        $logger = new FileLogger();
        $dir = version_compare(_PS_VERSION_, '1.7', '<') ? '/log/' : '/app/logs/';
        $dir = version_compare(_PS_VERSION_, '8.0', '>') ? '/var/logs/' : $dir;
        $logger->setFilename(_PS_ROOT_DIR_ . $dir . date('Y-m') . '_econt.log');
        $logger->logInfo(date('H:i:s') . "\n" . $message);
    }
}