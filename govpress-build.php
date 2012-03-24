<?php
/*
Plugin Name: GovPress Build Script
Description:  Builds a single zip from all plugins on the currated plugin list and a zip of a WordPress install with those plugins plus the GovFresh theme
Version: 1.0
Author: Benjamin J. Balter
Author URI: http://ben.balter.com
License: GPL3
*/

/*  GovPress Build Script
 *
 *  Once a day (or on demand) builds a single zip from all plugins on the currated plugin list
 *  and a zip of a WordPress install with those plugins plus the GovFresh theme.
 *  Grabs the latest stable version of each plugin, the theme, and WordPress core.
 *
 *  Copyright (C) 2012  Benjamin J. Balter  ( ben@balter.com -- http://ben.balter.com )
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *  @copyright 2012
 *  @license GPL v3
 *  @version 1.0
 *  @package GovPress_Build_Script
 *  @author Benjamin J. Balter <ben@balter.com>
 */

class GovPress_Build {

	public $plugin_api = 'http://api.wordpress.org/plugins/info/1.0/';
	public $theme_url  = 'https://github.com/govfresh/GovFresh-WP/zipball/master';
	public $core_url   = 'http://wordpress.org/latest.zip';
	public $name       = 'GovPress';
	public $plugins    = array();
	public $upgrade_folder;

	/**
	 * Register Hooks with WordPress API
	 */
	function __construct() {

		add_action( 'govpress_daily_build', array( &$this, 'build' ) );
		add_action( 'admin_init', array( &$this, 'check_for_manual_call' ) );
		register_activation_hook( __FILE__, array( &$this, 'schedule' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'unschedule' ) );

	}


	/**
	 * Schedule script to rebuild zips daily via WordPress Cron on activation
	 */
	function schedule() {
		wp_schedule_event( time(), 'daily', 'govpress_daily_build' );
	}


	/**
	 * Clear daily build cron job on deactivation
	 */
	function unschedule() {
		wp_clear_schedule_hook( 'govpress_daily_build' );
	}


	/**
	 * Allows zips to be dynamically built by a manual call
	 * add ?govpress_build=true to any admin URL to fire
	 * Must be an admin
	 */
	function check_for_manual_call() {

		if ( !current_user_can( 'manage_options' ) )
			return;

		if ( !isset( $_GET['govpress_build'] ) )
			return;

		$this->build();

	}


	/**
	 * Load necessary build classes
	 */
	function init() {

		global $wp_filesystem;
		ini_set( 'max_execution_time', 0);

		if ( !function_exists( 'download_url' ) )
			require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();
		$this->upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';

		$this->cleanup();

		$this->parse_plugin_file();

	}


	/**
	 * Clear contents of upgrade (working) directory
	 */
	function cleanup() {

		global $wp_filesystem;
		//Clean up contents of upgrade directory beforehand.
		$upgrade_files = $wp_filesystem->dirlist( $this->upgrade_folder );
		if ( !empty( $upgrade_files ) ) {
			foreach ( $upgrade_files as $file )
				$wp_filesystem->delete( $this->upgrade_folder . $file['name'], true );
		}

	}


	/**
	 * Remove core plugins prior to building plugin zip
	 */
	function cleanup_plugins() {
		global $wp_filesystem;

		$plugin_folder = $this->upgrade_folder . 'wordpress/wp-content/plugins/';
		$wp_filesystem->delete( $plugin_folder . 'akismet', true );
		$wp_filesystem->delete( $plugin_folder . 'index.php' );
		$wp_filesystem->delete( $plugin_folder . 'hello.php' );

	}


	/**
	 * Parses plugin list into an array
	 */
	function parse_plugin_file() {

		$data = file_get_contents( dirname( __FILE__ ) . '/plugins.txt' );
		$this->plugins = explode( "\n", $data );

	}


	/**
	 * Main build action
	 */
	function build() {

		$this->init();
		$this->get_core();
		$this->get_plugins();
		$this->get_theme();
		$this->zip_wp();
		$this->cleanup_plugins();
		$this->zip_plugins();
		$this->move_to_upload_dir();
		$this->cleanup();

		wp_die( $this->name . ' build script sucessfully executed' );

	}


	/**
	 * Recursively zips a directory
	 * @param string $dir absolute path to the directory to zip
	 * @param string $destination absolute path to the (non-existant) destination zip file
	 */
	function zip( $dir, $destination ) {

		$zip = new ZipArchive();
		$zip->open( $destination, ZIPARCHIVE::CREATE );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );

		foreach ( $iterator as $key => $value ) {

			//ensure paths within zip are relative to the zip's base
			$local_path = str_replace( $dir, '', $key );

			$zip->addFile( realpath( $key ), $local_path );

		}

