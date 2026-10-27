<?php

/**
 * Journal du module.
 *
 * Le fichier est stocké dans wp-content/uploads/axonaut-logs, avec un nom
 * non devinable et un .htaccess qui interdit l'accès direct.
 *
 * @since      1.0.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

const AXONAUT_LOG_INFO = "INFO";
const AXONAUT_LOG_WARNING = "WARNING";
const AXONAUT_LOG_ERROR = "ERROR";

/**
 * Chemin du fichier journal. Crée le dossier protégé si besoin.
 * @return string|null null si le dossier n'est pas accessible en écriture
 */
function axonaut_log_path()
{
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        return null;
    }

    $dir = trailingslashit($uploads['basedir']) . 'axonaut-logs';
    if (!is_dir($dir)) {
        if (!wp_mkdir_p($dir)) {
            return null;
        }
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
    }

    $suffix = get_option('axonaut_log_suffix');
    if (empty($suffix)) {
        $suffix = wp_generate_password(16, false);
        update_option('axonaut_log_suffix', $suffix, false);
    }

    return $dir . '/log-' . $suffix . '.txt';
}

/**
 * Écrit une ligne dans le journal.
 * @param string $str   message
 * @param string $level AXONAUT_LOG_*
 */
function axonaut_write_log($str, string $level = AXONAUT_LOG_INFO)
{
    $path = axonaut_log_path();
    if ($path === null) {
        return;
    }

    // Rotation simple : au-delà de 2 Mo, on repart d'un fichier vide.
    if (file_exists($path) && filesize($path) > 2 * 1024 * 1024) {
        rename($path, $path . '.old');
    }

    $date = (new DateTime())->format("d/m/Y H:i:s");
    error_log("[ $date ] $level : $str\n", 3, $path);
}

/**
 * Contenu du journal.
 * @return string
 */
function read_log()
{
    $path = axonaut_log_path();
    return ($path !== null && file_exists($path)) ? file_get_contents($path) : "";
}
