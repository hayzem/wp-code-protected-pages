<?php
/*
 Plugin Name: WP Code Protected Pages
 Plugin URI: https://github.com/hayzem/wp-code-protected-pages
 Description: Protect pages and posts with codes. Codes can be added manually or created automatically.
 Version: 1.0.0
 Author: Ali Atasever
 Author URI: http://hayzem.net
 Text Domain: wp-code-protected-pages
 GitHub Plugin URI: https://github.com/hayzem/wp-code-protected-pages
 */

/**
 * @todo: create class for all methods
 */

add_action( 'template_redirect', 'update_passwords' );

function update_passwords()
{
    global $post;
    $global = false;

    if ( empty( $post ) && isset( $GLOBALS['post'] ) )
    {
        $post = $GLOBALS['post'];
        $global = true;
    }

    if( ! $post )
        return null;

    $correct_password = is_array( $post ) ? $post['post_password'] : $post->post_password;

    if( ! $correct_password )
        return null;

    if ( ! isset( $_COOKIE['wp-postpass_' . COOKIEHASH ] ) )
        return;

    if( ! class_exists( 'PasswordHash' ) )
        require_once ABSPATH . WPINC . '/class-phpass.php';

    $hasher = new PasswordHash( 8, true );
    $hash = wp_unslash( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
    if ( 0 !== strpos( $hash, '$P$B' ) )
        return;

    $passed = false;


    $entities = getEntities();
    foreach( $entities as $entity ){
        if( ! $entity->code )
            continue;

        $check_password = $entity->code;

        if( is_string( $check_password ) || is_numeric( $check_password ) )
            $check_password = trim( $check_password );
        else
            continue;

        if( $hasher->CheckPassword( $check_password, $hash ) ){
            $passed = $check_password;
            insertLog($entity, 'LOGGED_IN');
            break;
        }
    }

    if( ! $passed )
        return;

    if( is_array( $post ) ){
        $post['post_password'] = $passed;
    } else {
        $post->post_password = $passed;
    }

    if( $global )
        $GLOBALS['post'] = $post;
}

function createPluginDatabaseTable()
{
    global $table_prefix, $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $tableName = 'wcpp';
    $wp_table = $table_prefix . "$tableName";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    if($wpdb->get_var( "show tables like '$wp_table'" ) != $wp_table) {
        $sql = "CREATE TABLE $wp_table (
              id mediumint(9) NOT NULL AUTO_INCREMENT,
              code tinytext NOT NULL,
              name text NOT NULL,
              description text NOT NULL,
              created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
              PRIMARY KEY  (id)
            ) $charset_collate;
        ";

        dbDelta($sql);
    }

    $logTableName = 'wcpp_log';
    $wp_log_table = $table_prefix . "$logTableName";

    if($wpdb->get_var( "show tables like '$wp_log_table'" ) != $wp_log_table) {
        $sql = "CREATE TABLE $wp_log_table (
              id mediumint(9) NOT NULL AUTO_INCREMENT,
              entity_id integer NOT NULL,
              title text NOT NULL,
              details text NOT NULL,
              created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
              PRIMARY KEY  (id)
            ) $charset_collate;
        ";

        dbDelta($sql);
    }
}

register_activation_hook( __FILE__, 'createPluginDatabaseTable' );

/**
 * @param $entity
 */
function insertEntity($entity)
{
    global $table_prefix, $wpdb;

    $tableName = 'wcpp';
    $wp_track_table = $table_prefix . "$tableName";

    $wpdb->insert(
        $wp_track_table,
        array(
            'code' => $entity['code'],
            'name' => $entity['name'],
            'description' => $entity['description'],
            'created_at' => current_time( 'mysql' ),
        )
    );
}

function insertLog($entity, $action = 'ACTION_UNKNOWN')
{
    global $table_prefix, $wpdb;

    $logTableName = 'wcpp_log';
    $wp_log_table = $table_prefix . "$logTableName";

    $wpdb->insert(
        $wp_log_table,
        array(
            'entity_id' => $entity->id,
            'title' => $entity->name.' '.$action,
            'details' => $entity->name.' '.$entity->description,
            'created_at' => current_time( 'mysql' ),
        )
    );
}

function getEntities(array $options = [])
{
    global $wpdb,$table_prefix;
    $tableName = 'wcpp';
    $wp_track_table = $table_prefix . "$tableName";

    return $wpdb->get_results( 'SELECT * FROM '.$wp_track_table.';', OBJECT );
}
