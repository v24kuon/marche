<?php
/**
 * データアクセスクラス
 *
 * @package MarcheManagement
 * @subpackage Includes
 * @since 1.0.0
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * データベースアクセスを担当するクラス
 *
 * @class MarcheDataAccess
 * @description フォーム、エリア、レンタル用品、開催日の情報取得を提供
 */
class MarcheDataAccess {

    /**
     * フォーム情報の取得
     *
     * @param int $formId フォームID
     * @return array|null フォーム情報
     */
    public function getFormInfo($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_forms';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d",
            $formId
        ));

        return $result ? array(
            'id' => $result->id,
            'form_id' => $result->form_id,
            'form_name' => $result->form_name,
            'form_type' => isset($result->form_type) ? $result->form_type : 'マルシェ',
            'payment_method' => $result->payment_method
        ) : null;
    }

    /**
     * 開催日情報の取得
     *
     * @param int $dateId
     * @return array|null
     */
    public function getDateInfo($dateId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_dates';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d AND is_active = 1",
            $dateId
        ));

        return $result ? array(
            'id' => $result->id,
            'date_value' => $result->date_value,
            'description' => $result->description,
            'form_id' => $result->form_id
        ) : null;
    }

    /**
     * 開催日情報の取得（日付ラベルで検索）
     *
     * @param int $formId
     * @param string $dateLabel
     * @return array|null
     */
    public function getDateInfoByLabel($formId, $dateLabel) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_dates';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d AND is_active = 1",
            $formId
        ));

        foreach ($results as $result) {
            $dateFormatted = date_i18n('Y年n月j日 (D)', strtotime($result->date_value));
            $label = $result->description ? $dateFormatted . ' - ' . $result->description : $dateFormatted;

            if ($label === $dateLabel) {
                return array(
                    'id' => $result->id,
                    'date_value' => $result->date_value,
                    'description' => $result->description,
                    'form_id' => $result->form_id
                );
            }
        }

        return null;
    }

    /**
     * エリア情報の取得
     *
     * @param int $areaId
     * @return array|null
     */
    public function getAreaInfo($areaId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d AND is_active = 1",
            $areaId
        ));

        return $result ? array(
            'id' => $result->id,
            'area_name' => $result->area_name,
            'price' => $result->price,
            'capacity' => $result->capacity,
            'capacity_limit_enabled' => isset($result->capacity_limit_enabled) ? $result->capacity_limit_enabled : 1,
            'form_id' => $result->form_id,
            'date_id' => $result->date_id
        ) : null;
    }

    /**
     * エリア情報の取得（エリア名で検索）
     *
     * @param int $formId
     * @param string $areaName
     * @param int $dateId 開催日ID（オプション）
     * @return array|null
     */
    public function getAreaInfoByName($formId, $areaName, $dateId = null) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';

        if ($dateId) {
            // 特定の開催日のエリアを検索
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tableName} WHERE area_name = %s AND form_id = %d AND date_id = %d AND is_active = 1",
                $areaName,
                $formId,
                $dateId
            ));
        } else {
            // 開催日指定なしで検索（後方互換性のため）
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tableName} WHERE area_name = %s AND form_id = %d AND is_active = 1",
                $areaName,
                $formId
            ));
        }

        return $result ? array(
            'id' => $result->id,
            'area_name' => $result->area_name,
            'price' => $result->price,
            'capacity' => $result->capacity,
            'capacity_limit_enabled' => isset($result->capacity_limit_enabled) ? $result->capacity_limit_enabled : 1,
            'form_id' => $result->form_id,
            'date_id' => $result->date_id
        ) : null;
    }

    /**
     * レンタル用品情報の取得
     *
     * @param int $rentalId
     * @return array|null
     */
    public function getRentalInfo($rentalId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d AND is_active = 1",
            $rentalId
        ));

        return $result ? array(
            'id' => $result->id,
            'item_name' => $result->item_name,
            'field_name' => $result->field_name,
            'price' => $result->price,
            'unit' => $result->unit,
            'description' => $result->description,
            'min_quantity' => $result->min_quantity,
            'max_quantity' => $result->max_quantity,
            'form_id' => $result->form_id
        ) : null;
    }

    /**
     * レンタル用品情報の取得（アイテム名で検索）
     *
     * @param int $formId
     * @param string $itemName
     * @return array|null
     */
    public function getRentalInfoByName($formId, $itemName) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE item_name = %s AND form_id = %d AND is_active = 1",
            $itemName,
            $formId
        ));

        return $result ? array(
            'id' => $result->id,
            'item_name' => $result->item_name,
            'field_name' => $result->field_name,
            'price' => $result->price,
            'unit' => $result->unit,
            'description' => $result->description,
            'min_quantity' => $result->min_quantity,
            'max_quantity' => $result->max_quantity,
            'form_id' => $result->form_id
        ) : null;
    }

    /**
     * 開催日選択肢の取得
     *
     * @param int $formId
     * @return array
     */
    public function getDateOptions($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_dates';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, date_value, description FROM {$tableName} WHERE form_id = %d AND is_active = 1 AND date_value >= CURDATE() ORDER BY sort_order, date_value",
            $formId
        ));

        $options = array();

        foreach ($results as $row) {
            $dateFormatted = date_i18n('Y年n月j日 (D)', strtotime($row->date_value));
            $label = $row->description ? $dateFormatted . ' - ' . $row->description : $dateFormatted;

            // 値には表示ラベルのみを設定
            $options[$label] = $label;
        }

        return $options;
    }

    /**
     * エリア選択肢の取得
     *
     * @param int $formId
     * @param int $dateId 開催日ID（オプション）
     * @param bool $includeInactive 無効エリアも含めるかどうか（デフォルト: false）
     * @return array
     */
    public function getAreaOptions($formId, $dateId = null, $includeInactive = false) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';

        // 管理画面用とフロントエンド用の取得方法を分離
        $activeCondition = $includeInactive ? '' : 'AND is_active = 1';

        if ($dateId) {
            // 特定の開催日のエリアを取得
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT id, area_name, price, capacity, capacity_limit_enabled, date_id, is_active FROM {$tableName} WHERE form_id = %d AND date_id = %d {$activeCondition} ORDER BY sort_order",
                $formId,
                $dateId
            ));
        } else {
            // 全ての開催日のエリアを取得（後方互換性のため）
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT id, area_name, price, capacity, capacity_limit_enabled, date_id, is_active FROM {$tableName} WHERE form_id = %d {$activeCondition} ORDER BY sort_order",
                $formId
            ));
        }

        $options = array();

        foreach ($results as $row) {
            // フロントエンド（Contact Form 7）では無効エリアを除外
            if (!$includeInactive && !$row->is_active) {
                continue;
            }

            // 定員制限が有効な場合のみ定員状況を確認
            $capacityLimitEnabled = isset($row->capacity_limit_enabled) ? $row->capacity_limit_enabled : 1;

            if ($capacityLimitEnabled && $row->capacity > 0) {
                // 定員状況の確認
                require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/area-management.php';
                $capacityStatus = MarcheAreaManagement::getAreaCapacityStatus($formId, $row->date_id, $row->id);

                $remainingCapacity = $row->capacity - $capacityStatus['used'];

                if ($remainingCapacity <= 0) {
                    // 定員オーバーの場合は選択肢に含めない
                    continue;
                }
            }

            // 表示も値もエリア名のみを設定
            $options[$row->area_name] = $row->area_name;
        }

        return $options;
    }

    /**
     * エリア料金設定の取得
     *
     * @param int $formId フォームID
     * @param int $dateId 開催日ID（オプション）
     * @return array エリア料金設定
     */
    public function getAreaPricingSettings($formId, $dateId = null) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';

        // デバッグ: テーブルの存在確認
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$tableName}'");

        // デバッグ: 全件数の確認
        $totalCount = $wpdb->get_var("SELECT COUNT(*) FROM {$tableName}");

        // デバッグ: 指定フォームIDの件数確認
        $formCount = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tableName} WHERE form_id = %d", $formId));

        // クエリの構築
        if ($dateId) {
            $sql = "SELECT id, area_name, price, capacity, capacity_limit_enabled, is_active, sort_order, date_id
                    FROM {$tableName}
                    WHERE form_id = %d AND date_id = %d
                    ORDER BY sort_order, area_name";
            $queryParams = array($formId, $dateId);
        } else {
            $sql = "SELECT id, area_name, price, capacity, capacity_limit_enabled, is_active, sort_order, date_id
                    FROM {$tableName}
                    WHERE form_id = %d
                    ORDER BY sort_order, area_name";
            $queryParams = array($formId);
        }

        // デバッグ情報を一時的に保存
        $debugInfo = array(
            'table_name' => $tableName,
            'table_exists' => $tableExists ? 'Yes' : 'No',
            'total_records' => $totalCount,
            'form_id' => $formId,
            'date_id' => $dateId,
            'form_records' => $formCount,
            'sql_query' => $wpdb->prepare($sql, ...$queryParams)
        );

        $areas = $wpdb->get_results($wpdb->prepare($sql, ...$queryParams));

        // デバッグ: 生のクエリ結果を追加
        $debugInfo['raw_query_result'] = $areas;
        $debugInfo['wpdb_last_error'] = $wpdb->last_error;

        // グローバル変数にデバッグ情報を保存（テスト画面で表示するため）
        if (!isset($GLOBALS['marche_debug_info'])) {
            $GLOBALS['marche_debug_info'] = array();
        }
        $GLOBALS['marche_debug_info']['area_debug'] = $debugInfo;

        $areaSettings = array();
        foreach ($areas as $area) {
            $areaSettings[] = array(
                'id' => intval($area->id),
                'name' => $area->area_name,
                'price' => intval($area->price),
                'capacity' => intval($area->capacity),
                'capacity_limit_enabled' => boolval($area->capacity_limit_enabled),
                'is_active' => boolval($area->is_active),
                'sort_order' => intval($area->sort_order),
                'date_id' => intval($area->date_id),
                'description' => '' // 一時的に空文字列を設定
            );
        }

        return $areaSettings;
    }

    /**
     * レンタル用品料金設定の取得
     *
     * @param int $formId フォームID
     * @return array レンタル用品料金設定
     */
    public function getRentalPricingSettings($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';
        $rentals = $wpdb->get_results($wpdb->prepare(
            "SELECT id, item_name, field_name, price, unit, min_quantity, max_quantity, is_active, sort_order, description
             FROM {$tableName}
             WHERE form_id = %d
             ORDER BY sort_order, item_name",
            $formId
        ));

        $rentalSettings = array();
        foreach ($rentals as $rental) {
            $rentalSettings[] = array(
                'id' => intval($rental->id),
                'item_name' => $rental->item_name,
                'field_name' => $rental->field_name,
                'price' => intval($rental->price),
                'unit' => $rental->unit,
                'min_quantity' => intval($rental->min_quantity),
                'max_quantity' => intval($rental->max_quantity),
                'is_active' => boolval($rental->is_active),
                'sort_order' => intval($rental->sort_order),
                'description' => $rental->description
            );
        }

        return $rentalSettings;
    }
}
