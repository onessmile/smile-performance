<?php
/**
 * Plugin Name: Smile Performance
 * Plugin URI:  https://hp4.me/
 * Description: Bricks Builder向け高速化・キャッシュ最適化プラグイン。LiteSpeed Cache併用モード対応。
 * Version:     1.29
 * Author:      One's Smile
 * License:     GPL-2.0-or-later
 * Text Domain: smile-performance
 * Tested up to:     6.9.4
 * GitHub Plugin URI: onessmile/smile-performance
 * GitHub Branch:     main
 * Auto Update URI:   https://github.com/onessmile/smile-performance
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Bricksビルダー編集画面かどうかを検出
function spc_is_bricks_builder() {
    return isset($_GET['bricks']) || (defined('BRICKS_VERSION') && isset($_GET['bricks']));
}

// エラーをファイルに記録
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = date('Y-m-d H:i:s') . " [$errno] $errstr in $errfile on line $errline\n";
    file_put_contents(WP_CONTENT_DIR . '/smile-error.log', $msg, FILE_APPEND);
    return false;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = date('Y-m-d H:i:s') . " [FATAL] {$error['message']} in {$error['file']} on line {$error['line']}\n";
        file_put_contents(WP_CONTENT_DIR . '/smile-error.log', $msg, FILE_APPEND);
    }
});

// ============================================================
// プラグイン有効化フック
// ============================================================
// // register_activation_hook( __FILE__, 'spc_on_activation' );
register_activation_hook( __FILE__, 'spc_on_activation' );
function spc_on_activation() {
    // エラー出力を有効化
    @error_reporting(E_ALL);
    @ini_set('display_errors', 1);
    // LiteSpeed Cacheが無効化された後に残るobject-cache.phpを検出して退避
    // そのまま削除すると他プラグインへの影響リスクがあるため .bak にリネームする
    $object_cache = WP_CONTENT_DIR . '/object-cache.php';
    if ( file_exists( $object_cache ) ) {
        // LiteSpeed Cache由来かどうか確認（ファイル内容でチェック）
        $content = file_get_contents( $object_cache );
        if ( $content !== false && strpos( $content, 'LiteSpeed' ) !== false ) {
            // LiteSpeed Cacheプラグインが有効でない場合のみ退避
            if ( ! defined('LSCWP_V') && ! class_exists('LiteSpeed_Cache') ) {
                @rename( $object_cache, $object_cache . '.bak' );
            }
        }
    }

    // キャッシュディレクトリの作成
    if ( ! is_dir( WP_CONTENT_DIR . '/cache/simple-page-cache/' ) ) {
        wp_mkdir_p( WP_CONTENT_DIR . '/cache/simple-page-cache/' );
    }
}

// ============================================================
// 1. 定数
// ============================================================
if ( ! defined( 'SPC_CACHE_DIR' ) ) {
    define('SPC_CACHE_DIR', WP_CONTENT_DIR . '/cache/simple-page-cache/');
}

// キャッシュ有効期限は設定値から動的に取得
function spc_get_cache_expiry() {
    $s = get_option('spc_settings', []);
    $hours = isset($s['cache_expiry_hours']) ? (int)$s['cache_expiry_hours'] : 12;
    return $hours * HOUR_IN_SECONDS;
}

// ============================================================
// 2. モード判定ヘルパー
// ============================================================
function spc_get_mode() {
    $settings = spc_get_settings();
    return $settings['cache_mode'] ?? 'standalone';
}
function spc_is_standalone() { return spc_get_mode() === 'standalone'; }
function spc_is_litespeed()   { return spc_get_mode() === 'litespeed'; }
function spc_litespeed_active() {
    return defined('LSCWP_V') || class_exists('LiteSpeed_Cache');
}

// ログイン済みCookieチェック（ハッシュ付きCookie名に対応）
function spc_is_logged_in_cookie() {
    foreach (array_keys($_COOKIE) as $cookie_name) {
        if (strpos($cookie_name, 'wordpress_logged_in_') === 0) {
            return true;
        }
    }
    return false;
}

// ============================================================
// 3. キャッシュの提供（単独モードのみ）
// ============================================================
add_action('init', 'spc_serve_cache', 1);
function spc_serve_cache() {
    // LiteSpeedモードではPHPキャッシュを使用しない
    if (spc_is_litespeed()) return;
    if (
        is_admin() || defined('DOING_AJAX') || defined('DOING_CRON') ||
        is_user_logged_in() || spc_is_logged_in_cookie() ||
        !empty($_POST) || isset($_GET['preview']) || isset($_GET['s'])
    ) return;

    $cache_file = spc_get_cache_file_path();
    if (!$cache_file) return;

    if (file_exists($cache_file)) {
        $modified = filemtime($cache_file);
        if ((time() - $modified) < spc_get_cache_expiry()) {
            header('X-Cache: HIT');
            header('X-Cache-Age: ' . (time() - $modified) . 's');
            echo file_get_contents($cache_file);
            exit;
        } else {
            @unlink($cache_file);
        }
    }
}

// ============================================================
// 4. バッファリング・キャッシュ保存（単独モードのみ）
// ============================================================
add_action('template_redirect', 'spc_start_buffer');
function spc_start_buffer() {
    // LiteSpeedモードではPHPキャッシュを生成しない
    if (spc_is_litespeed()) return;
    if (
        is_admin() || is_user_logged_in() || spc_is_logged_in_cookie() ||
        !empty($_POST) || isset($_GET['preview']) ||
        isset($_GET['s']) || is_404() || is_search()
    ) return;
    ob_start('spc_save_cache');
}

function spc_save_cache($buffer) {
    if (strlen($buffer) < 255 || strpos($buffer, '</html>') === false) return $buffer;
    $cache_file = spc_get_cache_file_path();
    if (!$cache_file) return $buffer;
    $dir = dirname($cache_file);
    if (!is_dir($dir)) wp_mkdir_p($dir);
    $timestamp = "\n<!-- Cached by Simple Page Cache: " . date('Y-m-d H:i:s') . " -->";
    file_put_contents($cache_file, $buffer . $timestamp, LOCK_EX);
    return $buffer;
}

// ============================================================
// 5. キャッシュファイルパスの生成
// ============================================================
function spc_get_cache_file_path() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $query = parse_url($request_uri, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $params);
        $allowed = array_intersect_key($params, array_flip(['page', 'paged']));
        if (count($params) !== count($allowed)) return false;
    }
    $path = parse_url($request_uri, PHP_URL_PATH);
    $path = trim($path, '/') ?: 'index';
    $path = preg_replace('/[^a-zA-Z0-9\-\_\/]/', '_', $path);
    return SPC_CACHE_DIR . $path . '/index.html';
}

// ============================================================
// 6. キャッシュクリア関数（両モード共通）
// ============================================================
// 6. キャッシュクリア関数
// ============================================================
function spc_clear_all_cache() {
    if (spc_is_litespeed()) {
        if (spc_litespeed_active()) do_action('litespeed_purge_all');
    } else {
        if (is_dir(SPC_CACHE_DIR)) {
            spc_delete_directory(SPC_CACHE_DIR);
            wp_mkdir_p(SPC_CACHE_DIR);
        }
    }
    // Cloudflare連携：全キャッシュパージ
    spc_cf_purge_all();
}

function spc_clear_url_cache($url) {
    if (spc_is_litespeed()) {
        if (spc_litespeed_active()) do_action('litespeed_purge_url', $url);
    } else {
        $path = parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/') ?: 'index';
        $path = preg_replace('/[^a-zA-Z0-9\-\_\/]/', '_', $path);
        $cache_file = SPC_CACHE_DIR . $path . '/index.html';
        if (file_exists($cache_file)) @unlink($cache_file);
    }
    // Cloudflare連携：URL単位パージ
    spc_cf_purge_url($url);
}

function spc_delete_directory($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? spc_delete_directory($path) : @unlink($path);
    }
    @rmdir($dir);
}

// ============================================================
// Cloudflare API連携
// ============================================================
function spc_cf_get_settings() {
    return get_option('spc_cf_settings', [
        'enabled'   => 0,
        'api_token' => '',
        'zone_id'   => '',
    ]);
}

function spc_cf_is_enabled() {
    $cf = spc_cf_get_settings();
    return !empty($cf['enabled']) && !empty($cf['api_token']) && !empty($cf['zone_id']);
}

// 全キャッシュパージ
function spc_cf_purge_all() {
    if (!spc_cf_is_enabled()) return;
    $cf  = spc_cf_get_settings();
    $url = 'https://api.cloudflare.com/client/v4/zones/' . $cf['zone_id'] . '/purge_cache';
    wp_remote_post($url, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $cf['api_token'],
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode(['purge_everything' => true]),
    ]);
}

// URL単位パージ
function spc_cf_purge_url($url) {
    if (!spc_cf_is_enabled()) return;
    $cf  = spc_cf_get_settings();
    $api = 'https://api.cloudflare.com/client/v4/zones/' . $cf['zone_id'] . '/purge_cache';
    wp_remote_post($api, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $cf['api_token'],
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode(['files' => [$url]]),
    ]);
}

// 接続テスト
function spc_cf_test_connection($api_token, $zone_id) {
    $response = wp_remote_get(
        'https://api.cloudflare.com/client/v4/zones/' . $zone_id,
        [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'application/json',
            ],
        ]
    );
    if (is_wp_error($response)) return ['success' => false, 'message' => $response->get_error_message()];
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['success'])) {
        return ['success' => true, 'name' => $body['result']['name'] ?? ''];
    }
    $msg = !empty($body['errors'][0]['message']) ? $body['errors'][0]['message'] : '認証エラー';
    return ['success' => false, 'message' => $msg];
}

// 接続テストのAJAXハンドラ
add_action('wp_ajax_spc_cf_test', 'spc_handle_cf_test');
function spc_handle_cf_test() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_ajax_referer('spc_cf_test', 'nonce');
    $token   = sanitize_text_field($_POST['api_token'] ?? '');
    $zone_id = sanitize_text_field($_POST['zone_id']   ?? '');
    if (empty($token) || empty($zone_id)) {
        wp_send_json_error(['message' => 'APIトークンとゾーンIDを入力してください']);
    }
    $result = spc_cf_test_connection($token, $zone_id);
    if ($result['success']) {
        wp_send_json_success(['message' => '✅ 接続成功：ゾーン「' . esc_html($result['name']) . '」を確認しました']);
    } else {
        wp_send_json_error(['message' => '⚠️ 接続失敗：' . esc_html($result['message'])]);
    }
}

// ============================================================
// 7. 投稿保存時のキャッシュクリア
// ============================================================
add_action('save_post', 'spc_on_save_post', 10, 2);
function spc_on_save_post($post_id, $post) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if ($post->post_status !== 'publish') return;

    $post_type       = get_post_type($post_id);
    $settings        = spc_get_settings();
    $clear_all_types = $settings['clear_all_post_types'] ?? [];

    if (empty($clear_all_types) || in_array($post_type, $clear_all_types, true)) {
        spc_clear_all_cache();
        return;
    }

    spc_clear_url_cache(get_permalink($post_id));
    spc_clear_url_cache(home_url('/'));

    if ($post_type !== 'post') {
        $archive_url = get_post_type_archive_link($post_type);
        if ($archive_url) spc_clear_url_cache($archive_url);
    }

    $taxonomies = get_object_taxonomies($post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_post_terms($post_id, $taxonomy);
        if (is_wp_error($terms)) continue;
        foreach ($terms as $term) {
            $term_url = get_term_link($term);
            if (!is_wp_error($term_url)) spc_clear_url_cache($term_url);
        }
    }

    $author_url = get_author_posts_url($post->post_author);
    if ($author_url) spc_clear_url_cache($author_url);
}

add_action('trashed_post', function($post_id) {
    spc_clear_url_cache(get_permalink($post_id));
    spc_clear_url_cache(home_url('/'));
});

add_action('save_post_bricks_template', function() {
    spc_clear_all_cache();
});

// ============================================================
// 8. 管理バーボタン（LiteSpeedモード時は非表示）
// ============================================================
add_action('admin_bar_menu', 'spc_admin_bar_button', 100);
function spc_admin_bar_button($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;
    if (spc_is_litespeed()) return;
    $wp_admin_bar->add_node([
        'id'    => 'spc-clear-cache',
        'title' => '🗑 キャッシュをクリア',
        'href'  => wp_nonce_url(admin_url('admin-post.php?action=spc_clear_cache'), 'spc_clear_cache'),
        'meta'  => ['title' => '全ページキャッシュを削除します'],
    ]);
}

add_action('admin_post_spc_clear_cache', 'spc_handle_clear_cache');
function spc_handle_clear_cache() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_clear_cache');
    spc_clear_all_cache();
    wp_redirect(wp_get_referer() ?: admin_url());
    exit;
}

// ============================================================
// 9. プリロード Cron（両モード共通）
// ============================================================
add_filter('cron_schedules', 'spc_add_cron_intervals');
function spc_add_cron_intervals($schedules) {
    $schedules['spc_1hour']  = ['interval' => 1  * HOUR_IN_SECONDS, 'display' => '1時間ごと'];
    $schedules['spc_2hours'] = ['interval' => 2  * HOUR_IN_SECONDS, 'display' => '2時間ごと'];
    $schedules['spc_4hours'] = ['interval' => 4  * HOUR_IN_SECONDS, 'display' => '4時間ごと'];
    $schedules['spc_6hours'] = ['interval' => 6  * HOUR_IN_SECONDS, 'display' => '6時間ごと'];
    $schedules['spc_12hours']= ['interval' => 12 * HOUR_IN_SECONDS, 'display' => '12時間ごと'];
    $schedules['spc_24hours']= ['interval' => 24 * HOUR_IN_SECONDS, 'display' => '24時間ごと'];
    return $schedules;
}

add_action('init', 'spc_schedule_preload');
function spc_schedule_preload() {
    $settings = spc_get_settings();
    $interval = $settings['preload_interval'] ?? 'spc_6hours';
    $scheduled = wp_next_scheduled('spc_preload_event');

    // LiteSpeedモードではプリロードCronを停止
    if (spc_is_litespeed() || $interval === 'off') {
        if ($scheduled) wp_clear_scheduled_hook('spc_preload_event');
        return;
    }
    if (!$scheduled) {
        wp_schedule_event(time(), $interval, 'spc_preload_event');
    } else {
        $current = wp_get_schedule('spc_preload_event');
        if ($current !== $interval) {
            wp_clear_scheduled_hook('spc_preload_event');
            wp_schedule_event(time(), $interval, 'spc_preload_event');
        }
    }
}

add_action('spc_preload_event', 'spc_preload_cache');
function spc_preload_cache() {
    $urls  = [home_url('/')];
    $types = array_merge(['post', 'page'], array_values(spc_get_custom_post_types()));
    foreach ($types as $type) {
        $posts = get_posts([
            'numberposts' => 50,
            'post_status' => 'publish',
            'post_type'   => $type,
            'fields'      => 'ids',
        ]);
        foreach ($posts as $id) $urls[] = get_permalink($id);
    }
    foreach (array_unique($urls) as $url) {
        wp_remote_get($url, ['timeout' => 10, 'blocking' => false, 'sslverify' => false]);
        usleep(200000);
    }
}

// ============================================================
// 10. DBクリーン Cron（両モード共通）
// ============================================================
add_action('init', 'spc_schedule_db_clean');
function spc_schedule_db_clean() {
    $settings  = spc_get_settings();
    $interval  = $settings['db_clean_interval'] ?? 'spc_24hours';
    $scheduled = wp_next_scheduled('spc_db_clean_event');

    if ($interval === 'off') {
        if ($scheduled) wp_clear_scheduled_hook('spc_db_clean_event');
        return;
    }
    if (!$scheduled) {
        wp_schedule_event(time(), $interval, 'spc_db_clean_event');
    } else {
        $current = wp_get_schedule('spc_db_clean_event');
        if ($current !== $interval) {
            wp_clear_scheduled_hook('spc_db_clean_event');
            wp_schedule_event(time(), $interval, 'spc_db_clean_event');
        }
    }
}

add_action('spc_db_clean_event', 'spc_clean_database');
function spc_clean_database() {
    global $wpdb;

    // 不要データ削除
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' OR post_type = 'revision'");
    $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
    $wpdb->query("DELETE a, b FROM {$wpdb->options} a JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_timeout', '') WHERE a.option_name LIKE '_transient_timeout_%' AND a.option_value < UNIX_TIMESTAMP()");
    $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");

    // テーブル最適化（InnoDB対応）
    $tables = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
    foreach ($tables as $table) {
        $name   = $table['Name'];
        $engine = strtolower($table['Engine'] ?? '');

        if ($engine === 'innodb') {
            // InnoDB: ALTER TABLE で実際に再編成（OPTIMIZE TABLEより確実）
            $wpdb->query("ALTER TABLE `{$name}` ENGINE=InnoDB");
        } else {
            // MyISAM等: 通常のOPTIMIZE
            $wpdb->query("OPTIMIZE TABLE `{$name}`");
        }
    }

    // InnoDB統計情報を強制更新（オーバーヘッド表示を正確にする）
    $wpdb->query("ANALYZE TABLE `{$wpdb->posts}`");
    $wpdb->query("ANALYZE TABLE `{$wpdb->postmeta}`");
    $wpdb->query("ANALYZE TABLE `{$wpdb->options}`");
    $wpdb->query("ANALYZE TABLE `{$wpdb->comments}`");
    $wpdb->query("ANALYZE TABLE `{$wpdb->commentmeta}`");

    update_option('spc_db_last_cleaned', current_time('mysql'));
}

add_action('admin_post_spc_manual_db_clean', 'spc_handle_manual_db_clean');
function spc_handle_manual_db_clean() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_manual_db_clean');
    spc_clean_database();
    wp_redirect(add_query_arg(['page' => 'spc-settings', 'db_done' => '1'], admin_url('admin.php')));
    exit;
}

// ============================================================
// 11. テーマ切り替え時のクリーンアップ
// ============================================================
add_action('switch_theme', function() {
    wp_clear_scheduled_hook('spc_preload_event');
    wp_clear_scheduled_hook('spc_db_clean_event');
    wp_clear_scheduled_hook('spc_ga4_update_event');
    spc_clear_all_cache();
});

// ============================================================
// 12. GA4ローカル化
// ============================================================

// GA4スクリプトファイルの保存パス
function spc_ga4_local_path() {
    return WP_CONTENT_DIR . '/uploads/smile-cache/ga4-local.js';
}
function spc_ga4_local_url() {
    return content_url('uploads/smile-cache/ga4-local.js');
}

// GoogleからGA4スクリプトを取得してローカルに保存
function spc_ga4_fetch_script($measurement_id = '') {
    if (empty($measurement_id)) {
        $s = spc_get_settings();
        $measurement_id = $s['ga4_measurement_id'] ?? '';
    }
    if (empty($measurement_id)) return false;

    $ga4_url  = 'https://www.googletagmanager.com/gtag/js?id=' . urlencode($measurement_id);
    $response = wp_remote_get($ga4_url, ['timeout' => 15, 'sslverify' => true]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $script = wp_remote_retrieve_body($response);
    if (empty($script)) return false;

    $dir = dirname(spc_ga4_local_path());
    if (!is_dir($dir)) wp_mkdir_p($dir);

    file_put_contents(spc_ga4_local_path(), $script, LOCK_EX);
    update_option('spc_ga4_last_updated', current_time('mysql'));
    return true;
}

// Cronで定期更新
add_action('spc_ga4_update_event', 'spc_ga4_fetch_script');

// フロントエンドにGA4タグを出力（ローカルスクリプト使用）
add_action('wp_head', 'spc_ga4_output_tag', 5);
function spc_ga4_output_tag() {
    $s = spc_get_settings();
    if (empty($s['ga4_local_enabled'])) return;
    if (is_admin()) return;

    $measurement_id = $s['ga4_measurement_id'] ?? '';
    if (empty($measurement_id)) return;

    // ローカルスクリプトが存在しない場合はフォールバックでGoogleから直接読み込み
    $script_url = file_exists(spc_ga4_local_path())
        ? spc_ga4_local_url()
        : 'https://www.googletagmanager.com/gtag/js?id=' . esc_attr($measurement_id);

    echo '<script async src="' . esc_url($script_url) . '"></script>' . "\n";
    echo '<script>' . "\n";
    echo 'window.dataLayer = window.dataLayer || [];' . "\n";
    echo 'function gtag(){dataLayer.push(arguments);}' . "\n";
    echo 'gtag(\'js\', new Date());' . "\n";
    echo 'gtag(\'config\', \'' . esc_js($measurement_id) . '\');' . "\n";
    echo '</script>' . "\n";
}

// 手動でスクリプトを今すぐ更新
add_action('admin_post_spc_ga4_refresh', 'spc_handle_ga4_refresh');
function spc_handle_ga4_refresh() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_ga4_refresh');
    $result = spc_ga4_fetch_script();
    $status = $result ? 'ga4_ok' : 'ga4_error';
    wp_redirect(add_query_arg(['page' => 'spc-settings', $status => '1'], admin_url('admin.php')));
    exit;
}

// ============================================================
// 13. yakuhan CSSローカル化
// ============================================================

// yakuhanのCSSファイル定義
function spc_yakuhan_files() {
    return [
        'jp' => [
            'url'   => 'https://cdn.jsdelivr.net/npm/yakuhanjp@4.1.1/dist/css/yakuhanjp.min.css',
            'path'  => WP_CONTENT_DIR . '/uploads/smile-cache/yakuhanjp.min.css',
            'local' => content_url('uploads/smile-cache/yakuhanjp.min.css'),
            'handle'=> 'yakuhanjp',
        ],
        'mp' => [
            // yakuhanmpはyakuhanjpパッケージ内に含まれる
            'url'   => 'https://cdn.jsdelivr.net/npm/yakuhanjp@4.1.1/dist/css/yakuhanmp.min.css',
            'path'  => WP_CONTENT_DIR . '/uploads/smile-cache/yakuhanmp.min.css',
            'local' => content_url('uploads/smile-cache/yakuhanmp.min.css'),
            'handle'=> 'yakuhanmp',
        ],
    ];
}

// JSDelivrからyakuhan CSSを取得してローカルに保存
function spc_yakuhan_fetch($type = 'all') {
    $files  = spc_yakuhan_files();
    $dir    = WP_CONTENT_DIR . '/uploads/smile-cache/';
    if (!is_dir($dir)) wp_mkdir_p($dir);

    $targets = ($type === 'all') ? array_keys($files) : [$type];
    $results = [];

    foreach ($targets as $key) {
        if (!isset($files[$key])) continue;
        $file     = $files[$key];
        $response = wp_remote_get($file['url'], ['timeout' => 15, 'sslverify' => true]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $results[$key] = false;
            continue;
        }

        $css = wp_remote_retrieve_body($response);
        if (empty($css)) { $results[$key] = false; continue; }

        // 相対パスのフォントURLをJSDelivrの絶対URLに書き換え
        // ../fonts/YakuHanJP/ → https://cdn.jsdelivr.net/npm/yakuhanjp@4.1.1/dist/fonts/YakuHanJP/
        // ../fonts/YakuHanMP/ → https://cdn.jsdelivr.net/npm/yakuhanjp@4.1.1/dist/fonts/YakuHanMP/
        $css = str_replace(
            ['url(../fonts/YakuHanJP/', 'url(../fonts/YakuHanMP/'],
            ['url(https://cdn.jsdelivr.net/npm/yakuhanjp@4.1.1/dist/fonts/YakuHanJP/', 'url(https://cdn.jsdelivr.net/npm/yakuhanjp@4.1.1/dist/fonts/YakuHanMP/'],
            $css
        );

        file_put_contents($file['path'], $css, LOCK_EX);
        $results[$key] = true;
    }

    update_option('spc_yakuhan_last_updated', current_time('mysql'));
    return !in_array(false, $results, true);
}

// Cronで定期更新
add_action('spc_yakuhan_update_event', 'spc_yakuhan_fetch');

// yakuhanのCSSをローカルから読み込む（JSDelivrの読み込みを置き換え）
add_action('wp_enqueue_scripts', 'spc_yakuhan_enqueue', 5);
function spc_yakuhan_enqueue() {
    $s     = spc_get_settings();
    $files = spc_yakuhan_files();

    foreach (['jp' => 'yakuhan_jp_enabled', 'mp' => 'yakuhan_mp_enabled'] as $key => $setting) {
        if (empty($s[$setting])) continue;
        $file = $files[$key];

        // ローカルファイルが存在する場合はローカルから、なければCDNから
        $url = file_exists($file['path']) ? $file['local'] : $file['url'];

        // 既存の外部登録があれば上書き、なければ新規登録
        wp_deregister_style($file['handle']);
        wp_enqueue_style($file['handle'], $url, [], null);
    }
}

// 手動更新
add_action('admin_post_spc_yakuhan_refresh', 'spc_handle_yakuhan_refresh');
function spc_handle_yakuhan_refresh() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_yakuhan_refresh');
    $result = spc_yakuhan_fetch('all');
    $status = $result ? 'yakuhan_ok' : 'yakuhan_error';
    wp_redirect(add_query_arg(['page' => 'spc-settings', $status => '1'], admin_url('admin.php')));
    exit;
}

// ============================================================
// 12. 設定ヘルパー
// ============================================================
function spc_get_settings() {
    return get_option('spc_settings', [
        'cache_mode'            => 'standalone',
        'cache_expiry_hours'    => 12,
        'clear_all_post_types'  => [],
        'preload_interval'      => 'spc_6hours',
        'db_clean_interval'     => 'spc_24hours',
        'tuning_dns_prefetch'        => 1,
        'tuning_dns_prefetch_fonts'  => 0,
        'tuning_emoji'               => 1,
        'tuning_oembed'         => 1,
        'tuning_query_strings'  => 1,
        'tuning_rss'            => 0,
        'tuning_header_cleanup' => 1,
        'tuning_iframe_lazy'    => 1,
        'tuning_image_blur_lazy'=> 1,
        'tuning_js_defer'       => 1,
        'tuning_lcp_preload'        => 0,
        'tuning_lcp_preload_url'    => '',
        'tuning_font_preload'       => 0,
        'tuning_video_preload_none' => 0,
        'tuning_video_lazy'         => 0,
        'tuning_image_lazy_enhance' => 0,
        'tuning_browser_cache'      => 0,
        'tuning_css_minify'         => 0,
        'tuning_css_minify_inline'  => 0,
        'tuning_css_minify_limit'   => 110,
        'tuning_gzip'               => 0,
        'prefetch_enabled'      => 0,
        'prefetch_mobile'       => 0,
        'prefetch_concurrency'  => 2,
        'prefetch_delay'        => 2000,
        'prefetch_excludes'     => '',
        'nonce_refresh'         => 1,
        'ga4_local_enabled'     => 0,
        'ga4_measurement_id'    => '',
        'ga4_update_interval'   => 'spc_24hours',
        'yakuhan_jp_enabled'    => 0,
        'yakuhan_mp_enabled'    => 0,
        'yakuhan_update_interval' => 'spc_24hours',
    ]);
}

function spc_get_custom_post_types() {
    return get_post_types(['public' => true, '_builtin' => false], 'names');
}

function spc_get_db_stats() {
    global $wpdb;
    $db_name = DB_NAME;

    return [
        'revisions'          => (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
        'auto_drafts'        => (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
        'trash_posts'        => (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
        'expired_transients' => (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"),
        'spam_comments'      => (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
        'total_size'         => (float) $wpdb->get_var("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE table_schema = '{$db_name}'"),
        'overhead'           => (float) $wpdb->get_var("SELECT ROUND(SUM(data_free) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE table_schema = '{$db_name}'"),
    ];
}

// ============================================================
// 13. Bricks最適化チューニング
// ============================================================
add_action('init', 'spc_apply_tuning');
function spc_apply_tuning() {
    $s = spc_get_settings();

    if (!empty($s['tuning_emoji'])) {
        remove_action('wp_head',             'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles',     'print_emoji_styles');
        remove_action('admin_print_styles',  'print_emoji_styles');
        remove_filter('the_content_feed',    'wp_staticize_emoji');
        remove_filter('comment_text_rss',    'wp_staticize_emoji');
        remove_filter('wp_mail',             'wp_staticize_emoji_for_email');
    }

    if (!empty($s['tuning_oembed'])) {
        remove_action('wp_head',           'wp_oembed_add_discovery_links');
        remove_action('wp_head',           'wp_oembed_add_host_js');
        remove_action('rest_api_init',     'wp_oembed_register_route');
        remove_filter('oembed_dataparse',  'wp_filter_oembed_result');
        remove_action('wp_head',           'rest_output_link_wp_head');
        remove_action('template_redirect', 'rest_output_link_header', 11);
    }

    if (!empty($s['tuning_rss'])) {
        remove_action('wp_head', 'feed_links',       2);
        remove_action('wp_head', 'feed_links_extra', 3);
    }

    if (!empty($s['tuning_header_cleanup'])) {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
        add_filter('style_loader_src',  'spc_remove_wp_version_strings', 10, 2);
        add_filter('script_loader_src', 'spc_remove_wp_version_strings', 10, 2);
    }

    // iframeの遅延読み込み
    if (!empty($s['tuning_iframe_lazy'])) {
        add_filter('the_content',         'spc_add_iframe_lazy_loading', 20);
        add_filter('widget_text',         'spc_add_iframe_lazy_loading', 20);
        add_filter('bricks_front_render', 'spc_add_iframe_lazy_loading', 20);
    }

    // 画像ブラーフェードイン（LQIP風）
    if (!empty($s['tuning_image_blur_lazy'])) {
        add_action('wp_head',   'spc_image_blur_lazy_css');
        add_action('wp_footer', 'spc_image_blur_lazy_js');
    }

    // CSSプリロードは検証の結果逆効果のため削除済み

    // scroll-timeline.js defer化（レンダリングブロック解消）
    if (!empty($s['tuning_js_defer'])) {
        add_filter('script_loader_tag', 'spc_defer_scroll_timeline', 10, 3);
    }

    // LCPヒーロー画像のpreload（URL直接入力方式）
    if (!empty($s['tuning_lcp_preload']) && !empty($s['tuning_lcp_preload_url'])) {
        add_action('wp_head', 'spc_output_lcp_preload', 1);
        // fetchpriority="high" / loading="eager" を対象imgタグに自動付与
        add_filter('bricks_front_render', 'spc_add_lcp_img_attrs', 99);
        add_filter('the_content',         'spc_add_lcp_img_attrs', 99);
        add_action('wp_head',             'spc_add_lcp_img_attrs_buffer', 0);
    }

    // フォントpreload
    if (!empty($s['tuning_font_preload'])) {
        add_action('wp_head', 'spc_output_font_preload', 2);
    }

    // video preload="none" 自動付与
    if (!empty($s['tuning_video_preload_none'])) {
        add_filter('the_content',         'spc_video_preload_none', 20);
        add_filter('widget_text',         'spc_video_preload_none', 20);
        add_filter('bricks_front_render', 'spc_video_preload_none', 20);
    }

    // video 遅延読み込み（Intersection Observer）
    if (!empty($s['tuning_video_lazy'])) {
        add_filter('the_content',         'spc_video_lazy_load', 20);
        add_filter('widget_text',         'spc_video_lazy_load', 20);
        add_filter('bricks_front_render', 'spc_video_lazy_load', 20);
        add_action('wp_footer',           'spc_video_lazy_js');
    }

    // HTML圧縮
    // HTML圧縮機能は削除済み

    // CSS圧縮（単独モードのみ・Bricks編集画面を除外）
    if (!empty($s['tuning_css_minify']) && !spc_is_litespeed() && !spc_is_bricks_builder()) {
        add_filter('style_loader_tag', 'spc_css_minify_enqueued', 99, 4);
    }
    // インラインCSS圧縮（別途チェックボックスで制御）
    if (!empty($s['tuning_css_minify']) && !empty($s['tuning_css_minify_inline']) && !spc_is_litespeed() && !spc_is_bricks_builder()) {
        add_action('wp_head', 'spc_css_minify_inline', 99);
    }

    // Gzip/Brotli圧縮（サーバーが未対応の場合のみ有効）
    if (!empty($s['tuning_gzip'])) {
        add_action('init', 'spc_start_gzip_compression', 0);
        add_action('send_headers', 'spc_add_htaccess_compression');
    }

    // 画像遅延読み込み強化
    if (!empty($s['tuning_image_lazy_enhance'])) {
        add_filter('the_content',         'spc_enhance_image_lazy', 20);
        add_filter('bricks_front_render', 'spc_enhance_image_lazy', 20);
    }

    // ブラウザキャッシュヘッダー（LiteSpeedモード時は無効）
    if (!empty($s['tuning_browser_cache']) && !spc_is_litespeed()) {
        add_action('send_headers', 'spc_send_browser_cache_headers');
    }
}

// ============================================================
// Gzip/Brotli圧縮（サーバー未対応時のフォールバック）
// ============================================================

// サーバーがすでにgzip/brotliを適用しているか検出
function spc_server_has_compression() {
    // Accept-Encodingヘッダーがない場合は不要
    if (empty($_SERVER['HTTP_ACCEPT_ENCODING'])) return false;
    // PHP出力圧縮が既に有効な場合
    if (ini_get('zlib.output_compression')) return true;
    // サーバーヘッダーでの検出（OpenLiteSpeed/Nginx等）
    if (function_exists('apache_get_modules')) {
        $modules = apache_get_modules();
        if (in_array('mod_deflate', $modules) || in_array('mod_brotli', $modules)) return true;
    }
    return false;
}

// PHPレベルのgzip圧縮（サーバー未対応時のフォールバック）
function spc_start_gzip_compression() {
    if (is_admin() || headers_sent()) return;
    if (spc_server_has_compression()) return;
    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (strpos($accept, 'br') !== false && function_exists('brotli_compress')) {
        // Brotli対応（php-brotli拡張がある場合）
        ob_start(function($output) {
            header('Content-Encoding: br');
            header('Vary: Accept-Encoding');
            return brotli_compress($output, 4);
        });
    } elseif (strpos($accept, 'gzip') !== false) {
        // Gzip
        ob_start('ob_gzhandler');
        header('Vary: Accept-Encoding');
    }
}

// .htaccess にApache用圧縮設定を追記（Apache環境のみ）
function spc_add_htaccess_compression() {
    // Apache以外はスキップ
    if (!function_exists('apache_get_version') && strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'Apache') === false) return;
    $htaccess = ABSPATH . '.htaccess';
    if (!is_writable($htaccess)) return;
    $content = file_get_contents($htaccess);
    if (strpos($content, '# SPC Gzip Compression') !== false) return;
    $gzip_rules = "
# SPC Gzip Compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json
</IfModule>
<IfModule mod_brotli.c>
    AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css application/javascript application/json
</IfModule>
# End SPC Gzip Compression
";
    file_put_contents($htaccess, $content . $gzip_rules);
}

// プラグイン無効化時に.htaccessのGzip設定を削除
register_deactivation_hook(__FILE__, 'spc_remove_htaccess_compression');
function spc_remove_htaccess_compression() {
    $htaccess = ABSPATH . '.htaccess';
    if (!file_exists($htaccess)) return;
    $content = file_get_contents($htaccess);
    $content = preg_replace('/
# SPC Gzip Compression[\s\S]*?# End SPC Gzip Compression
/', '', $content);
    file_put_contents($htaccess, $content);
}

// ============================================================
// CSS圧縮（単独モードのみ）
// ============================================================

// CSSミニファイ処理（コメント・空白除去）
function spc_minify_css_string($css) {
    if (empty($css)) return $css;
    // @charset を保持
    $charset = '';
    if (preg_match('/^(@charset[^;]+;)/i', $css, $m)) {
        $charset = $m[1];
        $css = substr($css, strlen($charset));
    }
    // コメント除去（/*!...*/は保持）
    $css = preg_replace('/\/\*(?!!)[\s\S]*?\*\//', '', $css);
    // 改行・タブを空白に
    $css = preg_replace('/[
	]+/', ' ', $css);
    // 複数空白を1つに
    $css = preg_replace('/\s{2,}/', ' ', $css);
    // セレクタ・プロパティ周辺の不要空白を除去
    $css = preg_replace('/\s*([:;,{}])\s*/', '$1', $css);
    $css = preg_replace('/;\}/', '}', $css);
    // 先頭・末尾の空白除去
    $css = trim($css);
    return $charset . $css;
}

