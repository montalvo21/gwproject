

<?php
require_once __DIR__ . '/vendor/autoload.php';

function create_google_meet_event($summary, $description, $startDateTime, $endDateTime, $attendeeEmail) {
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->addScope(Google_Service_Calendar::CALENDAR);
    $client->setAccessType('offline');

    // Comprobar token guardado
    $tokenPath = __DIR__ . '/token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // Si expira, renovar
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Solicitar manualmente desde navegador
            $authUrl = $client->createAuthUrl();
            printf("Abre este enlace en el navegador:\n%s\n", $authUrl);
            print 'Pega el código de verificación aquí: ';
            $authCode = trim(fgets(STDIN));
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Guardar token
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
    }

    $service = new Google_Service_Calendar($client);

    $event = new Google_Service_Calendar_Event([
        'summary' => $summary,
        'description' => $description,
        'start' => [
            'dateTime' => $startDateTime,
            'timeZone' => 'America/El_Salvador',
        ],
        'end' => [
            'dateTime' => $endDateTime,
            'timeZone' => 'America/El_Salvador',
        ],
        'attendees' => [
            ['email' => $attendeeEmail],
        ],
        'conferenceData' => [
            'createRequest' => [
                'requestId' => uniqid(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
            ]
        ],
    ]);

    $event = $service->events->insert('primary', $event, ['conferenceDataVersion' => 1]);

    return $event->getHangoutLink();
}