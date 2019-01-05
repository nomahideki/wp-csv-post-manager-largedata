<?php

/*
  Plugin Name: CSV Post Manager Large Data
  Description: This plugin extends CSV Post Manager to handle large data
  Author: Hideki Noma
 */

include_once (WP_PLUGIN_DIR . '/csv-post-manager/csv-post-manager.php');

function __2($str, $file) {
// 翻訳エンジン
    if ($str == 'CSV Post Manager LargeData') {
        return "CSV投稿マネージャー（改造版）";
    }
    return $str;
}

class csv_post_manager_largedata extends csv_post_manager {

    protected $workdir;
    protected $split_data = 25; // CSVファイルを何行に分割するか？

    function csv_post_manager_largedata() {
        $this->workdir = WP_PLUGIN_DIR . '/csv-post-manager-largedata/work/';
        add_action('init', array(&$this, 'csv_post_manager_init'));
        add_action('admin_init', array(&$this, 'csv_post_manager_admin_init'));
        add_action('admin_print_scripts', array(&$this, 'csv_post_manager_admin_scripts'));
        add_action('admin_menu', array(&$this, 'csv_post_manager_admin_menu'));
        add_filter('plugin_action_links', array(&$this, 'csv_post_manager_plugin_action_links'), 10, 2);
    }

    function fetch_remote_file($url, $post) {
        $url = str_replace(WP_CONTENT_URL, WP_CONTENT_DIR, $url);
        $file_name = basename($url);
        $upload = wp_upload_bits($file_name, 0, '', $post['upload_date']);
        if ($upload['error'])
            return new WP_Error('upload_dir_error', $upload['error']);

        $filesize = 0;
        $headers = array();
        if (preg_match('/http/', $url)) {
            $headers = wp_get_http($url, $upload['file']);

            if (!$headers) {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', 'error');
            }

            if ($headers['response'] != '200') {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', 'error');
            }

            $filesize = filesize($upload['file']);

            if (isset($headers['content-length']) && $filesize != $headers['content-length']) {
                @unlink($upload['file']);
                return new WP_Error('import_file_error', 'error');
            }
        } else {
            if (file_exists($url)) {
                copy($url, $upload['file']);
            }
        }
        clearstatcache(true, $upload['file']);
        $filesize = filesize($upload['file']);

        if (0 == $filesize) {
            @unlink($upload['file']);
            return new WP_Error('import_file_error', 'error');
        }

        $this->url_remap[$url] = $upload['url'];
        $this->url_remap[$post['guid']] = $upload['url'];
        if (isset($headers['x-final-location']) && $headers['x-final-location'] != $url)
            $this->url_remap[$headers['x-final-location']] = $upload['url'];

        return $upload;
    }

    function csv_post_manager_admin_menu() {
        global $menu, $current_user;
        $options = get_option('csv_post_manager_data');

        add_options_page(__2('CSV Post Manager LargeData', 'csv-post-manager-largedata'), __2('CSV Post Manager LargeData', 'csv-post-manager-largedata'), 'manage_options', basename(__FILE__), array(&$this, 'csv_post_manager_admin'));
    }

    function csv_post_manager_plugin_action_links($links, $file) {
        static $this_plugin;

        if (!$this_plugin)
            $this_plugin = plugin_basename(__FILE__);

        if ($file == $this_plugin) {
            $settings_link = '<a href="options-general.php?page=csv-post-manager-largedata.php">' . __('Settings', 'csv-post-manager') . '</a>';
            $links = array_merge(array($settings_link), $links);
        }
        return $links;
    }

