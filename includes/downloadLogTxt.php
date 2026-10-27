<?php
/**
 * Created by PhpStorm.
 * User: thiba
 * Date: 08/04/2019
 * Time: 14:19
 *
 * @package    Axonaut
 * @subpackage Axonaut/includes
 */

    $file = "log.txt";
    if (file_exists($file)){
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }