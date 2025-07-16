<?php
/**
 * Marche Management Plugin アンインストール処理
 *
 * @package MarcheManagement
 * @author GITAG
 * @version 1.0.0
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * プラグインアンインストール時の処理
 *
 * @return void
 */
function marcheManagementUninstall() {
    // プラグイン設定の削除
    delete_option('marche_management_version');
    delete_option('marche_management_settings');

    // キャッシュデータの削除
    delete_transient('marche_management_cache');
    delete_transient('marche_pricing_cache');
    delete_transient('marche_form_cache');

    // プラグイン関連のキャッシュを削除
    wp_cache_delete('marche_management_forms');
    wp_cache_delete('marche_management_areas');
    wp_cache_delete('marche_management_rentals');

    // WordPressキャッシュのクリア
    wp_cache_flush();
}

// アンインストール処理の実行
marcheManagementUninstall();
