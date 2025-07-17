<?php
/**
 * Plugin Name: Marche Management Plugin
 * Description: WordPressマルシェ顧客管理システム - Contact Form 7と連携した動的フォーム管理
 * Version: 1.0.0
 * Author: GITAG
 * Text Domain: marche-management
 * Requires at least: 6.7
 * Tested up to: 6.7
 * Requires PHP: 7.4
 *
 * @package MarcheManagement
 * @author GITAG
 * @version 1.0.0
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数の定義
define('MARCHE_MANAGEMENT_VERSION', '1.0.0');
define('MARCHE_MANAGEMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MARCHE_MANAGEMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MARCHE_MANAGEMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * メインプラグインクラス
 *
 * @class MarcheManagementPlugin
 * @description プラグインのメイン機能を管理するクラス
 */
class MarcheManagementPlugin {

    /**
     * プラグインのインスタンス
     *
     * @var MarcheManagementPlugin
     */
    private static $instance = null;

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
     * フォームフッククラスのインスタンス
     *
     * @var MarcheFormHooks
     */
    private $formHooks;

    /**
     * 設定管理クラスのインスタンス
     *
     * @var MarcheSettingsManager
     */
    private $settingsManager;

    /**
     * 申し込みデータ保存クラスのインスタンス
     *
     * @var MarcheApplicationSaver
     */
    private $applicationSaver;

    /**
     * ファイル管理クラスのインスタンス
     *
     * @var Marche_File_Manager
     */
    private $fileManager;

    /**
     * シングルトンインスタンスの取得
     *
     * @return MarcheManagementPlugin
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     * プラグインの初期化処理
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * プラグインの初期化
     *
     * @return void
     */
    public function init() {
        // Contact Form 7の依存関係チェック
        if (!$this->checkDependencies()) {
            add_action('admin_notices', array($this, 'dependencyNotice'));
            return;
        }

        // クラスファイルの読み込み
        $this->loadClasses();

        // クラスインスタンスの初期化
        $this->initializeClasses();

        // 日本語のみ対応のため言語ファイルの読み込みは不要

        // フロントエンドスクリプトの読み込み
        add_action('wp_enqueue_scripts', array($this, 'enqueueFrontendAssets'));

        // AJAXエンドポイントの追加
        add_action('wp_ajax_get_area_options_by_date', array($this, 'ajaxGetAreaOptionsByDate'));
        add_action('wp_ajax_nopriv_get_area_options_by_date', array($this, 'ajaxGetAreaOptionsByDate'));
        add_action('wp_ajax_marche_get_file_details', array($this, 'ajaxGetFileDetails'));
        add_action('wp_ajax_get_form_pricing_settings', array($this, 'ajaxGetFormPricingSettings'));
        add_action('wp_ajax_nopriv_get_form_pricing_settings', array($this, 'ajaxGetFormPricingSettings'));

        // 管理画面の初期化
        if (is_admin()) {
            $this->initAdmin();
        }
    }

