<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EcontDeliveryPayment extends PaymentModule {

    private $hooks = array(
        'paymentOptions',
        'orderConfirmation'
    );

    public function __construct() {
        $this->name = 'econtdeliverypayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.2'; // change from zip generator
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

        $this->displayName = $this->l('Гарантирано от Еконт');
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

    private function orderConfirmationMessage($message = 'Възникна грешка', $dontTranslate = false) {
        $message = "Гарантирано от Еконт: {$message}";
        return '<p class="text-danger">' . ($dontTranslate ? $message : $this->l($message)) . '</p>';
    }

    public function hookPaymentOptions($params) {
        /** @var Cart $cart */
        $cart = $params['cart'];

        if (!$this->active || $cart->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return null;

        $currency = new Currency($cart->id_currency);
        if ($currency->iso_code != 'BGN') return null;

        $paymentOption = new PaymentOption();

        $paymentOption->setCallToActionText($this->l(Configuration::get('ECONT_DELIVERY_PAYMENT_TITLE')));
        $paymentOption->setAction($this->context->link->getModuleLink($this->name, 'validation', array()));
        ob_start() ;?>
            <?php $paymentLogo = trim(Configuration::get('ECONT_DELIVERY_PAYMENT_LOGO')); ?>
            <?php if (!empty($paymentLogo)): ?>
                <?php $econtDelivery = new EcontDelivery(); ?>
                <img src="<?php echo rtrim($this->context->link->getBaseLink(), '/')  . $econtDelivery->getPathUri() . "view/img/econt_payment_logo_{$paymentLogo}.png"; ?>" alt="Econt Delivery Payment Logo" style="margin-bottom: 15px;">
            <?php endif; ?>
            <p><?php echo $this->l(Configuration::get('ECONT_DELIVERY_PAYMENT_DESCRIPTION')); ?></p>
        <?php $additionalInformation = ob_get_contents();
        ob_end_clean();
        $paymentOption->setAdditionalInformation($additionalInformation);

        return array($paymentOption);
    }
    public function hookOrderConfirmation($params) {
        /** @var Order $order */
        $order = $params['order'];

        if (intval($order->id) <= 0 || $order->module !== 'econtdeliverypayment') return null;

        if (!$this->active || $order->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) return $this->orderConfirmationMessage('Улугата не е активна');

        $currency = new Currency($order->id_currency);
        if ($currency->iso_code != 'BGN') return $this->orderConfirmationMessage('Невалидна валута');

        $customer = new Customer($order->id_customer);
        if (!Validate::isLoadedObject($customer)) return $this->orderConfirmationMessage('Невалиден купувач');

        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET');
        if(!$deliveryBaseURL || !$deliverySecret) return $this->orderConfirmationMessage('Невалидна конфигурация');

        if (@$_GET['econtdeliverypayment_hash'] !== EcontDelivery::getProtectionHash(array(
            'id_order' => (string)@$order->id,
            'econtdeliverypayment_transaction_id' => (string)@$_GET['econtdeliverypayment_transaction_id'],
            'econtdeliverypayment_time' => (string)@$_GET['econtdeliverypayment_time']
        ))) return $this->orderConfirmationMessage('Невалидна трансакция');

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/PaymentsService.confirmPayment.json");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$deliverySecret}"
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
            'paymentIdentifier' => $_GET['econtdeliverypayment_transaction_id']
        )));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        $response = json_decode(curl_exec($curl), true);
        $responseInfo = curl_getinfo($curl);

        if ($responseInfo['http_code'] !== 200) $response['error'] = $response;
        curl_close($curl);

        if (!empty($response['error'])) {
            if (EcontDelivery::updateOrderStatus($order, $this->context->language->id)) $this->setOrderCurrentState($order, Configuration::get('PS_OS_ERROR'));
            return $this->orderConfirmationMessage($response['message'], true);
        } elseif (empty($response['paymentToken'])) {
            if (EcontDelivery::updateOrderStatus($order, $this->context->language->id)) $this->setOrderCurrentState($order, Configuration::get('PS_OS_ERROR'));
            return $this->orderConfirmationMessage('Възникна проблем при приключване на плащане');
        }

        if (EcontDelivery::updateOrderStatus($order, $this->context->language->id)) $this->orderConfirmationMessage('Не е възможно да се промени статуса на плащането');

        $this->setOrderCurrentState($order, Configuration::get('PS_OS_PAYMENT'));
        $econtDelivery = new EcontDelivery();
        $econtDelivery->syncOrder($order->id, $response['paymentToken']);
        $this->setOrderCurrentState($order, Configuration::get('PS_OS_PREPARATION'));
    }

    public static function setOrderCurrentState($order, $state) {
        $orderHistory = new OrderHistory();
        $orderHistory->id_order = (int) $order->id;
        $orderHistory->changeIdOrderState((int) $state, $order, true);
        return $orderHistory->addWithemail();
    }

}
