<?php
/**
 * 画像管理画面
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

// パラメータ取得
$selectedFormId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$selectedDateId = isset($_GET['date_id']) ? intval($_GET['date_id']) : 0;

// 必要なクラスの初期化
$dataAccess = new MarcheDataAccess();
$fileManager = new Marche_File_Manager();

// フォーム一覧の取得
$forms = getAllForms();

// 選択されたフォームの開催日一覧を取得
$dates = array();
if ($selectedFormId > 0) {
    $dates = getFormDates($selectedFormId);
}

// 画像一覧の取得
$files = array();
if ($selectedFormId > 0) {
    $files = $fileManager->getFilesByFormAndDate($selectedFormId, $selectedDateId);
}

/**
 * フォーム一覧の取得
 *
 * @return array フォーム一覧
 */
function getAllForms() {
    global $wpdb;

    $tableName = $wpdb->prefix . 'marche_forms';
    return $wpdb->get_results("SELECT id, form_id, form_name FROM {$tableName} ORDER BY form_name", ARRAY_A);
}

/**
 * フォームの開催日一覧を取得
 *
 * @param int $formId フォームID
 * @return array 開催日一覧
 */
function getFormDates($formId) {
    global $wpdb;

    $tableName = $wpdb->prefix . 'marche_dates';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, date_value, description FROM {$tableName} WHERE form_id = %d AND is_active = 1 ORDER BY sort_order, date_value",
        $formId
    ), ARRAY_A);
}



/**
 * 申し込み情報の取得
 *
 * @param int $applicationId 申し込みID
 * @return array|null 申し込み情報
 */
function getApplicationInfo($applicationId) {
    global $wpdb;

    $tableName = $wpdb->prefix . 'marche_applications';
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tableName} WHERE id = %d",
        $applicationId
    ));

    if (!$result) {
        return null;
    }

    $applicationData = json_decode($result->application_data, true);
    return array(
        'id' => $result->id,
        'customer_name' => isset($applicationData['your-name']) ? $applicationData['your-name'] : '',
        'customer_email' => isset($applicationData['your-email']) ? $applicationData['your-email'] : '',
        'area_name' => $result->area_name,
        'application_date' => $result->created_at,
        'application_data' => $applicationData
    );
}

?>

