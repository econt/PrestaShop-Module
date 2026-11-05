<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.0.4:
 *  - the delivery step with Econt needs data with prices from the Econt server (actionValidateStepComplete)
 *  - EcontPay (modules/econtdeliverypayment) is a copy that the upgrade of this module does not change,
 *    so the copy is refreshed here if EcontPay is in the shop
 *
 * @param EcontDelivery $module
 * @return bool
 */
function upgrade_module_2_0_4($module) {
    if (!$module->registerHook('actionValidateStepComplete')) return false;

    if (is_dir(_PS_MODULE_DIR_ . 'econtdeliverypayment')) {
        if (!$module->copyPaymentModuleFiles()) return false;
        Module::upgradeModuleVersion('econtdeliverypayment', $module->version);
    }

    return true;
}
