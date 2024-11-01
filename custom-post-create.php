<?php
/**
 * Endpoint Create Custom Post
 */

if (!defined('ABSPATH')) exit;

// Register custom API endpoint for creating posts
add_action('rest_api_init', function () {
    // Create new post API
    register_rest_route('wp/v3', 'posts/create', array(
        'methods' => 'POST',
        'callback' => 'handle_create_post_request',
        'permission_callback' => 'authenticationUserPermission',
    ));
});


// Permission callback function
function authenticationUserPermission($request) {
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

    if (!empty($user_info)) {
        $userRole = implode(', ', $user_info->roles);

        if ($userRole == 'administrator' && wp_check_password($password, $user->data->user_pass, $user->ID)) {
            return true;
        }
    }

    return new WP_Error('rest_forbidden', esc_html__('Invalid username or password.', 'text-domain'), array('status' => 401));
}

// Callback function for creating posts
function handle_create_post_request($data) {
    // Get dynamic title, content, URL, Meta Title, Meta Description, and Blog Title from request parameters
    $new_post_title = isset($data['title']) ? sanitize_text_field($data['title']) : 'New Post';
    $new_post_content = isset($data['content']) ? wp_kses_post($data['content']) : 'This is the content of the new post.';
    $custom_permalink = isset($data['custom_permalink']) ? sanitize_title($data['custom_permalink']) : '';
    $new_post_meta_title = isset($data['meta_title']) ? sanitize_text_field($data['meta_title']) : '';
    $new_post_meta_description = isset($data['meta_description']) ? sanitize_text_field($data['meta_description']) : '';
    $new_post_blog_title = isset($data['blog_title']) ? sanitize_text_field($data['blog_title']) : '';

    // Get dynamic values for category, author name, and slug
    $category_name = isset($data['category_name']) ? sanitize_text_field($data['category_name']) : '';
    $slug_name = isset($data['slug_name']) ? sanitize_title($data['slug_name']) : '';

    // Get dynamic values for author name
    $author_name = isset($data['author_name']) ? sanitize_text_field($data['author_name']) : '';


    // Create a new post
    $new_post = array(
        'post_title'    => $new_post_title,
        'post_content'  => $new_post_content,
        'post_status'   => 'Draft',
        'post_type'     => 'post',
        'post_author'   => 1,
        'post_name'     => $slug_name,
    );

    // Insert the post into the database
    $new_post_id = wp_insert_post($new_post);

    if ($new_post_id) {
        // Update additional post data
        update_post_meta($new_post_id, '_meta_title', $new_post_meta_title);
        update_post_meta($new_post_id, '_meta_description', $new_post_meta_description);
        update_post_meta($new_post_id, '_blog_title', $new_post_blog_title);

        // Handle Featured Image
        $featured_image_url = isset($data['featured_image_url']) ? esc_url($data['featured_image_url']) : '';

        if (class_exists('WPSEO_Meta')) {
            $seo_data = array(
                'title'       => $new_post_meta_title,
                'metadesc'    => $new_post_meta_description,
                
            );
        
            // Use the Yoast SEO function to set meta data
            add_post_meta($new_post_id, '_yoast_wpseo_title', $seo_data['title'], true);
            add_post_meta($new_post_id, '_yoast_wpseo_metadesc', $seo_data['metadesc'], true);
            
        }

       // Update the post_name field (slug) to set the custom permalink
       if ($custom_permalink) {
        $post_data = array(
            'ID'         => $new_post_id,
            'post_name'  => $custom_permalink,
        );

        wp_update_post($post_data);

        /// Use custom_permalink as the slug_name
        $post_url = home_url('/') . $custom_permalink;
        }
        else {
            // Get the post URL based on the post's slug
            $post_url = get_permalink($new_post_id);
            $post_url .= '&preview=true';
        }

        // Category
        if ($category_name) {
            $category = get_category_by_slug($category_name);

            if ($category) {
                // Category found, get the category ID
                $category_id = $category->term_id;

                // Get all parent and child category IDs
                $all_category_ids = get_ancestors($category_id, 'category');
                $all_category_ids[] = $category_id;

                // Set post categories to include both the selected category and its parents (if any)
                wp_set_post_categories($new_post_id, $all_category_ids);
            } else {
                // Category not found, handle this case as needed
                // For now, set the post to the default category ('blog')
                $default_category = get_category_by_slug('blog');
                $default_category_id = $default_category ? $default_category->term_id : 0;

                wp_set_post_categories($new_post_id, array($default_category_id));
            }
        } else {
            // No category selected, set the post to the default category ('blog')
            $default_category = get_category_by_slug('blog');
            $default_category_id = $default_category ? $default_category->term_id : 0;

            wp_set_post_categories($new_post_id, array($default_category_id));
        }


        // Dynamic Author Name
        if ($author_name) {
            $author = get_user_by('login', $author_name);

            if ($author) {
                // Author found, update the post with the author ID
                $author_id = $author->ID;
                wp_update_post(array('ID' => $new_post_id, 'post_author' => $author_id));
            } else {
                // Author not found, you can handle this case as needed
                // For now, we'll set the post_author to the default user (ID: 1)
                wp_update_post(array('ID' => $new_post_id, 'post_author' => 1));
            }
        }

        // Check if a featured image URL is provided
        if ($featured_image_url) {
            // Download the image and upload it to the media library
            $image_id = handle_featured_image_upload($featured_image_url, $new_post_title);
            
            if ($image_id) {
                // Set the uploaded image as the featured image for the post
                set_post_thumbnail($new_post_id, $image_id);
            }
        }

        // Return a JSON response indicating success
        return rest_ensure_response(array('message' => 'Post created successfully', 'new_page_id' => $new_post_id, 'post_url' => $post_url));
    } else {
        // Return a JSON response indicating failure
        return rest_ensure_response(array('error' => 'Failed to create a new post'));
    }
}


function handle_featured_image_upload($image_url, $post_title) {
    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($image_url);
    $filename = sanitize_file_name($post_title) . '_' . wp_rand() . '.jpg';
    $upload_file = wp_upload_bits($filename, null, $image_data);

    if (!$upload_file['error']) {
        $attachment = array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => sanitize_text_field($post_title),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $upload_file['file']);
        if (!is_wp_error($attachment_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);

            return $attachment_id;
        }
    }

    return false;
}