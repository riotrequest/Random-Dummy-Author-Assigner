<?php
/**
 * Plugin Name: Riot Authors
 * Description: Create fictional byline profiles and randomly assign new posts on first publication. Retire profiles without deleting their posts.
 * Version: 2.0.0
 * Author: riotrequest
 * Plugin URI: https://github.com/riotrequest/Random-Dummy-Author-Assigner
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: riot-authors
 */
defined('ABSPATH') || exit;
define('RIOT_AUTHORS_VERSION', '2.0.0');

function riot_authors_install() {
    if (is_multisite()) { return; }
    add_role('dummy_author', 'Dummy Author', array());
    if (false === get_option('riot_authors_start_id', false)) {
        global $wpdb;
        add_option('riot_authors_start_id', (int) $wpdb->get_var("SELECT MAX(ID) FROM $wpdb->posts"), '', false);
    }
}
register_activation_hook(__FILE__, 'riot_authors_install');
add_action('init', 'riot_authors_install');

/** Exclusive dummy roles only: never operate on administrators or mixed-role users. */
function riot_authors_is_dummy($user) {
    return !is_multisite() && $user instanceof WP_User && array('dummy_author') === array_values($user->roles) && !is_super_admin($user->ID);
}

function riot_authors_eligible($user) {
    return riot_authors_is_dummy($user) && !array_filter(array_diff_key($user->allcaps, array('dummy_author' => true)));
}

function riot_authors_roster() {
    // ponytail: one capped query supports small demo rosters; add pagination if more than 100 profiles are needed.
    return get_users(array('role' => 'dummy_author', 'number' => 101, 'orderby' => 'ID', 'order' => 'ASC'));
}

function riot_authors_pool() {
    return array_values(array_filter(array_slice(riot_authors_roster(), 0, 100), function ($user) {
        return riot_authors_eligible($user) && !get_user_meta($user->ID, '_riot_authors_retired', true);
    }));
}

function riot_authors_enabled() { return '0' !== (string) get_option('riot_authors_enabled', '1'); }

/** Run after all ordinary insert metadata has been saved, including REST and scheduled publishing. */
function riot_authors_assign($post_id, $post, $update, $before) {
    static $assigning = false;
    if ($assigning || is_multisite() || 'post' !== $post->post_type || 'publish' !== $post->post_status || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post_id <= (int) get_option('riot_authors_start_id', 0)) { return; }
    $seen = metadata_exists('post', $post_id, '_riot_authors_seen_publish');
    add_post_meta($post_id, '_riot_authors_seen_publish', '1', true);
    if ($seen || ($before && 'publish' === $before->post_status) || !riot_authors_enabled() || metadata_exists('post', $post_id, '_riot_authors_assignment')) { return; }
    $pool = riot_authors_pool();
    if (!$pool) { return; }
    $chosen = $pool[wp_rand(0, count($pool) - 1)];
    $record = array('from' => (int) $post->post_author, 'to' => $chosen->ID, 'at' => time());
    if (!add_post_meta($post_id, '_riot_authors_assignment', $record, true)) { return; }
    $assigning = true;
    try {
        $result = wp_update_post(array('ID' => $post_id, 'post_author' => $chosen->ID), true);
        if (is_wp_error($result) || (int) get_post_field('post_author', $post_id) !== $chosen->ID) {
            delete_post_meta($post_id, '_riot_authors_assignment', $record);
        } else {
            // REST builds its response from this object after the hook; keep its author current.
            $post->post_author = $chosen->ID;
        }
    } finally { $assigning = false; }
}
add_action('wp_after_insert_post', 'riot_authors_assign', 20, 4);

// Dummy profiles are bylines, not sign-in accounts. Ordinary and promoted users are unaffected.
add_filter('wp_authenticate_user', function ($user) {
    return riot_authors_is_dummy($user) ? new WP_Error('riot_authors_no_login', 'This byline profile cannot sign in.') : $user;
});
add_filter('determine_current_user', function ($id) { return $id && riot_authors_is_dummy(get_userdata($id)) ? 0 : $id; }, 99);
add_filter('allow_password_reset', function ($allow, $id) { return riot_authors_is_dummy(get_userdata($id)) ? false : $allow; }, 10, 2);
add_filter('wp_is_application_passwords_available_for_user', function ($allow, $user) { return riot_authors_is_dummy($user) ? false : $allow; }, 10, 2);

