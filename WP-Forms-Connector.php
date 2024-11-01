<?php
/*
Plugin name: WP Forms Connector
Plugin URI: https://appypie.com/
Description: Save and manage Contact Form 7 data and api.
Author: Surendra
Author URI: https://www.appypie.com/
Text Domain: Appy pie 
Domain Path: /languages/
Version: 1.7.10
*/

// Function to set plugin timezone during activation
function set_plugin_timezone() {
    // Check if the timezone_string option is already set in the database
    $current_timezone = get_option('timezone_string');

    // If the timezone is already set, do not change it
    if (!empty($current_timezone)) {
        return;
    }

    // Set the timezone to UTC
    update_option('timezone_string', 'UTC');
    
    // Set GMT offset to 0 for UTC
    update_option('gmt_offset', 0); 
}

// Hook the function to run during plugin activation
register_activation_hook(__FILE__, 'set_plugin_timezone');

function Appyconnect_create_table(){
    global $wpdb;
    $appyconnector       = apply_filters( 'Appyconnect_database', $wpdb );
    $table_name = $appyconnector->prefix.'manage_forms';
	$table_name_api = $appyconnector->prefix.'manage_api';
	$table_name_custom = $appyconnector->prefix.'manage_custom';

    if( $appyconnector->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {

        $charset_collate = $appyconnector->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            form_id bigint(20) NOT NULL AUTO_INCREMENT,
            form_post_id bigint(20) NOT NULL,
            form_value longtext NOT NULL,
            form_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (form_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
	
	if( $appyconnector->get_var("SHOW TABLES LIKE '$table_name_api'") != $table_name_api ) {

        $charset_collate = $appyconnector->get_charset_collate();

        $sql = "CREATE TABLE $table_name_api (
            form_id bigint(20) NOT NULL AUTO_INCREMENT,
			form_post_name varchar(100) NOT NULL,
            form_post_id bigint(20) NOT NULL,
            form_value longtext NOT NULL,
            form_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (form_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
	
		if( $appyconnector->get_var("SHOW TABLES LIKE '$table_name_custom'") != $table_name_custom ) {

        $charset_collate = $appyconnector->get_charset_collate();

        $sql = "CREATE TABLE $table_name_custom (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_post_id bigint(20) NOT NULL,
            start_date date DEFAULT '0000-00-00' NOT NULL,
			end_date date DEFAULT '0000-00-00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    $upload_dir    = wp_upload_dir();
    $Appyconnect_dirname = $upload_dir['basedir'].'/Appyconnect_uploads';
    if ( ! file_exists( $Appyconnect_dirname ) ) {
        wp_mkdir_p( $Appyconnect_dirname );
    }
    add_option( 'Appyconnect_view_install_date', date('Y-m-d G:i:s'), '', 'yes');

}

function appyconnect_manageform_on_activate( $network_wide ){

    global $wpdb;
    if ( is_multisite() && $network_wide ) {
        // Get all blogs in the network and activate plugin on each one
        $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            Appyconnect_create_table();
            restore_current_blog();
        }
    } else {
        Appyconnect_create_table();
    }

	// Add custom capability
	$role = get_role( 'administrator' );
	$role->add_cap( 'connect_access' );
	add_option('appypie_secret', bin2hex(random_bytes(128)));
}

register_activation_hook( __FILE__, 'appyconnect_manageform_on_activate' );


function Appyconnect_on_deactivate() {

	// Remove custom capability from all roles
	global $wp_roles;

	foreach( array_keys( $wp_roles->roles ) as $role ) {
		$wp_roles->remove_cap( $role, 'connect_access' );
	}
	 delete_option('appypie_secret');
}

register_deactivation_hook( __FILE__, 'connect_form_on_deactivate' );


function Appyconnect_before_send_mail( $form_tag ) {

    global $wpdb;
    $appyconnector          = apply_filters( 'Appyconnect_database', $wpdb );
    $table_name    = $appyconnector->prefix.'manage_forms';
    $upload_dir    = wp_upload_dir();
    $Appyconnect_dirname = $upload_dir['basedir'].'/Appyconnect_uploads';
    $time_now      = time();

    $form = WPCF7_Submission::get_instance();

    if ( $form ) {

        $black_list   = array('_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag',
        '_wpcf7_is_ajax_call','Appyconnect_name', '_wpcf7_container_post','_wpcf7cf_hidden_group_fields',
        '_wpcf7cf_hidden_groups', '_wpcf7cf_visible_groups', '_wpcf7cf_options','g-recaptcha-response');

        $data           = $form->get_posted_data();
        $files          = $form->uploaded_files();
        $uploaded_files = array();

        $rm_underscore  = apply_filters('Appyconnect_remove_underscore_data', true); 

        foreach ($files as $file_key => $file) {
            array_push($uploaded_files, $file_key);
            copy($file, $Appyconnect_dirname.'/'.$time_now.'-'.basename($file));
        }

        $form_data   = array();

        $form_data['Appyconnect_status'] = 'unread';
        foreach ($data as $key => $d) {
            
            $matches = array();
            if( $rm_underscore ) preg_match('/^_.*$/m', $key, $matches);

            if ( !in_array($key, $black_list ) && !in_array($key, $uploaded_files ) && empty( $matches[0] ) ) {

                $tmpD = $d;

                if ( ! is_array($d) ){

                    $bl   = array('\"',"\'",'/','\\','"',"'");
                    $wl   = array('&quot;','&#039;','&#047;', '&#092;','&quot;','&#039;');

                    $tmpD = str_replace($bl, $wl, $tmpD );
                }

                $form_data[$key] = $tmpD;
            }
            if ( in_array($key, $uploaded_files ) ) {
                $form_data[$key.'Appyconnect_file'] = $time_now.'-'.$d;
            }
        }

        /* Appyconnect before save data. */
        $form_data = apply_filters('Appyconnect_before_save_data', $form_data);

        do_action( 'Appyconnect_before_save', $form_data );

        $form_post_id = $form_tag->id();
        $form_value   = serialize( $form_data );
        $form_date    = current_time('Y-m-d H:i:s');

        $appyconnector->insert( $table_name, array(
            'form_post_id' => $form_post_id,
            'form_value'   => $form_value,
            'form_date'    => $form_date
        ) );

        /* Appyconnect after save data */
        $insert_id = $appyconnector->insert_id;
        do_action( 'Appyconnect_after_save_data', $insert_id );
    }

}

add_action( 'wpcf7_before_send_mail', 'Appyconnect_before_send_mail' );


function manage_api_form_data( $form_tag ) {

    global $wpdb;
    $appyconnector          = apply_filters( 'Appyconnect_database', $wpdb );
    $table_name    = $appyconnector->prefix.'manage_api';
    $upload_dir    = wp_upload_dir();
    $Appyconnect_dirname = $upload_dir['basedir'].'/Appyconnect_uploads';
    $time_now      = time();

    $form = WPCF7_Submission::get_instance();

    if ( $form ) {

        $black_list   = array('_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag',
        '_wpcf7_is_ajax_call','Appyconnect_name', '_wpcf7_container_post','_wpcf7cf_hidden_group_fields',
        '_wpcf7cf_hidden_groups', '_wpcf7cf_visible_groups', '_wpcf7cf_options','g-recaptcha-response');

        $data           = $form->get_posted_data();
        $files          = $form->uploaded_files();
        $uploaded_files = array();

        $rm_underscore  = apply_filters('Appyconnect_remove_underscore_data', true); 

        foreach ($files as $file_key => $file) {
            array_push($uploaded_files, $file_key);
            copy($file, $Appyconnect_dirname.'/'.$time_now.'-'.basename($file));
        }

        $form_data   = array();

        $form_data['Appyconnect_status'] = 'unread';
        foreach ($data as $key => $d) {
            
            $matches = array();
            if( $rm_underscore ) preg_match('/^_.*$/m', $key, $matches);

            if ( !in_array($key, $black_list ) && !in_array($key, $uploaded_files ) && empty( $matches[0] ) ) {

                $tmpD = $d;

                if ( ! is_array($d) ){

                    $bl   = array('\"',"\'",'/','\\','"',"'");
                    $wl   = array('&quot;','&#039;','&#047;', '&#092;','&quot;','&#039;');

                    $tmpD = str_replace($bl, $wl, $tmpD );
                }

                $form_data[$key] = $tmpD;
            }
            if ( in_array($key, $uploaded_files ) ) {
                $form_data[$key.'Appyconnect_file'] = $time_now.'-'.$d;
            }
        }

        /* Appyconnect before save data. */
        $form_data = apply_filters('Appyconnect_before_save_data', $form_data);

        do_action( 'Appyconnect_before_save', $form_data );

        $form_post_id   = $form_tag->id();
		$form_post_name = $form_tag->title();
        $form_value     = $form_data;
        $form_date      = current_time('Y-m-d H:i:s');

        $appyconnector->insert( $table_name, array(
            'form_post_id'   => $form_post_id,
            'form_post_name' => $form_post_name,
			'form_value'     => json_encode($form_value, true),
            'form_date'      => $form_date
        ) );

        /* Appyconnect after save data */
        $insert_id = $appyconnector->insert_id;
        do_action( 'Appyconnect_after_save_data', $insert_id );
    }

}
add_action( 'wpcf7_before_send_mail', 'manage_api_form_data' );

add_action( 'init', 'Appyconnect_init');

/**
 * Appyconnect_init and Appyconnect_admin_init
 * Admin setting
 */
function Appyconnect_init(){

    do_action( 'Appyconnect_init' );

    if( is_admin() ){

        require_once 'inc/appyconnect-admin-mainpage.php';
        require_once 'inc/appyconnect-admin-subpage.php';
        require_once 'inc/appyconnect-admin-form-details.php';
        require_once 'inc/appyconnect-export-csv.php';
        require_once 'inc/appyconnect-export-csv.php';
		

        do_action( 'Appyconnect_admin_init' );

        $csv = new AppyConnect_Expoert_CSV();
        if( isset($_REQUEST['csv']) && ( $_REQUEST['csv'] == true ) && isset( $_REQUEST['nonce'] ) ) {

            $nonce  = filter_input( INPUT_GET, 'nonce', FILTER_SANITIZE_STRING );

            if ( ! wp_verify_nonce( $nonce, 'dnonce' ) ) wp_die('Invalid nonce..!!');

            $csv->download_csv_file();
        }
        new Appyconnect_Wp_Main_Page();
    }
}

// Include the custom-post-create.php file
require_once plugin_dir_path(__FILE__) . 'custom-post-create.php';

add_action( 'admin_notices', 'Appyconnect_admin_notice' );
add_action('admin_init', 'Appyconnect_view_ignore_notice' );

function Appyconnect_admin_notice() {

    $install_date = get_option( 'Appyconnect_view_install_date', '');
    $install_date = date_create( $install_date );
    $date_now     = date_create( date('Y-m-d G:i:s') );
    $date_diff    = date_diff( $install_date, $date_now );

    if ( $date_diff->format("%d") < 7 ) {

        return false;
    }

    if ( ! get_option( 'Appyconnect_view_ignore_notice' ) ) {

        echo '<div class="updated"><p>';

        printf(__( 'Awesome, you\'ve been using <a href="admin.php?page=Appyconnect-list.php">Contact Form AppyConnect</a> for more than 1 week. May we ask you to give it a 5-star rating on WordPress? | <a href="%2$s" target="_blank">Ok, you deserved it</a> | <a href="%1$s">I already did</a> | <a href="%1$s">No, not good enough</a>', 'contact-form-Appyconnect' ), '?Appyconnect-ignore-notice=0',
        'https://wordpress.org/plugins/contact-form-Appyconnect/');
        echo "</p></div>";
    }
}

function Appyconnect_view_ignore_notice() {

    if ( isset($_GET['Appyconnect-ignore-notice']) && '0' == $_GET['Appyconnect-ignore-notice'] ) {

        update_option( 'Appyconnect_view_ignore_notice', 'true' );
    }
}

/**
 * Plugin settings link
 * @param  array $links list of links
 * @return array of links
 */
function Appyconnect_settings_link( $links ) {
    $forms_link = '<a href="admin.php?page=Appyconnect-list.php">Appyconnect Api</a>';
    array_unshift($links, $forms_link);
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'Appyconnect_settings_link' );

/**
 * Load language files to enable plugin translation
 *
 * @since 1.2.4.1
 */
function Appyconnect_load_textdomain() {
	load_plugin_textdomain( 'contact-form-Appyconnect', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'Appyconnect_load_textdomain' );

class Token_Auth {
    private $namespace;
    private $requiredcapability;
    public function __construct() {
        $this->namespace = 'form/v2';
        $this->requiredcapability = 'read';
        add_action('rest_api_init', array(&$this, 'add_api_routes'));
    }
    public function add_api_routes() {
        register_rest_route($this->namespace, 'token', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array($this, 'create_token'),));
    }
    public function create_token($request) {
        $username = $request->get_param('username');
        $password = $request->get_param('password');
        $response = array();
        if (!empty($username) && !empty($password)) {
            $user = get_user_by('login', $username); // Verify the user.
            // message reveals if the username or password are correct.
            $result = $user && wp_check_password($password, $user->data->user_pass, $user->ID);
            if (!$result) {
                $response['code'] = "invalid_username";
                $response['message'] = __("Unknown username. Check again or try your email address.", "wp-rest-user");
                $response['data'] = null;
                return $response;
            } else {
                $response['data'] = array(
				"status" => 200,
				"user_id"=> $user->ID
				);
                return rest_ensure_response($response);
            }
            wp_set_current_user($user->ID, $user->user_login);
            // A subscriber has 'read' access so a very basic user account can be used.
            if (!current_user_can($this->requiredcapability)) {
                return new WP_Error('rest_forbidden', 'You do not have permissions to view this data.', array('status' => 401));
            }
        } else {
            return new WP_Error('invalid-method', 'You must specify a valid username and password.', array('status' => 400)); //Bad Request
            
        }
    }
}
$plugin = new Token_Auth();


// Start form list API

class contact_form7_list extends WP_REST_Controller {
	private $apinamespace;
	private $baseurl;
	private $apiversion;
	private $requiredcapability;
	
	public function __construct() {
		$this->apinamespace = 'form/v';
		$this->baseurl = 'api';
		$this->apiversion = '1';
		$this->requiredcapability = 'read';  // Minimum capability to use the endpoint
		
		$this->init();
	}
	public function register_routes() {
		$namespace = $this->apinamespace . $this->apiversion;

		register_rest_route( $namespace, '/' . $this->baseurl , array(
	array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'callback' => array( $this, 'Form_list' ), ),
		)  );
	}
	// Register our REST Server
	public function init(){
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	public function Form_list( WP_REST_Request $request ){
		
		$creds = array();
		$response = array();
		$headers = array();
		foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
			}
		}
		
		$username = $headers['Username'];
		$password = $headers['Password'];
		// Get username and password from the submitted headers.
		if ( array_key_exists( 'Username', $headers ) && array_key_exists( 'Password', $headers ) ) {
			$creds['user_login'] = $username;
			$creds['user_password'] =  $password;
			$creds['remember'] = false;
			$user = get_user_by('login', $username);// Verify the user.
			//$user = wp_signon( $creds, false );  
			// message reveals if the username or password are correct.
			 $result = $user && wp_check_password($password, $user->data->user_pass, $user->ID);
			 if(!$result){
				  $response['code'] = "invalid_username";
				  $response['message'] = __("Unknown username. Check again or try your email address.", "wp-rest-user");
				  $response['data'] = null;
				  return $response;
			 }
                    
			wp_set_current_user( $user->ID, $user->user_login );
			
			// A subscriber has 'read' access so a very basic user account can be used.
			if ( ! current_user_can( $this->requiredcapability ) ) {
				return new WP_Error( 'rest_forbidden', 'You do not have permissions to view this data.', array( 'status' => 401 ) );
			}
			
			
			global $wpdb;
			$appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
			$table_name = $appyconnector->prefix.'posts';
			$query = "SELECT ID, post_title FROM $table_name WHERE 1=1 AND $table_name.post_type = 'wpcf7_contact_form' AND ($table_name.post_status = 'publish') ORDER BY $table_name.post_date DESC";
			$list = $wpdb->get_results($query);
			return $list;
		}
		else {
			return new WP_Error( 'invalid-method', 'You must specify a valid username and password.', array( 'status' => 400  
		 ) ); //Bad Request
		}
	}
	
}
 
$lps_rest_server = new contact_form7_list();

// Strat Form data api

class List_Product_Stock_Rest_Server extends WP_REST_Controller {
	private $api_namespace;
	private $base;
	private $api_version;
	private $required_capability;
	
	public function __construct() {
		$this->api_namespace = 'form/v';
		$this->base = 'jsonapi';
		$this->api_version = '1';
		$this->required_capability = 'read';  // Minimum capability to use the endpoint
		
		$this->init();
	}
	
	public function register_routes() {
		$namespace = $this->api_namespace . $this->api_version;
		
		register_rest_route( $namespace, '/' . $this->base .'/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'callback' => array( $this, 'Form_data_api' ), ),
		)  );
	}
	// Register our REST Server
	public function init(){
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	
	public function Form_data_api( WP_REST_Request $request ){

		$creds = array();
		$response = array();
		$headers = array();
		foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
			}
		}
		$username = $headers['Username'];
		$password = $headers['Password'];

		// Get username and password from the submitted headers.
		if ( array_key_exists( 'Username', $headers ) && array_key_exists( 'Password', $headers ) ) {
			$creds['user_login'] = $username;
			$creds['user_password'] =  $password;
			$creds['remember'] = false;
			$user = get_user_by('login', $username);// Verify the user.
			//$user = wp_signon( $creds, false );  // Verify the user.
            $result = $user && wp_check_password($password, $user->data->user_pass, $user->ID);
			 if(!$result){
				  $response['code'] = "invalid_username";
				  $response['message'] = __("Unknown username. Check again or try your email address.", "wp-rest-user");
				  $response['data'] = null;
				  return $response;
			 }
			// message reveals if the username or password are correct.
			if ( is_wp_error($user)) {
				return $user;
			}
			
			wp_set_current_user( $user->ID, $user->user_login );
			
			// A subscriber has 'read' access so a very basic user account can be used.
			if ( ! current_user_can( $this->required_capability ) ) {
				return new WP_Error( 'rest_forbidden', 'You do not have permissions to view this data.', array( 'status' => 401 ) );
			}
                global $wpdb;
                $uri = $_SERVER['REQUEST_URI'];
                $FormiD = (explode("/wp-", $uri));
                $FormiDjson =  $FormiD[1];
                $FormiD = (explode("/", $FormiDjson));

                if (isset($_GET['from_date'])){
                    $datetime = new DateTime($_GET['from_date']);
                    $la_time = new DateTimeZone(get_option('timezone_string'));
                    $datetime->setTimezone($la_time);
                    $from_date= $datetime->format('Y-m-d H:i:s');
                }else{
                    $from_date=date("Y-m-d H:i:s");
                }
                $Formjsondata = $FormiD[4];
                $Formjsondata=explode("?",$Formjsondata);
                $Formjsondata=$Formjsondata[0];
                $appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
                $table_name = $appyconnector->prefix.'manage_api';
                $query = "SELECT * FROM $table_name WHERE form_post_id = $Formjsondata and form_date > '".$from_date."'";
                $list = $wpdb->get_results($query);
                $result=array("timezone"=>get_option('timezone_string'),"data"=>$list);
				return $result;
		}
		else {
        return new WP_Error( 'invalid-method', 'You must specify a valid username and password.', array( 'status' => 400  
		 ) ); //Bad Request
		}
	}
}

$lps_rest_server = new List_Product_Stock_Rest_Server();



class wpCommonAPI {
    private $namespace;
    private $requiredcapability;
    public function __construct() {
        $this->namespace = 'wp/v3';
        $this->requiredcapability = 'read';
        add_action('rest_api_init', array(&$this, 'addApiRoutes'));
    }
    public function addApiRoutes() {
	
	register_rest_route($this->namespace, 'post/alllist', array(
            'methods' => 'POST',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'allposttype'
            ),
		));
	
	//Pages Rest api Endpoints
	//page create
	register_rest_route($this->namespace, 'page/create', array(
            'methods'  => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'createPage'
            ),
		));
	//page listing
	register_rest_route($this->namespace, 'page/list', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'listPages'
            ),
		));
		// get page details with id
		register_rest_route($this->namespace, 'page/list/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'pagelistDetail'
            ),
		));
		
		// Delete pages with id
		register_rest_route($this->namespace, 'page/delete/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'PageDelete'
            ),
		));
		
	   //post Rest Api Endpoints
	   //post Created
		register_rest_route($this->namespace, 'post/create', array(
            'methods'  => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'createPost'
            ),
		)); 
		
		//post listing
		register_rest_route($this->namespace, 'post/list', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'listPost'
            ),
		));
		
		//post get details
		register_rest_route($this->namespace, 'post/list/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'listPostDetail'
            ),
		));
		//post deleted
		register_rest_route($this->namespace, 'post/delete/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'postDelete'
            ),
		));
		
		register_rest_route($this->namespace, 'category/list', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'categoryListing'
            ),
		));
		
		register_rest_route($this->namespace, 'user/list', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'userListing'
            ),
		));
		register_rest_route($this->namespace, 'user/list/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'userDetail'
            ),
		));
		
		register_rest_route($this->namespace, 'user/delete/(?P<user_id>[\d]+)', array(
			'methods' => 'GET',
			'permission_callback' => '__return_true',
			'callback' => array(
				$this,
				'delete_wc_user'
			)
		));
       
		register_rest_route($this->namespace, 'user/create', array(
			'methods' => 'POST',
			'permission_callback' => '__return_true',
			'callback' => array(
				$this,
				'add_new_users'
			)
		));
  
		register_rest_route($this->namespace, 'comment/list', array(
			'methods' => 'GET',
			'permission_callback' => '__return_true',
			'callback' => array(
				$this,
				'get_comment_list'
			)
		));
		
		register_rest_route($this->namespace, 'comment/list/(?P<id>[\d]+)', array(
			'methods' => 'GET',
			'permission_callback' => '__return_true',
			'callback' => array(
				$this,
				'get_post_list'
			)
		));
		
		register_rest_route($this->namespace, 'comment/detail/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'comment_detail'
            ),
		));
		
		register_rest_route($this->namespace, 'comment/delete/(?P<id>[\d]+)', array(
            'methods' => 'GET',
			'permission_callback' => '__return_true',
            'callback' => array(
                $this,
                'delete_comment'
            ),
		));
		
    }
	
	 public function allposttype(WP_REST_Request $request) {
            $args = array(
             'numberposts' => -1,
             //'post_type'   => 'page',
             'post_status' => 'publish'
			 //'posts_per_page' => 5
           );
        
        $latestPost = get_posts($args);
		if(count($latestPost)>0){
			return rest_ensure_response($latestPost);
		}
        return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'post'));
   }

	public function categoryListing($request) {
	global $wpdb;
    foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		$categories = get_categories(array(
			'orderby' => 'name',
			'order'   => 'ASC'
			//'taxonomy'   =>  'product_cat' // mention taxonomy here. 

		  ) 
		);
		if(count($categories)>0){
		  return rest_ensure_response($categories);
		}
		return new WP_Error(404, sprintf(__('Sorry, No records is not found.', 'WordPress'), 'post'));
		}
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		    }
        }
	public function listPages(WP_REST_Request $request) {
	 global $wpdb;
    foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
                global $wpdb;
		        $uri = $_SERVER['REQUEST_URI'];
                $uri = $_SERVER['REQUEST_URI'];
                $FormiD = (explode("/wp-", $uri));
				//print_r($FormiD); exit;
                $FormiDjson =  $FormiD[1];
                $FormiD = (explode("/", $FormiDjson));
				//print_r($FormiD);
               // exit;
                if (isset($_GET['after'])){
                    $datetime = new DateTime($_GET['after']);
                    $la_time = new DateTimeZone(get_option('timezone_string'));
                    $datetime->setTimezone($la_time);
                    $post_date= $datetime->format('Y-m-d H:i:s');
                }else{
                    $post_date=date("Y-m-d H:i:s");
                }
				
				$shorting = $_GET['order'];
				$pageLimit = $_GET['limit'];
				$Pagestatus = $_GET['status'];
				
                /* $Formjsondata = $FormiD[4];
                $Formjsondata = explode("=",$Formjsondata);
				$afterdate    = $Formjsondata[1];
				$shorting     = $Formjsondata[2];
				$pageLimit    = $Formjsondata[3];
				$Formjsondata = explode("&",$shorting);
                $shorting     = $Formjsondata[0];
				$afterdate    = explode("&",$afterdate);
				//$post_date    = $afterdate[0];
				//exit; */
                $appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
                $table_name = $appyconnector->prefix.'posts';
              
				$query = "SELECT * FROM $table_name WHERE $table_name.post_type = 'page' and $table_name.post_date > '".$post_date."' AND ($table_name.post_status = '$Pagestatus' OR $table_name.post_status = 'inherit') ORDER BY $table_name.post_date $shorting LIMIT 0, $pageLimit";
                $list = $wpdb->get_results($query);
				
				$result = array("timezone"=>get_option('timezone_string'),"data"=>$list);
				return $result;
				
        return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'page'));
		 }
		   }
		   else
		   {
			return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
				'status' => 400
			));
		  }
     }
	//Page listing  with ID callback fuctions
	public function pagelistDetail($request) {
	 global $wpdb;
   foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
				$headers[$name] = $value;
			}
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		 if($request['id']){
			 $postData = get_post($request['id']);
			 return rest_ensure_response($postData);
		 }
		 return new WP_Error(404, sprintf(__('Sorry, No records is not found.', 'WordPress'), 'page'));
		 
		  }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		    }
        }
	//Page Create listing callback fuctions
	public function createPage($request) {
	global $wpdb;
   foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
				$headers[$name] = $value;
			}
		}
        $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			if ($userRole == 'administrator')
			 {
		// Hit Url
		global $wpdb;
		$Posttitel = $_GET['title'];
		$Content = $_GET['content'];
		$appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
		$tablename = $appyconnector->prefix.'posts';
		$table_name1 = $wpdb->prefix .'posts';
		$date = date('Y-m-d H:i:s'); 
        //Insert New page		
		$insertpost = $wpdb->insert($tablename, array(
		'post_title'   => $Posttitel,
		'post_content' => $Content,
		'post_status'  => 'draft',
		'post_type'    => 'page',
		'post_name'    => 'page',
		'post_author'  => 1,
		'post_date'    => $date,
		'post_date_gmt'=> $date
	   ));
	   //get post ID
		$query = "SELECT ID FROM $tablename  
		ORDER BY `$tablename`.`ID`  DESC LIMIT 0, 1;";
		$list = $wpdb->get_results($query);
        $postid = $list[0]->ID;
		   if($postid){
			  return new WP_Error('message', 'Page added.', array('status' => 200));
		   }
		   return new WP_Error(404, sprintf(__('Sorry, Page is not created.', 'WordPress'), 'pages'));	

			 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
		
    }
	//page delete with id callback fuctions
	public function PageDelete($request) {
	 global $wpdb;
   foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
				$headers[$name] = $value;
			}
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		if($request['id']){
			$success = wp_delete_post($request['id']);
			if($success){
			 $response['code'] = 200;
				$response['message'] = __("User was deleted Successfully", "wp-rest-user");
				return $response;
			}else{
			 return new WP_Error(404, sprintf(__('Sorry, ID is not found.', 'WordPress'), 'post'));
			}
		}
		 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
    }
	//Post listing With ID Callback fuctions
	public function listPostDetail($request) {
     global $wpdb;
     foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	    $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		 if($request['id']){
			 $postData = get_post($request['id']);
			 return rest_ensure_response($postData);
		 }
		 return new WP_Error(404, sprintf(__('Sorry, No records is not found.', 'WordPress'), 'post'));
		  }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
    }
	//Post Create Callback fuctions
    public function createPost($request) {
	foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		} 
		error_reporting(E_ALL ^ E_NOTICE);
	    $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		global $wpdb;
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix .'users';
		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{	
		 if ($userRole == 'administrator')
		{
		// Hit Url
		global $wpdb;
		$Posttitel = $_GET['title'];
		$Content = $_GET['content'];
		$appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
		$tablename = $appyconnector->prefix.'posts';
		$table_name1 = $wpdb->prefix .'posts';
		$date = date('Y-m-d H:i:s'); 
        //Insert New Post	
		$insertpost = $wpdb->insert($tablename, array(
		'post_title'   => $Posttitel,
		'post_content' => $Content,
		'post_status'  => 'draft',
		'post_type'    => 'post',
		'post_name'    => 'post',
		'post_author'  => 3,
		'post_date'    => $date,
		'post_date_gmt'=> $date
		
	   ));
	   //get post ID
		$query = "SELECT ID FROM $tablename  
		ORDER BY `$tablename`.`ID`  DESC LIMIT 0, 1;";
		$list = $wpdb->get_results($query);
        $postid = $list[0]->ID; 
	   if($postid){
		  return new WP_Error('message', 'Post added.', array('status' => 200));
	   }
	   return new WP_Error(404, sprintf(__('Sorry, Post is not created.', 'WordPress'), 'post'));
	    }
		  }
		   else
		  {
			return new WP_Error('Error', 'You must Enter specify a valid username and password.', array('status' => 400
		  ));
		}
    }
	//post listing callback fuctions
	public function listPost(WP_REST_Request $request) {
	 global $wpdb;
    foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
          global $wpdb;
                $uri = $_SERVER['REQUEST_URI'];
                $FormiD = (explode("/wp-", $uri));
				//print_r($FormiD); exit;
                $FormiDjson =  $FormiD[1];
                $FormiD = (explode("/", $FormiDjson));
				//print_r($FormiD);
               // exit;
                if (isset($_GET['after'])){
                    $datetime = new DateTime($_GET['after']);
                    $la_time = new DateTimeZone(get_option('timezone_string'));
                    $datetime->setTimezone($la_time);
                    $post_date= $datetime->format('Y-m-d H:i:s');
                }else{
                    $post_date=date("Y-m-d H:i:s");
                }
				$postStatus = ($_GET['status']);
				$shorting   = ($_GET['order']);
				$pageLimit  = ($_GET['limit']);
               /*$Formjsondata = $FormiD[4];
                $Formjsondata = explode("=",$Formjsondata);
				$afterdate    = $Formjsondata[1];
				$shorting     = $Formjsondata[2];
				$pageLimit    = $Formjsondata[3];
				$Formjsondata = explode("&",$shorting);
                $shorting     = $Formjsondata[0];
				$afterdate    = explode("&",$afterdate);
				//$post_date    = $afterdate[0];
				//exit; */
                $appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
                $table_name = $appyconnector->prefix.'posts';
              
				$query = "SELECT * FROM $table_name WHERE $table_name.post_type = 'post' and $table_name.post_date > '".$post_date."' AND ($table_name.post_status = '$postStatus' OR $table_name.post_status = 'inherit') ORDER BY $table_name.post_date $shorting LIMIT 0, $pageLimit";
                $list = $wpdb->get_results($query);
				
				$result = array("timezone"=>get_option('timezone_string'),"data"=>$list);
				return $result;

        return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'post'));
		 }
		   }
		   else
		   {
			return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
				'status' => 400
			));
		  }
     }
   //Post Delete with id call fuctions
   public function postDelete($request) {
        global $wpdb;
   foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		if($request['id']){
			$success = wp_delete_post($request['id']);
			if($success){
			 $response['code'] = 200;
				$response['message'] = __("User was deleted Successfully", "wp-rest-user");
				return $response;
			}else{
			 return new WP_Error(404, sprintf(__('Sorry, ID is not found.', 'WordPress'), 'post'));
			}
		}
		 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
    }
      
   public function add_new_users($request) {
   
	 $parameters = $request->get_params();
	 $username   = sanitize_text_field($parameters['username']);
	 $email      = sanitize_text_field($parameters['email']);
	 $password   = sanitize_text_field($parameters['password']);
	 $first_name = sanitize_text_field($parameters['first_name']);
	 $last_name  = sanitize_text_field($parameters['last_name']);
	 $response = array();
	 $error = new WP_Error();
	 if (empty($username))
	 {
		$error->add(400, __("Username field 'username' is required.", 'wp-rest-user') , array(
			'status' => 400
		));
		return $error;
	 }

	 if (empty($email))
	 {
		$error->add(401, __("Email field 'email' is required.", 'wp-rest-user') , array(
			'status' => 400
		));
		return $error;
	 }
	 if (empty($password))
	 {
		$error->add(404, __("Password field 'password' is required.", 'wp-rest-user') , array(
			'status' => 400
		));
		return $error;
	 }
	 $user_id = username_exists($username);
	 if (!$user_id && email_exists($email) == false)
	 {
		$user_id = wp_create_user($username, $password, $email);
		//wp_new_user_notification($user_id);
		$user_data = wp_update_user(array( 'ID' => $user_id, 'first_name' => $first_name,'last_name'=>$last_name ) );

		if (!is_wp_error($user_id))
		{
			// Ger User Data (Non-Sensitive, Pass to front end.)
			$response['code'] = 200;
			$response['message'] = __("User '" . $username . "'Thanks for registration check your inbox!", "wp-rest-user");
		}
		else
		{
			return $user_id;
		}
	}
	else
	{
		$error->add(406, __("Email already exists, please try 'Reset Password'", 'wp-rest-user') , array(
			'status' => 400
		));
		return $error;
	}
	return new WP_REST_Response($response);
	
   }
   
   public function userListing(WP_REST_Request $request) {
     global $wpdb;
   foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	    $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
		if ($userRole == 'administrator')
		{
          global $wpdb;
		        $uri = $_SERVER['REQUEST_URI'];
                $FormiD = (explode("/wp-", $uri));
				//print_r($FormiD); exit;
                $FormiDjson =  $FormiD[1];
                $FormiD = (explode("/", $FormiDjson));

                /* $Formjsondata = $FormiD[4];
                $Formjsondata = explode("=",$Formjsondata);
				$afterdate    = $Formjsondata[1];
				$shorting     = $Formjsondata[2];
				$pageLimit    = $Formjsondata[3];
				$Formjsondata = explode("&",$shorting);
                $shorting     = $Formjsondata[0];
				$afterdate    = explode("&",$afterdate);
				$post_date1    = $afterdate[0]; */
				
				$shorting = $_GET['order'];
				$pageLimit = $_GET['limit'];
				
				 if (isset($_GET['after'])){
                    $datetime = new DateTime($_GET['after']);
                    $la_time = new DateTimeZone(get_option('timezone_string'));
                    $datetime->setTimezone($la_time);
                    $post_date= $datetime->format('Y-m-d H:i:s');
                }else{
                    $post_date=date("Y-m-d H:i:s");
                } 
                $appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
                $table_name = $appyconnector->prefix.'users';
              
				$query = "SELECT * FROM $table_name WHERE $table_name.user_status = 'active' and $table_name.user_registered > '".$post_date."' ORDER BY $table_name.user_registered $shorting LIMIT 0, $pageLimit";
                $list = $wpdb->get_results($query);
                $result=array("timezone"=>get_option('timezone_string'),"data"=>$list);
				return $result;
		      }
			   }
			   else
			   {
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		    }
				
       }
   public function userDetail($request) {
   global $wpdb;
   foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		$user_info = get_userdata($request['id']);
		$user = get_user_by('id', $request['id']);
		if ($user)
		{ 
			$first_name = $user_info->first_name;
			$last_name = $user_info->last_name;

			$userArr[] = array(
				'ID' => $user->ID,
				'user_id' => $user->ID,
				'user_login' => $user->user_login,
				'user_pass' => $user->user_pass,
				'email' => $user->user_email,
				'first_name' => $first_name,
				'last_name' => $last_name,
				'user_registered'=>$user->user_registered
			);
			return new WP_REST_Response($userArr);
		}
		else
		{
			return new WP_Error('Error', __('Sorry invalid User ID ') , array(
				'status' => 400
			));

		}
		}
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
   }
   
   function delete_wc_user($request)
	{
		global $wpdb;
		$headers = array();
		foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
				$headers[$name] = $value;
			}
		}
		$username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			if ($user && wp_check_password($password, $user
				->data->user_pass, $user->ID))
			{
				if ($userRole == 'administrator')
				{
					if ($deleteUser)
					{
						$res = $wpdb->query("DELETE  FROM {$table_name} WHERE ID = $user_id");
						if ($res == 1)
						{
							$response['code'] = 200;
							$response['message'] = __("User was deleted Successfully", "wp-rest-user");
							return $response;
						}
					}
					else
					{
						return new WP_Error('Error', 'Sorry no user exits.', array(
							'status' => 400
						));
					}

				}

			}
			else
			{
				return new WP_Error('Error', 'You must specify a valid username and password.', array(
					'status' => 400
				));
			}
		}
		return new WP_Error('Error', __('Sorry invalid API Request') , array(
			'status' => 500
		));
	}
	
	public function get_comment_list(WP_REST_Request $request){
	  global $wpdb;
   foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{	
			if ($userRole == 'administrator')
			 {	
				global $wpdb;
                $uri = $_SERVER['REQUEST_URI'];
                $FormiD = (explode("/wp-", $uri));
				//print_r($FormiD); exit;
                $FormiDjson =  $FormiD[1];
                $FormiD = (explode("/", $FormiDjson));
				//print_r($FormiD);
               // exit;
                if (isset($_GET['after'])){
                    $datetime = new DateTime($_GET['after']);
                    $la_time = new DateTimeZone(get_option('timezone_string'));
                    $datetime->setTimezone($la_time);
                    $post_date= $datetime->format('Y-m-d H:i:s');
                }else{
                    $post_date=date("Y-m-d H:i:s");
                }
				//echo "<pre>";
				//print_r($_GET);
	            $shorting = $_GET['order'];
				$pageLimit = $_GET['limit'];
				$comment_Status = $_GET['status'];
                /* $Formjsondata = $FormiD[4];
                $Formjsondata = explode("=",$Formjsondata);
				$afterdate    = $Formjsondata[1];
				$shorting     = $Formjsondata[2];
				$pageLimit    = $Formjsondata[3];
				$Formjsondata = explode("&",$shorting);
                $shorting     = $Formjsondata[0];
				$afterdate    = explode("&",$afterdate); */
				//$post_date    = $afterdate[0];
				//exit;
                $appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
                $table_name = $appyconnector->prefix.'comments';
				
				$query = "SELECT * FROM $table_name WHERE $table_name.comment_approved = $comment_Status AND $table_name.comment_date > '".$post_date."' ORDER BY $table_name.comment_date $shorting LIMIT 0, $pageLimit";

                $list = $wpdb->get_results($query);
                $result=array("timezone"=>get_option('timezone_string'),"data"=>$list);
				return $result;
		
        return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'comment'));
		 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
				
   }
   
   public function get_post_list(WP_REST_Request $request){
     global $wpdb;
   foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		if(!empty($request['post_id'])){
			$args = array(
			 'post_id' => $request['post_id']  
		    );
		}
        $comments = get_comments($args);
		if(count($comments)>0){
			return rest_ensure_response($comments);
		}
        return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'comment'));
		 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
			));
		}			
   }
   public function comment_detail($request){
     global $wpdb;
   foreach($_SERVER as $name => $value) {
		if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
		 }
		}
	   $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		$my_id = $request['id'];
		if(!empty($my_id)){
           $commentdetail = get_comment($my_id, ARRAY_A );
		   return rest_ensure_response($commentdetail);
		}
		return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'comment'));
		 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
   }
   
   public function delete_comment($request){
    $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		$deleteUser = get_user_by('id', $request['user_id']);
		$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
		$comment_id = $request['id']; 
		$force_delete = false; 
		if(!empty($comment_id)){
           $result = wp_delete_comment($comment_id, $force_delete);
		   if($result){
			  return new WP_Error(200, sprintf(__('comment is deleted', 'WordPress'), 'comment'));
		   }
		}
		return new WP_Error(404, sprintf(__('Sorry no records found', 'WordPress'), 'comment'));
		
		 }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
   }
   
   function attach_product_thumbnail($post_id, $url, $flag){
   
    	$image_url = $url;
	    $url_array = explode('/',$url);
	    $image_name = $url_array[count($url_array)-1];
	    $image_data = file_get_contents($image_url);

        $allowedExts = array("png", "jpg", "jpeg");
        $temp = explode(".", $image_name);
        $extension = end($temp);          

	    $upload_dir = wp_upload_dir(); // Set upload folder
	    $unique_file_name = wp_unique_filename( $upload_dir['path'], $image_name ); //    Generate unique name
	    $filename = basename( $unique_file_name ); // Create image file name
	    // Check folder permission and define file location
	    if( wp_mkdir_p($upload_dir['path'] ) ) {
	        $file = $upload_dir['path'] . '/' . $filename;
	    } else {
	        $file = $upload_dir['basedir'] . '/' . $filename;
	    }
	    // Create the image file on the server
	    file_put_contents( $file, $image_data );
	    // Check image file type
	    $wp_filetype = wp_check_filetype( $filename, null );
	    // Set attachment data
	    $attachment = array(
	        'post_mime_type' => $wp_filetype['type'],
	        'post_title' => sanitize_file_name( $filename ),
	        'post_content' => '',
	        'post_status' => 'inherit'
	    );
	    // Create the attachment
	    $attach_id = wp_insert_attachment( $attachment, $file, $post_id );
	    // Include image.php
	    require_once(ABSPATH . 'wp-admin/includes/image.php');
	    // Define attachment metadata
	    $attach_data = wp_generate_attachment_metadata( $attach_id, $file );
	    // Assign metadata to attachment
	    wp_update_attachment_metadata( $attach_id, $attach_data );
	    // asign to feature image
	    if( $flag == 0){
	        // And finally assign featured image to post
	        set_post_thumbnail( $post_id, $attach_id );
	    }
	  }
}
$commonAPI = new wpCommonAPI();

