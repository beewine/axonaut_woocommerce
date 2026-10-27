<?php

/**
 * The front-end class.
 *
 * This is used to print elements to user in configuration page
 * public-facing site hooks.
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */
class AxonautDisplay
{
    /**
     * Create HTML switch
     * @param $name: name of the component
     * @return string: HTML Ccode
     */
    public static function create_switch($name)
    {
        return ' <label class="switch" for="' . $name . '">
		<input name="' . $name . '" id="' . $name . '" type="checkbox"' .
            (esc_attr(get_option($name)) == "on" ? ' checked="checked"' : '') . '/>' .
            '<span class="slider round"></span></label>';
    }

    /**
     * Create HTML switch
     * @param $name: name of the component
     * @param $type: type of the component
     * @param $def : default value of the component
     * @return string : HTML code
     */
    public static function create_input_id($name, $type, $def)
    {
        return ' <label for="' . $name . '">
		<input class="axo_id" name="' . $name . '" id="' . $name . '" type="' . $type . '" value="' .
            $def . '"></label>';
    }
}