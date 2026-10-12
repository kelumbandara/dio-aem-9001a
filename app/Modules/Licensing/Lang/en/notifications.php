<?php

return [
    'ending' => [
        'title' => 'Subscription ends soon',
        'body' => 'The subscription ends on :date (:days day(s) left). Ask the vendor for a renewal.',
    ],
    'grace' => [
        'title' => 'Subscription ended — grace period',
        'body' => 'It ended on :date. Everything still works, but the system becomes read-only from :readonly.',
    ],
    'readonly' => [
        'title' => 'The system is now read-only',
        'body' => 'The subscription ended on :date. People can look and print but not change anything; it locks from :locked.',
    ],
    'locked' => [
        'title' => 'The system is locked',
        'body' => 'The subscription ended on :date. Install a new licence to continue.',
    ],
];
