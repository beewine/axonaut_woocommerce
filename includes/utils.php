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