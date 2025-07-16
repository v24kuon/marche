<?php
/**
 * ダッシュボード管理画面
 *
 * @package MarcheManagement
 * @subpackage Admin
 * @since 1.0.0
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// 現在のユーザーが管理者権限を持っているかチェック
if (!current_user_can('manage_options')) {
    wp_die(__('このページにアクセスする権限がありません。'));
}

/**
 * ダッシュボード管理クラス
 */
class MarcheDashboardManagement {

    /**
     * ページ表示
     */
    public static function displayPage() {
        // POSTデータの処理
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handlePostRequest();
        }

        // 初期化
        $dataAccess = new MarcheDataAccess();
        $fileManager = new Marche_File_Manager();

        // パラメータ取得
        $selectedFormId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $selectedDateId = isset($_GET['date_id']) ? intval($_GET['date_id']) : 0;
        $activeTab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        $sortBy = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : '';
        $sortOrder = isset($_GET['sort_order']) ? sanitize_text_field($_GET['sort_order']) : 'asc';

        // フォーム一覧の取得
        $forms = self::getAllForms();

        // 選択されたフォームの開催日一覧を取得
        $dates = array();
        if ($selectedFormId > 0) {
            $dates = self::getFormDates($selectedFormId);
        }

        // 統計データの取得
        $statistics = array();
        $applications = array();
        $availableColumns = array();
        $columnSettings = array();

        if ($selectedFormId > 0 && $selectedDateId > 0) {
            $statistics = self::calculateStatistics($selectedFormId, $selectedDateId);
            $applications = self::getApplicationsData($selectedFormId, $selectedDateId, $sortBy, $sortOrder);

            // 既存のレコードで files_json が空の場合、wp_marche_files から補完
            $applications = self::supplementFilesJson($applications);

            $availableColumns = self::getAvailableColumns($selectedFormId, $selectedDateId);
            $columnSettings = self::getColumnSettings($selectedFormId);
        }

