<?php

namespace App\Tests\Service\Bibliography;

use App\Service\Bibliography\BibParser;
use PHPUnit\Framework\TestCase;

class BibParserTest extends TestCase
{
    private BibParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BibParser();
    }

    public function testParseEmptyContent(): void
    {
        $this->assertEmpty($this->parser->parse(''));
        $this->assertEmpty($this->parser->parse('   '));
    }

    public function testParseStandardEntries(): void
    {
        $bib = <<<BIB
@article{smith2023,
  author = {Smith, John and Doe, Jane},
  title = {A Great Study on Machine Learning},
  journal = {Journal of AI Research},
  year = {2023},
  volume = {12},
  pages = {1--10},
  doi = {10.1000/xyz123}
}

@book{knuth1997,
  author = {Knuth, Donald E.},
  title = {The Art of Computer Programming},
  publisher = {Addison-Wesley},
  year = {1997}
}
BIB;

        $entries = $this->parser->parse($bib);

        $this->assertCount(2, $entries);
        $this->assertArrayHasKey('smith2023', $entries);
        $this->assertArrayHasKey('knuth1997', $entries);

        // Vérification article
        $article = $entries['smith2023'];
        $this->assertEquals('smith2023', $article['citeKey']);
        $this->assertEquals('article', $article['type']);
        $this->assertEquals('A Great Study on Machine Learning', $article['fields']['title']);
        $this->assertEquals('Smith, John and Doe, Jane', $article['fields']['author']);
        $this->assertEquals('2023', $article['fields']['year']);
        $this->assertEquals('10.1000/xyz123', $article['fields']['doi']);

        // Vérification book
        $book = $entries['knuth1997'];
        $this->assertEquals('knuth1997', $book['citeKey']);
        $this->assertEquals('book', $book['type']);
        $this->assertEquals('The Art of Computer Programming', $book['fields']['title']);
        $this->assertEquals('Knuth, Donald E.', $book['fields']['author']);
    }

    public function testCleanValueDecodesAccents(): void
    {
        $bib = <<<BIB
@article{accented,
  author = {Ren{\\'e} Desqu{\\^e}nes and Fran{\\c{c}}ois Ch{\\\"o}se},
  title = {Etude des diff{\\'e}rents mod{\\`e}les {\\`a} l'{\\oe}uvre}
}
BIB;

        $entries = $this->parser->parse($bib);
        $this->assertArrayHasKey('accented', $entries);
        
        $fields = $entries['accented']['fields'];
        $this->assertEquals('René Desquênes and François Chöse', $fields['author']);
        $this->assertEquals("Etude des différents modèles à l'œuvre", $fields['title']);
    }

    public function testGetStats(): void
    {
        $bib = <<<BIB
@article{a1, title={t1}}
@article{a2, title={t2}}
@book{b1, title={t3}}
BIB;

        $stats = $this->parser->getStats($bib);
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['byType']['article']);
        $this->assertEquals(1, $stats['byType']['book']);
    }
}
