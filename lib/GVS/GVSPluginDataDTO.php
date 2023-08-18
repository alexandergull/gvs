<?php

class GVSPluginDataDTO
{
    public $inner_name;
    public $plugin_slug;
    public $wp_api_response_search_regex;
    public $github_links;
    public $zip_name;
    public $new_version_folder_directory;
    public $new_version_zip_directory;
    public $temp_directory;
    public $active_plugin_directory;
    public $backup_plugin_directory;
    public $zip_path;
    public $new_version_dir;

    public function __construct($inner_name,
                                $plugin_slug,
                                $search_regex,
                                $zip_name,
                                $temp_directory,
                                $github_links)
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
    }

}
