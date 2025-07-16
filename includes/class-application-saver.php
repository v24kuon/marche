<?php
/**
 * 申し込みデータ保存クラス
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
 * Contact Form 7の送信データを保存するクラス
 *
 * @class MarcheApplicationSaver
 * @description Contact Form 7のwpcf7_mail_sentフックを使用してデータを保存
 */
class MarcheApplicationSaver {

    /**
     * データアクセスクラスのインスタンス
     *
     * @var MarcheDataAccess
     */
    private $dataAccess;

    /**
     * コンストラクタ
     *
     * @param MarcheDataAccess $dataAccess データアクセスクラス
     */
    public function __construct($dataAccess) {
        $this->dataAccess = $dataAccess;
        $this->initHooks();
    }

    /**
     * WordPressフックの初期化
     *
     * @return void
     */
    private function initHooks() {
        // Contact Form 7送信完了時のフックのみ使用（重複実行を防ぐため）
        add_action('wpcf7_mail_sent', array($this, 'saveApplicationData'), 20);
    }

    /**
     * 申し込みデータの保存
     *
     * @param WPCF7_ContactForm $contact_form Contact Form 7のフォームオブジェクト
     * @return void
     */
    public function saveApplicationData($contact_form) {
        static $already_saved = array();

        try {
            // フォームIDの取得
            $form_id = $contact_form->id();

            // 重複実行防止
            if (isset($already_saved[$form_id])) {
                return;
            }

            // 送信データの取得
            $submission = WPCF7_Submission::get_instance();
            if (!$submission) {
                return;
            }

            $posted_data = $submission->get_posted_data();
            if (empty($posted_data)) {
                return;
            }

            // バックアップとして $_POST も確認
            $backup_data = $_POST;
            if (empty($posted_data['date']) && !empty($backup_data['date'])) {
                $posted_data['date'] = $backup_data['date'];
            }

            // プラグインで管理されているフォームかチェック
            $form_info = $this->dataAccess->getFormInfo($form_id);
            if (!$form_info) {
                return;
            }

            // 必須項目の抽出
            $required_fields = $this->extractRequiredFields($form_id, $posted_data);
            if (!$required_fields) {
                return;
            }

            // レンタル用品データの抽出
            $rental_items = $this->extractRentalItems($form_id, $posted_data);

            // データベースに保存
            $this->saveToDatabase($form_id, $required_fields, $posted_data, $rental_items);

            // 重複実行防止フラグを設定
            $already_saved[$form_id] = true;

        } catch (Exception $e) {
        }
    }

    /**
     * 代替の申し込みデータ保存（送信前フック）
     *
     * @param WPCF7_ContactForm $contact_form Contact Form 7のフォームオブジェクト
     * @return void
     */
    public function saveApplicationDataAlternative($contact_form) {
        static $already_saved = array();

        try {
            // フォームIDの取得
            $form_id = $contact_form->id();

            // 重複実行防止
            if (isset($already_saved[$form_id])) {
                return;
            }

            // プラグインで管理されているフォームかチェック
            $form_info = $this->dataAccess->getFormInfo($form_id);
            if (!$form_info) {
                return;
            }

            // $_POSTから直接データを取得（この時点では確実に存在する）
            $posted_data = $_POST;
            if (empty($posted_data)) {
                return;
            }

            // 必須項目の抽出（$_POSTデータを使用）
            $required_fields = $this->extractRequiredFieldsFromPost($form_id, $posted_data);
            if (!$required_fields) {
                return;
            }

            // レンタル用品データの抽出
            $rental_items = $this->extractRentalItems($form_id, $posted_data);

            // データベースに保存
            $this->saveToDatabase($form_id, $required_fields, $posted_data, $rental_items);

            // 重複実行防止フラグを設定
            $already_saved[$form_id] = true;

        } catch (Exception $e) {
        }
    }

