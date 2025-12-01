<?php
// Correct autoload path (vendor is inside /public_html/api/vendor/)
require_once __DIR__ . '/../vendor/autoload.php';

use Pusher\Pusher;

const PUSHER_APP_ID    = '2049564';
const PUSHER_KEY       = 'c9a924289093535f51f9';
const PUSHER_SECRET    = '8d314ff82d71df11f2b9';
const PUSHER_CLUSTER   = 'ap1';

function pusher_client(): Pusher {
    return new Pusher(
        PUSHER_KEY,     // key
        PUSHER_SECRET,  // secret
        PUSHER_APP_ID,  // app_id
        [
            'cluster' => PUSHER_CLUSTER,
            'useTLS'  => true
        ]
    );
}
