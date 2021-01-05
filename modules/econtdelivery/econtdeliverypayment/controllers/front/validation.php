<?php /** @noinspection PhpUnused */

class EcontDeliveryPaymentValidationModuleFrontController extends ModuleFrontController {

    private function redirectWithMessage($message = 'Възникна грешка', $dontTranslate = false) {
        $message = "Гарантирано с Еконт: {$message}";
        $this->errors[] = ($dontTranslate ? $message : $this->l($message));
        $this->redirectWithNotifications(__PS_BASE_URI__ . '?' . http_build_query(array( 'controller' => 'order')));
    }

    public function postProcess() {
        /** @var EcontDeliveryPayment $module */
        $module = $this->module;
        $cart = $this->context->cart;

        if (!$module->active || $cart->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) $this->redirectWithMessage('Улугата не е активна');

        $currency = new Currency($cart->id_currency);
        if ($currency->iso_code != 'BGN') $this->redirectWithMessage('Невалидна валута');

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) $this->redirectWithMessage('Невалиден купувач');

        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET');
        if(!$deliveryBaseURL || !$deliverySecret) $this->redirectWithMessage('Невалидна конфигурация');

        $module->validateOrder($cart->id, Configuration::get('PS_OS_BANKWIRE'), $this->context->cart->getOrderTotal(), $module->displayName, null, null, $currency->id, false, $customer->secure_key);
        if (!$module->currentOrder) $this->redirectWithMessage('Нвалиден номер на поръчка');

        $order = new Order($module->currentOrder);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/PaymentsService.createPayment.json");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$deliverySecret}"
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
            'order' => array(
                'orderNumber' => $order->id
            )
        )));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        $response = json_decode(curl_exec($curl), true);
        $responseInfo = curl_getinfo($curl);

        if ($responseInfo['http_code'] !== 200) $response['error'] = $response;
        curl_close($curl);

        if (!empty($response['error'])) {
            $this->redirectWithMessage($response['message'], true);
        } elseif (empty($response['paymentURI']) || empty($response['paymentIdentifier'])) {
            if (EcontDelivery::updateOrderStatus($order, $this->context->language->id)) $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
            $this->redirectWithMessage('Възникна проблем при генериране на плащане');
        }

        if (!EcontDelivery::updateOrderStatus($order, $this->context->language->id)) $this->redirectWithMessage('Възникна проблем при при добавяне на плащане');

        $order->addOrderPayment(floatval($cart->getOrderTotal()), $module->displayName, $response['paymentIdentifier'], $currency);

        $parsetPaymentURI = parse_url($response['paymentURI']);
        $parsetPaymentURIParams = array();
        parse_str($parsetPaymentURI['query'], $parsetPaymentURIParams);
        $currentTimestamp = time();
        $responseURL = _PS_BASE_URL_ . '/?' . http_build_query(array(
            'controller' => 'order-confirmation',
            'id_cart' => $cart->id,
            'id_module' => $this->module->id,
            'id_order' => $module->currentOrder,
            'key' => $customer->secure_key,
            'econtdeliverypayment_transaction_id' => $response['paymentIdentifier'],
            'econtdeliverypayment_time' => time(),
            'econtdeliverypayment_hash' => EcontDelivery::getProtectionHash(array(
                'id_order' => (string)$order->id,
                'econtdeliverypayment_transaction_id' => (string)$response['paymentIdentifier'],
                'econtdeliverypayment_time' => (string)$currentTimestamp
            ))
        ));
        header(sprintf('Location: %s', str_replace($parsetPaymentURI['query'], http_build_query(array_merge($parsetPaymentURIParams, array(
            'successUrl' => $responseURL,
            'failUrl' => $responseURL,
            'eMail' => $customer->email
        ))), $response['paymentURI'])));

        exit;
    }

}