    /**
     * 必須項目の抽出
     *
     * @param int $form_id フォームID
     * @param array $posted_data 送信データ
     * @return array|false 必須項目データまたはfalse
     */
    private function extractRequiredFields($form_id, $posted_data) {

        // 開催日の取得とdate_idの変換
        $date_id = null;
        $date_value = null;

        // Flamingo スタイルの堅牢なデータ取得
        if (isset($posted_data['date']) && !empty($posted_data['date'])) {
            // 配列の場合は最初の要素を取得（Flamingo 方式）
            $date_value = is_array($posted_data['date']) ? $posted_data['date'][0] : $posted_data['date'];
        }

        // Contact Form 7 の submission データからの取得（第2の方法）
        if (empty($date_value)) {
            $submission = WPCF7_Submission::get_instance();
            if ($submission) {
                $form_fields = $submission->get_posted_data();
                if (isset($form_fields['date']) && !empty($form_fields['date'])) {
                    $date_value = is_array($form_fields['date']) ? $form_fields['date'][0] : $form_fields['date'];
                }
            }
        }

        // $_POST からの直接取得（第3の方法）
        if (empty($date_value) && isset($_POST['date']) && !empty($_POST['date'])) {
            $date_value = is_array($_POST['date']) ? $_POST['date'][0] : $_POST['date'];
        }

        if (!empty($date_value)) {
            $date_label = sanitize_text_field($date_value);
            $date_info = $this->dataAccess->getDateInfoByLabel($form_id, $date_label);
            if ($date_info) {
                $date_id = $date_info['id'];
            } else {

                // デバッグ: 利用可能な日付オプションをログ出力
                $available_dates = $this->dataAccess->getDateOptions($form_id);
            }
        } else {
        }

        if (!$date_id) {
            return false;
        }

        // エリア名の取得（堅牢化）
        $area_name = null;
        if (isset($posted_data['booth-location']) && !empty($posted_data['booth-location'])) {
            $area_name = is_array($posted_data['booth-location']) ?
                sanitize_text_field($posted_data['booth-location'][0]) :
                sanitize_text_field($posted_data['booth-location']);
        }

        // チラシ枚数の取得（堅牢化）
        $flyer_number = 0;
        if (isset($posted_data['flyer-number'])) {
            $flyer_value = is_array($posted_data['flyer-number']) ? $posted_data['flyer-number'][0] : $posted_data['flyer-number'];
            if (is_numeric($flyer_value)) {
                $flyer_number = intval($flyer_value);
            }
        }

        // 車両高さの取得（堅牢化）
        $car_height = null;
        if (isset($posted_data['booth-car-height']) && !empty($posted_data['booth-car-height'])) {
            $car_height = is_array($posted_data['booth-car-height']) ?
                sanitize_text_field($posted_data['booth-car-height'][0]) :
                sanitize_text_field($posted_data['booth-car-height']);
        }

        return array(
            'date_id' => $date_id,
            'area_name' => $area_name,
            'flyer_number' => $flyer_number,
            'car_height' => $car_height
        );
    }

    /**
     * $_POSTデータから必須項目を抽出
     *
     * @param int $form_id フォームID
     * @param array $posted_data POSTデータ
     * @return array|false 必須項目データまたはfalse
     */
    private function extractRequiredFieldsFromPost($form_id, $posted_data) {

        // 開催日の取得とdate_idの変換（堅牢化）
        $date_id = null;
        $date_value = null;

        if (isset($posted_data['date']) && !empty($posted_data['date'])) {
            // 配列の場合は最初の要素を取得（Flamingo 方式）
            $date_value = is_array($posted_data['date']) ? $posted_data['date'][0] : $posted_data['date'];
        }

        if (!empty($date_value)) {
            $date_label = sanitize_text_field($date_value);
            $date_info = $this->dataAccess->getDateInfoByLabel($form_id, $date_label);
            if ($date_info) {
                $date_id = $date_info['id'];
            } else {

                // デバッグ: 利用可能な日付オプションをログ出力
                $available_dates = $this->dataAccess->getDateOptions($form_id);
            }
        } else {
        }

        if (!$date_id) {
            return false;
        }

        // エリア名の取得（堅牢化）
        $area_name = null;
        if (isset($posted_data['booth-location']) && !empty($posted_data['booth-location'])) {
            $area_name = is_array($posted_data['booth-location']) ?
                sanitize_text_field($posted_data['booth-location'][0]) :
                sanitize_text_field($posted_data['booth-location']);
        }

        // チラシ枚数の取得（堅牢化）
        $flyer_number = 0;
        if (isset($posted_data['flyer-number'])) {
            $flyer_value = is_array($posted_data['flyer-number']) ? $posted_data['flyer-number'][0] : $posted_data['flyer-number'];
            if (is_numeric($flyer_value)) {
                $flyer_number = intval($flyer_value);
            }
        }

        // 車両高さの取得（堅牢化）
        $car_height = null;
        if (isset($posted_data['booth-car-height']) && !empty($posted_data['booth-car-height'])) {
            $car_height = is_array($posted_data['booth-car-height']) ?
                sanitize_text_field($posted_data['booth-car-height'][0]) :
                sanitize_text_field($posted_data['booth-car-height']);
        }

        return array(
            'date_id' => $date_id,
            'area_name' => $area_name,
            'flyer_number' => $flyer_number,
            'car_height' => $car_height
        );
    }

