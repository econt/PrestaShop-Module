<?php

class EcontdeliveryAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        // Prevent PrestaShop from displaying the default template
        $this->ajax = true;
        parent::initContent();
    }

    public function displayAjax()
    {
        // Get the ajax function parameter
        $ajax_function = Tools::getValue('ajax_function');
        
        // Route to the appropriate method
        if (method_exists($this, $ajax_function)) {
            $this->ajaxRender($this->$ajax_function());
        } else {
            $this->ajaxRender(json_encode([
                'success' => false,
                'error' => 'Function not found'
            ]));
        }
    }

    public function set_econt_shipping_info()
    {
        $json = array();

        if(isset($_POST['shipping_price'])) {
            unset($_POST['ajax_function']);
            $_SESSION['econt_delivery_info-'.$this->context->cookie->id_cart] = json_encode($_POST);
            $json['success'] = true;
        }
        
        return (json_encode([
            'success' => true,
            'message' => 'Shipping info saved!'
        ]));
    }
}