// エンキューCSSのインライン化（小さいファイルのみ圧縮して出力）
function spc_css_minify_enqueued($tag, $handle, $href, $media) {
    // 管理画面・Bricks編集画面・ログインページは除外
    if (is_admin() || spc_is_bricks_builder()) return $tag;
    // アイコン関連CSSは除外（fontawesome・ionicons・themify等）
    $icon_patterns = ['fontawesome', 'font-awesome', 'ionicons', 'themify', 'dashicons', 'genericons'];
    foreach ($icon_patterns as $pattern) {
        if (stripos($href, $pattern) !== false) return $tag;
    }
    // 外部ドメインのCSSは除外
    $site_url = site_url();
    if (strpos($href, $site_url) === false) return $tag;
    // クエリ文字列を除去してパスを取得
    $href_clean = strtok($href, '?');
    $file_path  = str_replace(site_url('/'), ABSPATH, $href_clean);
    if (!file_exists($file_path)) return $tag;
    // 設定の上限サイズを取得（デフォルト110KB）
    $spc_s = spc_get_settings();
    $limit_kb = (int)($spc_s['tuning_css_minify_limit'] ?? 110);
    if (filesize($file_path) > $limit_kb * 1024) return $tag;
    $css = file_get_contents($file_path);
    if ($css === false) return $tag;
    // @font-faceを含むCSSはURLパスが壊れる可能性があるためスキップ
    if (stripos($css, '@font-face') !== false) return $tag;
    // .min.cssは既圧縮のためコメント除去のみ軽量処理
    if (strpos($href_clean, '.min.css') !== false) {
        $minified = preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $css);
        $minified = trim($minified);
    } else {
        $minified = spc_minify_css_string($css);
    }
    $media_attr = $media && $media !== 'all' ? ' media="' . esc_attr($media) . '"' : '';
    return '<style' . $media_attr . '>' . $minified . '</style>' . "
";
}

// インラインCSSの圧縮（wp_head内のstyleタグ）
function spc_css_minify_inline() {
    // Bricks編集画面は除外
    if (spc_is_bricks_builder()) return;
    // 出力バッファでwp_headのstyleタグを圧縮
    ob_start(function($html) {
        return preg_replace_callback(
            '/<style([^>]*)>([\s\S]*?)<\/style>/i',
            function($matches) {
                $attrs    = $matches[1];
                $css      = $matches[2];
                // id属性がbricks-inline-cssまたはbricks-colorsの場合はスキップ
                if (preg_match('#id=["\']bricks-#i', $attrs)) return $matches[0];
                $minified = spc_minify_css_string($css);
                return '<style' . $attrs . '>' . $minified . '</style>';
            },
            $html
        );
    });
}

// scroll-timeline.js にdefer属性を付与
function spc_defer_scroll_timeline($tag, $handle, $src) {
    if (strpos($src, 'scroll-timeline') === false) return $tag;
    if (strpos($tag, 'defer') !== false) return $tag;
    return str_replace(' src=', ' defer src=', $tag);
}

// LCPヒーロー画像preload出力（URL直接入力方式）
function spc_output_lcp_preload() {
    if (is_admin()) return;
    $s    = spc_get_settings();
    $urls = $s['tuning_lcp_preload_url'] ?? '';
    if (empty($urls)) return;
    $url_list = array_filter(array_map('trim', explode("\n", $urls)));
    foreach ($url_list as $url) {
        if (!empty($url)) {
            echo '<link rel="preload" as="image" href="' . esc_url($url) . '" fetchpriority="high">' . "\n";
        }
    }
}

// LCP対象imgタグに fetchpriority="high" / loading="eager" を付与（the_content / bricks_front_render用）
function spc_add_lcp_img_attrs($content) {
    if (is_admin() || empty($content)) return $content;
    $s        = spc_get_settings();
    $urls_raw = $s['tuning_lcp_preload_url'] ?? '';
    $url_list = array_filter(array_map('trim', explode("\n", $urls_raw)));
    if (empty($url_list)) return $content;

    foreach ($url_list as $lcp_url) {
        $lcp_url = esc_url($lcp_url);
        // src属性にURLが含まれるimgタグを対象に属性付与
        $content = preg_replace_callback(
            '/<img([^>]*?)>/i',
            function($matches) use ($lcp_url) {
                $attrs = $matches[1];
                if (strpos($attrs, $lcp_url) === false) return $matches[0];
                // fetchpriority が未設定なら追加
                if (stripos($attrs, 'fetchpriority') === false) {
                    $attrs .= ' fetchpriority="high"';
                }
                // loading が未設定またはlazyなら eager に変更
                if (stripos($attrs, 'loading') === false) {
                    $attrs .= ' loading="eager"';
                } else {
                    $attrs = preg_replace('/loading=(["\'])lazy\1/i', 'loading="eager"', $attrs);
                }
                return '<img' . $attrs . '>';
            },
            $content
        );
    }
    return $content;
}

// 出力バッファリングでBricksのヒーロー画像にも対応（wp_headの前にバッファ開始）
function spc_add_lcp_img_attrs_buffer() {
    if (is_admin()) return;
    ob_start('spc_add_lcp_img_attrs');
}

// フォントpreload出力（Bricksローカルフォントのwoff2をpreload）
function spc_output_font_preload() {
    if (is_admin()) return;
    // wp-content/uploads/fonts/ 以下のwoff2ファイルを最大4件preload
    $font_dir = WP_CONTENT_DIR . '/uploads/fonts/';
    $font_url = content_url('uploads/fonts/');
    if (!is_dir($font_dir)) return;

    $files = glob($font_dir . '*.woff2');
    if (empty($files)) return;

    // ファイルサイズが小さいもの（主要ウェイト）を優先して最大4件
    usort($files, function($a, $b) { return filesize($a) - filesize($b); });
    $files = array_slice($files, 0, 4);

    foreach ($files as $file) {
        $filename = basename($file);
        echo '<link rel="preload" href="' . esc_url($font_url . $filename) . '" as="font" type="font/woff2" crossorigin>' . "\n";
    }
}

// ============================================================
// video preload="none" 自動付与
// ============================================================
function spc_video_preload_none($content) {
    if (!is_string($content) || strpos($content, '<video') === false) return $content;
    return preg_replace_callback('/<video([^>]*)>/i', function($matches) {
        $attrs = $matches[1];
        if (preg_match('/\bdata-no-preload\b/i', $attrs)) return $matches[0];
        if (preg_match('/\bpreload\s*=/i', $attrs)) {
            $attrs = preg_replace('/\bpreload\s*=\s*(["\'])[^"\']*\1/i', 'preload="none"', $attrs);
            return '<video' . $attrs . '>';
        }
        return '<video' . $attrs . ' preload="none">';
    }, $content);
}

// ============================================================
// video 遅延読み込み（Intersection Observer）
// ============================================================
function spc_video_lazy_load($content) {
    if (!is_string($content) || strpos($content, '<video') === false) return $content;
    return preg_replace_callback(
        '/(<video([^>]*)>)(.*?)(<\/video>)/is',
        function($m) {
            $open_tag  = $m[1];
            $tag_attrs = $m[2];
            $inner     = $m[3];
            $close_tag = $m[4];
            if (preg_match('/\bdata-no-lazy\b/i', $tag_attrs))    return $m[0];
            if (preg_match('/\bautoplay\b/i', $tag_attrs))         return $m[0];
            if (strpos($tag_attrs, 'data-spc-lazy') !== false)     return $m[0];
            $inner = preg_replace_callback('/<source([^>]*)>/i', function($sm) {
                $a = $sm[1];
                $a = preg_replace('/\bsrc\s*=\s*(["\'])([^"\']*)\1/i',    'data-src="$2"',    $a);
                $a = preg_replace('/\bsrcset\s*=\s*(["\'])([^"\']*)\1/i', 'data-srcset="$2"', $a);
                return '<source' . $a . '>';
            }, $inner);
            $open_tag = preg_replace('/\bsrc\s*=\s*(["\'])([^"\']*)\1/i', 'data-src="$2"', $open_tag);
            $open_tag = str_replace('<video', '<video data-spc-lazy="1"', $open_tag);
            return $open_tag . $inner . $close_tag;
        },
        $content
    );
}

function spc_video_lazy_js() {
    $js  = '<script id="spc-video-lazy-js">' . "\n";
    $js .= '(function() {' . "\n";
    $js .= '    var videos = document.querySelectorAll(\'video[data-spc-lazy]\');' . "\n";
    $js .= '    if (!videos.length) return;' . "\n";
    $js .= '    function loadVideo(video) {' . "\n";
    $js .= '        video.querySelectorAll(\'source[data-src], source[data-srcset]\').forEach(function(s) {' . "\n";
    $js .= '            if (s.dataset.src)    s.setAttribute(\'src\',    s.dataset.src);' . "\n";
    $js .= '            if (s.dataset.srcset) s.setAttribute(\'srcset\', s.dataset.srcset);' . "\n";
    $js .= '        });' . "\n";
    $js .= '        if (video.dataset.src) video.setAttribute(\'src\', video.dataset.src);' . "\n";
    $js .= '        video.load();' . "\n";
    $js .= '        video.removeAttribute(\'data-spc-lazy\');' . "\n";
    $js .= '    }' . "\n";
    $js .= '    if (\'IntersectionObserver\' in window) {' . "\n";
    $js .= '        var io = new IntersectionObserver(function(entries) {' . "\n";
    $js .= '            entries.forEach(function(entry) {' . "\n";
    $js .= '                if (!entry.isIntersecting) return;' . "\n";
    $js .= '                loadVideo(entry.target);' . "\n";
    $js .= '                io.unobserve(entry.target);' . "\n";
    $js .= '            });' . "\n";
    $js .= '        }, { rootMargin: \'200px 0px\', threshold: 0 });' . "\n";
    $js .= '        videos.forEach(function(v) { io.observe(v); });' . "\n";
    $js .= '    } else {' . "\n";
    $js .= '        videos.forEach(function(v) { loadVideo(v); });' . "\n";
    $js .= '    }' . "\n";
    $js .= '})();' . "\n";
    $js .= '</script>' . "\n";
    echo $js;
}

// ============================================================
// HTML圧縮（Minify）
// ============================================================
// ============================================================
// 画像遅延読み込み強化
// ============================================================
// Bricks処理済み・LCP画像・除外クラスを避けてloading="lazy"を補完
function spc_enhance_image_lazy($content) {
    if (!is_string($content) || strpos($content, '<img') === false) return $content;
    return preg_replace_callback('/<img([^>]*)>/i', function($matches) {
        $attrs = $matches[1];

        // すでにloading属性がある場合はスキップ
        if (preg_match('/\bloading\s*=/i', $attrs)) return $matches[0];

        // fetchpriority="high"（LCP画像）はスキップ
        if (preg_match('/\bfetchpriority\s*=\s*["\']high["\']/i', $attrs)) return $matches[0];

        // Bricksが処理済みの画像はスキップ
        if (preg_match('/\bdata-src\b/i', $attrs)) return $matches[0];
        if (preg_match('/bricks-lazy/i', $attrs)) return $matches[0];
        if (preg_match('/\bclass\s*=\s*["\'][^"\']*bricks-[^"\']*["\']/i', $attrs)) return $matches[0];

        // data-no-lazy属性があればスキップ
        if (preg_match('/\bdata-no-lazy\b/i', $attrs)) return $matches[0];

        return '<img' . $attrs . ' loading="lazy">';
    }, $content);
}

