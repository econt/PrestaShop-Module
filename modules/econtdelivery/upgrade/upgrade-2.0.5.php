<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.0.5:
 *  - when Econt does not allow cash on delivery for the order (e.g. above the legal limit for cash payments), cash on
 *    delivery and EcontPay are not offered (actionPresentPaymentOptions) and the payment step says why
 *    (displayPaymentTop)
 *  - EcontPay (modules/econtdeliverypayment) is a copy that the upgrade of this module does not change,
 *    so the copy is refreshed here if EcontPay is in the shop
 *
 * @param EcontDelivery $module
 * @return bool
 */
function upgrade_module_2_0_5($module) {
    if (!$module->registerHook('actionPresentPaymentOptions')) return false;
    if (!$module->registerHook('displayPaymentTop')) return false;

    if (is_dir(_PS_MODULE_DIR_ . 'econtdeliverypayment')) {
        if (!$module->copyPaymentModuleFiles()) return false;
        Module::upgradeModuleVersion('econtdeliverypayment', $module->version);
    }

    return true;
}
