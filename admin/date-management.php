<?php
/**
 * 開催日管理画面
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
 * 開催日管理クラス
 *
 * @class MarcheDateManagement
 * @description 開催日管理画面の処理を行うクラス
 */
class MarcheDateManagement {

    /**
     * 開催日管理画面の表示
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
        $dateId = isset($_GET['date_id']) ? intval($_GET['date_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>開催日管理</h1>';

        // フォーム選択
        self::displayFormSelector($formId);

        if ($formId) {
            switch ($action) {
                case 'add':
                    self::displayAddForm($formId);
                    break;
                case 'edit':
                    self::displayEditForm($formId, $dateId);
                    break;
                case 'delete':
                    self::deleteDate($formId, $dateId);
                    self::displayDateList($formId);
                    break;
                case 'toggle':
                    self::toggleDateStatus($formId, $dateId);
                    self::displayDateList($formId);
                    break;
                default:
                    self::displayDateList($formId);
                    break;
            }
        }

        echo '</div>';
    }

    /**
     * フォーム選択の表示
     *
     * @param int $selectedFormId
     * @return void
     */
    private static function displayFormSelector($selectedFormId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_forms';
        $forms = $wpdb->get_results("SELECT * FROM {$tableName} ORDER BY form_name");

        echo '<div class="marche-form-selector">';
        echo '<h2>フォーム選択</h2>';
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="marche-date-management">';
        echo '<select name="form_id" onchange="this.form.submit()">';
        echo '<option value="">フォームを選択してください</option>';

        foreach ($forms as $form) {
            $selected = ($form->form_id == $selectedFormId) ? 'selected' : '';
            echo '<option value="' . esc_attr($form->form_id) . '" ' . $selected . '>';
            echo esc_html($form->form_name) . ' (ID: ' . $form->form_id . ')';
            echo '</option>';
        }

        echo '</select>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * POSTリクエストの処理
     *
     * @return void
     */
    private static function handlePostRequest() {
        // nonce確認
        if (!isset($_POST['marche_date_nonce']) || !wp_verify_nonce($_POST['marche_date_nonce'], 'marche_date_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        switch ($action) {
            case 'add_date':
                self::addDate();
                break;
            case 'edit_date':
                self::editDate();
                break;
            case 'update_sort_order':
                self::updateSortOrder();
                break;
        }
    }

    /**
     * 開催日一覧の表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayDateList($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_dates';
        $dates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d ORDER BY sort_order, date_value",
            $formId
        ));

        echo '<div class="marche-date-list">';
        echo '<div class="marche-list-header">';
        echo '<h2>開催日一覧</h2>';
        echo '<a href="?page=marche-date-management&action=add&form_id=' . $formId . '" class="button button-primary">新規追加</a>';
        echo '</div>';

        // Contact Form 7コード表示
        self::displayContactFormCode($formId);

        if (!empty($dates)) {
            echo '<form method="post" action="" id="sort-form">';
            wp_nonce_field('marche_date_action', 'marche_date_nonce');
            echo '<input type="hidden" name="action" value="update_sort_order">';
            echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';

            echo '<table class="wp-list-table widefat fixed striped marche-sortable-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th width="30">順序</th>';
            echo '<th>開催日</th>';
            echo '<th>説明</th>';
            echo '<th>状態</th>';
            echo '<th>作成日</th>';
            echo '<th>操作</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody id="sortable-dates">';

            foreach ($dates as $date) {
                $statusClass = $date->is_active ? 'active' : 'inactive';
                $statusText = $date->is_active ? '有効' : '無効';

                echo '<tr class="' . $statusClass . '" data-date-id="' . $date->id . '">';
                echo '<td class="sort-handle">';
                echo '<input type="hidden" name="date_ids[]" value="' . $date->id . '">';
                echo '<span class="dashicons dashicons-menu"></span>';
                echo '</td>';
                echo '<td>' . esc_html(date_i18n('Y年n月j日 (D)', strtotime($date->date_value))) . '</td>';
                echo '<td>' . esc_html($date->description) . '</td>';
                echo '<td>';
                echo '<span class="marche-status-badge ' . $statusClass . '">' . $statusText . '</span>';
                echo '</td>';
                echo '<td>' . esc_html(date_i18n('Y年n月j日', strtotime($date->created_at))) . '</td>';
                echo '<td>';
                echo '<a href="?page=marche-date-management&action=edit&form_id=' . $formId . '&date_id=' . $date->id . '" class="button">編集</a> ';
                echo '<a href="?page=marche-date-management&action=toggle&form_id=' . $formId . '&date_id=' . $date->id . '" class="button">' . ($date->is_active ? '無効化' : '有効化') . '</a> ';
                echo '<a href="?page=marche-date-management&action=delete&form_id=' . $formId . '&date_id=' . $date->id . '" class="button button-secondary marche-delete-confirm" data-confirm-message="この開催日を削除しますか？">削除</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

            echo '<p class="submit">';
            echo '<input type="submit" name="submit" class="button" value="並び順を保存">';
            echo '</p>';
            echo '</form>';
        } else {
            echo '<p>登録されている開催日はありません。</p>';
        }

        echo '</div>';
    }

    /**
     * 開催日追加画面の表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayAddForm($formId) {
        echo '<h2>開催日追加</h2>';
        echo '<form method="post" action="" class="marche-form">';
        wp_nonce_field('marche_date_action', 'marche_date_nonce');
        echo '<input type="hidden" name="action" value="add_date">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="date_value">開催日 <span class="required">*</span></label></th>';
        echo '<td><input type="date" name="date_value" id="date_value" class="regular-text" required></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="description">説明</label></th>';
        echo '<td>';
        echo '<input type="text" name="description" id="description" class="regular-text" placeholder="例: 春の大マルシェ">';
        echo '<p class="description">開催日の説明や特別な情報を入力してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="is_active">状態</label></th>';
        echo '<td>';
        echo '<select name="is_active" id="is_active">';
        echo '<option value="1">有効</option>';
        echo '<option value="0">無効</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="sort_order">並び順</label></th>';
        echo '<td>';
        echo '<input type="number" name="sort_order" id="sort_order" class="small-text" value="0" min="0">';
        echo '<p class="description">小さい数字ほど上に表示されます。</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="追加">';
        echo ' <a href="?page=marche-date-management&form_id=' . $formId . '" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * 開催日編集画面の表示
     *
     * @param int $formId
     * @param int $dateId
     * @return void
     */
    private static function displayEditForm($formId, $dateId) {
        global $wpdb;

        if (!$dateId) {
            echo '<div class="notice notice-error"><p>開催日IDが指定されていません。</p></div>';
            echo '<a href="?page=marche-date-management&form_id=' . $formId . '" class="button">戻る</a>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_dates';
        $date = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d AND form_id = %d",
            $dateId,
            $formId
        ));

        if (!$date) {
            echo '<div class="notice notice-error"><p>指定された開催日が見つかりません。</p></div>';
            echo '<a href="?page=marche-date-management&form_id=' . $formId . '" class="button">戻る</a>';
            return;
        }

        echo '<h2>開催日編集</h2>';
        echo '<form method="post" action="" class="marche-form">';
        wp_nonce_field('marche_date_action', 'marche_date_nonce');
        echo '<input type="hidden" name="action" value="edit_date">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';
        echo '<input type="hidden" name="date_id" value="' . esc_attr($dateId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="date_value">開催日 <span class="required">*</span></label></th>';
        echo '<td><input type="date" name="date_value" id="date_value" class="regular-text" value="' . esc_attr($date->date_value) . '" required></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="description">説明</label></th>';
        echo '<td>';
        echo '<input type="text" name="description" id="description" class="regular-text" value="' . esc_attr($date->description) . '" placeholder="例: 春の大マルシェ">';
        echo '<p class="description">開催日の説明や特別な情報を入力してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="is_active">状態</label></th>';
        echo '<td>';
        echo '<select name="is_active" id="is_active">';
        echo '<option value="1"' . selected($date->is_active, 1, false) . '>有効</option>';
        echo '<option value="0"' . selected($date->is_active, 0, false) . '>無効</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="sort_order">並び順</label></th>';
        echo '<td>';
        echo '<input type="number" name="sort_order" id="sort_order" class="small-text" value="' . esc_attr($date->sort_order) . '" min="0">';
        echo '<p class="description">小さい数字ほど上に表示されます。</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="更新">';
        echo ' <a href="?page=marche-date-management&form_id=' . $formId . '" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * 開催日の追加処理
     *
     * @return void
     */
    private static function addDate() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $dateValue = sanitize_text_field($_POST['date_value']);
        $description = sanitize_text_field($_POST['description']);
        $isActive = intval($_POST['is_active']);
        $sortOrder = intval($_POST['sort_order']);

        // バリデーション
        if (!$formId || !$dateValue) {
            echo '<div class="notice notice-error"><p>必須項目が入力されていません。</p></div>';
            return;
        }

        // 日付の形式チェック
        if (!self::isValidDate($dateValue)) {
            echo '<div class="notice notice-error"><p>正しい日付を入力してください。</p></div>';
            return;
        }

        // 重複チェック
        $tableName = $wpdb->prefix . 'marche_dates';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d AND date_value = %s",
            $formId,
            $dateValue
        ));

        if ($exists) {
            echo '<div class="notice notice-error"><p>この開催日は既に登録されています。</p></div>';
            return;
        }

        // データベースに挿入
        $result = $wpdb->insert(
            $tableName,
            array(
                'form_id' => $formId,
                'date_value' => $dateValue,
                'description' => $description,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ),
            array('%d', '%s', '%s', '%d', '%d')
        );

        if ($result) {
            echo '<div class="notice notice-success"><p>開催日が正常に追加されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>開催日の追加に失敗しました。</p></div>';
        }
    }

