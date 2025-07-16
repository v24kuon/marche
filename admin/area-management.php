<?php
/**
 * エリア管理画面
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
 * エリア管理クラス
 *
 * @class MarcheAreaManagement
 * @description エリア管理画面の処理を行うクラス
 */
class MarcheAreaManagement {

    /**
     * エリア管理画面の表示
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
        $areaId = isset($_GET['area_id']) ? intval($_GET['area_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>エリア管理</h1>';

        // フォーム選択
        self::displayFormSelector($formId);

        if ($formId) {
            // 開催日選択
            self::displayDateSelector($formId, $dateId);

            if ($dateId) {
                switch ($action) {
                    case 'add':
                        self::displayAddForm($formId, $dateId);
                        break;
                    case 'edit':
                        self::displayEditForm($formId, $dateId, $areaId);
                        break;
                    case 'delete':
                        self::deleteArea($formId, $dateId, $areaId);
                        self::displayAreaList($formId, $dateId);
                        break;
                    case 'toggle':
                        self::toggleAreaStatus($formId, $dateId, $areaId);
                        self::displayAreaList($formId, $dateId);
                        break;
                    default:
                        self::displayAreaList($formId, $dateId);
                        break;
                }
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
        echo '<input type="hidden" name="page" value="marche-area-management">';
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
     * 開催日選択の表示
     *
     * @param int $formId
     * @param int $selectedDateId
     * @return void
     */
    private static function displayDateSelector($formId, $selectedDateId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_dates';
        $dates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d ORDER BY sort_order, date_value",
            $formId
        ));

        echo '<div class="marche-date-selector">';
        echo '<h2>開催日選択</h2>';

        if (empty($dates)) {
            echo '<div class="notice notice-warning"><p>このフォームには開催日が登録されていません。先に<a href="?page=marche-date-management&form_id=' . $formId . '">開催日管理</a>で開催日を登録してください。</p></div>';
            return;
        }

        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="marche-area-management">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';
        echo '<select name="date_id" onchange="this.form.submit()">';
        echo '<option value="">開催日を選択してください</option>';

        foreach ($dates as $date) {
            $selected = ($date->id == $selectedDateId) ? 'selected' : '';
            $statusText = $date->is_active ? '' : '（無効）';
            echo '<option value="' . esc_attr($date->id) . '" ' . $selected . '>';
            echo esc_html(date_i18n('Y年n月j日', strtotime($date->date_value))) . $statusText;
            if ($date->description) {
                echo ' - ' . esc_html($date->description);
            }
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
        if (!isset($_POST['marche_area_nonce']) || !wp_verify_nonce($_POST['marche_area_nonce'], 'marche_area_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        switch ($action) {
            case 'add_area':
                self::addArea();
                break;
            case 'edit_area':
                self::editArea();
                break;
            case 'update_sort_order':
                self::updateSortOrder();
                break;
        }
    }

    /**
     * エリア一覧の表示
     *
     * @param int $formId
     * @param int $dateId
     * @return void
     */
    private static function displayAreaList($formId, $dateId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';
        $areas = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d AND date_id = %d ORDER BY sort_order, area_name",
            $formId,
            $dateId
        ));

        // 開催日情報を取得
        $datesTable = $wpdb->prefix . 'marche_dates';
        $dateInfo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$datesTable} WHERE id = %d",
            $dateId
        ));

        echo '<div class="marche-area-list">';
        echo '<div class="marche-list-header">';
        echo '<h2>エリア一覧';
        if ($dateInfo) {
            echo ' - ' . esc_html(date_i18n('Y年n月j日', strtotime($dateInfo->date_value)));
            if ($dateInfo->description) {
                echo ' (' . esc_html($dateInfo->description) . ')';
            }
        }
        echo '</h2>';
        echo '<a href="?page=marche-area-management&action=add&form_id=' . $formId . '&date_id=' . $dateId . '" class="button button-primary">新規追加</a>';
        echo '</div>';

        // Contact Form 7コード表示
        self::displayContactFormCode($formId);

        if (!empty($areas)) {
            echo '<form method="post" action="" id="sort-form">';
            wp_nonce_field('marche_area_action', 'marche_area_nonce');
            echo '<input type="hidden" name="action" value="update_sort_order">';
            echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';
            echo '<input type="hidden" name="date_id" value="' . esc_attr($dateId) . '">';

            echo '<table class="wp-list-table widefat fixed striped marche-sortable-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th width="30">順序</th>';
            echo '<th>エリア名</th>';
            echo '<th>料金</th>';
            echo '<th>定員</th>';
            echo '<th>定員制限</th>';
            echo '<th>状態</th>';
            echo '<th>作成日</th>';
            echo '<th>操作</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody id="sortable-areas">';

            foreach ($areas as $area) {
                $statusClass = $area->is_active ? 'active' : 'inactive';
                $statusText = $area->is_active ? '有効' : '無効';
                $capacityText = $area->capacity > 0 ? $area->capacity . '件' : '無制限';
                $capacityLimitEnabled = isset($area->capacity_limit_enabled) ? $area->capacity_limit_enabled : 1;
                $capacityLimitText = $capacityLimitEnabled ? '有効' : '無効';
                $capacityLimitClass = $capacityLimitEnabled ? 'active' : 'inactive';

                echo '<tr class="' . $statusClass . '" data-area-id="' . $area->id . '">';
                echo '<td class="sort-handle">';
                echo '<input type="hidden" name="area_ids[]" value="' . $area->id . '">';
                echo '<span class="dashicons dashicons-menu"></span>';
                echo '</td>';
                echo '<td><strong>' . esc_html($area->area_name) . '</strong></td>';
                echo '<td>¥' . number_format($area->price) . '</td>';
                echo '<td>' . esc_html($capacityText) . '</td>';
                echo '<td>';
                echo '<span class="marche-status-badge ' . $capacityLimitClass . '">' . $capacityLimitText . '</span>';
                echo '</td>';
                echo '<td>';
                echo '<span class="marche-status-badge ' . $statusClass . '">' . $statusText . '</span>';
                echo '</td>';
                echo '<td>' . esc_html(date_i18n('Y年n月j日', strtotime($area->created_at))) . '</td>';
                echo '<td>';
                echo '<a href="?page=marche-area-management&action=edit&form_id=' . $formId . '&date_id=' . $dateId . '&area_id=' . $area->id . '" class="button">編集</a> ';
                echo '<a href="?page=marche-area-management&action=toggle&form_id=' . $formId . '&date_id=' . $dateId . '&area_id=' . $area->id . '" class="button">' . ($area->is_active ? '無効化' : '有効化') . '</a> ';
                echo '<a href="?page=marche-area-management&action=delete&form_id=' . $formId . '&date_id=' . $dateId . '&area_id=' . $area->id . '" class="button button-secondary marche-delete-confirm" data-confirm-message="このエリアを削除しますか？">削除</a>';
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
            echo '<p>登録されているエリアはありません。</p>';
        }

        echo '</div>';
    }

    /**
     * エリア追加画面の表示
     *
     * @param int $formId
     * @param int $dateId
     * @return void
     */
    private static function displayAddForm($formId, $dateId) {
        global $wpdb;

        // 開催日情報を取得
        $datesTable = $wpdb->prefix . 'marche_dates';
        $dateInfo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$datesTable} WHERE id = %d",
            $dateId
        ));

        echo '<h2>エリア追加';
        if ($dateInfo) {
            echo ' - ' . esc_html(date_i18n('Y年n月j日', strtotime($dateInfo->date_value)));
            if ($dateInfo->description) {
                echo ' (' . esc_html($dateInfo->description) . ')';
            }
        }
        echo '</h2>';

        echo '<form method="post" action="" class="marche-form">';
        wp_nonce_field('marche_area_action', 'marche_area_nonce');
        echo '<input type="hidden" name="action" value="add_area">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';
        echo '<input type="hidden" name="date_id" value="' . esc_attr($dateId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="area_name">エリア名 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="text" name="area_name" id="area_name" class="regular-text" required placeholder="例: Aエリア（屋内）">';
        echo '<p class="description">エリアの名前を入力してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="price">料金 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" name="price" id="price" class="regular-text price-input" required min="0" step="1" placeholder="4000">';
        echo ' <span>円</span>';
        echo '<p class="description">エリアの出店料金を入力してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="capacity">定員</label></th>';
        echo '<td>';
        echo '<input type="number" name="capacity" id="capacity" class="regular-text" min="0" step="1" value="0" placeholder="10">';
        echo ' <span>件（0で無制限）</span>';
        echo '<p class="description">エリアの定員数を入力してください。0を入力すると無制限になります。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="capacity_limit_enabled">定員制限</label></th>';
        echo '<td>';
        echo '<select name="capacity_limit_enabled" id="capacity_limit_enabled">';
        echo '<option value="1">有効</option>';
        echo '<option value="0">無効</option>';
        echo '</select>';
        echo '<p class="description">定員制限機能の有効/無効を選択してください。無効にすると定員に関係なく申込可能になります。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="is_active">状態</label></th>';
        echo '<td>';
        echo '<select name="is_active" id="is_active">';
        echo '<option value="1">有効</option>';
        echo '<option value="0">無効</option>';
        echo '</select>';
        echo '<p class="description">エリアの有効/無効を選択してください。</p>';
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
        echo ' <a href="?page=marche-area-management&form_id=' . $formId . '&date_id=' . $dateId . '" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * エリア編集画面の表示
     *
     * @param int $formId
     * @param int $dateId
     * @param int $areaId
     * @return void
     */
    private static function displayEditForm($formId, $dateId, $areaId) {
        global $wpdb;

        if (!$areaId) {
            echo '<div class="notice notice-error"><p>エリアIDが指定されていません。</p></div>';
            echo '<a href="?page=marche-area-management&form_id=' . $formId . '&date_id=' . $dateId . '" class="button">戻る</a>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_areas';
        $area = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d AND form_id = %d AND date_id = %d",
            $areaId,
            $formId,
            $dateId
        ));

        if (!$area) {
            echo '<div class="notice notice-error"><p>指定されたエリアが見つかりません。</p></div>';
            echo '<a href="?page=marche-area-management&form_id=' . $formId . '&date_id=' . $dateId . '" class="button">戻る</a>';
            return;
        }

        // 開催日情報を取得
        $datesTable = $wpdb->prefix . 'marche_dates';
        $dateInfo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$datesTable} WHERE id = %d",
            $dateId
        ));

        echo '<h2>エリア編集';
        if ($dateInfo) {
            echo ' - ' . esc_html(date_i18n('Y年n月j日', strtotime($dateInfo->date_value)));
            if ($dateInfo->description) {
                echo ' (' . esc_html($dateInfo->description) . ')';
            }
        }
        echo '</h2>';

        echo '<form method="post" action="" class="marche-form">';
        wp_nonce_field('marche_area_action', 'marche_area_nonce');
        echo '<input type="hidden" name="action" value="edit_area">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr($formId) . '">';
        echo '<input type="hidden" name="date_id" value="' . esc_attr($dateId) . '">';
        echo '<input type="hidden" name="area_id" value="' . esc_attr($areaId) . '">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="area_name">エリア名 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="text" name="area_name" id="area_name" class="regular-text" required value="' . esc_attr($area->area_name) . '" placeholder="例: Aエリア（屋内）">';
        echo '<p class="description">エリアの名前を入力してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="price">料金 <span class="required">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" name="price" id="price" class="regular-text price-input" required min="0" step="1" value="' . esc_attr($area->price) . '" placeholder="4000">';
        echo ' <span>円</span>';
        echo '<p class="description">エリアの出店料金を入力してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="capacity">定員</label></th>';
        echo '<td>';
        echo '<input type="number" name="capacity" id="capacity" class="regular-text" min="0" step="1" value="' . esc_attr($area->capacity) . '" placeholder="10">';
        echo ' <span>件（0で無制限）</span>';
        echo '<p class="description">エリアの定員数を入力してください。0を入力すると無制限になります。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="capacity_limit_enabled">定員制限</label></th>';
        echo '<td>';
        echo '<select name="capacity_limit_enabled" id="capacity_limit_enabled">';
        $capacityLimitEnabled = isset($area->capacity_limit_enabled) ? $area->capacity_limit_enabled : 1;
        echo '<option value="1"' . selected($capacityLimitEnabled, 1, false) . '>有効</option>';
        echo '<option value="0"' . selected($capacityLimitEnabled, 0, false) . '>無効</option>';
        echo '</select>';
        echo '<p class="description">定員制限機能の有効/無効を選択してください。無効にすると定員に関係なく申込可能になります。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="is_active">状態</label></th>';
        echo '<td>';
        echo '<select name="is_active" id="is_active">';
        echo '<option value="1"' . selected($area->is_active, 1, false) . '>有効</option>';
        echo '<option value="0"' . selected($area->is_active, 0, false) . '>無効</option>';
        echo '</select>';
        echo '<p class="description">エリアの有効/無効を選択してください。</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="sort_order">並び順</label></th>';
        echo '<td>';
        echo '<input type="number" name="sort_order" id="sort_order" class="small-text" value="' . esc_attr($area->sort_order) . '" min="0">';
        echo '<p class="description">小さい数字ほど上に表示されます。</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" name="submit" class="button-primary" value="更新">';
        echo ' <a href="?page=marche-area-management&form_id=' . $formId . '&date_id=' . $dateId . '" class="button">キャンセル</a>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * エリアの追加処理
     *
     * @return void
     */
    private static function addArea() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $dateId = intval($_POST['date_id']);
        $areaName = sanitize_text_field($_POST['area_name']);
        $price = intval($_POST['price']);
        $capacity = intval($_POST['capacity']);
        $capacityLimitEnabled = intval($_POST['capacity_limit_enabled']);
        $isActive = intval($_POST['is_active']);
        $sortOrder = intval($_POST['sort_order']);

        // バリデーション
        if (!$formId || !$dateId || !$areaName || $price < 0) {
            echo '<div class="notice notice-error"><p>必須項目が正しく入力されていません。</p></div>';
            return;
        }

        // 重複チェック（同じフォーム・開催日内でのエリア名重複）
        $tableName = $wpdb->prefix . 'marche_areas';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d AND date_id = %d AND area_name = %s",
            $formId,
            $dateId,
            $areaName
        ));

        if ($exists) {
            echo '<div class="notice notice-error"><p>この開催日にはすでに同じエリア名が登録されています。</p></div>';
            return;
        }

        // データベースに挿入
        $result = $wpdb->insert(
            $tableName,
            array(
                'form_id' => $formId,
                'date_id' => $dateId,
                'area_name' => $areaName,
                'price' => $price,
                'capacity' => $capacity,
                'capacity_limit_enabled' => $capacityLimitEnabled,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ),
            array('%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d')
        );

        if ($result) {
            echo '<div class="notice notice-success"><p>エリアが正常に追加されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>エリアの追加に失敗しました。</p></div>';
        }
    }

    /**
     * エリアの編集処理
     *
     * @return void
     */
    private static function editArea() {
        global $wpdb;

        $formId = intval($_POST['form_id']);
        $dateId = intval($_POST['date_id']);
        $areaId = intval($_POST['area_id']);
        $areaName = sanitize_text_field($_POST['area_name']);
        $price = intval($_POST['price']);
        $capacity = intval($_POST['capacity']);
        $capacityLimitEnabled = intval($_POST['capacity_limit_enabled']);
        $isActive = intval($_POST['is_active']);
        $sortOrder = intval($_POST['sort_order']);

        // バリデーション
        if (!$formId || !$dateId || !$areaId || !$areaName || $price < 0) {
            echo '<div class="notice notice-error"><p>必須項目が正しく入力されていません。</p></div>';
            return;
        }

        // 重複チェック（同じフォーム・開催日内で自分以外のエリア名重複）
        $tableName = $wpdb->prefix . 'marche_areas';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d AND date_id = %d AND area_name = %s AND id != %d",
            $formId,
            $dateId,
            $areaName,
            $areaId
        ));

        if ($exists) {
            echo '<div class="notice notice-error"><p>この開催日にはすでに同じエリア名が登録されています。</p></div>';
            return;
        }

        // データベースを更新
        $result = $wpdb->update(
            $tableName,
            array(
                'area_name' => $areaName,
                'price' => $price,
                'capacity' => $capacity,
                'capacity_limit_enabled' => $capacityLimitEnabled,
                'is_active' => $isActive,
                'sort_order' => $sortOrder
            ),
            array('id' => $areaId, 'form_id' => $formId, 'date_id' => $dateId),
            array('%s', '%d', '%d', '%d', '%d', '%d'),
            array('%d', '%d', '%d')
        );

        if ($result !== false) {
            echo '<div class="notice notice-success"><p>エリアが正常に更新されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>エリアの更新に失敗しました。</p></div>';
        }
    }

    /**
     * エリアの削除処理
     *
     * @param int $formId
     * @param int $dateId
     * @param int $areaId
     * @return void
     */
    private static function deleteArea($formId, $dateId, $areaId) {
        global $wpdb;

        if (!$formId || !$dateId || !$areaId) {
            echo '<div class="notice notice-error"><p>エリアIDが指定されていません。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_areas';
        $result = $wpdb->delete(
            $tableName,
            array('id' => $areaId, 'form_id' => $formId, 'date_id' => $dateId),
            array('%d', '%d', '%d')
        );

        if ($result) {
            echo '<div class="notice notice-success"><p>エリアが正常に削除されました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>エリアの削除に失敗しました。</p></div>';
        }
    }

    /**
     * エリアの有効/無効切り替え
     *
     * @param int $formId
     * @param int $dateId
     * @param int $areaId
     * @return void
     */
    private static function toggleAreaStatus($formId, $dateId, $areaId) {
        global $wpdb;

        if (!$formId || !$dateId || !$areaId) {
            echo '<div class="notice notice-error"><p>エリアIDが指定されていません。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_areas';

        // 現在の状態を取得
        $currentStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$tableName} WHERE id = %d AND form_id = %d AND date_id = %d",
            $areaId,
            $formId,
            $dateId
        ));

        if ($currentStatus === null) {
            echo '<div class="notice notice-error"><p>指定されたエリアが見つかりません。</p></div>';
            return;
        }

        // 状態を切り替え
        $newStatus = $currentStatus ? 0 : 1;
        $result = $wpdb->update(
            $tableName,
            array('is_active' => $newStatus),
            array('id' => $areaId, 'form_id' => $formId, 'date_id' => $dateId),
            array('%d'),
            array('%d', '%d', '%d')
        );

        if ($result !== false) {
            $statusText = $newStatus ? '有効' : '無効';
            echo '<div class="notice notice-success"><p>エリアを' . $statusText . 'に変更しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>エリアの状態変更に失敗しました。</p></div>';
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
        $dateId = intval($_POST['date_id']);
        $areaIds = isset($_POST['area_ids']) ? array_map('intval', $_POST['area_ids']) : array();

        if (!$formId || !$dateId || empty($areaIds)) {
            echo '<div class="notice notice-error"><p>並び順の更新に必要なデータが不足しています。</p></div>';
            return;
        }

        $tableName = $wpdb->prefix . 'marche_areas';
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($areaIds as $index => $areaId) {
                $wpdb->update(
                    $tableName,
                    array('sort_order' => $index),
                    array('id' => $areaId, 'form_id' => $formId, 'date_id' => $dateId),
                    array('%d'),
                    array('%d', '%d', '%d')
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
     * エリアの定員状況を取得
     *
     * @param int $formId
     * @param int $dateId
     * @param int $areaId
     * @return array
     */
    public static function getAreaCapacityStatus($formId, $dateId, $areaId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';
        $area = $wpdb->get_row($wpdb->prepare(
            "SELECT area_name, capacity, capacity_limit_enabled FROM {$tableName} WHERE id = %d AND form_id = %d AND date_id = %d",
            $areaId,
            $formId,
            $dateId
        ));

        if (!$area) {
            return array(
                'area_name' => '',
                'capacity' => 0,
                'capacity_limit_enabled' => 1,
                'used' => 0,
                'available' => 0,
                'is_full' => false
            );
        }

        // 実際の申込数を取得
        $applicationsTable = $wpdb->prefix . 'marche_applications';
        $used = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$applicationsTable} WHERE form_id = %d AND date_id = %d AND area_name = %s",
            $formId,
            $dateId,
            $area->area_name
        ));

        $used = intval($used); // 確実に数値にする

        // 定員制限が無効の場合は常に利用可能
        if (!$area->capacity_limit_enabled) {
            return array(
                'area_name' => $area->area_name,
                'capacity' => $area->capacity,
                'capacity_limit_enabled' => $area->capacity_limit_enabled,
                'used' => $used,
                'available' => 999999,
                'is_full' => false
            );
        }

        $available = $area->capacity > 0 ? max(0, $area->capacity - $used) : 999999;
        $isFull = $area->capacity > 0 && $used >= $area->capacity;

        return array(
            'area_name' => $area->area_name,
            'capacity' => $area->capacity,
            'capacity_limit_enabled' => $area->capacity_limit_enabled,
            'used' => $used,
            'available' => $available,
            'is_full' => $isFull
        );
    }

    /**
     * フォーム・開催日の全エリア情報を取得
     *
     * @param int $formId
     * @param int $dateId
     * @return array
     */
    public static function getFormAreas($formId, $dateId = null) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';

        if ($dateId) {
            // 特定の開催日のエリアを取得
            $areas = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tableName} WHERE form_id = %d AND date_id = %d AND is_active = 1 ORDER BY sort_order, area_name",
                $formId,
                $dateId
            ));
        } else {
            // 全ての開催日のエリアを取得（後方互換性のため）
            $areas = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tableName} WHERE form_id = %d AND is_active = 1 ORDER BY sort_order, area_name",
                $formId
            ));
        }

        $result = array();
        foreach ($areas as $area) {
            $capacityStatus = self::getAreaCapacityStatus($formId, $area->date_id, $area->id);
            $result[] = array(
                'id' => $area->id,
                'date_id' => $area->date_id,
                'area_name' => $area->area_name,
                'price' => $area->price,
                'capacity' => $area->capacity,
                'capacity_limit_enabled' => $area->capacity_limit_enabled,
                'capacity_status' => $capacityStatus,
                'sort_order' => $area->sort_order
            );
        }

        return $result;
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
        echo '<code>[select* booth-location data:booth-location first_as_label "エリアを選択してください"]</code>';
        echo '<button type="button" class="button marche-copy-button" onclick="navigator.clipboard.writeText(\'[select* booth-location data:booth-location first_as_label &quot;エリアを選択してください&quot;]\')">コピー</button>';
        echo '</div>';
        echo '<p class="description">※ エリアの選択肢は自動的に生成されます。有効なエリアのみが表示され、定員制限が有効な場合は満員のエリアは除外されます。<br>';
        echo '※ <strong>data:booth-location</strong> 属性は必須です。これにより動的選択肢が生成されます。<br>';
        echo '※ <strong>first_as_label</strong> オプションにより、最初の選択肢がプレースホルダーとして機能します。</p>';
        echo '</div>';

        // CSSは assets/css/admin.css に統合済み
    }
}
