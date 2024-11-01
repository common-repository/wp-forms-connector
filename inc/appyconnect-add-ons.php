<?php

add_submenu_page('appyconnect-list.php', 'Extensions', 'Connect Extensions', 'manage_options', 
	'appyconnect-extensions',  'appyconnect_extensions' );


/**
 * Extensions page
 */
function appyconnect_extensions(){
    ?>
    <div class="wrap">
        <h2><?php _e( 'Extensions for Appyconnect', 'contact-form-appyconnect' ); ?>
            <span>
                <a class="button-primary" href="https://www.appypie.com/connect/"><?php _e( 'Browse All Extensions', 'contact-form-appyconnect' ); ?></a>
            </span>
        </h2>
        <?php echo connect_add_ons_get_feed(); ?>
    </div>
    <?php
}

/**
 * Add-ons Get Feed
 *
 * Gets the add-ons page feed.
 *
 * @since 1.0
 * @return void
 */
function connect_add_ons_get_feed(){
	$cache = get_transient( 'connect_add_ons_feed' );
	if ( false === $cache ) {
		$url = 'https://www.appypie.com/connect/';
		$feed = wp_remote_get( esc_url_raw( $url ), array( 'sslverify' => false ) );
		if ( ! is_wp_error( $feed ) ) {
			if ( isset( $feed['body'] ) && strlen( $feed['body'] ) > 0 ) {
				$cache = wp_remote_retrieve_body( $feed );
				set_transient( 'connect_add_ons_feed', $cache, 3600 );
			}
		} else {
		}
	}
	return $cache;
}
//delete_transient('connect_add_ons_feed');