    /**
     * 開催日の編集処理
     *
     * @return void
     */
    private static function editDate() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $dateId = intval($_POST['date_id']);
        $dateValue = sanitize_text_field($_POST['date_value']);
        $description = sanitize_text_field($_POST['description']);
        $isActive = intval($_POST['is_active']);
        $sortOrder = intval($_POST['sort_order']);

        // バリデーション
        if (!$formId || !$dateId || !$dateValue) {
            echo '<div class="notice notice-error"><p>必須項目が入力されていません。</p></div>';
            return;
        }

        // 日付の形式チェック
        if (!self::isValidDate($dateValue)) {
            echo '<div class="notice notice-error"><p>正しい日付を入力してください。</p></div>';
            return;
        }

        // 重複チェック（自分以外）
        $tableName = $wpdb->prefix . 'marche_dates';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d AND date_value = %s AND id != %d",
            $formId,
            $dateValue,
            $dateId
        ));

        if ($exists) {
            echo '<div class="notice notice-error"><p>この開催日は既に登録されています。</p></div>';
            return;
        }

        // データベースを更新
        $result = $wpdb->update(
            $tableName,
            array(
                'date_value' => $dateValue,
                'description' => $description,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ),
            array('id' => $dateId, 'form_id' => $formId),
            array('%s', '%s', '%d', '%d'),
            array('%d', '%d')
        );

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>開催日が正常に更新されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>開催日の更新に失敗しました。</p></div>';
        }
    }

    /**
     * 開催日の削除処理
     *
     * @param int $formId
     * @param int $dateId
     * @return void
     */
    private static function deleteDate($formId, $dateId) {
        global $wpdb;

        if (!$formId || !$dateId) {
            echo '<div class="notice notice-error"><p>開催日IDが指定されていません。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_dates';
        $result = $wpdb->delete(
            $tableName,
            array('id' => $dateId, 'form_id' => $formId),
            array('%d', '%d')
        );

        if ($result) {
            echo '<div class="notice notice-success"><p>開催日が正常に削除されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>開催日の削除に失敗しました。</p></div>';
        }
    }

    /**
     * 開催日の有効/無効切り替え
     *
     * @param int $formId
     * @param int $dateId
     * @return void
     */
    private static function toggleDateStatus($formId, $dateId) {
        global $wpdb;

        if (!$formId || !$dateId) {
            echo '<div class="notice notice-error"><p>開催日IDが指定されていません。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_dates';

        // 現在の状態を取得
        $currentStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$tableName} WHERE id = %d AND form_id = %d",
            $dateId,
            $formId
        ));

        if ($currentStatus === null) {
            echo '<div class="notice notice-error"><p>指定された開催日が見つかりません。</p></div>';
            return;
        }

        // 状態を切り替え
        $newStatus = $currentStatus ? 0 : 1;
        $result = $wpdb->update(
            $tableName,
            array('is_active' => $newStatus),
            array('id' => $dateId, 'form_id' => $formId),
            array('%d'),
            array('%d', '%d')
        );

        if ($result !== false) {
            $statusText = $newStatus ? '有効' : '無効';
            echo '<div class="notice notice-success"><p>開催日を' . $statusText . 'に変更しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>開催日の状態変更に失敗しました。</p></div>';
        }
    }

    /**
     * 並び順の更新処理
     *
     * @return void
     */
    private static function updateSortOrder() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $dateIds = isset($_POST['date_ids']) ? array_map('intval', $_POST['date_ids']) : array();

        if (!$formId || empty($dateIds)) {
            echo '<div class="notice notice-error"><p>並び順の更新に必要なデータが不足しています。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_dates';
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($dateIds as $index => $dateId) {
                $wpdb->update(
                    $tableName,
                    array('sort_order' => $index),
                    array('id' => $dateId, 'form_id' => $formId),
                    array('%d'),
                    array('%d', '%d')
                );
            }

            $wpdb->query('COMMIT');
            echo '<div class="notice notice-success"><p>並び順が正常に更新されました。</p></div>';
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            echo '<div class="notice notice-error"><p>並び順の更新に失敗しました。</p></div>';
        }
    }

    /**
     * 日付の形式チェック
     *
     * @param string $date
     * @return bool
     */
    private static function isValidDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Contact Form 7用コードの表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayContactFormCode($formId) {
        echo '<div class="marche-cf7-code-section">';
        echo '<h3>Contact Form 7 コード</h3>';
        echo '<p>以下のコードをContact Form 7のフォームに貼り付けてください：</p>';
        echo '<div class="marche-code-block">';
        echo '<code>[select* date data:date first_as_label "開催日を選択してください"]</code>';
        echo '<button type="button" class="button marche-copy-button" onclick="navigator.clipboard.writeText(\'[select* date data:date first_as_label &quot;開催日を選択してください&quot;]\')">コピー</button>';
        echo '</div>';
        echo '<p class="description">※ 開催日の選択肢は自動的に生成されます。有効な開催日のみが表示されます。<br>';
        echo '※ <strong>data:date</strong> 属性は必須です。これにより動的選択肢が生成されます。<br>';
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
        .marche-code-block code {
            flex: 1;
            font-family: Consolas, Monaco, monospace;
            font-size: 14px;
            color: #333;
        }
        .marche-copy-button {
            flex-shrink: 0;
        }
        </style>';
    }
}
