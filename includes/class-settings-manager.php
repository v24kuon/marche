<?php
/**
 * 設定管理クラス
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
 * 設定管理を担当するクラス
 *
 * @class MarcheSettingsManager
 * @description 料金設定の取得・検証、キャッシュ管理、テスト機能を提供
 */
class MarcheSettingsManager {

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
    public function __construct($dataAccess, $priceCalculator) {
        $this->dataAccess = $dataAccess;
        $this->priceCalculator = $priceCalculator;
    }

    /**
     * 管理画面設定料金の動的取得機能
     *
     * @param int $formId フォームID
     * @return array 設定料金情報
     */
    public function getFormPricingSettings($formId) {
        try {
            $formInfo = $this->dataAccess->getFormInfo($formId);
            if (!$formInfo) {
                return array(
                    'success' => false,
                    'error' => 'Form not found',
                    'settings' => array()
                );
            }

            // エリア料金設定の取得
            $areaSettings = $this->dataAccess->getAreaPricingSettings($formId);

            // レンタル用品料金設定の取得
            $rentalSettings = $this->dataAccess->getRentalPricingSettings($formId);

            // 基本設定の取得
            $basicSettings = array(
                'form_id' => $formId,
                'form_name' => $formInfo['form_name'],
                'payment_method' => $formInfo['payment_method'],
                'currency' => 'JPY'
            );

            return array(
                'success' => true,
                'settings' => array(
                    'basic' => $basicSettings,
                    'areas' => $areaSettings,
                    'rentals' => $rentalSettings
                )
            );

        } catch (Exception $e) {
            // エラーログは必要に応じてコメントアウトを外す
            return array();
        }
    }

    /**
     * 料金設定の検証
     *
     * @param int $formId フォームID
     * @return array 検証結果
     */
    public function validatePricingSettings($formId) {
        $warnings = array();
        $errors = array();

        try {
            // デバッグ情報の追加
            $debugInfo = array();

            // エリア設定の検証
            $areaSettings = $this->dataAccess->getAreaPricingSettings($formId);
            $debugInfo['raw_area_settings'] = $areaSettings;

            $activeAreas = array_filter($areaSettings, function($area) {
                return $area['is_active'];
            });
            $debugInfo['active_areas'] = $activeAreas;

            if (empty($activeAreas)) {
                $warnings[] = 'No active areas configured for this form';
            }

            foreach ($activeAreas as $area) {
                if ($area['price'] < 0) {
                    $errors[] = sprintf('Negative price for area "%s": %s', $area['name'], $area['price']);
                }

                if ($area['capacity_limit_enabled'] && $area['capacity'] <= 0) {
                    $warnings[] = sprintf('Area "%s" has capacity limit enabled but capacity is 0', $area['name']);
                }
            }

            // レンタル用品設定の検証
            $rentalSettings = $this->dataAccess->getRentalPricingSettings($formId);
            $debugInfo['raw_rental_settings'] = $rentalSettings;

            $activeRentals = array_filter($rentalSettings, function($rental) {
                return $rental['is_active'];
            });
            $debugInfo['active_rentals'] = $activeRentals;

            foreach ($activeRentals as $rental) {
                if ($rental['price'] < 0) {
                    $errors[] = sprintf('Negative price for rental item "%s": %s', $rental['item_name'], $rental['price']);
                }

                if ($rental['min_quantity'] > $rental['max_quantity']) {
                    $errors[] = sprintf(
                        'Invalid quantity range for rental item "%s": min=%d, max=%d',
                        $rental['item_name'],
                        $rental['min_quantity'],
                        $rental['max_quantity']
                    );
                }

                if ($rental['min_quantity'] < 0 || $rental['max_quantity'] < 0) {
                    $errors[] = sprintf('Negative quantity for rental item "%s"', $rental['item_name']);
                }
            }

            return array(
                'success' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'areas_count' => count($activeAreas),
                'rentals_count' => count($activeRentals),
                'debug_info' => $debugInfo // デバッグ情報を追加
            );

        } catch (Exception $e) {
            $errors[] = 'Error validating pricing settings: ' . $e->getMessage();
            return array(
                'success' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'areas_count' => 0,
                'rentals_count' => 0,
                'debug_info' => array('exception' => $e->getMessage())
            );
        }
    }

    /**
     * キャッシュのクリア
     *
     * @param int $formId フォームID（nullの場合は全キャッシュをクリア）
     * @return void
     */
    public function clearPricingCache($formId = null) {
        $cacheKey = $formId ? "marche_pricing_settings_{$formId}" : 'marche_pricing_settings_*';

        // WordPress Object Cacheがある場合
        if (function_exists('wp_cache_delete')) {
            if ($formId) {
                wp_cache_delete($cacheKey, 'marche_plugin');
            } else {
                wp_cache_flush();
            }
        }

        // Transientのクリア
        if ($formId) {
            delete_transient("marche_pricing_{$formId}");
        } else {
            // 全ての関連transientをクリア
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_marche_pricing_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_marche_pricing_%'");
        }
    }
}
