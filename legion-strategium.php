<?php
/**
 * Plugin Name: The Legion Strategium
 * Description: Edition-aware army building and battle companion foundations.
 * Version: 0.3.0
 * Requires PHP: 8.1
 * Text Domain: legion-strategium
 */
if (!defined('ABSPATH')) { exit; }
define('LS_VERSION', '0.3.0');
define('LS_PATH', plugin_dir_path(__FILE__));
define('LS_URL', plugin_dir_url(__FILE__));
require_once LS_PATH . 'includes/class-ls-schema.php';
require_once LS_PATH . 'includes/class-ls-accounts.php';
require_once LS_PATH . 'includes/class-ls-plugin.php';
register_activation_hook(__FILE__, array('LS_Schema', 'activate'));
add_action('plugins_loaded', array('LS_Plugin', 'boot'));
