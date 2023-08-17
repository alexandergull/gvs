<?php

class GVS
{
    public $plugins_data = array();

    public $process_plugin = GVSPluginDataDTO::class;

    private $log;

    private $log_path = GVS_PLUGIN_DIR . '\gvs.log';

    public function __construct()
    {
        $this->plugins_data['apbct'] = new GVSPluginDataDTO(
            'apbct',
            'cleantalk-spam-protect',
            '/https:\/\/downloads\.wordpress\.org\/plugin\/cleantalk-spam-protect\.6\..*?zip/',
            'apbct-packed.zip',
            wp_get_upload_dir()['path']
        );

        $this->plugins_data['spbct'] = new GVSPluginDataDTO(
            'spbct',
            'security-malware-firewall',
            '/https:\/\/downloads\.wordpress\.org\/plugin\/security-malware-firewall\.2\..*?zip/',
            'spbct-packed.zip',
            wp_get_upload_dir()['path']
        );
    }

    public function detectSupportedPlugins()
    {
        $output = array();
        foreach ( $this->plugins_data as $plugin_datum ) {
            if ( is_dir($plugin_datum->active_plugin_directory) ) {
                $output[$plugin_datum->inner_name] = 'active';
            } else {
                $output[$plugin_datum->inner_name] = 'inactive';
            }
        }
        return !empty($output) ? $output : false;
    }

    public function getDownloadInterfaceForm($plugin_inner_name)
    {
        $versions = $this->getVersionsList($plugin_inner_name);
        $html = file_get_contents(GVS_PLUGIN_DIR . '/templates/gvs_form.html');
        $options = '';
        foreach ( $versions as $version ) {
            $options .= "<option value='$version'>$version</option>";
        }
        $html = str_replace('%GVS_OPTIONS%', $options, $html);
        $html = str_replace('%PLUGIN_INNER_NAME%', $plugin_inner_name, $html);
        $html = str_replace('%PLUGIN_INNER_NAME_UPPER%', strtoupper($plugin_inner_name), $html);

        return $html;
    }

    public function getLogLayout()
    {
        $html = file_get_contents(GVS_PLUGIN_DIR . '/templates/gvs_log_layout.html');
        if (!$html) {
            return 'LOG_FILE_TEMPLATE_READ_ERROR';
        } else {
            $content = $this->getLastLogContent();
            if (empty($content)) {
                $content = 'No log persists yet.';
            }
            $html = str_replace('%LOG_CONTENT%', $content, $html);
        }

        return $html;
    }

    private function getVersionsList($plugin_inner_name)
    {
        $this->writeLog("Api response proceed..");

        $wp_api_response = @file_get_contents("https://api.wordpress.org/plugins/info/1.0/" . $this->plugins_data[$plugin_inner_name]->plugin_slug);

        if ( empty($wp_api_response) ) {
            throw new \Exception('Empty API response');
        }

        $this->writeLog("Api response successfully got.");

        $this->writeLog("Seek for versions..");

        preg_match_all($this->plugins_data[$plugin_inner_name]->search_regex, $wp_api_response, $versions_found);

        if ( empty($versions_found) ) {
            throw new \Exception('No versions found');
        }

        $versions_found = $versions_found[0];

        return $versions_found;
    }

    public function downloadPluginZip($url)
    {
        $output_path = $this->process_plugin->new_version_zip_directory;

        $versions = $this->getVersionsList($this->process_plugin->inner_name);

        if ( !in_array($url, $versions) ) {
            throw new \Exception('This URL is not allowed.');
        }

        $this->writeLog("Downloading content of $url to $output_path ...");

        if ( !is_dir($output_path) ) {
            $result = mkdir($output_path, 0777, true);
            if ( !$result ) {
                throw new \Exception('Can not create temp folder.');
            }
        }

        $version_content = file_get_contents($url);

        if ( empty($version_content) ) {
            throw new \Exception('Cannot get url content.');
        }

        $this->writeLog("Writing " . $this->process_plugin->zip_path . "...");

        $result = file_put_contents($this->process_plugin->zip_path, $version_content);

        if ( empty($result) ) {
            throw new \Exception('Cannot write file.');
        }

        return $this;
    }

