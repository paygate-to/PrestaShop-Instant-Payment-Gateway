<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0: delete the debug logs that earlier versions wrote inside the public module folder.
 * Logs now go to PrestaShop's protected var/logs folder.
 */
function upgrade_module_1_1_0($module)
{
    return $module->removeLegacyLogs();
}
