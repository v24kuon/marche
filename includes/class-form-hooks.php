<?php
/**
 * フォームフックハンドラクラス
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
 * Contact Form 7フック処理を担当するクラス
 *
 * @class MarcheFormHooks
 * @description 動的選択肢生成、バリデーション、フィールド設定を提供
 */
class MarcheFormHooks {

    /**
     * データアクセスクラスのインスタンス
     *
     * @var MarcheDataAccess
     */
    private $dataAccess;

    /**
     * 料金計算クラスのインスタンス
     *
     * @var MarchePriceCalculator
     */
    private $priceCalculator;

    /**
     * コンストラクタ
     *
     * @param MarcheDataAccess $dataAccess データアクセスクラス
     * @param MarchePriceCalculator $priceCalculator 料金計算クラス
     */
    public function __construct($dataAccess, $priceCalculator = null) {
        $this->dataAccess = $dataAccess;
        $this->priceCalculator = $priceCalculator;
        $this->initHooks();
    }

    /**
     * WordPressフックの初期化
     *
     * @return void
     */
    private function initHooks() {
        // Contact Form 7フック連携
        add_filter('wpcf7_form_tag_data_option', array($this, 'addDynamicOptions'), 10, 3);
        add_filter('wpcf7_form_tag', array($this, 'addRentalQuantityFields'), 10, 2);
        add_filter('wpcf7_validate_number', array($this, 'validateRentalQuantity'), 20, 2);
        add_filter('wpcf7_validate_number*', array($this, 'validateRentalQuantity'), 20, 2);
        add_filter('wpcf7_validate_select', array($this, 'validateAreaCapacity'), 20, 2);
        add_filter('wpcf7_validate_select*', array($this, 'validateAreaCapacity'), 20, 2);

        // メールタグ処理
        add_filter('wpcf7_mail_components', array($this, 'replaceTotalAmountInMail'), 10, 3);

        // Stripe連携フック
        add_filter('wpcf7_stripe_payment_intent_parameters', array($this, 'setStripePaymentParameters'), 10, 1);
    }

    /**
     * 動的選択肢の追加（Contact Form 7フック）
     *
     * @param array $n
     * @param array $options
     * @param array $args
     * @return array
     */
    public function addDynamicOptions($n, $options, $args) {
        // フォームIDの取得
        $form = wpcf7_get_current_contact_form();
        if (!$form) {
            return $n;
        }

        $formId = $form->id();

        // data:date が指定されている場合
        if (in_array('date', $options)) {
            return $this->dataAccess->getDateOptions($formId);
        }

        // data:booth-location が指定されている場合
        if (in_array('booth-location', $options)) {
            // 初期表示では最初の開催日のエリアを表示
            $dateOptions = $this->dataAccess->getDateOptions($formId);
            if (!empty($dateOptions)) {
                $firstDateLabel = reset($dateOptions);
                if (!empty($firstDateLabel)) {
                    $dateInfo = $this->dataAccess->getDateInfoByLabel($formId, $firstDateLabel);
                    if ($dateInfo) {
                        return $this->dataAccess->getAreaOptions($formId, $dateInfo['id']);
                    }
                }
            }

            // 開催日が存在しない場合は空の配列を返す
            return array();
        }

        return $n;
    }

