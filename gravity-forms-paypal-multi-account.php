<?php
/**
 * Plugin Name: Custom PayPal Multi-Account Connector for GF
 * Description: Same as original description.
 * Version: 1.43.0
 * Author: Aliyan Faisal
 * Author URI: https://aliyanfaisal.com
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-gfp-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-gfp-core.php';

class GFP_Multi_Account
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        new GFP_Settings();
        new GFP_Core();
    }
}

add_action('plugins_loaded', array('GFP_Multi_Account', 'get_instance'));
