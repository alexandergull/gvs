<?php

function gvs_delete_folder_recursive($path)
{
    if (is_dir($path) === true)
    {
        $files = array_diff(scandir($path), array('.', '..'));

        foreach ($files as $file)
        {
            gvs_delete_folder_recursive(realpath($path) . '/' . $file);
        }

        return @rmdir($path);
    }

    else if (is_file($path) === true)
    {
        return @unlink($path);
    }

    return false;
}

/**
 * Putting WordPress to maintenance mode.
 * For given duration in seconds
 *
 * @param $duration
 *
 * @return bool
 */
function gvs_maintenance_mode__enable($duration)
{
    gvs_maintenance_mode__disable();
    $content = "<?php\n\n"
        . '$upgrading = ' . (time() - (60 * 10) + $duration) . ';';

    return (bool)file_put_contents(ABSPATH . '.maintenance', $content);
}

/**
 * Disabling maintenance mode by deleting .maintenance file.
 *
 * @return void
 */
function gvs_maintenance_mode__disable()
{
    $maintenance_file = ABSPATH . '.maintenance';
    if ( file_exists($maintenance_file) ) {
        unlink($maintenance_file);
    }
}

function gvs_prepare_filesystem()
{
    global $wp_filesystem;

    try {
        if( ! $wp_filesystem ){
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
    } catch (\Exception $e) {
        return false;
    }

    if (!function_exists('copy_dir')) {
        return false;
    }

    return true;
}

function gvs_get_plugin_version_short_name($url, $process_plugin)
{
    $regex_github = '/download\/([a-z,0-9].*)\/' . $process_plugin->plugin_slug . '/';
    preg_match_all('/plugin\/([a-z,0-9].*\.zip)/', $url,$matches_wp);
    preg_match_all($regex_github, $url,$matches_github);

    if (isset($matches_wp[1],$matches_wp[1][0])) {
        $short = $matches_wp[1][0];
    }
    if (isset($matches_github[1],$matches_github[1][0])) {
        $short = $process_plugin->inner_name . '-' .  $matches_github[1][0];
    }

    return !empty($short) ? $short : $process_plugin->inner_name;
}
