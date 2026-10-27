<?php

/**
 * Get the current version of the module.
 * @return bool|string
 */

function getVersionModule()
{
    return file_get_contents(plugin_dir_path(dirname(__FILE__)) . "/version.txt");
}

/**
 * Check if the current version is the last version
 * @return bool
 */
function isLastVersion()
{
    $versionModule = getVersionModule();
    $versionOnline = file_get_contents("https://axonaut.com/modules/woocommerce/version.php");
    if (strcmp(preg_replace( "/\r|\n/", "", $versionOnline), $versionModule) === 0) { //same version
        axonaut_write_log("[ Update Manager ] The Axonaut module is up to date (version : " . getVersionModule() . ").");
        return true;
    }
    return false;
}

/**
 * Create HTML component of notify a new update available.
 */
function notificationUpdate()
{
    $latestVersion = file_get_contents("https://axonaut.com/modules/woocommerce/version.php");
    axonaut_write_log("[ Update Manager ] The Axonaut module NOT up to date (current : " . getVersionModule() . ", latest : " . $latestVersion . ").");
    echo ('<div class="notice notice-warning is-dismissible">
                <p><b>' . __("Une nouvelle version du module Axonaut est disponible", "axonaut") . "(version '.$latestVersion.')".'
                <br><a href="https://axonaut.com/modules/woocommerce/download.php">' . __('Veuillez la télécharger en cliquant ici', 'axonaut') . '</a></b></p>
            </div>');
}

/**
 * Function use by GUI for show notification bar if a new version of the app is available.
 */
function checkUpdate()
{
    if (!isLastVersion())
        notificationUpdate();
}

?>