add_action( 'init', 'better_rest_api_featured_images_init', 12 );
/**
 * Register our enhanced better_featured_image field to all public post types
 * that support post thumbnails.
 *
 * @since  1.0.0
 */
function better_rest_api_featured_images_init() {

	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	foreach ( $post_types as $post_type ) {

		$post_type_name     = $post_type->name;
		$show_in_rest       = ( isset( $post_type->show_in_rest ) && $post_type->show_in_rest ) ? true : false;
		$supports_thumbnail = post_type_supports( $post_type_name, 'thumbnail' );

		// Only proceed if the post type is set to be accessible over the REST API
		// and supports featured images.
		if ( $show_in_rest && $supports_thumbnail ) {

			// Compatibility with the REST API v2 beta 9+
			if ( function_exists( 'register_rest_field' ) ) {
				register_rest_field( $post_type_name,
					'better_featured_image',
					array(
						'get_callback' => 'better_rest_api_featured_images_get_field',
						'schema'       => null,
					)
				);
			} elseif ( function_exists( 'register_api_field' ) ) {
				register_api_field( $post_type_name,
					'better_featured_image',
					array(
						'get_callback' => 'better_rest_api_featured_images_get_field',
						'schema'       => null,
					)
				);
			}
		}
	}
}

/**
 * Return the better_featured_image field.
 *
 * @since   1.0.0
 *
 * @param   object  $object      The response object.
 * @param   string  $field_name  The name of the field to add.
 * @param   object  $request     The WP_REST_Request object.
 *
 * @return  object|null
 */
