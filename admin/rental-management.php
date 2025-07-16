<?php
/**
 * レンタル用品管理画面
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
 * レンタル用品管理クラス
 *
 * @class MarcheRentalManagement
 * @description レンタル用品管理画面の処理を行うクラス
 */
class MarcheRentalManagement {

    /**
     * レンタル用品管理画面の表示
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
        $rentalId = isset($_GET['rental_id']) ? intval($_GET['rental_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>レンタル用品管理</h1>';

        // フォーム選択
        self::displayFormSelector($formId);

        if ($formId) {
            switch ($action) {
                case 'add':
                    self::displayAddForm($formId);
                    break;
                case 'edit':
                    self::displayEditForm($formId, $rentalId);
                    break;
                case 'delete':
                    self::deleteRental($formId, $rentalId);
                    self::displayRentalList($formId);
                    break;
                case 'toggle':
                    self::toggleRentalStatus($formId, $rentalId);
                    self::displayRentalList($formId);
                    break;
                default:
                    self::displayRentalList($formId);
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
        echo '<input type="hidden" name="page" value="marche-rental-management">';
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
        if (!isset($_POST['marche_rental_nonce']) || !wp_verify_nonce($_POST['marche_rental_nonce'], 'marche_rental_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        switch ($action) {
            case 'add_rental':
                self::addRental();
                break;
            case 'edit_rental':
                self::editRental();
                break;
            case 'update_sort_order':
                self::updateSortOrder();
                break;
        }
    }

    /**
     * レンタル用品一覧の表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayRentalList($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $rentals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d ORDER BY sort_order, item_name",
            $formId
        ));

        echo '<div class="marche-rental-list">';
        echo '<div class="marche-list-header">';
        echo '<h2>レンタル用品一覧</h2>';
        echo '<a href="?page=marche-rental-management&action=add&form_id=' . $formId . '" class="button button-primary">新規追加</a>';
        echo '</div>';

        // Contact Form 7コード表示
        self::displayContactFormCode($formId);

        if (!empty($rentals)) {
            echo '<form method="post" action="" id="sort-form">';
            wp_nonce_field('marche_rental_action', 'marche_rental_nonce');
            echo '<input type="hidden" name="action" value="update_sort_order">';
            echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';

            echo '<table class="wp-list-table widefat fixed striped marche-sortable-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th width="30">順序</th>';
            echo '<th>レンタル用品名</th>';
            echo '<th>料金</th>';
            echo '<th>単位</th>';
            echo '<th>数量範囲</th>';
            echo '<th>状態</th>';
            echo '<th>作成日</th>';
            echo '<th>操作</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody id="sortable-rentals">';

            foreach ($rentals as $rental) {
                $statusClass = $rental->is_active ? 'active' : 'inactive';
                $statusText = $rental->is_active ? '有効' : '無効';

                echo '<tr class="' . $statusClass . '" data-rental-id="' . $rental->id . '">';
                echo '<td class="sort-handle">';
                echo '<input type="hidden" name="rental_ids[]" value="' . $rental->id . '">';
                echo '<span class="dashicons dashicons-menu"></span>';
                echo '</td>';
                echo '<td><strong>' . esc_html($rental->item_name) . '</strong></td>';
                echo '<td>¥' . number_format($rental->price) . '</td>';
                echo '<td>' . esc_html($rental->unit) . '</td>';
                echo '<td>' . esc_html($rental->min_quantity) . '～' . esc_html($rental->max_quantity) . '</td>';
                echo '<td>';
                echo '<span class="marche-status-badge ' . $statusClass . '">' . $statusText . '</span>';
                echo '</td>';
                echo '<td>' . esc_html(date_i18n('Y年n月j日', strtotime($rental->created_at))) . '</td>';
                echo '<td>';
                echo '<a href="?page=marche-rental-management&action=edit&form_id=' . $formId . '&rental_id=' . $rental->id . '" class="button">編集</a> ';
                echo '<a href="?page=marche-rental-management&action=toggle&form_id=' . $formId . '&rental_id=' . $rental->id . '" class="button">' . ($rental->is_active ? '無効化' : '有効化') . '</a> ';
                echo '<a href="?page=marche-rental-management&action=delete&form_id=' . $formId . '&rental_id=' . $rental->id . '" class="button button-secondary marche-delete-confirm" data-confirm-message="このレンタル用品を削除しますか？">削除</a>';
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
            echo '<p>レンタル用品が登録されていません。</p>';
        }

        echo '</div>';
    }

    /**
     * レンタル用品追加フォームの表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayAddForm($formId) {
        echo '<div class="marche-rental-form">';
        echo '<h2>レンタル用品追加</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field('marche_rental_action', 'marche_rental_nonce');
        echo '<input type="hidden" name="action" value="add_rental">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="item_name">レンタル用品名 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="text" id="item_name" name="item_name" class="regular-text" required>';
        echo '<p class="description">レンタル用品の名称を入力してください</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="field_name">フィールド名 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="text" id="field_name" name="field_name" class="regular-text" required pattern="[a-zA-Z0-9_-]+" placeholder="例: tent, table, chair">';
        echo '<p class="description">Contact Form 7で使用するフィールド名を英数字で入力してください（rental-の後に続く部分）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="price">料金 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<div class="marche-price-input">';
        echo '<span class="marche-yen-symbol">¥</span>';
        echo '<input type="number" id="price" name="price" min="0" step="1" class="marche-price-field" required>';
        echo '</div>';
        echo '<p class="description">レンタル料金を円単位で入力してください</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="unit">単位 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<select id="unit" name="unit" required>';
        echo '<option value="">単位を選択してください</option>';
        echo '<option value="個">個</option>';
        echo '<option value="台">台</option>';
        echo '<option value="セット">セット</option>';
        echo '<option value="本">本</option>';
        echo '<option value="枚">枚</option>';
        echo '<option value="日">日</option>';
        echo '<option value="時間">時間</option>';
        echo '<option value="その他">その他</option>';
        echo '</select>';
        echo '<p class="description">レンタル用品の単位を選択してください</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="description">説明</label></th>';
        echo '<td>';
        echo '<textarea id="description" name="description" rows="3" class="large-text"></textarea>';
        echo '<p class="description">レンタル用品の説明を入力してください（任意）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="min_quantity">最小数量 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" id="min_quantity" name="min_quantity" min="0" max="99" value="0" class="small-text" required>';
        echo '<p class="description">レンタル可能な最小数量を設定してください（0以上）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="max_quantity">最大数量 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" id="max_quantity" name="max_quantity" min="1" max="99" value="9" class="small-text" required>';
        echo '<p class="description">レンタル可能な最大数量を設定してください（1以上）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="is_active">状態</label></th>';
        echo '<td>';
        echo '<select name="is_active" id="is_active">';
        echo '<option value="1" selected>有効</option>';
        echo '<option value="0">無効</option>';
        echo '</select>';
        echo '<p class="description">レンタル用品の状態を選択してください</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="レンタル用品を追加">';
        echo ' <a href="?page=marche-rental-management&form_id=' . $formId . '" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * レンタル用品編集フォームの表示
     *
     * @param int $formId
     * @param int $rentalId
     * @return void
     */
    private static function displayEditForm($formId, $rentalId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d AND form_id = %d",
            $rentalId,
            $formId
        ));

        if (!$rental) {
            echo '<div class="notice notice-error"><p>レンタル用品が見つかりません。</p></div>';
            return;
        }

        echo '<div class="marche-rental-form">';
        echo '<h2>レンタル用品編集</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field('marche_rental_action', 'marche_rental_nonce');
        echo '<input type="hidden" name="action" value="edit_rental">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';
        echo '<input type="hidden" name="rental_id" value="' . esc_attr($rentalId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="item_name">レンタル用品名 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="text" id="item_name" name="item_name" class="regular-text" value="' . esc_attr($rental->item_name) . '" required>';
        echo '<p class="description">レンタル用品の名称を入力してください</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="field_name">フィールド名 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="text" id="field_name" name="field_name" class="regular-text" value="' . esc_attr($rental->field_name) . '" required pattern="[a-zA-Z0-9_-]+" placeholder="例: tent, table, chair">';
        echo '<p class="description">Contact Form 7で使用するフィールド名を英数字で入力してください（rental-の後に続く部分）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="price">料金 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<div class="marche-price-input">';
        echo '<span class="marche-yen-symbol">¥</span>';
        echo '<input type="number" id="price" name="price" min="0" step="1" class="marche-price-field" value="' . esc_attr($rental->price) . '" required>';
        echo '</div>';
        echo '<p class="description">レンタル料金を円単位で入力してください</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="unit">単位 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<select id="unit" name="unit" required>';
        echo '<option value="">単位を選択してください</option>';
        $units = ['個', '台', 'セット', '本', '枚', '日', '時間', 'その他'];
        foreach ($units as $unit) {
            $selected = ($rental->unit === $unit) ? 'selected' : '';
            echo '<option value="' . esc_attr($unit) . '" ' . $selected . '>' . esc_html($unit) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">レンタル用品の単位を選択してください</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="description">説明</label></th>';
        echo '<td>';
        echo '<textarea id="description" name="description" rows="3" class="large-text">' . esc_textarea($rental->description) . '</textarea>';
        echo '<p class="description">レンタル用品の説明を入力してください（任意）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="min_quantity">最小数量 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" id="min_quantity" name="min_quantity" min="0" max="99" value="' . esc_attr($rental->min_quantity) . '" class="small-text" required>';
        echo '<p class="description">レンタル可能な最小数量を設定してください（0以上）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="max_quantity">最大数量 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" id="max_quantity" name="max_quantity" min="1" max="99" value="' . esc_attr($rental->max_quantity) . '" class="small-text" required>';
        echo '<p class="description">レンタル可能な最大数量を設定してください（1以上）</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="is_active">状態</label></th>';
        echo '<td>';
        echo '<select name="is_active" id="is_active">';
        echo '<option value="1"' . selected($rental->is_active, 1, false) . '>有効</option>';
        echo '<option value="0"' . selected($rental->is_active, 0, false) . '>無効</option>';
        echo '</select>';
        echo '<p class="description">レンタル用品の状態を選択してください</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="レンタル用品を更新">';
        echo ' <a href="?page=marche-rental-management&form_id=' . $formId . '" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * レンタル用品の追加
     *
     * @return void
     */
    private static function addRental() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $itemName = sanitize_text_field($_POST['item_name']);
        $fieldName = sanitize_text_field($_POST['field_name']);
        $price = intval($_POST['price']);
        $unit = sanitize_text_field($_POST['unit']);
        $description = sanitize_textarea_field($_POST['description']);
        $minQuantity = intval($_POST['min_quantity']);
        $maxQuantity = intval($_POST['max_quantity']);
        $isActive = intval($_POST['is_active']);

        // バリデーション
        if (empty($itemName) || empty($fieldName) || $price < 0 || empty($unit) || $minQuantity < 0 || $maxQuantity < 1 || $minQuantity > $maxQuantity) {
            echo '<div class="notice notice-error"><p>必須項目を正しく入力してください。最小数量は最大数量以下である必要があります。</p></div>';
            return;
        }

        // フィールド名の形式チェック
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $fieldName)) {
            echo '<div class="notice notice-error"><p>フィールド名は英数字、アンダースコア、ハイフンのみ使用できます。</p></div>';
            return;
        }

        // 重複チェック（アイテム名とフィールド名）
        $tableName = $wpdb->prefix . 'marche_rental_items';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d AND (item_name = %s OR field_name = %s)",
            $formId,
            $itemName,
            $fieldName
        ));

        if ($existing > 0) {
            echo '<div class="notice notice-error"><p>同じ名前またはフィールド名のレンタル用品が既に存在します。</p></div>';
            return;
        }

        // 最大ソート順を取得
        $maxSortOrder = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM {$tableName} WHERE form_id = %d",
            $formId
        ));
        $sortOrder = $maxSortOrder ? $maxSortOrder + 1 : 1;

        // データベースに挿入
        $result = $wpdb->insert(
            $tableName,
            [
                'form_id' => $formId,
                'item_name' => $itemName,
                'field_name' => $fieldName,
                'price' => $price,
                'unit' => $unit,
                'description' => $description,
                'min_quantity' => $minQuantity,
                'max_quantity' => $maxQuantity,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
        );

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>レンタル用品を追加しました。</p></div>';
            // 一覧画面にリダイレクト
            echo '<script>window.location.href = "?page=marche-rental-management&form_id=' . $formId . '";</script>';
        } else {
            echo '<div class="notice notice-error"><p>レンタル用品の追加に失敗しました。</p></div>';
        }
    }

    /**
     * レンタル用品の編集
     *
     * @return void
     */
    private static function editRental() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $rentalId = intval($_POST['rental_id']);
        $itemName = sanitize_text_field($_POST['item_name']);
        $fieldName = sanitize_text_field($_POST['field_name']);
        $price = intval($_POST['price']);
        $unit = sanitize_text_field($_POST['unit']);
        $description = sanitize_textarea_field($_POST['description']);
        $minQuantity = intval($_POST['min_quantity']);
        $maxQuantity = intval($_POST['max_quantity']);
        $isActive = intval($_POST['is_active']);

        // バリデーション
        if (empty($itemName) || empty($fieldName) || $price < 0 || empty($unit) || $minQuantity < 0 || $maxQuantity < 1 || $minQuantity > $maxQuantity) {
            echo '<div class="notice notice-error"><p>必須項目を正しく入力してください。最小数量は最大数量以下である必要があります。</p></div>';
            return;
        }

        // フィールド名の形式チェック
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $fieldName)) {
            echo '<div class="notice notice-error"><p>フィールド名は英数字、アンダースコア、ハイフンのみ使用できます。</p></div>';
            return;
        }

        // 重複チェック（自分以外）
        $tableName = $wpdb->prefix . 'marche_rental_items';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d AND (item_name = %s OR field_name = %s) AND id != %d",
            $formId,
            $itemName,
            $fieldName,
            $rentalId
        ));

        if ($existing > 0) {
            echo '<div class="notice notice-error"><p>同じ名前またはフィールド名のレンタル用品が既に存在します。</p></div>';
            return;
        }

        // データベースを更新
        $result = $wpdb->update(
            $tableName,
            [
                'item_name' => $itemName,
                'field_name' => $fieldName,
                'price' => $price,
                'unit' => $unit,
                'description' => $description,
                'min_quantity' => $minQuantity,
                'max_quantity' => $maxQuantity,
                'is_active' => $isActive,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $rentalId, 'form_id' => $formId],
            ['%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s'],
            ['%d', '%d']
        );

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>レンタル用品を更新しました。</p></div>';
            // 一覧画面にリダイレクト
            echo '<script>window.location.href = "?page=marche-rental-management&form_id=' . $formId . '";</script>';
        } else {
            echo '<div class="notice notice-error"><p>レンタル用品の更新に失敗しました。</p></div>';
        }
    }

    /**
     * レンタル用品の削除
     *
     * @param int $formId
     * @param int $rentalId
     * @return void
     */
    private static function deleteRental($formId, $rentalId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $result = $wpdb->delete(
            $tableName,
            ['id' => $rentalId, 'form_id' => $formId],
            ['%d', '%d']
        );

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>レンタル用品を削除しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>レンタル用品の削除に失敗しました。</p></div>';
        }
    }

    /**
     * レンタル用品の状態切り替え
     *
     * @param int $formId
     * @param int $rentalId
     * @return void
     */
    private static function toggleRentalStatus($formId, $rentalId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';

        // 現在の状態を取得
        $currentStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$tableName} WHERE id = %d AND form_id = %d",
            $rentalId,
            $formId
        ));

        if ($currentStatus !== null) {
            $newStatus = $currentStatus ? 0 : 1;
            $result = $wpdb->update(
                $tableName,
                [
                    'is_active' => $newStatus,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $rentalId, 'form_id' => $formId],
                ['%d', '%s'],
                ['%d', '%d']
            );

            if ($result !== false) {
                $statusText = $newStatus ? '有効' : '無効';
                echo '<div class="notice notice-success"><p>レンタル用品を' . $statusText . 'にしました。</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>状態の変更に失敗しました。</p></div>';
            }
        }
    }

    /**
     * ソート順の更新
     *
     * @return void
     */
    private static function updateSortOrder() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $rentalIds = isset($_POST['rental_ids']) ? array_map('intval', $_POST['rental_ids']) : [];

        if (empty($rentalIds)) {
            echo '<div class="notice notice-error"><p>並び順の更新に失敗しました。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $success = true;

        foreach ($rentalIds as $index => $rentalId) {
            $sortOrder = $index + 1;
            $result = $wpdb->update(
                $tableName,
                [
                    'sort_order' => $sortOrder,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $rentalId, 'form_id' => $formId],
                ['%d', '%s'],
                ['%d', '%d']
            );

            if ($result === false) {
                $success = false;
                break;
            }
        }

        if ($success) {
            echo '<div class="notice notice-success"><p>並び順を更新しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>並び順の更新に失敗しました。</p></div>';
        }
    }

    /**
     * フォームのレンタル用品一覧を取得
     *
     * @param int $formId
     * @return array
     */
    public static function getFormRentals($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d AND is_active = 1 ORDER BY sort_order, item_name",
            $formId
        ));
    }

    /**
     * レンタル用品の料金を取得
     *
     * @param int $rentalId
     * @return int|null
     */
    public static function getRentalPrice($rentalId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT price FROM {$tableName} WHERE id = %d AND is_active = 1",
            $rentalId
        ));
    }

    /**
     * Contact Form 7用コードの表示
     *
     * @param int $formId
     * @return void
     */
    private static function displayContactFormCode($formId) {
        global $wpdb;

        // レンタル用品一覧を取得
        $tableName = $wpdb->prefix . 'marche_rental_items';
        $rentals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d AND is_active = 1 ORDER BY sort_order, item_name",
            $formId
        ));

        echo '<div class="marche-cf7-code-section">';
        echo '<h3>Contact Form 7 コード</h3>';
        echo '<p>以下のコードをContact Form 7のフォームに貼り付けてください：</p>';

        if (!empty($rentals)) {
            foreach ($rentals as $rental) {
                $fieldName = 'rental-' . $rental->field_name;
                $codeText = '[number* ' . $fieldName . ' min:' . $rental->min_quantity . ' max:' . $rental->max_quantity . ' "0"]';

                echo '<div class="marche-code-block">';
                echo '<label>' . esc_html($rental->item_name) . ' (' . esc_html($rental->unit) . ')</label>';
                echo '<code>' . esc_html($codeText) . '</code>';
                echo '<button type="button" class="button marche-copy-button" onclick="navigator.clipboard.writeText(\'' . esc_js($codeText) . '\')">コピー</button>';
                echo '</div>';
            }
        } else {
            echo '<p class="description">レンタル用品が登録されていません。</p>';
        }

        echo '<p class="description">※ 各レンタル用品の数量制限は自動的に適用されます。最小・最大数量の設定に従ってバリデーションが行われます。<br>';
        echo '※ レンタル用品の数量フィールドは wpcf7_form_tag フックで動的に設定されるため、data属性は不要です。</p>';
        echo '</div>';

        // CSSは assets/css/admin.css に統合済み
    }
}