// ============================================================
// ブラウザキャッシュヘッダー（Cache-Control / Expires）
// ============================================================
function spc_send_browser_cache_headers() {
    if (is_admin() || is_user_logged_in()) return;
    if (is_feed() || is_404()) return;

    // 動的ページ：短めのキャッシュ（プロキシキャッシュ禁止）
    header('Cache-Control: public, max-age=600, s-maxage=0');
    header('Vary: Accept-Encoding');
}

// .htaccessへの静的ファイルキャッシュルール書き込み
function spc_write_browser_cache_htaccess() {
    $htaccess = ABSPATH . '.htaccess';
    if (!is_writable($htaccess)) return false;

    $marker = 'SPC_BROWSER_CACHE';
    $rules  = [];
    $rules[] = '<IfModule mod_expires.c>';
    $rules[] = '    ExpiresActive On';
    $rules[] = '    ExpiresByType image/webp             "access plus 1 year"';
    $rules[] = '    ExpiresByType image/jpeg             "access plus 1 year"';
    $rules[] = '    ExpiresByType image/png              "access plus 1 year"';
    $rules[] = '    ExpiresByType image/gif              "access plus 1 year"';
    $rules[] = '    ExpiresByType image/svg+xml          "access plus 1 year"';
    $rules[] = '    ExpiresByType image/x-icon           "access plus 1 year"';
    $rules[] = '    ExpiresByType font/woff2             "access plus 1 year"';
    $rules[] = '    ExpiresByType font/woff              "access plus 1 year"';
    $rules[] = '    ExpiresByType text/css               "access plus 1 month"';
    $rules[] = '    ExpiresByType application/javascript "access plus 1 month"';
    $rules[] = '    ExpiresByType video/mp4              "access plus 1 year"';
    $rules[] = '    ExpiresByType video/webm             "access plus 1 year"';
    $rules[] = '    ExpiresByType video/ogg              "access plus 1 year"';
    $rules[] = '    ExpiresByType video/quicktime        "access plus 1 year"';
    $rules[] = '</IfModule>';
    $rules[] = '<IfModule mod_headers.c>';
    $rules[] = '    <FilesMatch "\.(webp|jpg|jpeg|png|gif|svg|ico|woff2|woff)$">';
    $rules[] = '        Header set Cache-Control "public, max-age=31536000, immutable"';
    $rules[] = '    </FilesMatch>';
    $rules[] = '    <FilesMatch "\.(css|js)$">';
    $rules[] = '        Header set Cache-Control "public, max-age=2592000"';
    $rules[] = '    </FilesMatch>';
    $rules[] = '    <FilesMatch "\.(mp4|webm|ogv|mov)$">';
    $rules[] = '        Header set Cache-Control "public, max-age=31536000"';
    $rules[] = '    </FilesMatch>';
    $rules[] = '</IfModule>';

    insert_with_markers($htaccess, $marker, $rules);
    return true;
}

// .htaccessからSPCのブラウザキャッシュルールを除去
function spc_remove_browser_cache_htaccess() {
    $htaccess = ABSPATH . '.htaccess';
    if (!is_writable($htaccess)) return false;
    insert_with_markers($htaccess, 'SPC_BROWSER_CACHE', []);
    return true;
}

function spc_remove_wp_version_strings($src) {
    if (strpos($src, 'ver=' . get_bloginfo('version')) !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

// iframe遅延読み込み：loading="lazy"を付与（既に付いているものはスキップ）
function spc_add_iframe_lazy_loading($content) {
    if (!is_string($content) || strpos($content, '<iframe') === false) return $content;
    return preg_replace_callback('/<iframe([^>]*)>/i', function($matches) {
        $attrs = $matches[1];
        // 既にloading属性がある場合はスキップ
        if (preg_match('/\bloading\s*=/i', $attrs)) return $matches[0];
        // Cloudflare Turnstile iframeはスキップ（Shadow DOM内だが念のため除外）
        if (strpos($attrs, 'challenges.cloudflare.com') !== false) return $matches[0];
        return '<iframe' . $attrs . ' loading="lazy">';
    }, $content);
}

// 画像ブラーフェードイン（LQIP風）CSS
function spc_image_blur_lazy_css() {
    $css  = '<style id="spc-blur-lazy-css">' . "\n";
    $css .= 'img.spc-blur-lazy{filter:blur(8px);transition:filter 0.4s ease-in-out,opacity 0.4s ease-in-out;opacity:0.6;will-change:filter,opacity;}' . "\n";
    $css .= 'img.spc-blur-lazy.spc-loaded{filter:blur(0);opacity:1;}' . "\n";
    $css .= 'img.spc-blur-lazy[width][height]{aspect-ratio:attr(width)/attr(height);}' . "\n";
    $css .= '</style>' . "\n";
    echo $css;
}

// 画像ブラーフェードイン（LQIP風）JS
function spc_image_blur_lazy_js() {
    $js  = '<script id="spc-blur-lazy-js">' . "\n";
    $js .= '(function() {' . "\n";
    $js .= '    var imgs = document.querySelectorAll(\'img[loading="lazy"]:not(.bricks-lazy-load-isotope):not([data-no-blur])\');' . "\n";
    $js .= '    if (!imgs.length) return;' . "\n";
    $js .= '    if (\'IntersectionObserver\' in window) {' . "\n";
    $js .= '        var io = new IntersectionObserver(function(entries) {' . "\n";
    $js .= '            entries.forEach(function(entry) {' . "\n";
    $js .= '                if (!entry.isIntersecting) return;' . "\n";
    $js .= '                var img = entry.target;' . "\n";
    $js .= '                img.classList.add(\'spc-blur-lazy\');' . "\n";
    $js .= '                function onLoad() { img.classList.add(\'spc-loaded\'); io.unobserve(img); }' . "\n";
    $js .= '                if (img.complete && img.naturalWidth > 0) { onLoad(); }' . "\n";
    $js .= '                else {' . "\n";
    $js .= '                    img.addEventListener(\'load\', onLoad, { once: true });' . "\n";
    $js .= '                    img.addEventListener(\'error\', function() { io.unobserve(img); }, { once: true });' . "\n";
    $js .= '                }' . "\n";
    $js .= '            });' . "\n";
    $js .= '        }, { rootMargin: \'200px 0px\', threshold: 0 });' . "\n";
    $js .= '        imgs.forEach(function(img) { io.observe(img); });' . "\n";
    $js .= '    } else {' . "\n";
    $js .= '        imgs.forEach(function(img) { img.classList.add(\'spc-blur-lazy\', \'spc-loaded\'); });' . "\n";
    $js .= '    }' . "\n";
    $js .= '})();' . "\n";
    $js .= '</script>' . "\n";
    echo $js;
}

add_action('init', function() {
    $s = spc_get_settings();
    if (!empty($s['tuning_query_strings'])) {
        add_filter('style_loader_src',  'spc_remove_query_strings', 15);
        add_filter('script_loader_src', 'spc_remove_query_strings', 15);
    }
});

function spc_remove_query_strings($src) {
    if (strpos($src, '?ver=') !== false || strpos($src, '&ver=') !== false) {
        $src = preg_replace('/[?&]ver=[^&]*/', '', $src);
    }
    return $src;
}

add_action('wp_head', 'spc_output_resource_hints', 1);
function spc_output_resource_hints() {
    $s = spc_get_settings();

    // Google Fonts preconnect（Bricksでフォントを使用している場合のみ）
    if (!empty($s['tuning_dns_prefetch_fonts'])) {
        echo '<link rel="preconnect" href="' . esc_url('https://fonts.googleapis.com') . '" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="' . esc_url('https://fonts.gstatic.com') . '" crossorigin>' . "\n";
        echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
        echo '<link rel="dns-prefetch" href="//fonts.gstatic.com">' . "\n";
    }

    // GTM・GA preconnect（GA4ローカル化がオフ かつ DNSプリフェッチがオンの場合のみ）
    if (!empty($s['tuning_dns_prefetch']) && empty($s['ga4_local_enabled'])) {
        echo '<link rel="preconnect" href="' . esc_url('https://www.googletagmanager.com') . '" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="' . esc_url('https://www.google-analytics.com') . '" crossorigin>' . "\n";
        echo '<link rel="dns-prefetch" href="//www.googletagmanager.com">' . "\n";
        echo '<link rel="dns-prefetch" href="//www.google-analytics.com">' . "\n";
    }
}

// ============================================================
// 14. AJAX nonce差し替え
// ============================================================
add_action('wp_ajax_nopriv_spc_get_nonces', 'spc_ajax_get_nonces');
add_action('wp_ajax_spc_get_nonces',        'spc_ajax_get_nonces');
function spc_ajax_get_nonces() {
    wp_send_json_success([
        'fluentform'  => wp_create_nonce('fluentform_nonce'),
        'bricks'      => wp_create_nonce('bricks-nonce'),
        'bricksforge' => wp_create_nonce('bricksforge_form_nonce'),
    ]);
}

add_action('wp_enqueue_scripts', 'spc_enqueue_nonce_refresh');
function spc_enqueue_nonce_refresh() {
    $s = spc_get_settings();
    if (empty($s['nonce_refresh'])) return;
    if (is_admin()) return;

    wp_register_script('spc-nonce-refresh', false, [], null, true);
    wp_enqueue_script('spc-nonce-refresh');
    wp_localize_script('spc-nonce-refresh', 'SPC_AJAX', [
        'url' => admin_url('admin-ajax.php'),
    ]);
    wp_add_inline_script('spc-nonce-refresh', spc_get_nonce_refresh_js());
}

function spc_get_nonce_refresh_js() {
    $js  = '(function() {' . "\n";
    $js .= '    function hasForm() {' . "\n";
    $js .= '        return !!(' . "\n";
    $js .= '            document.querySelector(\'input[name*="_fluentformnonce"]\') ||' . "\n";
    $js .= '            document.querySelector(\'.bricks-form input[name="nonce"]\') ||' . "\n";
    $js .= '            document.querySelector(\'input[name="bricksforge_form_nonce"]\') ||' . "\n";
    $js .= '            document.querySelector(\'input[name="bricksforge_nonce"]\')' . "\n";
    $js .= '        );' . "\n";
    $js .= '    }' . "\n";
    $js .= '    if (!hasForm()) return;' . "\n";
    $js .= '    function refreshNonces() {' . "\n";
    $js .= '        var xhr = new XMLHttpRequest();' . "\n";
    $js .= '        xhr.open(\'POST\', SPC_AJAX.url, true);' . "\n";
    $js .= '        xhr.setRequestHeader(\'Content-Type\', \'application/x-www-form-urlencoded\');' . "\n";
    $js .= '        xhr.onload = function() {' . "\n";
    $js .= '            if (xhr.status !== 200) return;' . "\n";
    $js .= '            try {' . "\n";
    $js .= '                var res = JSON.parse(xhr.responseText);' . "\n";
    $js .= '                if (!res.success || !res.data) return;' . "\n";
    $js .= '                var nonces = res.data;' . "\n";
    $js .= '                document.querySelectorAll(\'input[name*="_fluentformnonce"]\').forEach(function(el) { el.value = nonces.fluentform; });' . "\n";
    $js .= '                document.querySelectorAll(\'.bricks-form input[name="nonce"]\').forEach(function(el) { el.value = nonces.bricks; });' . "\n";
    $js .= '                document.querySelectorAll(\'input[name="bricksforge_form_nonce"]\').forEach(function(el) { el.value = nonces.bricksforge; });' . "\n";
    $js .= '                document.querySelectorAll(\'input[name="bricksforge_nonce"]\').forEach(function(el) { el.value = nonces.bricksforge; });' . "\n";
    $js .= '            } catch(e) { console.warn(\'SPC nonce refresh error:\', e); }' . "\n";
    $js .= '        };' . "\n";
    $js .= '        xhr.send(\'action=spc_get_nonces\');' . "\n";
    $js .= '    }' . "\n";
    $js .= '    if (document.readyState === \'loading\') {' . "\n";
    $js .= '        document.addEventListener(\'DOMContentLoaded\', refreshNonces);' . "\n";
    $js .= '    } else { refreshNonces(); }' . "\n";
    $js .= '})();' . "\n";
    return $js;
}

// ============================================================
// 15. フロントエンド リンクプリフェッチ JS
// ============================================================
add_action('wp_enqueue_scripts', 'spc_enqueue_prefetch');
function spc_enqueue_prefetch() {
    $s = spc_get_settings();
    if (empty($s['prefetch_enabled'])) return;
    if (is_admin()) return;

    if (!empty($s['prefetch_mobile'])) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) return;
    }

    $excludes = array_filter(array_map('trim', explode("\n", $s['prefetch_excludes'] ?? '')));
    $config   = [
        'concurrency' => (int) ($s['prefetch_concurrency'] ?? 2),
        'delay'       => (int) ($s['prefetch_delay']       ?? 2000),
        'excludes'    => array_values($excludes),
        'siteUrl'     => home_url('/'),
        'mobile'      => !empty($s['prefetch_mobile']),
    ];

    wp_register_script('spc-prefetch', false, [], null, true);
    wp_enqueue_script('spc-prefetch');
    wp_add_inline_script('spc-prefetch', spc_get_prefetch_js($config));
}

function spc_get_prefetch_js($config) {
    $config_json = wp_json_encode($config);
    $js  = '(function() {' . "\n";
    $js .= '    var SPC = ' . $config_json . ';' . "\n";
    $js .= '    if (SPC.mobile && /Mobile|Android|iPhone|iPad/i.test(navigator.userAgent)) return;' . "\n";
    $js .= '    if (navigator.connection && navigator.connection.saveData) return;' . "\n";
    $js .= '    if (navigator.connection && /slow-2g|2g/.test(navigator.connection.effectiveType)) return;' . "\n";
    $js .= '    var prefetched = new Set();' . "\n";
    $js .= '    var queue = [];' . "\n";
    $js .= '    var active = 0;' . "\n";
    $js .= '    function isExcluded(url) {' . "\n";
    $js .= '        if (url.indexOf(SPC.siteUrl) !== 0) return true;' . "\n";
    $js .= '        if (url.indexOf(\'#\') !== -1) return true;' . "\n";
    $js .= '        if (/wp-admin|wp-login|admin-ajax|rest_route|\?nonce|logout/.test(url)) return true;' . "\n";
    $js .= '        if (/\.(pdf|zip|gz|mp4|mp3|avi|mov|wmv|exe|dmg)$/i.test(url)) return true;' . "\n";
    $js .= '        for (var i = 0; i < SPC.excludes.length; i++) {' . "\n";
    $js .= '            if (SPC.excludes[i] && url.indexOf(SPC.excludes[i]) !== -1) return true;' . "\n";
    $js .= '        }' . "\n";
    $js .= '        return false;' . "\n";
    $js .= '    }' . "\n";
    $js .= '    function prefetch(url) {' . "\n";
    $js .= '        if (prefetched.has(url)) return;' . "\n";
    $js .= '        prefetched.add(url);' . "\n";
    $js .= '        active++;' . "\n";
    $js .= '        var link = document.createElement(\'link\');' . "\n";
    $js .= '        link.rel = \'prefetch\';' . "\n";
    $js .= '        link.href = url;' . "\n";
    $js .= '        link.onload = link.onerror = function() { active--; processQueue(); };' . "\n";
    $js .= '        document.head.appendChild(link);' . "\n";
    $js .= '    }' . "\n";
    $js .= '    function processQueue() {' . "\n";
    $js .= '        while (active < SPC.concurrency && queue.length > 0) { prefetch(queue.shift()); }' . "\n";
    $js .= '    }' . "\n";
    $js .= '    function collectLinks() {' . "\n";
    $js .= '        document.querySelectorAll(\'a[href]\').forEach(function(link) {' . "\n";
    $js .= '            var url = link.href;' . "\n";
    $js .= '            try { url = new URL(link.href).href; } catch(e) { return; }' . "\n";
    $js .= '            if (!isExcluded(url) && !prefetched.has(url)) queue.push(url);' . "\n";
    $js .= '        });' . "\n";
    $js .= '        processQueue();' . "\n";
    $js .= '    }' . "\n";
    $js .= '    function run() {' . "\n";
    $js .= '        setTimeout(function() {' . "\n";
    $js .= '            if (\'requestIdleCallback\' in window) { requestIdleCallback(collectLinks, { timeout: 3000 }); }' . "\n";
    $js .= '            else { collectLinks(); }' . "\n";
    $js .= '        }, SPC.delay);' . "\n";
    $js .= '    }' . "\n";
    $js .= '    if (document.readyState === \'complete\') { run(); }' . "\n";
    $js .= '    else { window.addEventListener(\'load\', run); }' . "\n";
    $js .= '})();' . "\n";
    return $js;
}

// ============================================================
// 16. 管理画面
// ============================================================
add_action('admin_menu', 'spc_add_admin_menu');
function spc_add_admin_menu() {
    add_menu_page(
        'Smile Performance',
        'Smile Performance',
        'manage_options',
        'spc-settings',
        'spc_render_settings_page',
        'dashicons-performance',
        80
    );
    add_submenu_page(
        'spc-settings',
        'PageSpeed分析',
        '📊 PageSpeed分析',
        'manage_options',
        'spc-pagespeed',
        'spc_render_pagespeed_page'
    );
    add_submenu_page(
        'spc-settings',
        'WebP設定',
        '🖼 WebP設定',
        'manage_options',
        'spc-webp',
        'spc_render_webp_page'
    );
    add_submenu_page(
        'spc-settings',
        'Cloudflare API連携',
        '☁ Cloudflare連携',
        'manage_options',
        'spc-cloudflare',
        'spc_render_cf_page'
    );
    add_submenu_page(
        'spc-settings',
        '更新履歴',
        '📋 更新履歴',
        'manage_options',
        'spc-changelog',
        'spc_render_changelog_page'
    );
}

add_action('admin_init', 'spc_register_settings');
function spc_register_settings() {
    register_setting('spc_settings_group', 'spc_settings', 'spc_sanitize_settings');
}

function spc_sanitize_settings($input) {
    $all_types        = array_merge(['post', 'page'], array_values(spc_get_custom_post_types()));
    $valid_modes      = ['standalone', 'litespeed'];
    $valid_intervals  = ['spc_1hour','spc_2hours','spc_4hours','spc_6hours','spc_12hours','spc_24hours','off'];
    $valid_delays     = [0, 1000, 2000, 3000, 5000];
    $valid_expiry     = [1, 3, 6, 12, 24, 48];

    $s = [];
    $s['cache_mode'] = in_array($input['cache_mode'] ?? '', $valid_modes, true)
        ? $input['cache_mode'] : 'standalone';

    $input_expiry        = (int)($input['cache_expiry_hours'] ?? 12);
    $s['cache_expiry_hours'] = in_array($input_expiry, $valid_expiry, true) ? $input_expiry : 12;

    $s['clear_all_post_types'] = [];
    if (!empty($input['clear_all_post_types']) && is_array($input['clear_all_post_types'])) {
        $s['clear_all_post_types'] = array_values(array_intersect($input['clear_all_post_types'], $all_types));
    }

    $s['preload_interval'] = in_array($input['preload_interval'] ?? '', $valid_intervals, true)
        ? $input['preload_interval'] : 'spc_6hours';

    $s['db_clean_interval'] = in_array($input['db_clean_interval'] ?? '', $valid_intervals, true)
        ? $input['db_clean_interval'] : 'spc_24hours';

    // CSS圧縮上限サイズ（1〜500KBの範囲で）
    $css_limit = (int)($input['tuning_css_minify_limit'] ?? 110);
    $s['tuning_css_minify_limit'] = max(1, min(500, $css_limit));

    foreach (['tuning_dns_prefetch','tuning_dns_prefetch_fonts','tuning_emoji','tuning_oembed','tuning_query_strings','tuning_rss','tuning_header_cleanup','tuning_iframe_lazy','tuning_image_blur_lazy','tuning_js_defer','tuning_lcp_preload','tuning_font_preload','tuning_video_preload_none','tuning_video_lazy','tuning_image_lazy_enhance','tuning_browser_cache','tuning_css_minify','tuning_css_minify_inline','tuning_gzip'] as $key) {
        $s[$key] = !empty($input[$key]) ? 1 : 0;
    }
    $lcp_urls = $input['tuning_lcp_preload_url'] ?? '';
    $lcp_url_lines = array_filter(array_map('trim', explode("\n", $lcp_urls)));
    $lcp_url_sanitized = array_map('esc_url_raw', $lcp_url_lines);
    $s['tuning_lcp_preload_url'] = implode("\n", $lcp_url_sanitized);

    // ブラウザキャッシュ .htaccess 書き込み制御
    $prev = get_option('spc_settings', []);
    $prev_cache = !empty($prev['tuning_browser_cache']);
    if ($s['tuning_browser_cache'] && !$prev_cache) {
        spc_write_browser_cache_htaccess();
    } elseif (!$s['tuning_browser_cache'] && $prev_cache) {
        spc_remove_browser_cache_htaccess();
    }

    $s['nonce_refresh']        = !empty($input['nonce_refresh'])        ? 1 : 0;
    $s['prefetch_enabled']     = !empty($input['prefetch_enabled'])      ? 1 : 0;
    $s['prefetch_mobile']      = !empty($input['prefetch_mobile'])       ? 1 : 0;
    $s['prefetch_concurrency'] = min(5, max(1, (int)($input['prefetch_concurrency'] ?? 2)));
    $s['prefetch_excludes']    = sanitize_textarea_field($input['prefetch_excludes'] ?? '');
    $input_delay               = (int)($input['prefetch_delay'] ?? 2000);
    $s['prefetch_delay']       = in_array($input_delay, $valid_delays, true) ? $input_delay : 2000;

    // GA4ローカル化
    $s['ga4_local_enabled']  = !empty($input['ga4_local_enabled']) ? 1 : 0;
    $s['ga4_measurement_id'] = preg_replace('/[^A-Za-z0-9\-]/', '', $input['ga4_measurement_id'] ?? '');
    $s['ga4_update_interval']= in_array($input['ga4_update_interval'] ?? '', $valid_intervals, true)
        ? $input['ga4_update_interval'] : 'spc_24hours';

    // GA4スクリプト更新Cronのスケジュール反映
    $current_ga4 = wp_get_schedule('spc_ga4_update_event');
    if (!$s['ga4_local_enabled']) {
        if ($current_ga4) wp_clear_scheduled_hook('spc_ga4_update_event');
    } else {
        if (!$current_ga4) {
            wp_schedule_event(time(), $s['ga4_update_interval'], 'spc_ga4_update_event');
            spc_ga4_fetch_script($s['ga4_measurement_id']);
        } elseif ($current_ga4 !== $s['ga4_update_interval']) {
            wp_clear_scheduled_hook('spc_ga4_update_event');
            wp_schedule_event(time(), $s['ga4_update_interval'], 'spc_ga4_update_event');
        }
    }

    // yakuhan設定
    $s['yakuhan_jp_enabled']      = !empty($input['yakuhan_jp_enabled'])   ? 1 : 0;
    $s['yakuhan_mp_enabled']      = !empty($input['yakuhan_mp_enabled'])   ? 1 : 0;
    $s['yakuhan_update_interval'] = in_array($input['yakuhan_update_interval'] ?? '', $valid_intervals, true)
        ? $input['yakuhan_update_interval'] : 'spc_24hours';

    // yakuhan Cronスケジュール反映
    $yakuhan_any     = $s['yakuhan_jp_enabled'] || $s['yakuhan_mp_enabled'];
    $current_yakuhan = wp_get_schedule('spc_yakuhan_update_event');
    if (!$yakuhan_any) {
        if ($current_yakuhan) wp_clear_scheduled_hook('spc_yakuhan_update_event');
    } else {
        if (!$current_yakuhan) {
            wp_schedule_event(time(), $s['yakuhan_update_interval'], 'spc_yakuhan_update_event');
            spc_yakuhan_fetch('all');
        } elseif ($current_yakuhan !== $s['yakuhan_update_interval']) {
            wp_clear_scheduled_hook('spc_yakuhan_update_event');
            wp_schedule_event(time(), $s['yakuhan_update_interval'], 'spc_yakuhan_update_event');
        }
    }

    // プリロードスケジュール即時反映
    $current_preload = wp_get_schedule('spc_preload_event');
    if ($current_preload !== $s['preload_interval']) {
        wp_clear_scheduled_hook('spc_preload_event');
        if ($s['preload_interval'] !== 'off') {
            wp_schedule_event(time(), $s['preload_interval'], 'spc_preload_event');
        }
    }

    // DBスケジュール即時反映
    $current = wp_get_schedule('spc_db_clean_event');
    if ($current !== $s['db_clean_interval']) {
        wp_clear_scheduled_hook('spc_db_clean_event');
        if ($s['db_clean_interval'] !== 'off') {
            wp_schedule_event(time(), $s['db_clean_interval'], 'spc_db_clean_event');
        }
    }

    // モード切り替え時の処理
    $prev_settings = get_option('spc_settings', []);
    $prev_mode     = $prev_settings['cache_mode'] ?? 'standalone';

    if ($prev_mode !== $s['cache_mode']) {
        if ($s['cache_mode'] === 'litespeed') {
            // 単独→LiteSpeed切り替え：PHPキャッシュを全削除してからLSに委譲
            if (is_dir(SPC_CACHE_DIR)) {
                spc_delete_directory(SPC_CACHE_DIR);
                wp_mkdir_p(SPC_CACHE_DIR);
            }
            // プリロードCronを停止
            wp_clear_scheduled_hook('spc_preload_event');
            // ブラウザキャッシュを自動OFF・.htaccessルールを除去
            if (!empty($s['tuning_browser_cache'])) {
                $s['tuning_browser_cache'] = 0;
                spc_remove_browser_cache_htaccess();
            }
        } elseif ($s['cache_mode'] === 'standalone') {
            // LiteSpeed→単独切り替え：残存PHPキャッシュを念のため削除
            if (is_dir(SPC_CACHE_DIR)) {
                spc_delete_directory(SPC_CACHE_DIR);
                wp_mkdir_p(SPC_CACHE_DIR);
            }
        }
    }

    return $s;
}

