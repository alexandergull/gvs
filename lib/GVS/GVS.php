<?php

class GVS
{
    /**
     * @var array
     */
    public $plugins_data = array();

    /**
     * @var GVSPluginDataDTO
     */
    public $process_plugin;

    /**
     * @var array
     */
    private $log;

    /**
     * @var string
     */
    private $state_file = GVS_PLUGIN_DIR . '\files\state.gvs';

    /**
     * @var
     */
    public $plugin_version_short_name;

    public function __construct()
    {
        $this->plugins_data['apbct'] = new GVSPluginDataDTO(
            'apbct',
            'cleantalk-spam-protect',
            '/https:\/\/downloads\.wordpress\.org\/plugin\/cleantalk-spam-protect\.6\..*?zip/',
            'apbct-packed.zip',
            wp_get_upload_dir()['path'],
            array(
                'https://github.com/CleanTalk/wordpress-antispam/releases/download/dev-version/cleantalk-spam-protect.zip',
                'https://github.com/CleanTalk/wordpress-antispam/releases/download/fix-version/cleantalk-spam-protect.zip'
            )
        );

        $this->plugins_data['spbct'] = new GVSPluginDataDTO(
            'spbct',
            'security-malware-firewall',
            '/https:\/\/downloads\.wordpress\.org\/plugin\/security-malware-firewall\.2\..*?zip/',
            'spbct-packed.zip',
            wp_get_upload_dir()['path'],
            array(
                'https://github.com/CleanTalk/security-malware-firewall/releases/download/dev-version/security-malware-firewall.zip',
                'https://github.com/CleanTalk/security-malware-firewall/releases/download/fix-version/security-malware-firewall.zip'
            )
        );
    }

    /**
     * ==========
     * State actions logic.
     * ==========
     */


    /**
     * @param $key
     * @return false|mixed
     * @throws Exception
     */
    public function readStateFile($key)
    {
        if (is_file($this->state_file)) {
            $content = @file_get_contents($this->state_file);
            if (false === $content) {
                throw new \Exception('Can not read content of state file on path: ' . $this->state_file);
            }
            $state = (array)unserialize(file_get_contents($this->state_file));
            return is_array($state) && !empty($state[$key]) ? $state[$key] : false;
        }
        throw new \Exception('State file missed on path: ' . $this->state_file);
    }

