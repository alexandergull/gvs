<?php

use WpOrg\Requests\Transport\Curl;

class GVS
{
    /**
     * Array of GVSPluginDataObject. Keep here handling plugins data.
     * @var array
     */
    public $plugins_data = array();
    /**
     * Selected plugin GVSPluginDataObject
     * @var GVSPluginDataObject
     */
    public $selected_plugin;
    /**
     * Log of process operations.
     * @var array
     */
    private $log_limit = 25;
    /**
     * Log of process operations.
     * @var array
     */
    private $stream_log;
    /**
     * Path to GVS state file.
     * @var string
     */
    private $state_file = GVS_PLUGIN_DIR . '/files/state.gvs';
    /**
     * Name of newly installed plugin used to show in interface.
     * @var string
     */
    public $plugin_version_short_name;

    public function __construct()
    {
        // init plugins data with GVSPluginDataObject instances

        $this->plugins_data['apbct'] = new GVSPluginDataObject(
            'apbct',
            'cleantalk-spam-protect',
            '/https:\/\/downloads\.wordpress\.org\/plugin\/cleantalk-spam-protect\.6\..*?zip/',
            'apbct-packed.zip',
            wp_get_upload_dir()['path'],
            array(
                'https://github.com/CleanTalk/wordpress-antispam/releases/download/dev-version/cleantalk-spam-protect.zip',
                'https://github.com/CleanTalk/wordpress-antispam/releases/download/fix-version/cleantalk-spam-protect.zip'
            ),
            array(
                'CleanTalk',
                'wordpress-antispam'
            )
        );

        $this->plugins_data['spbct'] = new GVSPluginDataObject(
            'spbct',
            'security-malware-firewall',
            '/https:\/\/downloads\.wordpress\.org\/plugin\/security-malware-firewall\.2\..*?zip/',
            'spbct-packed.zip',
            wp_get_upload_dir()['path'],
            array(
                'https://github.com/CleanTalk/security-malware-firewall/releases/download/dev-version/security-malware-firewall.zip',
                'https://github.com/CleanTalk/security-malware-firewall/releases/download/fix-version/security-malware-firewall.zip'
            ),
            array(
                'CleanTalk',
                'security-malware-firewall'
            )
        );
    }

    /**
     * =====================
     * ### STATE ACTIONS ###
     * =====================
     */

