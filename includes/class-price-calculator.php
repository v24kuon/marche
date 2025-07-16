<?php
/**
 * 料金計算クラス
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
 * 料金計算を担当するクラス
 *
 * @class MarchePriceCalculator
 * @description Stripe決済連携対応の料金計算機能を提供
 */
class MarchePriceCalculator {

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
    }

    /**
     * フォームの料金計算（Stripe決済連携対応版）
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @return array 計算結果（total_price, breakdown, errors, warnings）
     */
    public function calculateFormPrice($formId, $formData) {
        $startTime = microtime(true);
        $totalPrice = 0;
        $breakdown = array();
        $errors = array();
        $warnings = array();

        try {
            // 入力データの検証
            if (!is_numeric($formId) || $formId <= 0) {
                $errors[] = 'Invalid form ID provided';
                return $this->buildPriceResponse(0, array(), $errors, $warnings);
            }

            if (!is_array($formData)) {
                $errors[] = 'Invalid form data provided';
                return $this->buildPriceResponse(0, array(), $errors, $warnings);
            }

            // フォームの存在確認
            $formInfo = $this->dataAccess->getFormInfo($formId);
            if (!$formInfo) {
                $errors[] = 'Form not found in database';
                return $this->buildPriceResponse(0, array(), $errors, $warnings);
            }

            // エリア料金の計算
            $areaResult = $this->calculateAreaPrice($formId, $formData);
            if ($areaResult['success']) {
                $totalPrice += $areaResult['price'];
                $breakdown['area'] = $areaResult['data'];
            } else {
                $errors = array_merge($errors, $areaResult['errors']);
                if (!empty($areaResult['warnings'])) {
                    $warnings = array_merge($warnings, $areaResult['warnings']);
                }
            }

            // レンタル用品料金の計算
            $rentalResult = $this->calculateRentalPrice($formId, $formData);
            if ($rentalResult['success']) {
                $totalPrice += $rentalResult['total'];
                if ($rentalResult['total'] > 0) {
                    $breakdown['rental'] = $rentalResult['data'];
                }
            } else {
                $errors = array_merge($errors, $rentalResult['errors']);
                if (!empty($rentalResult['warnings'])) {
                    $warnings = array_merge($warnings, $rentalResult['warnings']);
                }
            }

            // 合計金額の検証
            if ($totalPrice < 0) {
                $errors[] = 'Total price cannot be negative';
                $totalPrice = 0;
            }

            // 計算時間の記録
            $calculationTime = microtime(true) - $startTime;
            if ($calculationTime > 1.0) { // 1秒以上かかった場合は警告
                $warnings[] = sprintf('Price calculation took %.2f seconds', $calculationTime);
            }

            // 結果のログ記録
            $result = $this->buildPriceResponse($totalPrice, $breakdown, $errors, $warnings);
            return $result;

        } catch (Exception $e) {
            $errors[] = 'Unexpected error during price calculation: ' . $e->getMessage();
            return $this->buildPriceResponse(0, array(), $errors, $warnings);
        }
    }

    /**
     * エリア料金の計算
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @return array 計算結果
     */
    private function calculateAreaPrice($formId, $formData) {
        $errors = array();
        $warnings = array();

        try {
            if (!isset($formData['booth-location']) || empty($formData['booth-location'])) {
                return array(
                    'success' => true,
                    'price' => 0,
                    'data' => null,
                    'errors' => array(),
                    'warnings' => array('No area selected')
                );
            }

            $areaName = sanitize_text_field($formData['booth-location']);

            // 開催日情報を取得してエリア検索を改善
            $dateId = null;
            if (isset($formData['date']) && !empty($formData['date'])) {
                $dateInfo = $this->dataAccess->getDateInfoByLabel($formId, $formData['date']);
                if ($dateInfo) {
                    $dateId = $dateInfo['id'];
                }
            }

            // エリア名でエリア情報を取得（開催日指定があれば使用）
            $areaInfo = $this->dataAccess->getAreaInfoByName($formId, $areaName, $dateId);
            if (!$areaInfo) {
                $errors[] = sprintf('Area "%s" not found for form ID %d', $areaName, $formId);
                if ($dateId) {
                    $errors[] = sprintf('Area search was limited to date ID %d', $dateId);
                }
                return array(
                    'success' => false,
                    'price' => 0,
                    'data' => null,
                    'errors' => $errors,
                    'warnings' => $warnings
                );
            }

            // 料金の検証
            $areaPrice = intval($areaInfo['price']);
            if ($areaPrice < 0) {
                $errors[] = sprintf('Invalid negative price for area "%s": %s', $areaName, $areaPrice);
                return array(
                    'success' => false,
                    'price' => 0,
                    'data' => null,
                    'errors' => $errors,
                    'warnings' => $warnings
                );
            }

            return array(
                'success' => true,
                'price' => $areaPrice,
                'data' => array(
                    'name' => $areaInfo['area_name'],
                    'price' => $areaPrice,
                    'area_id' => $areaInfo['id'],
                    'date_id' => $areaInfo['date_id']
                ),
                'errors' => $errors,
                'warnings' => $warnings
            );

        } catch (Exception $e) {
            $errors[] = 'Error calculating area price: ' . $e->getMessage();
            return array(
                'success' => false,
                'price' => 0,
                'data' => null,
                'errors' => $errors,
                'warnings' => $warnings
            );
        }
    }

    /**
     * レンタル用品料金の計算
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @return array 計算結果
     */
    private function calculateRentalPrice($formId, $formData) {
        $errors = array();
        $warnings = array();
        $rentalTotal = 0;
        $rentalItems = array();

        try {
            global $wpdb;
            $tableName = $wpdb->prefix . 'marche_rental_items';

            foreach ($formData as $key => $value) {
                if (strpos($key, 'rental-') !== 0 || empty($value)) {
                    continue;
                }

                $quantity = intval($value);
                if ($quantity <= 0) {
                    continue;
                }

                // レンタル用品フィールド名を取得
                $fieldName = str_replace('rental-', '', $key);
                $fieldName = sanitize_text_field($fieldName);

                // データベースからレンタル用品情報を取得
                $rental = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$tableName} WHERE form_id = %d AND field_name = %s AND is_active = 1 ORDER BY sort_order LIMIT 1",
                    $formId,
                    $fieldName
                ));

                if (!$rental) {
                    $warnings[] = sprintf('Rental item "%s" not found for form ID %d', $fieldName, $formId);
                    continue;
                }

                // 数量制限チェック
                if ($quantity < $rental->min_quantity) {
                    $errors[] = sprintf(
                        'Quantity %d for "%s" is below minimum %d',
                        $quantity,
                        $rental->item_name,
                        $rental->min_quantity
                    );
                    continue;
                }

                if ($quantity > $rental->max_quantity) {
                    $errors[] = sprintf(
                        'Quantity %d for "%s" exceeds maximum %d',
                        $quantity,
                        $rental->item_name,
                        $rental->max_quantity
                    );
                    continue;
                }

                // 料金計算
                $unitPrice = intval($rental->price);
                if ($unitPrice < 0) {
                    $errors[] = sprintf('Invalid negative price for rental item "%s": %s', $rental->item_name, $unitPrice);
                    continue;
                }

                $itemPrice = $unitPrice * $quantity;
                $rentalTotal += $itemPrice;

                $rentalItems[] = array(
                    'name' => $rental->item_name,
                    'field_name' => $rental->field_name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $itemPrice,
                    'unit' => $rental->unit,
                    'min_quantity' => $rental->min_quantity,
                    'max_quantity' => $rental->max_quantity,
                    'rental_id' => $rental->id
                );
            }

            return array(
                'success' => empty($errors),
                'total' => $rentalTotal,
                'data' => array(
                    'total' => $rentalTotal,
                    'items' => $rentalItems
                ),
                'errors' => $errors,
                'warnings' => $warnings
            );

        } catch (Exception $e) {
            $errors[] = 'Error calculating rental price: ' . $e->getMessage();
            return array(
                'success' => false,
                'total' => 0,
                'data' => array(),
                'errors' => $errors,
                'warnings' => $warnings
            );
        }
    }

    /**
     * Stripe決済用の料金計算（パブリック API）
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @return array Stripe用の料金情報
     */
    public function calculateStripePrice($formId, $formData) {
        $result = $this->calculateFormPrice($formId, $formData);

        // Stripe用のフォーマットに変換（日本円は最小通貨単位が1円なので100倍不要）
        $stripeAmount = intval($result['total_price']);

        return array(
            'amount' => $stripeAmount,
            'stripe_amount' => $stripeAmount, // 互換性のため両方追加
            'currency' => 'jpy',
            'success' => $result['success'],
            'description' => $this->generatePaymentDescription($formId, $formData, $result),
            'metadata' => $this->generateStripeMetadata($formId, $formData, $result),
            'breakdown' => $result['breakdown'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'total_amount' => $result['total_price'] // 元の金額も追加
        );
    }

    /**
     * 決済説明文の生成
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @param array $priceResult 料金計算結果
     * @return string 決済説明文
     */
    public function generatePaymentDescription($formId, $formData, $priceResult) {
        $description = '';

        // フォーム情報の取得
        $formInfo = $this->dataAccess->getFormInfo($formId);
        $formType = $formInfo ? $formInfo['form_type'] : 'マルシェ';

        // 開催日の取得
        $eventDate = '';
        if (isset($formData['date']) && !empty($formData['date'])) {
            $dateInfo = $this->dataAccess->getDateInfoByLabel($formId, $formData['date']);
            if ($dateInfo) {
                // 日付を日本語形式に変換
                $eventDate = $this->formatJapaneseDate($dateInfo['date_value']);
            }
        }

        // 顧客名の取得
        $customerName = '';
        if (isset($formData['your-name']) && !empty($formData['your-name'])) {
            $customerName = sanitize_text_field($formData['your-name']);
        }

        // メールアドレスの取得
        $customerEmail = '';
        if (isset($formData['your-email']) && !empty($formData['your-email'])) {
            $customerEmail = sanitize_email($formData['your-email']);
        }

        // エリア名の取得
        $areaName = '';
        if (isset($priceResult['breakdown']['area'])) {
            $areaName = $priceResult['breakdown']['area']['name'];
        }

        // 説明文の構築
        if ($eventDate) {
            $description .= $eventDate . 'に開催の';
        }

        // フォームタイプを追加
        $description .= $formType . 'で申し込みの';

        if ($customerName) {
            $description .= $customerName;
            if ($customerEmail) {
                $description .= '(' . $customerEmail . ')';
            }
            $description .= 'さんの決済です。';
        } else {
            $description .= 'お客様の決済です。';
        }

        if ($areaName) {
            $description .= '出店場所は' . $areaName . 'です';
        }

        // フォールバック（情報が不足している場合）
        if (empty($description)) {
            $description = $formType . '出店料の決済です';
        }

        return $description;
    }

    /**
     * 日付を日本語形式にフォーマット
     *
     * @param string $dateValue 日付値
     * @return string 日本語形式の日付
     */
    private function formatJapaneseDate($dateValue) {
        try {
            // 日付文字列をDateTimeオブジェクトに変換
            $date = new DateTime($dateValue);

            // 曜日の配列
            $weekdays = array('日', '月', '火', '水', '木', '金', '土');
            $weekday = $weekdays[$date->format('w')];

            // 日本語形式にフォーマット（例: 2024年12月12日(日)）
            return $date->format('Y年n月j日') . '(' . $weekday . ')';

        } catch (Exception $e) {
            // 変換に失敗した場合は元の値を返す
            return $dateValue;
        }
    }

    /**
     * Stripeメタデータの生成
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @param array $priceResult 料金計算結果
     * @return array メタデータ
     */
    public function generateStripeMetadata($formId, $formData, $priceResult) {
        $metadata = array();

        // form_id
        $metadata['form_id'] = strval($formId);

        // formType
        $formInfo = $this->dataAccess->getFormInfo($formId);
        $metadata['formType'] = $formInfo ? $formInfo['form_type'] : 'マルシェ';

        // date
        if (isset($formData['date']) && !empty($formData['date'])) {
            $metadata['date'] = $formData['date'];
        }

        // customer_name
        if (isset($formData['your-name']) && !empty($formData['your-name'])) {
            $metadata['customer_name'] = sanitize_text_field($formData['your-name']);
        }

        // customer_email
        if (isset($formData['your-email']) && !empty($formData['your-email'])) {
            $metadata['customer_email'] = sanitize_email($formData['your-email']);
        }

        // area_name（エリア情報のメタデータ設定）
        if (isset($formData['booth-location']) && !empty($formData['booth-location'])) {
            $metadata['area_name'] = sanitize_text_field($formData['booth-location']);
        } elseif (isset($priceResult['breakdown']['area']['name'])) {
            $metadata['area_name'] = $priceResult['breakdown']['area']['name'];
        }

        // レンタル用品情報のメタデータ設定（rental_フィールド名: 数量）
        foreach ($formData as $key => $value) {
            if (strpos($key, 'rental-') === 0 && is_numeric($value) && intval($value) > 0) {
                // フィールド名を英数字・アンダースコアに変換
                $fieldKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
                $metadata[$fieldKey] = intval($value);
            }
        }

        return $metadata;
    }

    /**
     * 料金計算結果の検証
     *
     * @param array $result 料金計算結果
     * @return bool 有効かどうか
     */
    public function validatePriceResult($result) {
        if (!is_array($result)) {
            return false;
        }

        // 必須フィールドの確認
        $requiredFields = array('total_price', 'breakdown', 'errors', 'success');
        foreach ($requiredFields as $field) {
            if (!isset($result[$field])) {
                return false;
            }
        }

        // エラーがある場合は無効
        if (!empty($result['errors'])) {
            return false;
        }

        // 金額が負の場合は無効
        if ($result['total_price'] < 0) {
            return false;
        }

        return true;
    }

    /**
     * 料金計算レスポンスの構築
     *
     * @param float $totalPrice 合計金額
     * @param array $breakdown 内訳
     * @param array $errors エラー
     * @param array $warnings 警告
     * @return array
     */
    private function buildPriceResponse($totalPrice, $breakdown, $errors, $warnings) {
        return array(
            'total_price' => intval($totalPrice), // 日本円は整数値
            'breakdown' => $breakdown,
            'errors' => $errors,
            'warnings' => $warnings,
            'success' => empty($errors),
            'currency' => 'JPY',
            'calculated_at' => current_time('mysql')
        );
    }

    /**
     * 料金設定の最終検証
     *
     * @param array $settings 料金設定
     * @return array 検証結果
     */
    private function validatePricingSettings($settings) {
        $errors = array();
        $warnings = array();

        // エリア設定の検証
        if (empty($settings['areas'])) {
            $errors[] = 'No areas configured';
        } else {
            foreach ($settings['areas'] as $area) {
                if ($area['price'] < 0) {
                    $errors[] = sprintf('Negative area price: %s', $area['name']);
                }
            }
        }

        return array(
            'errors' => $errors,
            'warnings' => $warnings,
            'is_valid' => empty($errors)
        );
    }
}