		$zip->close();

	}


	/**
	 * Creates a zip of the plugins folder
	 */
	function zip_plugins() {

		$this->zip( $this->upgrade_folder . 'wordpress/wp-content/plugins', $this->upgrade_folder . "{$this->name}-Plugins.zip" );

	}


	/**
	 * Creates a zip of all of WordPress, including plugin and theme
	 */
	function zip_wp() {

		$this->zip( $this->upgrade_folder . 'wordpress/', $this->upgrade_folder . "{$this->name}.zip" );

	}


	/**
	 * Download and unzip WordPress core to the upgrade directory
	 */
	function get_core() {
		$file = $this->download_package( $this->core_url );
		$this->unpack_package( $file );
	}


	/**
	 * Download all plugins and unzip them to the vanilla WordPress's plugins directory
	 * (in wp-content/upgrade of the active install)
	 */
	function get_plugins() {

		foreach ( $this->plugins as $plugin ) {

			$plugin_url = $this->get_plugin_url( $plugin );
			$file = $this->download_package( $plugin_url );
			$this->unpack_package( $file, 'wordpress/wp-content/plugins/' );

		}

	}


	/**
	 * Download theme and unzips to the vanilla WordPress's theme directory
	 * (in wp-content/upgrade of the active install)
	 */
	function get_theme() {

		global $wp_filesystem;

		$file = $this->download_package( $this->theme_url );
		$dir = $this->unpack_package( $file, 'wordpress/wp-content/themes/' );

		//because we're pulling from Git, we have to rename the folder to something prettier
		$theme_folder = $this->upgrade_folder . 'wordpress/wp-content/themes/';
		foreach ( glob( $theme_folder . 'govfresh*' ) as $dir )
			$wp_filesystem->move( $dir, $theme_folder . 'govfresh' );

	}


	/**
	 * Move recently created zip files to final destination in uploads folder
	 */
	function move_to_upload_dir() {
		global $wp_filesystem;

		$upload_dir = wp_upload_dir();

		foreach ( array( "{$this->name}.zip", "{$this->name}-Plugins.zip" ) as $zip )
			$wp_filesystem->move( $this->upgrade_folder . $zip, trailingslashit( $upload_dir['basedir'] ) . $zip, true );

	}


	/**
	 * Gets the URL of the latest version of a given plugin
	 * because WordPress plugin URLs change from version to version
	 * Uses the WordPress.org plugin info API
	 * @param unknown $plugin
	 * @return unknown
	 */
	function get_plugin_url( $plugin ) {

		$payload = array(
			'action' => 'plugin_information',
			'request' => serialize( (object) array(
					'slug' => $plugin,
					'fields' => array( 
						//only grab the minium required fields to save bandwidth
						'description'  => false, 
						'sections'     => false,
						'tested'       => false,
						'requires'     => false,
						'rating'       => false,
						'downloaded'   => false,
						'downloadlink' => true,
						'last_updated' => false,
						'homepage'     => false,
						'tags'         => false,
					),
				) )
		);

		$data = wp_remote_post( $this->plugin_api, array( 'body' => $payload ) );

		if ( is_wp_error( $data ) )
			return false;

		$data = unserialize( wp_remote_retrieve_body( $data ) );

		return $data->download_link;

	}


	/**
	 * Downlaod a zip file to a temporay location within /wp-content/upgrade
	 * Adapted from /wp-admin/includes/class-wp-upgrader.php
	 * @param string $package the URL to the package to download
	 * @return unknown
	 */
	function download_package( $package ) {

		if ( ! preg_match('!^(http|https|ftp)://!i', $package) && file_exists($package) ) //Local file or remote?
			return $package; //must be a local file..

		if ( empty($package) )
			return new WP_Error('no_package', $this->strings['no_package']);

		$download_file = download_url($package);

		if ( is_wp_error($download_file) ) 
			return new WP_Error('download_failed', $this->strings['download_failed'], $download_file->get_error_message());
		
		return $download_file;

	}


	/**
	 * Unzip a file
	 * Adapted from /wp-admin/includes/class-wp-upgrader.php
	 * @param string $package the absolute path to the package to unzip
	 * @param string $destination (optional) the path to extract to, defaults to upgrade folder
	 */
	function unpack_package( $package, $destination = null ) {

		if ( is_wp_error( $package ) )
			return;

		global $wp_filesystem;

		//We need a working directory
		$working_dir = ( $destination ) ? $this->upgrade_folder . $destination : $this->upgrade_folder;

		// Unzip package to working directory
		unzip_file( $package, $working_dir );

		// Once extracted, delete the package
		unlink($package);

	}


}


$govpress_build = new GovPress_Build();