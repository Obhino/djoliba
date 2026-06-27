<?php

namespace App\MessageHandler;

use App\Message\SendEmailMessage;
use App\Repository\EmailQueueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendEmailMessageHandler
{
    public function __construct(
        private readonly EmailQueueRepository $emailQueueRepository,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SendEmailMessage $message): void
    {
        $queueId = $message->getEmailQueueId();
        $this->logger->info('[SendEmailMessage] Processing email queue ID #{id}', ['id' => $queueId]);

        $emailQueue = $this->emailQueueRepository->find($queueId);
        if (!$emailQueue) {
            $this->logger->error('[SendEmailMessage] Email queue record #{id} not found', ['id' => $queueId]);
            return;
        }

        if ($emailQueue->getStatus() !== 'pending') {
            $this->logger->warning('[SendEmailMessage] Email queue record #{id} is not in pending status, current status: {status}', [
                'id' => $queueId,
                'status' => $emailQueue->getStatus()
            ]);
            return;
        }

        try {
            $email = (new Email())
                ->from('no-reply@djoliba.com')
                ->to($emailQueue->getRecipient())
                ->subject($emailQueue->getSubject())
                ->html($emailQueue->getBody());

            $this->mailer->send($email);

            $emailQueue->setStatus('sent');
            $emailQueue->setSentAt(new \DateTime());
            $emailQueue->setErrorMessage(null);

            $this->logger->info('[SendEmailMessage] Email successfully sent for queue ID #{id}', ['id' => $queueId]);
        } catch (\Throwable $e) {
            $emailQueue->setStatus('failed');
            $emailQueue->setErrorMessage($e->getMessage());

            $this->logger->error('[SendEmailMessage] Failed to send email for queue ID #{id}: {error}', [
                'id' => $queueId,
                'error' => $e->getMessage()
            ]);
        }

        $this->entityManager->flush();
    }
}
