<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\Project;
use App\Entity\Chapter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ThesisControllerTest extends WebTestCase
{
    private $client;
    private $user;
    private $project;
    private $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();

        // 1. Get or create test user
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'thesis-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('thesis-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('ThesisTester');
            $this->em->persist($user);
            $this->em->flush();
        }
        $this->user = $user;

        // 2. Create a thesis project
        $project = new Project();
        $project->setName('Projet Thèse Test');
        $project->setType('thesis');
        $project->setUser($this->user);
        $this->em->persist($project);
        $this->em->flush();
        $this->project = $project;
    }

    protected function tearDown(): void
    {
        // Clean up chapters created during the tests
        $project = $this->em->getRepository(Project::class)->find($this->project->getId());
        if ($project) {
            $chapters = $this->em->getRepository(Chapter::class)->findBy(['project' => $project]);
            foreach ($chapters as $chapter) {
                $this->em->remove($chapter);
            }
            $this->em->remove($project);
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testThesisLifecycle(): void
    {
        $this->client->loginUser($this->user);

        // --- STEP 1: Add a root chapter (POST /api/thesis/structure) ---
        $this->client->request('POST', '/api/thesis/structure', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'project_id' => $this->project->getId(),
            'title' => 'Chapitre 1 : Introduction'
        ]));

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $chapter1Id = $data['data']['chapter']['id'];
        $this->assertNotNull($chapter1Id);
        $this->assertEquals('Chapitre 1 : Introduction', $data['data']['chapter']['title']);

        // --- STEP 2: Add a sub-chapter (POST /api/thesis/structure) ---
        $this->client->request('POST', '/api/thesis/structure', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'project_id' => $this->project->getId(),
            'title' => '1.1 Contexte de la recherche',
            'parent_id' => $chapter1Id
        ]));

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
        $subData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($subData['success']);
        $subChapterId = $subData['data']['chapter']['id'];

        // --- STEP 3: Add a second root chapter ---
        $this->client->request('POST', '/api/thesis/structure', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'project_id' => $this->project->getId(),
            'title' => 'Chapitre 2 : Etat de l\'art'
        ]));
        $chapter2Id = json_decode($this->client->getResponse()->getContent(), true)['data']['chapter']['id'];

        // --- STEP 4: Retrieve the tree structure (GET /api/thesis/structure) ---
        $this->client->request('GET', '/api/thesis/structure?project_id=' . $this->project->getId());
        $this->assertResponseIsSuccessful();
        $structData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($structData['success']);
        $structure = $structData['data']['structure'];

        // We expect 2 root chapters, and the first root chapter should have 1 child
        $this->assertCount(2, $structure);
        $this->assertEquals($chapter1Id, $structure[0]['id']);
        $this->assertCount(1, $structure[0]['children']);
        $this->assertEquals($subChapterId, $structure[0]['children'][0]['id']);
        $this->assertEquals($chapter2Id, $structure[1]['id']);

        // --- STEP 4.5: Retrieve individual chapter (GET /api/thesis/chapter/{id}) ---
        $this->client->request('GET', '/api/thesis/chapter/' . $chapter1Id);
        $this->assertResponseIsSuccessful();
        $chapterData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($chapterData['success']);
        $this->assertEquals('Chapitre 1 : Introduction', $chapterData['data']['chapter']['title']);

        // --- STEP 5: Update a chapter's content (PUT /api/thesis/chapter/{id}) ---
        $this->client->request('PUT', '/api/thesis/chapter/' . $subChapterId, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'title' => '1.1 Contexte et Objectifs',
            'content' => 'Voici le texte d\'introduction scientifique en LaTeX.'
        ]));
        $this->assertResponseIsSuccessful();
        $updateData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($updateData['success']);
        $this->assertEquals('1.1 Contexte et Objectifs', $updateData['data']['chapter']['title']);
        $this->assertEquals('Voici le texte d\'introduction scientifique en LaTeX.', $updateData['data']['chapter']['content']);

        // --- STEP 6: Reorder chapters (PUT /api/thesis/structure) ---
        // Let's swap the order of the two root chapters (Chapter 2 becomes order 1, Chapter 1 becomes order 2)
        // and also move the sub-chapter to Chapter 2!
        $this->client->request('PUT', '/api/thesis/structure', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'project_id' => $this->project->getId(),
            'orders' => [
                ['id' => $chapter2Id, 'parent_id' => null, 'order' => 1],
                ['id' => $chapter1Id, 'parent_id' => null, 'order' => 2],
                ['id' => $subChapterId, 'parent_id' => $chapter2Id, 'order' => 1]
            ]
        ]));
        $this->assertResponseIsSuccessful();
        $reorderData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($reorderData['success']);

        // Let's verify database state after reordering
        $this->em->clear(); // Clear cache to get fresh values from DB
        $chapter1 = $this->em->getRepository(Chapter::class)->find($chapter1Id);
        $chapter2 = $this->em->getRepository(Chapter::class)->find($chapter2Id);
        $subChapter = $this->em->getRepository(Chapter::class)->find($subChapterId);

        $this->assertEquals(2, $chapter1->getOrder());
        $this->assertNull($chapter1->getParent());
        $this->assertEquals(1, $chapter2->getOrder());
        $this->assertNull($chapter2->getParent());
        $this->assertEquals(1, $subChapter->getOrder());
        $this->assertEquals($chapter2Id, $subChapter->getParent()->getId()); // Successfully reparented!

        // --- STEP 7: Delete a chapter recursively (DELETE /api/thesis/chapter/{id}) ---
        // Let's delete Chapter 2, which should also recursively delete the sub-chapter
        $this->client->request('DELETE', '/api/thesis/chapter/' . $chapter2Id);
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Chapter::class)->find($chapter2Id));
        $this->assertNull($this->em->getRepository(Chapter::class)->find($subChapterId)); // Recursively deleted!
        $this->assertNotNull($this->em->getRepository(Chapter::class)->find($chapter1Id)); // Chapter 1 still exists!
    }
}