/** All mutations share the same capability and input boundary, including direct test calls. */
function riot_authors_manage($input) {
    if (is_multisite() || !current_user_can('manage_options')) { return new WP_Error('riot_denied', 'Administrator permission on a single-site installation is required.'); }
    $action = isset($input['riot_authors_action']) && is_string($input['riot_authors_action']) ? $input['riot_authors_action'] : '';
    if ('settings' === $action) {
        if (!isset($input['enabled']) || !in_array($input['enabled'], array('0', '1'), true)) { return new WP_Error('riot_invalid', 'Choose an assignment mode.'); }
        update_option('riot_authors_enabled', $input['enabled'], false);
        return '1' === $input['enabled'] ? 'Automatic assignment enabled for future first publications.' : 'Assignment paused. Existing posts are unchanged.';
    }
    if (!in_array($action, array('create', 'edit', 'retire', 'activate'), true)) { return new WP_Error('riot_invalid', 'Unknown action.'); }
    if ('create' === $action) {
        if (!current_user_can('create_users')) { return new WP_Error('riot_denied', 'User creation permission is required.'); }
        if (count(riot_authors_roster()) >= 100) { return new WP_Error('riot_limit', 'The roster is limited to 100 profiles, including retired profiles.'); }
    } else {
        $id = isset($input['author_id']) && is_scalar($input['author_id']) ? absint($input['author_id']) : 0;
        $user = get_userdata($id);
        if (!riot_authors_eligible($user) || !current_user_can('edit_user', $id)) { return new WP_Error('riot_denied', 'Only unprivileged dummy profiles can be managed here.'); }
        if ('retire' === $action || 'activate' === $action) {
            update_user_meta($id, '_riot_authors_retired', 'retire' === $action ? '1' : '');
            return 'retire' === $action ? 'Profile retired. Existing bylines and post statuses are unchanged.' : 'Profile returned to the assignment pool.';
        }
    }
    $name = isset($input['display_name']) && is_string($input['display_name']) ? trim(sanitize_text_field($input['display_name'])) : '';
    $bio = isset($input['description']) && is_string($input['description']) ? sanitize_textarea_field($input['description']) : '';
    if ('' === $name || strlen($name) > 100 || strlen($bio) > 1000) { return new WP_Error('riot_invalid', 'Use a display name of 1–100 bytes and a bio of at most 1,000 bytes.'); }
    foreach (riot_authors_roster() as $existing) {
        if ((!isset($id) || $existing->ID !== $id) && 0 === strcasecmp($existing->display_name, $name)) { return new WP_Error('riot_duplicate', 'That display name already exists in the roster.'); }
    }
    if ('create' === $action) {
        $id = wp_insert_user(array('user_login' => 'riot_byline_' . strtolower(wp_generate_password(16, false)), 'user_pass' => wp_generate_password(64, true, true), 'display_name' => $name, 'nickname' => $name, 'user_nicename' => substr(sanitize_title($name), 0, 35) ?: 'riot-byline', 'description' => $bio, 'role' => 'dummy_author', 'show_admin_bar_front' => 'false'));
    } else {
        $id = wp_update_user(array('ID' => $id, 'display_name' => $name, 'nickname' => $name, 'description' => $bio));
    }
    if (is_wp_error($id)) { return $id; }
    return 'create' === $action ? 'Profile created and added to the active pool.' : 'Profile updated. Its name and bio also update existing bylines.';
}

function riot_authors_menu() {
    add_menu_page('Riot Authors', 'Riot Authors', 'manage_options', 'riot-authors', 'riot_authors_page', 'dashicons-admin-users', 80);
}
add_action('admin_menu', 'riot_authors_menu');

function riot_authors_assets($hook) {
    if ('toplevel_page_riot-authors' !== $hook) { return; }
    wp_enqueue_style('riot-authors', plugins_url('riot-authors-admin-style.css', __FILE__), array(), RIOT_AUTHORS_VERSION);
    wp_enqueue_script('riot-authors', plugins_url('riot-authors-admin-script.js', __FILE__), array(), RIOT_AUTHORS_VERSION, array('in_footer' => true, 'strategy' => 'defer'));
}
add_action('admin_enqueue_scripts', 'riot_authors_assets');

