<?php
/**
 * Plugin Name: Random Dummy Author Assigner
 * Description: Automatically assigns new posts to randomly selected dummy authors.
 * Version: 1.0
 * Author: riotrequest
 */

// Hook into the 'save_post' action to change the post author upon publishing
add_action('save_post', 'assign_random_dummy_author', 10, 3);

function assign_random_dummy_author($post_id, $post, $update) {
    // Check if this is a new post and not an update
    if ($update) {
        return;
    }

    // Define your dummy author IDs
    $dummy_author_ids = [3, 4, 5]; // Replace with actual user IDs of your dummy authors

    // Select a random author ID
    $random_author_id = $dummy_author_ids[array_rand($dummy_author_ids)];

    // Update the post author
    wp_update_post([
        'ID' => $post_id,
        'post_author' => $random_author_id
    ]);
}
