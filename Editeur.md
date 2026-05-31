# CONTEXTE ÉDITEUR DJOLIBA (WYSIWYG) – NE PAS CASSER L’EXISTANT

## Fonctionnalités déjà opérationnelles (NE PAS MODIFIER)
- ✅ Mathématiques KaTeX (inline $...$, display $$...$$)
- ✅ Transcription HTML → Preview en temps réel
- ✅ Sauvegarde automatique (debounce 2s)
- ✅ Bascule entre mode WYSIWYG et mode LaTeX brut
- ✅ Import Word (DOCX → HTML)
- ✅ Export LaTeX basique

## Fichiers stables (modifications interdites sur ces parties)
assets/controllers/writing_editor_controller.js
  - Méthodes existantes : connect(), initializeEditor(), updatePreview(), saveContent()
  - Extensions TipTap existantes : StarterKit, Placeholder, Link, Image
  - NE PAS TOUCHER à renderMathInElement() et à la gestion KaTeX
  - NE PAS TOUCHER à la transcription en temps réel

## Nouvelles fonctionnalités à ajouter SANS CASSER
1. Tableaux (TipTap Table extension)
2. Figures avec légende (extension personnalisée)
3. Références croisées
4. Blocs de code (highlight.js)
5. Notes de bas de page
6. Export LaTeX enrichi (backend)

## Règles de modification
- Ajouter des méthodes, ne pas supprimer/modifier les existantes
- Nouvelles extensions via editor.commands ou editor.extension()
- Les maths restent en $...$ / $$...$$ dans le HTML stocké
- La transcription continue d’utiliser la même méthode updatePreview()

## Dépendances à installer
@tiptap/extension-table
@tiptap/extension-table-row
@tiptap/extension-table-cell
@tiptap/extension-table-header
highlight.js

Contexte : fichier CONTEXTE ci-dessus.

Ajoute les tableaux à l’éditeur TipTap SANS modifier les parties existantes.

1. Installe les extensions @tiptap/extension-table et ses dépendances.

2. Dans writing_editor_controller.js :
   - Ajoute les extensions Table, TableRow, TableCell, TableHeader dans l’éditeur existant (ligne extensions: [ ... ])
   - Ajoute un bouton "Tableau" dans la barre d’outils
   - Le bouton ouvre un modal (lignes, colonnes)
   - Utilise editor.commands.insertTable() (NE PAS modifier les commandes existantes)

3. NE PAS toucher à initializeEditor(), updatePreview(), renderMathInElement().
4. NE PAS supprimer/modifier les extensions existantes.

Génère uniquement le code à ajouter, pas le fichier entier.

Contexte : fichier CONTEXTE ci-dessus.

Ajoute les figures avec légende.

1. Crée une extension TipTap personnalisée `FigureExtension` :
   - Structure : <figure data-label="..."><img><figcaption contenteditable="true">...</figcaption></figure>
   - La légende est editable

2. Dans writing_editor_controller.js :
   - Ajoute l’extension
   - Ajoute un bouton "Figure" dans la barre d’outils
   - Modal : upload image (ou URL), légende, label optionnel

3. NE PAS casser les maths (renderMathInElement déjà appelé dans updatePreview)

Génère l’extension et le code d’intégration.

Contexte : fichier CONTEXTE ci-dessus.

Ajoute les références croisées.

1. Dans writing_editor_controller.js, ajoute une méthode `getAllLabels()` :
   - Parcourt le document TipTap
   - Extrait les data-label des figures, sections, équations

2. Ajoute un bouton "Référence" :
   - Ouvre modal listant les labels
   - Insère <a href="#label" class="reference">texte</a>

3. NE PAS modifier updatePreview() existant (juste ajouter une classe CSS)

Génère le code.

Contexte : fichier CONTEXTE ci-dessus.

Ajoute les blocs de code avec coloration syntaxique.

1. Utilise @tiptap/extension-code-block-lowlight

2. Dans writing_editor_controller.js :
   - Ajoute l’extension CodeBlockLowlight
   - Ajoute un bouton "Code" : modal (langage) → insert code block

3. Dans updatePreview(), après le rendu HTML, applique highlight.js sur les <pre><code>

4. NE PAS casser l’existant (appel à renderMathInElement déjà présent)

Génère le code d’intégration.

Contexte : fichier CONTEXTE ci-dessus.

Ajoute les notes de bas de page.

1. Dans writing_editor_controller.js :
   - Compteur interne pour les notes
   - Bouton "Note" : insère <sup data-fn="1">[1]</sup> à la position du curseur
   - Ajoute en fin de document <div class="footnotes"><hr><p id="fn1">[1] </p></div>

2. En preview, les <sup> sont rendus comme des liens (#fn1)

3. NE PAS toucher aux maths.

Génère le code.

Contexte : fichier CONTEXTE ci-dessus.

Enrichit l’export LaTeX pour supporter les nouvelles fonctionnalités.

1. Modifie src/Service/Converter/HtmlToLatex.php :
   - convertTables() : <table> → \begin{tabular}
   - convertFigures() : <figure> → \begin{figure}
   - convertReferences() : <a href="#label"> → \ref{label}
   - convertFootnotes() : <sup> → \footnote
   - convertCodeBlock() : <pre><code> → \begin{verbatim}

2. Crée src/Controller/Api/ExportController.php (si non existant) :
   - POST /api/projects/{id}/export/latex
   - Reçoit { html: "..." }
   - Retourne .tex file

3. Dans writing_editor_controller.js, la méthode exportToLatex() existe déjà ? Si non, ajoute-la.

Génère les fichiers backend complets.