function riot_authors_fields($action, $id = null) {
    wp_nonce_field('riot_authors_manage');
    echo '<input type="hidden" name="riot_authors_action" value="' . esc_attr($action) . '">';
    if (null !== $id) { echo '<input type="hidden" name="author_id" value="' . esc_attr($id) . '">'; }
}

function riot_authors_page() {
    if (!current_user_can('manage_options')) { wp_die('Administrator permission required.'); }
    if (is_multisite()) { echo '<div class="wrap"><h1>Riot Authors</h1><p>This version supports single-site WordPress only. No profiles or posts are changed on multisite.</p></div>'; return; }
    $result = null;
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['riot_authors_action'])) {
        check_admin_referer('riot_authors_manage');
        $result = riot_authors_manage(wp_unslash($_POST));
    }
    $roster = riot_authors_roster();
    $pool = riot_authors_pool();
    $retired = count(array_filter($roster, function ($user) { return (bool) get_user_meta($user->ID, '_riot_authors_retired', true); }));
    $ids = array_map('intval', wp_list_pluck($roster, 'ID'));
    global $wpdb;
    $published = $ids ? $wpdb->get_results("SELECT post_author, COUNT(*) AS total FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' AND post_author IN (" . implode(',', $ids) . ') GROUP BY post_author', OBJECT_K) : array();
    $total = array_sum(array_map(function ($row) { return (int) $row->total; }, $published));
    ?>
    <div class="wrap ra-console" id="riot-authors-app">
        <header class="ra-hero"><div class="ra-eyebrow"><span class="ra-dot"></span> RIOTREQUEST / PUBLISHING TOOLS <span class="ra-version">v<?php echo esc_html(RIOT_AUTHORS_VERSION); ?></span></div><h1>Pick the pool.<span>Let publishing handle the rest.</span></h1><p>Create fictional author profiles. Randomly assign each new post on its first publication.</p><div class="ra-hero-bottom"><span class="ra-mode <?php echo riot_authors_enabled() && $pool ? 'is-on' : ''; ?>"><?php echo riot_authors_enabled() ? ($pool ? 'AUTO ASSIGNMENT ON' : 'WAITING FOR PROFILES') : 'ASSIGNMENT PAUSED'; ?></span><span>No rerolls on edits. No changes to old posts.</span></div></header>
        <?php if (null !== $result) : ?><div class="ra-feedback <?php echo is_wp_error($result) ? 'is-error' : ''; ?>" role="status"><?php echo esc_html(is_wp_error($result) ? $result->get_error_message() : $result); ?></div><?php endif; ?>
        <div class="ra-stats"><div><strong><?php echo count($pool); ?></strong><span>ACTIVE PROFILES</span></div><div><strong><?php echo (int) $retired; ?></strong><span>RETIRED PROFILES</span></div><div><strong><?php echo (int) $total; ?></strong><span>PUBLISHED POSTS BY ROSTER</span></div></div>
        <div class="ra-layout"><section class="ra-roster"><div class="ra-section-head"><div><span class="ra-kicker">01 / THE ROSTER</span><h2>Your byline pool</h2></div><span class="ra-count"><?php echo count($roster); ?> / 100 profiles</span></div><p class="ra-intro">Each active profile has an equal chance. Retiring one keeps its existing posts and bylines intact.</p>
        <?php if (count($roster) > 100) : ?><p class="ra-feedback is-error">This roster exceeds the supported 100 profiles. Only the first 100 by ID can be selected. Manage excess accounts through WordPress Users.</p><?php endif; ?>
        <div class="ra-cards">
        <?php if (!$roster) : ?><div class="ra-empty"><span>↗</span><h3>Start with a name.</h3><p>Add your first profile to build the pool. Until then, posts keep their original author.</p></div><?php endif; ?>
        <?php foreach (array_slice($roster, 0, 100) as $index => $user) : $inactive = (bool) get_user_meta($user->ID, '_riot_authors_retired', true); $eligible = riot_authors_eligible($user); $count = isset($published[$user->ID]) ? (int) $published[$user->ID]->total : 0; ?>
            <article class="ra-card <?php echo $inactive ? 'is-retired' : ''; ?>"><div class="ra-card-top"><span class="ra-avatar ra-tone-<?php echo $index % 4; ?>" aria-hidden="true"><?php echo esc_html(function_exists('mb_substr') ? mb_strtoupper(mb_substr($user->display_name, 0, 1)) : strtoupper(substr($user->display_name, 0, 1))); ?></span><span class="ra-pill"><?php echo !$eligible ? 'INELIGIBLE' : ($inactive ? 'RETIRED' : 'ACTIVE'); ?></span></div><h3><?php echo esc_html($user->display_name); ?></h3><p class="ra-bio"><?php echo esc_html($user->description ?: 'No bio yet. Add a little context to this byline.'); ?></p><div class="ra-card-meta"><span><?php echo $count; ?> published <?php echo 1 === $count ? 'post' : 'posts'; ?></span><span>PROFILE #<?php echo (int) $user->ID; ?></span></div>
            <?php if ($eligible) : ?><div class="ra-card-controls"><details><summary>Edit profile<span class="screen-reader-text"> <?php echo esc_html($user->display_name); ?></span></summary><form method="post" class="ra-form ra-edit"><?php riot_authors_fields('edit', $user->ID); ?><label for="ra-name-<?php echo (int) $user->ID; ?>">Display name</label><input id="ra-name-<?php echo (int) $user->ID; ?>" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" maxlength="100" required><label for="ra-bio-<?php echo (int) $user->ID; ?>">Bio</label><textarea id="ra-bio-<?php echo (int) $user->ID; ?>" name="description" rows="3" maxlength="1000"><?php echo esc_textarea($user->description); ?></textarea><small>Changes also update this profile's existing bylines. Its archive URL stays the same.</small><button class="button" type="submit">Save profile</button></form></details><form method="post"><?php riot_authors_fields($inactive ? 'activate' : 'retire', $user->ID); ?><button type="submit" class="button button-link" aria-label="<?php echo esc_attr(($inactive ? 'Reactivate ' : 'Retire ') . $user->display_name); ?>"><?php echo $inactive ? 'Reactivate' : 'Retire'; ?></button></form></div><?php else : ?><p class="ra-ineligible">This account has other roles or permissions. Review it in WordPress Users; it is excluded from assignment.</p><?php endif; ?></article>
        <?php endforeach; ?>
        </div></section><aside class="ra-sidebar"><section class="ra-panel"><span class="ra-kicker">02 / ADD A PROFILE</span><h2>Give the next post a name.</h2><p>Profiles are WordPress users with no role capabilities. This plugin blocks their sign-in and password recovery while active.</p><form method="post" class="ra-form" id="ra-create"><?php riot_authors_fields('create'); ?><label for="ra-new-name">Display name</label><input id="ra-new-name" name="display_name" placeholder="e.g. Morgan Vale" maxlength="100" autocomplete="off" required><label for="ra-new-bio">Bio <span>(optional)</span></label><textarea id="ra-new-bio" name="description" placeholder="A short introduction for this fictional byline." rows="3" maxlength="1000"></textarea><button class="button button-primary" type="submit" <?php disabled(count($roster) >= 100 || !current_user_can('create_users')); ?>>Add to pool <span aria-hidden="true">↗</span></button></form></section>
        <section class="ra-panel ra-settings"><span class="ra-kicker">03 / ASSIGNMENT</span><h2>You set the pace.</h2><p>Only standard posts on their first publication are eligible. Drafts, pages, and already published posts stay as they are.</p><form method="post" class="ra-form"><?php riot_authors_fields('settings'); ?><label for="ra-enabled">On first publication</label><select id="ra-enabled" name="enabled"><option value="1" <?php selected(riot_authors_enabled()); ?>>Assign from the active pool</option><option value="0" <?php selected(!riot_authors_enabled()); ?>>Pause — keep the chosen author</option></select><button class="button" type="submit">Save assignment settings</button></form></section><p class="ra-note">Fictional profiles are public bylines, not additional contributors. Use them for demos or clearly disclosed pseudonyms. Theme support determines how bios and archives appear.</p></aside></div>
        <footer class="ra-footer">RIOT AUTHORS / SMALL POOL. LESS BUSYWORK. <a href="https://github.com/riotrequest/Random-Dummy-Author-Assigner#readme" target="_blank" rel="noopener noreferrer">Documentation ↗</a></footer>
    </div>
    <?php
}
