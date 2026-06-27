<?php

namespace App\Service\Email;

use App\Entity\EmailQueue;
use App\Message\SendEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

class EmailService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly Environment $twig
    ) {
    }

    /**
     * Envoie un email en le mettant dans la file d'attente (asynchrone).
     */
    public function send(string $recipient, string $subject, string $bodyHtml, bool $isBulk = false): EmailQueue
    {
        $emailQueue = new EmailQueue();
        $emailQueue->setRecipient($recipient);
        $emailQueue->setSubject($subject);
        $emailQueue->setBody($bodyHtml);
        $emailQueue->setStatus('pending');
        $emailQueue->setIsBulk($isBulk);

        $this->entityManager->persist($emailQueue);
        $this->entityManager->flush();

        // Envoi asynchrone via Symfony Messenger
        $this->messageBus->dispatch(new SendEmailMessage($emailQueue->getId()));

        return $emailQueue;
    }

    /**
     * Envoie un email basé sur un template Twig (qui peut contenir du Markdown ou du HTML).
     */
    public function sendTemplate(string $recipient, string $subject, string $template, array $context = [], bool $isBulk = false): EmailQueue
    {
        $body = $this->twig->render($template, $context);
        return $this->send($recipient, $subject, $body, $isBulk);
    }

    /**
     * Parse un texte au format Markdown léger vers du HTML.
     */
    public function markdownToHtml(string $markdown): string
    {
        $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        // Titres
        $html = preg_replace('/^### (.*?)$/m', '<h3 style="color:#2d3748; margin-top:16px;">$1</h3>', $html);
        $html = preg_replace('/^## (.*?)$/m', '<h2 style="color:#2d3748; margin-top:20px;">$1</h2>', $html);
        $html = preg_replace('/^# (.*?)$/m', '<h1 style="color:#1a202c; margin-top:24px;">$1</h1>', $html);

        // Gras
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);

        // Italique
        $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);

        // Liens markdown [Texte](URL)
        $html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" style="color:#3182ce; text-decoration:underline;">$1</a>', $html);

        // Listes à puces
        $html = preg_replace('/^\* (.*?)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>)/s', '<ul style="margin-bottom:12px;">$0</ul>', $html);
        // Supprimer les imbrications ul/ul incorrectes dues au replace simple
        $html = str_replace('</ul><ul style="margin-bottom:12px;">', '', $html);

        // Paragraphes
        $html = preg_replace('/\n\n/', '</p><p style="margin-bottom:16px; line-height:1.6; color:#4a5568;">', $html);
        $html = '<p style="margin-bottom:16px; line-height:1.6; color:#4a5568;">' . str_replace("\n", '<br>', $html) . '</p>';

        // Nettoyage des balises vides
        $html = str_replace('<p style="margin-bottom:16px; line-height:1.6; color:#4a5568;"></p>', '', $html);

        return $html;
    }
}