    public function unpackZip()
    {
        if ( !is_dir($this->process_plugin->new_version_folder_directory) ) {
            $result = mkdir($this->process_plugin->new_version_folder_directory, 0777, true);
            if ( !$result ) {
                throw new \Exception('Invalid temp dir path');
            }
        }

        $this->writeLog("Unpacking " . $this->process_plugin->zip_path . " ...");

        // init zip
        $zip = new ZipArchive();
        // open
        $zip->open($this->process_plugin->zip_path);

        // collect the main folder name in zip
        $plugin_folder_name = $zip->getNameIndex(0);
        $plugin_folder_name = substr($plugin_folder_name, 0, strlen($plugin_folder_name) - 1);
        $new_version_dir = $this->process_plugin->new_version_folder_directory . '/' . $plugin_folder_name;

        // do extract
        $zip->extractTo($this->process_plugin->new_version_folder_directory);
        // close
        $zip->close();

        if ( !is_dir($new_version_dir) ) {
            throw new \Exception('Invalid completed temp path. Roll back..');
        }

        $this->writeLog("Unpacking success: $new_version_dir");

        $this->process_plugin->new_version_dir = $new_version_dir;

        return $this;
    }

    public function prepareDirectories()
    {
        // delete if backup path already persists
        if ( is_dir($this->process_plugin->backup_plugin_directory) ) {
            gvs_delete_folder_recursive($this->process_plugin->backup_plugin_directory);
        }

        // check if active plugin directory exists
        if ( !is_dir($this->process_plugin->active_plugin_directory) ) {
            throw new \Exception('Invalid active plugin path');
        }

        // prepare filesystem
        if ( !gvs_prepare_filesystem() ) {
            throw new \Exception('Can not init WordPress filesystem.');
        }

        return $this;
    }

    public function doBackup()
    {
        $result = copy_dir($this->process_plugin->active_plugin_directory, $this->process_plugin->backup_plugin_directory);
        if ( !$result ) {
            throw new \Exception('Can not backup active plugin.');
        }
        return $this;
    }

    public function replaceActivePlugin()
    {
        // enable maintenance mode
        $this->writeLog('Enabling maintenance mode..');
        gvs_maintenance_mode__enable(120);

        // remove active plugin
        gvs_delete_folder_recursive($this->process_plugin->active_plugin_directory);

        // replace active plugin
        $this->writeLog('Replacing active plugin ' . $this->process_plugin->active_plugin_directory);
        $result = copy_dir($this->process_plugin->new_version_dir, $this->process_plugin->active_plugin_directory);
        if ( !$result ) {
            $this->writeLog('Disabling maintenance mode..');
            gvs_maintenance_mode__disable();
            throw new \Exception('Can not replace active plugin.');
        }

        $this->writeLog('Disabling maintenance mode..');
        gvs_maintenance_mode__disable();
        $this->writeLog('Replaced successfully.');
        return $this;
    }

    public function deleteTempFiles()
    {
        $this->writeLog("Delete temp files " . $this->process_plugin->temp_directory . " ...");

        gvs_delete_folder_recursive($this->process_plugin->temp_directory);

        $this->writeLog("Deleting temp files success.");
        $this->writeLog("All done.");

        return $this;
    }

    /**
     * @param $msg
     * @return void
     */
    public function writeLog($msg)
    {
        $this->log[] = current_time('Y-m-d H:i:s') . " " . $msg;
    }

    /**
     * @param $type
     * @return string
     */
    public function readLogAs($type = 'array') {
        if (!empty($this->log)) {
            if ($type === 'array') {
                return $this->log;
            } elseif ($type === 'string') {
                return implode("\n", $this->log);
            }
        }
        return '';
    }

    /**
     * @return false|int
     */
    public function saveLog() {
        $this->writeLog("Save log..");
        return @file_put_contents($this->log_path, $this->readLogAs('string'));
    }

    public function getLastLogContent(){
        if (is_file($this->log_path)){
            $content = file_get_contents($this->log_path);
            if (!empty($content)) {
                $html = '<ul class="ul-disc">';
                $log_array = explode("\n",$content);
                foreach ($log_array as $row) {
                    $p = "<li>$row</li>";
                    $html .= $p;
                }
                $html .= '</ul>';
                return ($html);
            }
        }
        return '';
    }
}
