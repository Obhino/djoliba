<?php

namespace App\Controller\Admin;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Service\Email\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class EmailSenderController extends AbstractController
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/admin/send-email', name: 'admin_email_sender', methods: ['GET', 'POST'])]
    public function sendEmail(Request $request): Response
    {
        $userRepo = $this->entityManager->getRepository(User::class);
        $users = $userRepo->findAll();

        if ($request->isMethod('POST')) {
            $type = $request->request->get('recipient_type');
            $specificRecipient = $request->request->get('specific_recipient');
            $subject = $request->request->get('subject');
            $rawBody = $request->request->get('body');

            if (empty($subject) || empty($rawBody)) {
                $this->addFlash('danger', 'Le sujet et le corps du message sont requis.');
                return $this->redirectToRoute('admin_email_sender');
            }

            $sentCount = 0;

            if ($type === 'targeted') {
                if (empty($specificRecipient)) {
                    $this->addFlash('danger', 'Veuillez renseigner le destinataire.');
                    return $this->redirectToRoute('admin_email_sender');
                }

                $bodyHtml = $this->emailService->markdownToHtml($rawBody);
                $this->emailService->send($specificRecipient, $subject, $bodyHtml, false);
                $sentCount = 1;
            } elseif ($type === 'bulk') {
                foreach ($users as $user) {
                    if (!$user->isActive()) {
                        continue;
                    }
                    $bodyHtml = $this->emailService->markdownToHtml($rawBody);
                    $this->emailService->send($user->getEmail(), $subject, $bodyHtml, true);
                    $sentCount++;
                }
            } elseif ($type === 'personalized') {
                foreach ($users as $user) {
                    if (!$user->isActive()) {
                        continue;
                    }
                    $bodyPersonalized = strtr($rawBody, [
                        '%firstName%' => $user->getFirstName() ?? 'Cher utilisateur',
                        '%lastName%' => $user->getLastName() ?? '',
                        '%email%' => $user->getEmail(),
                    ]);

                    $bodyHtml = $this->emailService->markdownToHtml($bodyPersonalized);
                    $this->emailService->send($user->getEmail(), $subject, $bodyHtml, true);
                    $sentCount++;
                }
            }

            // Log action manually
            $log = new AdminLog();
            $log->setAdmin($this->getUser());
            $log->setAction('SEND_EMAIL');
            $log->setTarget(sprintf('Type: %s, Count: %d', $type, $sentCount));
            $log->setIpAddress($request->getClientIp());
            
            $this->entityManager->persist($log);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('%d email(s) ont été ajoutés à la file d\'attente.', $sentCount));
            return $this->redirectToRoute('admin_email_sender');
        }

        return $this->render('admin/email_sender.html.twig', [
            'users' => $users,
        ]);
    }
}