    /**
     * レンタル用品数量フィールドの動的生成
     *
     * @param WPCF7_FormTag $tag
     * @param array $unused
     * @return WPCF7_FormTag
     */
    public function addRentalQuantityFields($tag, $unused) {
        // $tagがオブジェクトかどうかをチェック
        if (!is_object($tag) || !isset($tag->name)) {
            return $tag;
        }

        // フォームIDの取得
        $form = wpcf7_get_current_contact_form();
        if (!$form) {
            return $tag;
        }

        $formId = $form->id();
        $tagName = $tag->name;

        // レンタル用品数量フィールドの処理
        if (preg_match('/^rental-(.+)$/', $tagName, $matches)) {
            $fieldName = $matches[1];

            // データベースからレンタル用品情報を取得
            global $wpdb;
            $tableName = $wpdb->prefix . 'marche_rental_items';
            $rental = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tableName} WHERE form_id = %d AND field_name = %s AND is_active = 1 ORDER BY sort_order LIMIT 1",
                $formId,
                $fieldName
            ));

            if ($rental && method_exists($tag, 'set_option')) {
                // 最小・最大値を動的に設定
                $tag->set_option('min:' . $rental->min_quantity);
                $tag->set_option('max:' . $rental->max_quantity);

                // デフォルト値を最小値に設定
                $tag->set_option('default:' . $rental->min_quantity);

                // データ属性を追加（JavaScript用）
                $tag->set_option('data-rental-id:' . $rental->id);
                $tag->set_option('data-rental-price:' . $rental->price);
                $tag->set_option('data-rental-unit:' . $rental->unit);
            }
        }

        return $tag;
    }

    /**
     * レンタル用品数量のバリデーション
     *
     * @param WPCF7_Validation $result
     * @param WPCF7_FormTag $tag
     * @return WPCF7_Validation
     */
    public function validateRentalQuantity($result, $tag) {
        // $tagがオブジェクトかどうかをチェック
        if (!is_object($tag) || !isset($tag->name)) {
            return $result;
        }

        $tagName = $tag->name;

        // レンタル用品数量フィールドの場合のみ処理
        if (!preg_match('/^rental-(.+)$/', $tagName, $matches)) {
            return $result;
        }

        $fieldName = $matches[1];
        $value = isset($_POST[$tagName]) ? intval($_POST[$tagName]) : 0;

        // フォームIDの取得
        $form = wpcf7_get_current_contact_form();
        if (!$form) {
            return $result;
        }

        $formId = $form->id();

        // データベースからレンタル用品情報を取得
        global $wpdb;
        $tableName = $wpdb->prefix . 'marche_rental_items';
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d AND field_name = %s AND is_active = 1 ORDER BY sort_order LIMIT 1",
            $formId,
            $fieldName
        ));

        if ($rental) {
            // 最小・最大数量のチェック
            if ($value < $rental->min_quantity) {
                $result->invalidate($tag, sprintf(
                    '%sの数量は%d以上で入力してください。',
                    $rental->item_name,
                    $rental->min_quantity
                ));
            } elseif ($value > $rental->max_quantity) {
                $result->invalidate($tag, sprintf(
                    '%sの数量は%d以下で入力してください。',
                    $rental->item_name,
                    $rental->max_quantity
                ));
            }

            // 必須フィールドで最小数量が1以上の場合のチェック
            if (method_exists($tag, 'is_required') && $tag->is_required() && $rental->min_quantity > 0 && $value < $rental->min_quantity) {
                $result->invalidate($tag, sprintf(
                    '%sは必須項目です。%d以上で入力してください。',
                    $rental->item_name,
                    $rental->min_quantity
                ));
            }
        }

        return $result;
    }

    /**
     * エリア定員のバリデーション
     *
     * @param WPCF7_Validation $result
     * @param WPCF7_FormTag $tag
     * @return WPCF7_Validation
     */
    public function validateAreaCapacity($result, $tag) {
        // $tagがオブジェクトかどうかをチェック
        if (!is_object($tag) || !isset($tag->name)) {
            return $result;
        }

        $tagName = $tag->name;

        // エリア選択フィールドの場合のみ処理
        if ($tagName !== 'booth-location') {
            return $result;
        }

        $value = isset($_POST[$tagName]) ? sanitize_text_field($_POST[$tagName]) : '';

        if (empty($value)) {
            return $result;
        }

        // フォームIDの取得
        $form = wpcf7_get_current_contact_form();
        if (!$form) {
            return $result;
        }

        $formId = $form->id();

        // 開催日情報の取得
        $dateId = null;
        if (isset($_POST['date']) && !empty($_POST['date'])) {
            $dateLabel = sanitize_text_field($_POST['date']);
            $dateInfo = $this->dataAccess->getDateInfoByLabel($formId, $dateLabel);
            if ($dateInfo) {
                $dateId = $dateInfo['id'];
            }
        }

        // エリア情報の取得（エリア名で検索、開催日を考慮）
        $areaInfo = $this->dataAccess->getAreaInfoByName($formId, $value, $dateId);

        if (!$areaInfo) {
            $result->invalidate($tag, '選択されたエリアが見つかりません。');
            return $result;
        }

        // 配列形式の情報をオブジェクト形式に変換（後続のコードとの互換性のため）
        $area = (object) $areaInfo;

        // 定員制限が無効の場合はチェックしない
        $capacityLimitEnabled = isset($area->capacity_limit_enabled) ? $area->capacity_limit_enabled : 1;
        if (!$capacityLimitEnabled) {
            return $result;
        }

        // 定員が0（無制限）の場合はチェックしない
        if ($area->capacity <= 0) {
            return $result;
        }

        // 定員状況の確認
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/area-management.php';
        $capacityStatus = MarcheAreaManagement::getAreaCapacityStatus($formId, $area->date_id, $area->id);

        $remainingCapacity = $area->capacity - $capacityStatus['used'];

        if ($remainingCapacity <= 0) {
            $result->invalidate($tag, sprintf(
                '%sは定員に達しているため、お申し込みできません。',
                $area->area_name
            ));
        }

        return $result;
    }

    /**
     * フォームの選択肢データをJSON形式で取得
     *
     * @param int $formId
     * @return string
     */
    public function getFormOptionsJson($formId) {
        $options = array(
            'dates' => $this->dataAccess->getDateOptions($formId),
            'areas' => $this->dataAccess->getAreaOptions($formId),
            'rentals' => array() // レンタル用品選択肢は現在未実装
        );

        return wp_json_encode($options);
    }

            /**
     * 合計金額メールタグの置換処理
     *
     * @param array $components メールコンポーネント
     * @param WPCF7_ContactForm $contact_form フォームオブジェクト
     * @param string $mail_tag メールタグ
     * @return array
     */
    public function replaceTotalAmountInMail($components, $contact_form, $mail_tag) {
        // フォームIDを取得
        $form_id = $contact_form->id();

        // 対象のフォームかどうかをチェック（登録済みフォームのみ）
        if (!$this->isRegisteredForm($form_id)) {
            return $components;
        }

        // 送信インスタンスからフォームデータを取得
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return $components;
        }

        $posted_data = $submission->get_posted_data();
        if (empty($posted_data)) {
            return $components;
        }

        // フォームデータの整理
        $formData = array();
        foreach ($posted_data as $key => $value) {
            if (strpos($key, '_wpcf7') === false && strpos($key, 'g-recaptcha') === false) {
                // 配列の場合は最初の要素を取得
                if (is_array($value)) {
                    $formData[$key] = $value[0];
                } else {
                    $formData[$key] = $value;
                }
            }
        }

        // 料金計算の実行
        $total_amount = 0;
        if ($this->priceCalculator) {
            $priceResult = $this->priceCalculator->calculateFormPrice($form_id, $formData);
            $total_amount = $priceResult['total_price'];
        }

        // 日本円でフォーマット
        $formatted_amount = $this->formatAmountJPY($total_amount);

        // メール本文内の[_total_amount]を置換
        if (isset($components['body'])) {
            $components['body'] = str_replace(
                '[_total_amount]',
                $formatted_amount,
                $components['body']
            );
        }

        return $components;
    }

    /**
     * 日本円でフォーマットする関数
     *
     * @param int $amount 金額
     * @return string フォーマットされた金額
     */
    private function formatAmountJPY($amount) {
        return number_format($amount) . '円';
    }

    /**
     * 登録済みフォームかどうかをチェック
     *
     * @param int $form_id フォームID
     * @return bool
     */
    private function isRegisteredForm($form_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'marche_forms';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE form_id = %d",
            $form_id
        ));
        return $count > 0;
    }

    /**
     * Stripe連携パラメータの設定
     *
     * @param array $parameters
     * @return array
     */
    public function setStripePaymentParameters($parameters) {
        try {
            // 現在のフォームオブジェクトを取得
            $form = wpcf7_get_current_contact_form();
            if (!$form) {
                return $parameters;
            }

            // フォームIDの取得
            $formId = $form->id();

            // 料金計算機能が利用可能な場合、動的料金を計算
            if ($this->priceCalculator) {
                // フォームデータの収集
                $formData = array();

                // エリア選択フィールドの値を取得
                $boothLocation = isset($_POST['booth-location']) ? sanitize_text_field($_POST['booth-location']) : '';
                if (!empty($boothLocation)) {
                    $formData['booth-location'] = $boothLocation;
                }

                // 開催日フィールドの値を取得
                $eventDate = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
                if (!empty($eventDate)) {
                    $formData['date'] = $eventDate;
                }

                // 顧客名フィールドの値を取得
                $customerName = isset($_POST['your-name']) ? sanitize_text_field($_POST['your-name']) : '';
                if (!empty($customerName)) {
                    $formData['your-name'] = $customerName;
                }

                // レンタル用品フィールドの値を取得
                global $wpdb;
                $rentalTable = $wpdb->prefix . 'marche_rental_items';
                $rentals = $wpdb->get_results($wpdb->prepare(
                    "SELECT field_name FROM {$rentalTable} WHERE form_id = %d AND is_active = 1",
                    $formId
                ));

                foreach ($rentals as $rental) {
                    $fieldName = 'rental-' . $rental->field_name;
                    if (isset($_POST[$fieldName])) {
                        $formData[$fieldName] = intval($_POST[$fieldName]);
                    }
                }

                                // 料金計算の実行
                $priceResult = $this->priceCalculator->calculateFormPrice($formId, $formData);

                if ($priceResult['success']) {
                    // Stripe用料金計算（銭単位での計算）
                    $stripeResult = $this->priceCalculator->calculateStripePrice($formId, $formData);

                    // 日本円設定（Stripeでは最小単位での指定が必要）
                    $parameters['currency'] = 'jpy';
                    $parameters['amount'] = $stripeResult['stripe_amount']; // 円を銭に変換済み

                    // 決済説明文の動的生成
                    $description = $this->priceCalculator->generatePaymentDescription($formId, $formData, $priceResult);
                    $parameters['description'] = $description;

                    // メタデータの設定
                    $metadata = $this->priceCalculator->generateStripeMetadata($formId, $formData, $priceResult);
                    $parameters['metadata'] = $metadata;

                } else {
                    // 料金計算エラーの場合
                    $parameters['currency'] = 'jpy';
                    $parameters['amount'] = 0; // エラー時は0円
                    $parameters['description'] = 'エラー: 料金計算に失敗しました';
                    $parameters['metadata'] = array(
                        'error' => $priceResult['error'],
                        'form_id' => $formId
                    );
                }
            } else {
                // 料金計算機能が利用できない場合のログ
            }



        } catch (Exception $e) {
            // 例外発生時のエラーログ
            $parameters['currency'] = 'jpy';
            $parameters['amount'] = 0;
            $parameters['description'] = 'システムエラーが発生しました';
            $parameters['metadata'] = array(
                'error' => $e->getMessage(),
                'form_id' => $formId
            );
        }

        return $parameters;
    }

    /**
     * Stripe決済パラメータフィルター
     *
     * @param array $intent_params Stripe PaymentIntent パラメータ
     * @param WPCF7_ContactForm $contact_form Contact Form 7 フォームオブジェクト
     * @return array 更新されたパラメータ
     */
    public function filterStripePaymentIntentParameters($intent_params, $contact_form) {
        try {
            // 現在のフォームIDを取得
            $formId = $contact_form ? $contact_form->id() : null;

            if (!$formId) {
                return $intent_params;
            }

            // フォーム情報の取得
            $settingsManager = new MarcheSettingsManager();
            $formInfo = $settingsManager->getFormInfo($formId);

            if (!$formInfo) {
                return $intent_params;
            }

            // Stripe設定の確認
            if (!$formInfo['enable_stripe']) {
                return $intent_params;
            }

            // 価格計算クラスの取得
            if (!class_exists('MarchePriceCalculator')) {
                return $intent_params;
            }

            $priceCalculator = new MarchePriceCalculator();

            // 現在の送信データから価格を計算
            $submission = WPCF7_Submission::get_instance();
            if (!$submission) {
                return $intent_params;
            }

            $posted_data = $submission->get_posted_data();
            $priceResult = $priceCalculator->calculatePrice($formId, $posted_data);

            if ($priceResult['success']) {
                // 価格をStripeパラメータに設定（円 -> セント変換）
                $amount_in_cents = intval($priceResult['total_price'] * 100);
                $intent_params['amount'] = $amount_in_cents;
                $intent_params['currency'] = 'jpy';

                // メタデータに詳細情報を追加
                $intent_params['metadata'] = array_merge(
                    isset($intent_params['metadata']) ? $intent_params['metadata'] : array(),
                    array(
                        'plugin' => 'marche-management',
                        'form_id' => $formId,
                        'base_price' => $priceResult['base_price'],
                        'area_price' => $priceResult['area_price'],
                        'rental_price' => $priceResult['rental_price'],
                        'total_price' => $priceResult['total_price']
                    )
                );
            } else {
                // 価格計算エラーの場合はデフォルト金額を設定
                $intent_params['amount'] = $formInfo['default_stripe_amount'] * 100;
                $intent_params['currency'] = 'jpy';
            }

            return $intent_params;

        } catch (Exception $e) {
            // エラーが発生した場合は元のパラメータを返す
            return $intent_params;
        }
    }
}
