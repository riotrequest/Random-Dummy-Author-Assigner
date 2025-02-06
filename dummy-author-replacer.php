<?php
/**
 * Plugin Name: Riot Authors
 * Description: This plugin automatically assigns new posts to randomly selected authors and provides functionality to add dummy authors.
 * Version: 1.2
 * Author: riotrequest
 */

// Enqueue admin styles and scripts for the plugin
add_action('admin_enqueue_scripts', 'riot_authors_enqueue_admin_styles_and_scripts');
function riot_authors_enqueue_admin_styles_and_scripts($hook) {
    // Only enqueue on our plugin's admin page
    if ($hook != 'toplevel_page_riot-authors') {
        return;
    }
    wp_enqueue_style('riot-authors-admin-style', plugin_dir_url(__FILE__) . 'riot-authors-admin-style.css');
    wp_enqueue_script('riot-authors-admin-script', plugin_dir_url(__FILE__) . 'riot-authors-admin-script.js', array('jquery'), '1.0.0', true);
}

// Add a new page to the admin menu
add_action('admin_menu', 'riot_authors_admin_page');
function riot_authors_admin_page() {
    add_menu_page(
        'Riot Authors',
        'Riot Authors',
        'manage_options',
        'riot-authors',
        'riot_authors_admin_page_callback',
        'dashicons-admin-users',
        3
    );
}

// Render the admin page
function riot_authors_admin_page_callback() {
    ?>
    <div class="wrap">
        <h1 class="admin-page-title">Riot Authors Plugin</h1>
        <div id="message-area"></div> <!-- Area to display messages -->
        <div class="admin-page-content">
            <?php
            $dummy_authors = get_users(['role' => 'dummy_author']);
            if (!empty($dummy_authors)) {
                foreach ($dummy_authors as $author) {
                    echo '<div id="author-' . esc_attr($author->ID) . '" class="author-item">';
                    echo '<span>' . esc_html($author->display_name) . '</span>';
                    echo '<button class="delete-button" data-id="' . esc_attr($author->ID) . '" data-delete_author_nonce="' . wp_create_nonce('delete_author_nonce') . '">Delete</button>';
                    echo '</div>';
                }
            } else {
                echo '<p>No dummy authors found.</p>';
            }
            ?>
            <form id="add-author-form" method="post" action="">
                <input type="text" id="new_author_name" name="new_author_name" placeholder="Enter new author name" required>
                <input type="submit" value="Add Author">
                <?php wp_nonce_field('add_author_nonce', 'add_author_nonce_field'); ?>
            </form>
        </div>
        <div class="plugin-description">
            This plugin is designed to make it appear as though you have a busy blog with multiple authors.
            Here you can create "dummy authors" and when a new post is published, it's randomly assigned to one of them.
            When you delete an author from here their posts are drafted and assigned to the user you are logged in as.
        </div>
    </div>
    <?php
}

// AJAX handler for adding a new author
add_action('wp_ajax_add_new_author', 'add_new_author_callback');
function add_new_author_callback() {
    // Capability check
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(array('message' => 'Insufficient permissions.'));
    }
    check_ajax_referer('add_author_nonce', 'nonce');

    $new_author_name = sanitize_text_field($_POST['new_author_name']);
    // Insert the user as a dummy_author with a random password
    $new_author_id = wp_insert_user([
        'user_login' => $new_author_name,
        'user_pass' => wp_generate_password(),
        'role' => 'dummy_author'
    ]);

    if (is_wp_error($new_author_id)) {
        wp_send_json_error(array('message' => $new_author_id->get_error_message()));
    } else {
        wp_send_json_success(array('author_id' => $new_author_id, 'author_name' => $new_author_name));
    }
    wp_die();
}

// AJAX handler for deleting an author
add_action('wp_ajax_delete_author', 'delete_author_callback');
function delete_author_callback() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(array('message' => 'Insufficient permissions.'));
    }
    check_ajax_referer('delete_author_nonce', 'nonce');

    $user_id = intval($_POST['user_id']);
    $current_user_id = get_current_user_id();

    // Get all posts by the author to be deleted
    $author_posts = get_posts(['author' => $user_id, 'numberposts' => -1]);
    // Update each post: set status to draft and assign to the current user
    foreach ($author_posts as $post) {
        wp_update_post([
            'ID' => $post->ID,
            'post_status' => 'draft',
            'post_author' => $current_user_id
        ]);
    }
    if (wp_delete_user($user_id)) {
        wp_send_json_success(array('user_id' => $user_id, 'message' => 'Author deleted and posts drafted to current user.'));
    } else {
        wp_send_json_error(array('message' => 'Failed to delete user.'));
    }
    wp_die();
}

// Hook into 'save_post' to assign a random dummy author when a new post is created
add_action('save_post', 'assign_random_author', 10, 3);
function assign_random_author($post_id, $post, $update) {
    // Avoid processing during autosave, revision, or if not a "post" type
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }
    if ('post' !== $post->post_type) {
        return;
    }
    // Only assign author on initial creation, not on updates
    if ($update) {
        return;
    }

    // Get dummy authors; if none, do nothing
    $dummy_authors = get_users(['role' => 'dummy_author']);
    if (empty($dummy_authors)) {
        return;
    }
    // Extract IDs from dummy authors
    $author_ids = array_map(function($user) {
        return $user->ID;
    }, $dummy_authors);

    // Select a random author ID
    $random_author_id = $author_ids[array_rand($author_ids)];

    // Update the post author without causing recursive loops
    remove_action('save_post', 'assign_random_author', 10);
    wp_update_post([
        'ID' => $post_id,
        'post_author' => $random_author_id
    ]);
    add_action('save_post', 'assign_random_author', 10, 3);
}
