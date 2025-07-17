<?php
/**
 * ファイル管理クラス
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
 * ファイル管理機能を担当するクラス
 *
 * @class Marche_File_Manager
 * @description 画像保存、URL生成、ディレクトリ管理を担当
 */
class Marche_File_Manager {

    /**
     * ベースアップロードディレクトリ
     */
    const BASE_UPLOAD_DIR = 'marche';

    /**
     * 初期化
     */
    public function init() {
        // Contact Form 7の送信完了フック（申し込みデータ保存の後に実行）
        add_action('wpcf7_mail_sent', array($this, 'handleFileUpload'), 30, 1);

        // メールタグフィルター
        add_filter('wpcf7_mail_tag_replaced', array($this, 'replaceFileUrlsTag'), 10, 4);
    }

    /**
     * Contact Form 7ファイルアップロード処理
     *
     * @param WPCF7_ContactForm $contact_form Contact Form 7オブジェクト
     * @return void
     */
    public function handleFileUpload($contact_form) {
        try {
            $form_id = $contact_form->id();

            // 送信インスタンスの取得
            $submission = WPCF7_Submission::get_instance();
            if (!$submission) {
                return;
            }

            // Contact Form 7のアップロードファイル取得
            $uploaded_files = $submission->uploaded_files();
            if (empty($uploaded_files)) {
                return;
            }

            // 送信データの取得
            $posted_data = $submission->get_posted_data();
            if (empty($posted_data)) {
                return;
            }

            $this->processFileUpload($form_id, $uploaded_files, $posted_data);

        } catch (Exception $e) {
            // エラーログは必要に応じてコメントアウトを外す
        }
    }

    /**
     * 新しいファイル処理メソッド
     *
     * @param int $form_id フォームID
     * @param array $uploaded_files アップロードファイル
     * @param array $posted_data 送信データ
     * @return void
     */
    private function processFileUpload($form_id, $uploaded_files, $posted_data) {
        // ブース名の取得（Flamingo スタイルで堅牢化）
        $booth_name = '';
        if (isset($posted_data['booth-name'])) {
            $booth_name = is_array($posted_data['booth-name']) ?
                sanitize_text_field($posted_data['booth-name'][0]) :
                sanitize_text_field($posted_data['booth-name']);
        }

        $date_value = '';
        if (isset($posted_data['date'])) {
            $date_value = is_array($posted_data['date']) ?
                sanitize_text_field($posted_data['date'][0]) :
                sanitize_text_field($posted_data['date']);
        }

        if (empty($booth_name) || empty($date_value)) {
            return;
        }

        // 最新の申し込みIDを取得（複数回試行）
        $application_id = $this->getLatestApplicationId($form_id);
        if (!$application_id) {
            // 少し待ってからもう一度試行
            sleep(1);
            $application_id = $this->getLatestApplicationId($form_id);
        }

        if (!$application_id) {
            return;
        }

        // ファイル保存処理（Contact Form 7 アップロードファイル形式）
        $saved_files = $this->saveSubmissionFiles($uploaded_files, $date_value, $booth_name, $application_id);

        if (empty($saved_files)) {
            return;
        }

        // 申し込みデータにファイル情報を追加
        $this->updateApplicationWithFiles($application_id, $saved_files);
    }

