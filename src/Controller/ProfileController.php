<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_user_profile')]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        // Sécurité : rediriger vers la page de connexion si l'utilisateur n'est pas connecté
        if (!$user) {
            $this->addFlash('error', 'Veuillez vous connecter pour accéder à votre profil.');
            return $this->redirectToRoute('app_login');
        }

        // Traitement de la soumission du formulaire
        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));
            $firstName = trim($request->request->get('firstName', ''));
            $lastName = trim($request->request->get('lastName', ''));
            $orcid = trim($request->request->get('orcid', ''));
            $affiliation = trim($request->request->get('affiliation', ''));
            $researchField = trim($request->request->get('researchField', ''));
            $academicStatus = trim($request->request->get('academicStatus', ''));
            $biography = trim($request->request->get('biography', ''));
            $googleScholar = trim($request->request->get('googleScholar', ''));
            $languagePreference = $request->request->get('languagePreference', 'fr');
            $helpEnabled = $request->request->get('helpEnabled') === 'on' || $request->request->get('helpEnabled') === '1';

            // Validation de l'email
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Veuillez renseigner une adresse email valide.');
            } else {
                // Vérifier l'unicité de l'email s'il a changé
                if ($email !== $user->getEmail()) {
                    $existingUser = $userRepository->findOneBy(['email' => $email]);
                    if ($existingUser) {
                        $this->addFlash('error', 'Cette adresse email est déjà utilisée par un autre chercheur.');
                        return $this->render('profile/index.html.twig');
                    }
                    $user->setEmail($email);
                }

                // Validation simple de l'identifiant ORCID (format XXXX-XXXX-XXXX-XXXX)
                if (!empty($orcid) && !preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
                    $this->addFlash('error', 'Le format de l\'identifiant ORCID doit être XXXX-XXXX-XXXX-XXXX.');
                    return $this->render('profile/index.html.twig');
                }

                // Mise à jour des informations
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setOrcid(!empty($orcid) ? $orcid : null);
                $user->setAffiliation(!empty($affiliation) ? $affiliation : null);
                $user->setResearchField(!empty($researchField) ? $researchField : null);
                $user->setAcademicStatus(!empty($academicStatus) ? $academicStatus : null);
                $user->setBiography(!empty($biography) ? $biography : null);
                $user->setGoogleScholar(!empty($googleScholar) ? $googleScholar : null);
                $user->setLanguagePreference($languagePreference);
                $user->setHelpEnabled($helpEnabled);
                $user->setUpdatedAt(new \DateTime());

                $entityManager->flush();

                $this->addFlash('success', 'Votre profil de chercheur a été mis à jour avec succès !');

                // Redirection Post-Redirect-Get pour éviter la resoumission du formulaire
                return $this->redirectToRoute('app_user_profile');
            }
        }

        return $this->render('profile/index.html.twig');
    }
}
