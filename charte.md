# Charte Graphique et Analyse du Projet — Djoliba

Ce document présente l'analyse stratégique du projet Djoliba ainsi que les directives graphiques destinées à guider le développement de l'interface utilisateur (UI/UX) pour la Phase 1.

---

## 1. Analyse du Projet Djoliba

### 1.1 Positionnement et ADN
[cite_start]Djoliba se positionne comme la **première plateforme autonome de recherche scientifique en Afrique**[cite: 2, 15, 18]. [cite_start]Le nom lui-même, inspiré du fleuve Niger, évoque la fluidité, la transmission du savoir et un ancrage territorial fort[cite: 15, 17]. [cite_start]L'outil concilie une **haute technicité** (recherche sémantique vectorielle, intelligence artificielle avec l'API DeepSeek) [cite: 31, 61, 65] [cite_start]et une **accessibilité inclusive**, en visant à lever les barrières financières et techniques pour la communauté scientifique africaine[cite: 18].

### 1.2 Public Cible (Phase 1)
* [cite_start]**Utilisateurs :** Chercheurs académiques, doctorants, post-doctorants, enseignants-chercheurs[cite: 21].
* [cite_start]**Géographie prioritaire :** Côte d'Ivoire, Sénégal[cite: 15, 20].
* [cite_start]**Langues :** Français et Anglais[cite: 22].
* [cite_start]**Attentes clés :** Rigueur absolue (aucune hallucination de citation) [cite: 105][cite_start], clarté visuelle pour la lecture prolongée [cite: 33, 41] [cite_start]et interface fluide s'intégrant nativement avec la stack technologique (Symfony UX + Tailwind CSS)[cite: 61, 114].

---

## 2. Palette de Couleurs (Hexadécimal & Tailwind)

Les couleurs choisies traduisent le sérieux du monde académique, l'innovation de l'IA et rappellent subtilement l'identité du continent.

| Usage | Nom de la couleur | Code HEX | Classe Tailwind (Proche) | Signification & Intention |
| :--- | :--- | :--- | :--- | :--- |
| **Primaire** | Bleu Djoliba | `#0B2545` | `bg-[#0B2545]` | Évoque le fleuve, la profondeur du savoir et la confiance académique. |
| **Secondaire** | Vert Émeraude IA | `#10B981` | `text-emerald-500` | [cite_start]Symbolise l'innovation technologique et la puissance de l'IA (DeepSeek)[cite: 13, 61]. |
| **Accent** | Or Prestige | `#F59E0B` | `amber-500` / `yellow-500` | Touche chaleureuse évoquant la lumière, l'Afrique et l'excellence. À utiliser pour les CTA importants. |
| **Fonds Neutres** | Blanc Cassé | `#F8FAFC` | `bg-slate-50` | [cite_start]Garantit un confort de lecture optimal pour les textes longs (thèses, mémoires)[cite: 41]. |
| **Texte Principal**| Gris Ardoise | `#1E293B` | `text-slate-800` | [cite_start]Moins agressif que le noir pur pour réduire la fatigue oculaire lors des revues de littérature[cite: 28]. |

---

## 3. Typographie

Afin de garantir une lisibilité irréprochable sur l'interface bilingue, deux polices de caractères complémentaires sont recommandées.

### 3.1 Titres (H1, H2, H3)
* **Police :** `Plus Jakarta Sans` ou `Inter` (Sans-Serif)
* **Style :** Moderne, géométrique et épuré. Donnes un aspect "SaaS technologique" haut de gamme et lisible.
* **Intégration Tailwind :** `font-sans font-bold tracking-tight`

### 3.2 Corps de texte (Paragraphs, Listes, Tableaux)
* **Police :** `Inter` (Sans-Serif) ou optionnellement `Merriweather` (Serif) pour les blocs de lecture.
* [cite_start]**Style :** Une police Serif peut être proposée en option ("Mode Lecture") pour les synthèses d'articles générées [cite: 35][cite_start], car elle fatigue moins les yeux des chercheurs habitués aux formats papier et PDF[cite: 34, 84].
* **Intégration Tailwind :** `font-normal leading-relaxed text-slate-800`

---

## 4. Composants d'Interface et Iconographie (Tailwind CSS)

### 4.1 Formes et Structures
* [cite_start]**Bords arrondis :** Utilisation de l'arrondi moyen à large (`rounded-xl` à `rounded-2xl`) pour moderniser et adoucir l'interface Symfony UX[cite: 61].
* [cite_start]**Ombres :** Des ombres très légères (`shadow-sm` ou `shadow-md` au survol) pour détacher les cartes contenant les résumés d'articles ou les suggestions de la revue de littérature[cite: 29, 35].

### 4.2 Iconographie (Style Filaire / Line Icons)
Utilisation d'une bibliothèque moderne comme *Heroicons* ou *Lucide Icons* (parfaitement adaptées à Tailwind).
* **IA / Suggestions :** Icône d'étincelle ou de réseau de neurones (`sparkles`).
* [cite_start]**Sources / Bibliographie :** Icône de livre ou de document lié, rappelant l'intégration Zotero/Mendeley[cite: 49].
* [cite_start]**Exports :** Icônes distinctives et claires pour le PDF, le LaTeX et le BibTeX[cite: 86].

### 4.3 Visualisation de l'Intelligence Artificielle
* [cite_start]Lorsque l'IA exécute une action (ex: génération du fondement théorique) [cite: 30][cite_start], utiliser un effet de chargement discret (ex: `animate-pulse`) teinté de la couleur secondaire *Vert Émeraude* (`#10B981`) pour indiquer le travail de l'algorithme sans perturber l'expérience utilisateur[cite: 114].

---

## 5. Concept du Logo (Pistes UI)

Le logo de **Djoliba / DjolibaSearch** doit être minimaliste et s'adapter parfaitement à la barre de navigation (Navbar).

1. [cite_start]**Le Symbole (Isotype) :** Un **D** majuscule stylisé où la courbe droite n'est pas pleine, mais formée par une ligne fluide imitant le mouvement d'un cours d'eau (le fleuve) et se terminant par des points connectés (symbolisant les vecteurs de données et l'IA)[cite: 31].
2. [cite_start]**Le Texte (Logotype) :** Le mot "Djoliba" en gras (`font-bold text-[#0B2545]`) suivi du mot "Search" ou du sous-titre "La 1ère plateforme autonome de recherche scientifique en Afrique" dans une graisse plus fine[cite: 2, 17].

---

## 6. Accessibilité et Adaptabilité

* [cite_start]**Mode Sombre (Dark Mode) :** Essentiel pour les chercheurs travaillant de nuit sur la rédaction longue[cite: 41]. Le fond passe du blanc cassé (`slate-50`) à un bleu nuit profond (`slate-950`).
* [cite_start]**Design Responsif :** L'interface construite avec Tailwind CSS doit rester parfaitement fluide et lisible sur tous les écrans (ordinateurs portables de recherche, tablettes)[cite: 61, 114].