    /**
     * レンタル用品データの抽出
     *
     * @param int $form_id フォームID
     * @param array $posted_data 送信データ
     * @return array レンタル用品データ
     */
    private function extractRentalItems($form_id, $posted_data) {
        global $wpdb;

        $rental_items = array();
        $rental_table = $wpdb->prefix . 'marche_rental_items';

        // フォームで有効なレンタル用品を取得
        $rentals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$rental_table} WHERE form_id = %d AND is_active = 1",
            $form_id
        ));

        foreach ($rentals as $rental) {
            $field_name = 'rental-' . $rental->field_name;
            if (isset($posted_data[$field_name])) {
                // Flamingo スタイル: 配列の場合は最初の要素を取得
                $quantity_value = is_array($posted_data[$field_name]) ? $posted_data[$field_name][0] : $posted_data[$field_name];

                if (is_numeric($quantity_value)) {
                    $quantity = intval($quantity_value);
                    if ($quantity > 0) {
                        $rental_items[$field_name] = array(
                            'item_name' => $rental->item_name,
                            'quantity' => $quantity,
                            'price' => $rental->price,
                            'unit' => $rental->unit
                        );
                    }
                }
            }
        }

        return $rental_items;
    }

    /**
     * データベースに保存
     *
     * @param int $form_id フォームID
     * @param array $required_fields 必須項目データ
     * @param array $posted_data 送信データ
     * @param array $rental_items レンタル用品データ
     * @return void
     */
    private function saveToDatabase($form_id, $required_fields, $posted_data, $rental_items) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_applications';

                // 重複チェック: 非常に短時間（10秒以内）の完全に同じデータの送信のみを重複として扱う
        $application_data_hash = md5(wp_json_encode($posted_data, JSON_UNESCAPED_UNICODE));

        $existing_record = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name}
             WHERE form_id = %d AND date_id = %d AND area_name = %s
             AND MD5(application_data) = %s
             AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)",
            $form_id,
            $required_fields['date_id'],
            $required_fields['area_name'],
            $application_data_hash
        ));

        if ($existing_record) {
            // 完全に同じデータの10秒以内の重複送信の場合は保存をスキップ
            return $existing_record;
        }

        // Flamingo スタイルの安全なデータ準備
        $data = array(
            'form_id' => (int) $form_id,
            'date_id' => (int) $required_fields['date_id'],
            'application_data' => wp_json_encode($posted_data, JSON_UNESCAPED_UNICODE),
            'area_name' => sanitize_text_field($required_fields['area_name']),
            'flyer_number' => (int) $required_fields['flyer_number'],
            'car_height' => sanitize_text_field($required_fields['car_height']),
            'rental_items' => wp_json_encode($rental_items, JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql', 1), // UTC時刻で保存
            'updated_at' => current_time('mysql', 1)
        );

        // データ型の指定（Flamingo スタイル）
        $format = array(
            '%d', // form_id
            '%d', // date_id
            '%s', // application_data
            '%s', // area_name
            '%d', // flyer_number
            '%s', // car_height
            '%s', // rental_items
            '%s', // created_at
            '%s'  // updated_at
        );

        // データベースに挿入（エラーハンドリング強化）
        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
            $error_message = sprintf(
                'Database insert failed for form ID %d: %s',
                $form_id,
                $wpdb->last_error
            );
            throw new Exception($error_message);
        }

        $application_id = $wpdb->insert_id;
        //     '[Marche Plugin] Application saved successfully: ID %d for form %d',
        //     $application_id,
        //     $form_id
        // ));

        return $application_id;
    }

    /**
     * 申し込みデータの取得（ダッシュボード用）
     *
     * @param int $form_id フォームID
     * @param int $date_id 開催日ID
     * @return array 申し込みデータ一覧
     */
    public function getApplications($form_id, $date_id = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_applications';

        $where_clause = "WHERE form_id = %d";
        $params = array($form_id);

        if ($date_id) {
            $where_clause .= " AND date_id = %d";
            $params[] = $date_id;
        }

        $query = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC";

        return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
    }

    /**
     * 統計データの取得（ダッシュボード用）
     *
     * @param int $form_id フォームID
     * @param int $date_id 開催日ID
     * @return array 統計データ
     */
    public function getStatistics($form_id, $date_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_applications';

        // エリア別統計
        $area_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT area_name, COUNT(*) as count FROM {$table_name}
             WHERE form_id = %d AND date_id = %d AND area_name IS NOT NULL
             GROUP BY area_name ORDER BY count DESC",
            $form_id, $date_id
        ), ARRAY_A);

        // チラシ枚数合計
        $flyer_total = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(flyer_number) FROM {$table_name}
             WHERE form_id = %d AND date_id = %d",
            $form_id, $date_id
        ));

        // 車両タイプ別統計
        $car_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN CAST(car_height AS UNSIGNED) <= 1550 THEN 1 ELSE 0 END) as low_roof,
                SUM(CASE WHEN CAST(car_height AS UNSIGNED) > 1550 THEN 1 ELSE 0 END) as high_roof
             FROM {$table_name}
             WHERE form_id = %d AND date_id = %d AND car_height IS NOT NULL AND car_height != ''",
            $form_id, $date_id
        ), ARRAY_A);

        // レンタル用品別統計
        $rental_stats = $this->getRentalStatistics($form_id, $date_id);

        return array(
            'area_stats' => $area_stats,
            'flyer_total' => intval($flyer_total),
            'car_stats' => $car_stats[0] ?? array('low_roof' => 0, 'high_roof' => 0),
            'rental_stats' => $rental_stats
        );
    }

    /**
     * レンタル用品別統計の取得
     *
     * @param int $form_id フォームID
     * @param int $date_id 開催日ID
     * @return array レンタル用品統計
     */
    private function getRentalStatistics($form_id, $date_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_applications';

        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT rental_items FROM {$table_name}
             WHERE form_id = %d AND date_id = %d AND rental_items IS NOT NULL AND rental_items != ''",
            $form_id, $date_id
        ), ARRAY_A);

        $rental_totals = array();

        foreach ($applications as $app) {
            $rental_items = json_decode($app['rental_items'], true);
            if (is_array($rental_items)) {
                foreach ($rental_items as $field_name => $item_data) {
                    if (isset($item_data['item_name']) && isset($item_data['quantity'])) {
                        $item_name = $item_data['item_name'];
                        $quantity = intval($item_data['quantity']);

                        if (!isset($rental_totals[$item_name])) {
                            $rental_totals[$item_name] = 0;
                        }
                        $rental_totals[$item_name] += $quantity;
                    }
                }
            }
        }

        // 配列を統計用の形式に変換
        $rental_stats = array();
        foreach ($rental_totals as $item_name => $total) {
            $rental_stats[] = array(
                'item_name' => $item_name,
                'total' => $total
            );
        }

        return $rental_stats;
    }
}
