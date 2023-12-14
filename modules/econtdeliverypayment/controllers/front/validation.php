<?php /** @noinspection PhpUnused */

class EcontDeliveryPaymentValidationModuleFrontController extends ModuleFrontController {

    private function redirectWithMessage($message = null) {
        $message = $message ?: $this->trans('Въникна грешка', [], 'EcontDelivery.Payment');
        $this->errors[] = "EcontPay: {$message}";
        $this->redirectWithNotifications($this->context->link->getPageLink('order'));

        exit;
    }

    public function postProcess() {
        /** @var EcontDeliveryPayment $module */
        $module = $this->module;
        $cart = $this->context->cart;

        if (!$module->active || $cart->id_carrier != Configuration::get('ECONT_DELIVERY_CARRIER_ID')) $this->redirectWithMessage($this->trans('Улугата не е активна', [], 'EcontDelivery.Payment'));

        $deliveryBaseURL = Configuration::get('ECONT_DELIVERY_ENDPOINT');
        $deliverySecret = Configuration::get('ECONT_DELIVERY_SECRET');
        if (!$deliveryBaseURL || !$deliverySecret) $this->redirectWithMessage($this->trans('Невалидна конфигурация', [], 'EcontDelivery.Payment'));

        $currency = new Currency($cart->id_currency);
        if ($currency->iso_code != 'BGN') $this->redirectWithMessage($this->trans('Невалидна валута', [], 'EcontDelivery.Payment'));

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) $this->redirectWithMessage($this->trans('Невалиден купувач', [], 'EcontDelivery.Payment'));

        $address = new Address($cart->id_address_delivery);
        $customerInfoId = EcontDelivery::storage('customersInfo')[$cart->id][EcontDelivery::pregMatchCustomerInfoId($address->other)]['id'];
        if (empty($customerInfoId)) $this->redirectWithMessage($this->trans('Необходимо е да валидирате адреса за доставка %s', [
            '<script>setTimeout(function() { document.getElementById("checkout-delivery-step").click(); }, 500)</script>'
        ], 'EcontDelivery.Payment'));

        if (intval($_REQUEST['identifier']) <= 0) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "{$deliveryBaseURL}/services/PaymentsService.createPayment.json");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json', "Authorization: {$deliverySecret}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
                'order' => array(
                    'customerInfo' => [
                        'id' => $customerInfoId
                    ],
                    'currency' => $currency->iso_code,
                    'cod' => 1,
                    'items' => [[
                        'name' => 'fake item',
                        'count' => 1,
                        'totalPrice' => round($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2),
                        'totalWeight' => floatval($cart->getTotalWeight())
                    ]]
                ))
            ));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = json_decode(curl_exec($curl), true);
            $responseInfo = curl_getinfo($curl);

            if ($responseInfo['http_code'] !== 200) $response['error'] = $response;
            curl_close($curl);

            if (!empty($response['error'])) $this->redirectWithMessage($response['message']); elseif (empty($response['paymentURI']) || empty($response['paymentIdentifier'])) $this->redirectWithMessage($this->trans('Възникна проблем при генериране на плащане', [], 'EcontDelivery.Payment'));

            $customersInfo = EcontDelivery::storage('customersInfo');
            $customersInfo[$cart->id][$customerInfoId]['payment_service']['create_payment_response'] = $response;
            EcontDelivery::storage('customersInfo', $customersInfo);

            $parsetPaymentURI = parse_url($response['paymentURI']);
            $parsetPaymentURIParams = array();
            parse_str($parsetPaymentURI['query'], $parsetPaymentURIParams);
            $params = [
                'id_customer_info' => (string)$customerInfoId,
                'identifier' => (string)$response['paymentIdentifier'],
                'time' => (string)time()
            ];
            $responseURL = $this->context->link->getModuleLink($module->name, 'validation', array_merge($params, ['hash' => EcontDelivery::getProtectionHash($params)]));
            header(sprintf('Location: %s', str_replace($parsetPaymentURI['query'], http_build_query(array_merge($parsetPaymentURIParams, array(
                'successUrl' => $responseURL,
                'failUrl' => $responseURL,
                'eMail' => $customersInfo[$cart->id][$customerInfoId]['email'] ?: $customer->email
            ))), $response['paymentURI'])));

            exit;
        }

        if (@$_REQUEST['hash'] !== EcontDelivery::getProtectionHash([
            'id_customer_info' => (string)$customerInfoId,
            'identifier' => ($identifier = (string)$_REQUEST['identifier']),
            'time' => (string)$_REQUEST['time']
        ])) $this->redirectWithMessage($this->trans('Неуспешно плащане', [], 'EcontDelivery.Payment'));

        $customerInfo = EcontDelivery::storage('customersInfo')[$cart->id][$customerInfoId];
        if (empty($customerInfo) || $customerInfo['payment_service']['create_payment_response']['paymentIdentifier'] != $identifier) $this->redirectWithMessage($this->trans('Не са намерени данни за плащането', [], 'EcontDelivery.Payment'));

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
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
            'paymentIdentifier' => $identifier
        ]));
        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
        $response = json_decode(curl_exec($curl), true);
        $responseInfo = curl_getinfo($curl);

        if ($responseInfo['http_code'] !== 200) $response['error'] = $response;
        curl_close($curl);

        if (!empty($response['error'])) $this->redirectWithMessage($response['message']);
        elseif (empty($response['paymentToken'])) $this->redirectWithMessage($this->trans('Възникна проблем при приключване на плащане', [], 'EcontDelivery.Payment'));

        $module->validateOrder($cart->id, Configuration::get('PS_OS_BANKWIRE'), $this->context->cart->getOrderTotal(), $module->displayName, null, null, $currency->id, false, $customer->secure_key);
        if (!$module->currentOrder) $this->redirectWithMessage($this->trans('Нвалиден номер на поръчка', [], 'EcontDelivery.Payment'));

        $order = new Order($module->currentOrder);
        if (!EcontDelivery::updateOrderStatus($order, $this->context->language->id)) $this->redirectWithMessage($this->trans('Възникна проблем при при добавяне на плащане', [], 'EcontDelivery.Payment'));
        $order->addOrderPayment(floatval($cart->getOrderTotal()), $module->displayName, $identifier, $currency);

        $econtDeliveryPayment = new EcontDeliveryPayment();
        $econtDeliveryPayment->setOrderCurrentState($order, Configuration::get('PS_OS_PAYMENT'));
        $econtDelivery = new EcontDelivery();
        $econtDelivery->syncOrder($order->id, $response['paymentToken']);
        $econtDeliveryPayment->setOrderCurrentState($order, Configuration::get('PS_OS_PREPARATION'));

        Tools::redirect($this->context->link->getPageLink('order-confirmation', null, null, [
            'id_cart' => $cart->id,
            'id_module' => $this->module->id,
            'id_order' => $module->currentOrder,
            'key' => $customer->secure_key
        ]));
    }

}