function better_rest_api_featured_images_get_field( $object, $field_name, $request ) {

	// Only proceed if the post has a featured image.
	if ( ! empty( $object['featured_media'] ) ) {
		$image_id = (int)$object['featured_media'];
	} elseif ( ! empty( $object['featured_image'] ) ) {
		// This was added for backwards compatibility with < WP REST API v2 Beta 11.
		$image_id = (int)$object['featured_image'];
	} else {
		return null;
	}

	$image = get_post( $image_id );

	if ( ! $image ) {
		return null;
	}

	// This is taken from WP_REST_Attachments_Controller::prepare_item_for_response().
	$featured_image['id']            = $image_id;
	$featured_image['alt_text']      = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
	$featured_image['caption']       = $image->post_excerpt;
	$featured_image['description']   = $image->post_content;
	$featured_image['media_type']    = wp_attachment_is_image( $image_id ) ? 'image' : 'file';
	$featured_image['media_details'] = wp_get_attachment_metadata( $image_id );
	$featured_image['post']          = ! empty( $image->post_parent ) ? (int) $image->post_parent : null;
	$featured_image['source_url']    = wp_get_attachment_url( $image_id );

	if ( empty( $featured_image['media_details'] ) ) {
		$featured_image['media_details'] = new stdClass;
	} elseif ( ! empty( $featured_image['media_details']['sizes'] ) ) {
		$img_url_basename = wp_basename( $featured_image['source_url'] );
		foreach ( $featured_image['media_details']['sizes'] as $size => &$size_data ) {
			$image_src = wp_get_attachment_image_src( $image_id, $size );
			if ( ! $image_src ) {
				continue;
			}
			$size_data['source_url'] = $image_src[0];
		}
	} elseif ( is_string( $featured_image['media_details'] ) ) {
		// This was added to work around conflicts with plugins that cause
		// wp_get_attachment_metadata() to return a string.
		$featured_image['media_details'] = new stdClass();
		$featured_image['media_details']->sizes = new stdClass();
	} else {
		$featured_image['media_details']['sizes'] = new stdClass;
	}

	return apply_filters( 'better_rest_api_featured_image', $featured_image, $image_id );
}