    function csv_convert_files() {
        $DIR = opendir($this->workdir);
        $files = array();
        while ($file = readdir($DIR)) {
            $files[] = $file;
        }
        closedir($DIR);
        sort($files);
        $ct = 0;
        foreach ($files as $file) {
            if (preg_match('/import([0-9][0-9][0-9][0-9])\.csv/', $file, $matches)) {
                $file = $this->workdir . '/' . $file;
                echo "convert $file<br />\n";
                $file_setting = preg_replace('/\.csv$/', '.settings', $file);
                $setting = file_get_contents($file_setting);
                $_POST = json_decode($setting, true);
                $this->csv_split_file($file, $this->split_data);
                unlink($file);
                unlink($file_setting);
                $ct++;
                if ($ct > 10) {
                    break;
                }
            }
        }
    }

// $this->csv_split_file($_FILES['csvfile']['tmp_name'], $this->split_data);
    function csv_split_file($file, $length) {
        ini_set("auto_detect_line_endings", true);
        if (is_numeric($_POST['setting'])) :
            $setting = explode(',', $options['setting'][(int) $_POST['setting']]);
            $setting = array_map('trim', $setting);
        else :
            $_POST['skip_first_data'] = 1;
        endif;
        $row = 1;
        $handle = fopen($file, "r");
        if (empty($setting)) :
            $data = $this->fgetExcelCSV($handle, null, ',', '"');
            $setting = $data;
            fseek($handle, 0);
        endif;
//1行目を温存
        $filecount = 0;
        $rowcount = 0;
        $row = 1;
// filecount をMAXに設定
        $DIR = opendir($this->workdir);
        while ($file = readdir($DIR)) {
            if (preg_match('/import([0-9]+)\.csv/', $file, $matches)) {
                $max = intval($matches[1]);
                if ($max > $filecount) {
                    $filecount = $max;
                }
            }
        }
        closedir($DIR);
        while (($data = $this->fgetExcelCSV($handle, null, ',', '"')) !== false) :
            if ($rowcount == 0) {
                $F = null;
                while ($F == null) {
                    $filecount++;
                    $filepath = $this->workdir . sprintf('import%06d.csv', $filecount);
                    if (file_exists($filepath)) {
                        continue;
                    }
                    $filepath2 = $this->workdir . sprintf('import%06d.settings', $filecount);
                    $F2 = fopen($filepath2, 'w');
                    fwrite($F2, json_encode($_POST));
                    fclose($F2);
                    $F = fopen($filepath, 'w');
                    fputcsv($F, $setting);
                    break;
                }
            }
            if ($row == 1 && !empty($_POST['skip_first_data'])) :
                $row++;
                $rowcount++;
                continue;
            endif;
            fputcsv($F, $data);
            $row++;
            $rowcount++;
            if ($rowcount > $length) {
                $rowcount = 0;
                fclose($F);
                unset($F);
            }
        endwhile;
    }

