<?php

/*
 * Plugin Name:       Gull's CT version selector.
 * Plugin URI:        https://github.com/alexandergull/gvs
 * Description:       Install any version of CleanTalk plugins.
 * Version:           1.0
 * Requires at least: 6.3
 * Requires PHP:      5.6
 * Author:            Alexander Gull
 * Author URI:        https://github.com/alexandergull
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/alexandergull/gvs
 * Text Domain:       gull-gvs
 * Domain Path:       /languages
 */

define('GVS_PLUGIN_DIR', __DIR__);

require_once('inc/gvs_helper.php');
require_once('lib/GVS/GVS.php');
require_once('lib/GVS/GVSPluginDataDTO.php');

if ( empty($_POST) ) {
    add_action('admin_menu', 'gvs_menu_page', 25);
}

add_action('plugins_loaded', 'gvs_main');

/**
 * Init main logic via fluid interface if POST contains GVS signs.
 * @return void
 * @throws Exception
 */
function gvs_main()
{
    try {
        $gvs = new GVS();

        // check if supported plugins installed
        $work_with_plugin = isset($_POST['plugin_inner_name']) ? $_POST['plugin_inner_name'] : null;
        $url = isset($_POST['gvs_select']) ? $_POST['gvs_select'] : null;

        if ( $work_with_plugin && $url ) {

            // set plugin slug as working with
            $gvs->process_plugin = $gvs->plugins_data[$work_with_plugin];

            // run processes
            $gvs->downloadPluginZip($url)
                ->unpackZip()
                ->prepareDirectories()
                ->doBackup()
                ->replaceActivePlugin()
                ->deleteTempFiles()
                ->saveLogToState();

            // add a notice of success
            $gvs->setNotice('Plugin '. $gvs->plugin_version_short_name .' successfully replaced.', 'success');

            // do redirect
            wp_redirect(get_admin_url() . '?page=gvs_page');
            exit;
        }

    } catch ( \Exception $e ) {
        $gvs->writeStreamLog('ERROR: ' . $e->getMessage());
        $gvs->saveLogToState();

        // add a notice of error
        $gvs->setNotice('Error occurred during installation of ' . $gvs->plugin_version_short_name . ':' . $e->getMessage(), 'error');

        // do redirect
        wp_redirect(get_admin_url() . '?page=gvs_page');
        exit;
    }
}

/**
 * @return void
 * @throws Exception
 */
function gvs_construct_settings_page()
{
    $gvs = new GVS();
    // header
    $html = '<h1 style="margin: 15px">CleanTalk plugins versions selector</h1><br>';
    $html .= $gvs->getNoticeLayout();
    // detect supported plugins and build forms for each of them
    $supported_plugins = $gvs->detectSupportedPlugins();
    foreach ( $supported_plugins as $plugin_inner_name => $status ) {
        if ( $status === 'active' ) {
            $html .= $gvs->getDownloadInterfaceForm($plugin_inner_name);
        }
    }

    $html .= $gvs->getLogLayout();
    $html .= $gvs->getSupportLayout();

    echo $html;
}

/**
 * Init menu link and page.
 * @return void
 */
function gvs_menu_page()
{
    add_menu_page(
        'Gull\'s versions selector',
        'CleanTalk versions',
        'manage_options',
        'gvs_page',
        'gvs_construct_settings_page',
        'dashicons-images-alt2',
        20
    );
}