    /**
     * Read the state file key.
     * @param $key
     * @return false|mixed
     * @throws Exception
     */
    public function readStateFileKey($key)
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
     * Write the state file key.
     * @param $key
     * @param $value
     * @return void
     * @throws Exception
     */
    public function writeStateFileKey($key, $value, $add_value_to_array = false)
    {
        if (is_file($this->state_file)) {
            $state = (array)unserialize(file_get_contents($this->state_file));
        } else {
            $state = array();
        }

        if ($add_value_to_array) {
            if (!isset($state[$key]) || !is_array($state[$key])) {
                $state[$key] = [];
            }
            if (!is_array($value)) {
                $state[$key][] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $row) {
                    $state[$key][] = $row;
                }
            }
        } else {
            $state[$key] = $value;
        }
        $buffer = serialize($state);
        $result = @file_put_contents($this->state_file, $buffer);
        if (!$result) {
            throw new \Exception('Can not write state file on path: ' . $this->state_file);
        }
    }

    /**
     * Save process log to the state key.
     * @return void
     * @throws Exception
     */
    public function saveLogToState() {
        $this->writeStreamLog("Save process log..");
        $log = !empty($this->stream_log) ? $this->stream_log : [];
        $this->writeStateFileKey('log', $log, true);
    }

    /**
     * Set notice to show in interface.
     * @param $text
     * @param $type
     * @return void
     * @throws Exception
     */
    public function setNotice($text, $type)
    {
        $this->writeStateFileKey('notice', array('text' => $text, 'type' => $type));
    }

    /**
     * Write stream log single record.
     * @param $record
     * @return void
     */
    public function writeStreamLog($record)
    {
        $this->stream_log[] = current_time('Y-m-d H:i:s') . " " . $record;
    }

    /**
     * ==================
     * ### MAIN LOGIC ###
     * ==================
     */

    /**
     * Check filesystem to know if plugins is presented.
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

    /**
     * Get URLs of versions can be downloaded. Check WP API and custom GitHub links.
     * @param $plugin_inner_name
     * @return string[]
     * @throws Exception
     */
    private function getVersionsList($plugin_inner_name)
    {

        //GitHub branches
        $this->writeStreamLog($plugin_inner_name . ": Github API request..");
        $url = "https://api.github.com/repos/"
            . $this->plugins_data[$plugin_inner_name]->github_owner . '/'
            . $this->plugins_data[$plugin_inner_name]->github_slug . '/branches';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent:GVS 1.2.1'
        ]);
        $data = curl_exec($ch);
        curl_close($ch);

        $github_api_response = json_decode($data,ARRAY_A);

        if ( empty($github_api_response) ) {
            throw new \Exception('Empty GitHub API response');
        }

        $this->writeStreamLog($plugin_inner_name . ": Seek for GitHub branches..");


        $github_api_branches_names = array_map(function ($key) {
            return isset($key['name']) && !in_array($key['name'], array('dev','fix', 'master')) ? $key['name'] : null;
        }, $github_api_response);

        $github_api_branches_names = array_filter($github_api_branches_names, function ($key) {
            return $key;
        });

        $github_branches_links = [];

        foreach ($github_api_branches_names as $branch_name) {
            $download_github_zip_url = "https://github.com/"
                . $this->plugins_data[$plugin_inner_name]->github_owner . '/'
                . $this->plugins_data[$plugin_inner_name]->github_slug . '/archive/refs/heads/'
                . $branch_name
                . '.zip';
            $github_branches_links[] = $download_github_zip_url;
        }

        $this->writeStreamLog($plugin_inner_name . ": Github branches added.");

        // add WP links
        $this->writeStreamLog($plugin_inner_name . ": WP API request..");
        $github_api_response = @file_get_contents("https://api.wordpress.org/plugins/info/1.0/" . $this->plugins_data[$plugin_inner_name]->plugin_slug);
        if ( empty($github_api_response) ) {
            throw new \Exception('Empty WP API response');
        }
        $this->writeStreamLog($plugin_inner_name . ": Seek for WP versions..");
        preg_match_all($this->plugins_data[$plugin_inner_name]->wp_api_response_search_regex, $github_api_response, $versions_found);

        if ( empty($versions_found) ) {
            throw new \Exception($plugin_inner_name . ": No WP versions found");
        }
        $versions_found = $versions_found[0];
        $this->writeStreamLog($plugin_inner_name . ": WP versions added.");

        // add github links
        foreach ($github_branches_links as $link) {
            array_unshift($versions_found, $link);
        }
        foreach ($this->plugins_data[$plugin_inner_name]->github_links as $link) {
            array_unshift($versions_found, $link);
        }
        $this->writeStreamLog($plugin_inner_name . ": GitHub versions added.");

        $versions_found = array_unique($versions_found);

        // save urls list to speed up further check
        $this->writeStateFileKey('links_for_' . $plugin_inner_name, $versions_found);

        return $versions_found;
    }

    /**
     * Liquid interface. Replace already installed plugin.
     * @param $url
     * @return void
     * @throws Exception
     */
    public function replacePlugin($url){
        // run processes
        $this->downloadPluginZip($url)
            ->unpackZip()
            ->prepareDirectories('rewrite')
            ->doBackup()
            ->rewritePluginFiles()
            ->deleteTempFiles()
            ->saveLogToState();

        // add a notice of success
        $this->setNotice('Plugin '. $this->plugin_version_short_name .' successfully replaced.', 'success');
    }

    /**
     * Liquid interface. Install new plugin instance.
     * @param $url
     * @return void
     * @throws Exception
     */
    public function installPlugin($url){
        // run processes
        $this->downloadPluginZip($url)
            ->unpackZip()
            ->prepareDirectories('install')
            ->createPluginFiles()
            ->deleteTempFiles()
            ->saveLogToState();

        // add a notice of success
        $this->setNotice('Plugin '. $this->plugin_version_short_name .' successfully installed.', 'success');
    }

    /**
     * Download plugin zip file.
     * @param $url string URL to get zip content
     * @return $this
     * @throws Exception
     */
    public function downloadPluginZip($url)
    {
        $output_path = $this->selected_plugin->new_version_zip_directory;

        $versions = $this->readStateFileKey('links_for_' . $this->selected_plugin->inner_name);

        if ( !in_array($url, $versions) ) {
            throw new \Exception('This URL is not allowed: ' . $url);
        }

        $this->plugin_version_short_name = gvs_get_plugin_version_short_name($url, $this->selected_plugin);

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

        $this->writeStreamLog("Writing " . $this->selected_plugin->zip_path . "...");

        $result = file_put_contents($this->selected_plugin->zip_path, $version_content);

        if ( empty($result) ) {
            throw new \Exception('Cannot write file.');
        }

        return $this;
    }

    /**
     * Unpack downloaded zip file.
     * @return $this
     * @throws Exception
     */
    public function unpackZip()
    {
        if ( !is_dir($this->selected_plugin->new_version_folder_directory) ) {
            $result = mkdir($this->selected_plugin->new_version_folder_directory, 0777, true);
            if ( !$result ) {
                throw new \Exception('Invalid temp dir path');
            }
        }

        $this->writeStreamLog("Unpacking " . $this->selected_plugin->zip_path . " ...");

        // init zip
        $zip = new ZipArchive();
        // open
        $zip->open($this->selected_plugin->zip_path);

        // collect the main folder name in zip
        $plugin_folder_name = $zip->getNameIndex(0);
        $plugin_folder_name = substr($plugin_folder_name, 0, strlen($plugin_folder_name) - 1);
        $new_version_dir = $this->selected_plugin->new_version_folder_directory . '/' . $plugin_folder_name;

        // do extract
        $zip->extractTo($this->selected_plugin->new_version_folder_directory);
        // close
        $zip->close();

        $this->writeStreamLog("Check directory: $new_version_dir");

        // exclusions for GitHub zips
        if ( !is_dir($new_version_dir) ) {
            $new_version_dir = $this->selected_plugin->new_version_folder_directory;
            if ( !is_dir($new_version_dir) ) {
                //todo Implement rollback
                throw new \Exception('Invalid completed temp path ' . $new_version_dir . '. Roll back..');
            }
        }

        $this->writeStreamLog("Unpacking success: $new_version_dir");

        $this->selected_plugin->new_version_dir = $new_version_dir;

        return $this;
    }

    /**
     * Check and prepare directories for further work with files.
     * @param $mode string 'rewrite' or 'install'
     * @return $this
     * @throws Exception
     */
    public function prepareDirectories($mode)
    {
        // delete if backup path already persists
        if ($mode === 'rewrite' && is_dir($this->selected_plugin->backup_plugin_directory) ) {
            gvs_delete_folder_recursive($this->selected_plugin->backup_plugin_directory);
        }

        // check if active plugin directory exists
        if ($mode === 'rewrite' && !is_dir($this->selected_plugin->active_plugin_directory) ) {
            throw new \Exception('Invalid active plugin path');
        }

        if ($mode === 'install' && !is_dir($this->selected_plugin->active_plugin_directory) ) {
            $result = mkdir($this->selected_plugin->active_plugin_directory);
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

    /**
     * Do backup of current installed files.
     * @return $this
     * @throws Exception
     */
    public function doBackup()
    {
        $result = copy_dir($this->selected_plugin->active_plugin_directory, $this->selected_plugin->backup_plugin_directory);
        if ( !$result ) {
            throw new \Exception('Can not backup active plugin.');
        }
        return $this;
    }

    /**
     * Rewrite existing plugin folder with downloaded version.
     * @return $this
     * @throws Exception
     */
    public function rewritePluginFiles()
    {
        // enable maintenance mode
        $this->writeStreamLog('Enabling maintenance mode..');
        gvs_maintenance_mode__enable(120);

        // remove active plugin
        gvs_delete_folder_recursive($this->selected_plugin->active_plugin_directory);

        // replace active plugin
        $this->writeStreamLog('Rewrite active plugin files ' . $this->selected_plugin->active_plugin_directory);
        $result = copy_dir($this->selected_plugin->new_version_dir, $this->selected_plugin->active_plugin_directory);
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

    /**
     * Create files of downloaded version. Use this if no current version installed.
     * @return $this
     * @throws Exception
     */
    public function createPluginFiles()
    {
        // replace active plugin
        $this->writeStreamLog('Install active plugin ' . $this->selected_plugin->active_plugin_directory);
        $result = copy_dir($this->selected_plugin->new_version_dir, $this->selected_plugin->active_plugin_directory);
        if ( !$result ) {
            throw new \Exception('Can not install active plugin.');
        }

        $this->writeStreamLog('Installed successfully.');
        return $this;
    }

    /**
     * Clear all temp files.
     * @return $this
     */
    public function deleteTempFiles()
    {
        $this->writeStreamLog("Delete temp files " . $this->selected_plugin->temp_directory . " ...");

        gvs_delete_folder_recursive($this->selected_plugin->temp_directory);

        $this->writeStreamLog("Deleting temp files success.");
        $this->writeStreamLog("All done.");

        return $this;
    }

    /**
     * =================
     * ### INTERFACE ###
     * =================
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
            $html = str_replace('%LOG_LIMIT%', $this->log_limit, $html);
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
            $log_content = $this->readStateFileKey('log');
            $log_content = array_reverse($log_content);
            if (!empty($log_content)) {
                $i = 0;
                $html = '<ul class="ul-disc">';
                foreach ($log_content as $row) {
                    if ($i === $this->log_limit) {
                        break;
                    }
                    $p = "<li>" . esc_html($row) . "</li>";
                    $html .= $p;
                    $i++;
                }
                $html .= '</ul>';
                $this->trimLogs();
                return ($html);
            }
        }
        return '';
    }

    private function trimLogs()
    {
        $log_content = $this->readStateFileKey('log');
        $log_content = array_slice($log_content, 0, $this->log_limit);

        $this->writeStateFileKey('log', $log_content);
    }

    /**
     * Build notice block layout
     * @return array|string|string[]
     * @throws Exception
     */
    public function getNoticeLayout()
    {
        $html = '';
        $notice = $this->readStateFileKey('notice');
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
        $this->writeStateFileKey('notice','');
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