<div class="wrap">
    <h1>画像管理</h1>

    <!-- フィルター -->
    <div class="marche-filter-section">
        <form method="get" action="">
            <input type="hidden" name="page" value="marche-image-management">

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
                            <option value="0">すべての開催日</option>
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

    <?php if ($selectedFormId > 0): ?>
        <div class="marche-files-section">
            <h2>アップロード画像一覧</h2>

            <?php if (empty($files)): ?>
                <div class="notice notice-info">
                    <p>選択された条件に該当する画像はありません。</p>
                </div>
            <?php else: ?>
                <div class="marche-files-summary">
                    <p><strong>画像数:</strong> <?php echo count($files); ?>件</p>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>画像</th>
                            <th>ファイル名</th>
                            <th>申し込み者情報</th>
                            <th>申し込み日時</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file):
                            $applicationInfo = getApplicationInfo($file['application_id']);
                            $isImage = in_array(strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);
                        ?>
                            <tr>
                                <td>
                                    <?php if ($isImage): ?>
                                        <img src="<?php echo esc_url($file['file_url']); ?>"
                                             alt="<?php echo esc_attr($file['file_name']); ?>"
                                             style="max-width: 100px; max-height: 100px; object-fit: cover;">
                                    <?php else: ?>
                                        <span class="dashicons dashicons-media-default" style="font-size: 32px; color: #ccc;"></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($file['file_name']); ?></strong><br>
                                    <small>アップロード: <?php
                                        // WordPressのタイムゾーン設定を取得
                                        $timezone = get_option('timezone_string');
                                        if (empty($timezone)) {
                                            $timezone = 'Asia/Tokyo'; // デフォルトで日本時間
                                        }

                                        // 日時を日本時間で表示
                                        $date = new DateTime($file['uploaded_at'], new DateTimeZone('UTC'));
                                        $date->setTimezone(new DateTimeZone($timezone));
                                        echo esc_html($date->format('Y-m-d H:i'));
                                    ?></small>
                                </td>
                                <td>
                                    <?php if ($applicationInfo): ?>
                                        <strong><?php echo esc_html($applicationInfo['customer_name']); ?></strong><br>
                                        <small><?php echo esc_html($applicationInfo['customer_email']); ?></small><br>
                                        <small>エリア: <?php echo esc_html($applicationInfo['area_name']); ?></small>
                                    <?php else: ?>
                                        <span class="description">情報取得エラー</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        // WordPressのタイムゾーン設定を取得
                                        $timezone = get_option('timezone_string');
                                        if (empty($timezone)) {
                                            $timezone = 'Asia/Tokyo'; // デフォルトで日本時間
                                        }

                                        // 日時を日本時間で表示
                                        $date = new DateTime($file['application_date'], new DateTimeZone('UTC'));
                                        $date->setTimezone(new DateTimeZone($timezone));
                                        echo esc_html($date->format('Y-m-d H:i'));
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($file['file_url']); ?>"
                                       target="_blank"
                                       class="button button-small">表示</a>
                                    <a href="<?php echo esc_url($file['file_url']); ?>"
                                       download="<?php echo esc_attr($file['file_name']); ?>"
                                       class="button button-small">ダウンロード</a>
                                    <button type="button"
                                            class="button button-small marche-show-details"
                                            data-file-id="<?php echo esc_attr($file['id']); ?>"
                                            data-application-id="<?php echo esc_attr($file['application_id']); ?>">詳細</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="notice notice-warning">
            <p>フォームを選択してください。</p>
        </div>
    <?php endif; ?>
</div>

<!-- 詳細表示モーダル -->
<div id="marche-file-details-modal" style="display: none;">
    <div class="marche-modal-overlay">
        <div class="marche-modal-content">
            <div class="marche-modal-header">
                <h3>画像詳細情報</h3>
                <button type="button" class="marche-modal-close">&times;</button>
            </div>
            <div class="marche-modal-body">
                <div id="marche-file-details-content">
                    <!-- AJAX で読み込まれるコンテンツ -->
                </div>
            </div>
        </div>
    </div>
</div>



<script>
document.addEventListener('DOMContentLoaded', () => {
    // 詳細表示ボタンのイベントハンドラ
    const detailButtons = document.querySelectorAll('.marche-show-details');
    const modal = document.getElementById('marche-file-details-modal');
    const modalContent = document.getElementById('marche-file-details-content');
    const closeButton = document.querySelector('.marche-modal-close');

    detailButtons.forEach(button => {
        button.addEventListener('click', async (e) => {
            e.preventDefault();

            const fileId = button.dataset.fileId;
            const applicationId = button.dataset.applicationId;

            // モーダル表示
            modal.style.display = 'block';
            modalContent.innerHTML = '<p>読み込み中...</p>';

            try {
                // AJAX で詳細情報を取得
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'marche_get_file_details',
                        file_id: fileId,
                        application_id: applicationId,
                        nonce: '<?php echo wp_create_nonce('marche_file_details'); ?>'
                    })
                });

                const data = await response.json();

                if (data.success) {
                    modalContent.innerHTML = data.data.html;
                } else {
                    modalContent.innerHTML = '<p>エラー: 詳細情報の取得に失敗しました。</p>';
                }
            } catch (error) {
                modalContent.innerHTML = '<p>エラー: 通信に失敗しました。</p>';
            }
        });
    });

    // モーダルクローズ
    closeButton.addEventListener('click', () => {
        modal.style.display = 'none';
    });

    // オーバーレイクリックでクローズ
    modal.addEventListener('click', (e) => {
        if (e.target === modal || e.target.classList.contains('marche-modal-overlay')) {
            modal.style.display = 'none';
        }
    });
});
</script>
