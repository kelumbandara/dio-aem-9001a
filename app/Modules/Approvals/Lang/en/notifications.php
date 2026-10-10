<?php

return [
    'requested' => ['title' => 'Approval needed: :doc', 'body' => ':requester asks for approval: :summary'],
    'reminder' => ['title' => 'Reminder: :doc is still waiting', 'body' => 'Waiting for your decision: :summary'],
    'approved' => ['title' => ':doc was approved', 'body' => 'It is now being carried out: :summary'],
    'rejected' => ['title' => ':doc was rejected', 'body' => 'Open the request to read the reason: :summary'],
    'expired' => ['title' => ':doc expired', 'body' => 'It was not completed in time and was not carried out: :summary'],
    'cancelled' => ['title' => ':doc was cancelled', 'body' => 'It will not be carried out: :summary'],
    'executed' => ['title' => ':doc was completed', 'body' => 'The approved action has been carried out: :summary'],
    'execution_failed' => ['title' => ':doc could not be carried out', 'body' => 'It was approved but failed: :error'],
];
