<?php

return [
    // Explicit operator approval bound to the actual local MySQL server and schema.
    'enabled' => env('DEMO_STUDENT_ENABLED', false),
    'database' => env('DEMO_STUDENT_DATABASE'),
    'server_uuid' => env('DEMO_STUDENT_SERVER_UUID'),
];
