<?php

namespace App\Message;

class SendEmailMessage
{
    public function __construct(
        private int $emailQueueId
    ) {
    }

    public function getEmailQueueId(): int
    {
        return $this->emailQueueId;
    }
}
