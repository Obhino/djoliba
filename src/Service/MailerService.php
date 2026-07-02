<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class MailerService
{
    private MailerInterface $mailer;
    private string $senderEmail;
    private string $senderName = 'Djoliba';

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
        $this->senderEmail = $_ENV['MAILER_FROM'] ?? 'contact@djolibasearch.com';
    }

    /**
     * Envoie un e-mail au format HTML à partir d'un template Twig.
     */
    public function sendEmail(string $to, string $subject, string $template, array $context = []): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context);

        $this->mailer->send($email);
    }

    /**
     * Envoie l'e-mail de confirmation/vérification d'adresse e-mail.
     */
    public function sendVerificationEmail(User $user, string $signedUrl): void
    {
        $this->sendEmail(
            $user->getEmail(),
            'Activez votre compte chercheur - Djoliba',
            'security/confirmation_email.html.twig',
            [
                'signedUrl' => $signedUrl,
            ]
        );
    }

    /**
     * Envoie l'e-mail de bienvenue après validation de l'e-mail.
     */
    public function sendWelcomeEmail(User $user): void
    {
        $this->sendEmail(
            $user->getEmail(),
            'Bienvenue sur Djoliba ! 🎓',
            'security/welcome_email.html.twig',
            [
                'user' => $user,
            ]
        );
    }
}
