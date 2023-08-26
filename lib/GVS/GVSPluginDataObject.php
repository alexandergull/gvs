<?php

class GVSPluginDataObject
{
    /**
     * Plugin name for internal usage.
     * @var string
     */
    public $inner_name;
    /**
     * Plugin slug as it presented in the WP filesystem.
     * @var string
     */
    public $plugin_slug;
    /**
     * Regex to find URLs in the WordPress API response.
     * @var string
     */
    public $wp_api_response_search_regex;
    public $github_owner;
    public $github_slug;
    /**
     * Custom GitHub links to download.
     * @var string
     */
    public $github_branches_credentials;
    /**
     * ZIP file name should be used after downloading.
     * @var string
     */
    public $zip_name;
    /**
     * Path where to keep unzipped files.
     * @var string
     */
    public $new_version_folder_directory;
    /**
     * Path where to keep zip file.
     * @var string
     */
    public $new_version_zip_directory;
    /**
     * Global GVS temp directory.
     * @var string
     */
    public $temp_directory;
    /**
     * Path where installed plugin can be found.
     * @var string
     */
    public $active_plugin_directory;
    /**
     * Path where do backup of installed plugin.
     * @var string
     */
    public $backup_plugin_directory;
    /**
     * Full zip path after download.
     * @var string
     */
    public $zip_path;
    /**
     * Path to extract downloaded zip.
     * @var string
     */
    public $new_version_dir;

    public function __construct($inner_name,
                                $plugin_slug,
                                $search_regex,
                                $zip_name,
                                $temp_directory,
                                $github_links,
                                $github_branches_credentials)
    {
        $this->inner_name = $inner_name;
        $this->plugin_slug = $plugin_slug;
        $this->wp_api_response_search_regex = $search_regex;
        $this->zip_name = $zip_name;
        $this->active_plugin_directory = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
        $this->temp_directory = str_replace('\\', '/', $temp_directory) . '/gvs/' . $this->plugin_slug;
        $this->backup_plugin_directory = $this->temp_directory . '/backup';
        $this->new_version_folder_directory = $this->temp_directory . '/new_version';
        $this->new_version_zip_directory = $this->temp_directory . '/zip';
        $this->zip_path = $this->new_version_zip_directory . '/' . $this->zip_name;
        $this->github_links = $github_links;
        $this->github_owner = $github_branches_credentials[0];
        $this->github_slug = $github_branches_credentials[1];
    }

}
