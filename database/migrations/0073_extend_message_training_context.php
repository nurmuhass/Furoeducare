<?php

declare(strict_types=1);

return [
    'up' => "ALTER TABLE message_conversations MODIFY context_type ENUM('support','recruitment','vacancy','tutoring','candidate_information','placement','training') NOT NULL",
    // MySQL safely rejects removal of an enum value in use under strict SQL mode.
    'down' => "ALTER TABLE message_conversations MODIFY context_type ENUM('support','recruitment','vacancy','tutoring','candidate_information','placement') NOT NULL",
];