    function csv_post_manager_action() {
        global $wp_version, $wpdb, $wp_actions;
        $options = get_option('csv_post_manager_data');
        $_POST = stripslashes_deep($_POST);
        if (!empty($_GET['csv_post_manager_post_importer_execute_check'])) {
            $DIR = opendir($this->workdir);
            $files = array();
            while ($file = readdir($DIR)) {
                $files[] = $file;
            }
            closedir($DIR);
            sort($files);
            $message = 'no more files';
            foreach ($files as $file) {
                $file = $this->workdir . '/' . $file;
                if (preg_match('/\.csv.work$/', $file)) {
                    $mtime = filemtime($this->workdir . 'out1');
                    if (time() - $mtime > 600) { //長時間止まっている場合はやり直し
                        $newfile = preg_replace('/.work$/', '', $file);
                        rename($file, $newfile);
                    }
                    $message = "working";
                    break;
                }
                if (preg_match('/\.csv$/', $file)) {
                    $message = "waiting";
                }
            }
            echo $message;
            exit;
        } elseif (!empty($_GET['csv_post_manager_post_importer_execute_checklist'])) {
            system('ls -l ' . $this->workdir);
            system('ps aux');

            exit;
        } elseif (!empty($_GET['csv_post_manager_post_importer_execute'])) {
            $DIR = opendir($this->workdir);
            $files = array();
            while ($file = readdir($DIR)) {
                $files[] = $file;
                if (preg_match('/.csv.work/', $file)) {
                    exit;
                }
            }
            closedir($DIR);
            sort($files);
            foreach ($files as $file) {
                $file = $this->workdir . '/' . $file;
                if (preg_match('/\.csv$/', $file)) {
                    $file_setting = preg_replace('/\.csv$/', '.settings', $file);
                    $setting = file_get_contents($file_setting);
                    $newfile = $file . ".work";
                    rename($file, $newfile); // block double work
                    $oldfile = $file;
                    $file = $newfile;

                    $_POST = json_decode($setting, true);

                    $_FILES['csvfile']['tmp_name'] = $file;
                    $_POST['csv_post_manager_post_importer_submit_launch'] = 1;
                    $_POST['csv_post_manager_job'] = 1; // to avoid showing page
                    if (isset($_POST['csv_post_manager_post_importer_save'])) {
                        unset($_POST['csv_post_manager_post_importer_save']);
                    }
                    if (isset($_GET['csv_post_manager_post_importer_execute'])) {
                        unset($_GET['csv_post_manager_post_importer_execute']);
                    }
                    $_POST['csvfile'] = $file;
                    $_POST['settingfile'] = $file_setting;
                    $_REQUEST = array_merge($_GET, $POST);
                    $_ENV['QUERY_STRING'] = http_build_query($_GET);

                    $args = array(
                        '_POST' => $_POST,
                        '_GET' => $_GET,
                        '_REQUEST' => $_REQUEST,
                        '_COOKIE' => $_COOKIE,
                        '_SERVER' => $_SERVER,
                        '_SESSION' => $_SESSION,
                        '_FILES' => $_FILES,
                        '_ENV' => $_ENV
                    );
                    $filepath2 = $this->workdir . 'tmp.settings';
                    $F2 = fopen($filepath2, 'w');
                    fwrite($F2, json_encode($args));
                    fclose($F2);
                    echo "execute";
                    chdir(WP_PLUGIN_DIR . '/csv-post-manager-largedata/');
                    $php_ini = php_ini_loaded_file();
                    $php = PHP_BINARY;
                    if ($php == 'PHP_BINARY') {
// Gets the PID of the current executable
                        $pid = posix_getpid();
// Returns the exact path to the PHP executable.
                        $php = exec("readlink -f /proc/$pid/exe");
                    }
                    if (preg_match('/php-cgi/', $php)) {
                        $php_cli = preg_replace('/php-cgi/', 'php-cli', $php);
                        if (file_exists($php_cli)) {
                            $php = $php_cli;
                        }
                        $php_cli = preg_replace('/php-cgi/', 'php', $php);
                        if (file_exists($php_cli)) {
                            $php = $php_cli;
                        }
                    }

                    $cmd = 'nohup ' . $php . ' -c ' . $php_ini . ' -d register_argc_argv=On execute.php ' . $filepath2 . '>work/out1 2>work/out2 &';
                    flush();
// 作業ログファイルを削除
                    if (file_exists($this->workdir . 'out1')) {
                        unlink($this->workdir . 'out1');
                    }
                    if (file_exists($this->workdir . 'out2')) {
                        unlink($this->workdir . 'out2');
                    }
                    $try_ct = 0;
                    while (1) {
                        exec($cmd);
                        $success = FALSE;
                        $ct = 0;
                        // プロセスが起動しているかの確認
                        while (1) {
                            if (file_exists($this->workdir . 'out1')) {
                                $success = TRUE;
                                break;
                            }
                            $ct++;
                            if ($ct > 10) {
                                break;
                            }
                            sleep(1);
                        }

                        if ($success) {
                            $success = FALSE;
                            while (1) {
                                if (file_exists($filepath2 . '.working')) {
                                    $success = TRUE;
                                    break;
                                }
                                $ct++;
                                if ($ct > 10) {
                                    break;
                                }
                                sleep(1);
                            }
                            if ($success) {
                                break;
                            }
                        }
                        $try_ct++;
                        sleep(10);
                        if ($try_ct > 10) { //処理をいったんキャンセル
                            rename($newfile, $oldfile);
                            break;
                        }
                    }
                    flush(); // 出力はここで終了
                    exit;
                }
            }
            echo "no more files";
            exit;
        } elseif (!empty($_POST['csv_post_manager_post_importer_submit_launch'])) {
            $file = $_POST['csvfile'];
            $file_setting = $_POST['settingfile'];
            echo "launch $file";
            if (file_exists($file)) {
                $_POST['csv_post_manager_post_importer_submit'] = 1;
                ignore_user_abort();
                ob_start();
                parent::csv_post_manager_action();
                $html = ob_get_contents();
                ob_end_clean();
// remove files;
                echo "finished";
                unlink($file);
                unlink($file_setting);
                exit;
            }
        } elseif (!empty($_GET['csv_post_manager_post_importer_split_saveddata'])) {
            $this->csv_convert_files();
        } elseif (!empty($_POST['csv_post_manager_post_importer_save'])) {
//save files to working directory;
            if ($_FILES['csvfile']['tmp_name']) :
                $this->csv_split_file($_FILES['csvfile']['tmp_name'], $this->split_data);
                wp_redirect(get_option('siteurl') . '/wp-admin/options-general.php?page=' . $_REQUEST['page'] . '&message=' . $message . '&row=' . $row);
            else :
                $message = 'failed';
            endif;
        }
        else {
            parent::csv_post_manager_action();
        }
    }

