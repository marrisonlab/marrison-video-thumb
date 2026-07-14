<?php
/**
 * Plugin Name: Marrison Video Thumbnail
 * Plugin URI:  https://github.com/marrisonlab/marrison-video-thumb/releases/tag/v1.0.3
 * Update URI:   https://github.com/marrisonlab/marrison-video-thumb/
 * Description: Paste a YouTube URL, pick a thumbnail, and add it to the WordPress Media Library.
 * Version: 1.0.3
 * Author: Marrisonlab
 * Author URI:  https://marrisonlab.com
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MVT_VERSION')) {
    define('MVT_VERSION', '1.0.3');
}

class Marrison_Video_Thumb {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugins_api'], 20, 3);
        add_action('wp_ajax_mvt_get_thumbnails', [$this, 'ajax_get_thumbnails']);
        add_action('wp_ajax_mvt_import_thumbnail', [$this, 'ajax_import_thumbnail']);
    }

    public function add_admin_page() {
        add_media_page(
            'Marrison Video Thumbnail',
            'Marrison Video Thumbnail',
            'upload_files',
            'marrison-video-thumb',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ('media_page_marrison-video-thumb' !== $hook) {
            return;
        }
        wp_enqueue_style('mvt-style', plugin_dir_url(__FILE__) . 'assets/style.css', [], filemtime(plugin_dir_path(__FILE__) . 'assets/style.css'));
        wp_enqueue_script('mvt-script', plugin_dir_url(__FILE__) . 'assets/script.js', ['jquery'], filemtime(plugin_dir_path(__FILE__) . 'assets/script.js'), true);
        wp_localize_script('mvt-script', 'mvtData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mvt_nonce'),
        ]);
    }

    public function render_admin_page() {
        ?>
        <div class="wrap mvt-wrap">
            <h1>Marrison Video Thumbnail</h1>
            <p>Paste a YouTube video URL to fetch the available thumbnails.</p>

            <div class="mvt-form">
                <input type="text" id="mvt-url" class="regular-text" placeholder="https://www.youtube.com/watch?v=..." />
                <button id="mvt-fetch" type="button" class="button button-primary">Fetch Thumbnails</button>
            </div>

            <div id="mvt-status" class="mvt-status"></div>

            <div id="mvt-results" class="mvt-results"></div>
        </div>
        <script>
            if (typeof jQuery === 'undefined') {
                document.getElementById('mvt-results').innerHTML = '<p style="color:red;">Error: jQuery is not loaded.</p>';
            }
        </script>
        <?php
    }

    /**
     * Extract YouTube video ID from various URL formats.
     */
    private function extract_video_id($url) {
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',
            '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        return false;
    }

    /**
     * Try to resolve the YouTube video title via oEmbed.
     */
    private function extract_video_title($url) {
        $endpoint = add_query_arg([
            'url'    => $url,
            'format' => 'json',
        ], 'https://www.youtube.com/oembed');

        $response = wp_remote_get($endpoint, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return false;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['title'])) {
            return false;
        }

        return sanitize_text_field(wp_strip_all_tags($data['title']));
    }

    /**
     * Build a safe filename base from the video title.
     */
    private function build_filename_from_title($video_title, $video_id) {
        $base = sanitize_file_name(wp_strip_all_tags((string) $video_title));

        if ($base === '') {
            $base = 'youtube_' . $video_id;
        }

        return substr($base, 0, 180);
    }

    /**
     * Retrieve the latest GitHub release data.
     */
    private function get_latest_release_data() {
        $cached = get_transient('mvt_github_release_data');
        if ($cached !== false) {
            return $cached;
        }

        if (get_transient('mvt_github_fetch_failed') !== false) {
            return false;
        }

        $response = wp_remote_get('https://api.github.com/repos/marrisonlab/marrison-video-thumb/releases/latest', [
            'timeout' => 5,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/MarrisonVideoThumbnail',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient('mvt_github_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name']) || empty($body['zipball_url'])) {
            return false;
        }

        $version = preg_replace('/^v/i', '', trim((string) $body['tag_name']));
        if ($version === '') {
            return false;
        }

        $data = [
            'tag_name'      => $body['tag_name'],
            'version'       => $version,
            'zipball_url'   => $body['zipball_url'],
            'html_url'      => !empty($body['html_url']) ? $body['html_url'] : 'https://github.com/marrisonlab/marrison-video-thumb/releases/latest',
            'published_at'  => !empty($body['published_at']) ? $body['published_at'] : '',
        ];

        set_transient('mvt_github_release_data', $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * Inject the GitHub update into WordPress.
     */
    public function check_for_updates($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release_data();
        if (!$release) {
            return $transient;
        }

        if (version_compare(MVT_VERSION, $release['version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $transient->response[$plugin_file] = (object) [
            'slug'        => 'marrison-video-thumb',
            'plugin'      => $plugin_file,
            'new_version' => $release['version'],
            'url'         => 'https://github.com/marrisonlab/marrison-video-thumb',
            'package'     => $release['zipball_url'],
        ];

        return $transient;
    }

    /**
     * Populate the "View details" modal from the GitHub release.
     */
    public function plugins_api($result, $action, $args) {
        if ('plugin_information' !== $action || empty($args->slug) || 'marrison-video-thumb' !== $args->slug) {
            return $result;
        }

        $release = $this->get_latest_release_data();
        if (!$release) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'Marrison Video Thumbnail';
        $info->slug = 'marrison-video-thumb';
        $info->version = $release['version'];
        $info->author = '<a href="https://marrisonlab.com">Marrisonlab</a>';
        $info->homepage = 'https://github.com/marrisonlab/marrison-video-thumb';
        $info->download_link = $release['zipball_url'];
        $info->requires = '5.8';
        $info->tested = '6.8';
        $info->requires_php = '7.4';
        $info->last_updated = !empty($release['published_at']) ? $release['published_at'] : current_time('mysql');
        $info->sections = [
            'description' => 'Paste a YouTube video URL to fetch the available thumbnails and import the selected image directly into the WordPress Media Library.',
            'changelog'   => $this->get_plugin_changelog(),
        ];

        return $info;
    }

    /**
     * Return a short changelog for the plugin details modal.
     */
    private function get_plugin_changelog() {
        return '<h4>1.0.3</h4><ul><li>Added GitHub release-based update detection.</li><li>Enabled WordPress update notifications for installed sites.</li><li>Added plugin details support for the "View details" modal.</li></ul>'
            . '<h4>1.0.2</h4><ul><li>Switched all user-facing labels and messages to English.</li><li>Updated the admin plugin title to Marrison Video Thumbnail.</li><li>Removed the YouTube Thumbnail prefix from imported media titles.</li></ul>';
    }

    /**
     * AJAX: Return available thumbnails for a given YouTube URL.
     */
    public function ajax_get_thumbnails() {
        check_ajax_referer('mvt_nonce', 'nonce');

        $url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
        $video_id = $this->extract_video_id($url);

        if (!$video_id) {
            wp_send_json_error('Invalid YouTube URL. Check the format.');
        }

        $video_title = $this->extract_video_title($url);

        // YouTube exposes only fixed thumbnail variants. Keep the useful ones.
        $candidates = [
            ['url' => "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg", 'label' => 'Maximum Resolution (1280 x 720)', 'w' => 1280, 'h' => 720],
            ['url' => "https://img.youtube.com/vi/{$video_id}/sddefault.jpg",    'label' => 'Standard (640 x 480)',       'w' => 640,  'h' => 480],
        ];

        // Verify which thumbnails actually exist.
        $thumbnails = [];
        foreach ($candidates as $thumb) {
            $response = wp_remote_head($thumb['url'], ['timeout' => 10]);
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200 && $thumb['w'] >= 640) {
                $thumb['video_id'] = $video_id;
                $thumb['video_title'] = $video_title;
                $thumbnails[] = $thumb;
            }
        }

        if (empty($thumbnails)) {
            wp_send_json_error('No thumbnails were found for this video.');
        }

        wp_send_json_success(['thumbnails' => $thumbnails]);
    }

    /**
     * AJAX: Import a thumbnail URL into the WordPress Media Library.
     */
    public function ajax_import_thumbnail() {
        check_ajax_referer('mvt_nonce', 'nonce');

        $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
        $video_id  = isset($_POST['video_id']) ? sanitize_text_field(wp_unslash($_POST['video_id'])) : '';
        $video_title = isset($_POST['video_title']) ? sanitize_text_field(wp_unslash($_POST['video_title'])) : '';

        if (empty($image_url)) {
            wp_send_json_error('Missing image URL.');
        }

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $filename = $this->build_filename_from_title($video_title, $video_id);

        // Download the image
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            wp_send_json_error('Unable to download the image: ' . $tmp->get_error_message());
        }

        // Determine file extension from content-type or URL
        $ext = 'jpg';
        $content_type = '';
        $head_response = wp_remote_head($image_url, ['timeout' => 10]);
        if (!is_wp_error($head_response)) {
            $content_type = wp_remote_retrieve_header($head_response, 'content-type');
        }
        if (strpos($content_type, 'png') !== false) {
            $ext = 'png';
        } elseif (strpos($content_type, 'webp') !== false) {
            $ext = 'webp';
        }

        $file_array = [
            'name'     => $filename . '.' . $ext,
            'tmp_name' => $tmp,
        ];

        // Validate the file is an image
        if (!file_is_valid_image($tmp)) {
            @unlink($tmp);
            wp_send_json_error('The downloaded file is not a valid image.');
        }

        $attachment_desc = !empty($video_title) ? $video_title : $video_id;
        $attachment_id = media_handle_sideload($file_array, 0, $attachment_desc);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            wp_send_json_error('Import error: ' . $attachment_id->get_error_message());
        }

        $edit_url = admin_url('post.php?post=' . $attachment_id . '&action=edit');

        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'edit_url'      => $edit_url,
            'media_url'     => admin_url('upload.php?item=' . $attachment_id),
        ]);
    }
}

new Marrison_Video_Thumb();
