<?php

/**
 * The utils file
 *
 * This is used to store useful functions
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

function f($var)
{
    if (empty($var)) {
        return "";
    }
    return $var;
}

/**
 * Get the default structure for http request
 * @param $body : body of the request
 * @return array : structure
 */
function get_default_request_args($body)
{
    return array(
        'body' => $body,
        'timeout' => '5',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => array(),
        'cookies' => array()
    );
}
/**
 * Get the current version of the module.
 * @return string
 */
function getVersionModule()
{
    $file = plugin_dir_path(dirname(__FILE__)) . "version.txt";
    return file_exists($file) ? trim(file_get_contents($file)) : "";
}

/**
 * Secret partagé du webhook de stock. Généré à la première utilisation.
 * @return string
 */
function axonaut_get_webhook_secret()
{
    $secret = get_option('axonaut_webhook_secret');
    if (empty($secret)) {
        $secret = wp_generate_password(40, false);
        update_option('axonaut_webhook_secret', $secret, false);
    }
    return $secret;
}
