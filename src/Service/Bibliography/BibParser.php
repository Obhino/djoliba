<?php

namespace App\Service\Bibliography;

/**
 * BibParser — Parseur pur PHP de fichiers BibTeX (.bib)
 *
 * Parse un contenu BibTeX brut et retourne un tableau structuré
 * d'entrées bibliographiques sans aucune dépendance externe.
 *
 * Supporte : @article, @book, @inproceedings, @misc, @phdthesis,
 *            @mastersthesis, @techreport, @conference, @unpublished, @online
 */
class BibParser
{
    /**
     * Correspondance des types BibTeX vers des étiquettes normalisées.
     */
    private const ENTRY_TYPES = [
        'article'       => 'article',
        'book'          => 'book',
        'booklet'       => 'book',
        'inproceedings' => 'inproceedings',
        'conference'    => 'inproceedings',
        'incollection'  => 'incollection',
        'phdthesis'     => 'phdthesis',
        'mastersthesis' => 'mastersthesis',
        'techreport'    => 'techreport',
        'misc'          => 'misc',
        'unpublished'   => 'misc',
        'online'        => 'misc',
        'manual'        => 'misc',
    ];

    /**
     * Parse un contenu BibTeX complet.
     *
     * @return array<string, array{citeKey: string, type: string, fields: array<string, string>}>
     *         Tableau indexé par citeKey.
     */
    public function parse(string $bibContent): array
    {
        if (empty(trim($bibContent))) {
            return [];
        }

        $entries = [];

        // Supprimer les commentaires BibTeX (@comment, %)
        $content = preg_replace('/%[^\n]*/', '', $bibContent);
        $content = preg_replace('/@comment\s*\{[^}]*\}/si', '', $content);

        // Trouver chaque occurence de @type{
        $offset = 0;
        while (($pos = strpos($content, '@', $offset)) !== false) {
            $offset = $pos + 1;
            
            // Lire le type de l'entrée
            if (!preg_match('/^@([a-zA-Z]+)\s*\{/', substr($content, $pos), $typeMatch)) {
                continue;
            }
            
            $rawType = strtolower($typeMatch[1]);
            $startPos = $pos + strlen($typeMatch[0]);
            
            // Trouver le corps de l'entrée en équilibrant les accolades
            $braceCount = 1;
            $len = strlen($content);
            $body = '';
            $endPos = $startPos;
            
            for ($i = $startPos; $i < $len; $i++) {
                $char = $content[$i];
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                }
                
                if ($braceCount === 0) {
                    $body = substr($content, $startPos, $i - $startPos);
                    $endPos = $i + 1;
                    break;
                }
            }
            
            if ($braceCount !== 0) {
                // Braces non fermées, ignorer l'entrée
                continue;
            }
            
            // Avancer l'offset de recherche après l'entrée
            $offset = $endPos;
            
            // Séparer la clé de citation des champs
            $parts = explode(',', $body, 2);
            $citeKey = trim($parts[0]);
            $fieldsRaw = $parts[1] ?? '';
            
            if (empty($citeKey)) {
                continue;
            }
            
            $type = self::ENTRY_TYPES[$rawType] ?? 'misc';
            $fields = $this->parseFields($fieldsRaw);
            
            $entries[$citeKey] = [
                'citeKey' => $citeKey,
                'type'    => $type,
                'fields'  => $fields,
            ];
        }