function spc_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $s              = spc_get_settings();
    $mode           = $s['cache_mode']           ?? 'standalone';
    $selected       = $s['clear_all_post_types'] ?? [];
    $is_all         = empty($selected);
    $cache_expiry   = (int)($s['cache_expiry_hours'] ?? 12);
    $preload_interval = $s['preload_interval']   ?? 'spc_6hours';
    $db_interval    = $s['db_clean_interval']    ?? 'spc_24hours';
    $last_cleaned   = get_option('spc_db_last_cleaned', null);
    $next_scheduled = wp_next_scheduled('spc_db_clean_event');
    $ls_active      = spc_litespeed_active();

    $all_types   = array_merge(['post', 'page'], array_values(spc_get_custom_post_types()));
    $type_labels = [];
    foreach ($all_types as $type) {
        $obj = get_post_type_object($type);
        $type_labels[$type] = $obj ? $obj->labels->singular_name : $type;
    }

    $cache_count = 0; $cache_size = 0;
    if (is_dir(SPC_CACHE_DIR)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SPC_CACHE_DIR));
        foreach ($iterator as $file) {
            if ($file->isFile()) { $cache_count++; $cache_size += $file->getSize(); }
        }
    }

    $db = spc_get_db_stats();

    $interval_labels = [
        'spc_1hour'  => '1時間ごと',  'spc_2hours'  => '2時間ごと',
        'spc_4hours' => '4時間ごと',  'spc_6hours'  => '6時間ごと',
        'spc_12hours'=> '12時間ごと', 'spc_24hours' => '24時間ごと',
        'off'        => 'オフ（自動クリーンしない）',
    ];

    $expiry_labels = [
        1  => '1時間',
        3  => '3時間',
        6  => '6時間',
        12 => '12時間（推奨）',
        24 => '24時間',
        48 => '48時間',
    ];

    $preload_interval_labels = [
        'spc_1hour'  => '1時間ごと',
        'spc_2hours' => '2時間ごと',
        'spc_4hours' => '4時間ごと',
        'spc_6hours' => '6時間ごと（推奨）',
        'spc_12hours'=> '12時間ごと',
        'spc_24hours'=> '24時間ごと',
        'off'        => 'オフ（プリロードしない）',
    ];

    $delay_labels = [
        0    => 'ページ読み込み直後',
        1000 => '1秒後',
        2000 => '2秒後（推奨）',
        3000 => '3秒後',
        5000 => '5秒後',
    ];

    $tuning_items = [
        ['key' => 'tuning_dns_prefetch_fonts',  'label' => 'DNSプリフェッチ（Google Fonts）',         'desc' => 'Google Fontsを使用している場合のみオンに。BricksでGoogle Fontsを無効化している場合はオフ推奨'],
        ['key' => 'tuning_emoji',               'label' => '絵文字スクリプト削除',                    'desc' => 'WordPressデフォルトの絵文字JS/CSSを除去。絵文字はUnicodeでそのまま表示される'],
        ['key' => 'tuning_oembed',              'label' => 'oEmbed無効化',                           'desc' => '外部コンテンツ埋め込み検出のリクエストを削減。YouTube等の手動埋め込みは引き続き動作'],
        ['key' => 'tuning_query_strings',       'label' => 'クエリ文字列除去',                       'desc' => 'CSS/JSのURLからバージョン文字列を除去しブラウザキャッシュ効率を改善'],
        ['key' => 'tuning_header_cleanup',      'label' => 'ヘッダー情報クリーンアップ',              'desc' => 'WPバージョン情報・wlwmanifest・RSDリンク等の不要なhead要素を除去'],
        ['key' => 'tuning_iframe_lazy',         'label' => 'iframe遅延読み込み',                     'desc' => 'Google Maps等のiframeにloading="lazy"を自動付与。Bricksの動画要素は標準搭載のため対象外'],
        ['key' => 'tuning_image_blur_lazy',     'label' => '画像ブラーフェードイン（LQIP風）',       'desc' => 'lazy load対象の画像をぼかした状態で即表示し、読み込み完了後にクリアにフェード。スライダー等で問題が出る場合はオフに'],
        ['key' => 'tuning_js_defer',            'label' => 'scroll-timeline.js defer化',             'desc' => 'スクロールアニメーション用JSをdefer読み込みにしレンダリングブロックを解消'],
        ['key' => 'tuning_font_preload',        'label' => 'フォントpreload',                        'desc' => 'Bricksのローカルフォント（woff2）をpreloadしクリティカルパスを短縮。wp-content/uploads/fonts/以下のwoff2を最大4件対象'],
        ['key' => 'tuning_video_preload_none',  'label' => 'video preload="none"自動付与',            'desc' => '<video>タグにpreload="none"を自動付与しページ読み込み時の動画先読みを抑制。YouTube/Vimeo（iframe）は対象外。autoplay動画・data-no-preload属性付きはスキップ'],
        ['key' => 'tuning_video_lazy',          'label' => 'video 遅延読み込み',                     'desc' => '<video>タグをビューポートに入るまで読み込まない。ページ下部の動画が多い場合に有効。YouTube/Vimeo（iframe）は対象外。autoplay動画・data-no-lazy属性付きはスキップ'],
        ['key' => 'tuning_image_lazy_enhance',  'label' => '画像遅延読み込み強化',                   'desc' => 'loading="lazy"が付いていない画像に自動付与して読み込みを最適化。Bricks処理済み画像・LCP画像（fetchpriority="high"）・data-no-lazy属性付きはスキップ'],
        ['key' => 'tuning_rss',                 'label' => 'RSSフィード無効化',                      'desc' => 'RSSを使用しない場合は除去可能。RSSリーダー等で購読している場合はオフ推奨'],
    ];

    // LCPプリロードは専用UIで別途表示
    $lcp_preload_enabled = !empty($s['tuning_lcp_preload']);
    $lcp_preload_url     = $s['tuning_lcp_preload_url'] ?? '';

    // ---- notices ----
    $html = '<div class="wrap">';
    $html .= '<h1>⚡ Smile Performance</h1>';
    if (isset($_GET['settings-updated'])) $html .= '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
    if (isset($_GET['db_done']))          $html .= '<div class="notice notice-success is-dismissible"><p>✅ データベースのクリーンアップが完了しました。</p></div>';
    if (isset($_GET['ga4_ok']))           $html .= '<div class="notice notice-success is-dismissible"><p>✅ GA4スクリプトを更新しました。</p></div>';
    if (isset($_GET['ga4_error']))        $html .= '<div class="notice notice-error is-dismissible"><p>⚠️ GA4スクリプトの取得に失敗しました。測定IDとサーバーのインターネット接続を確認してください。</p></div>';
    if (isset($_GET['yakuhan_ok']))       $html .= '<div class="notice notice-success is-dismissible"><p>✅ yakuhan CSSを更新しました。</p></div>';
    if (isset($_GET['yakuhan_error']))    $html .= '<div class="notice notice-error is-dismissible"><p>⚠️ yakuhan CSSの取得に失敗しました。サーバーのインターネット接続を確認してください。</p></div>';

    // ---- モードバッジ ----
    $html .= '<div style="margin:16px 0;">';
    if ($mode === 'litespeed') {
        $html .= '<span style="background:#0073aa;color:#fff;padding:4px 12px;border-radius:20px;font-size:.85em;font-weight:bold;">⚡ LiteSpeed Cache 併用モード</span>';
        if ($ls_active) {
            $html .= '<span style="background:#1d7a1d;color:#fff;padding:4px 12px;border-radius:20px;font-size:.85em;margin-left:8px;">✅ LiteSpeed Cache 検出済み</span>';
        } else {
            $html .= '<span style="background:#d63638;color:#fff;padding:4px 12px;border-radius:20px;font-size:.85em;margin-left:8px;">⚠️ LiteSpeed Cacheプラグインが見つかりません</span>';
        }
    } else {
        $html .= '<span style="background:#2271b1;color:#fff;padding:4px 12px;border-radius:20px;font-size:.85em;font-weight:bold;">🗂 単独モード</span>';
    }
    $html .= '</div>';

    // ---- キャッシュ統計 ----
    $html .= '<h2 style="border-left:4px solid #2271b1;padding-left:10px;margin-top:24px;">📦 ページキャッシュ</h2>';
    $html .= '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin:12px 0;display:flex;align-items:center;gap:32px;flex-wrap:wrap;">';
    $html .= '<div><div style="font-size:.8em;color:#666;">キャッシュファイル数</div><div style="font-size:1.6em;font-weight:bold;">' . number_format($cache_count) . ' <span style="font-size:.6em;font-weight:normal;">ファイル</span></div></div>';
    $html .= '<div><div style="font-size:.8em;color:#666;">キャッシュ容量</div><div style="font-size:1.6em;font-weight:bold;">' . round($cache_size / 1024, 1) . ' <span style="font-size:.6em;font-weight:normal;">KB</span></div></div>';
    if ($mode === 'standalone') {
        $clear_url = esc_url(wp_nonce_url(admin_url('admin-post.php?action=spc_clear_cache'), 'spc_clear_cache'));
        $html .= '<div style="margin-left:auto;"><a href="' . $clear_url . '" class="button button-secondary" onclick="return confirm(\'全キャッシュを削除しますか？\');">🗑 今すぐ全キャッシュクリア</a></div>';
    }
    $html .= '</div>';
    if ($mode === 'litespeed') {
        $html .= '<div style="background:#f0f6fc;border:1px solid #72aee6;border-radius:4px;padding:10px 16px;margin:-4px 0 12px;font-size:.9em;color:#0073aa;">⚡ LiteSpeed Cache 併用モードで動作中です。ページキャッシュはLiteSpeed Cacheが管理します。</div>';
    }

    // ---- DB統計 ----
    $html .= '<h2 style="border-left:4px solid #2271b1;padding-left:10px;margin-top:32px;">🗄 データベース状況</h2>';
    $html .= '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin:12px 0;">';
    $html .= '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:16px;">';
    $stats_items = [
        ['label'=>'リビジョン',            'value'=>$db['revisions'],         'unit'=>'件','warn'=>50],
        ['label'=>'自動保存',              'value'=>$db['auto_drafts'],        'unit'=>'件','warn'=>20],
        ['label'=>'ゴミ箱（投稿）',        'value'=>$db['trash_posts'],        'unit'=>'件','warn'=>10],
        ['label'=>'期限切れトランジェント','value'=>$db['expired_transients'], 'unit'=>'件','warn'=>100],
        ['label'=>'スパムコメント',        'value'=>$db['spam_comments'],      'unit'=>'件','warn'=>10],
        ['label'=>'DB合計サイズ',          'value'=>$db['total_size'],         'unit'=>'MB','warn'=>999],
        ['label'=>'オーバーヘッド',        'value'=>$db['overhead'],           'unit'=>'MB','warn'=>5],
    ];
    foreach ($stats_items as $item) {
        $color = ($item['value'] > $item['warn']) ? '#d63638' : '#1d7a1d';
        $html .= '<div style="min-width:120px;">';
        $html .= '<div style="font-size:.8em;color:#666;">' . $item['label'] . '</div>';
        $html .= '<div style="font-size:1.5em;font-weight:bold;color:' . $color . '">' . number_format((float)$item['value']) . ' <span style="font-size:.6em;font-weight:normal;color:#333;">' . $item['unit'] . '</span></div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '<div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;border-top:1px solid #eee;padding-top:14px;">';
    $html .= '<div style="font-size:.9em;color:#555;">🕐 前回クリーン：<strong>' . ($last_cleaned ? esc_html($last_cleaned) : '未実行') . '</strong>';
    if ($next_scheduled) $html .= '　／　次回予定：<strong>' . date_i18n('Y-m-d H:i', $next_scheduled) . '</strong>';
    $html .= '</div>';
    $db_clean_url = esc_url(wp_nonce_url(admin_url('admin-post.php?action=spc_manual_db_clean'), 'spc_manual_db_clean'));
    $html .= '<div style="margin-left:auto;"><a href="' . $db_clean_url . '" class="button button-primary" onclick="return confirm(\'今すぐデータベースをクリーンアップしますか？\');">🧹 今すぐクリーンアップ</a></div>';
    $html .= '</div></div>';

    // ---- 設定フォーム ----
    $html .= '<h2 style="border-left:4px solid #2271b1;padding-left:10px;margin-top:32px;">⚙️ 設定</h2>';
    $html .= '<form method="post" action="options.php">';
    $html .= wp_nonce_field('spc_settings_group-options', '_wpnonce', true, false);
    $html .= '<input type="hidden" name="option_page" value="spc_settings_group">';
    $html .= '<input type="hidden" name="action" value="update">';
    $html .= '<table class="form-table" role="presentation">';

    // 動作モード
    $modes = [
        'standalone' => ['label' => '単独モード',                 'desc' => 'キャッシュ機能をフル稼働。LiteSpeedサーバーでは無い場合に使用。'],
        'litespeed'  => ['label' => 'LiteSpeed Cache 併用モード', 'desc' => 'PHPキャッシュ・プリロードCronを無効化しLiteSpeed Cacheに委譲。DBクリーン・チューニング・GA4ローカル化・リンクプリフェッチは引き続き動作。管理バーのクリアボタンは非表示になります。'],
    ];
    $html .= '<tr><th scope="row">動作モード</th><td>';
    foreach ($modes as $value => $info) {
        $disabled   = ($value === 'litespeed' && !$ls_active) ? ' disabled' : '';
        $opacity    = ($value === 'litespeed' && !$ls_active) ? 'opacity:.5;' : '';
        $checked    = ($mode === $value) ? ' checked' : '';
        $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;' . $opacity . '">';
        $html .= '<input type="radio" name="spc_settings[cache_mode]" value="' . esc_attr($value) . '"' . $checked . $disabled . ' style="margin-top:3px;">';
        $html .= '<span><strong>' . esc_html($info['label']) . '</strong><br>';
        $html .= '<span style="font-size:.85em;color:#666;">' . esc_html($info['desc']);
        if ($value === 'litespeed' && !$ls_active) $html .= '<span style="color:#d63638;">（LiteSpeed Cacheプラグインが有効化されていません）</span>';
        $html .= '</span></span></label>';
    }
    $html .= '</td></tr>';

    // 投稿タイプ
    $all_types   = array_merge(['post', 'page'], array_values(spc_get_custom_post_types()));
    $type_labels = [];
    foreach ($all_types as $type) {
        $obj = get_post_type_object($type);
        $type_labels[$type] = $obj ? $obj->labels->singular_name : $type;
    }
    $is_all_checked = $is_all ? ' checked' : '';
    $list_style     = $is_all ? 'opacity:.4;pointer-events:none;' : '';
    $html .= '<tr><th scope="row">保存時に全キャッシュクリアする投稿タイプ</th><td>';
    $html .= '<p class="description" style="margin-bottom:12px;">チェックした投稿タイプを保存した際、全ページのキャッシュをクリアします。<br><strong>何もチェックしない（全て）= どの投稿タイプ保存時も全キャッシュクリア</strong></p>';
    $html .= '<label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;font-weight:bold;"><input type="checkbox" id="spc_check_all"' . $is_all_checked . ' onchange="spcToggleAll(this)"> すべての投稿タイプ（デフォルト）</label><br>';
    $html .= '<hr style="margin:8px 0;"><div id="spc_type_list" style="' . $list_style . '">';
    foreach ($type_labels as $type => $label) {
        $checked = in_array($type, $selected, true) ? ' checked' : '';
        $html .= '<label style="display:inline-flex;align-items:center;gap:6px;margin:4px 16px 4px 0;">';
        $html .= '<input type="checkbox" name="spc_settings[clear_all_post_types][]" value="' . esc_attr($type) . '"' . $checked . '>';
        $html .= esc_html($label) . ' <span style="color:#999;font-size:.85em;">(' . esc_html($type) . ')</span></label>';
    }
    $html .= '</div></td></tr>';

    // キャッシュ有効期限
    $expiry_labels = [1=>'1時間',3=>'3時間',6=>'6時間',12=>'12時間（推奨）',24=>'24時間',48=>'48時間'];
    $html .= '<tr><th scope="row">キャッシュ有効期限</th><td><select name="spc_settings[cache_expiry_hours]">';
    foreach ($expiry_labels as $val => $label) {
        $sel = ($cache_expiry === $val) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $sel . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select><p class="description">キャッシュの有効期限です。期限が切れたキャッシュは次回アクセス時に自動再生成されます。</p></td></tr>';

    // プリロードCron
    $preload_interval_labels = ['spc_1hour'=>'1時間ごと','spc_2hours'=>'2時間ごと','spc_4hours'=>'4時間ごと','spc_6hours'=>'6時間ごと（推奨）','spc_12hours'=>'12時間ごと','spc_24hours'=>'24時間ごと','off'=>'オフ（プリロードしない）'];
    $html .= '<tr><th scope="row">プリロードCron間隔</th><td><select name="spc_settings[preload_interval]">';
    foreach ($preload_interval_labels as $val => $label) {
        $sel = ($preload_interval === $val) ? ' selected' : '';
        $html .= '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select><p class="description">全ページを自動でプリロードする間隔です。オフにするとCronによる自動プリロードを無効化します。</p></td></tr>';

    // DBクリーン間隔
    $interval_labels = ['spc_1hour'=>'1時間ごと','spc_2hours'=>'2時間ごと','spc_4hours'=>'4時間ごと','spc_6hours'=>'6時間ごと','spc_12hours'=>'12時間ごと','spc_24hours'=>'24時間ごと','off'=>'オフ（自動クリーンしない）'];
    $html .= '<tr><th scope="row">データベース自動クリーン間隔</th><td><select name="spc_settings[db_clean_interval]">';
    foreach ($interval_labels as $val => $label) {
        $sel = ($db_interval === $val) ? ' selected' : '';
        $html .= '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select><p class="description">全モード共通で動作します。</p></td></tr>';

    // Bricks最適化チューニング
    $tuning_items = [
        ['key'=>'tuning_dns_prefetch_fonts', 'label'=>'DNSプリフェッチ（Google Fonts）',  'desc'=>'Google Fontsを使用している場合のみオンに。BricksでGoogle Fontsを無効化している場合はオフ推奨'],
        ['key'=>'tuning_emoji',              'label'=>'絵文字スクリプト削除',              'desc'=>'WordPressデフォルトの絵文字JS/CSSを除去。絵文字はUnicodeでそのまま表示される'],
        ['key'=>'tuning_oembed',             'label'=>'oEmbed無効化',                     'desc'=>'外部コンテンツ埋め込み検出のリクエストを削減。YouTube等の手動埋め込みは引き続き動作'],
        ['key'=>'tuning_query_strings',      'label'=>'クエリ文字列除去',                 'desc'=>'CSS/JSのURLからバージョン文字列を除去しブラウザキャッシュ効率を改善'],
        ['key'=>'tuning_header_cleanup',     'label'=>'ヘッダー情報クリーンアップ',       'desc'=>'WPバージョン情報・wlwmanifest・RSDリンク等の不要なhead要素を除去'],
        ['key'=>'tuning_iframe_lazy',        'label'=>'iframe遅延読み込み',               'desc'=>'Google Maps等のiframeにloading="lazy"を自動付与。Bricksの動画要素は標準搭載のため対象外'],
        ['key'=>'tuning_image_blur_lazy',    'label'=>'画像ブラーフェードイン（LQIP風）','desc'=>'lazy load対象の画像をぼかした状態で即表示し、読み込み完了後にクリアにフェード。スライダー等で問題が出る場合はオフに'],
        ['key'=>'tuning_js_defer',           'label'=>'scroll-timeline.js defer化',       'desc'=>'スクロールアニメーション用JSをdefer読み込みにしレンダリングブロックを解消'],
        ['key'=>'tuning_font_preload',       'label'=>'フォントpreload',                  'desc'=>'Bricksのローカルフォント（woff2）をpreloadしクリティカルパスを短縮。wp-content/uploads/fonts/以下のwoff2を最大4件対象'],
        ['key'=>'tuning_video_preload_none', 'label'=>'video preload="none"自動付与',     'desc'=>'<video>タグにpreload="none"を自動付与しページ読み込み時の動画先読みを抑制。YouTube/Vimeo（iframe）は対象外。autoplay動画・data-no-preload属性付きはスキップ'],
        ['key'=>'tuning_video_lazy',         'label'=>'video 遅延読み込み',               'desc'=>'<video>タグをビューポートに入るまで読み込まない。ページ下部の動画が多い場合に有効。YouTube/Vimeo（iframe）は対象外。autoplay動画・data-no-lazy属性付きはスキップ'],
        ['key'=>'tuning_image_lazy_enhance', 'label'=>'画像遅延読み込み強化',             'desc'=>'loading="lazy"が付いていない画像に自動付与して読み込みを最適化。Bricks処理済み画像・LCP画像（fetchpriority="high"）・data-no-lazy属性付きはスキップ'],
        ['key'=>'tuning_rss',                'label'=>'RSSフィード無効化',                'desc'=>'RSSを使用しない場合は除去可能。RSSリーダー等で購読している場合はオフ推奨'],
    ];
    $html .= '<tr><th scope="row">Bricks最適化チューニング</th><td>';
    foreach ($tuning_items as $item) {
        $checked = !empty($s[$item['key']]) ? ' checked' : '';
        $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;">';
        $html .= '<input type="checkbox" name="spc_settings[' . esc_attr($item['key']) . ']" value="1"' . $checked . ' style="margin-top:2px;">';
        $html .= '<span><strong>' . esc_html($item['label']) . '</strong><br>';
        $html .= '<span style="font-size:.85em;color:#666;">' . esc_html($item['desc']) . '</span></span></label>';
    }
    // CSS圧縮
    $css_minify_checked  = !empty($s['tuning_css_minify']) ? ' checked' : '';
    $css_minify_disabled = ($mode === 'litespeed');
    $css_minify_opacity  = $css_minify_disabled ? 'opacity:.45;' : '';
    $css_minify_dis_attr = $css_minify_disabled ? ' disabled' : '';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px;' . $css_minify_opacity . '">';
    $html .= '<input type="checkbox" name="spc_settings[tuning_css_minify]" value="1"' . $css_minify_checked . $css_minify_dis_attr . ' style="margin-top:2px;">';
    $html .= '<span><strong>CSS圧縮</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">CSSのコメント・空白を除去してファイルサイズを削減します（単独モードのみ有効）。<br>';
    if ($css_minify_disabled) {
        $html .= '<span style="color:#d63638;font-weight:bold;">⚠️ LiteSpeed Cache 併用モード時はオフになります。</span><br>';
    }
    $html .= 'LiteSpeed Cache併用モード時には、LiteSpeed Cacheの設定で対応します。「ページの最適化」⇒「CSS設定」⇒「CSS圧縮化」をオンにして下さい。他の設定はオフにして下さい。</span></span></label>';

    // CSS圧縮上限サイズ
    $css_limit_val = (int)($s['tuning_css_minify_limit'] ?? 110);
    $css_limit_opacity = $css_minify_disabled ? 'opacity:.45;' : '';
    $html .= '<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;padding-left:26px;' . $css_limit_opacity . '">';
    $html .= '<label for="spc_css_minify_limit" style="font-size:13px;color:#444;">圧縮対象の上限ファイルサイズ：</label>';
    $html .= '<input type="number" id="spc_css_minify_limit" name="spc_settings[tuning_css_minify_limit]" value="' . esc_attr($css_limit_val) . '" min="1" max="500"' . $css_minify_dis_attr . ' style="width:70px;">';
    $html .= '<span style="font-size:13px;color:#444;">KB</span>';
    $html .= '<span style="font-size:12px;color:#888;">（デフォルト：110KB・推奨範囲：50〜200KB）</span>';
    $html .= '</div>';

    // インラインCSS圧縮
    $css_inline_checked = !empty($s['tuning_css_minify_inline']) ? ' checked' : '';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px;padding-left:26px;' . $css_minify_opacity . '">';
    $html .= '<input type="checkbox" name="spc_settings[tuning_css_minify_inline]" value="1"' . $css_inline_checked . $css_minify_dis_attr . ' style="margin-top:2px;">';
    $html .= '<span><strong>インラインCSS圧縮</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">HTMLに埋め込まれた&lt;style&gt;タグ内のCSSも圧縮します。<br>';
    $html .= '<span style="font-size:.85em;color:#d63638;font-weight:bold;">⚠️ BricksforgeまたはNextBricksをインストールしている場合はパフォーマンス悪化のリスクがあるため、オフを推奨します。</span></span></span></label>';

    // Gzip/Brotli圧縮
    $gzip_checked = !empty($s['tuning_gzip']) ? ' checked' : '';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px;">';
    $html .= '<input type="checkbox" name="spc_settings[tuning_gzip]" value="1"' . $gzip_checked . ' style="margin-top:2px;">';
    $html .= '<span><strong>テキスト圧縮（Gzip/Brotli）</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">HTMLの転送サイズを削減します。OpenLiteSpeed・Nginx等のサーバーが自動対応している場合は不要です。<br>';
    $html .= '<span style="color:#0073aa;">※ PageSpeed Insightsで「テキスト圧縮を有効にする」という警告が出ている場合のみオンにしてください。</span></span></span></label>';

    // ブラウザキャッシュ
    $bc_disabled  = ($mode === 'litespeed');
    $bc_opacity   = $bc_disabled ? 'opacity:.45;' : '';
    $bc_checked   = !empty($s['tuning_browser_cache']) ? ' checked' : '';
    $bc_dis_attr  = $bc_disabled ? ' disabled' : '';
    $bc_ls_notice = $bc_disabled ? '<br><span style="color:#d63638;font-weight:bold;">⚠️ LiteSpeed Cache 併用モード中はオフになります（LiteSpeed Cache側で制御してください）</span>' : '';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px;' . $bc_opacity . '">';
    $html .= '<input type="checkbox" id="spc_browser_cache_toggle" name="spc_settings[tuning_browser_cache]" value="1"' . $bc_checked . $bc_dis_attr . ' style="margin-top:2px;">';
    $html .= '<span><strong>ブラウザキャッシュ最適化</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">静的ファイル（画像・CSS・JS・フォント）の Cache-Control / Expires ヘッダーを .htaccess に書き込みます。リピーターの体感速度が向上します。' . $bc_ls_notice . '</span></span></label>';
    // LCPプリロード
    $lcp_vis = $lcp_preload_enabled ? '' : 'opacity:.4;pointer-events:none;';
    $lcp_chk = $lcp_preload_enabled ? ' checked' : '';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;margin-top:6px;">';
    $html .= '<input type="checkbox" id="spc_lcp_preload_toggle" name="spc_settings[tuning_lcp_preload]" value="1"' . $lcp_chk . ' onchange="spcToggleLcpUrl(this)" style="margin-top:2px;">';
    $html .= '<span><strong>LCPヒーロー画像</strong><br><span style="font-size:.85em;color:#666;">ヒーロー画像のURLを指定してpreload、fetchpriority=&quot;high&quot;、loading=&quot;eager&quot;を自動付与します。PC・モバイルで異なる画像の場合は両方のURLを入力してください。</span></span></label>';
    $html .= '<div id="spc_lcp_url_wrap" style="' . $lcp_vis . 'padding-left:24px;margin-bottom:10px;">';
    $html .= '<textarea name="spc_settings[tuning_lcp_preload_url]" rows="3" style="width:100%;max-width:560px;font-family:monospace;font-size:12px;" placeholder="https://example.com/wp-content/uploads/hero-pc.webp&#10;https://example.com/wp-content/uploads/hero-sp.webp">' . esc_textarea($lcp_preload_url) . '</textarea>';
    $html .= '<p class="description">1行に1URLで複数入力可能です。メディアライブラリから画像URLをコピーして貼り付けてください。</p>';
    $html .= '<div style="margin-top:10px;font-size:12px;color:#555;line-height:1.9;border-left:3px solid #72aee6;padding:8px 12px;background:#f0f6fc;border-radius:0 4px 4px 0;">';
    $html .= '<strong>※ヒーロー画像のブレークポイント設定について（PSIスコア向上に有効な場合があります）</strong><br>';
    $html .= '・画像要素の画像指定の下にある「追加ソース」を押します。<br>';
    $html .= '・ブレークポイントは「カスタム（メディアクエリ）」を選択し、<code>(max-width: 1366px)</code> を入力してください。<br>';
    $html .= '・全幅画像の場合は1080px程度の画像をアップするか、同じ画像を選択して「PSI-fit-PC（1080×689）」の画質を設定してください。<br>';
    $html .= '・全幅画像ではない場合は上記以下のサイズ（480pxなど）の画像を設定してください。';
    $html .= '</div></div>';
    $html .= '</td></tr>';

    // フォームnonce
    $nonce_checked = !empty($s['nonce_refresh']) ? ' checked' : '';
    $html .= '<tr><th scope="row">フォームnonce自動リフレッシュ</th><td>';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;">';
    $html .= '<input type="checkbox" name="spc_settings[nonce_refresh]" value="1"' . $nonce_checked . ' style="margin-top:2px;">';
    $html .= '<span><strong>キャッシュされたページのフォームnonceを自動で更新する</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">Fluent Forms Pro・Bricks標準フォーム・Bricksforge Pro Formsのnonceをページ読み込み時にAJAXで自動差し替えします。</span></span></label>';
    $html .= '</td></tr>';

    // リンクプリフェッチ
    $delay_labels = [0=>'ページ読み込み直後',1000=>'1秒後',2000=>'2秒後（推奨）',3000=>'3秒後',5000=>'5秒後'];
    $prefetch_checked = !empty($s['prefetch_enabled']) ? ' checked' : '';
    $prefetch_vis     = empty($s['prefetch_enabled']) ? 'opacity:.4;pointer-events:none;' : '';
    $mobile_checked   = !empty($s['prefetch_mobile']) ? ' checked' : '';
    $current_delay    = (int)($s['prefetch_delay'] ?? 2000);
    $html .= '<tr><th scope="row">リンクプリフェッチ<br><span style="font-size:.8em;font-weight:normal;color:#666;">（自動プリロード機能）</span></th><td>';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:14px;">';
    $html .= '<input type="checkbox" id="spc_prefetch_enabled" name="spc_settings[prefetch_enabled]" value="1"' . $prefetch_checked . ' onchange="spcTogglePrefetch(this)" style="margin-top:2px;">';
    $html .= '<span><strong>リンクプリフェッチを有効にする</strong><br><span style="font-size:.85em;color:#666;">ページ読み込み後、ブラウザのアイドル時間を使ってリンク先をバックグラウンドで先読み。ページ遷移が高速になります。</span></span></label>';
    $html .= '<div id="spc_prefetch_options" style="' . $prefetch_vis . 'padding-left:24px;border-left:3px solid #e0e0e0;">';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:12px;"><input type="checkbox" name="spc_settings[prefetch_mobile]" value="1"' . $mobile_checked . ' style="margin-top:2px;"><span><strong>モバイルでは無効にする</strong><br><span style="font-size:.85em;color:#666;">モバイル回線の通信量節約のため、スマートフォン・タブレットではプリフェッチを行いません。</span></span></label>';
    $html .= '<div style="margin-bottom:12px;"><label style="font-weight:bold;display:block;margin-bottom:4px;">プリフェッチ開始タイミング</label>';
    $html .= '<select name="spc_settings[prefetch_delay]">';
    foreach ($delay_labels as $val => $label) {
        $sel = ($current_delay === $val) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $sel . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select><p class="description">遅延を設けることでページ表示直後の負荷を軽減し、LiteSpeed Cache等との競合を防ぎます。</p></div>';
    $html .= '<div style="margin-bottom:12px;"><label style="font-weight:bold;display:block;margin-bottom:4px;">同時プリフェッチ数</label>';
    $html .= '<input type="number" name="spc_settings[prefetch_concurrency]" value="' . (int)($s['prefetch_concurrency'] ?? 2) . '" min="1" max="5" style="width:60px;">';
    $html .= '<span style="font-size:.85em;color:#666;margin-left:8px;">件（推奨：2。サーバー負荷が心配な場合は1に）</span></div>';
    $html .= '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">除外URL（1行に1つ）</label>';
    $html .= '<textarea name="spc_settings[prefetch_excludes]" rows="4" style="width:100%;max-width:500px;font-family:monospace;font-size:.85em;" placeholder="/cart&#10;/checkout&#10;/my-account">' . esc_textarea($s['prefetch_excludes'] ?? '') . '</textarea>';
    $html .= '<p class="description">wp-admin・wp-login・admin-ajax・外部リンク・ファイルダウンロードは自動除外されます。</p></div>';
    $html .= '</div></td></tr>';

    // GTM・GA設定
    $gtm_checked = !empty($s['tuning_dns_prefetch']) ? ' checked' : '';
    $html .= '<tr><th scope="row">GTM・GA 設定</th><td>';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:14px;">';
    $html .= '<input type="checkbox" name="spc_settings[tuning_dns_prefetch]" value="1"' . $gtm_checked . ' style="margin-top:2px;">';
    $html .= '<span><strong>DNSプリフェッチ・リソースヒント（GTM・GA）</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">Google TagManager・Google Analyticsへの接続を先行させ初回リクエストを高速化。<br>GA4ローカル化をオンにした場合は自動的に無効になります。GA4未使用の場合はオフ推奨。</span></span></label>';
    $html .= '</td></tr>';

    // GA4ローカル化
    $ga4_enabled      = !empty($s['ga4_local_enabled']);
    $ga4_id           = $s['ga4_measurement_id'] ?? '';
    $ga4_interval     = $s['ga4_update_interval'] ?? 'spc_24hours';
    $ga4_last_updated = get_option('spc_ga4_last_updated', null);
    $ga4_file_exists  = file_exists(spc_ga4_local_path());
    $ga4_interval_labels = ['spc_12hours'=>'12時間ごと','spc_24hours'=>'24時間ごと（推奨）','spc_48hours'=>'48時間ごと','weekly'=>'週1回'];
    $ga4_checked = $ga4_enabled ? ' checked' : '';
    $ga4_vis     = !$ga4_enabled ? 'opacity:.4;pointer-events:none;' : '';
    $html .= '<tr><th scope="row">GA4ローカル化</th><td>';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:14px;">';
    $html .= '<input type="checkbox" id="spc_ga4_enabled" name="spc_settings[ga4_local_enabled]" value="1"' . $ga4_checked . ' onchange="spcToggleGa4(this)" style="margin-top:2px;">';
    $html .= '<span><strong>GA4スクリプトをローカルサーバーからホストする</strong><br>';
    $html .= '<span style="font-size:.85em;color:#666;">GA4スクリプトを自サーバーに保存して配信します。外部接続が不要になりPageSpeed Insightsの警告が解消されます。<br>※ WPCodeboxやBricksに埋め込んでいるGA4タグは重複するため削除してください。</span></span></label>';
    $html .= '<div id="spc_ga4_options" style="' . $ga4_vis . 'padding-left:24px;border-left:3px solid #e0e0e0;">';
    $html .= '<div style="margin-bottom:14px;"><label style="font-weight:bold;display:block;margin-bottom:4px;">GA4 測定ID</label>';
    $html .= '<input type="text" name="spc_settings[ga4_measurement_id]" value="' . esc_attr($ga4_id) . '" placeholder="G-XXXXXXXXXX" style="width:200px;font-family:monospace;">';
    $html .= '<p class="description">Googleアナリティクスの管理画面 → データストリーム → 測定IDをコピーしてください。</p></div>';
    $html .= '<div style="margin-bottom:14px;"><label style="font-weight:bold;display:block;margin-bottom:4px;">スクリプト自動更新間隔</label>';
    $html .= '<select name="spc_settings[ga4_update_interval]">';
    foreach ($ga4_interval_labels as $val => $lbl) {
        $sel = ($ga4_interval === $val) ? ' selected' : '';
        $html .= '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($lbl) . '</option>';
    }
    $html .= '</select><p class="description">Googleはスクリプトを更新することがあります。定期的に再取得して最新の状態を維持します。</p></div>';
    $html .= '<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:12px;">';
    $html .= '<div style="font-size:.85em;color:#555;margin-bottom:8px;">📄 ローカルスクリプト：';
    if ($ga4_file_exists) {
        $html .= '<span style="color:#1d7a1d;font-weight:bold;">✅ 保存済み</span>';
        if ($ga4_last_updated) $html .= '　最終更新：<strong>' . esc_html($ga4_last_updated) . '</strong>';
    } else {
        $html .= '<span style="color:#d63638;font-weight:bold;">⚠️ 未取得（設定を保存すると自動取得します）</span>';
    }
    $html .= '</div>';
    if ($ga4_enabled && !empty($ga4_id)) {
        $ga4_refresh_url = esc_url(wp_nonce_url(admin_url('admin-post.php?action=spc_ga4_refresh'), 'spc_ga4_refresh'));
        $html .= '<a href="' . $ga4_refresh_url . '" class="button button-secondary" onclick="return confirm(\'GA4スクリプトを今すぐ取得・更新しますか？\');">🔄 今すぐ更新</a>';
    }
    $html .= '</div></div></td></tr>';

    // yakuhan CSSローカル化
    $yakuhan_jp       = !empty($s['yakuhan_jp_enabled']);
    $yakuhan_mp       = !empty($s['yakuhan_mp_enabled']);
    $yakuhan_interval = $s['yakuhan_update_interval'] ?? 'spc_24hours';
    $yakuhan_last     = get_option('spc_yakuhan_last_updated', null);
    $yakuhan_files    = spc_yakuhan_files();
    $yakuhan_jp_exists = file_exists($yakuhan_files['jp']['path']);
    $yakuhan_mp_exists = file_exists($yakuhan_files['mp']['path']);
    $yakuhan_any_on   = $yakuhan_jp || $yakuhan_mp;
    $html .= '<tr><th scope="row">yakuhan CSSローカル化</th><td>';
    $html .= '<p class="description" style="margin-bottom:12px;">JSDelivr CDNから配信されるyakuhan CSSを自サーバーにホストします。外部接続が不要になりレンダリングブロックが解消されます。<br>使用しているものだけをオンにしてください。</p>';
    $yj_chk = $yakuhan_jp ? ' checked' : '';
    $yj_status = $yakuhan_jp_exists ? '<span style="color:#1d7a1d;font-size:.8em;"> ✅ ローカル保存済み</span>' : '<span style="color:#d63638;font-size:.8em;"> ⚠️ 未取得</span>';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;">';
    $html .= '<input type="checkbox" name="spc_settings[yakuhan_jp_enabled]" value="1"' . $yj_chk . ' style="margin-top:2px;">';
    $html .= '<span><strong>yakuhanjp（ゴシック体ベース）</strong><br><span style="font-size:.85em;color:#666;">日本語テキストの約物（「」、。など）を半角化するCSS。ゴシック体ベース。</span>' . $yj_status . '</span></label>';
    $ym_chk = $yakuhan_mp ? ' checked' : '';
    $ym_status = $yakuhan_mp_exists ? '<span style="color:#1d7a1d;font-size:.8em;"> ✅ ローカル保存済み</span>' : '<span style="color:#d63638;font-size:.8em;"> ⚠️ 未取得</span>';
    $html .= '<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:14px;">';
    $html .= '<input type="checkbox" name="spc_settings[yakuhan_mp_enabled]" value="1"' . $ym_chk . ' style="margin-top:2px;">';
    $html .= '<span><strong>yakuhanmp（明朝体ベース）</strong><br><span style="font-size:.85em;color:#666;">日本語テキストの約物（「」、。など）を半角化するCSS。明朝体ベース。</span>' . $ym_status . '</span></label>';
    $html .= '<div style="margin-bottom:12px;"><label style="font-weight:bold;display:block;margin-bottom:4px;">CSS自動更新間隔</label>';
    $html .= '<select name="spc_settings[yakuhan_update_interval]">';
    foreach ($ga4_interval_labels as $val => $lbl) {
        $sel = ($yakuhan_interval === $val) ? ' selected' : '';
        $html .= '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($lbl) . '</option>';
    }
    $html .= '</select><p class="description">yakuhanのバージョンアップ時に自動で最新版を取得します。</p></div>';
    $html .= '<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:12px 16px;">';
    $html .= '<div style="font-size:.85em;color:#555;margin-bottom:8px;">🕐 最終更新：<strong>' . ($yakuhan_last ? esc_html($yakuhan_last) : '未実行') . '</strong></div>';
    if ($yakuhan_any_on) {
        $yk_url = esc_url(wp_nonce_url(admin_url('admin-post.php?action=spc_yakuhan_refresh'), 'spc_yakuhan_refresh'));
        $html .= '<a href="' . $yk_url . '" class="button button-secondary" onclick="return confirm(\'yakuhan CSSを今すぐ取得・更新しますか？\');">🔄 今すぐ更新</a>';
    }
    $html .= '</div></td></tr>';

    $html .= '</table>';
    $html .= get_submit_button('設定を保存');
    $html .= '</form></div>';

    // JS
    $html .= '<script>';
    $html .= 'function spcToggleAll(c){var l=document.getElementById("spc_type_list");if(c.checked){l.style.opacity=".4";l.style.pointerEvents="none";l.querySelectorAll("input[type=checkbox]").forEach(function(cb){cb.checked=false});}else{l.style.opacity="1";l.style.pointerEvents="";}}';
    $html .= 'document.querySelectorAll("#spc_type_list input[type=checkbox]").forEach(function(cb){cb.addEventListener("change",function(){if(this.checked){document.getElementById("spc_check_all").checked=false;document.getElementById("spc_type_list").style.opacity="1";document.getElementById("spc_type_list").style.pointerEvents="";}});});';
    $html .= 'function spcTogglePrefetch(c){var o=document.getElementById("spc_prefetch_options");o.style.opacity=c.checked?"1":".4";o.style.pointerEvents=c.checked?"":"none";}';
    $html .= 'function spcToggleGa4(c){var o=document.getElementById("spc_ga4_options");o.style.opacity=c.checked?"1":".4";o.style.pointerEvents=c.checked?"":"none";}';
    $html .= 'function spcToggleLcpUrl(c){var w=document.getElementById("spc_lcp_url_wrap");w.style.opacity=c.checked?"1":".4";w.style.pointerEvents=c.checked?"":"none";}';
    $html .= '</script>';

    echo $html;
}

// ============================================================
// Cloudflare API連携 設定ページ
// ============================================================

// 設定保存ハンドラ
add_action('admin_post_spc_cf_save', 'spc_handle_cf_save');
function spc_handle_cf_save() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_cf_save');

    $cf = [
        'enabled'   => !empty($_POST['spc_cf_enabled']) ? 1 : 0,
        'api_token' => sanitize_text_field($_POST['spc_cf_api_token'] ?? ''),
        'zone_id'   => sanitize_text_field($_POST['spc_cf_zone_id']   ?? ''),
    ];
    update_option('spc_cf_settings', $cf);

    wp_redirect(add_query_arg(['page' => 'spc-cloudflare', 'cf_saved' => '1'], admin_url('admin.php')));
    exit;
}

// 手動全パージハンドラ
add_action('admin_post_spc_cf_purge_all', 'spc_handle_cf_purge_all');
function spc_handle_cf_purge_all() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_cf_purge_all');
    spc_cf_purge_all();
    wp_redirect(add_query_arg(['page' => 'spc-cloudflare', 'cf_purged' => '1'], admin_url('admin.php')));
    exit;
}

// Cloudflare設定ページ描画
function spc_render_cf_page() {
    if (!current_user_can('manage_options')) return;
    ob_start();
    $cf      = spc_cf_get_settings();
    $enabled = !empty($cf['enabled']);
    $token   = $cf['api_token'] ?? '';
    $zone    = $cf['zone_id']   ?? '';

    $nonce_field   = wp_nonce_field('spc_cf_save', '_wpnonce', true, false);
    $submit_btn    = get_submit_button('設定を保存');
    $checked       = $enabled ? ' checked' : '';
    $token_val     = esc_attr($token);
    $zone_val      = esc_attr($zone);
    $ajax_url      = esc_url(admin_url('admin-ajax.php'));
    $cf_test_nonce = wp_create_nonce('spc_cf_test');
    $action_url    = esc_url(admin_url('admin-post.php'));

    $notice = '';
    if (isset($_GET['cf_saved']))  $notice = '<div class="notice notice-success is-dismissible"><p>✅ Cloudflare設定を保存しました。</p></div>';
    if (isset($_GET['cf_purged'])) $notice = '<div class="notice notice-success is-dismissible"><p>✅ Cloudflareのキャッシュを全パージしました。</p></div>';

    $purge_btn = '';
    if ($enabled && !empty($token) && !empty($zone)) {
        $purge_url = esc_url(wp_nonce_url(admin_url('admin-post.php?action=spc_cf_purge_all'), 'spc_cf_purge_all'));
        $purge_btn = '<hr style="margin:24px 0;">'
            . '<h2 style="border-left:4px solid #2271b1;padding-left:10px;">手動パージ</h2>'
            . '<p>今すぐCloudflareの全キャッシュをパージします。</p>'
            . '<a href="' . $purge_url . '" class="button button-secondary"'
            . ' onclick="return confirm(\'Cloudflareの全キャッシュをパージしますか？\');">🗑 今すぐ全キャッシュパージ</a>';
    }

    $js  = '<script>' . "\n";
    $js .= 'document.getElementById(\'spc_cf_test_btn\').addEventListener(\'click\', function() {' . "\n";
    $js .= '    const btn    = this;' . "\n";
    $js .= '    const result = document.getElementById(\'spc_cf_test_result\');' . "\n";
    $js .= '    const token  = document.querySelector(\'[name="spc_cf_api_token"]\').value;' . "\n";
    $js .= '    const zone   = document.querySelector(\'[name="spc_cf_zone_id"]\').value;' . "\n";
    $js .= '    if (!token || !zone) {' . "\n";
    $js .= '        result.textContent = \'⚠️ APIトークンとゾーンIDを入力してください\';' . "\n";
    $js .= '        result.style.color = \'#d63638\';' . "\n";
    $js .= '        return;' . "\n";
    $js .= '    }' . "\n";
    $js .= '    btn.disabled = true;' . "\n";
    $js .= '    result.textContent = \'接続中...\';' . "\n";
    $js .= '    result.style.color = \'#555\';' . "\n";
    $js .= '    const data = new FormData();' . "\n";
    $js .= '    data.append(\'action\',    \'spc_cf_test\');' . "\n";
    $js .= '    data.append(\'nonce\',     \'' . $cf_test_nonce . '\');' . "\n";
    $js .= '    data.append(\'api_token\', token);' . "\n";
    $js .= '    data.append(\'zone_id\',   zone);' . "\n";
    $js .= '    fetch(\'' . $ajax_url . '\', { method: \'POST\', body: data })' . "\n";
    $js .= '        .then(r => r.json())' . "\n";
    $js .= '        .then(res => {' . "\n";
    $js .= '            result.textContent = res.data.message;' . "\n";
    $js .= '            result.style.color = res.success ? \'#1d7a1d\' : \'#d63638\';' . "\n";
    $js .= '        })' . "\n";
    $js .= '        .catch(() => {' . "\n";
    $js .= '            result.textContent = \'⚠️ 通信エラーが発生しました\';' . "\n";
    $js .= '            result.style.color = \'#d63638\';' . "\n";
    $js .= '        })' . "\n";
    $js .= '        .finally(() => { btn.disabled = false; });' . "\n";
    $js .= '});' . "\n";
    $js .= '</script>' . "\n";

    echo '<div class="wrap">';
    echo '<h1>☁ Cloudflare API連携</h1>';
    echo $notice;
    echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:14px 18px;margin:16px 0;border-radius:0 4px 4px 0;max-width:760px;">';
    echo '<strong>Cloudflare API連携とは</strong><br>';
    echo '<p style="margin:.6em 0 0;">投稿・ページの保存時にWordPressサーバーからCloudflareのAPIを呼び出し、エッジキャッシュを自動パージする機能です。</p>';
    echo '<p style="margin:.4em 0 0;">Cloudflareのプロキシ（オレンジ雲）を使用している場合、コンテンツ更新がエッジに即時反映されます。<br>プロキシなし（グレー雲・DNSのみ）の場合はCloudflareのキャッシュは存在しないため、この設定は不要です。</p>';
    echo '<p style="margin:.4em 0 0;">APIトークンはCloudflareダッシュボード →「マイプロフィール」→「APIトークン」→「トークンを作成」から取得できます。<br>必要な権限は <strong>Zone / Cache Purge（編集）</strong> のみです。無料プランでも利用可能です。</p>';
    echo '</div>';
    echo '<form method="post" action="' . $action_url . '">';
    echo $nonce_field;
    echo '<input type="hidden" name="action" value="spc_cf_save">';
    echo '<table class="form-table" role="presentation" style="max-width:760px;">';
    echo '<tr><th scope="row">Cloudflare連携を有効にする</th><td>';
    echo '<label style="display:flex;align-items:center;gap:8px;">';
    echo '<input type="checkbox" name="spc_cf_enabled" value="1"' . $checked . '>';
    echo '<span>有効にする</span></label>';
    echo '<p class="description">オンにすると投稿・ページ保存時にCloudflareのキャッシュを自動パージします。</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">APIトークン</th><td>';
    echo '<input type="password" name="spc_cf_api_token" value="' . $token_val . '" style="width:100%;max-width:460px;font-family:monospace;" placeholder="Cloudflare APIトークンを入力">';
    echo '<p class="description">Zone / Cache Purge（編集）権限のみで動作します。他の権限は不要です。</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">ゾーンID</th><td>';
    echo '<input type="text" name="spc_cf_zone_id" value="' . $zone_val . '" style="width:100%;max-width:360px;font-family:monospace;" placeholder="Cloudflareゾーン概要ページのゾーンID">';
    echo '<p class="description">CloudflareダッシュボードでドメインのゾーンIDを確認してください（概要ページ右下に表示されます）。</p>';
    echo '</td></tr>';
    echo '</table>';
    echo '<div style="margin:16px 0 8px;display:flex;align-items:center;gap:12px;">';
    echo '<button type="button" id="spc_cf_test_btn" class="button button-secondary">🔌 接続テスト</button>';
    echo '<span id="spc_cf_test_result" style="font-size:.9em;"></span>';
    echo '</div>';
    echo $submit_btn;
    echo '</form>';
    echo $purge_btn;
    echo $js;
    echo '</div>';
}
// ============================================================
// PageSpeed分析 AJAXハンドラ
// ============================================================
add_action('wp_ajax_spc_pagespeed_analyze', 'spc_handle_pagespeed_analyze');
function spc_handle_pagespeed_analyze() {
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    check_ajax_referer('spc_pagespeed_nonce', 'nonce');

    $url      = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
    $strategy = in_array($_POST['strategy'] ?? 'desktop', ['desktop', 'mobile'], true) ? $_POST['strategy'] : 'desktop';
    $api_key  = get_option('spc_pagespeed_api_key', '');

    if (empty($url)) wp_send_json_error('URLを入力してください');
    if (empty($api_key)) wp_send_json_error('PageSpeed APIキーが設定されていません');

    $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
        . '?url=' . rawurlencode($url)
        . '&strategy=' . $strategy
        . '&category=performance&category=accessibility&category=best-practices&category=seo'
        . '&locale=ja'
        . '&key=' . $api_key;

    $response = wp_remote_get($api_url, ['timeout' => 60, 'sslverify' => true]);

    if (is_wp_error($response)) {
        wp_send_json_error('APIリクエストエラー: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!empty($data['error'])) {
        wp_send_json_error('APIエラー: ' . ($data['error']['message'] ?? '不明なエラー'));
    }

    wp_send_json_success($data);
}

// APIキー保存ハンドラ
add_action('admin_post_spc_pagespeed_save_key', 'spc_handle_pagespeed_save_key');
function spc_handle_pagespeed_save_key() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_pagespeed_save_key');
    $key = sanitize_text_field(wp_unslash($_POST['spc_pagespeed_api_key'] ?? ''));
    update_option('spc_pagespeed_api_key', $key);
    wp_redirect(add_query_arg(['page' => 'spc-pagespeed', 'key_saved' => '1'], admin_url('admin.php')));
    exit;
}

// ============================================================
// PageSpeed分析 管理画面
// ============================================================
function spc_render_pagespeed_page() {
    if (!current_user_can('manage_options')) return;

    $api_key     = get_option('spc_pagespeed_api_key', '');
    $has_key     = !empty($api_key);
    $masked_key  = $has_key ? substr($api_key, 0, 8) . '••••••••••••••••••••' : '';
    $nonce       = wp_create_nonce('spc_pagespeed_nonce');
    $save_nonce  = wp_nonce_field('spc_pagespeed_save_key', '_wpnonce', true, false);

    // キャッシュモード取得（プロンプト前提条件用）
    $spc_settings  = get_option('spc_settings', array());
    $spc_cache_mode = $spc_settings['cache_mode'] ?? 'standalone';
    $spc_is_litespeed = ($spc_cache_mode === 'litespeed');

    // noindex設定を検出
    $spc_noindex = (bool) get_option('blog_public') === false;

    $html = '<div class="wrap">';
    $html .= '<h1>📊 PageSpeed分析</h1>';

    // noindex警告
    if ($spc_noindex) {
        $html .= '<p style="color:#d63638;font-size:13px;margin:-8px 0 12px;">⚠️ 現在noindexが有効になっています（検索エンジンにインデックスされない設定です）。</p>';
    }

    // PSI直接リンク説明文
    $spc_site_url = home_url('/');
    $spc_psi_direct_url = 'https://pagespeed.web.dev/analysis?url=' . urlencode($spc_site_url);
    $html .= '<div style="font-size:13px;color:#555;margin-bottom:16px;line-height:1.8;">';
    $html .= '一部情報は取得できない場合があり、API使用のためリアルタイムのスコアを取得できない場合があります。<br>';
    $html .= 'すぐに計測したい場合は直接以下URLよりPageSpeed Insightsで確認して下さい。<br>';
    $html .= '<a href="' . esc_url($spc_psi_direct_url) . '" target="_blank" rel="noopener noreferrer" style="color:#0073aa;word-break:break-all;">' . esc_html($spc_psi_direct_url) . '</a>';
    $html .= '</div>';

    if (isset($_GET['key_saved'])) {
        $html .= '<div class="notice notice-success is-dismissible"><p>✅ APIキーを保存しました。</p></div>';
    }

    // APIキー設定
    $html .= '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin:16px 0 24px;">';
    $html .= '<h2 style="margin:0 0 12px;font-size:1em;color:#555;">🔑 PageSpeed Insights APIキー設定</h2>';
    if ($has_key) {
        // 保存済みの場合はマスク表示＋変更フォーム
        $html .= '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">';
        $html .= '<span style="font-family:monospace;background:#f8f8f8;border:1px solid #ddd;border-radius:3px;padding:6px 12px;color:#555;letter-spacing:2px;">••••••••••••••••••••••••••••••••</span>';
        $html .= '<span style="font-size:12px;color:#1d7a1d;">✅ 設定済み</span>';
        $html .= '<button type="button" class="button button-secondary" onclick="document.getElementById(\'spc_ps_key_form\').style.display=\'block\';this.style.display=\'none\';">変更する</button>';
        $html .= '</div>';
        $html .= '<div id="spc_ps_key_form" style="display:none;">';
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">';
        $html .= $save_nonce;
        $html .= '<input type="hidden" name="action" value="spc_pagespeed_save_key">';
        $html .= '<input type="password" name="spc_pagespeed_api_key" placeholder="新しいAPIキーを入力" style="width:360px;font-family:monospace;" autocomplete="off">';
        $html .= '<button type="submit" class="button button-primary">保存</button>';
        $html .= '</form>';
        $html .= '</div>';
    } else {
        // 未設定の場合は入力フォームを表示
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">';
        $html .= $save_nonce;
        $html .= '<input type="hidden" name="action" value="spc_pagespeed_save_key">';
        $html .= '<input type="password" name="spc_pagespeed_api_key" placeholder="Google PageSpeed Insights APIキー" style="width:360px;font-family:monospace;" autocomplete="off">';
        $html .= '<button type="submit" class="button button-primary">保存</button>';
        $html .= '</form>';
    }
    $html .= '<p class="description" style="margin-top:8px;">Google Cloud Console → PageSpeed Insights API を有効化してAPIキーを取得してください。</p>';
    $html .= '</div>';

    if (!$has_key) {
        $html .= '<div style="background:#fff8e1;border:1px solid #f0c040;border-radius:4px;padding:12px 16px;color:#7a5c00;">⚠️ APIキーが設定されていません。上のフォームからAPIキーを入力して保存してください。</div>';
        $html .= '</div>';
        echo $html;
        return;
    }

    // 分析フォーム
    $html .= '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:20px;">';
    $html .= '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    $html .= '<input type="url" id="spc_ps_url" value="' . esc_attr(home_url('/')) . '" placeholder="https://example.com" style="flex:1;min-width:260px;">';
    $html .= '<select id="spc_ps_strategy" style="width:130px;">';
    $html .= '<option value="desktop">デスクトップ</option>';
    $html .= '<option value="mobile">モバイル</option>';
    $html .= '</select>';
    $html .= '<button type="button" id="spc_ps_btn" class="button button-primary" onclick="spcPsAnalyze()">分析開始</button>';
    $html .= '</div>';
    $html .= '<p class="description" style="margin-top:8px;">分析には10〜30秒かかります。</p>';
    $html .= '</div>';

    // 結果エリア
    $html .= '<div id="spc_ps_result"></div>';

    // JavaScript
    $html .= '<script>';
    $html .= 'var spc_ps_nonce = "' . esc_js($nonce) . '";';
    $html .= 'var spc_ps_ajax = "' . esc_js(admin_url('admin-ajax.php')) . '";';

    $html .= 'function spcPsAnalyze() {';
    $html .= '  var url = document.getElementById("spc_ps_url").value.trim();';
    $html .= '  var strategy = document.getElementById("spc_ps_strategy").value;';
    $html .= '  var result = document.getElementById("spc_ps_result");';
    $html .= '  if (!url) { alert("URLを入力してください"); return; }';
    $html .= '  document.getElementById("spc_ps_btn").disabled = true;';
    $html .= '  result.innerHTML = \'<div style="padding:2rem;text-align:center;color:#666;">⏳ 分析中... しばらくお待ちください</div>\';';
    $html .= '  var fd = new FormData();';
    $html .= '  fd.append("action","spc_pagespeed_analyze");';
    $html .= '  fd.append("nonce", spc_ps_nonce);';
    $html .= '  fd.append("url", url);';
    $html .= '  fd.append("strategy", strategy);';
    $html .= '  fetch(spc_ps_ajax, {method:"POST",body:fd})';
    $html .= '  .then(r=>r.json()).then(res=>{';
    $html .= '    document.getElementById("spc_ps_btn").disabled = false;';
    $html .= '    if (!res.success) { result.innerHTML=\'<div style="color:#d63638;padding:1rem;">エラー: \'+res.data+\'</div>\'; return; }';
    $html .= '    spcPsRender(res.data, url, strategy);';
    $html .= '  }).catch(e=>{';
    $html .= '    document.getElementById("spc_ps_btn").disabled = false;';
    $html .= '    result.innerHTML=\'<div style="color:#d63638;padding:1rem;">通信エラー: \'+e.message+\'</div>\';';
    $html .= '  });';
    $html .= '}';

    $html .= 'function spcScoreColor(s) {';
    $html .= '  if (s>=90) return "#1d7a1d";';
    $html .= '  if (s>=50) return "#ba7517";';
    $html .= '  return "#d63638";';
    $html .= '}';

    $html .= 'function spcMetricColor(score) {';
    $html .= '  if (score>=0.9) return "#1d7a1d";';
    $html .= '  if (score>=0.5) return "#ba7517";';
    $html .= '  return "#d63638";';
    $html .= '}';

    $html .= 'function spcFmt(val, unit) {';
    $html .= '  if (val===undefined||val===null) return "-";';
    $html .= '  if (unit==="millisecond") return (val/1000).toFixed(1)+" 秒";';
    $html .= '  if (unit==="unitless") return val.toFixed(3);';
    $html .= '  return val.toFixed(2);';
    $html .= '}';

    $html .= 'function spcPsRender(data, url, strategy) {';
    $html .= '  var cats = data.lighthouseResult.categories;';
    $html .= '  var audits = data.lighthouseResult.audits;';
    $html .= '  var strategyLabel = strategy==="desktop" ? "デスクトップ" : "モバイル";';
    $html .= '  var now = new Date().toLocaleString("ja-JP");';

    // スコアカード
    $html .= '  var html = \'<h2 style="border-left:4px solid #2271b1;padding-left:10px;margin:0 0 16px;">\'+url+\' [\'+strategyLabel+\']\</h2>\';';
    $html .= '  var catList=[{k:"performance",l:"パフォーマンス"},{k:"accessibility",l:"ユーザー補助"},{k:"best-practices",l:"おすすめの方法"},{k:"seo",l:"SEO"}];';
    $html .= '  html+=\'<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">\';';
    $html .= '  catList.forEach(function(c){';
    $html .= '    var cat=cats[c.k]; var score=cat?Math.round(cat.score*100):"-";';
    $html .= '    var color=typeof score==="number"?spcScoreColor(score):"#666";';
    $html .= '    html+=\'<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:16px;text-align:center;">\';';
    $html .= '    html+=\'<div style="font-size:.8em;color:#666;margin-bottom:8px;">\'+c.l+\'</div>\';';
    $html .= '    html+=\'<div style="font-size:2em;font-weight:bold;color:\'+color+\'">\'+score+\'</div></div>\';';
    $html .= '  });';
    $html .= '  html+=\'</div>\';';

    // 主要指標
    $html .= '  var metrics=[{id:"first-contentful-paint",l:"FCP"},{id:"largest-contentful-paint",l:"LCP"},{id:"total-blocking-time",l:"TBT"},{id:"cumulative-layout-shift",l:"CLS"},{id:"speed-index",l:"Speed Index"},{id:"interactive",l:"TTI"}];';
    $html .= '  html+=\'<h3 style="margin:0 0 10px;font-size:1em;color:#555;">主要指標</h3>\';';
    $html .= '  html+=\'<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;">\';';
    $html .= '  metrics.forEach(function(m){';
    $html .= '    var a=audits[m.id]; if(!a) return;';
    $html .= '    var color=spcMetricColor(a.score||0);';
    $html .= '    html+=\'<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px;">\';';
    $html .= '    html+=\'<div style="font-size:.8em;color:#666;margin-bottom:4px;">\'+m.l+\'</div>\';';
    $html .= '    html+=\'<div style="font-size:1.4em;font-weight:bold;color:\'+color+\'">\'+spcFmt(a.numericValue,a.numericUnit)+\'</div></div>\';';
    $html .= '  });';
    $html .= '  html+=\'</div>\';';

    // 改善が必要な項目
    $html .= '  var skipIds=["screenshot-thumbnails","final-screenshot","full-page-screenshot","network-requests","main-thread-tasks","metrics","resource-summary","third-party-summary","uses-long-cache-ttl","user-timings","largest-contentful-paint-element","lcp-lazy-loaded","critical-request-chains"];';
    $html .= '  var failed=Object.values(audits).filter(function(a){';
    $html .= '    return a.score!==null&&a.score!==undefined&&a.score<0.9&&a.title&&skipIds.indexOf(a.id)===-1;';
    $html .= '  }).sort(function(a,b){return (a.score||0)-(b.score||0);}).slice(0,15);';
    $html .= '  if(failed.length>0){';
    $html .= '    html+=\'<h3 style="margin:0 0 10px;font-size:1em;color:#555;">改善が必要な項目</h3>\';';
    $html .= '    failed.forEach(function(a,idx){';
    $html .= '      var color=a.score>=0.5?"#ba7517":"#d63638";';
    $html .= '      var icon=a.score>=0.5?"△":"▲";';
    $html .= '      var saving=a.details&&a.details.overallSavingsMs?" — 推定削減: "+Math.round(a.details.overallSavingsMs)+"ms":"";';
    $html .= '      var desc=(a.description||"").replace(/\[([^\]]+)\]\([^)]+\)/g,"$1");';
    $html .= '      var scoreDisp=a.score!==null?Math.round(a.score*100)+"点":"-";';
    $html .= '      var detailId="spc_audit_"+idx;';

    // 詳細コンテンツを生成する関数
    $html .= '      var detailHtml="";';

    // 説明文
    $html .= '      if(desc) detailHtml+=\'<div style="font-size:12px;color:#555;margin-bottom:10px;line-height:1.7;">\'+desc+\'</div>\';';

    // critical-request-chains：a.idで確実に判定（APIの仕様変更に対応）
    $html .= '      if(a.id==="critical-request-chains"){';
    $html .= '        var psUrlCrc="https://pagespeed.web.dev/analysis?url="+encodeURIComponent(document.getElementById("spc_ps_url").value);';
    $html .= '        detailHtml+=\'<div style="font-size:12px;color:#555;line-height:1.8;">本ツールではツリーを取得できないため、詳しくはPageSpeed Insightsの結果を確認して下さい。<br><a href="\'+psUrlCrc+\'" target="_blank" rel="noopener noreferrer" style="color:#0073aa;word-break:break-all;">\'+psUrlCrc+\'</a></div>\';';
    $html .= '      }';

    // table形式
    $html .= '      else if(a.details&&a.details.type==="table"&&a.details.items&&a.details.items.length>0){';
    $html .= '        var items=a.details.items.slice(0,8);';
    $html .= '        var headings=a.details.headings||[];';
    $html .= '        detailHtml+=\'<div style="overflow-x:auto;">\';';
    $html .= '        detailHtml+=\'<table style="width:100%;border-collapse:collapse;font-size:11px;">\';';
    $html .= '        if(headings.length>0){';
    $html .= '          detailHtml+=\'<thead><tr>\';';
    $html .= '          headings.forEach(function(h){ detailHtml+=\'<th style="text-align:left;padding:5px 8px;background:#f5f5f5;border:1px solid #e0e0e0;color:#555;font-weight:500;white-space:nowrap;">\'+((h.label||h.key)||"")+\'</th>\'; });';
    $html .= '          detailHtml+=\'</tr></thead>\';';
    $html .= '        }';
    $html .= '        detailHtml+=\'<tbody>\';';
    $html .= '        items.forEach(function(item){';
    $html .= '          detailHtml+=\'<tr>\';';
    $html .= '          if(headings.length>0){';
    $html .= '            headings.forEach(function(h){';
    $html .= '              var key=h.key; var val=item[key]; var disp="";';
    $html .= '              if(val===undefined||val===null) disp="-";';
    $html .= '              else if(typeof val==="object"&&val.type==="url") disp=\'<span style="word-break:break-all;">\'+((val.url||"").substring(0,80))+(val.url&&val.url.length>80?"...":"")+\'</span>\';';
    $html .= '              else if(typeof val==="object"&&val.type==="bytes") disp=Math.round((val.value||0)/1024)+" KiB";';
    $html .= '              else if(typeof val==="object"&&(val.type==="timespanMs"||val.type==="ms")) disp=Math.round(val.value||0)+" ms";';
    $html .= '              else if(typeof val==="object"&&val.type==="thumbnail") disp="[画像]";';
    $html .= '              else if(typeof val==="object"&&val.type==="node"&&val.nodeLabel) disp=\'<code style="font-size:10px;word-break:break-all;">\'+String(val.nodeLabel).substring(0,80)+\'</code>\';';
    $html .= '              else if(typeof val==="object"&&val.type==="node"&&val.snippet) disp=\'<code style="font-size:10px;word-break:break-all;">\'+String(val.snippet).substring(0,80)+\'</code>\';';
    $html .= '              else if(typeof val==="object"&&val.type==="node") disp="[要素]";';
    $html .= '              else if(typeof val==="object"&&val.type==="code"&&val.value) disp=\'<code style="font-size:10px;word-break:break-all;">\'+String(val.value).substring(0,80)+\'</code>\';';
    $html .= '              else if(typeof val==="object"&&val.value!==undefined) disp=String(val.value).substring(0,80);';
    $html .= '              else if(typeof val==="object") disp="-";';
    $html .= '              else disp=String(val).substring(0,100);';
    $html .= '              detailHtml+=\'<td style="padding:5px 8px;border:1px solid #e0e0e0;color:#333;vertical-align:top;">\'+disp+\'</td>\';';
    $html .= '            });';
    $html .= '          }';
    $html .= '          detailHtml+=\'</tr>\';';
    $html .= '        });';
    $html .= '        detailHtml+=\'</tbody></table>\';';
    $html .= '        if(a.details.items.length>8) detailHtml+=\'<div style="font-size:11px;color:#888;margin-top:4px;">他 \'+(a.details.items.length-8)+\'件</div>\';';
    $html .= '        detailHtml+=\'</div>\';';
    $html .= '      }';

    // opportunity形式（推定削減量あり）
    $html .= '      else if(a.details&&a.details.type==="opportunity"&&a.details.items&&a.details.items.length>0){';
    $html .= '        var items=a.details.items.slice(0,8);';
    $html .= '        var headings=a.details.headings||[];';
    $html .= '        detailHtml+=\'<div style="overflow-x:auto;">\';';
    $html .= '        detailHtml+=\'<table style="width:100%;border-collapse:collapse;font-size:11px;">\';';
    $html .= '        if(headings.length>0){';
    $html .= '          detailHtml+=\'<thead><tr>\';';
    $html .= '          headings.forEach(function(h){ detailHtml+=\'<th style="text-align:left;padding:5px 8px;background:#f5f5f5;border:1px solid #e0e0e0;color:#555;font-weight:500;">\'+((h.label||h.key)||"")+\'</th>\'; });';
    $html .= '          detailHtml+=\'</tr></thead>\';';
    $html .= '        }';
    $html .= '        detailHtml+=\'<tbody>\';';
    $html .= '        items.forEach(function(item){';
    $html .= '          detailHtml+=\'<tr>\';';
    $html .= '          if(headings.length>0){';
    $html .= '            headings.forEach(function(h){';
    $html .= '              var key=h.key; var val=item[key]; var disp="";';
    $html .= '              if(val===undefined||val===null) disp="-";';
    $html .= '              else if(typeof val==="object"&&val.type==="url") disp=\'<span style="word-break:break-all;">\'+((val.url||"").substring(0,80))+(val.url&&val.url.length>80?"...":"")+\'</span>\';';
    $html .= '              else if(typeof val==="object"&&val.type==="bytes") disp=Math.round((val.value||0)/1024)+" KiB";';
    $html .= '              else if(typeof val==="object"&&(val.type==="timespanMs"||val.type==="ms")) disp=Math.round(val.value||0)+" ms";';
    $html .= '              else if(typeof val==="object"&&val.value!==undefined) disp=String(val.value).substring(0,80);';
    $html .= '              else if(typeof val==="object") disp="-";';
    $html .= '              else disp=String(val).substring(0,100);';
    $html .= '              detailHtml+=\'<td style="padding:5px 8px;border:1px solid #e0e0e0;color:#333;vertical-align:top;">\'+disp+\'</td>\';';
    $html .= '            });';
    $html .= '          }';
    $html .= '          detailHtml+=\'</tr>\';';
    $html .= '        });';
    $html .= '        detailHtml+=\'</tbody></table>\';';
    $html .= '        if(a.details.items.length>8) detailHtml+=\'<div style="font-size:11px;color:#888;margin-top:4px;">他 \'+(a.details.items.length-8)+\'件</div>\';';
    $html .= '        detailHtml+=\'</div>\';';
    $html .= '      }';

    // list形式（強制リフローなど）
    $html .= '      else if(a.details&&a.details.type==="list"&&a.details.items&&a.details.items.length>0){';
    $html .= '        detailHtml+=\'<div style="font-size:12px;">\';';
    $html .= '        a.details.items.slice(0,8).forEach(function(item){';
    $html .= '          if(item.type==="table"&&item.items){';
    $html .= '            var subHeadings=item.headings||[];';
    $html .= '            detailHtml+=\'<table style="width:100%;border-collapse:collapse;font-size:11px;margin-bottom:8px;">\';';
    $html .= '            if(subHeadings.length>0){';
    $html .= '              detailHtml+=\'<thead><tr>\';';
    $html .= '              subHeadings.forEach(function(h){ detailHtml+=\'<th style="text-align:left;padding:4px 8px;background:#f5f5f5;border:1px solid #e0e0e0;color:#555;font-weight:500;">\'+((h.label||h.key)||"")+\'</th>\'; });';
    $html .= '              detailHtml+=\'</tr></thead>\';';
    $html .= '            }';
    $html .= '            detailHtml+=\'<tbody>\';';
    $html .= '            item.items.slice(0,5).forEach(function(row){';
    $html .= '              detailHtml+=\'<tr>\';';
    $html .= '              subHeadings.forEach(function(h){';
    $html .= '                var val=row[h.key]; var disp="";';
    $html .= '                if(val===undefined||val===null) disp="-";';
    $html .= '                else if(typeof val==="object"&&val.type==="url") disp=\'<span style="word-break:break-all;">\'+((val.url||"").substring(0,80))+\'</span>\';';
    $html .= '                else if(typeof val==="object"&&(val.type==="timespanMs"||val.type==="ms")) disp=Math.round(val.value||0)+" ms";';
    // sourceキー対応（強制リフロー）
    $html .= '                else if(h.key==="source"&&typeof val==="object"){';
    $html .= '                  if(val.url&&val.line) disp=\'<span style="word-break:break-all;font-size:11px;">\'+val.url.split("/").pop()+":"+val.line+(val.column?":"+val.column:"")+\'</span>\';';
    $html .= '                  else if(val.url) disp=\'<span style="word-break:break-all;">\'+val.url.split("/").pop()+\'</span>\';';
    $html .= '                  else disp="[ソース不明]";';
    $html .= '                }';
    $html .= '                else if(typeof val==="object"&&val.value!==undefined) disp=String(val.value).substring(0,80);';
    $html .= '                else if(typeof val==="object") disp="-";';
    $html .= '                else if(typeof val==="number") disp=Math.round(val*100)/100+" ms";';
    $html .= '                else disp=String(val).substring(0,100);';
    $html .= '                detailHtml+=\'<td style="padding:4px 8px;border:1px solid #e0e0e0;color:#333;vertical-align:top;">\'+disp+\'</td>\';';
    $html .= '              });';
    $html .= '              detailHtml+=\'</tr>\';';
    $html .= '            });';
    $html .= '            detailHtml+=\'</tbody></table>\';';
    $html .= '          } else if(typeof item==="string"){';
    $html .= '            detailHtml+=\'<div style="padding:3px 0;color:#555;">\'+item+\'</div>\';';
    $html .= '          } else if(item&&typeof item==="object"){';
    $html .= '            var itemStr="";';
    $html .= '            if(item.url) itemStr+=item.url;';
    $html .= '            else if(item.value!==undefined) itemStr+=String(item.value);';
    $html .= '            else if(item.text) itemStr+=item.text;';
    $html .= '            else if(item.label) itemStr+=item.label;';
    $html .= '            else { try{ itemStr=JSON.stringify(item).substring(0,200); }catch(e){ itemStr=""; } }';
    $html .= '            if(itemStr) detailHtml+=\'<div style="padding:3px 0;color:#555;">\'+itemStr+\'</div>\';';
    $html .= '          }';
    $html .= '        });';
    $html .= '        detailHtml+=\'</div>\';';
    $html .= '      }';



    // 折りたたみUI
    $html .= '      html+=\'<div style="background:#fff;border:1px solid #ddd;border-radius:4px;margin-bottom:6px;">\';';
    $html .= '      html+=\'<div onclick="spcToggleAudit(\\\'\'+detailId+\'\\\')" style="display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer;user-select:none;">\';';
    $html .= '      html+=\'<span style="color:\'+color+\';flex-shrink:0;font-size:13px;">\'+icon+\'</span>\';';
    $html .= '      html+=\'<span style="flex:1;font-size:13px;font-weight:500;">\'+a.title+\'</span>\';';
    $html .= '      html+=\'<span style="font-size:11px;color:#888;margin-right:4px;">スコア: \'+scoreDisp+\'</span>\';';
    $html .= '      if(saving) html+=\'<span style="font-size:11px;color:#ba7517;margin-right:4px;">\'+saving+\'</span>\';';
    $html .= '      html+=\'<span id="\'+detailId+\'_arrow" style="font-size:12px;color:#999;transition:transform .2s;">▶</span>\';';
    $html .= '      html+=\'</div>\';';
    $html .= '      html+=\'<div id="\'+detailId+\'" style="display:none;padding:0 14px 12px 14px;border-top:1px solid #f0f0f0;">\'+detailHtml+\'</div>\';';
    $html .= '      html+=\'</div>\';';
    $html .= '    });';
    $html .= '  }';

    // AIプロンプト生成エリア
    $html .= '  var textLines=[];';
    $html .= '  textLines.push("# PageSpeed Insights 分析結果");';
    $html .= '  textLines.push("URL: "+url);';
    $html .= '  textLines.push("デバイス: "+strategyLabel);';
    $html .= '  textLines.push("取得日時: "+now);';
    $html .= '  textLines.push("");';
    $html .= '  textLines.push("## スコア");';
    $html .= '  catList.forEach(function(c){';
    $html .= '    var cat=cats[c.k]; var score=cat?Math.round(cat.score*100):"-";';
    $html .= '    textLines.push(c.l+": "+score);';
    $html .= '  });';
    $html .= '  textLines.push("");';
    $html .= '  textLines.push("## 主要指標");';
    $html .= '  metrics.forEach(function(m){';
    $html .= '    var a=audits[m.id]; if(!a) return;';
    $html .= '    textLines.push(m.l+": "+spcFmt(a.numericValue,a.numericUnit));';
    $html .= '  });';
    $html .= '  textLines.push("");';
    $html .= '  textLines.push("## 改善が必要な項目");';
    $html .= '  failed.forEach(function(a){';
    $html .= '    var saving=a.details&&a.details.overallSavingsMs?" (推定削減: "+Math.round(a.details.overallSavingsMs)+"ms)":"";';
    $html .= '    var scoreDisp=a.score!==null?Math.round(a.score*100)+"点":"-";';
    $html .= '    textLines.push("### "+a.title+" [スコア: "+scoreDisp+"]"+saving);';
    $html .= '    var desc=(a.description||"").replace(/\[([^\]]+)\]\([^)]+\)/g,"$1").substring(0,200);';
    $html .= '    if(desc) textLines.push("説明: "+desc);';
    $html .= '    if(a.details&&a.details.type==="table"&&a.details.items&&a.details.items.length>0){';
    $html .= '      a.details.items.slice(0,3).forEach(function(item){';
    $html .= '        var headings=a.details.headings||[];';
    $html .= '        var parts=[];';
    $html .= '        headings.forEach(function(h){';
    $html .= '          var val=item[h.key]; if(val===undefined||val===null) return;';
    $html .= '          var disp="";';
    $html .= '          if(typeof val==="object"&&val.type==="url") disp=(val.url||"").substring(0,80);';
    $html .= '          else if(typeof val==="object"&&val.type==="bytes") disp=Math.round((val.value||0)/1024)+"KiB";';
    $html .= '          else if(typeof val==="object"&&val.type==="timespanMs") disp=Math.round(val.value||0)+"ms";';
    $html .= '          else if(typeof val==="object"&&val.value!==undefined) disp=String(val.value);';
    $html .= '          else disp=String(val).substring(0,80);';
    $html .= '          if(disp) parts.push((h.label||h.key)+": "+disp);';
    $html .= '        });';
    $html .= '        if(parts.length>0) textLines.push("  - "+parts.join(" | "));';
    $html .= '      });';
    $html .= '      if(a.details.items.length>3) textLines.push("  ... 他"+(a.details.items.length-3)+"件");';
    $html .= '    }';
    $html .= '    if(a.id==="critical-request-chains"){';
    $html .= '      var psUrl="https://pagespeed.web.dev/analysis?url="+encodeURIComponent(url);';
    $html .= '      textLines.push("  詳細はPageSpeed Insightsで確認: "+psUrl);';
    $html .= '    }';
    $html .= '    textLines.push("");';
    $html .= '  });';

    // プロンプト前提条件（PHPで動的生成）
    // PageSpeedスコアに影響する有効化済み設定を収集
    $spc_active_features = [];
    if ($spc_is_litespeed)                                   $spc_active_features[] = 'LiteSpeed Cache併用モード（サーバーレベルのページキャッシュ・配信最適化）';
    if (!empty($spc_settings['prefetch_enabled']))           $spc_active_features[] = 'リンクプリフェッチ（ホバー前のページ先読み）';
    if (!empty($spc_settings['tuning_lcp_preload']) && !empty($spc_settings['tuning_lcp_preload_url']))
                                                             $spc_active_features[] = 'LCPヒーロー画像preload・fetchpriority=high・loading=eager';
    if (!empty($spc_settings['tuning_js_defer']))            $spc_active_features[] = 'scroll-timeline.js defer（レンダリングブロック解消）';
    if (!empty($spc_settings['tuning_iframe_lazy']))         $spc_active_features[] = 'iFrame遅延読み込み';
    if (!empty($spc_settings['tuning_image_blur_lazy']))     $spc_active_features[] = '画像ブラーフェードイン（遅延読み込み）';
    if (!empty($spc_settings['tuning_image_lazy_enhance']))  $spc_active_features[] = '画像loading=lazy強化';
    if (!empty($spc_settings['tuning_font_preload']))        $spc_active_features[] = 'フォントpreload';
    if (!empty($spc_settings['tuning_dns_prefetch_fonts']))  $spc_active_features[] = 'Google Fonts DNS prefetch';
    if (!empty($spc_settings['tuning_dns_prefetch']) && empty($spc_settings['ga4_local_enabled']))
                                                             $spc_active_features[] = 'GTM/GA DNS preconnect';
    if (!empty($spc_settings['ga4_local_enabled']))          $spc_active_features[] = 'GA4スクリプトローカル配信';
    if (!empty($spc_settings['yakuhan_jp_enabled']) || !empty($spc_settings['yakuhan_mp_enabled']))
                                                             $spc_active_features[] = 'Yakuhan CSSローカル配信';
    if (!empty($spc_settings['tuning_query_strings']))       $spc_active_features[] = 'クエリストリング除去（キャッシュ効率化）';
    if (!empty($spc_settings['tuning_browser_cache']) && !$spc_is_litespeed)
                                                             $spc_active_features[] = 'ブラウザキャッシュ設定';
    if (!empty($spc_settings['tuning_video_lazy']))          $spc_active_features[] = '動画遅延読み込み';
    if (get_option('spc_webp_enable', 'yes') === 'yes')      $spc_active_features[] = 'WebP自動変換';
    if (!empty($spc_settings['tuning_css_minify']) && !$spc_is_litespeed)
                                                             $spc_active_features[] = 'CSS圧縮（コメント・空白除去）';
    if (!empty($spc_settings['tuning_gzip']))                $spc_active_features[] = 'テキスト圧縮（Gzip/Brotliフォールバック）';

    $prompt_features = '';
    if (!empty($spc_active_features)) {
        $prompt_features  = ' +"\\n【Smile Performance 有効化済み設定】\\n"';
        foreach ($spc_active_features as $feat) {
            $prompt_features .= ' +"- ' . $feat . '\\n"';
        }
    }

    // CSS圧縮状態によってプロンプト文言を変更
    $css_minify_note = !empty($spc_settings['tuning_css_minify']) && !$spc_is_litespeed
        ? '- CSS圧縮（コメント・空白除去）はSmile Performanceで対応済み。\\n'
        : '- CSS圧縮・HTML圧縮はBricks BuilderもしくはLiteSpeed Cacheと干渉するため非対応。\\n';

    // noindex状態のプロンプト文言
    $noindex_note = $spc_noindex
        ? ' +"- 制作中サイトのためnoindexを有効化。\\n"'
        : '';

    // BricksのCSS読み込み方法を取得
    $bricks_settings   = get_option('bricks_settings', array());
    $bricks_css_loading = $bricks_settings['cssLoading'] ?? 'inline';
    $bricks_css_label   = match($bricks_css_loading) {
        'file'        => '外部ファイル',
        'inline'      => 'インラインスタイル（デフォルト）',
        default       => $bricks_css_loading,
    };
    $bricks_css_note = '- BricksのCSS読み込み方法は「' . $bricks_css_label . '」に設定。\\n';

    $html .= '  var prompt = "【前提条件】\\n"';
    $html .= '    +"このサイトはSmile Performanceプラグインを使用しています。\\n"';
    $html .= '    +"' . $css_minify_note . '"';
    $html .= '    +"' . $bricks_css_note . '"';
    $html .= '    +"- CSS非同期読み込み・クリティカルCSS生成はBricksに影響が出る可能性があるため実施しない。\\n"';
    $html .= '    +"- CSSファイルのPreloadはPSIスコアが低下することが確認されているため実施しない。\\n"';
    $html .= '    +"- 画像ブラーフェードインはオンにしてもスコアに影響が無いことが確認済み。\\n"';
    $html .= '    +"- JS圧縮はBricksに影響を及ぼす可能性があるため実施せず。\\n"';
    $html .= $noindex_note;
    $html .= $prompt_features;
    $html .= '    +"\\n以下はPageSpeed Insightsの計測結果です。\\n"';
    $html .= '    +"WordPressサイト（Bricks Builder使用）のパフォーマンス改善に特化した観点で、\\n"';
    $html .= '    +"以下の点を踏まえて分析・提案してください。\\n\\n"';
    $html .= '    +"1. スコアと主要指標の現状評価（良い点・問題点）\\n"';
    $html .= '    +"2. 改善が必要な項目の優先順位と具体的な対処方法\\n"';
    $html .= '    +"3. プラグインや設定で対応できる項目とBricks Builder側で対応すべき項目の分類\\n"';
    $html .= '    +"4. 対応難易度別（簡単・中程度・難しい）の改善ロードマップ\\n\\n"';
    $html .= '    +textLines.join("\\n");';

    $html .= '  html+=\'<div style="margin-top:24px;background:#f0f6fc;border:1px solid #72aee6;border-radius:4px;padding:16px 20px;">\';';
    $html .= '  html+=\'<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">\';';
    $html .= '  html+=\'<h3 style="margin:0;font-size:1em;color:#0073aa;">🤖 AI分析プロンプト（クリップボードにコピーしてAIに貼り付け）</h3>\';';
    $html .= '  html+=\'<button type="button" onclick="spcCopyPrompt()" class="button button-secondary" style="flex-shrink:0;">📋 コピー</button>\';';
    $html .= '  html+=\'</div>\';';
    $html .= '  html+=\'<textarea id="spc_ps_prompt" rows="12" style="width:100%;font-family:monospace;font-size:12px;background:#fff;border:1px solid #aed6f1;border-radius:3px;padding:10px;box-sizing:border-box;" readonly>\'+prompt+\'</textarea>\';';
    $html .= '  html+=\'</div>\';';

    $html .= '  document.getElementById("spc_ps_result").innerHTML=html;';
    $html .= '  document.getElementById("spc_ps_prompt").value=prompt;';
    $html .= '}';

    $html .= 'function spcCopyPrompt() {';
    $html .= '  var ta = document.getElementById("spc_ps_prompt");';
    $html .= '  ta.select();';
    $html .= '  document.execCommand("copy");';
    $html .= '  var btn = event.target;';
    $html .= '  btn.textContent="✅ コピーしました";';
    $html .= '  setTimeout(function(){ btn.textContent="📋 コピー"; }, 2000);';
    $html .= '}';

    $html .= 'function spcToggleAudit(id) {';
    $html .= '  var el = document.getElementById(id);';
    $html .= '  var arrow = document.getElementById(id+"_arrow");';
    $html .= '  if (!el) return;';
    $html .= '  var isOpen = el.style.display !== "none";';
    $html .= '  el.style.display = isOpen ? "none" : "block";';
    $html .= '  if (arrow) arrow.style.transform = isOpen ? "" : "rotate(90deg)";';
    $html .= '}';

    $html .= 'document.addEventListener("keydown", function(e){';
    $html .= '  if (e.key==="Enter" && document.activeElement.id==="spc_ps_url") spcPsAnalyze();';
    $html .= '});';
    $html .= '</script>';

    // 説明文
    $html .= '<div style="background:#f0f6fc;border:1px solid #72aee6;border-radius:4px;padding:10px 16px;margin-top:16px;font-size:12px;color:#0073aa;line-height:1.7;">';
    $html .= '⚠️ <strong>計測結果について：</strong>PageSpeed Insightsの計測結果はリクエストのたびに数値が変動する場合があります。改善対応後は最低5分以上待ってからキャッシュをクリアした上で計測してください。同じ条件で複数回計測し、平均値で判断することをお勧めします。1〜2点程度の差は誤差の範囲です。';
    $html .= '</div>';

    $html .= '</div>';
    echo $html;
}

// ============================================================
// プラグイン一覧にアップデート確認リンクを追加
// ============================================================
add_filter('plugin_action_links_smile-performance/smile-performance.php', 'spc_add_plugin_action_links');
function spc_add_plugin_action_links($links) {
    $check_url = wp_nonce_url(
        add_query_arg(
            ['action' => 'spc_force_update_check'],
            admin_url('admin-post.php')
        ),
        'spc_force_update_check'
    );
    $check_link = '<a href="' . esc_url($check_url) . '">アップデートを確認</a>';
    array_push($links, $check_link);
    return $links;
}

add_action('admin_post_spc_force_update_check', 'spc_handle_force_update_check');
function spc_handle_force_update_check() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('spc_force_update_check');

    // WordPressの更新キャッシュを削除して強制チェック
    delete_site_transient('update_plugins');
    wp_update_plugins();

    wp_redirect(add_query_arg(
        ['update_check' => '1'],
        admin_url('plugins.php')
    ));
    exit;
}

// アップデート確認後の通知
add_action('admin_notices', 'spc_update_check_notice');
function spc_update_check_notice() {
    if (!isset($_GET['update_check']) || !current_user_can('manage_options')) return;
    echo '<div class="notice notice-success is-dismissible"><p>✅ Smile Performance のアップデート確認が完了しました。</p></div>';
}

// ============================================================
// WebP自動変換・リサイズ機能
// ============================================================

// psi-fit-pcサイズをWordPressに登録
add_action('after_setup_theme', 'spc_register_psi_image_size');
function spc_register_psi_image_size() {
    add_image_size('psi-fit-pc', 1080, 9999, false);
}

// WebP設定の登録
add_action('admin_init', 'spc_webp_settings_init');
function spc_webp_settings_init() {
    register_setting('spc_webp_settings_group', 'spc_webp_enable');
    register_setting('spc_webp_settings_group', 'spc_webp_quality', 'intval');
    register_setting('spc_webp_settings_group', 'spc_webp_max_size', 'intval');
    register_setting('spc_webp_settings_group', 'spc_webp_is_configured');
    register_setting('spc_webp_settings_group', 'spc_webp_active_sizes', array('sanitize_callback' => 'spc_webp_sanitize_sizes'));
}

function spc_webp_sanitize_sizes($input) {
    if (!is_array($input)) return array();
    return array_filter($input, function($val) {
        return $val !== 'dummy';
    });
}

// 画像アップロード時の変換&リサイズ処理
add_filter('wp_handle_upload', 'spc_webp_convert_on_upload', 10, 2);
function spc_webp_convert_on_upload($upload, $context) {
    if (get_option('spc_webp_enable', 'yes') !== 'yes' || isset($upload['error'])) {
        return $upload;
    }
    $file_path = $upload['file'];
    $type      = $upload['type'];
    if (!in_array($type, array('image/jpeg', 'image/png'))) {
        return $upload;
    }
    $editor = wp_get_image_editor($file_path);
    if (is_wp_error($editor)) {
        return $upload;
    }
    $max_size = (int) get_option('spc_webp_max_size', 2560);
    $quality  = (int) get_option('spc_webp_quality', 75);
    if ($max_size > 0) {
        $size = $editor->get_size();
        if (!is_wp_error($size)) {
            if ($size['width'] > $max_size || $size['height'] > $max_size) {
                $editor->resize($max_size, $max_size, false);
            }
        }
    }
    $editor->set_quality($quality);
    $path_info     = pathinfo($file_path);
    $dir           = $path_info['dirname'];
    $filename      = $path_info['filename'];
    $new_file_name = wp_unique_filename($dir, $filename . '.webp');
    $new_file_path = $dir . '/' . $new_file_name;
    $saved = $editor->save($new_file_path, 'image/webp');
    if (!is_wp_error($saved)) {
        @unlink($file_path);
        $upload['file'] = $new_file_path;
        $upload['url']  = str_replace(basename($upload['url']), $new_file_name, $upload['url']);
        $upload['type'] = 'image/webp';
    }
    return $upload;
}

// サムネイル生成サイズの制御
add_filter('intermediate_image_sizes_advanced', 'spc_webp_filter_image_sizes', 10, 3);
function spc_webp_filter_image_sizes($new_sizes, $image_meta, $attachment_id) {
    if (get_option('spc_webp_enable', 'yes') !== 'yes') {
        return $new_sizes;
    }
    $is_configured = get_option('spc_webp_is_configured', 'no');
    if ($is_configured === 'no') {
        $active_sizes = array('thumbnail', 'medium_large', 'large', 'psi-fit-pc');
    } else {
        $active_sizes = get_option('spc_webp_active_sizes', array());
        if (!is_array($active_sizes)) {
            $active_sizes = array();
        }
    }
    foreach (array_keys($new_sizes) as $size_name) {
        if (!in_array($size_name, $active_sizes)) {
            unset($new_sizes[$size_name]);
        }
    }
    return $new_sizes;
}

// AJAX: 既存WebP画像への一括PSI-fit-pc生成
add_action('wp_ajax_spc_generate_psi_bulk', 'spc_generate_psi_bulk');
function spc_generate_psi_bulk() {
    check_ajax_referer('spc_psi_bulk_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $offset = (int)($_POST['offset'] ?? 0);
    $batch  = 5;

    $query = new WP_Query(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image/webp',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch,
        'offset'         => $offset,
        'fields'         => 'ids',
    ));

    $total = (new WP_Query(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image/webp',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    )))->found_posts;

    $generated = 0;
    $skipped   = 0;
    $errors    = 0;

    foreach ($query->posts as $id) {
        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) { $errors++; continue; }

        // 元画像が1366px未満はスキップ
        $meta = wp_get_attachment_metadata($id);
        $orig_w = $meta['width']  ?? 0;
        $orig_h = $meta['height'] ?? 0;
        $orig_long = max($orig_w, $orig_h);
        if ($orig_long < 1080) { $skipped++; continue; }

        // psi-fit-pcが既に存在する場合は古いファイルを削除して再生成
        if (!empty($meta['sizes']['psi-fit-pc'])) {
            $old_file = path_join(dirname($file), $meta['sizes']['psi-fit-pc']['file']);
            if (file_exists($old_file)) @unlink($old_file);
            unset($meta['sizes']['psi-fit-pc']);
        }

        // サムネイル生成
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) { $errors++; continue; }

        $editor->resize(1080, 9999, false);
        $editor->set_quality((int)get_option('spc_webp_quality', 75));

        $path_info = pathinfo($file);
        $new_name  = $path_info['filename'] . '-1366x' . $editor->get_size()['height'] . '.webp';
        $new_path  = $path_info['dirname'] . '/' . $new_name;
        $saved     = $editor->save($new_path, 'image/webp');

        if (is_wp_error($saved)) { $errors++; continue; }

        // メタデータに追記
        $meta['sizes']['psi-fit-pc'] = array(
            'file'      => basename($saved['path']),
            'width'     => $saved['width'],
            'height'    => $saved['height'],
            'mime-type' => 'image/webp',
        );
        wp_update_attachment_metadata($id, $meta);
        $generated++;
    }

    $done = ($offset + $batch) >= $total;

    wp_send_json_success(array(
        'generated' => $generated,
        'skipped'   => $skipped,
        'errors'    => $errors,
        'offset'    => $offset + $batch,
        'total'     => $total,
        'done'      => $done,
    ));
}

// WebP設定ページ レンダー
function spc_render_webp_page() {
    if (!current_user_can('manage_options')) return;

    $enable        = get_option('spc_webp_enable', 'yes');
    $quality       = get_option('spc_webp_quality', 75);
    $max_size      = get_option('spc_webp_max_size', 2560);
    $is_configured = get_option('spc_webp_is_configured', 'no');
    $all_sizes     = get_intermediate_image_sizes();
    global $_wp_additional_image_sizes;

    if ($is_configured === 'no') {
        $active_sizes = array('thumbnail', 'medium_large', 'large', 'psi-fit-pc');
    } else {
        $active_sizes = get_option('spc_webp_active_sizes', array());
        if (!is_array($active_sizes)) $active_sizes = array();
    }

    if (isset($_GET['settings-updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h1>&#x1F5BC; WebP自動変換・リサイズ設定</h1>';
    echo '<p>画像アップロード時にJPEG・PNGを自動でWebPに変換・リサイズします。元画像は削除されます。</p>';
    echo '<form action="options.php" method="post">';
    echo '<input type="hidden" name="option_page" value="spc_webp_settings_group">';
    echo '<input type="hidden" name="action" value="update">';
    echo wp_nonce_field('spc_webp_settings_group-options', '_wpnonce', true, false);
    echo '<input type="hidden" name="spc_webp_is_configured" value="yes">';
    echo '<input type="hidden" name="spc_webp_active_sizes[]" value="dummy">';

    echo '<table class="form-table">';

    echo '<tr>';
    echo '<th scope="row">自動変換機能</th>';
    echo '<td>';
    echo '<label>';
    echo '<input type="checkbox" name="spc_webp_enable" value="yes"' . checked($enable, 'yes', false) . '>';
    echo ' アップロード時に画像をWebPに自動変換する';
    echo '</label>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="spc_webp_quality">変換品質 (Quality)</label></th>';
    echo '<td>';
    echo '<input type="number" id="spc_webp_quality" name="spc_webp_quality" value="' . esc_attr($quality) . '" min="1" max="100">';
    echo '<p class="description">1〜100の範囲で指定。デフォルトは「75」です。</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="spc_webp_max_size">最大画像サイズ（長辺px）</label></th>';
    echo '<td>';
    echo '<input type="number" id="spc_webp_max_size" name="spc_webp_max_size" value="' . esc_attr($max_size) . '" min="0"> px';
    echo '<p class="description">このサイズを超える画像はアスペクト比を維持して縮小されます。（デフォルト: 2560）</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row">作成するサムネイル</th>';
    echo '<td>';
    echo '<p class="description">チェックを入れたサイズのサムネイルのみを生成します。<br>';
    echo '<span style="color:#d63638;">※注意：テーマやプラグインが必須とする画像サイズは外さないようにしてください。</span><br>';
    echo '<span style="color:#0073aa;">※FVに使用する画像は追加ソースよりカスタム（メディアクエリ）のブレークポイントを設定し、メディアクエリは(max-width: 1366px)、画像は該当画像と同じものにし、Psi Fit Pcのサイズもしくはそれ以下にするとPage Speed Insightsのスコアが改善する可能性があります。</span></p>';
    echo '<fieldset style="margin-top:10px;border:1px solid #ccd0d4;padding:15px;background:#fff;max-height:400px;overflow-y:auto;">';

    foreach ($all_sizes as $size) {
        $checked   = in_array($size, (array)$active_sizes) ? ' checked' : '';
        $width     = isset($_wp_additional_image_sizes[$size]['width'])  ? $_wp_additional_image_sizes[$size]['width']  : get_option("{$size}_size_w");
        $height    = isset($_wp_additional_image_sizes[$size]['height']) ? $_wp_additional_image_sizes[$size]['height'] : get_option("{$size}_size_h");
        $crop      = isset($_wp_additional_image_sizes[$size]['crop'])   ? $_wp_additional_image_sizes[$size]['crop']   : get_option("{$size}_crop");
        $crop_text = $crop ? '(切り抜きあり)' : '(比率維持)';
        $w_disp    = $width  ?: '自動';
        $h_disp    = $height ?: '自動';
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="checkbox" name="spc_webp_active_sizes[]" value="' . esc_attr($size) . '"' . $checked . '>';
        echo ' <strong>' . esc_html($size) . '</strong> [' . esc_html($w_disp) . ' &times; ' . esc_html($h_disp) . '] ' . esc_html($crop_text);
        echo '</label>';
    }

    echo '</fieldset>';
    echo '</td>';
    echo '</tr>';

    echo '</table>';
    submit_button('設定を保存');
    echo '</form>';

    // 一括PSI-fit-pc生成
    $psi_nonce = wp_create_nonce('spc_psi_bulk_nonce');
    echo '<hr style="margin:30px 0;">';
    echo '<h2>既存WebP画像へのPSI-fit-pc一括生成</h2>';
    echo '<p>メディアライブラリ内のWebP画像に対してpsi-fit-pc（1080px）サムネイルを一括生成します。<br>';
    echo '元画像が1080px未満のもの・すでに生成済みのものはスキップされます（再生成する場合は既存ファイルを削除してから実行してください）。</p>';
    echo '<button type="button" id="spc_psi_bulk_btn" class="button button-secondary">🔄 一括生成を開始</button>';
    echo '<div id="spc_psi_bulk_progress" style="margin-top:12px;display:none;">';
    echo '<div id="spc_psi_bulk_bar_wrap" style="background:#e0e0e0;border-radius:4px;height:18px;width:400px;max-width:100%;overflow:hidden;">';
    echo '<div id="spc_psi_bulk_bar" style="background:#0073aa;height:18px;width:0%;transition:width 0.3s;"></div>';
    echo '</div>';
    echo '<p id="spc_psi_bulk_status" style="margin-top:6px;font-size:13px;"></p>';
    echo '</div>';

    echo '<script>';
    echo 'document.getElementById("spc_psi_bulk_btn").addEventListener("click", function() {';
    echo '  var btn = this;';
    echo '  btn.disabled = true;';
    echo '  document.getElementById("spc_psi_bulk_progress").style.display = "";';
    echo '  var totalProcessed = 0, totalCount = 0, totalGen = 0, totalSkip = 0, totalErr = 0;';
    echo '  function runBatch(offset) {';
    echo '    var fd = new FormData();';
    echo '    fd.append("action", "spc_generate_psi_bulk");';
    echo '    fd.append("nonce", "' . $psi_nonce . '");';
    echo '    fd.append("offset", offset);';
    echo '    fetch(ajaxurl, {method:"POST", body:fd})';
    echo '      .then(function(r){ return r.json(); })';
    echo '      .then(function(res) {';
    echo '        if (!res.success) { document.getElementById("spc_psi_bulk_status").textContent = "エラーが発生しました。"; btn.disabled = false; return; }';
    echo '        var d = res.data;';
    echo '        totalCount = d.total;';
    echo '        totalGen  += d.generated;';
    echo '        totalSkip += d.skipped;';
    echo '        totalErr  += d.errors;';
    echo '        totalProcessed = Math.min(d.offset, totalCount);';
    echo '        var pct = totalCount > 0 ? Math.round(totalProcessed / totalCount * 100) : 100;';
    echo '        document.getElementById("spc_psi_bulk_bar").style.width = pct + "%";';
    echo '        document.getElementById("spc_psi_bulk_status").textContent = totalProcessed + " / " + totalCount + " 件処理中（生成: " + totalGen + " / スキップ: " + totalSkip + " / エラー: " + totalErr + "）";';
    echo '        if (!d.done) { runBatch(d.offset); } else {';
    echo '          document.getElementById("spc_psi_bulk_status").textContent = "完了！ 全" + totalCount + "件 ／ 生成: " + totalGen + " / スキップ: " + totalSkip + " / エラー: " + totalErr;';
    echo '          btn.disabled = false;';
    echo '        }';
    echo '      });';
    echo '  }';
    echo '  runBatch(0);';
    echo '});';
    echo '</script>';

    echo '</div>';
}

// ============================================================
// 更新履歴ページ
// ============================================================
function spc_render_changelog_page() {
    if (!current_user_can('manage_options')) return;

    $changelog = [
        [
            'version' => '1.29',
            'date'    => '2026-04-07',
            'changes' => [
                'AIプロンプト：各前提条件の文末表記を統一（ですます調を除去）',
                'AIプロンプト：CSS圧縮オフ時の文言にLiteSpeed Cacheを追加',
                'LCPヒーロー画像：ブレークポイント設定の備考欄を追加',
            ],
        ],
        [
            'version' => '1.28',
            'date'    => '2026-04-06',
            'changes' => [
                'CSS圧縮：インラインCSS圧縮を別チェックボックスに分離（デフォルトオフ）',
                'CSS圧縮：インラインCSS圧縮にBricksforge・NextBricks使用時のオフ推奨警告を追加',
            ],
        ],
        [
            'version' => '1.27',
            'date'    => '2026-04-06',
            'changes' => [
                'CSS圧縮：Bricksビルダー編集画面（?bricks=run）では無効化',
                'CSS圧縮：アイコンフォント関連CSS（FontAwesome・Ionicons・Themify等）を除外',
                'CSS圧縮：@font-faceを含むCSSはURLパス破損防止のためスキップ',
            ],
        ],
        [
            'version' => '1.26',
            'date'    => '2026-04-06',
            'changes' => [
                'ブラウザキャッシュ最適化：動画ファイル（mp4・webm・ogv・mov）のキャッシュ設定を追加',
            ],
        ],
        [
            'version' => '1.25',
            'date'    => '2026-04-06',
            'changes' => [
                'PageSpeed分析：AIプロンプトに「画像ブラーフェードインはオンにしてもスコアに影響が無いことが確認済み」を追加',
                'PageSpeed分析：ネットワークの依存関係ツリーをskipIdsに追加して[object Object]表示を完全解消',
                'PageSpeed分析：タイトル下にPSI直接リンクの説明文を追加',
            ],
        ],
        [
            'version' => '1.24',
            'date'    => '2026-04-06',
            'changes' => [
                'PageSpeed分析：AIプロンプトに「CSSファイルのPreloadはPSIスコアが低下することが確認されているため実施しない」を追加',
                'PageSpeed分析：ネットワークの依存関係ツリーのAIプロンプト出力を修正（PageSpeed InsightsのURLリンクを追加）',
            ],
        ],
        [
            'version' => '1.23',
            'date'    => '2026-04-05',
            'changes' => [
                'PageSpeed分析：AIプロンプトにBricksのCSS読み込み方法を動的追加',
                'PageSpeed分析：AIプロンプトに「CSSの非同期読み込み・クリティカルCSS生成はBricksに影響が出る可能性があるため実施しない」を追加',
            ],
        ],
        [
            'version' => '1.22',
            'date'    => '2026-04-05',
            'changes' => [
                'PageSpeed分析：ネットワークの依存関係ツリーの[object Object]表示を修正',
                'PageSpeed分析：noindex有効時にタイトル下に赤文字で警告を表示',
                'PageSpeed分析：AIプロンプトに「JS圧縮はBricksに影響を及ぼす可能性があるため実施せず」を追加',
                'PageSpeed分析：noindex有効時にAIプロンプトへ「制作中サイトのためnoindexを有効化」を動的追加',
            ],
        ],
        [
            'version' => '1.21',
            'date'    => '2026-04-05',
            'changes' => [
                'CSS圧縮：対象ファイルの上限サイズを5KB→デフォルト110KBに変更（設定画面で1〜500KBの範囲で変更可能）',
                'CSS圧縮：.min.cssファイルもコメント除去による軽量処理の対象に追加',
            ],
        ],
        [
            'version' => '1.20',
            'date'    => '2026-04-04',
            'changes' => [
                'テキスト圧縮（Gzip/Brotli）機能を追加',
                'サーバーが自動対応済みの場合は自動検出してスキップ',
                'Apache環境では.htaccessにmod_deflate/mod_brotli設定を自動追記',
                'プラグイン無効化時に.htaccess設定を自動削除',
            ],
        ],
        [
            'version' => '1.19',
            'date'    => '2026-04-04',
            'changes' => [
                'CSS圧縮機能を追加（単独モードのみ有効・LiteSpeedモード時は自動無効）',
                'CSS圧縮設定にLiteSpeed Cache側での対応方法の説明文を追加',
                'PageSpeed分析：CSS圧縮が有効な場合はAIプロンプトの前提条件を自動更新',
            ],
        ],
        [
            'version' => '1.18',
            'date'    => '2026-04-04',
            'changes' => [
                'WebP設定：PSI最適化自動差し替え機能を削除（Bricks側での設定を推奨）',
                'WebP設定：作成するサムネイルのデフォルト値をthumbnail・medium_large・large・psi-fit-pcに変更',
                'WebP設定：サムネイル一覧にFV画像のPSI最適化に関する説明文を追加',
                'WebP設定：psi-fit-pc一括生成ボタンを維持（Bricks用サムネイル生成に使用）',
            ],
        ],
        [
            'version' => '1.17',
            'date'    => '2026-04-04',
            'changes' => [
                'LCPヒーロー画像の説明文を更新（preload・fetchpriority・loading=eagerの付与を明記）',
                'WebP設定：PSI一括生成で縦長画像（高さ＞幅）の場合も長辺基準で1366px判定に変更',
            ],
        ],
        [
            'version' => '1.16',
            'date'    => '2026-04-03',
            'changes' => [
                'プラグインアイコンを追加',
                'WordPress 6.9.4との互換性確認（Tested up to: 6.9.4）',
                'サブメニュー順序変更：WebP設定をCloudflare連携の前に移動',
                'PageSpeed分析：ネットワークの依存関係ツリーをPageSpeed InsightsへのURLリンク表示に修正',
                'PageSpeed分析：AIプロンプトから「画像最適化は設定済み」の行を削除',
                'PageSpeed分析：AIプロンプトにSmile Performance有効化済み設定を動的に表示',
                'LCPヒーロー画像：指定URL画像のimgタグにfetchpriority="high"とloading="eager"を自動付与',
                'WebP設定：psi-fit-pc（1080px）サムネイルサイズを追加（PSI表示幅75vw対応）',
                'WebP設定：PSI最適化（PC）機能を追加 — 指定URL画像にmax-width: 1366pxでpsi-fit-pcをsrcset自動配信',
                'WebP設定：既存WebP画像へのpsi-fit-pc一括生成ボタンを追加',
            ],
        ],
        [
            'version' => '1.15',
            'date'    => '2026-04-02',
            'changes' => [
                'WebP自動変換・リサイズ機能を追加（サブメニュー「WebP設定」）',
                'PageSpeed分析：AIプロンプトにSmile Performance使用前提の説明を追加',
                'PageSpeed分析：ネットワークの依存関係ツリーをPageSpeed InsightsへのリンクURL付き表示に変更',
            ],
        ],
        [
            'version' => '1.14',
            'date'    => '2026-04-02',
            'changes' => [
                'PageSpeed分析：強制リフローのスクリプト名・行番号・時間を正しく表示するよう修正',
                'PageSpeed分析：ネットワークの依存関係ツリーを[object Object]からURL・時間・サイズ付きのツリー表示に改善',
            ],
        ],
        [
            'version' => '1.13',
            'date'    => '2026-04-02',
            'changes' => [
                'GitHub Plugin URIをルート直下の構造に合わせて修正（自動更新通知の改善）',
                'リポジトリをPublicに変更',
            ],
        ],
        [
            'version' => '1.12',
            'date'    => '2026-04-02',
            'changes' => [
                'PageSpeed分析：改善が必要な項目を折りたたみ式に変更（クリックで展開/折りたたみ）',
                'PageSpeed分析：強制リフロー・未使用JavaScript削減など詳細表示に対応（list・opportunity形式に対応）',
                'PageSpeed分析：計測結果の変動に関する説明文を追加',
                'LCPヒーロー画像preload：複数URL入力対応（1行に1URL、PC・モバイルで異なる画像に対応）',
                '管理メニューに「更新履歴」ページを追加',
            ],
        ],
        [
            'version' => '1.11',
            'date'    => '2026-04-01',
            'changes' => [
                'yakuhan CSSローカル化：フォントURLの相対パスを絶対URLに自動変換するよう修正（404エラー対応）',
                'PageSpeed分析：URLデフォルト値をインストール済みサイトのURLに設定',
                'PageSpeed分析：改善が必要な項目の詳細表示を改善（node・code・ms型に対応）',
                'PageSpeed分析：APIカテゴリ取得方法を修正（ユーザー補助・おすすめの方法・SEOが取得できない問題を解消）',
                'PageSpeed分析：APIキーを保存後マスク表示に変更（セキュリティ改善）',
                'プラグイン一覧に「アップデートを確認」リンクを追加',
                'GitHub Plugin URIをフォルダ構造に合わせて修正',
            ],
        ],
        [
            'version' => '1.1',
            'date'    => '2026-04-01',
            'changes' => [
                'PageSpeed分析機能を追加（管理画面サブメニュー「📊 PageSpeed分析」）',
                'PageSpeed Insights APIキー設定機能を追加',
                'AI分析プロンプト生成・クリップボードコピー機能を追加',
                'Cloudflare連携：プラグイン名・メニュー名を「Smile Cache」から「Smile Performance」に変更',
            ],
        ],
        [
            'version' => '1.0',
            'date'    => '2026-03-31',
            'changes' => [
                'プラグイン化（functions.phpからスタンドアロンプラグインに移行）',
                'GitHub連携・自動更新機能を追加（Git Updater対応）',
                '管理メニュー名を「Smile Cache」から「Smile Performance」に変更',
                'HTML圧縮機能を削除（LiteSpeedサーバーレベルの圧縮と重複するため）',
                'spc_apply_tuning()の閉じ括弧欠落による構文エラーを修正',
                'spc_get_db_stats()の全テーブルANALYZE実行によるタイムアウト問題を修正',
                'ヒアドキュメント（<<<JS）を文字列連結に変換（サーバー互換性向上）',
                '管理画面描画関数を完全にechoベースに統一（?>/?php混在を解消）',
            ],
        ],
        [
            'version' => '0.2',
            'date'    => '2026-03-30',
            'changes' => [
                'Cloudflare API連携機能を追加（APIトークン・ゾーンID設定、接続テスト、投稿保存時自動パージ）',
                'キャッシュ有効期限の設定UI化（管理画面から変更可能に）',
                'プリロードCron間隔の設定UI化',
                'プリフェッチ開始タイミング（遅延設定）を追加',
                'LiteSpeed Cache 併用モード対応',
            ],
        ],
        [
            'version' => '0.1',
            'date'    => '2026-03-30',
            'changes' => [
                '初回リリース',
                'PHPファイルキャッシュ機能（standaloneモード）',
                'Bricks最適化チューニング（DNSプリフェッチ・遅延読み込み・フォントpreload等）',
                'GA4ローカル化・yakuhan CSSローカル化',
                'フォームnonce自動リフレッシュ',
                'リンクプリフェッチJS',
                'データベース自動クリーン',
            ],
        ],
    ];

    echo '<div class="wrap">';
    echo '<h1>📋 更新履歴</h1>';

    foreach ($changelog as $release) {
        $ver   = esc_html($release['version']);
        $date  = esc_html($release['date']);
        $is_current = ($release['version'] === '1.29');

        echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:16px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">';
        echo '<span style="font-size:1.2em;font-weight:bold;">v' . $ver . '</span>';
        if ($is_current) {
            echo '<span style="background:#1d7a1d;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;">現在のバージョン</span>';
        }
        echo '<span style="font-size:.85em;color:#888;">' . $date . '</span>';
        echo '</div>';
        echo '<ul style="margin:0;padding-left:20px;">';
        foreach ($release['changes'] as $change) {
            echo '<li style="font-size:13px;color:#333;margin-bottom:4px;line-height:1.6;">' . esc_html($change) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '</div>';
}
