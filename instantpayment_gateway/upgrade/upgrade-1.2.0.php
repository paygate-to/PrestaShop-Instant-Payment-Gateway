<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.2.0: new orders wait in an "Awaiting card payment" state (instead of the cheque state),
 * callbacks must carry PayGate's signature, and the fees tolerance is a setting (default 40%,
 * the fixed value earlier versions used).
 */
function upgrade_module_1_2_0($module)
{
    if (!Configuration::hasKey('INP_TOLERANCE')) {
        Configuration::updateValue('INP_TOLERANCE', '0.60');
    }
    return $module->installOrderState();
}