add_action( 'rest_api_init', function () {
register_rest_route( 'wp/v3', '/allposttype/', array(
'methods' => 'GET',
'permission_callback' => '__return_true',
'callback' => 'get_allposttype'
) );
} );

function get_allposttype(){
   global $wpdb;
   foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
				$headers[$name] = $value;
			}
		}
        $username = $headers['Username'];
		$password = $headers['Password'];
		$user = get_user_by('login', $username);
		$user_info = get_userdata($user->ID);
		if(!empty($user_info)){
		 $userRole = implode(', ', $user_info->roles);
		}
		$response = array();
		@$deleteUser = get_user_by('id', $request['user_id']);
		@$seconduserInfo = get_userdata($request['user_id']);
		$table_name = $wpdb->prefix . 'users';

		@$user_id = $request['user_id'];
		if (!empty($username && $password))
		{
			
			if ($userRole == 'administrator')
			 {
			    $table_name = $wpdb->prefix . 'posts';
				$query ="SELECT DISTINCT post_type FROM `$table_name` WHERE 1";
				$list = $wpdb->get_results($query);
				return $list; 	

			  }
			}
			else
			{
				return new WP_Error('Error', 'You must Enter specify a valid username and password.', array(
					'status' => 400
				));
		}
   }
 
   add_action('init', 'wpformsappyconnector_db_init');