    function csv_post_manager_admin() {
        ob_start();
        parent::csv_post_manager_admin();
        $html = ob_get_contents();
        ob_end_clean();
//URL書換
        $html = preg_replace('/page=csv-post-manager\.php/', 'page=csv-post-manager-largedata.php', $html);
// ボタンを追加
        $add_button = '<p><input type="submit" name="csv_post_manager_post_importer_save" value="投稿をインポート（分割処理） &raquo;" class="button-primary" /></p>';
        $html = preg_replace('/(<p><input type="submit" name="csv_post_manager_post_importer_submit" .*p>)/', $add_button . '$1', $html);
        echo $html;
        ?>
        <script type="text/javascript">
            ajaxProcessed = 0;
            ajaxSetting = {
                context: document.body,
                data: {csv_post_manager_post_importer_execute_check: 1, t: t()},
                dataType: 'text'
            };
            function t() {
                var d = new Date();
                return d.getTime();
            }
            function ajaxCall() {
                jQuery.ajax(ajaxSetting).done(ajaxHandler).fail(ajaxError);
            }
            function ajaxError() {
                ajaxSetting['data'] = {csv_post_manager_post_importer_execute_check: 1, t: t()};
                setTimeout(ajaxCall, 5000);
            }
            function ajaxHandler(data) {
                if (data == 'no more files') {
                    if (ajaxProcessed > 0) {
                        ajaxReport('処理完了', true);
                    }
        // do nothing
                } else if (data == 'waiting') {
                    if (ajaxProcessed == 0) {
                        ajaxReport('処理中', true);
                        ajaxProcessed++;
                    } else {
                        ajaxReport('・', false);
                    }
        // process next csv
                    ajaxSetting['data'] = {csv_post_manager_post_importer_execute: 1, t: t()};
                    jQuery.ajax(ajaxSetting).done(ajaxHandler).fail(ajaxError);
                } else if (data == 'working' || data == 'execute') {
                    if (ajaxProcessed == 0) {
                        ajaxReport('処理中', true);
                        ajaxProcessed++;
                    } else {
                        ajaxReport('・', false);
                    }
                    ajaxSetting['data'] = {csv_post_manager_post_importer_execute_check: 1, t: t()};
                    setTimeout(ajaxCall, 5000); //5秒おきにチェック
                } else {
                    ajaxSetting['data'] = {csv_post_manager_post_importer_execute_check: 1, t: t()};
                    setTimeout(ajaxCall, 5000); //5秒おきにチェック
                }
            }
            function ajaxReport(message, clear) {
                if (!jQuery('p.ajaxMessage').length) {
                    jQuery('h2').after('<p class="ajaxMessage"></p>');
                }
                if (clear) {
                    jQuery('p.ajaxMessage').html(message);
                } else {
                    jQuery('p.ajaxMessage').append(' ' + message);
                }
            }
            jQuery.ajax(ajaxSetting).done(ajaxHandler).fail(ajaxError);</script>
        <style>
            p.ajaxMessage {
                font-size: 120%;
                font-weight: bold;
                background: white;
                width: 80%;
                margin: 0;
                padding: 15px;
            }
        </style>
        <?php

    }

}

$csv_post_manager_largedata = new csv_post_manager_largedata();
if ($csv_post_manager) {
    remove_filter('plugin_action_links', array(&$csv_post_manager, 'csv_post_manager_plugin_action_links'), 10, 2);
    remove_action('admin_menu', array(&$csv_post_manager, 'csv_post_manager_admin_menu'));
}
?>