        // 表示
        self::displayDashboardPage($forms, $dates, $selectedFormId, $selectedDateId, $activeTab, $statistics, $applications, $availableColumns, $columnSettings, $sortBy, $sortOrder);
    }

    /**
     * POSTリクエストの処理
     */
    private static function handlePostRequest() {
        if (!wp_verify_nonce($_POST['marche_dashboard_nonce'], 'marche_dashboard_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        switch ($action) {
            case 'save_column_settings':
                self::saveColumnSettings();
                break;
            case 'delete_application':
                self::deleteApplication();
                break;
        }
    }

    /**
     * 申し込みデータの削除
     */
    private static function deleteApplication() {
        $applicationId = intval($_POST['application_id']);
        $formId = intval($_POST['form_id']);
        $dateId = intval($_POST['date_id']);

        if (!$applicationId) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>申し込みIDが指定されていません。</p></div>';
            });
            return;
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'marche_applications';

        // 申し込みデータの存在確認
        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d",
            $applicationId
        ));

        if (!$application) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>指定された申し込みが見つかりません。</p></div>';
            });
            return;
        }

        // 関連ファイルの削除
        $files = json_decode($application->files_json, true);
        if (!empty($files) && is_array($files)) {
            foreach ($files as $file) {
                if (isset($file['file_url']) && file_exists($file['file_url'])) {
                    unlink($file['file_url']);
                }
            }
        }

        // データベースから削除
        $result = $wpdb->delete(
            $tableName,
            array('id' => $applicationId),
            array('%d')
        );

                        if ($result !== false) {
            // 削除成功時はWordPressオプションにメッセージを保存
            update_option('marche_delete_success_' . get_current_user_id(), true, false);

            // JavaScript によるリダイレクト（より安全）
            echo '<script type="text/javascript">
                window.location.href = "' . add_query_arg(array(
                    'page' => 'marche-management',
                    'tab' => 'dashboard',
                    'form_id' => $formId,
                    'date_id' => $dateId
                ), admin_url('admin.php')) . '";
            </script>';
            echo '<p>削除が完了しました。リダイレクト中...</p>';
            return;
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>申し込みデータの削除に失敗しました。</p></div>';
            });
        }
    }

    /**
     * 列設定の保存
     */
    private static function saveColumnSettings() {
        $formId = intval($_POST['form_id']);
        $columns = isset($_POST['columns']) ? $_POST['columns'] : array();

        $columnSettings = array();
        foreach ($columns as $column => $enabled) {
            if ($enabled === '1') {
                $columnSettings[] = sanitize_text_field($column);
            }
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'marche_forms';

        $result = $wpdb->update(
            $tableName,
            array('dashboard_columns' => wp_json_encode($columnSettings, JSON_UNESCAPED_UNICODE)),
            array('form_id' => $formId),
            array('%s'),
            array('%d')
        );

        if ($result !== false) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>列設定を保存しました。</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>列設定の保存に失敗しました。</p></div>';
            });
        }
    }

    /**
     * フォーム一覧の取得
     */
    private static function getAllForms() {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_forms';
        return $wpdb->get_results("SELECT id, form_id, form_name FROM {$tableName} ORDER BY form_name", ARRAY_A);
    }

    /**
     * フォームの開催日一覧を取得
     */
    private static function getFormDates($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_dates';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, date_value, description FROM {$tableName} WHERE form_id = %d AND is_active = 1 ORDER BY sort_order, date_value",
            $formId
        ), ARRAY_A);
    }

    /**
     * 統計データの計算
     */
    private static function calculateStatistics($formId, $dateId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_applications';

        // エリア別統計（定員情報を含む）
        $areaStats = $wpdb->get_results($wpdb->prepare(
            "SELECT
                a.area_name,
                COUNT(ap.id) as count,
                a.capacity,
                a.capacity_limit_enabled
             FROM {$wpdb->prefix}marche_areas a
             LEFT JOIN {$tableName} ap ON a.area_name = ap.area_name AND ap.form_id = %d AND ap.date_id = %d
             WHERE a.form_id = %d AND a.date_id = %d AND a.is_active = 1
             GROUP BY a.id, a.area_name, a.capacity, a.capacity_limit_enabled
             ORDER BY a.sort_order, a.area_name",
            $formId, $dateId, $formId, $dateId
        ), ARRAY_A);

        // チラシ枚数合計
        $totalFlyers = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(flyer_number) FROM {$tableName} WHERE form_id = %d AND date_id = %d",
            $formId, $dateId
        ));

        // 車両タイプ統計（booth-carryingが「希望する」の場合のみカウント）
        // Contact Form 7のラジオボタンは配列として送信される場合があるため、配列の最初の要素または単一値を取得
        $lowroofCount = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName}
             WHERE form_id = %d AND date_id = %d
             AND CAST(JSON_UNQUOTE(JSON_EXTRACT(application_data, '$.\"booth-car-height\"')) AS UNSIGNED) <= 1550
             AND (
                 JSON_UNQUOTE(COALESCE(
                     JSON_EXTRACT(application_data, '$.\"booth-carrying\"[0]'),
                     JSON_EXTRACT(application_data, '$.\"booth-carrying\"')
                 )) = '希望する'
             )",
            $formId, $dateId
        ));

        $highroofCount = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tableName}
             WHERE form_id = %d AND date_id = %d
             AND CAST(JSON_UNQUOTE(JSON_EXTRACT(application_data, '$.\"booth-car-height\"')) AS UNSIGNED) > 1550
             AND (
                 JSON_UNQUOTE(COALESCE(
                     JSON_EXTRACT(application_data, '$.\"booth-carrying\"[0]'),
                     JSON_EXTRACT(application_data, '$.\"booth-carrying\"')
                 )) = '希望する'
             )",
            $formId, $dateId
        ));

        // レンタル用品別統計
        $rentalStats = self::calculateRentalStatistics($formId, $dateId);

        return array(
            'area_stats' => $areaStats,
            'total_flyers' => $totalFlyers ?: 0,
            'lowroof_count' => $lowroofCount ?: 0,
            'highroof_count' => $highroofCount ?: 0,
            'rental_stats' => $rentalStats
        );
    }

    /**
     * レンタル用品別統計の計算
     */
    private static function calculateRentalStatistics($formId, $dateId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_applications';

        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT rental_items FROM {$tableName} WHERE form_id = %d AND date_id = %d AND rental_items IS NOT NULL AND rental_items != ''",
            $formId, $dateId
        ), ARRAY_A);

        $rentalTotals = array();

        foreach ($applications as $app) {
            $rentalItems = json_decode($app['rental_items'], true);
            if (is_array($rentalItems)) {
                foreach ($rentalItems as $item) {
                    if (isset($item['item_name']) && isset($item['quantity'])) {
                        $itemName = $item['item_name'];
                        $quantity = intval($item['quantity']);

                        if (!isset($rentalTotals[$itemName])) {
                            $rentalTotals[$itemName] = 0;
                        }
                        $rentalTotals[$itemName] += $quantity;
                    }
                }
            }
        }

        return $rentalTotals;
    }

    /**
     * 申し込みデータの取得（並び替え対応）
     */
    private static function getApplicationsData($formId, $dateId, $sortBy = '', $sortOrder = 'asc') {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_applications';

        // 並び替えクエリの構築
        $orderClause = 'ORDER BY created_at DESC'; // デフォルトは作成日時の降順

        if (!empty($sortBy)) {
            // バリデーション: 並び替え可能な列のみ許可
            $allowedSortColumns = array('booth-location', 'flyer-number', 'booth-carrying', 'created_at');
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = '';
            }
        }

        if (!empty($sortBy)) {
            $sortDirection = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

            switch ($sortBy) {
                case 'booth-location':
                    // エリア名（area_name）で並び替え
                    $orderClause = "ORDER BY area_name {$sortDirection}, created_at DESC";
                    break;
                case 'flyer-number':
                    // チラシ枚数で並び替え
                    $orderClause = "ORDER BY flyer_number {$sortDirection}, created_at DESC";
                    break;
                case 'booth-carrying':
                    // 車で搬入希望で並び替え（JSONから抽出）
                    // 配列の場合は最初の要素を取得し、「希望する」を1、「希望しない」を0として並び替え
                    $orderClause = "ORDER BY CASE
                        WHEN JSON_UNQUOTE(COALESCE(
                            JSON_EXTRACT(application_data, '$.\"booth-carrying\"[0]'),
                            JSON_EXTRACT(application_data, '$.\"booth-carrying\"')
                        )) = '希望する' THEN 1
                        WHEN JSON_UNQUOTE(COALESCE(
                            JSON_EXTRACT(application_data, '$.\"booth-carrying\"[0]'),
                            JSON_EXTRACT(application_data, '$.\"booth-carrying\"')
                        )) = '希望しない' THEN 0
                        ELSE -1
                    END {$sortDirection}, created_at DESC";
                    break;
                case 'created_at':
                    // 申し込み日時で並び替え
                    $orderClause = "ORDER BY created_at {$sortDirection}";
                    break;
            }
        }

        $query = "SELECT * FROM {$tableName} WHERE form_id = %d AND date_id = %d {$orderClause}";

        return $wpdb->get_results($wpdb->prepare(
            $query,
            $formId, $dateId
        ), ARRAY_A);
    }

    /**
     * 既存のレコードで files_json が空の場合、wp_marche_files から補完
     */
    private static function supplementFilesJson($applications) {
        global $wpdb;

        $applicationIds = array_map(function($app) {
            return $app['id'];
        }, $applications);

        if (empty($applicationIds)) {
            return $applications;
        }

        $placeholders = implode(',', array_fill(0, count($applicationIds), '%d'));

        // テーブルの存在確認
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}marche_files'");

        if (!$table_exists) {
            // テーブルが存在しない場合、空のJSONを設定
            foreach ($applications as &$application) {
                if (empty($application['files_json']) || $application['files_json'] === 'null') {
                    $application['files_json'] = wp_json_encode(array(), JSON_UNESCAPED_UNICODE);
                }
            }
            return $applications;
        }

        $files = $wpdb->get_results($wpdb->prepare(
            "SELECT application_id, file_url, file_name as original_name FROM {$wpdb->prefix}marche_files WHERE application_id IN ({$placeholders})",
            $applicationIds
        ), ARRAY_A);

        $filesByApplicationId = array();
        foreach ($files as $file) {
            $filesByApplicationId[$file['application_id']][] = array(
                'file_url' => $file['file_url'],
                'original_name' => $file['original_name']
            );
        }

        foreach ($applications as &$application) {
            // files_json が空または null の場合のみ補完
            if (empty($application['files_json']) || $application['files_json'] === 'null') {
                if (isset($filesByApplicationId[$application['id']])) {
                    $application['files_json'] = wp_json_encode($filesByApplicationId[$application['id']], JSON_UNESCAPED_UNICODE);
                } else {
                    $application['files_json'] = wp_json_encode(array(), JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return $applications;
    }

    /**
     * 利用可能な列の取得
     */
    private static function getAvailableColumns($formId, $dateId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_applications';

        $applications = $wpdb->get_results($wpdb->prepare(
            "SELECT application_data FROM {$tableName} WHERE form_id = %d AND date_id = %d",
            $formId, $dateId
        ), ARRAY_A);

        $availableColumns = array();

        foreach ($applications as $application) {
            $data = json_decode($application['application_data'], true);
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    // _wpcf7から始まるフィールドは除外（ユーザー入力データではないため）
                    if (strpos($key, '_wpcf7') === 0) {
                        continue;
                    }
                    if (!in_array($key, $availableColumns)) {
                        $availableColumns[] = $key;
                    }
                }
            }
        }

        // システム列を追加（データベースの列）
        $systemColumns = array('created_at');
        foreach ($systemColumns as $column) {
            if (!in_array($column, $availableColumns)) {
                $availableColumns[] = $column;
            }
        }

        // 基本的な列を優先順位で並び替え
        $priorityColumns = array('your-name', 'your-email', 'date', 'booth-location', 'your-company', 'your-tel', 'created_at');
        $sortedColumns = array();

        foreach ($priorityColumns as $column) {
            if (in_array($column, $availableColumns)) {
                $sortedColumns[] = $column;
            }
        }

        foreach ($availableColumns as $column) {
            if (!in_array($column, $sortedColumns)) {
                $sortedColumns[] = $column;
            }
        }

        return $sortedColumns;
    }

    /**
     * 列設定の取得
     */
    private static function getColumnSettings($formId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_forms';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT dashboard_columns FROM {$tableName} WHERE form_id = %d",
            $formId
        ));

        if ($result) {
            return json_decode($result, true);
        }

        // デフォルト設定
        return array('your-name', 'your-email', 'booth-location', 'created_at');
    }

    /**
     * ダッシュボードページの表示
     */
    private static function displayDashboardPage($forms, $dates, $selectedFormId, $selectedDateId, $activeTab, $statistics, $applications, $availableColumns, $columnSettings, $sortBy = '', $sortOrder = 'asc') {
        // 削除成功メッセージの表示（WordPressオプションから取得）
        $deleteSuccessKey = 'marche_delete_success_' . get_current_user_id();
        if (get_option($deleteSuccessKey, false)) {
            echo '<div class="notice notice-success is-dismissible"><p>申し込みデータを削除しました。</p></div>';
            delete_option($deleteSuccessKey); // メッセージをクリア
        }
        ?>
        <div class="wrap">
            <h1>ダッシュボード</h1>

            <!-- フィルター -->
            <div class="marche-filter-section">
                <form method="get" action="">
                    <input type="hidden" name="page" value="marche-management">

                    <table class="form-table">
                        <tr>
                            <th scope="row">フォーム選択</th>
                            <td>
                                <select name="form_id" id="form_id" onchange="this.form.submit()">
                                    <option value="0">フォームを選択してください</option>
                                    <?php foreach ($forms as $form): ?>
                                        <option value="<?php echo esc_attr($form['form_id']); ?>"
                                                <?php selected($selectedFormId, $form['form_id']); ?>>
                                            <?php echo esc_html($form['form_name']); ?> (ID: <?php echo esc_html($form['form_id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <?php if ($selectedFormId > 0 && !empty($dates)): ?>
                        <tr>
                            <th scope="row">開催日選択</th>
                            <td>
                                <select name="date_id" id="date_id" onchange="this.form.submit()">
                                    <option value="0">開催日を選択してください</option>
                                    <?php foreach ($dates as $date): ?>
                                        <option value="<?php echo esc_attr($date['id']); ?>"
                                                <?php selected($selectedDateId, $date['id']); ?>>
                                            <?php
                                            echo esc_html($date['date_value']);
                                            if ($date['description']) {
                                                echo ' - ' . esc_html($date['description']);
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </form>
            </div>

            <?php if ($selectedFormId > 0 && $selectedDateId > 0): ?>
                <!-- タブ -->
                <h2 class="nav-tab-wrapper">
                    <a href="?page=marche-management&form_id=<?php echo $selectedFormId; ?>&date_id=<?php echo $selectedDateId; ?>&tab=dashboard"
                       class="nav-tab <?php echo $activeTab === 'dashboard' ? 'nav-tab-active' : ''; ?>">ダッシュボード</a>
                    <a href="?page=marche-management&form_id=<?php echo $selectedFormId; ?>&date_id=<?php echo $selectedDateId; ?>&tab=settings"
                       class="nav-tab <?php echo $activeTab === 'settings' ? 'nav-tab-active' : ''; ?>">列設定</a>
                    <?php if ($activeTab === 'detail'): ?>
                        <a href="#" class="nav-tab nav-tab-active">申し込み詳細</a>
                    <?php endif; ?>
                </h2>

                <?php if ($activeTab === 'dashboard'): ?>
                    <!-- 統計情報 -->
                    <div class="marche-statistics-section">
                        <div class="marche-stats-grid">
                            <div class="marche-stat-box">
                                <h3>エリア別申し込み数</h3>
                                <div class="marche-stat-content">
                                    <?php if (!empty($statistics['area_stats'])): ?>
                                        <?php foreach ($statistics['area_stats'] as $areaStat): ?>
                                            <div class="marche-stat-item">
                                                <span class="marche-stat-label"><?php echo esc_html($areaStat['area_name']); ?></span>
                                                <span class="marche-stat-value">
                                                    <?php
                                                    $count = intval($areaStat['count']);
                                                    $capacity = intval($areaStat['capacity']);
                                                    $capacityEnabled = boolval($areaStat['capacity_limit_enabled']);

                                                    if ($capacityEnabled && $capacity > 0) {
                                                        // 定員制限が有効で定員が設定されている場合
                                                        echo esc_html($count . '/' . $capacity . ' 件');
                                                    } else {
                                                        // 定員制限が無効または定員が設定されていない場合
                                                        echo esc_html($count . ' 件');
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>データがありません</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="marche-stat-box">
                                <h3>レンタル用品別数量</h3>
                                <div class="marche-stat-content">
                                    <?php if (!empty($statistics['rental_stats'])): ?>
                                        <?php foreach ($statistics['rental_stats'] as $item => $quantity): ?>
                                            <div class="marche-stat-item">
                                                <span class="marche-stat-label"><?php echo esc_html($item); ?></span>
                                                <span class="marche-stat-value"><?php echo esc_html($quantity); ?>個</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>データがありません</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="marche-stat-box">
                                <h3>その他統計</h3>
                                <div class="marche-stat-content">
                                    <div class="marche-stat-item">
                                        <span class="marche-stat-label">チラシ枚数合計</span>
                                        <span class="marche-stat-value"><?php echo esc_html($statistics['total_flyers']); ?>枚</span>
                                    </div>
                                    <div class="marche-stat-item">
                                        <span class="marche-stat-label">ロールーフ車両</span>
                                        <span class="marche-stat-value"><?php echo esc_html($statistics['lowroof_count']); ?>台</span>
                                    </div>
                                    <div class="marche-stat-item">
                                        <span class="marche-stat-label">ハイルーフ車両</span>
                                        <span class="marche-stat-value"><?php echo esc_html($statistics['highroof_count']); ?>台</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 申し込み一覧テーブル -->
                    <div class="marche-table-section">
                        <h2>申し込み一覧</h2>

                        <?php if (empty($applications)): ?>
                            <div class="notice notice-info">
                                <p>選択された条件に該当する申し込みはありません。</p>
                            </div>
                        <?php else: ?>
                            <?php self::displayApplicationsTable($applications, $columnSettings, $selectedFormId, $selectedDateId, $sortBy, $sortOrder); ?>
                        <?php endif; ?>
                    </div>

                <?php elseif ($activeTab === 'settings'): ?>
                    <!-- 列設定 -->
                    <div class="marche-settings-section">
                        <h2>列設定</h2>
                        <form method="post" action="">
                            <?php wp_nonce_field('marche_dashboard_action', 'marche_dashboard_nonce'); ?>
                            <input type="hidden" name="action" value="save_column_settings">
                            <input type="hidden" name="form_id" value="<?php echo esc_attr($selectedFormId); ?>">

                            <table class="form-table">
                                <tr>
                                    <th scope="row">表示する列を選択</th>
                                    <td>
                                        <p class="description">ダッシュボードテーブルに表示する列を選択してください。画像列は常に表示されます。</p>
                                        <?php foreach ($availableColumns as $column): ?>
                                            <label>
                                                <input type="checkbox"
                                                       name="columns[<?php echo esc_attr($column); ?>]"
                                                       value="1"
                                                       <?php checked(in_array($column, $columnSettings)); ?>>
                                                <?php echo esc_html(self::getColumnLabel($column)); ?>
                                                <small>(<?php echo esc_html($column); ?>)</small>
                                            </label><br>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            </table>

                            <?php submit_button('設定を保存'); ?>
                        </form>
                    </div>
                <?php elseif ($activeTab === 'detail'): ?>
                    <!-- 申し込み詳細 -->
                    <?php self::displayApplicationDetail(); ?>
                <?php endif; ?>

            <?php else: ?>
                <div class="notice notice-warning">
                    <p>フォームと開催日を選択してください。</p>
                </div>
            <?php endif; ?>
        </div>

        <?php self::displayDashboardCSS(); ?>
        <?php
    }

    /**
     * 申し込み一覧テーブルの表示（並び替え対応）
     */
    private static function displayApplicationsTable($applications, $columnSettings, $selectedFormId, $selectedDateId, $sortBy = '', $sortOrder = 'asc') {
        // 並び替え可能な列の定義
        $sortableColumns = array('booth-location', 'flyer-number', 'booth-carrying');

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 80px;">画像</th>
                    <?php foreach ($columnSettings as $column): ?>
                        <th class="<?php echo in_array($column, $sortableColumns) ? 'marche-sortable-column' : ''; ?>">
                            <?php if (in_array($column, $sortableColumns)): ?>
                                <?php
                                // 並び替えリンクの作成
                                $nextOrder = ($sortBy === $column && $sortOrder === 'asc') ? 'desc' : 'asc';
                                $sortUrl = add_query_arg(array(
                                    'page' => 'marche-management',
                                    'tab' => 'dashboard',
                                    'form_id' => $selectedFormId,
                                    'date_id' => $selectedDateId,
                                    'sort_by' => $column,
                                    'sort_order' => $nextOrder
                                ), admin_url('admin.php'));

                                $currentSort = ($sortBy === $column);
                                $sortIcon = '';
                                if ($currentSort) {
                                    $sortIcon = $sortOrder === 'asc' ? ' ↑' : ' ↓';
                                }
                                ?>
                                <a href="<?php echo esc_url($sortUrl); ?>" class="marche-sort-link <?php echo $currentSort ? 'marche-sort-active' : ''; ?>">
                                    <?php echo esc_html(self::getColumnLabel($column)); ?><?php echo $sortIcon; ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html(self::getColumnLabel($column)); ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $application):
                    $applicationData = json_decode($application['application_data'], true);
                    $files = json_decode($application['files_json'], true);

                    // デバッグ: files_json の内容をログ出力
                ?>
                    <tr>
                        <td class="marche-image-cell">
                            <?php
                            $detailUrl = add_query_arg(array(
                                'page' => 'marche-management',
                                'tab' => 'detail',
                                'application_id' => $application['id'],
                                'form_id' => $selectedFormId,
                                'date_id' => $selectedDateId
                            ), admin_url('admin.php'));
                            ?>

                            <?php if (!empty($files) && is_array($files)):
                                $firstFile = $files[0];
                                $isImage = in_array(strtolower(pathinfo($firstFile['original_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);
                            ?>
                                <a href="<?php echo esc_url($detailUrl); ?>" class="marche-detail-link">
                                    <?php if ($isImage): ?>
                                        <img src="<?php echo esc_url($firstFile['file_url']); ?>"
                                             alt="<?php echo esc_attr($firstFile['original_name']); ?>"
                                             class="marche-thumbnail">
                                    <?php else: ?>
                                        <span class="dashicons dashicons-media-default marche-file-icon"></span>
                                    <?php endif; ?>
                                </a>
                                <div class="marche-download-btn">
                                    <a href="<?php echo esc_url($firstFile['file_url']); ?>"
                                       download="<?php echo esc_attr($firstFile['original_name']); ?>"
                                       class="button button-small">ダウンロード</a>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo esc_url($detailUrl); ?>" class="marche-detail-link">
                                    <span class="marche-no-image">画像なし</span>
                                </a>
                                <?php

                                ?>
                            <?php endif; ?>
                        </td>

                        <?php foreach ($columnSettings as $column): ?>
                            <td>
                                <div class="marche-mobile-label"><?php echo esc_html(self::getColumnLabel($column)); ?></div>
                                <?php
                                // システム列（データベースの列）かどうかを判定
                                if ($column === 'created_at') {
                                    // 申し込み日時を日本時間で表示
                                    $timezone = get_option('timezone_string');
                                    if (empty($timezone)) {
                                        $timezone = 'Asia/Tokyo';
                                    }

                                    $date = new DateTime($application[$column], new DateTimeZone('UTC'));
                                    $date->setTimezone(new DateTimeZone($timezone));
                                    $value = $date->format('Y-m-d H:i');
                                } else {
                                    // 通常のフィールド（JSONデータから取得）
                                    $value = isset($applicationData[$column]) ? $applicationData[$column] : '';

                                    // 配列の場合は適切に文字列化
                                    if (is_array($value)) {
                                        if (count($value) === 1) {
                                            $value = $value[0]; // 単一要素の配列の場合
                                        } else {
                                            $value = implode(', ', $value); // 複数要素の場合はカンマ区切り
                                        }
                                    }
                                }

                                echo esc_html($value);
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * 列ラベルの取得
     */
    private static function getColumnLabel($column) {
        $labels = array(
            // 基本情報
            'your-name' => '申し込み者名',
            'your-email' => 'メールアドレス',
            'your-company' => '会社名',
            'your-tel' => '電話番号',
            'your-address' => '住所',

            // 開催日・エリア
            'date' => '開催日',
            'booth-location' => 'エリア',

            // ブース情報
            'booth-name' => 'ブース名',
            'booth-summary' => 'ブース概要',
            'booth-detail' => 'ブース詳細',
            'booth-generator' => '発電機',
            'booth-carrying' => '車搬入希望',
            'booth-car-type' => '車両タイプ',
            'booth-car-height' => '車両高さ',

            // チラシ・配布関連
            'flyer-number' => 'チラシ枚数',
            'flyer-image' => 'チラシ画像',
            'flyer-desired' => 'チラシ配布希望',
            'flyer-zip' => 'チラシ郵便番号',
            'flyer-address' => 'チラシ住所',

            // その他
            'other-know' => '知ったきっかけ',
            'other-remarks' => '備考',
            'other-terms' => '利用規約同意',

            // 支払い
            'payment-method' => '支払い方法',

            // システム列
            'created_at' => '申し込み日時'
        );

        return isset($labels[$column]) ? $labels[$column] : $column;
    }

    /**
     * ダッシュボードCSSの表示（統合済み）
     *
     * CSSは assets/css/admin.css に統合されました
     */
    private static function displayDashboardCSS() {
        // CSSは assets/css/admin.css に統合済み
    }

        /**
     * 申し込み詳細の表示
     */
    private static function displayApplicationDetail() {
        $applicationId = isset($_GET['application_id']) ? intval($_GET['application_id']) : 0;
        $selectedFormId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $selectedDateId = isset($_GET['date_id']) ? intval($_GET['date_id']) : 0;

        if (!$applicationId) {
            echo '<div class="notice notice-error"><p>申し込みIDが指定されていません。</p></div>';
            return;
        }

        if (!$selectedFormId || !$selectedDateId) {
            echo '<div class="notice notice-error"><p>フォームIDまたは開催日IDが指定されていません。</p></div>';
            return;
        }

        // 申し込み詳細データの取得
        $application = self::getApplicationDetail($applicationId);

        if (!$application) {
            echo '<div class="notice notice-error"><p>指定された申し込みが見つかりません。</p></div>';
            return;
        }

        $applicationData = json_decode($application['application_data'], true);
        $files = json_decode($application['files_json'], true);
        $rentalItems = json_decode($application['rental_items'], true);

        ?>
        <div class="marche-application-detail">
            <div class="marche-detail-header">
                <h3>申し込み詳細 - ID: <?php echo esc_html($applicationId); ?></h3>
                <p><strong>申し込み日時:</strong> <?php
                    // WordPressのタイムゾーン設定を取得
                    $timezone = get_option('timezone_string');
                    if (empty($timezone)) {
                        $timezone = 'Asia/Tokyo'; // デフォルトで日本時間
                    }

                    // 日時を日本時間で表示
                    $date = new DateTime($application['created_at'], new DateTimeZone('UTC'));
                    $date->setTimezone(new DateTimeZone($timezone));
                    echo esc_html($date->format('Y年n月j日 H:i:s'));
                ?></p>

                <div class="marche-detail-actions">
                    <!-- 戻るボタン -->
                    <?php
                    $backUrl = add_query_arg(array(
                        'page' => 'marche-management',
                        'tab' => 'dashboard',
                        'form_id' => $selectedFormId,
                        'date_id' => $selectedDateId
                    ), admin_url('admin.php'));
                    ?>
                    <a href="<?php echo esc_url($backUrl); ?>" class="button">← ダッシュボードに戻る</a>

                    <!-- 削除ボタン -->
                    <form method="post" action="" style="display: inline-block; margin-left: 10px;" onsubmit="return confirm('この申し込みデータを削除しますか？\n\n※ この操作は取り消せません。\n※ 関連するファイルも削除されます。');">
                        <?php wp_nonce_field('marche_dashboard_action', 'marche_dashboard_nonce'); ?>
                        <input type="hidden" name="action" value="delete_application">
                        <input type="hidden" name="application_id" value="<?php echo esc_attr($applicationId); ?>">
                        <input type="hidden" name="form_id" value="<?php echo esc_attr($selectedFormId); ?>">
                        <input type="hidden" name="date_id" value="<?php echo esc_attr($selectedDateId); ?>">
                        <button type="submit" class="button button-link-delete">🗑️ 申し込みを削除</button>
                    </form>
                </div>
            </div>

                        <div class="marche-detail-grid">
                <!-- 全送信データ -->
                <div class="marche-detail-section marche-full-width">
                    <h4>送信データ</h4>
                    <div class="marche-data-grid">
                        <?php
                        // 重要な項目を優先順位順に表示
                        $priorityFields = array('your-name', 'your-email', 'date', 'booth-location', 'your-company', 'your-tel', 'your-address');
                        $displayedFields = array();

                        // 優先項目を先に表示
                        foreach ($priorityFields as $field) {
                            if (isset($applicationData[$field])) {
                                $value = $applicationData[$field];
                                $displayedFields[] = $field;
                                ?>
                                <div class="marche-data-item marche-priority-item">
                                    <div class="marche-data-label">
                                        <?php echo esc_html(self::getColumnLabel($field)); ?>
                                        <small class="marche-field-name"><?php echo esc_html($field); ?></small>
                                    </div>
                                    <div class="marche-data-value">
                                        <?php
                                        if (is_array($value)) {
                                            if (count($value) === 1) {
                                                echo esc_html($value[0]);
                                            } else {
                                                echo esc_html(implode(', ', $value));
                                            }
                                        } else {
                                            echo esc_html($value ?: '未入力');
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php
                            }
                        }

                        // その他の項目を表示
                        foreach ($applicationData as $key => $value) {
                            if (strpos($key, '_wpcf7') === 0) continue; // _wpcf7フィールドはスキップ
                            if (strpos($key, 'rental-') === 0) continue; // レンタル用品フィールドはスキップ（専用セクションで表示）
                            if (in_array($key, $displayedFields)) continue; // 既に表示済みの項目はスキップ
                            ?>
                            <div class="marche-data-item">
                                <div class="marche-data-label">
                                    <?php echo esc_html(self::getColumnLabel($key)); ?>
                                    <small class="marche-field-name"><?php echo esc_html($key); ?></small>
                                </div>
                                <div class="marche-data-value">
                                    <?php
                                    if (is_array($value)) {
                                        if (count($value) === 1) {
                                            echo esc_html($value[0]);
                                        } else {
                                            echo esc_html(implode(', ', $value));
                                        }
                                    } else {
                                        echo esc_html($value ?: '未入力');
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>

                <!-- レンタル用品情報 -->
                <?php
                // 登録されている全レンタル用品を取得し、数量を表示（0も含む）
                $formId = $application['form_id'];
                $allRentalItems = self::getAllRentalItemsWithQuantity($formId, $applicationData, $rentalItems);
                ?>
                <?php if (!empty($allRentalItems)): ?>
                <div class="marche-detail-section marche-full-width">
                    <h4>レンタル用品</h4>
                    <div class="marche-rental-grid">
                        <?php foreach ($allRentalItems as $item): ?>
                            <div class="marche-rental-item">
                                <div class="marche-rental-name"><?php echo esc_html($item['item_name']); ?></div>
                                <div class="marche-rental-quantity"><?php echo esc_html($item['quantity']); ?><?php echo esc_html($item['unit']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ファイル情報 -->
                <?php if (!empty($files) && is_array($files)): ?>
                <div class="marche-detail-section marche-full-width">
                    <h4>アップロードファイル</h4>
                    <div class="marche-files-grid">
                        <?php foreach ($files as $file): ?>
                            <div class="marche-file-item">
                                <p><strong>ファイル名:</strong> <?php echo esc_html($file['original_name']); ?></p>
                                <?php
                                $isImage = in_array(strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);
                                if ($isImage):
                                ?>
                                    <img src="<?php echo esc_url($file['file_url']); ?>"
                                         alt="<?php echo esc_attr($file['original_name']); ?>"
                                         style="max-width: 300px; height: auto; border: 1px solid #ddd; border-radius: 4px;">
                                <?php else: ?>
                                    <span class="dashicons dashicons-media-default" style="font-size: 48px;"></span>
                                <?php endif; ?>
                                <p><a href="<?php echo esc_url($file['file_url']); ?>" target="_blank" class="button">ダウンロード</a></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>


        <?php
    }

    /**
     * 申し込み詳細データの取得
     */
    private static function getApplicationDetail($applicationId) {
        global $wpdb;

        $tableName = $wpdb->prefix . 'marche_applications';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE id = %d",
            $applicationId
        ), ARRAY_A);
    }

    /**
     * 全レンタル用品の数量を取得（0も含む）
     *
     * @param int $formId フォームID
     * @param array $applicationData 申し込みデータ
     * @param array $rentalItems 保存されているレンタル用品データ
     * @return array 全レンタル用品の数量
     */
    private static function getAllRentalItemsWithQuantity($formId, $applicationData, $rentalItems) {
        global $wpdb;

        // フォームに登録されている全レンタル用品を取得
        $tableName = $wpdb->prefix . 'marche_rental_items';
        $allRentals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tableName} WHERE form_id = %d AND is_active = 1 ORDER BY sort_order, item_name",
            $formId
        ));

        $result = array();

        foreach ($allRentals as $rental) {
            $fieldName = 'rental-' . $rental->field_name;
            $quantity = 0;

            // まず application_data から数量を取得
            if (isset($applicationData[$fieldName])) {
                $value = $applicationData[$fieldName];
                if (is_array($value)) {
                    $quantity = isset($value[0]) && is_numeric($value[0]) ? intval($value[0]) : 0;
                } elseif (is_numeric($value)) {
                    $quantity = intval($value);
                }
            }

            // レンタル用品データを配列に追加（0も含む）
            $result[] = array(
                'item_name' => $rental->item_name,
                'quantity' => $quantity,
                'unit' => $rental->unit,
                'field_name' => $rental->field_name
            );
        }

        return $result;
    }
}

// ページの表示
MarcheDashboardManagement::displayPage();
