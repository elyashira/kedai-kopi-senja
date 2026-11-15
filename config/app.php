<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');
define('BASEURL', 'http://localhost/kedai-kopi-senja');