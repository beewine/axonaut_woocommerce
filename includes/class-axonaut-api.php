<?php

/**
 * Client HTTP unique pour l'API Axonaut.
 *
 * Tous les appels sortants du module passent par ici : base URL, en-têtes,
 * vérification SSL, décodage JSON et journalisation des erreurs.
 *
 * @since      2.5.0
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

require_once plugin_dir_path(dirname(__FILE__)) . "includes/log.php";

class Axonaut_Api
{
    const BASE_URL = "https://axonaut.com";

    /**
     * Envoie une requête à l'API Axonaut.
     *
     * @param string     $method  GET, POST, PATCH, DELETE
     * @param string     $path    Chemin commençant par /api/v2/...
     * @param array|null $body    Corps JSON (POST/PATCH)
     * @param array      $query   Paramètres d'URL
     * @param array      $options timeout (secondes), page (en-tête de pagination)
     * @return array{ok:bool,status:int,data:mixed,error:?string}
     */
    public static function request($method, $path, $body = null, $query = array(), $options = array())
    {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url = add_query_arg(array_map('rawurlencode', $query), $url);
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'userApiKey' => get_option('axonaut_api_key'),
            'X-AXONAUT-ORIGIN' => "WooCommerce " . getVersionModule(),
        );
        if (isset($options['page'])) {
            $headers['page'] = (int) $options['page'];
        }

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => isset($options['timeout']) ? (int) $options['timeout'] : 20,
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            axonaut_write_log("$method $path : $message", AXONAUT_LOG_ERROR);
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => $message);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data["error"]) || $status >= 400) {
            $error = !empty($data["error"]) ? wp_json_encode($data["error"]) : "HTTP $status";
            // Un 404 sur une recherche est un résultat normal, pas une erreur.
            $level = $status === 404 ? AXONAUT_LOG_INFO : AXONAUT_LOG_ERROR;
            axonaut_write_log("$method $path : $error", $level);
            return array('ok' => false, 'status' => $status, 'data' => $data, 'error' => $error);
        }

        return array('ok' => true, 'status' => $status, 'data' => $data, 'error' => null);
    }

    public static function get($path, $query = array(), $options = array())
    {
        return self::request('GET', $path, null, $query, $options);
    }

    public static function post($path, $body, $options = array())
    {
        return self::request('POST', $path, $body, array(), $options);
    }

    public static function patch($path, $body, $options = array())
    {
        return self::request('PATCH', $path, $body, array(), $options);
    }

    public static function delete($path, $options = array())
    {
        return self::request('DELETE', $path, null, array(), $options);
    }
}
