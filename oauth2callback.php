<?php
require_once __DIR__ . '/wp-content/plugins/gw-manager/vendor/autoload.php';

session_start();

$client = new Google_Client();
$client->setAuthConfig(__DIR__ . '/wp-content/plugins/gw-manager/credentials.json');
$client->addScope(Google_Service_Calendar::CALENDAR);
$client->setRedirectUri('http://localhost/gwproject/oauth2callback.php'); // Tengo que cambiarlo cuando se suba a SiteGround

if (!isset($_GET['code'])) {
    // Paso 1: redirigir al usuario a Google
    $auth_url = $client->createAuthUrl();
    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit;
} else {
    // Paso 2: obtener token usando el código devuelto por Google
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $client->setAccessToken($token);

    // Guardar token para uso futuro
    file_put_contents(__DIR__ . '/wp-content/plugins/gw-manager/token.json', json_encode($client->getAccessToken()));

    echo "<h2>✅ Autenticación exitosa.</h2><p>Ya puedes usar Google Calendar y Meet en tu plugin.</p>";
}