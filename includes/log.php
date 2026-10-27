<?php

/**
 * The log file
 *
 * This is used to store logs
 * require define('WP_DEBUG_DISPLAY', true); in wp_config.php
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

const AXONAUT_LOG_INFO = "INFO";
const AXONAUT_LOG_WARNING = "WARNING";
const AXONAUT_LOG_ERROR = "ERROR";

/**
 * Write in logfile, a new log entry
 * @param $str : new entry
 */
function axonaut_write_log($str, string $level = AXONAUT_LOG_INFO)
{
    $str='';
    $date = new DateTime();
    $date = $date->format("d/m/Y H:i:s");
    error_log("[ $date ] $level : $str\n", 3, plugin_dir_path(__FILE__) . "log.txt");
}

/**
 * Read logs entries
 * @return bool|string : log content
 */
function read_log()
{
    return file_get_contents(plugin_dir_path(dirname(__FILE__)) . "/log.txt");
}