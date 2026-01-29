<?php
// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop translations table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gt_translations");

// Remove options
delete_option('gt_api_key');
delete_option('gt_source_language');
delete_option('gt_target_language');
delete_option('gt_db_version');
delete_option('gt_switcher_style');