    /**
     * クラスファイルの読み込み
     *
     * @return void
     */
    private function loadClasses() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/class-data-access.php';
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/class-price-calculator.php';
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/class-form-hooks.php';
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/class-settings-manager.php';
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/class-application-saver.php';
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/class-file-manager.php';
    }

    /**
     * クラスインスタンスの初期化
     *
     * @return void
     */
    private function initializeClasses() {
        // データアクセスクラスの初期化
        $this->dataAccess = new MarcheDataAccess();

        // 料金計算クラスの初期化
        $this->priceCalculator = new MarchePriceCalculator($this->dataAccess);

        // フォームフッククラスの初期化（WordPressフック&Stripe連携も自動登録）
        $this->formHooks = new MarcheFormHooks($this->dataAccess, $this->priceCalculator);

        // 設定管理クラスの初期化
        $this->settingsManager = new MarcheSettingsManager($this->dataAccess, $this->priceCalculator);

        // 申し込みデータ保存クラスの初期化
        $this->applicationSaver = new MarcheApplicationSaver($this->dataAccess);

        // ファイル管理クラスの初期化
        $this->fileManager = new Marche_File_Manager();
        $this->fileManager->init();
    }

    /**
     * 依存関係のチェック
     *
     * @return bool
     */
    private function checkDependencies() {
        // Contact Form 7がアクティブかチェック
        if (!is_plugin_active('contact-form-7/wp-contact-form-7.php')) {
            return false;
        }

        // Contact Form 7のバージョンチェック
        if (defined('WPCF7_VERSION') && version_compare(WPCF7_VERSION, '6.1', '<')) {
            return false;
        }

        return true;
    }

    /**
     * 依存関係エラーの通知
     *
     * @return void
     */
    public function dependencyNotice() {
        echo '<div class="notice notice-error"><p>';
        echo 'Marche Management Plugin を使用するには Contact Form 7 バージョン 6.1 以上がインストールされ、有効化されている必要があります。';
        echo '</p></div>';
    }

    /**
     * 管理画面の初期化
     *
     * @return void
     */
    private function initAdmin() {
        // 管理画面メニューの追加
        add_action('admin_menu', array($this, 'addAdminMenu'));

        // 管理画面スタイル・スクリプトの読み込み
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
    }

    /**
     * 管理画面メニューの追加
     *
     * @return void
     */
        public function addAdminMenu() {
        add_menu_page(
            'マルシェ管理',
            'マルシェ管理',
            'manage_options',
            'marche-management',
            array($this, 'adminPageCallback'),
            'dashicons-store',
            30
        );

        // サブメニューの追加
        add_submenu_page(
            'marche-management',
            'ダッシュボード',
            'ダッシュボード',
            'manage_options',
            'marche-management',
            array($this, 'adminPageCallback')
        );

        add_submenu_page(
            'marche-management',
            'フォーム管理',
            'フォーム管理',
            'manage_options',
            'marche-form-management',
            array($this, 'formManagementPageCallback')
        );

        add_submenu_page(
            'marche-management',
            '開催日管理',
            '開催日管理',
            'manage_options',
            'marche-date-management',
            array($this, 'dateManagementPageCallback')
        );

        add_submenu_page(
            'marche-management',
            'エリア管理',
            'エリア管理',
            'manage_options',
            'marche-area-management',
            array($this, 'areaManagementPageCallback')
        );

        add_submenu_page(
            'marche-management',
            'レンタル用品管理',
            'レンタル用品管理',
            'manage_options',
            'marche-rental-management',
            array($this, 'rentalManagementPageCallback')
        );

        add_submenu_page(
            'marche-management',
            '画像管理',
            '画像管理',
            'manage_options',
            'marche-image-management',
            array($this, 'imageManagementPageCallback')
        );

        add_submenu_page(
            'marche-management',
            '設定',
            '設定',
            'manage_options',
            'marche-settings',
            array($this, 'settingsPageCallback')
        );
    }

    /**
     * フロントエンドアセットの読み込み
     *
     * @return void
     */
    public function enqueueFrontendAssets() {
        // Contact Form 7が存在するページでのみ読み込み
        if (function_exists('wpcf7_enqueue_scripts')) {
            // フロントエンドCSS
            wp_enqueue_style(
                'marche-frontend',
                MARCHE_MANAGEMENT_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                MARCHE_MANAGEMENT_VERSION
            );

            // フロントエンドJS
            wp_enqueue_script(
                'marche-frontend',
                MARCHE_MANAGEMENT_PLUGIN_URL . 'assets/js/frontend.js',
                array(),
                MARCHE_MANAGEMENT_VERSION,
                true
            );

            // AJAX用の変数を渡す
            wp_localize_script('marche-frontend', 'marcheAjax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('marche_frontend_nonce')
            ));
        }
    }

    /**
     * 管理画面アセットの読み込み
     *
     * @param string $hook
     * @return void
     */
    public function enqueueAdminAssets($hook) {
        if (strpos($hook, 'marche-') === false) {
            return;
        }

        wp_enqueue_style(
            'marche-management-admin',
            MARCHE_MANAGEMENT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MARCHE_MANAGEMENT_VERSION
        );

        wp_enqueue_script(
            'marche-management-admin',
            MARCHE_MANAGEMENT_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            MARCHE_MANAGEMENT_VERSION,
            true
        );
    }

    // ===== 公開API: 新しいクラス構造への委譲メソッド =====

    /**
     * 料金計算API（外部アクセス用）
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @return array 計算結果
     */
    public function calculateFormPrice($formId, $formData) {
        return $this->priceCalculator->calculateFormPrice($formId, $formData);
    }

    /**
     * Stripe用料金計算API（外部アクセス用）
     *
     * @param int $formId フォームID
     * @param array $formData フォームデータ
     * @return array Stripe用料金情報
     */
    public function calculateStripePrice($formId, $formData) {
        return $this->priceCalculator->calculateStripePrice($formId, $formData);
    }

    /**
     * 料金設定取得API（外部アクセス用）
     *
     * @param int $formId フォームID
     * @return array 設定料金情報
     */
    public function getFormPricingSettings($formId) {
        return $this->settingsManager->getFormPricingSettings($formId);
    }

    /**
     * フォームの選択肢データをJSON形式で取得
     *
     * @param int $formId
     * @return string
     */
    public function getFormOptionsJson($formId) {
        return $this->formHooks->getFormOptionsJson($formId);
    }

    /**
     * 開催日に基づくエリア選択肢のAJAX取得
     *
     * @return void
     */
    public function ajaxGetAreaOptionsByDate() {
        // ノンス検証
        if (!wp_verify_nonce($_POST['nonce'], 'marche_frontend_nonce')) {
            wp_send_json_error('無効なリクエストです');
            return;
        }

        $formId = intval($_POST['form_id']);
        $dateLabel = sanitize_text_field($_POST['date_label']);

        if (!$formId || !$dateLabel) {
            wp_send_json_error('必要なパラメータが不足しています');
            return;
        }

        try {
            // 開催日ラベルから開催日IDを取得
            $dateInfo = $this->dataAccess->getDateInfoByLabel($formId, $dateLabel);
            if (!$dateInfo) {
                wp_send_json_error('指定された開催日が見つかりません');
                return;
            }

            $dateId = $dateInfo['id'];

            // 開催日に基づくエリア選択肢を取得
            $areaOptions = $this->dataAccess->getAreaOptions($formId, $dateId);

            wp_send_json_success($areaOptions);

        } catch (Exception $e) {
            wp_send_json_error('エリア選択肢の取得に失敗しました');
        }
    }

    // ===== 管理画面コールバックメソッド =====

    /**
     * フォーム管理画面のコールバック
     *
     * @return void
     */
    /**
     * フォーム料金設定取得のAJAXハンドラ
     */
    public function ajaxGetFormPricingSettings() {
        // ノンス検証
        if (!wp_verify_nonce($_POST['nonce'], 'marche_frontend_nonce')) {
            wp_send_json_error('無効なリクエストです');
            return;
        }

        $formId = intval($_POST['form_id']);

        if (!$formId) {
            wp_send_json_error('フォームIDが指定されていません');
            return;
        }

        try {
            // 料金設定を取得
            $pricingSettings = $this->settingsManager->getFormPricingSettings($formId);

            if ($pricingSettings['success']) {
                wp_send_json_success($pricingSettings['settings']);
            } else {
                wp_send_json_error('料金設定の取得に失敗しました');
            }

        } catch (Exception $e) {
            wp_send_json_error('料金設定の取得中にエラーが発生しました');
        }
    }

    /**
     * ファイル詳細情報取得のAJAXハンドラ
     */
    public function ajaxGetFileDetails() {
        // nonce チェック
        if (!wp_verify_nonce($_POST['nonce'], 'marche_file_details')) {
            wp_die('Security check failed');
        }

        // 権限チェック
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $file_id = intval($_POST['file_id']);
        $application_id = intval($_POST['application_id']);

        // ファイル情報の取得
        global $wpdb;
        $files_table = $wpdb->prefix . 'marche_files';
        $applications_table = $wpdb->prefix . 'marche_applications';

        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, a.application_data, a.area_name, a.created_at as application_date
             FROM {$files_table} f
             INNER JOIN {$applications_table} a ON f.application_id = a.id
             WHERE f.id = %d AND a.id = %d",
            $file_id, $application_id
        ));

        if (!$file) {
            wp_send_json_error('ファイル情報が見つかりません。');
            return;
        }

        $application_data = json_decode($file->application_data, true);
        $is_image = in_array(strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);

        // HTML生成
        ob_start();
        ?>
        <div class="marche-file-details">
            <?php if ($is_image): ?>
                <div style="text-align: center; margin-bottom: 20px;">
                    <img src="<?php echo esc_url($file->file_url); ?>"
                         alt="<?php echo esc_attr($file->file_name); ?>"
                         style="max-width: 100%; max-height: 300px; border: 1px solid #ddd;">
                </div>
            <?php endif; ?>

            <table class="marche-detail-table">
                <tr>
                    <th>ファイル名</th>
                    <td><?php echo esc_html($file->file_name); ?></td>
                </tr>
                <tr>
                    <th>アップロード日時</th>
                    <td><?php
                        // WordPressのタイムゾーン設定を取得
                        $timezone = get_option('timezone_string');
                        if (empty($timezone)) {
                            $timezone = 'Asia/Tokyo'; // デフォルトで日本時間
                        }

                        // 日時を日本時間で表示
                        $date = new DateTime($file->uploaded_at, new DateTimeZone('UTC'));
                        $date->setTimezone(new DateTimeZone($timezone));
                        echo esc_html($date->format('Y-m-d H:i:s'));
                    ?></td>
                </tr>
                <tr>
                    <th>ファイルURL</th>
                    <td><a href="<?php echo esc_url($file->file_url); ?>" target="_blank"><?php echo esc_html($file->file_url); ?></a></td>
                </tr>
                <tr>
                    <th>申し込み者名</th>
                    <td><?php echo esc_html(isset($application_data['your-name']) ? $application_data['your-name'] : ''); ?></td>
                </tr>
                <tr>
                    <th>メールアドレス</th>
                    <td><?php echo esc_html(isset($application_data['your-email']) ? $application_data['your-email'] : ''); ?></td>
                </tr>
                <tr>
                    <th>エリア名</th>
                    <td><?php echo esc_html($file->area_name); ?></td>
                </tr>
                <tr>
                    <th>申し込み日時</th>
                    <td><?php
                        // WordPressのタイムゾーン設定を取得
                        $timezone = get_option('timezone_string');
                        if (empty($timezone)) {
                            $timezone = 'Asia/Tokyo'; // デフォルトで日本時間
                        }

                        // 日時を日本時間で表示
                        $date = new DateTime($file->application_date, new DateTimeZone('UTC'));
                        $date->setTimezone(new DateTimeZone($timezone));
                        echo esc_html($date->format('Y-m-d H:i:s'));
                    ?></td>
                </tr>
                <?php if (isset($application_data['booth-company'])): ?>
                <tr>
                    <th>会社名</th>
                    <td><?php echo esc_html($application_data['booth-company']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (isset($application_data['your-tel'])): ?>
                <tr>
                    <th>電話番号</th>
                    <td><?php echo esc_html($application_data['your-tel']); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <div style="margin-top: 20px; text-align: center;">
                <a href="<?php echo esc_url($file->file_url); ?>" target="_blank" class="button button-primary">画像を表示</a>
                <a href="<?php echo esc_url($file->file_url); ?>" download="<?php echo esc_attr($file->file_name); ?>" class="button">ダウンロード</a>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    public function adminPageCallback() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/dashboard-management.php';
    }

    /**
     * フォーム管理ページのコールバック
     */
    public function formManagementPageCallback() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/form-management.php';
        MarcheFormManagement::displayPage();
    }

        /**
     * 開催日管理画面のコールバック
     *
     * @return void
     */
    public function dateManagementPageCallback() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/date-management.php';
        MarcheDateManagement::displayPage();
    }

        /**
     * エリア管理画面のコールバック
     *
     * @return void
     */
    public function areaManagementPageCallback() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/area-management.php';
        MarcheAreaManagement::displayPage();
    }

    /**
     * レンタル用品管理画面のコールバック
     *
     * @return void
     */
    public function rentalManagementPageCallback() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/rental-management.php';
        MarcheRentalManagement::displayPage();
    }

    /**
     * 画像管理ページのコールバック
     *
     * @return void
     */
    public function imageManagementPageCallback() {
        require_once MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/image-management.php';
    }

    /**
     * 設定ページコールバック
     *
     * @return void
     */
    public function settingsPageCallback() {
        echo '<div class="wrap">';
        echo '<h1>マルシェ管理設定</h1>';
        echo '<p>Contact Form 7で設定が必要なフィールドの一覧です。各フィールドのコードをコピーしてフォームエディタに貼り付けてください。</p>';

        // Contact Form 7必須フィールドのみ表示
        $this->displayRequiredFields();

        echo '</div>';
    }

    /**
     * 必須フィールドの表示
     */
    private function displayRequiredFields() {
        echo '<div class="marche-settings-section">';
        echo '<h2>🔴 必須フィールド</h2>';
        echo '<p class="description">これらのフィールドは必ずフォームに含めてください。</p>';

        $requiredFields = array(
            'ブース名' => '[text* booth-name placeholder "ブース名"]',
            '申し込み者名' => '[text* your-name placeholder "お名前"]',
            'メールアドレス' => '[email* your-email placeholder "メールアドレス"]',
            '開催日選択' => '[select* date data:date first_as_label "開催日を選択してください"]',
            'エリア選択' => '[select* booth-location data:booth-location first_as_label "エリアを選択してください"]',
            '車両高さ' => '[number booth-car-height min:1000 placeholder "車両高さ（mm）"]',
            '車で搬入希望' => '[radio booth-carrying use_label_element "希望する" "希望しない"]',
            'チラシ枚数' => '[number* flyer-number min:0 max:9999 placeholder "チラシ枚数"]'
        );

        $this->displayFieldList($requiredFields, 'required');
        echo '</div>';
    }

    /**
     * フィールドリストの表示
     */
    private function displayFieldList($fields, $category) {
        foreach ($fields as $label => $code) {
            echo '<div class="marche-code-block">';
            echo '<label class="marche-field-label">' . esc_html($label) . '</label>';
            echo '<code class="marche-field-code">' . esc_html($code) . '</code>';
            echo '<button type="button" class="button marche-copy-button" onclick="navigator.clipboard.writeText(\'' . esc_js($code) . '\'); this.textContent=\'コピー済み\'; setTimeout(() => this.textContent=\'コピー\', 2000);">コピー</button>';
            echo '</div>';
        }

        // CSS追加
        static $css_added = false;
        if (!$css_added) {
            // CSSは assets/css/admin.css に統合済み
            $css_added = true;
        }
    }

    // ===== プラグインアクティベーション・デアクティベーション =====

    /**
     * プラグインアクティベーション処理
     *
     * @return void
     */
    public function activate() {
        // データベーステーブルの作成
        $this->createDatabaseTables();

        // 必要なディレクトリの作成
        $this->createDirectories();

        // 初期設定の保存
        $this->saveDefaultSettings();

        // リライトルールのフラッシュ
        flush_rewrite_rules();
    }

    /**
     * プラグインデアクティベーション処理
     *
     * @return void
     */
    public function deactivate() {
        // リライトルールのフラッシュ
        flush_rewrite_rules();
    }

    /**
     * データベーステーブルの作成
     *
     * @return void
     */
    private function createDatabaseTables() {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        // フォーム基本設定テーブル
        $formsTable = $wpdb->prefix . 'marche_forms';
        $formsTableSql = "CREATE TABLE {$formsTable} (
            id int(11) NOT NULL AUTO_INCREMENT,
            form_id int(11) NOT NULL,
            form_name varchar(255) NOT NULL,
            form_type enum('マルシェ','ステージ') DEFAULT 'マルシェ',
            payment_method enum('credit_card','bank_transfer','both') DEFAULT 'both',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY form_id (form_id)
        ) {$charsetCollate};";

        // 開催日管理テーブル
        $datesTable = $wpdb->prefix . 'marche_dates';
        $datesTableSql = "CREATE TABLE {$datesTable} (
            id int(11) NOT NULL AUTO_INCREMENT,
            form_id int(11) NOT NULL,
            date_value date NOT NULL,
            description varchar(255) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            sort_order int(11) DEFAULT 0,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charsetCollate};";

        // エリア管理テーブル
        $areasTable = $wpdb->prefix . 'marche_areas';
        $areasTableSql = "CREATE TABLE {$areasTable} (
            id int(11) NOT NULL AUTO_INCREMENT,
            form_id int(11) NOT NULL,
            date_id int(11) NOT NULL,
            area_name varchar(255) NOT NULL,
            price int(11) NOT NULL DEFAULT 0,
            capacity int(11) DEFAULT 0,
            capacity_limit_enabled tinyint(1) DEFAULT 1,
            is_active tinyint(1) DEFAULT 1,
            sort_order int(11) DEFAULT 0,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY date_id (date_id),
            KEY form_date_idx (form_id, date_id)
        ) {$charsetCollate};";

        // レンタル用品管理テーブル
        $rentalItemsTable = $wpdb->prefix . 'marche_rental_items';
        $rentalItemsTableSql = "CREATE TABLE {$rentalItemsTable} (
            id int(11) NOT NULL AUTO_INCREMENT,
            form_id int(11) NOT NULL,
            item_name varchar(255) NOT NULL,
            field_name varchar(100) NOT NULL DEFAULT '',
            price int(11) NOT NULL DEFAULT 0,
            unit varchar(50) NOT NULL,
            description text DEFAULT NULL,
            min_quantity int(11) NOT NULL DEFAULT 0,
            max_quantity int(11) NOT NULL DEFAULT 99,
            is_active tinyint(1) DEFAULT 1,
            sort_order int(11) DEFAULT 0,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charsetCollate};";

        // 申し込みデータ保存テーブル
        $applicationsTable = $wpdb->prefix . 'marche_applications';
        $applicationsTableSql = "CREATE TABLE {$applicationsTable} (
            id int(11) NOT NULL AUTO_INCREMENT,
            form_id int(11) NOT NULL,
            date_id int(11) NOT NULL,
            application_data longtext NOT NULL,
            area_name varchar(255) DEFAULT NULL,
            flyer_number int(11) DEFAULT 0,
            car_height varchar(50) DEFAULT NULL,
            rental_items longtext DEFAULT NULL,
            files_json longtext DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY date_id (date_id),
            KEY form_date_idx (form_id, date_id),
            KEY area_name_idx (area_name),
            KEY created_at_idx (created_at)
        ) {$charsetCollate};";

        // ファイル情報保存テーブル
        $filesTable = $wpdb->prefix . 'marche_files';
        $filesTableSql = "CREATE TABLE {$filesTable} (
            id int(11) NOT NULL AUTO_INCREMENT,
            application_id int(11) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_url varchar(500) NOT NULL,
            uploaded_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY application_id (application_id),
            KEY uploaded_at (uploaded_at)
        ) {$charsetCollate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($formsTableSql);
        dbDelta($datesTableSql);
        dbDelta($areasTableSql);
        dbDelta($rentalItemsTableSql);
        dbDelta($applicationsTableSql);
        dbDelta($filesTableSql);

        // 既存のテーブルに新しいカラムを追加（アップグレード対応）
        $this->upgradeFormsTable();
        $this->upgradeRentalItemsTable();
        $this->upgradeAreasTable();
        $this->upgradeApplicationsTable();
    }

    /**
     * フォームテーブルのアップグレード
     *
     * @return void
     */
    private function upgradeFormsTable() {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_forms';

        // form_typeカラムの存在チェック
        $formTypeExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'form_type'",
            DB_NAME,
            $tableName
        ));

        if (empty($formTypeExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN form_type enum('マルシェ','ステージ') DEFAULT 'マルシェ' AFTER form_name");
        }

        // dashboard_columnsカラムの存在チェック
        $dashboardColumnsExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'dashboard_columns'",
            DB_NAME,
            $tableName
        ));

        if (empty($dashboardColumnsExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN dashboard_columns longtext DEFAULT NULL AFTER payment_method");
        }
    }

    /**
     * レンタル用品テーブルのアップグレード
     *
     * @return void
     */
    private function upgradeRentalItemsTable() {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_rental_items';

        // field_nameカラムの存在チェック
        $fieldNameExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'field_name'",
            DB_NAME,
            $tableName
        ));

        if (empty($fieldNameExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN field_name varchar(100) NOT NULL DEFAULT '' AFTER item_name");
        }

        // min_quantityカラムの存在チェック
        $minQuantityExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'min_quantity'",
            DB_NAME,
            $tableName
        ));

        if (empty($minQuantityExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN min_quantity int(11) NOT NULL DEFAULT 0 AFTER description");
        }

        // max_quantityカラムの存在チェック
        $maxQuantityExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'max_quantity'",
            DB_NAME,
            $tableName
        ));

        if (empty($maxQuantityExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN max_quantity int(11) NOT NULL DEFAULT 99 AFTER min_quantity");
        }

        // priceカラムの型をdecimal(10,2)からint(11)に変更
        $priceColumnInfo = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'price'",
            DB_NAME,
            $tableName
        ));

        if ($priceColumnInfo && $priceColumnInfo->DATA_TYPE === 'decimal') {
            $wpdb->query("ALTER TABLE {$tableName} MODIFY COLUMN price int(11) NOT NULL DEFAULT 0");
        }
    }

    /**
     * エリアテーブルのアップグレード
     *
     * @return void
     */
    private function upgradeAreasTable() {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_areas';

        // date_idカラムの存在チェック
        $dateIdExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'date_id'",
            DB_NAME,
            $tableName
        ));

        if (empty($dateIdExists)) {
            // date_idカラムを追加
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN date_id int(11) NOT NULL DEFAULT 0 AFTER form_id");

            // 既存データの処理：各フォームの最初の開催日を取得してdate_idを設定
            $datesTable = $wpdb->prefix . 'marche_dates';
            $forms = $wpdb->get_results("SELECT DISTINCT form_id FROM {$tableName}");

            foreach ($forms as $form) {
                $firstDate = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$datesTable} WHERE form_id = %d ORDER BY sort_order, date_value LIMIT 1",
                    $form->form_id
                ));

                if ($firstDate) {
                    $wpdb->update(
                        $tableName,
                        array('date_id' => $firstDate->id),
                        array('form_id' => $form->form_id, 'date_id' => 0),
                        array('%d'),
                        array('%d', '%d')
                    );
                }
            }

            // インデックスを追加
            $wpdb->query("ALTER TABLE {$tableName} ADD INDEX date_id (date_id)");
            $wpdb->query("ALTER TABLE {$tableName} ADD INDEX form_date_idx (form_id, date_id)");
        }

        // capacity_limit_enabledカラムの存在チェック
        $capacityLimitExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'capacity_limit_enabled'",
            DB_NAME,
            $tableName
        ));

        if (empty($capacityLimitExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN capacity_limit_enabled tinyint(1) DEFAULT 1 AFTER capacity");
        }

        // descriptionカラムの存在チェック
        $descriptionExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'description'",
            DB_NAME,
            $tableName
        ));

        if (empty($descriptionExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN description text DEFAULT NULL AFTER sort_order");
        }

        // priceカラムの型をdecimal(10,2)からint(11)に変更
        $priceColumnInfo = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'price'",
            DB_NAME,
            $tableName
        ));

        if ($priceColumnInfo && $priceColumnInfo->DATA_TYPE === 'decimal') {
            $wpdb->query("ALTER TABLE {$tableName} MODIFY COLUMN price int(11) NOT NULL DEFAULT 0");
        }
    }

    /**
     * 申し込みテーブルのアップグレード
     *
     * @return void
     */
    private function upgradeApplicationsTable() {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_applications';

        // files_jsonカラムの存在チェック
        $filesJsonExists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'files_json'",
            DB_NAME,
            $tableName
        ));

        if (empty($filesJsonExists)) {
            $wpdb->query("ALTER TABLE {$tableName} ADD COLUMN files_json longtext DEFAULT NULL AFTER rental_items");
        }
    }

    /**
     * 必要なディレクトリの作成
     *
     * @return void
     */
    private function createDirectories() {
        $directories = array(
            MARCHE_MANAGEMENT_PLUGIN_PATH . 'includes/',
            MARCHE_MANAGEMENT_PLUGIN_PATH . 'admin/',
            MARCHE_MANAGEMENT_PLUGIN_PATH . 'assets/',
            MARCHE_MANAGEMENT_PLUGIN_PATH . 'assets/css/',
            MARCHE_MANAGEMENT_PLUGIN_PATH . 'assets/js/'
        );

        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                wp_mkdir_p($directory);
            }
        }
    }

    /**
     * 初期設定の保存
     *
     * @return void
     */
    private function saveDefaultSettings() {
        // プラグインバージョンの保存
        update_option('marche_management_version', MARCHE_MANAGEMENT_VERSION);

        // 初期設定の保存
        $defaultSettings = array(
            'enable_date_management' => true,
            'enable_area_management' => true,
            'enable_rental_management' => true,
            'default_currency' => 'JPY',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i'
        );

        update_option('marche_management_settings', $defaultSettings);
    }
}

// プラグインの初期化
MarcheManagementPlugin::getInstance();
