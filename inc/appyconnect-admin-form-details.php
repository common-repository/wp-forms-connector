<?php

if (!defined( 'ABSPATH')) exit;

/**
*
*/
class AppyConnect_Form_Details
{
    private $form_id;
    private $form_post_id;


    public function __construct()
    {
       $this->form_post_id = esc_sql( $_GET['fid'] );
       $this->form_id = esc_sql( $_GET['ufid'] );

       $this->form_details_page();
    }

    public function form_details_page(){
        global $wpdb;
        $appyconnector          = apply_filters( 'Appyconnect_database', $wpdb );
        $table_name    = $appyconnector->prefix.'manage_forms';
        $upload_dir    = wp_upload_dir();
        $connect_dir_url = $upload_dir['baseurl'].'/connect_uploads';
        $rm_underscore = apply_filters('connect_remove_underscore_data', true); 


        if ( is_numeric($this->form_post_id) && is_numeric($this->form_id) ) {

           $results    = $appyconnector->get_results( "SELECT * FROM $table_name WHERE form_post_id = $this->form_post_id AND form_id = $this->form_id LIMIT 1", OBJECT );
        }

        if ( empty($results) ) {
            wp_die( $message = 'Not valid contact form' );
        }
        ?>
        <div class="wrap">
            <div id="welcome-panel" class="welcome-panel">
                <div class="welcome-panel-content">
                    <div class="welcome-panel-column-container">
					<table class="wp-list-table widefat fixed striped contact_forms">
                        <?php do_action('connect_before_formdetails_title',$this->form_post_id ); ?>
                        <h3><?php echo get_the_title( $this->form_post_id ); ?></h3>
                        <?php do_action('connect_after_formdetails_title', $this->form_post_id ); ?>
                        <p></span><?php echo $results[0]->form_date; ?></p>
                        <?php $form_data  = unserialize( $results[0]->form_value );

                        foreach ($form_data as $key => $data):

                            $matches = array();

                            if ( $key == 'connect_status' )  continue;
                            if( $rm_underscore ) preg_match('/^_.*$/m', $key, $matches);
                            if( ! empty($matches[0]) ) continue;

                            if ( strpos($key, 'connect_file') !== false ){

                                $key_val = str_replace('connect_file', '', $key);
                                $key_val = str_replace('your-', '', $key_val);
                                $key_val = ucfirst( $key_val );
                                echo '<p><b>'.$key_val.'</b>: <a href="'.$connect_dir_url.'/'.$data.'">'
                                .$data.'</a></p>';
                            }else{


                                if ( is_array($data) ) {

                                    $key_val = str_replace('your-', '', $key);
                                    $key_val = ucfirst( $key_val );
                                    $arr_str_data =  implode(', ',$data);
                                    $arr_str_data =  esc_html( $arr_str_data );
                                    echo '<p><b>'.$key_val.'</b>: '. nl2br($arr_str_data) .'</p>';

                                }else{

                                    $key_val = str_replace('your-', '', $key);
                                    $key_val = ucfirst( $key_val );
                                    $data    = esc_html( $data );
                                    echo '<tr><td><b>'.$key_val.'</b></td><td> '.nl2br($data).'</td></tr>';
                                }
                            }

                        endforeach;

                        //$form_data['connect_status'] = 'read';
                        $form_data = serialize( $form_data );
                        $form_id = $results[0]->form_id;

                        $appyconnector->query( "UPDATE $table_name SET form_value =
                            '$form_data' WHERE form_id = $form_id"
                        );
                        ?>
						</table>
						<p>&nbsp;</p><b><?php echo $link  = "<b><a href=admin.php?page=appyconnect-list.php&fid=%s&ufid=%s>Back to List Page</a></b>"; ?></b><p>&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
        do_action('connect_after_formdetails', $this->form_post_id );
    }

}