function wpformsappyconnector_db_init(){
    if( is_admin() ){
        require_once 'inc/class-main-page.php';

        if( isset($_REQUEST['wpformsappyconnector-csv']) && ( $_REQUEST['wpformsappyconnector-csv'] == true ) && isset( $_REQUEST['nonce'] ) ) {

            $nonce  = filter_input( INPUT_GET, 'nonce', FILTER_SANITIZE_STRING );

            if ( ! wp_verify_nonce( $nonce, 'dnonce' ) ) wp_die('Invalid nonce..!!');
            $csv = new wpformsappyconnector_Export_CSV();
            $csv->download_csv_file();
        }
        new wpformsappyconnectorDB_Wp_Main_Page;
    }
}

// Start WPForm data Api

function wpformsappyconnectorDB_create_table(){

    global $wpdb;
    $wpformsappyconnector       = apply_filters( 'wpformsappyconnectorDB_database', $wpdb );
    $table_name = $wpformsappyconnector->prefix.'wpformsappyconnector_db';

    if( $wpformsappyconnector->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {

        $charset_collate = $wpformsappyconnector->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            form_id bigint(20) NOT NULL AUTO_INCREMENT,
            form_post_name varchar(100) NOT NULL,
            form_post_id bigint(20) NOT NULL,
            form_value longtext NOT NULL,
            form_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (form_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    add_option( 'wpformsappyconnectorDB_view_install_date', date('Y-m-d G:i:s'), '', 'yes');

}

function wpformsappyconnectorDB_on_activate( $network_wide ){

    global $wpdb;
    if ( is_multisite() && $network_wide ) {
        // Get all blogs in the network and activate plugin on each one
        $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            wpformsappyconnectorDB_create_table();
            restore_current_blog();
        }
    } else {
        wpformsappyconnectorDB_create_table();
    }

	// Add custom capability
	$role = get_role( 'administrator' );
	$role->add_cap( 'wpformsappyconnectorDB_access' );
}

register_activation_hook( __FILE__, 'wpformsappyconnectorDB_on_activate' );

function wpformsappyconnectorDB_on_deactivate() {

	// Remove custom capability from all roles
	global $wp_roles;

	foreach( array_keys( $wp_roles->roles ) as $role ) {
		$wp_roles->remove_cap( $role, 'wpformsappyconnectorDB_access' );
	}
}

register_deactivation_hook( __FILE__, 'wpformsappyconnectorDB_on_deactivate' );

function wpformsappyconnectorDB_save( $fields, $entry, $form_id ) {

    global $wpdb;
    $wpformsappyconnector          = apply_filters( 'wpformsappyconnectorDB_database', $wpdb );
    $table_name    = $wpformsappyconnector->prefix.'wpformsappyconnector_db';
    $upload_dir    = wp_upload_dir();

        $wpdb_object = $wpformsappyconnector;
        $lastResult = $wpdb_object->last_result[0];
        $post_title = $lastResult->post_title;
       
    if ( $fields ) {

		$data           = $fields;
        $uploaded_files = array();

        $form_data   = array();

        $form_data['wpformsappyconnectorDB_status'] = 'unread';
        foreach ($data as $key => $d) {

            $d['value'] = is_array( $d['value'] ) ? implode(',', $d['value']) : $d['value'];

            $bl   = array('\"',"\'",'/','\\','"',"'");
            $wl   = array('&quot;','&#039;','&#047;', '&#092;','&quot;','&#039;');
            $d['value'] = str_replace($bl, $wl, $d['value'] );

            $form_data[ $d['name'] ] = $d['value'];       
        } 

        /* wpformsappyconnectorDB before save data. */
        $form_data = apply_filters('wpformsappyconnectorDB_before_save_data', $form_data);

        do_action( 'wpformsappyconnectorDB_before_save_data', $form_data );

        $form_post_id = $form_id;
        $form_value   = $form_data;
        $form_date    = current_time('Y-m-d H:i:s');
		$getformname = $wpdb->get_results("SELECT * FROM up_posts WHERE ID = $form_id and post_type = 'wpforms' limit 1");
        $form_post_name = $post_title; //get_the_title();

         $wpformsappyconnector->insert( $table_name, array(
            'form_post_id' => $form_post_id,
            'form_post_name' => $form_post_name,
            'form_value'   => json_encode($form_value, true),
            'form_date'    => $form_date
        ) );
        /* wpformsappyconnectorDB after save data */
        $insert_id = $wpformsappyconnector->insert_id;
        do_action( 'wpformsappyconnectorDB_after_save_data', $insert_id );
    }

}

add_action( 'wpforms_process_entry_save',  'wpformsappyconnectorDB_save', 10, 3 );

/**
 * Plugin settings link
 * @param  array $links list of links
 * @return array of links
 */
function wpformsappyconnectordb_settings_link( $links ) {
    $forms_link = '<a href="admin.php?page=wp-forms-db-list.php">wpformsappyconnector connector</a>';
    array_unshift($links, $forms_link);
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wpformsappyconnectordb_settings_link' );

add_action( 'admin_notices', 'wpformsappyconnectordb_admin_notice' );
add_action('admin_init', 'wpformsappyconnectordb_view_ignore_notice' );

function wpformsappyconnectordb_admin_notice() {

    $install_date = get_option( 'wpformsappyconnectorDB_view_install_date', '');
    $install_date = date_create( $install_date );
    $date_now     = date_create( date('Y-m-d G:i:s') );
    $date_diff    = date_diff( $install_date, $date_now );

    if ( $date_diff->format("%d") < 7 ) {

        return false;
    }

    if ( ! get_option( 'wpformsappyconnectordb_view_ignore_notice' ) ) {

        echo '<div class="updated"><p>';

        printf(__( 'Awesome, you\'ve been using <a href="admin.php?page=wp-forms-db-list.php">wpformsappyconnector Connector</a> for more than 1 week. May we ask you to give it a 5-star rating on WordPress? | <a href="%2$s" target="_blank">Ok, you deserved it</a> | <a href="%1$s">I already did</a> | <a href="%1$s">No, not good enough</a>', 'wpformsappyconnectordb' ), '?page=wp-forms-db-list.php&wpformsappyconnectordb-ignore-notice=0',
        'https://wordpress.org/plugins/database-for-wpformsappyconnector/');
        echo "</p></div>";
    }
} 

function wpformsappyconnectordb_view_ignore_notice() {

    if ( isset($_GET['wpformsappyconnectordb-ignore-notice']) && '0' == $_GET['wpformsappyconnectordb-ignore-notice'] ) {

        update_option( 'wpformsappyconnectordb_view_ignore_notice', 'true' );
    }
}

// Strat Form data api

class wpformList_Product_Stock_Rest_Server extends WP_REST_Controller {
	private $api_namespace;
	private $base;
	private $api_version;
	private $required_capability;
	
	public function __construct() {
		$this->api_namespace = 'wpform/v';
		$this->base = 'jsondada';
		$this->api_version = '2';
		$this->required_capability = 'read';  // Minimum capability to use the endpoint
		
		$this->init();
	}

public function register_routes() {
    $namespace = $this->api_namespace . $this->api_version;
    
    register_rest_route( $namespace, '/' . $this->base .'/(?P<id>\d+)', array(
        array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'callback' => array( $this, 'wpForm_data_api' ), ),
    )  );
}
// Register our REST Server
public function init(){
    add_action( 'rest_api_init', array( $this, 'register_routes' ) );
}
public function wpForm_data_api( WP_REST_Request $request ){

    $creds = array();
    $response = array();
    $headers = array();
    foreach($_SERVER as $name => $value) {
        if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
        $headers[$name] = $value;
        }
    }
    $username = $headers['Username'];
    $password = $headers['Password'];

    // Get username and password from the submitted headers.
    if ( array_key_exists( 'Username', $headers ) && array_key_exists( 'Password', $headers ) ) {
        $creds['user_login'] = $username;
        $creds['user_password'] =  $password;
        $creds['remember'] = false;
        $user = get_user_by('login', $username);// Verify the user.
        //$user = wp_signon( $creds, false );  // Verify the user.
        $result = $user && wp_check_password($password, $user->data->user_pass, $user->ID);
         if(!$result){
              $response['code'] = "invalid_username";
              $response['message'] = __("Unknown username. Check again or try your email address.", "wp-rest-user");
              $response['data'] = null;
              return $response;
         }
        // message reveals if the username or password are correct.
        if ( is_wp_error($user)) {
            return $user;
        }
        
        wp_set_current_user( $user->ID, $user->user_login );
        
        // A subscriber has 'read' access so a very basic user account can be used.
        if ( ! current_user_can( $this->required_capability ) ) {
            return new WP_Error( 'rest_forbidden', 'You do not have permissions to view this data.', array( 'status' => 401 ) );
        }
            global $wpdb;
            $uri = $_SERVER['REQUEST_URI'];
            $FormiD = (explode("/wp-", $uri));
            $FormiDjson =  $FormiD[1];
            $FormiD = (explode("/", $FormiDjson));

            if (isset($_GET['from_date'])){
                $datetime = new DateTime($_GET['from_date']);
                $la_time = new DateTimeZone(get_option('timezone_string'));
                $datetime->setTimezone($la_time);
                $from_date= $datetime->format('Y-m-d H:i:s');
            }else{
                $from_date=date("Y-m-d H:i:s");
            }
            $Formjsondata = $FormiD[4];
            $Formjsondata=explode("?",$Formjsondata);
            $Formjsondata=$Formjsondata[0];
            $appyconnector  = apply_filters( 'wpformsappyconnectorDB_database', $wpdb );
            $table_name = $appyconnector->prefix.'wpformsappyconnector_db';
            $query = "SELECT * FROM $table_name WHERE form_post_id = $Formjsondata and form_date > '".$from_date."'";
            $list = $wpdb->get_results($query);
            $result=array("timezone"=>get_option('timezone_string'),"data"=>$list);
            return $result;
    }
    else {
    return new WP_Error( 'invalid-method', 'You must specify a valid username and password.', array( 'status' => 400  
     ) ); //Bad Request
    }
 } 
}
$lps_rest_server = new wpformList_Product_Stock_Rest_Server();

// Start form list API

class wp_formappyconnectot_list extends WP_REST_Controller {
	private $apinamespace;
	private $baseurl;
	private $apiversion;
	private $requiredcapability;
	
	public function __construct() {
		$this->apinamespace = 'wpform/v';
		$this->baseurl = 'data';
		$this->apiversion = '2';
		$this->requiredcapability = 'read';  // Minimum capability to use the endpoint
		
		$this->init();
	}
	public function register_routes() {
		$namespace = $this->apinamespace . $this->apiversion;

		register_rest_route( $namespace, '/' . $this->baseurl , array(
	array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'callback' => array( $this, 'Form_list' ), ),
		)  );
	}
	// Register our REST Server
	public function init(){
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	public function Form_list( WP_REST_Request $request ){
		
		$creds = array();
		$response = array();
		$headers = array();
		foreach($_SERVER as $name => $value) {
			if($name != 'HTTP_MOD_REWRITE' && (substr($name, 0, 5) == 'HTTP_' || $name == 'CONTENT_LENGTH' || $name == 'CONTENT_TYPE')) {
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', str_replace('HTTP_', '', $name)))));
			$headers[$name] = $value;
			}
		}
		
		$username = $headers['Username'];
		$password = $headers['Password'];
		// Get username and password from the submitted headers.
		if ( array_key_exists( 'Username', $headers ) && array_key_exists( 'Password', $headers ) ) {
			$creds['user_login'] = $username;
			$creds['user_password'] =  $password;
			$creds['remember'] = false;
			$user = get_user_by('login', $username);// Verify the user.
			
			 $result = $user && wp_check_password($password, $user->data->user_pass, $user->ID);
			 if(!$result){
				  $response['code'] = "invalid_username";
				  $response['message'] = __("Unknown username. Check again or try your email address.", "wp-rest-user");
				  $response['data'] = null;
				  return $response;
			 }
                    
			wp_set_current_user( $user->ID, $user->user_login );
			
			// A subscriber has 'read' access so a very basic user account can be used.
			if ( ! current_user_can( $this->requiredcapability ) ) {
				return new WP_Error( 'rest_forbidden', 'You do not have permissions to view this data.', array( 'status' => 401 ) );
			}
			
			global $wpdb;
			$appyconnector  = apply_filters( 'Appyconnect_database', $wpdb );
			$table_name = $appyconnector->prefix.'posts';
			$query = "SELECT ID, post_title FROM $table_name WHERE 1=1 AND $table_name.post_type = 'wpforms' AND ($table_name.post_status = 'publish') ORDER BY $table_name.post_date DESC";
			$list = $wpdb->get_results($query);
			return $list;
		}
		else {
			return new WP_Error( 'invalid-method', 'You must specify a valid username and password.', array( 'status' => 400  
		 ) ); //Bad Request
		}
	}
	
}
 
$lps_rest_server = new wp_formappyconnectot_list();