    /**
     * @param $key
     * @param $value
     * @return void
     * @throws Exception
     */
    public function writeStateFile($key, $value)
    {
        if (is_file($this->state_file)) {
            $state = (array)unserialize(file_get_contents($this->state_file));
        } else {
            $state = array();
        }
        $state[$key] = $value;
        $buffer = serialize($state);
        $result = @file_put_contents($this->state_file, $buffer);
        if (!$result) {
            throw new \Exception('Can not write state file on path: ' . $this->state_file);
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function saveLogToState() {
        $this->writeStreamLog("Save log..");
        $log = !empty($this->log) ? $this->log : '';
        $this->writeStateFile('log', $log);
    }


    /**
     * @param $text
     * @param $type
     * @return void
     * @throws Exception
     */
    public function setNotice($text, $type)
    {
        $this->writeStateFile('notice', array('text' => $text, 'type' => $type));
    }

    /**
     * @param $msg
     * @return void
     */
    public function writeStreamLog($msg)
    {
        $this->log[] = current_time('Y-m-d H:i:s') . " " . $msg;
    }

    /**
     * ==========
     * Main logic.
     * ==========
     */

    /**
     * @return array|false
     */
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

    private function getVersionsList($plugin_inner_name)
    {
        $this->writeStreamLog("Api response proceed..");

        $wp_api_response = @file_get_contents("https://api.wordpress.org/plugins/info/1.0/" . $this->plugins_data[$plugin_inner_name]->plugin_slug);

        if ( empty($wp_api_response) ) {
            throw new \Exception('Empty API response');
        }

        $this->writeStreamLog("Api response successfully got.");

        $this->writeStreamLog("Seek for versions..");

        preg_match_all($this->plugins_data[$plugin_inner_name]->wp_api_response_search_regex, $wp_api_response, $versions_found);


        if ( empty($versions_found) ) {
            throw new \Exception('No versions found');
        }

        $versions_found = $versions_found[0];

        array_unshift($versions_found, $this->plugins_data[$plugin_inner_name]->github_links[0], $this->plugins_data[$plugin_inner_name]->github_links[1]);

        $versions_found = array_unique($versions_found);

        $this->writeStateFile('links_for_' . $plugin_inner_name, $versions_found);

        return $versions_found;
    }

    public function replacePlugin($url){
        // run processes
        $this->downloadPluginZip($url)
            ->unpackZip()
            ->prepareDirectories('rewrite')
            ->doBackup()
            ->replacePluginFiles()
            ->deleteTempFiles()
            ->saveLogToState();

        // add a notice of success
        $this->setNotice('Plugin '. $this->plugin_version_short_name .' successfully replaced.', 'success');
    }

    public function installPlugin($url){
        // run processes
        $this->downloadPluginZip($url)
            ->unpackZip()
            ->prepareDirectories('install')
            ->setPluginFiles()
            ->deleteTempFiles()
            ->saveLogToState();

        // add a notice of success
        $this->setNotice('Plugin '. $this->plugin_version_short_name .' successfully installed.', 'success');
    }

    public function downloadPluginZip($url)
    {
        $output_path = $this->process_plugin->new_version_zip_directory;

        $versions = $this->readStateFile('links_for_' . $this->process_plugin->inner_name);

        if ( !in_array($url, $versions) ) {
            throw new \Exception('This URL is not allowed: ' . $url);
        }

        $this->plugin_version_short_name = gvs_get_plugin_version_short_name($url, $this->process_plugin);

        $this->writeStreamLog("Downloading content of $url to $output_path ...");

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

        $this->writeStreamLog("Writing " . $this->process_plugin->zip_path . "...");

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

        $this->writeStreamLog("Unpacking " . $this->process_plugin->zip_path . " ...");

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

        $this->writeStreamLog("Check directory: $new_version_dir");

        // exclusions for github zips
        if ( !is_dir($new_version_dir) ) {
            $new_version_dir = $this->process_plugin->new_version_folder_directory;
            if ( !is_dir($new_version_dir) ) {
                throw new \Exception('Invalid completed temp path ' . $new_version_dir . '. Roll back..');
            }
        }

        $this->writeStreamLog("Unpacking success: $new_version_dir");

        $this->process_plugin->new_version_dir = $new_version_dir;

        return $this;
    }

    public function prepareDirectories($mode)
    {
        // delete if backup path already persists
        if ($mode === 'rewrite' && is_dir($this->process_plugin->backup_plugin_directory) ) {
            gvs_delete_folder_recursive($this->process_plugin->backup_plugin_directory);
        }

        // check if active plugin directory exists
        if ($mode === 'rewrite' && !is_dir($this->process_plugin->active_plugin_directory) ) {
            throw new \Exception('Invalid active plugin path');
        }

        if ($mode === 'install' && !is_dir($this->process_plugin->active_plugin_directory) ) {
            $result = mkdir($this->process_plugin->active_plugin_directory);
            if (!$result) {
                throw new \Exception('Can not create plugin folder.');
            }
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

    public function replacePluginFiles()
    {
        // enable maintenance mode
        $this->writeStreamLog('Enabling maintenance mode..');
        gvs_maintenance_mode__enable(120);

        // remove active plugin
        gvs_delete_folder_recursive($this->process_plugin->active_plugin_directory);

        // replace active plugin
        $this->writeStreamLog('Rewrite active plugin files ' . $this->process_plugin->active_plugin_directory);
        $result = copy_dir($this->process_plugin->new_version_dir, $this->process_plugin->active_plugin_directory);
        if ( !$result ) {
            $this->writeStreamLog('Disabling maintenance mode..');
            gvs_maintenance_mode__disable();
            throw new \Exception('Can not replace active plugin.');
        }

        $this->writeStreamLog('Disabling maintenance mode..');
        gvs_maintenance_mode__disable();
        $this->writeStreamLog('Rewrote successfully.');
        return $this;
    }

    public function setPluginFiles()
    {
        // replace active plugin
        $this->writeStreamLog('Install active plugin ' . $this->process_plugin->active_plugin_directory);
        $result = copy_dir($this->process_plugin->new_version_dir, $this->process_plugin->active_plugin_directory);
        if ( !$result ) {
            throw new \Exception('Can not install active plugin.');
        }

        $this->writeStreamLog('Installed successfully.');
        return $this;
    }

    public function deleteTempFiles()
    {
        $this->writeStreamLog("Delete temp files " . $this->process_plugin->temp_directory . " ...");

        gvs_delete_folder_recursive($this->process_plugin->temp_directory);

        $this->writeStreamLog("Deleting temp files success.");
        $this->writeStreamLog("All done.");

        return $this;
    }

    /**
     * ==========
     * Interface logic.
     * ==========
     */

    /**
     * Build plugins forms.
     * @param $plugin_inner_name
     * @return array|false|string|string[]
     * @throws Exception
     */
    public function getDownloadInterfaceForm($plugin_inner_name, $action)
    {
        $versions = $this->getVersionsList($plugin_inner_name);
        $html = file_get_contents(GVS_PLUGIN_DIR . '/templates/gvs_form.html');
        $options = '';
        $attention = '';
        foreach ( $versions as $version ) {
            $options .= '<option value="'. esc_url($version) . '">' . esc_url($version) . '</option>';
        }
        $html = str_replace('%GVS_OPTIONS%', $options, $html);
        $html = str_replace('%PLUGIN_INNER_NAME%', $plugin_inner_name, $html);
        $html = str_replace('%PLUGIN_INNER_NAME_UPPER%', strtoupper($plugin_inner_name), $html);
        if ($action === 'rewrite') {
            $plugin_action = 'Rewrite';
            $attention = 'WARNING: This action will delete all non-plugin files like .git or .idea. Be sure you know what do you do.';
            $plugin_meta = 'Plugin persists in file system, files will be rewrote: ' . $this->plugins_data[$plugin_inner_name]->active_plugin_directory;
        } elseif ($action === 'install') {
            $plugin_action = 'Install';
            $plugin_meta = 'Plugin does not persist in file system, files will be placed to: ' . $this->plugins_data[$plugin_inner_name]->active_plugin_directory;
        }
        if (!empty($plugin_meta)) {
            $html = str_replace('%PLUGIN_META%', $plugin_meta, $html);
            $html = str_replace('%PLUGIN_ACTION%', $plugin_action, $html);
        }
        $html = str_replace('%ATTENTION%', $attention, $html);

        return $html;
    }

    /**
     * Build logs block layout.
     * @return array|false|string|string[]
     * @throws Exception
     */
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

    /**
     * Get stream content and wrap this in HTML
     * @return string
     * @throws Exception
     */
    private function getLastLogContent(){
        if (is_file($this->state_file)){
            $log_content = $this->readStateFile('log');
            if (!empty($log_content)) {
                $html = '<ul class="ul-disc">';
                foreach ($log_content as $row) {
                    $p = "<li>" . esc_html($row) . "</li>";
                    $html .= $p;
                }
                $html .= '</ul>';
                return ($html);
            }
        }
        return '';
    }

    /**
     * Build notice block layout
     * @return array|string|string[]
     * @throws Exception
     */
    public function getNoticeLayout()
    {
        $html = '';
        $notice = $this->readStateFile('notice');
        if (!empty($notice) && isset($notice['text'], $notice['type'])) {
            if ($notice['type'] === 'error') {
                $color = 'red';
            }
            if ($notice['type'] === 'success') {
                $color = 'green';
            }
            $p = '<p style="margin: 15px"><b>' . esc_html($notice['text']) . '</b></p>';
            $html = file_get_contents(GVS_PLUGIN_DIR . '/templates/gvs_notice.html');
            $html = str_replace('%NOTICE_CONTENT%', $p, $html);
            $html = str_replace('%NOTICE_COLOR%', $color, $html);
        }
        // delete notice after first show
        $this->writeStateFile('notice','');
        return $html;
    }

    /**
     * Build support block layout
     * @return string
     */
    public function getSupportLayout()
    {
        $html = '<div style="border-style: groove; margin: 15px; max-width: 60%">';
        $html .= '<div id="gvs_support_wrap" style="margin: 15px">';
        $html .= '<p><b>Needs help?</b></p>';
        $html .= '<ul class="ul-square">';
        $html .= '<li>TG: @alexthegull</li>';
        $html .= '<li>mailto: alex.g@cleantalk.org</li>';
        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }
}