    /**
     * 最新の申し込みIDを取得
     *
     * @param int $form_id フォームID
     * @return int|false 申し込みID
     */
    private function getLatestApplicationId($form_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_applications';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE form_id = %d ORDER BY created_at DESC LIMIT 1",
            $form_id
        ));

        return $result ? (int)$result : false;
    }

    /**
     * Contact Form 7 submission ファイルの保存処理
     *
     * @param array  $uploaded_files Contact Form 7のアップロードファイル配列
     * @param string $date_value 開催日
     * @param string $booth_name ブース名
     * @param int    $application_id 申し込みID
     * @return array 保存されたファイル情報
     */
    private function saveSubmissionFiles($uploaded_files, $date_value, $booth_name, $application_id) {
        $saved_files = array();

        // 保存ディレクトリの作成
        $save_directory = $this->createSaveDirectory($date_value, $booth_name);
        if (!$save_directory) {
            throw new Exception('Failed to create save directory');
        }

        foreach ($uploaded_files as $field_name => $temp_files) {
            // ファイルが配列でない場合は配列に変換
            if (!is_array($temp_files)) {
                $temp_files = array($temp_files);
            }

            foreach ($temp_files as $temp_file_path) {
                if (empty($temp_file_path) || !file_exists($temp_file_path)) {
                    continue;
                }

                $file_data = array(
                    'field_name' => $field_name,
                    'name' => basename($temp_file_path),
                    'tmp_name' => $temp_file_path,
                    'type' => mime_content_type($temp_file_path),
                    'size' => filesize($temp_file_path)
                );

                $saved_file = $this->saveFile($file_data, $save_directory, $application_id);
                if ($saved_file) {
                    $saved_files[] = $saved_file;
                }
            }
        }

        return $saved_files;
    }

    /**
     * 保存ディレクトリの作成
     *
     * @param string $date_value 開催日
     * @param string $booth_name ブース名
     * @return string|false ディレクトリパス
     */
    private function createSaveDirectory($date_value, $booth_name) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/' . self::BASE_UPLOAD_DIR;

        // 開催日ディレクトリ
        $date_dir = $base_dir . '/' . sanitize_file_name($date_value);
        if (!wp_mkdir_p($date_dir)) {
            return false;
        }

        // ブース名ディレクトリ（連番付き）
        $booth_dir_base = $date_dir . '/' . sanitize_file_name($booth_name);
        $booth_dir = $booth_dir_base;
        $counter = 1;

        // 同名ディレクトリが存在する場合は連番を追加
        while (file_exists($booth_dir)) {
            $booth_dir = $booth_dir_base . '_' . $counter;
            $counter++;
        }

        if (!wp_mkdir_p($booth_dir)) {
            return false;
        }

        return $booth_dir;
    }

    /**
     * 個別ファイル保存処理
     *
     * @param array  $file_data ファイルデータ
     * @param string $save_directory 保存ディレクトリ
     * @param int    $application_id 申し込みID
     * @return array|false 保存されたファイル情報
     */
    private function saveFile($file_data, $save_directory, $application_id) {
        // セキュアなファイル名生成
        $filename = $this->generateSecureFilename($file_data['name']);
        $file_path = $save_directory . '/' . $filename;


        // ファイル移動（Contact Form 7の一時ファイルは rename ではなく copy + unlink を使用）
        if (!copy($file_data['tmp_name'], $file_path)) {
            return false;
        }

        // 元の一時ファイルを削除
        if (file_exists($file_data['tmp_name'])) {
            unlink($file_data['tmp_name']);
        }

        // ファイル権限設定
        chmod($file_path, 0644);


        // URL生成
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        $file_url = $upload_dir['baseurl'] . $relative_path;


        // データベースに保存
        $file_id = $this->saveFileToDatabase($application_id, $file_data['name'], $file_path, $file_url);

        if (!$file_id) {
            // ファイル削除
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            return false;
        }


        return array(
            'id' => $file_id,
            'original_name' => $file_data['name'],
            'saved_name' => $filename,
            'file_path' => $file_path,
            'file_url' => $file_url,
            'field_name' => $file_data['field_name']
        );
    }

    /**
     * セキュアなファイル名生成
     *
     * @param string $original_name 元のファイル名
     * @return string セキュアなファイル名
     */
    private function generateSecureFilename($original_name) {
        $pathinfo = pathinfo($original_name);
        $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
        $basename = sanitize_file_name($pathinfo['filename']);

        // タイムスタンプを追加してユニークにする
        $timestamp = date('YmdHis');
        $random = wp_generate_password(8, false);

        return $basename . '_' . $timestamp . '_' . $random . $extension;
    }

    /**
     * ファイル情報をデータベースに保存
     *
     * @param int    $application_id 申し込みID
     * @param string $file_name 元のファイル名
     * @param string $file_path ファイルパス
     * @param string $file_url ファイルURL
     * @return int|false ファイルID
     */
    private function saveFileToDatabase($application_id, $file_name, $file_path, $file_url) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_files';
        $result = $wpdb->insert(
            $table_name,
            array(
                'application_id' => $application_id,
                'file_name' => $file_name,
                'file_path' => $file_path,
                'file_url' => $file_url
            ),
            array('%d', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 申し込みデータにファイル情報を追加
     *
     * @param int   $application_id 申し込みID
     * @param array $saved_files 保存されたファイル情報
     * @return void
     */
    private function updateApplicationWithFiles($application_id, $saved_files) {
        global $wpdb;


        $files_data = array();
        foreach ($saved_files as $file) {
            $files_data[] = array(
                'id' => $file['id'],
                'original_name' => $file['original_name'],
                'file_url' => $file['file_url'],
                'field_name' => $file['field_name']
            );
        }

        $files_json = wp_json_encode($files_data, JSON_UNESCAPED_UNICODE);

        $table_name = $wpdb->prefix . 'marche_applications';
        $result = $wpdb->update(
            $table_name,
            array('files_json' => $files_json),
            array('id' => $application_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            throw new Exception('Failed to update application with file information: ' . $wpdb->last_error);
        } elseif ($result === 0) {
            // 申し込みレコードが存在するか確認
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE id = %d",
                $application_id
            ));
            if (!$exists) {
                throw new Exception('Application record not found: ' . $application_id);
            }
        } else {
        }
    }

    /**
     * Contact Form 7メールタグ処理 - [_file_urls]
     *
     * @param string $replaced 置換後の文字列
     * @param string $submitted 送信された値
     * @param bool   $html HTMLメールかどうか
     * @param object $mail_tag メールタグオブジェクト
     * @return string 置換後の文字列
     */
    public function replaceFileUrlsTag($replaced, $submitted, $html, $mail_tag) {
        if (!isset($mail_tag->name) || $mail_tag->name !== '_file_urls') {
            return $replaced;
        }

        // 最新の申し込みIDを取得（現在処理中のフォームから）
        $contact_form = wpcf7_get_current_contact_form();
        if (!$contact_form) {
            return '';
        }

        $application_id = $this->getLatestApplicationId($contact_form->id());
        if (!$application_id) {
            return '';
        }

        // ファイルURLの取得
        $file_urls = $this->getFileUrls($application_id);
        if (empty($file_urls)) {
            return '';
        }

        // HTML形式とテキスト形式で出力を変える
        if ($html) {
            $output = '';
            foreach ($file_urls as $file) {
                $output .= '<a href="' . esc_url($file['url']) . '">' . esc_html($file['name']) . '</a><br>' . "\n";
            }
        } else {
            $output = '';
            foreach ($file_urls as $file) {
                $output .= $file['name'] . ': ' . $file['url'] . "\n";
            }
        }

        return $output;
    }

    /**
     * 申し込みIDからファイルURLを取得
     *
     * @param int $application_id 申し込みID
     * @return array ファイルURL一覧
     */
    public function getFileUrls($application_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'marche_files';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT file_name, file_url FROM {$table_name} WHERE application_id = %d ORDER BY uploaded_at",
            $application_id
        ));

        $file_urls = array();
        foreach ($results as $result) {
            $file_urls[] = array(
                'name' => $result->file_name,
                'url' => $result->file_url
            );
        }

        return $file_urls;
    }

    /**
     * フォーム・開催日別のファイル一覧取得（管理画面用）
     *
     * @param int $form_id フォームID
     * @param int $date_id 開催日ID（オプション）
     * @return array ファイル一覧
     */
    public function getFilesByFormAndDate($form_id, $date_id = null) {
        global $wpdb;

        $applications_table = $wpdb->prefix . 'marche_applications';
        $files_table = $wpdb->prefix . 'marche_files';

        $where_clause = "WHERE a.form_id = %d";
        $params = array($form_id);

        if ($date_id) {
            $where_clause .= " AND a.date_id = %d";
            $params[] = $date_id;
        }

        $query = "
            SELECT f.*, a.application_data, a.area_name, a.created_at as application_date
            FROM {$files_table} f
            INNER JOIN {$applications_table} a ON f.application_id = a.id
            {$where_clause}
            ORDER BY f.uploaded_at DESC
        ";

        return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
    }
}
