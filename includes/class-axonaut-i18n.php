<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 *
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */
class Axonaut_i18n
{
    /**
     * Load the plugin text domain for translation.
     *
     * @since    1.0.0
     */
    public static function load_plugin_textdomain()
    {
        load_plugin_textdomain('axonaut', false,'axonaut/languages');
    }
}