        return $entries;
    }

    /**
     * Parse les champs d'une entrée BibTeX.
     *
     * @return array<string, string>
     */
    private function parseFields(string $fieldsRaw): array
    {
        $fields = [];

        // Regex robuste pour capturer : key = {value} ou key = "value" ou key = value
        preg_match_all(
            '/([a-zA-Z_]+)\s*=\s*(?:\{((?:[^{}]|\{[^{}]*\})*)\}|"((?:[^"\\\\]|\\\\.)*)"|([^,\n]+))/s',
            $fieldsRaw,
            $fieldMatches,
            PREG_SET_ORDER
        );

        foreach ($fieldMatches as $fm) {
            $key = strtolower(trim($fm[1]));
            // Priorité : {value} > "value" > valeur nue
            $value = $fm[2] !== '' ? $fm[2]
                : ($fm[3] !== '' ? $fm[3] : trim($fm[4] ?? ''));

            $fields[$key] = $this->cleanValue($value);
        }

        return $fields;
    }

    /**
     * Nettoie la valeur d'un champ BibTeX :
     * - Décode les accents LaTeX courants
     * - Supprime les doubles accolades résiduelles
     * - Normalise les espaces
     */
    private function cleanValue(string $value): string
    {
        // Décoder les accents LaTeX courants
        $latexAccents = [
            "\\'a" => 'á', "\\'e" => 'é', "\\'i" => 'í', "\\'o" => 'ó', "\\'u" => 'ú',
            "\\'A" => 'Á', "\\'E" => 'É', "\\'I" => 'Í', "\\'O" => 'Ó', "\\'U" => 'Ú',
            "\\`a" => 'à', "\\`e" => 'è', "\\`i" => 'ì', "\\`o" => 'ù', "\\`u" => 'ù',
            "\\`A" => 'À', "\\`E" => 'È', "\\`I" => 'Ì', "\\`O" => 'Ò', "\\`U" => 'Ù',
            "\\^a" => 'â', "\\^e" => 'ê', "\\^i" => 'î', "\\^o" => 'ô', "\\^u" => 'û',
            "\\^A" => 'Â', "\\^E" => 'Ê', "\\^I" => 'Î', "\\^O" => 'Ô', "\\^U" => 'Û',
            '\\"a' => 'ä', '\\"e' => 'ë', '\\"i' => 'ï', '\\"o' => 'ö', '\\"u' => 'ü',
            '\\"A' => 'Ä', '\\"E' => 'Ë', '\\"I' => 'Ï', '\\"O' => 'Ö', '\\"U' => 'Ü',
            "\\~n" => 'ñ', "\\~N" => 'Ñ', "\\~a" => 'ã', "\\~o" => 'õ',
            "\\c{c}" => 'ç', "\\c{C}" => 'Ç',
            "\\ss" => 'ß',
            "\\ae" => 'æ', "\\AE" => 'Æ',
            "\\oe" => 'œ', "\\OE" => 'Œ',
            "\\aa" => 'å', "\\AA" => 'Å',
            "--" => '–', "---" => '—',
        ];

        // 1. Traduire les accents de base
        $value = strtr($value, $latexAccents);

        // 2. Supprimer les accolades de protection LaTeX
        $value = preg_replace('/\{([^{}]*)\}/', '$1', $value);
        $value = str_replace(['{', '}'], '', $value);

        // 3. Traduire à nouveau pour attraper les accents libérés de leurs accolades
        $value = strtr($value, $latexAccents);

        // Nettoyer les backslashes résiduels devant les accents traduits
        $value = preg_replace('/\\\\([áéíóúÁÉÍÓÚàèìòùÀÈÌÒÙâêîôûÂÊÎÔÛäëïöüÄËÏÖÜñÑãõçÇßæÆœŒåÅ])/', '$1', $value);
        
        // Supprimer les doubles backslashes résiduels
        $value = str_replace('\\', '', $value);

        // Normaliser les espaces multiples
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * Extrait un champ spécifique depuis un tableau de champs.
     * Essaie plusieurs noms de champs (alias) par ordre de priorité.
     */
    public function extractField(array $fields, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (!empty($fields[$key])) {
                return $fields[$key];
            }
        }
        return $default;
    }

    /**
     * Valide que le fichier .bib contient au moins une entrée valide.
     */
    public function validate(string $bibContent): bool
    {
        $entries = $this->parse($bibContent);
        return count($entries) > 0;
    }

    /**
     * Retourne les statistiques de parsing pour un contenu .bib.
     *
     * @return array{total: int, byType: array<string, int>}
     */
    public function getStats(string $bibContent): array
    {
        $entries = $this->parse($bibContent);
        $byType = [];

        foreach ($entries as $entry) {
            $type = $entry['type'];
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total'  => count($entries),
            'byType' => $byType,
        ];
    }
}
