<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.0.4: the delivery step with Econt needs data with prices from the Econt server (actionValidateStepComplete)
 *
 * @param EcontDelivery $module
 * @return bool
 */
function upgrade_module_2_0_4($module) {
    return $module->registerHook('actionValidateStepComplete');
}
