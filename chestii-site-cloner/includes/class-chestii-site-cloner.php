<?php

class Chestii_Site_Cloner
{
    private const EXPORT_ACTION = 'chestii_site_cloner_export';
    private const IMPORT_ACTION = 'chestii_site_cloner_import';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handle_export']);
        add_action('admin_post_' . self::IMPORT_ACTION, [self::class, 'handle_import']);
    }

    public static function register_menu(): void
    {
        add_management_page(
            'Chestii Site Cloner',
            'Site Cloner',
            'manage_options',
            'chestii-site-cloner',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nu ai permisiuni suficiente.');
        }

        $export_url = admin_url('admin-post.php');
        $import_url = admin_url('admin-post.php');

        echo '<div class="wrap">';
        echo '<h1>Chestii Site Cloner</h1>';
        echo '<p>Exportă un pachet complet (bază de date + fișiere) și importă-l pe alt server WordPress.</p>';

        if (!empty($_GET['message'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['message'])) . '</p></div>';
        }

        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['error'])) . '</p></div>';
        }

        echo '<h2>Export</h2>';
        echo '<form method="post" action="' . esc_url($export_url) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '">';
        wp_nonce_field(self::EXPORT_ACTION, '_wpnonce');
        submit_button('Generează pachet', 'primary', 'submit', false);
        echo '</form>';

        echo '<hr />';
        echo '<h2>Import</h2>';
        echo '<form method="post" action="' . esc_url($import_url) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::IMPORT_ACTION) . '">';
        wp_nonce_field(self::IMPORT_ACTION, '_wpnonce');
        echo '<input type="file" name="chestii_package" accept=".zip" required />';
        submit_button('Importă pachet', 'primary', 'submit', false);
        echo '</form>';

        echo '<p><strong>Notă:</strong> Procesul suprascrie baza de date curentă și folderul wp-content. Fă un backup înainte.</p>';
        echo '</div>';
    }

    public static function handle_export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nu ai permisiuni suficiente.');
        }

        check_admin_referer(self::EXPORT_ACTION);

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'chestii-site-cloner-' . wp_generate_uuid4();

        if (!wp_mkdir_p($temp_dir)) {
            self::redirect_with_error('Nu pot crea directorul temporar.');
        }

        $db_file = $temp_dir . '/database.sql';
        $metadata_file = $temp_dir . '/metadata.json';

        $metadata = [
            'site_url' => site_url(),
            'home_url' => home_url(),
            'exported_at' => gmdate('c'),
        ];
        file_put_contents($metadata_file, wp_json_encode($metadata, JSON_PRETTY_PRINT));

        $dump_result = self::dump_database($db_file);
        if (!$dump_result['success']) {
            self::cleanup($temp_dir);
            self::redirect_with_error($dump_result['message']);
        }

        $zip_path = $temp_dir . '/site-package.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            self::cleanup($temp_dir);
            self::redirect_with_error('Nu pot crea arhiva.');
        }

        $zip->addFile($db_file, 'database.sql');
        $zip->addFile($metadata_file, 'metadata.json');

        self::add_directory_to_zip($zip, WP_CONTENT_DIR, 'wp-content');
        $zip->close();

        $filename = 'chestii-site-' . gmdate('Ymd-His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Length: ' . filesize($zip_path));

        readfile($zip_path);
        self::cleanup($temp_dir);
        exit;
    }

    public static function handle_import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nu ai permisiuni suficiente.');
        }

        check_admin_referer(self::IMPORT_ACTION);

        if (empty($_FILES['chestii_package']['tmp_name'])) {
            self::redirect_with_error('Nu ai încărcat niciun fișier.');
        }

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'chestii-site-cloner-' . wp_generate_uuid4();

        if (!wp_mkdir_p($temp_dir)) {
            self::redirect_with_error('Nu pot crea directorul temporar.');
        }

        $package_path = $temp_dir . '/package.zip';
        if (!move_uploaded_file($_FILES['chestii_package']['tmp_name'], $package_path)) {
            self::cleanup($temp_dir);
            self::redirect_with_error('Nu pot muta arhiva încărcată.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $unzipped = unzip_file($package_path, $temp_dir);
        if (is_wp_error($unzipped)) {
            self::cleanup($temp_dir);
            self::redirect_with_error('Nu pot dezarhiva pachetul: ' . $unzipped->get_error_message());
        }

        $db_file = $temp_dir . '/database.sql';
        if (!file_exists($db_file)) {
            self::cleanup($temp_dir);
            self::redirect_with_error('Lipsește fișierul database.sql.');
        }

        $import_result = self::import_database($db_file);
        if (!$import_result['success']) {
            self::cleanup($temp_dir);
            self::redirect_with_error($import_result['message']);
        }

        $content_source = $temp_dir . '/wp-content';
        if (!is_dir($content_source)) {
            self::cleanup($temp_dir);
            self::redirect_with_error('Lipsește folderul wp-content.');
        }

        $copy_result = copy_dir($content_source, WP_CONTENT_DIR);
        if (is_wp_error($copy_result)) {
            self::cleanup($temp_dir);
            self::redirect_with_error('Nu pot copia fișierele: ' . $copy_result->get_error_message());
        }

        self::cleanup($temp_dir);
        $redirect = add_query_arg('message', rawurlencode('Import finalizat cu succes.'), admin_url('tools.php?page=chestii-site-cloner'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function dump_database(string $destination): array
    {
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => 'Funcția exec este dezactivată pe server.'];
        }

        $mysqldump = self::locate_binary('mysqldump');
        if (!$mysqldump) {
            return ['success' => false, 'message' => 'Nu pot găsi mysqldump pe server.'];
        }

        $command = sprintf(
            '%s --host=%s --user=%s --password=%s %s > %s',
            escapeshellcmd($mysqldump),
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASSWORD),
            escapeshellarg(DB_NAME),
            escapeshellarg($destination)
        );

        exec($command, $output, $status);

        if ($status !== 0 || !file_exists($destination)) {
            return ['success' => false, 'message' => 'Exportul bazei de date a eșuat.'];
        }

        return ['success' => true, 'message' => 'OK'];
    }

    private static function import_database(string $source): array
    {
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => 'Funcția exec este dezactivată pe server.'];
        }

        $mysql = self::locate_binary('mysql');
        if (!$mysql) {
            return ['success' => false, 'message' => 'Nu pot găsi clientul mysql pe server.'];
        }

        $command = sprintf(
            '%s --host=%s --user=%s --password=%s %s < %s',
            escapeshellcmd($mysql),
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASSWORD),
            escapeshellarg(DB_NAME),
            escapeshellarg($source)
        );

        exec($command, $output, $status);

        if ($status !== 0) {
            return ['success' => false, 'message' => 'Importul bazei de date a eșuat.'];
        }

        return ['success' => true, 'message' => 'OK'];
    }

    private static function locate_binary(string $binary): ?string
    {
        $paths = [
            '/usr/bin/' . $binary,
            '/usr/local/bin/' . $binary,
        ];

        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which = trim(shell_exec('which ' . escapeshellarg($binary)));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }

    private static function add_directory_to_zip(ZipArchive $zip, string $directory, string $base): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = $base . '/' . ltrim(str_replace($directory, '', $path), '/');

            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }

            $zip->addFile($path, $relative);
        }
    }

    private static function cleanup(string $temp_dir): void
    {
        if (!is_dir($temp_dir)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;
        if ($wp_filesystem) {
            $wp_filesystem->delete($temp_dir, true);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($temp_dir);
    }

    private static function redirect_with_error(string $message): void
    {
        $redirect = add_query_arg('error', rawurlencode($message), admin_url('tools.php?page=chestii-site-cloner'));
        wp_safe_redirect($redirect);
        exit;
    }
}
