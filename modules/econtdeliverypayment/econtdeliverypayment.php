<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EcontDeliveryPayment extends PaymentModule {

    private $hooks = array(
        'paymentOptions'
    );

    public function __construct() {
        $this->name = 'econtdeliverypayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.228'; // change from zip generator
        $this->ps_versions_compliancy = array(
            'min' => '1.7',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;
        $this->author = 'ITPartner';
        $this->controllers = array('validation');

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('EcontPay');
        $this->description = $this->l('Допълнение към модула "Достави с Еконт".');

        $this->confirmUninstall = $this->l('Сигурни ли сте, че искате да премахнете модула?');
    }

    public function install() {
        if (!parent::install()) return false;

        foreach ($this->hooks as $hook) if (!$this->registerHook($hook)) return false;

        return true;
    }
    public function uninstall() {
        if (!parent::uninstall()) return false;

        foreach ($this->hooks as $hook) if (!$this->unregisterHook($hook)) return false;

        return true;
    }

    public function logEvent($event) {
        $event['plugin_type'] = 'prestashop';
        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/PluginsService.logEvent.json");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$deliverySecret}"
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([$event]));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        curl_exec($curl);
        curl_close($curl);
    }

    public function disable($force_all = false) {
        $this->logEvent(['action' => 'payment_enabled', 'value' => '0']);
        return parent::disable($force_all);
    }
    public function enable($force_all = false) {
        $this->logEvent(['action' => 'payment_enabled', 'value' => '1']);
        return parent::enable($force_all);
    }

    public function hookPaymentOptions($params) {
        /** @var Cart $cart */
        $cart = $params['cart'];

        if (!$this->active || $cart->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return null;

        $currency = new Currency($cart->id_currency);
        if ($currency->iso_code != 'BGN') return null;

        $paymentOption = new PaymentOption();
        $paymentOption->setModuleName($this->name);
        $paymentOption->setCallToActionText($this->l(Configuration::get('ECONT_DELIVERY_PAYMENT_TITLE')));
        $paymentOption->setAction($this->context->link->getModuleLink($this->name, 'validation'));
        ob_start() ;?>
            <section id="econt_delivery_payment-paymentOptions-additionalInformation">
                <p><?php echo $this->trans('Плати онлайн с EcontPay', [], 'EcontDelivery.Payment'); ?></p>
            </section>
        <?php $additionalInformation = ob_get_contents();
        ob_end_clean();
        $paymentOption->setAdditionalInformation($additionalInformation);

        return array($paymentOption);
    }

    public static function setOrderCurrentState($order, $state) {
        $orderHistory = new OrderHistory();
        $orderHistory->id_order = (int) $order->id;
        $orderHistory->changeIdOrderState((int) $state, $order, true);
        return $orderHistory->addWithemail();
    }

}
