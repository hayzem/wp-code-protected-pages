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


class WPCodeProtectedPages
{

    private $screenName;
    private static $instance;
    private $_wpdb;
    private $_tablePrefix;
    private $_tableName = 'wcpp';
    private $_tableNameLog = 'wcpp_log';

    public function __construct()
    {
        global $table_prefix, $wpdb;

        $this->_wpdb = $wpdb;
        $this->_tablePrefix = $table_prefix;

    }

    static function getInstance()
    {

        if (!isset(self::$instance))
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function adminMenu()
    {
        add_menu_page('Passcode', 'Passcode', 'manage_options', __FILE__, [$this, 'renderPage'], plugins_url('/img/icon.png',__DIR__));
        add_submenu_page(__FILE__, 'Dashboard', 'Dashboard', 'manage_options', __FILE__, [$this, 'renderPage']);
        add_submenu_page(__FILE__, 'New', 'New', 'manage_options', __FILE__.'/new', [$this, 'renderNewPage']);
        add_submenu_page(__FILE__, 'List', 'List', 'manage_options', __FILE__.'/list', [$this, 'renderListPage']);
        add_submenu_page(__FILE__, 'Log', 'Log', 'manage_options', __FILE__.'/log', [$this, 'renderLogPage']);
    }

    public function renderPage(){
        ?>
        <div class='wrap'>
            <h2>Passcode Dashboard</h2>
        </div>
        <?php
    }

    public function renderNewPage(){
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2><?php esc_html_e( 'Passcode Add New' ); ?></h2>
            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post">
                <input type="hidden" name="action" value="wcpp_entity_post">
                <table class="form-table">
                    <tbody>
                    <tr>
                        <th>
                            <label for="wcpp_code">Code</label>
                        </th>
                        <td>
                            <input type="text" name="wcpp_code" placeholder="Code" required="required"/>	<input type="button" value="Generate Code" class="button" /><br />
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="wcpp_name">Name</label>
                        </th>
                        <td>
                            <input type="text" name="wcpp_name" placeholder="Name"  required="required"/><br />
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="wcpp_description">Description</label>
                        </th>
                        <td>
                            <input type="text" name="wcpp_description" placeholder="Description" required="required" /><br />
                        </td>
                    </tr>
                    <tr>
                        <th>
                        </th>
                        <td>
                            <input type="submit" value="Save" class="button-primary" />
                        </td>
                    </tr>
                    </tbody>
                </table>
            </form>
        </div><!-- .wrap -->
        <?php
    }

    public function renderListPage(){
        $entities = $this->getEntities();
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2><?php esc_html_e( 'Passcode List' ); ?></h2>
            <table class="form-table">
                <thead>
                    <tr>
                    <th>#ID</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ($entities as $entity)
                {
                ?>
                <tr>
                    <td>
                        <?=$entity->id?>
                    </td>
                    <td>
                        <?=$entity->code?>
                    </td>
                    <td>
                        <?=$entity->name?>
                    </td>
                    <td>
                        <?=$entity->description?>
                    </td>
                    <td>
                        <?=$entity->created_at?>
                    </td>
                </tr>
                <?php
                }
                ?>
                </tbody>
            </table>
        <?php
    }

    public function renderLogPage(){
        $logs = $this->getLogs();
        ?>
        <div class="wrap">
        <?php screen_icon(); ?>
        <h2><?php esc_html_e( 'Passcode Log' ); ?></h2>
        <table class="form-table">
            <thead>
            <tr>
                <th>#ID</th>
                <th>Title</th>
                <th>Description</th>
                <th>Date Created</th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($logs as $log)
            {
                ?>
                <tr>
                    <td>
                        <?=$log->id?>
                    </td>
                    <td>
                        <?=$log->title?>
                    </td>
                    <td>
                        <?=$log->details?>
                    </td>
                    <td>
                        <?=$log->created_at?>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
        <?php
    }

    public function createEntity()
    {
        /**
         * @todo: check fields
         */

        $entity = [
            'code' => $_POST['wcpp_code'],
            'name' => $_POST['wcpp_name'],
            'description' => $_POST['wcpp_description'],
        ];

        $this->insertEntity($entity);
    }

    /**
     * @param $entity
     */
    function insertEntity($entity)
    {
        $wp_track_table = $this->_tablePrefix . "$this->_tableName";

        $this->_wpdb->insert(
            $wp_track_table,
            [
                'code' => $entity['code'],
                'name' => $entity['name'],
                'description' => $entity['description'],
                'created_at' => current_time( 'mysql' ),
            ]
        );
    }

    public function getEntities(array $options = [])
    {
        $wp_track_table = $this->_tablePrefix . "$this->_tableName";

        return $this->_wpdb->get_results( 'SELECT * FROM '.$wp_track_table.';', OBJECT );
    }

    public function getLogs(array $options = [])
    {
        $wp_track_table = $this->_tablePrefix . "$this->_tableNameLog";

        return $this->_wpdb->get_results( 'SELECT * FROM '.$wp_track_table.';', OBJECT );
    }

    public function init()
    {
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_post_wcpp_entity_post', [$this, 'createEntity'] );
    }
}

$wcpp = WPCodeProtectedPages::getInstance();
$wcpp->init();
