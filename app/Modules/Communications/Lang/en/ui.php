<?php

return [
    'menu' => ['messages' => 'Messages', 'templates' => 'Message templates', 'consents' => 'Permissions to contact', 'channels' => 'My phone and e-mail'],
    'all' => 'All',
    'filter' => 'Filter',
    'save' => 'Save',
    'email_footer' => 'If you do not want these e-mails, use this link to stop them:',
    'channel' => ['email' => 'E-mail', 'whatsapp' => 'WhatsApp'],
    'party' => ['customer' => 'Customer', 'supplier' => 'Supplier'],
    'status' => [
        'draft' => 'Draft', 'awaiting_approval' => 'Waiting for approval', 'queued' => 'Queued', 'sending' => 'Sending', 'sent' => 'Sent', 'delivered' => 'Delivered',
        'read' => 'Read', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'received' => 'Received',
    ],
    'template' => [
        'payment_reminder' => 'Payment reminder', 'document_notice' => 'Document ready', 'general_notice' => 'General notice', 'identity_code' => 'Verification code',
    ],
    'messages' => [
        'intro' => 'Every e-mail and WhatsApp message to a customer or supplier is prepared here, checked (permission to contact, daily limit, approval for sensitive ones), sent, and followed until it is delivered.',
        'new' => 'New message', 'new_intro' => 'Choose the channel and the kind of message, then who it is for. The message is prepared as a draft: nothing is sent until you press Send.',
        'one' => 'Message', 'status' => 'Status', 'channel' => 'Channel', 'to' => 'To', 'what' => 'Message', 'when' => 'When', 'none' => 'No messages yet.',
        'template' => 'Kind of message', 'next' => 'Next', 'party_type' => 'For a', 'party_code' => 'Code (for example CUS-00001)', 'prepare' => 'Prepare the message',
        'needs_approval' => 'needs approval', 'by_ai' => 'prepared by the assistant', 'drafted_by_ai' => 'Prepared by the AI assistant: please read it before sending.',
        'send' => 'Send', 'cancel' => 'Cancel', 'see_approval' => 'See the approval request',
        'created_at' => 'Prepared', 'queued_at' => 'Queued', 'sent_at' => 'Sent', 'delivered_at' => 'Delivered', 'read_at' => 'Read', 'failed_at' => 'Failed',
    ],
    'templates' => [
        'intro' => 'The wording of each message. Messages that touch money or legal matters are marked "needs approval". WhatsApp only allows templates that WhatsApp has approved.',
        'language' => 'Language', 'flags' => 'Status', 'active' => 'In use', 'off' => 'Switched off', 'approved' => 'approved by WhatsApp', 'not_approved' => 'not yet approved by WhatsApp', 'edit' => 'Edit',
        'values' => 'Values you can use', 'subject' => 'Subject', 'body' => 'Text', 'provider_template' => 'Template name at WhatsApp', 'provider_language' => 'Language code at WhatsApp',
        'approved_by_whatsapp' => 'WhatsApp has approved this template', 'sensitive' => 'Needs approval before it is sent',
    ],
    'consents' => [
        'intro' => 'Who has allowed or refused to be contacted. A refusal stops all messages to that address at once. WhatsApp always needs the person\'s permission first.',
        'address' => 'E-mail address or phone number', 'look_up' => 'Look up', 'invalid' => 'That is not a valid e-mail address or phone number.', 'record' => 'Record that this address', 'note' => 'Why (for example "asked by phone")',
        'source' => 'How', 'nothing' => 'Nothing has been recorded for this address.',
        'status' => ['opted_in' => 'has allowed contact', 'opted_out' => 'has refused contact', 'unknown' => 'nothing recorded'],
        'sources' => ['manual' => 'Entered by staff', 'keyword' => 'Replied "stop"', 'identity_link' => 'Linked their own number', 'unsubscribe_link' => 'Used the link in an e-mail', 'import' => 'Imported'],
    ],
    'channels' => [
        'intro' => 'Link your own phone number or e-mail address: a code is sent to it and you type it here. A linked address is recognised as yours.',
        'none' => 'Nothing is linked yet.', 'add' => 'Link a phone number or e-mail address', 'placeholder' => '077 123 4567 or you@company.lk', 'send_code' => 'Send a code',
        'enter_code' => 'Enter the code you received', 'confirm' => 'Confirm', 'verified' => 'verified', 'remove' => 'Remove',
    ],
    'unsubscribed' => ['title' => 'You will not receive these e-mails any more', 'body' => 'We have recorded your wish. If this was a mistake, tell the company that sent the e-mail.'],
    'flash' => [
        'drafted' => 'The message is ready. Read it, then press Send.', 'queued' => 'The message is queued for sending.', 'awaiting_approval' => 'This message needs approval first; it has been sent to the approvers.',
        'cancelled' => 'The message was cancelled.', 'template_saved' => 'Saved.', 'consent_saved' => 'Recorded.', 'code_sent' => 'A code was sent. Type it below.', 'linked' => 'Linked.', 'unlinked' => 'Removed.',
    ],
];
