<?php

namespace App\Tests\Functional;

use App\Entity\BibliographicReference;
use App\Entity\ProjectBibliography;
use App\Entity\ResearchProject;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BibliographyEntitiesTest extends WebTestCase
{
    public function testBibliographyEntitiesAndRelationships(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        // 1. Créer un utilisateur de test
        $user = new User();
        $user->setEmail('bib-entities-test@djoliba.com');
        $user->setPassword('password');
        $user->setFirstName('Bib');
        $user->setLastName('Tester');
        $em->persist($user);
        $em->flush();

        // 2. Créer une référence bibliographique globale pour cet utilisateur
        $reference = new BibliographicReference();
        $reference->setUser($user);
        $reference->setCiteKey('doe2026');
        $reference->setEntryType('article');
        $reference->setTitle('A Great Research Study');
        $reference->setAuthors('Doe, John and Smith, Jane');
        $reference->setYear('2026');
        $reference->setJournal('Journal of Testing');
        $reference->setDoi('10.1000/xyz123');
        $reference->setSource('zotero');
        $reference->setZoteroKey('ZT999');
        $reference->setRawData(['pages' => '10-20', 'volume' => '5']);
        $em->persist($reference);
        $em->flush();

        // 3. Créer un projet de recherche pour l'utilisateur
        $researchProject = new ResearchProject();
        $researchProject->setUser($user);
        $researchProject->setTitle('Project for Bib Test');
        $researchProject->setDescription('A research project to test relationships');
        $em->persist($researchProject);
        $em->flush();

        // 4. Créer la bibliographie de projet et lier la référence
        $projectBibliography = new ProjectBibliography();
        $projectBibliography->setResearchProject($researchProject);
        $projectBibliography->addReference($reference);
        $em->persist($projectBibliography);
        $em->flush();

        // Détacher les entités pour forcer leur rechargement depuis la base de données
        $em->clear();

        // 5. Récupérer et tester les relations
        /** @var User $freshUser */
        $freshUser = $em->getRepository(User::class)->findOneBy(['email' => 'bib-entities-test@djoliba.com']);
        $this->assertNotNull($freshUser);
        $this->assertCount(1, $freshUser->getBibliographicReferences());
        
        /** @var BibliographicReference $freshReference */
        $freshReference = $freshUser->getBibliographicReferences()->first();
        $this->assertEquals('doe2026', $freshReference->getCiteKey());
        $this->assertEquals('article', $freshReference->getEntryType());
        $this->assertEquals('A Great Research Study', $freshReference->getTitle());
        $this->assertEquals('zotero', $freshReference->getSource());
        $this->assertEquals('ZT999', $freshReference->getZoteroKey());
        $this->assertEquals(['pages' => '10-20', 'volume' => '5'], $freshReference->getRawData());

        /** @var ResearchProject $freshResearchProject */
        $freshResearchProject = $em->getRepository(ResearchProject::class)->findOneBy(['title' => 'Project for Bib Test']);
        $this->assertNotNull($freshResearchProject);
        
        $freshBibliography = $freshResearchProject->getProjectBibliography();
        $this->assertNotNull($freshBibliography);
        $this->assertCount(1, $freshBibliography->getReferences());
        
        $linkedRef = $freshBibliography->getReferences()->first();
        $this->assertEquals($freshReference->getId(), $linkedRef->getId());
        $this->assertEquals('doe2026', $linkedRef->getCiteKey());

        // Stocker les IDs pour la vérification après suppression
        $refId = $freshReference->getId();
        $bibId = $freshBibliography->getId();

        // 6. Nettoyage des entités créées (Cascades à l'œuvre)
        $em->remove($freshResearchProject);
        $em->remove($freshUser);
        $em->flush();

        // S'assurer que tout a été nettoyé
        $this->assertNull($em->getRepository(User::class)->findOneBy(['email' => 'bib-entities-test@djoliba.com']));
        $this->assertNull($em->getRepository(ResearchProject::class)->findOneBy(['title' => 'Project for Bib Test']));
        $this->assertNull($em->getRepository(BibliographicReference::class)->find($refId));
        $this->assertNull($em->getRepository(ProjectBibliography::class)->find($bibId));
    }
}
