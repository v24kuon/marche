<?php
/**
 * フォーム管理画面
 *
 * @package MarcheManagement
 * @author AI Assistant
 * @version 1.0.0
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * フォーム管理クラス
 *
 * @class MarcheFormManagement
 * @description フォーム管理画面の処理を行うクラス
 */
class MarcheFormManagement {

    /**
     * フォーム管理画面の表示
     *
     * @return void
     */
    public static function displayPage() {
        // POSTデータの処理
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handlePostRequest();
        }

        // GETパラメータの処理
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>フォーム管理</h1>';

        switch ($action) {
            case 'add':
                self::displayAddForm();
                break;
            case 'edit':
                self::displayEditForm($formId);
                break;
            case 'delete':
                self::deleteForm($formId);
                self::displayFormList();
                break;
            default:
                self::displayFormList();
                break;
        }

        echo '</div>';
    }

    /**
     * POSTリクエストの処理
     *
     * @return void
     */
    private static function handlePostRequest() {
        // データベーステーブル作成処理
        if (isset($_POST['create_tables']) && wp_verify_nonce($_POST['marche_debug_nonce'], 'marche_debug_action')) {
            $plugin = MarcheManagementPlugin::getInstance();
            $reflection = new ReflectionClass($plugin);
            $method = $reflection->getMethod('createDatabaseTables');
            $method->setAccessible(true);
            $method->invoke($plugin);

            echo '<div class="notice notice-success"><p>データベーステーブルを再作成しました。ページをリロードしてください。</p></div>';
            return;
        }

        // nonce確認
        if (!isset($_POST['marche_form_nonce']) || !wp_verify_nonce($_POST['marche_form_nonce'], 'marche_form_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        switch ($action) {
            case 'add_form':
                self::addForm();
                break;
            case 'edit_form':
                self::editForm();
                break;
        }
    }

    /**
     * フォーム一覧の表示
     *
     * @return void
     */
    private static function displayFormList() {
        global $wpdb;

        // Contact Form 7のフォーム一覧を取得
        $cf7Forms = get_posts(array(
            'post_type' => 'wpcf7_contact_form',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));

        // 登録済みフォーム一覧を取得
        $tableName = $wpdb->prefix . 'marche_forms';
        $marcheFormIds = $wpdb->get_col("SELECT form_id FROM {$tableName}");

        echo '<div class="marche-form-list">';
        echo '<h2>登録済みフォーム</h2>';

        // Contact Form 7コード表示（登録済みフォームがある場合）
        if (!empty($marcheFormIds)) {
            self::displayContactFormCode();
        }

        if (!empty($marcheFormIds)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>フォームID</th>';
            echo '<th>フォーム名</th>';
            echo '<th>フォームタイプ</th>';
            echo '<th>支払い方法</th>';
            echo '<th>作成日</th>';
            echo '<th>操作</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            $registeredForms = $wpdb->get_results("SELECT * FROM {$tableName} ORDER BY created_at DESC");

            foreach ($registeredForms as $form) {
                $cf7Form = get_post($form->form_id);
                $formTitle = $cf7Form ? $cf7Form->post_title : '削除されたフォーム';
                $formType = isset($form->form_type) ? $form->form_type : 'マルシェ';

                echo '<tr>';
                echo '<td>' . esc_html($form->form_id) . '</td>';
                echo '<td>' . esc_html($formTitle) . '</td>';
                echo '<td>' . esc_html($formType) . '</td>';
                echo '<td>' . esc_html(self::getPaymentMethodLabel($form->payment_method)) . '</td>';
                echo '<td>' . esc_html(date_i18n('Y年n月j日', strtotime($form->created_at))) . '</td>';
                echo '<td>';
                echo '<a href="?page=marche-form-management&action=edit&form_id=' . $form->form_id . '" class="button">編集</a> ';
                echo '<a href="?page=marche-form-management&action=delete&form_id=' . $form->form_id . '" class="button button-secondary" onclick="return confirm(\'このフォームを削除しますか？\')">削除</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<p>登録されているフォームはありません。</p>';
        }

        echo '</div>';

        // 新規追加可能なフォーム
        echo '<div class="marche-available-forms">';
        echo '<h2>新規追加可能なフォーム</h2>';

        $availableForms = array();
        foreach ($cf7Forms as $cf7Form) {
            if (!in_array($cf7Form->ID, $marcheFormIds)) {
                $availableForms[] = $cf7Form;
            }
        }

        if (!empty($availableForms)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>フォームID</th>';
            echo '<th>フォーム名</th>';
            echo '<th>作成日</th>';
            echo '<th>操作</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($availableForms as $form) {
                echo '<tr>';
                echo '<td>' . esc_html($form->ID) . '</td>';
                echo '<td>' . esc_html($form->post_title) . '</td>';
                echo '<td>' . esc_html(date_i18n('Y年n月j日', strtotime($form->post_date))) . '</td>';
                echo '<td>';
                echo '<a href="?page=marche-form-management&action=add&form_id=' . $form->ID . '" class="button button-primary">追加</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<p>追加可能なContact Form 7フォームはありません。</p>';
        }

        echo '</div>';

        // データベース状態確認の表示
        self::displayDatabaseStatus();
    }

    /**
     * フォーム追加画面の表示
     *
     * @return void
     */
    private static function displayAddForm() {
        $formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

        if (!$formId) {
            echo '<div class="notice notice-error"><p>フォームIDが指定されていません。</p></div>';
            echo '<a href="?page=marche-form-management" class="button">戻る</a>';
            return;
        }

        $cf7Form = get_post($formId);
        if (!$cf7Form) {
            echo '<div class="notice notice-error"><p>指定されたフォームが見つかりません。</p></div>';
            echo '<a href="?page=marche-form-management" class="button">戻る</a>';
            return;
        }

        echo '<h2>フォーム追加</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field('marche_form_action', 'marche_form_nonce');
        echo '<input type="hidden" name="action" value="add_form">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">フォームID</th>';
        echo '<td>' . esc_html($formId) . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">フォーム名</th>';
        echo '<td><input type="text" name="form_name" value="' . esc_attr($cf7Form->post_title) . '" class="regular-text" required></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">フォームタイプ</th>';
        echo '<td>';
        echo '<select name="form_type" required>';
        echo '<option value="マルシェ">マルシェ</option>';
        echo '<option value="ステージ">ステージ</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">支払い方法</th>';
        echo '<td>';
        echo '<select name="payment_method" required>';
        echo '<option value="both">クレジットカード・銀行振込み両方</option>';
        echo '<option value="credit_card">クレジットカードのみ</option>';
        echo '<option value="bank_transfer">銀行振込みのみ</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="追加">';
        echo ' <a href="?page=marche-form-management" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * フォーム編集画面の表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayEditForm($formId) {
        global $wpdb;

        if (!$formId) {
            echo '<div class="notice notice-error"><p>フォームIDが指定されていません。</p></div>';
            echo '<a href="?page=marche-form-management" class="button">戻る</a>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_forms';
        $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tableName} WHERE form_id = %d", $formId));

        if (!$form) {
            echo '<div class="notice notice-error"><p>指定されたフォームが見つかりません。</p></div>';
            echo '<a href="?page=marche-form-management" class="button">戻る</a>';
            return;
        }

        echo '<h2>フォーム編集</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field('marche_form_action', 'marche_form_nonce');
        echo '<input type="hidden" name="action" value="edit_form">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">フォームID</th>';
        echo '<td>' . esc_html($formId) . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">フォーム名</th>';
        echo '<td><input type="text" name="form_name" value="' . esc_attr($form->form_name) . '" class="regular-text" required></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">フォームタイプ</th>';
        echo '<td>';
        echo '<select name="form_type" required>';
        $currentFormType = isset($form->form_type) ? $form->form_type : 'マルシェ';
        echo '<option value="マルシェ"' . selected($currentFormType, 'マルシェ', false) . '>マルシェ</option>';
        echo '<option value="ステージ"' . selected($currentFormType, 'ステージ', false) . '>ステージ</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">支払い方法</th>';
        echo '<td>';
        echo '<select name="payment_method" required>';
        echo '<option value="both"' . selected($form->payment_method, 'both', false) . '>クレジットカード・銀行振込み両方</option>';
        echo '<option value="credit_card"' . selected($form->payment_method, 'credit_card', false) . '>クレジットカードのみ</option>';
        echo '<option value="bank_transfer"' . selected($form->payment_method, 'bank_transfer', false) . '>銀行振込みのみ</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="更新">';
        echo ' <a href="?page=marche-form-management" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * フォームの追加処理
     *
     * @return void
     */
    private static function addForm() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $formName = sanitize_text_field($_POST['form_name']);
        $formType = sanitize_text_field($_POST['form_type']);
        $paymentMethod = sanitize_text_field($_POST['payment_method']);

        // バリデーション
        if (!$formId || !$formName || !$formType || !$paymentMethod) {
            echo '<div class="notice notice-error"><p>必須項目が入力されていません。</p></div>';
            return;
        }

        // フォームタイプのバリデーション
        if (!in_array($formType, array('マルシェ', 'ステージ'))) {
            echo '<div class="notice notice-error"><p>無効なフォームタイプが指定されました。</p></div>';
            return;
        }

        // 重複チェック
        $tableName = $wpdb->prefix . 'marche_forms';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d", $formId));

        if ($exists) {
            echo '<div class="notice notice-error"><p>このフォームは既に登録されています。</p></div>';
            return;
        }

        // データベースに挿入
        $result = $wpdb->insert(
            $tableName,
            array(
                'form_id' => $formId,
                'form_name' => $formName,
                'form_type' => $formType,
                'payment_method' => $paymentMethod
            ),
            array('%d', '%s', '%s', '%s')
        );

        if ($result) {
            echo '<div class="notice notice-success"><p>フォームが正常に追加されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>フォームの追加に失敗しました。</p></div>';
        }
    }

    /**
     * フォームの編集処理
     *
     * @return void
     */
    private static function editForm() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $formName = sanitize_text_field($_POST['form_name']);
        $formType = sanitize_text_field($_POST['form_type']);
        $paymentMethod = sanitize_text_field($_POST['payment_method']);

        // バリデーション
        if (!$formId || !$formName || !$formType || !$paymentMethod) {
            echo '<div class="notice notice-error"><p>必須項目が入力されていません。</p></div>';
            return;
        }

        // フォームタイプのバリデーション
        if (!in_array($formType, array('マルシェ', 'ステージ'))) {
            echo '<div class="notice notice-error"><p>無効なフォームタイプが指定されました。</p></div>';
            return;
        }

        // データベースを更新
        $tableName = $wpdb->prefix . 'marche_forms';
        $result = $wpdb->update(
            $tableName,
            array(
                'form_name' => $formName,
                'form_type' => $formType,
                'payment_method' => $paymentMethod
            ),
            array('form_id' => $formId),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>フォームが正常に更新されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>フォームの更新に失敗しました。</p></div>';
        }
    }

    /**
     * フォームの削除処理
     *
     * @param int $formId
     * @return void
     */
    private static function deleteForm($formId) {
        global $wpdb;

        if (!$formId) {
            echo '<div class="notice notice-error"><p>フォームIDが指定されていません。</p></div>';
            return;
        }

        // 関連データの削除（カスケード削除）
        $tables = array(
            $wpdb->prefix . 'marche_dates',
            $wpdb->prefix . 'marche_areas',
            $wpdb->prefix . 'marche_rental_items',
            $wpdb->prefix . 'marche_forms'
        );

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($tables as $table) {
                $wpdb->delete($table, array('form_id' => $formId), array('%d'));
            }

            $wpdb->query('COMMIT');
            echo '<div class="notice notice-success"><p>フォームが正常に削除されました。</p></div>';
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            echo '<div class="notice notice-error"><p>フォームの削除に失敗しました。</p></div>';
        }
    }

    /**
     * 支払い方法のラベルを取得
     *
     * @param string $paymentMethod
     * @return string
     */
    private static function getPaymentMethodLabel($paymentMethod) {
        switch ($paymentMethod) {
            case 'credit_card':
                return 'クレジットカードのみ';
            case 'bank_transfer':
                return '銀行振込みのみ';
            case 'both':
            default:
                return 'クレジットカード・銀行振込み両方';
        }
    }

    /**
     * Contact Form 7用コードの表示
     *
     * @return void
     */
    private static function displayContactFormCode() {
        echo '<div class="marche-cf7-code-section">';
        echo '<h3>Contact Form 7 基本コード</h3>';
        echo '<p>フォーム作成時の基本的なコードテンプレート：</p>';

        $basicCodes = array(
            '開催日選択' => '[select* date data:date first_as_label "開催日を選択してください"]',
            'エリア選択' => '[select* booth-location data:booth-location first_as_label "エリアを選択してください"]',
            '合計金額表示' => '[_total_amount]',
            '支払い方法選択' => '[select* payment-method "クレジットカード" "銀行振込み"]'
        );

        foreach ($basicCodes as $label => $code) {
            echo '<div class="marche-code-block">';
            echo '<label>' . esc_html($label) . '</label>';
            echo '<code>' . esc_html($code) . '</code>';
            echo '<button type="button" class="button marche-copy-button" onclick="navigator.clipboard.writeText(\'' . esc_js($code) . '\')">コピー</button>';
            echo '</div>';
        }

        echo '<p class="description">※ 各フィールドの詳細設定は、対応する管理画面で行ってください。<br>';
        echo '※ レンタル用品の数量フィールドは、レンタル用品管理画面で個別に確認できます。<br>';
        echo '※ <strong>data属性</strong>は動的選択肢生成に必須です。開催日とエリア選択では必ず指定してください。<br>';
        echo '※ <strong>first_as_label</strong> オプションにより、最初の選択肢がプレースホルダーとして機能します。</p>';
        echo '</div>';

        // CSS追加
        echo '<style>
        .marche-cf7-code-section {
            background: #f1f1f1;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            border-left: 4px solid #0073aa;
        }
        .marche-code-block {
            background: #fff;
            padding: 10px;
            margin: 10px 0;
            border-radius: 3px;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .marche-code-block label {
            min-width: 120px;
            font-weight: bold;
        }
        .marche-code-block code {
            flex: 1;
            font-family: Consolas, Monaco, monospace;
            font-size: 14px;
            color: #333;
        }
        .marche-copy-button {
            flex-shrink: 0;
        }
        .marche-debug-section {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .marche-debug-info {
            margin-top: 10px;
        }
        </style>';
    }

    /**
     * データベース状態確認セクションの表示
     */
    private static function displayDatabaseStatus() {
        echo '<div class="marche-debug-section">';
        echo '<h3>📊 データベース状態確認</h3>';
        echo '<div class="marche-debug-info">';

        // テーブル存在チェック
        global $wpdb;
        $tables = array(
            'marche_forms' => $wpdb->prefix . 'marche_forms',
            'marche_dates' => $wpdb->prefix . 'marche_dates',
            'marche_areas' => $wpdb->prefix . 'marche_areas',
            'marche_rental_items' => $wpdb->prefix . 'marche_rental_items',
            'marche_applications' => $wpdb->prefix . 'marche_applications',
            'marche_files' => $wpdb->prefix . 'marche_files'
        );

        echo '<table class="wp-list-table widefat">';
        echo '<thead><tr><th>テーブル名</th><th>状態</th><th>件数</th></tr></thead>';
        echo '<tbody>';

        foreach ($tables as $key => $table_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
            $count = 0;

            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            }

            echo '<tr>';
            echo '<td>' . esc_html($table_name) . '</td>';
            echo '<td>' . ($exists ? '<span style="color: green;">✓ 存在</span>' : '<span style="color: red;">✗ 不存在</span>') . '</td>';
            echo '<td>' . ($exists ? number_format($count) . ' 件' : '-') . '</td>';
            echo '</tr>';
        }

                echo '</tbody></table>';

        // 手動テーブル作成ボタン
        echo '<form method="post" style="margin-top: 10px;">';
        wp_nonce_field('marche_debug_action', 'marche_debug_nonce');
        echo '<input type="submit" name="create_tables" class="button button-secondary" value="データベーステーブルを再作成" onclick="return confirm(\'データベーステーブルを再作成しますか？既存データは保持されます。\')">';
        echo '</form>';

        echo '</div>';
        echo '</div>';

        // 最新の申し込みデータ表示
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . 'marche_applications')) === $wpdb->prefix . 'marche_applications') {
            echo '<div class="marche-debug-section" style="margin-top: 20px;">';
            echo '<h3>📝 最新の申し込みデータ（最新5件）</h3>';

            $recent_applications = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}marche_applications ORDER BY created_at DESC LIMIT 5",
                ARRAY_A
            );

            if (!empty($recent_applications)) {
                echo '<table class="wp-list-table widefat">';
                echo '<thead><tr><th>ID</th><th>フォームID</th><th>開催日ID</th><th>エリア名</th><th>作成日時</th></tr></thead>';
                echo '<tbody>';

                foreach ($recent_applications as $app) {
                    echo '<tr>';
                    echo '<td>' . esc_html($app['id']) . '</td>';
                    echo '<td>' . esc_html($app['form_id']) . '</td>';
                    echo '<td>' . esc_html($app['date_id']) . '</td>';
                    echo '<td>' . esc_html($app['area_name']) . '</td>';
                    echo '<td>' . esc_html($app['created_at']) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p>申し込みデータがありません。</p>';
            }

            echo '</div>';
        }
    }
}
