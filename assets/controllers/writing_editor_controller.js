import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus Controller — writing-editor (Rich Editor Scientific v2)
 *
 * Gère l'éditeur scientifique hybride réutilisable :
 * - Mode WYSIWYG (TipTap / ProseMirror)
 * - Mode LaTeX Brut / Markdown (Textarea standard)
 * - Prévisualisation stylisée temps réel (Marked.js + KaTeX)
 * - Importation intelligente (DOCX via Mammoth.js)
 * - Sauvegarde automatique debouncée (2s) en LocalStorage et Backend
 * - Exportation LaTeX (.tex)
 */
export default class extends Controller {
    static targets = [
        'input', 'checkBtn', 'suggestBtn', 'status', 'results',
        'originalityModal', 'originalityContent',
        'journalModal', 'journalContent',
        'fileInput', 'previewModal', 'previewContent', 'confirmImportBtn', 'importBtn',
        'editorContainer', 'previewContainer', 'wordCount', 'charCount', 'pageCount',
        'modeWysiwygBtn', 'modeLatexBtn', 'helpModal', 'latexPreviewModal', 'latexPreviewContent',
        'previewBtn', 'renderBtn', 'wysiwygInput',
        'tableModal', 'tableCaptionInput', 'tableLabelInput', 'tableGridContainer', 'tableDeleteBtn',
        'figureModal', 'figureUrlInput', 'figureCaptionInput', 'figureLabelInput', 'figureDeleteBtn',
        'footnoteModal', 'footnoteTextInput',
        'referenceModal', 'referenceLabelSelect',
        'toolbarDeleteTableBtn', 'toolbarDeleteFigureBtn',
        'figureImageFileInput', 'figureUploadStatus',
        'mathModal', 'mathFormulaInput', 'mathDisplaySelect',
        'searchReplaceBar', 'searchInput', 'replaceInput', 'searchIndexIndicator',
        'focusBtn',
        'outlineBtn', 'outlinePanel', 'outlineContent',
        'snapshotModal', 'snapshotNameInput', 'snapshotList', 'snapshotEmptyMsg'
    ];

    static values = {
        projectId: Number,
        saveUrl: String,
        exportUrl: String,
        placeholder: String,
        storageKey: String,
        initialMode: String
    };

    async connect() {
        this.currentMode = this.hasInitialModeValue ? this.initialModeValue : 'wysiwyg';
        this.tipTapLoaded = false;
        this.autosaveTimeout = null;
        this.fontSize = 13;
        this.searchResults = [];
        this.currentSearchIndex = -1;

        // Éviter tout dysfonctionnement si la zone brute n'est pas encore visible
        if (this.hasInputTarget) {
            this.inputTarget.style.fontSize = `${this.fontSize}px`;
        }

        // Raccourcis clavier
        this.handleShortcutsBound = this.handleShortcuts.bind(this);
        document.addEventListener('keydown', this.handleShortcutsBound);

        try {
            await this.#loadLibraries();
            this.#initEditor();
            this.#initCodeMirror();
            this.#updateCounters();
            this.#updatePreview();
        } catch (err) {
            console.error("Échec d'initialisation de l'éditeur :", err);
        }
    }

    disconnect() {
        if (this.editor) {
            this.editor.destroy();
        }
        if (this.autosaveTimeout) {
            clearTimeout(this.autosaveTimeout);
        }
        document.removeEventListener('keydown', this.handleShortcutsBound);
    }

    // ─────────────────────────────────────────────
    // Initialisation & Chargement Dynamique
    // ─────────────────────────────────────────────

    async #loadLibraries() {
        this.#setStatus("Chargement des modules d'édition...");

        // Injecter KaTeX, CodeMirror et Highlight.js CSS si absents
        this.#loadStylesheet('https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css');
        this.#loadStylesheet('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css');
        this.#loadStylesheet('https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css');

        try {
            // Importation asynchrone parallélisée de tous les moteurs côté client
            const [
                { Editor, Node, Extension },
                StarterKitModule,
                PlaceholderModule,
                LinkModule,
                ImageModule,
                TurndownModule,
                MarkedModule,
                KatexModule,
                AutoRenderModule,
                TableModule,
                TableRowModule,
                TableCellModule,
                TableHeaderModule
            ] = await Promise.all([
                import('https://esm.sh/@tiptap/core@2.2.4'),
                import('https://esm.sh/@tiptap/starter-kit@2.2.4'),
                import('https://esm.sh/@tiptap/extension-placeholder@2.2.4'),
                import('https://esm.sh/@tiptap/extension-link@2.2.4'),
                import('https://esm.sh/@tiptap/extension-image@2.2.4'),
                import('https://esm.sh/turndown@7.1.2?exports=default'),
                import('https://esm.sh/marked@11.1.1?exports=marked'),
                import('https://esm.sh/katex@0.16.9?exports=default'),
                import('https://esm.sh/katex@0.16.9/dist/contrib/auto-render.js?exports=default'),
                import('https://esm.sh/@tiptap/extension-table@2.2.4'),
                import('https://esm.sh/@tiptap/extension-table-row@2.2.4'),
                import('https://esm.sh/@tiptap/extension-table-cell@2.2.4'),
                import('https://esm.sh/@tiptap/extension-table-header@2.2.4')
            ]);

            this.EditorClass = Editor;
            this.NodeClass = Node;
            this.ExtensionClass = Extension;
            this.StarterKit = StarterKitModule.default || StarterKitModule;
            this.Placeholder = PlaceholderModule.default || PlaceholderModule;
            this.Link = LinkModule.default || LinkModule;
            this.Image = ImageModule.default || ImageModule;
            this.Turndown = TurndownModule.default || TurndownModule;
            this.marked = MarkedModule.marked || MarkedModule;
            this.katex = KatexModule.default || KatexModule;
            this.renderMathInElement = AutoRenderModule.default || AutoRenderModule;

            // Dépendances de Tableaux
            this.Table = TableModule.default || TableModule;
            this.TableRow = TableRowModule.default || TableRowModule;
            this.TableCell = TableCellModule.default || TableCellModule;
            this.TableHeader = TableHeaderModule.default || TableHeaderModule;

            // Rendre KaTeX disponible globalement pour auto-render
            window.katex = this.katex;
            window.renderMathInElement = this.renderMathInElement;

            // Initialiser le convertisseur Turndown (HTML -> Markdown)
            this.turndownService = new this.Turndown({
                headingStyle: 'atx',
                hr: '---',
                bulletListMarker: '-',
                codeBlockStyle: 'fenced'
            });

            // Préserver les balises HTML scientifiques pour éviter de perdre les tableaux, figures et notes de bas de page lors du round-trip HTML <-> Markdown
            this.turndownService.keep(['table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'figcaption', 'img', 'sup']);

            // Charger CodeMirror & Highlight.js
            await Promise.all([
                this.#loadCodeMirrorScripts(),
                this.#loadScript('https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js')
            ]);

            this.tipTapLoaded = true;
            this.#setStatus("");
        } catch (error) {
            console.error("Erreur d'import des bibliothèques :", error);
            this.#setStatus("Impossible d'initialiser les modules d'écriture (vérifiez votre connexion)", true);
            throw error;
        }
    }

    #loadCodeMirrorScripts() {
        if (window.CodeMirror) return Promise.resolve(window.CodeMirror);
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js';
            script.async = true;
            script.onload = () => {
                const stexScript = document.createElement('script');
                stexScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/stex/stex.min.js';
                stexScript.async = true;
                stexScript.onload = () => {
                    resolve(window.CodeMirror);
                };
                stexScript.onerror = () => reject(new Error("Erreur de chargement du mode LaTeX."));
                document.head.appendChild(stexScript);
            };
            script.onerror = () => reject(new Error("Erreur de chargement de CodeMirror."));
            document.head.appendChild(script);
        });
    }

    #initCodeMirror() {
        if (!window.CodeMirror || !this.hasInputTarget) return;

        this.codeMirror = window.CodeMirror.fromTextArea(this.inputTarget, {
            mode: 'stex',
            lineNumbers: true,
            lineWrapping: true,
            tabSize: 2,
            indentWithTabs: false
        });

        // Ajouter la classe de thème Djoliba
        this.codeMirror.getWrapperElement().classList.add('djoliba-latex-theme');

        // Mettre à jour la taille de police initiale sur CodeMirror
        const wrapper = this.codeMirror.getWrapperElement();
        wrapper.style.fontSize = `${this.fontSize}px`;

        if (this.currentMode === 'wysiwyg') {
            wrapper.classList.add('hidden');
        }

        // Sync avec inputTarget et counters
        this.codeMirror.on('change', (instance) => {
            this.inputTarget.value = instance.getValue();
            this.#handleContentChange();
        });
    }

    #loadStylesheet(href) {
        if (document.querySelector(`link[href="${href}"]`)) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    #loadScript(src) {
        if (document.querySelector(`script[src="${src}"]`)) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
            document.head.appendChild(script);
        });
    }

    #initEditor() {
        if (!this.tipTapLoaded || !this.hasEditorContainerTarget) return;

        const initialMarkdown = this.hasWysiwygInputTarget ? this.wysiwygInputTarget.value : '';
        const initialHtml = initialMarkdown ? this.marked.parse(initialMarkdown) : '';
        const placeholderText = this.hasPlaceholderValue ? this.placeholderValue : 'Rédigez votre manuscrit scientifique ici (Markdown & LaTeX supportés)...';

        // Configurer la visibilité initiale selon le mode de départ
        if (this.hasInputTarget) {
            this.inputTarget.classList.add('hidden');
        }
        if (this.currentMode === 'wysiwyg') {
            this.editorContainerTarget.classList.remove('hidden');
        } else {
            this.editorContainerTarget.classList.add('hidden');
        }

        // Définir les extensions personnalisées pour les Figures scientifiques
        const Figure = this.NodeClass.create({
            name: 'figure',
            group: 'block',
            content: 'image figcaption',
            addAttributes() {
                return {
                    label: { default: '' }
                };
            },
            parseHTML() {
                return [{ tag: 'figure', getAttrs: dom => ({ label: dom.getAttribute('data-label') || '' }) }];
            },
            renderHTML({ HTMLAttributes }) {
                return ['figure', { 'data-label': HTMLAttributes.label, class: 'my-6 p-4 bg-slate-50 rounded-2xl border border-slate-100 flex flex-col items-center' }, 0];
            }
        });

        const Figcaption = this.NodeClass.create({
            name: 'figcaption',
            group: 'block',
            content: 'inline*',
            selectable: false,
            parseHTML() {
                return [{ tag: 'figcaption' }];
            },
            renderHTML() {
                return ['figcaption', { class: 'text-center text-xs text-slate-500 italic mt-2 focus:outline-none w-full' }, 0];
            }
        });

        this.editor = new this.EditorClass({
            element: this.editorContainerTarget,
            extensions: [
                this.StarterKit,
                this.Placeholder.configure({
                    placeholder: placeholderText,
                    emptyEditorClass: 'is-editor-empty'
                }),
                this.Link.configure({
                    openOnClick: false,
                    HTMLAttributes: { class: 'text-blue-500 underline' }
                }),
                this.Image.configure({
                    HTMLAttributes: { class: 'max-w-full rounded-2xl border border-slate-100 my-4 shadow-sm' }
                }),
                this.Table.configure({
                    resizable: true,
                    HTMLAttributes: { class: 'w-full border-collapse border border-slate-200 my-4 text-xs' }
                }),
                this.TableRow,
                this.TableCell.configure({
                    HTMLAttributes: { class: 'border border-slate-200 p-2 text-slate-700' }
                }),
                this.TableHeader.configure({
                    HTMLAttributes: { class: 'border border-slate-200 p-2 bg-slate-50 text-djoliba font-bold' }
                }),
                Figure,
                Figcaption
            ],
            content: initialHtml,
            editorProps: {
                attributes: {
                    class: 'focus:outline-none min-h-[480px] h-full p-6 text-xs text-slate-800 leading-relaxed font-sans prose prose-slate max-w-none focus:ring-0 focus:border-transparent custom-scrollbar overflow-y-auto'
                },
                handleDoubleClick: (view, pos, event) => {
                    const tableEl = event.target.closest('table');
                    if (tableEl) {
                        this.openEditTableModal(tableEl);
                        return true;
                    }
                    const figureEl = event.target.closest('figure');
                    if (figureEl) {
                        this.openEditFigureModal(figureEl);
                        return true;
                    }
                    return false;
                },
                handleKeyDown: (view, event) => {
                    const { state } = view;
                    const { selection } = state;
                    const $from = selection.$from;
                    
                    // 1. Détecter si on est à l'intérieur d'un tableau
                    let tableDepth = -1;
                    for (let i = $from.depth; i >= 0; i--) {
                        if ($from.node(i).type.name === 'table') {
                            tableDepth = i;
                            break;
                        }
                    }
                    
                    if (tableDepth === -1) {
                        return false;
                    }
                    
                    const tableNode = $from.node(tableDepth);
                    const tablePos = $from.before(tableDepth);
                    
                    // 2. Détecter la cellule courante
                    let cellDepth = -1;
                    for (let i = $from.depth; i > 0; i--) {
                        const name = $from.node(i).type.name;
                        if (name === 'tableCell' || name === 'tableHeader') {
                            cellDepth = i;
                            break;
                        }
                    }
                    
                    if (cellDepth === -1) return false;
                    const cellPos = $from.before(cellDepth);
                    
                    // Cas A : Flèche Bas dans la dernière ligne du tableau
                    if (event.key === 'ArrowDown') {
                        let isLastRow = false;
                        let rowDepth = cellDepth - 1;
                        if (rowDepth > 0 && $from.node(rowDepth).type.name === 'tableRow') {
                            const rowNode = $from.node(rowDepth);
                            const rowPos = $from.before(rowDepth);
                            
                            const tableEnd = tablePos + tableNode.nodeSize;
                            const rowEnd = rowPos + rowNode.nodeSize;
                            
                            // Si la ligne se termine juste avant la fermeture du tableau
                            if (rowEnd >= tableEnd - 2) {
                                isLastRow = true;
                            }
                        }
                        
                        if (isLastRow) {
                            const insertPos = tablePos + tableNode.nodeSize;
                            const tr = state.tr;
                            
                            const nextNode = state.doc.nodeAt(insertPos);
                            if (!nextNode || nextNode.type.name !== 'paragraph') {
                                const paragraph = state.schema.nodes.paragraph.create();
                                tr.insert(insertPos, paragraph);
                            }
                            
                            const $pos = tr.doc.resolve(insertPos + 1);
                            tr.setSelection(new state.selection.constructor($pos));
                            view.dispatch(tr);
                            view.focus();
                            
                            this.#handleContentChange();
                            return true;
                        }
                    }
                    
                    // Cas B : Entrée dans la dernière cellule du tableau à la fin du texte
                    if (event.key === 'Enter' && !event.shiftKey) {
                        let isLastCell = false;
                        tableNode.descendants((node, pos) => {
                            if (node.type.name === 'tableCell' || node.type.name === 'tableHeader') {
                                const absolutePos = tablePos + 1 + pos;
                                if (absolutePos === cellPos) {
                                    isLastCell = true;
                                } else if (absolutePos > cellPos) {
                                    isLastCell = false;
                                }
                            }
                        });
                        
                        if (isLastCell) {
                            const isAtCellEnd = selection.$from.parentOffset === selection.$from.parent.content.size;
                            if (isAtCellEnd) {
                                const insertPos = tablePos + tableNode.nodeSize;
                                const tr = state.tr;
                                
                                const nextNode = state.doc.nodeAt(insertPos);
                                if (!nextNode || nextNode.type.name !== 'paragraph') {
                                    const paragraph = state.schema.nodes.paragraph.create();
                                    tr.insert(insertPos, paragraph);
                                }
                                
                                const $pos = tr.doc.resolve(insertPos + 1);
                                tr.setSelection(new state.selection.constructor($pos));
                                view.dispatch(tr);
                                view.focus();
                                
                                this.#handleContentChange();
                                return true;
                            }
                        }
                    }
                    
                    return false;
                }
            },
            onUpdate: () => {
                this.#handleContentChange();
            },
            onSelectionUpdate: () => {
                this.#updateActiveNodes();
            }
        });

        this.#updateToggleButtons();
        this.#updateActiveNodes();
    }

    // ─────────────────────────────────────────────
    // Rendu, Synchronisation et Bascule
    // ─────────────────────────────────────────────

    #handleContentChange() {
        this.#updateCounters();
        this.#updatePreview();
        this.updateOutline();
        this.#triggerAutosave();
    }

    #getMarkdown() {
        if (this.currentMode === 'wysiwyg' && this.editor) {
            const html = this.editor.getHTML();
            return this.turndownService.turndown(html);
        }
        return this.hasInputTarget ? this.inputTarget.value : '';
    }

    #updatePreview() {
        if (!this.hasPreviewContainerTarget) return;

        let html = '';
        if (this.currentMode === 'wysiwyg' && this.editor) {
            html = this.editor.getHTML();
        } else {
            const markdown = this.#getMarkdown();
            html = this.marked.parse(markdown);
        }
        
        this.previewContainerTarget.innerHTML = html;

        // Rendu des titres de tableaux avec titre (data-caption)
        this.previewContainerTarget.querySelectorAll('table[data-caption]').forEach(table => {
            const caption = table.getAttribute('data-caption');
            const label = table.getAttribute('data-label') || '';
            const captionEl = document.createElement('div');
            captionEl.className = 'text-center text-xs text-slate-500 italic mb-2 mt-4 font-semibold';
            captionEl.textContent = `Tableau : ${caption} ${label ? `(${label})` : ''}`;
            table.parentNode.insertBefore(captionEl, table);
        });

        // Rendu mathématique automatique KaTeX côté client
        if (window.renderMathInElement) {
            window.renderMathInElement(this.previewContainerTarget, {
                delimiters: [
                    { left: '$$', right: '$$', display: true },
                    { left: '$', right: '$', display: false },
                    { left: '\\(', right: '\\)', display: false },
                    { left: '\\[', right: '\\]', display: true }
                ],
                throwOnError: false
            });
        }

        // Coloration syntaxique des blocs de code
        if (window.hljs) {
            this.previewContainerTarget.querySelectorAll('pre code').forEach((block) => {
                window.hljs.highlightElement(block);
            });
        }
    }

    #updateCounters() {
        const text = this.currentMode === 'wysiwyg' && this.editor ? this.editor.getText() : (this.hasInputTarget ? this.inputTarget.value : '');
        const chars = text.length;
        const words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
        const pages = Math.max(1, Math.ceil(words / 250));

        if (this.hasWordCountTarget) this.wordCountTarget.textContent = words;
        if (this.hasCharCountTarget) this.charCountTarget.textContent = chars;
        if (this.hasPageCountTarget) this.pageCountTarget.textContent = pages;
    }

    #triggerAutosave() {
        if (this.autosaveTimeout) {
            clearTimeout(this.autosaveTimeout);
        }
        this.autosaveTimeout = setTimeout(() => this.autosave(), 2000);
    }

    async autosave() {
        const wysiwygMarkdown = this.editor ? this.turndownService.turndown(this.editor.getHTML()) : '';
        const latexContent = this.codeMirror ? this.codeMirror.getValue() : (this.hasInputTarget ? this.inputTarget.value : '');

        if (!wysiwygMarkdown.trim() && !latexContent.trim()) return;

        // 1. Sauvegarde locale (LocalStorage) - Robuste, immédiat
        const storageKey = this.hasStorageKeyValue ? this.storageKeyValue : `djoliba_draft_${this.projectIdValue || 0}`;
        localStorage.setItem(`${storageKey}_wysiwyg`, wysiwygMarkdown);
        localStorage.setItem(`${storageKey}_latex`, latexContent);

        // 2. Sauvegarde vers le backend (si l'URL est fournie)
        const saveUrl = this.hasSaveUrlValue ? this.saveUrlValue : null;
        if (saveUrl) {
            this.#setStatus("Enregistrement automatique...");
            try {
                const response = await fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        content_wysiwyg: wysiwygMarkdown,
                        content_latex: latexContent,
                        mode: this.currentMode,
                        project_id: this.projectIdValue
                    })
                });

                if (!response.ok) throw new Error("Erreur de réponse");

                this.#setStatus("Manuscrit sauvegardé");
                setTimeout(() => {
                    if (this.statusTarget.textContent === "Manuscrit sauvegardé") {
                        this.#setStatus("");
                    }
                }, 2000);
            } catch (err) {
                console.warn("Échec d'autosave serveur :", err);
                this.#setStatus("Brouillon enregistré en local uniquement", true);
            }
        } else {
            this.#setStatus("Brouillon enregistré en local");
            setTimeout(() => {
                if (this.statusTarget.textContent === "Brouillon enregistré en local") {
                    this.#setStatus("");
                }
            }, 2000);
        }
    }

    // ─────────────────────────────────────────────
    // Bascule de Mode (WYSIWYG ⇄ LaTeX brut)
    // ─────────────────────────────────────────────

    setWysiwygMode() {
        this.setMode('wysiwyg');
    }

    setLatexMode() {
        this.setMode('latex');
    }

    setMode(mode) {
        if (!this.tipTapLoaded || mode === this.currentMode) return;

        if (mode === 'wysiwyg') {
            // LaTeX/Markdown brut ➔ WYSIWYG (pas de transfert automatique de texte)
            if (this.hasEditorContainerTarget) {
                this.editorContainerTarget.classList.remove('hidden');
            }

            if (this.codeMirror) {
                this.codeMirror.getWrapperElement().classList.add('hidden');
            } else if (this.hasInputTarget) {
                this.inputTarget.classList.add('hidden');
            }
        } else {
            // WYSIWYG ➔ LaTeX/Markdown brut (pas de transfert automatique de texte)
            if (this.hasEditorContainerTarget) {
                this.editorContainerTarget.classList.add('hidden');
            }

            if (this.codeMirror) {
                this.codeMirror.getWrapperElement().classList.remove('hidden');
                this.codeMirror.refresh();
            } else if (this.hasInputTarget) {
                this.inputTarget.classList.remove('hidden');
            }
        }

        this.currentMode = mode;
        this.#updateToggleButtons();
        this.#updateActiveNodes();
        this.#handleContentChange();
    }

    // ─────────────────────────────────────────────
    // Actions WYSIWYG de Formater (TipTap Toolbar)
    // ─────────────────────────────────────────────

    toggleBold() {
        if (this.editor) {
            this.editor.chain().focus().toggleBold().run();
        }
    }

    toggleItalic() {
        if (this.editor) {
            this.editor.chain().focus().toggleItalic().run();
        }
    }

    toggleHeading(event) {
        const level = parseInt(event.currentTarget.dataset.level || 1);
        if (this.editor) {
            this.editor.chain().focus().toggleHeading({ level }).run();
        }
    }

    toggleBulletList() {
        if (this.editor) {
            this.editor.chain().focus().toggleBulletList().run();
        }
    }

    toggleOrderedList() {
        if (this.editor) {
            this.editor.chain().focus().toggleOrderedList().run();
        }
    }

    toggleCodeBlock() {
        if (this.editor) {
            this.editor.chain().focus().toggleCodeBlock().run();
        }
    }

    togglePreview() {
        if (!this.hasPreviewContainerTarget) return;
        
        const isHidden = this.previewContainerTarget.classList.contains('hidden');
        if (isHidden) {
            this.previewContainerTarget.classList.remove('hidden');
            this.#updatePreview();
        } else {
            this.previewContainerTarget.classList.add('hidden');
        }
    }

    #updateToggleButtons() {
        if (this.hasModeWysiwygBtnTarget && this.hasModeLatexBtnTarget) {
            if (this.currentMode === 'wysiwyg') {
                this.modeWysiwygBtnTarget.classList.add('bg-slate-200', 'text-djoliba');
                this.modeWysiwygBtnTarget.classList.remove('text-slate-500');
                this.modeLatexBtnTarget.classList.remove('bg-slate-200', 'text-djoliba');
                this.modeLatexBtnTarget.classList.add('text-slate-500');

                // En mode visuel (WYSIWYG) : afficher "Prévisualiser", masquer "Aperçu Rendu"
                if (this.hasPreviewBtnTarget) this.previewBtnTarget.classList.remove('hidden');
                if (this.hasRenderBtnTarget) this.renderBtnTarget.classList.add('hidden');
            } else {
                this.modeLatexBtnTarget.classList.add('bg-slate-200', 'text-djoliba');
                this.modeLatexBtnTarget.classList.remove('text-slate-500');
                this.modeWysiwygBtnTarget.classList.remove('bg-slate-200', 'text-djoliba');
                this.modeWysiwygBtnTarget.classList.add('text-slate-500');

                // En mode LaTeX brut : masquer "Prévisualiser", afficher "Aperçu Rendu"
                if (this.hasPreviewBtnTarget) this.previewBtnTarget.classList.add('hidden');
                if (this.hasRenderBtnTarget) this.renderBtnTarget.classList.remove('hidden');

                // Masquer la prévisualisation split-screen si elle était ouverte
                if (this.hasPreviewContainerTarget && !this.previewContainerTarget.classList.contains('hidden')) {
                    this.previewContainerTarget.classList.add('hidden');
                }
            }
        }
    }

    #updateActiveNodes() {
        if (!this.editor) return;

        const hasTable = this.editor.isActive('table');
        const hasFigure = this.editor.isActive('figure');

        if (this.hasToolbarDeleteTableBtnTarget) {
            if (hasTable && this.currentMode === 'wysiwyg') {
                this.toolbarDeleteTableBtnTarget.classList.remove('hidden');
            } else {
                this.toolbarDeleteTableBtnTarget.classList.add('hidden');
            }
        }

        if (this.hasToolbarDeleteFigureBtnTarget) {
            if (hasFigure && this.currentMode === 'wysiwyg') {
                this.toolbarDeleteFigureBtnTarget.classList.remove('hidden');
            } else {
                this.toolbarDeleteFigureBtnTarget.classList.add('hidden');
            }
        }
    }

    // ─────────────────────────────────────────────
    // Éditeur : Taille de Police
    // ─────────────────────────────────────────────

    increaseFontSize() {
        if (!this.hasInputTarget) return;
        if (this.fontSize < 32) {
            this.fontSize += 1;
            this.#applyFontSize();
        }
    }

    decreaseFontSize() {
        if (!this.hasInputTarget) return;
        if (this.fontSize > 9) {
            this.fontSize -= 1;
            this.#applyFontSize();
        }
    }

    #applyFontSize() {
        if (this.hasInputTarget) {
            this.inputTarget.style.fontSize = `${this.fontSize}px`;
        }
        if (this.codeMirror) {
            const wrapper = this.codeMirror.getWrapperElement();
            if (wrapper) {
                wrapper.style.fontSize = `${this.fontSize}px`;
                this.codeMirror.refresh();
            }
        }
        if (this.editor && this.hasEditorContainerTarget) {
            const editorEl = this.editorContainerTarget.querySelector('.tiptap');
            if (editorEl) {
                editorEl.style.fontSize = `${this.fontSize}px`;
            }
        }
        this.#setStatus(`Taille : ${this.fontSize}px`);
        setTimeout(() => {
            if (this.statusTarget.textContent.startsWith("Taille :")) {
                this.#setStatus('');
            }
        }, 1200);
    }

    // ─────────────────────────────────────────────
    // Actions IA Métier (Originalité & Revues)
    // ─────────────────────────────────────────────

    async checkOriginality() {
        const text = this.#getMarkdown();
        if (text.length < 100) {
            this.#setStatus('Le texte doit contenir au moins 100 caractères.', true);
            return;
        }

        this.#setLoading(true, 'Vérification en cours...');

        try {
            const response = await fetch('/api/writing/check', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    text: text,
                    project_id: this.projectIdValue
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data?.error?.message || 'Erreur lors de la vérification');
            }

            this.#displayOriginalityResults(data.data);
            this.#setStatus('');
        } catch (error) {
            this.#setStatus(error.message, true);
        } finally {
            this.#setLoading(false);
        }
    }

    async suggestJournal() {
        const text = this.#getMarkdown();
        if (text.length < 100) {
            this.#setStatus('Le texte doit contenir au moins 100 caractères.', true);
            return;
        }

        this.#setLoading(true, 'Recherche de revues en cours...');

        try {
            const response = await fetch('/api/writing/suggest-journal', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    text: text,
                    project_id: this.projectIdValue
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data?.error?.message || 'Erreur lors de la suggestion');
            }

            this.#displayJournalResults(data.data);
            this.#setStatus('');
        } catch (error) {
            this.#setStatus(error.message, true);
        } finally {
            this.#setLoading(false);
        }
    }

    // ─────────────────────────────────────────────
    // Exportation LaTeX (.tex)
    // ─────────────────────────────────────────────

    async exportLatex() {
        let exportUrl = '';
        let payload = {};
        const filename = `djoliba_export_${this.projectIdValue || 'document'}.tex`;

        if (this.currentMode === 'wysiwyg') {
            if (!this.editor) {
                this.#setStatus("Éditeur non initialisé.", true);
                return;
            }
            const html = this.editor.getHTML();
            if (!html || this.editor.isEmpty) {
                this.#setStatus("L'éditeur est vide. Rien à exporter.", true);
                return;
            }
            this.#setStatus("Préparation du téléchargement LaTeX...");
            exportUrl = `/api/projects/${this.projectIdValue}/export/latex`;
            payload = {
                html: html,
                filename: filename
            };
        } else {
            const rawLatex = this.codeMirror ? this.codeMirror.getValue() : (this.hasInputTarget ? this.inputTarget.value : '');
            if (!rawLatex.trim()) {
                this.#setStatus("L'éditeur est vide. Rien à exporter.", true);
                return;
            }
            this.#setStatus("Préparation du téléchargement LaTeX...");
            exportUrl = this.hasExportUrlValue ? this.exportUrlValue : '/api/writing/export-latex';
            payload = {
                content: rawLatex,
                filename: filename
            };
        }

        try {
            const response = await fetch(exportUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) throw new Error("Échec d'exportation serveur.");

            const blob = await response.blob();
            const blobUrl = window.URL.createObjectURL(blob);
            
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            window.URL.revokeObjectURL(blobUrl);
            this.#setStatus("Exportation réussie");
            setTimeout(() => this.#setStatus(''), 2000);
        } catch (err) {
            console.error("Export error:", err);
            this.#setStatus("Erreur lors du téléchargement du fichier LaTeX", true);
        }
    }

    // ─────────────────────────────────────────────
    // Importation de Fichiers (DOCX & LaTeX)
    // ─────────────────────────────────────────────

    triggerFileInput() {
        if (this.hasFileInputTarget) {
            this.fileInputTarget.click();
        }
    }

    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;
        this.importFile(file);
        event.target.value = ''; // Réinitialisation pour sélection future
    }

    async importFile(file) {
        const extension = file.name.split('.').pop().toLowerCase();
        if (extension !== 'docx' && extension !== 'tex') {
            this.#setStatus("Format non supporté. Choisissez un fichier .docx ou .tex.", true);
            return;
        }

        this.#setLoading(true, "Lecture du fichier...");

        try {
            if (extension === 'docx') {
                await this.#importDocx(file);
            } else if (extension === 'tex') {
                await this.#importTex(file);
            }
        } catch (error) {
            this.#setStatus(error.message, true);
            this.#setLoading(false);
        }
    }

    #importTex(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const text = e.target.result;
                if (!text || text.trim().length === 0) {
                    reject(new Error("Le fichier LaTeX est vide ou illisible."));
                    return;
                }
                this.#showPreview(text);
                resolve();
            };
            reader.onerror = () => reject(new Error("Erreur de lecture du fichier LaTeX."));
            reader.readAsText(file);
        });
    }

    #importDocx(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = async (e) => {
                try {
                    const arrayBuffer = e.target.result;
                    const mammothInstance = await this.#loadMammoth();
                    
                    const result = await mammothInstance.extractRawText({ arrayBuffer: arrayBuffer });
                    const text = result.value;
                    
                    if (!text || text.trim().length === 0) {
                        throw new Error("Aucun texte lisible n'a pu être extrait du fichier Word.");
                    }
                    
                    this.#showPreview(text);
                    resolve();
                } catch (err) {
                    reject(new Error("Erreur d'extraction : " + err.message));
                }
            };
            reader.onerror = () => reject(new Error("Erreur de lecture du fichier Word."));
            reader.readAsArrayBuffer(file);
        });
    }

    async #loadMammoth() {
        if (window.mammoth) return window.mammoth;
        
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js';
            script.async = true;
            script.onload = () => {
                if (window.mammoth) {
                    resolve(window.mammoth);
                } else {
                    reject(new Error("Erreur d'initialisation de Mammoth.js"));
                }
            };
            script.onerror = () => reject(new Error("Impossible de télécharger le parseur Word."));
            document.head.appendChild(script);
        });
    }

    #showPreview(text) {
        this.pendingImportText = text;
        
        if (this.hasPreviewContentTarget) {
            this.previewContentTarget.textContent = text;
        }
        
        this.#setLoading(false);
        this.openPreviewModal();
    }

    // ─────────────────────────────────────────────
    // Modals & UI Locks
    // ─────────────────────────────────────────────

    openOriginalityModal() {
        if (!this.hasOriginalityModalTarget) return;
        const modal = this.originalityModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeOriginalityModal() {
        if (!this.hasOriginalityModalTarget) return;
        const modal = this.originalityModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    openJournalModal() {
        if (!this.hasJournalModalTarget) return;
        const modal = this.journalModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeJournalModal() {
        if (!this.hasJournalModalTarget) return;
        const modal = this.journalModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    openPreviewModal() {
        if (!this.hasPreviewModalTarget) return;
        const modal = this.previewModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closePreviewModal() {
        if (!this.hasPreviewModalTarget) return;
        const modal = this.previewModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    openHelpModal() {
        if (!this.hasHelpModalTarget) return;
        const modal = this.helpModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeHelpModal() {
        if (!this.hasHelpModalTarget) return;
        const modal = this.helpModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    openLatexPreviewModal() {
        if (!this.hasLatexPreviewModalTarget) return;
        const modal = this.latexPreviewModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeLatexPreviewModal() {
        if (!this.hasLatexPreviewModalTarget) return;
        const modal = this.latexPreviewModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    showLatexPreview() {
        const rawContent = this.#getMarkdown();
        
        // Si vide, indiquer un placeholder
        const textToTranscribe = rawContent.trim() ? rawContent : "*[Le manuscrit est actuellement vide. Saisissez du texte ou du code LaTeX pour l'apercevoir.]*";
        
        // Transcrire le LaTeX brut vers un Markdown équivalent
        const transcribedMarkdown = this.#transcribeLatex(textToTranscribe);
        
        // Convertir le Markdown transcrit en HTML via Marked
        const htmlContent = this.marked.parse(transcribedMarkdown);
        
        if (this.hasLatexPreviewContentTarget) {
            this.latexPreviewContentTarget.innerHTML = htmlContent;
            
            // Compiler les équations mathématiques à l'intérieur du modal avec KaTeX
            if (window.renderMathInElement) {
                window.renderMathInElement(this.latexPreviewContentTarget, {
                    delimiters: [
                        { left: '$$', right: '$$', display: true },
                        { left: '$', right: '$', display: false },
                        { left: '\\(', right: '\\)', display: false },
                        { left: '\\[', right: '\\]', display: true }
                    ],
                    throwOnError: false
                });
            }
        }
        
        this.openLatexPreviewModal();
    }

    #transcribeLatex(latex) {
        if (!latex) return '';

        let text = latex;

        // 1. Supprimer le préambule LaTeX classique
        text = text.replace(/\\documentclass\{[\s\S]*?\\begin\{document\}/gi, '');
        text = text.replace(/\\end\{document\}/gi, '');
        text = text.replace(/\\usepackage\{[\s\S]*?\}/gi, '');
        text = text.replace(/\\title\{([\s\S]*?)\}/gi, '# $1\n');
        text = text.replace(/\\author\{([\s\S]*?)\}/gi, '**Auteur :** $1\n');
        text = text.replace(/\\date\{([\s\S]*?)\}/gi, '*Date :* $1\n');
        text = text.replace(/\\maketitle/gi, '');

        // 2. Isoler les expressions mathématiques ($ et $$) pour éviter qu'elles ne soient cassées par les regex
        const mathBlocks = [];
        text = text.replace(/\$\$([\s\S]*?)\$\$/g, (match, math) => {
            const placeholder = `__MATHBLOCK_DISP_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, math, display: true });
            return placeholder;
        });
        text = text.replace(/\$([\s\S]*?)\$/g, (match, math) => {
            const placeholder = `__MATHBLOCK_INLINE_${mathBlocks.length}__`;
            mathBlocks.push({ placeholder, math, display: false });
            return placeholder;
        });

        // 3. Commandes de structuration de document
        text = text.replace(/\\section\*?\{([\s\S]*?)\}/gi, '\n# $1\n');
        text = text.replace(/\\subsection\*?\{([\s\S]*?)\}/gi, '\n## $1\n');
        text = text.replace(/\\subsubsection\*?\{([\s\S]*?)\}/gi, '\n### $1\n');
        text = text.replace(/\\paragraph\*?\{([\s\S]*?)\}/gi, '\n#### $1\n');

        // Style inline
        text = text.replace(/\\textbf\{([\s\S]*?)\}/gi, '**$1**');
        text = text.replace(/\\textit\{([\s\S]*?)\}/gi, '*$1*');
        text = text.replace(/\\texttt\{([\s\S]*?)\}/gi, '`$1`');
        text = text.replace(/\\underline\{([\s\S]*?)\}/gi, '<u>$1</u>');

        // Hyperliens
        text = text.replace(/\\href\{([\s\S]*?)\}\{([\s\S]*?)\}/gi, '[$2]($1)');
        text = text.replace(/\\url\{([\s\S]*?)\}/gi, '[$1]($1)');

        // 4. Listes d'éléments
        text = text.replace(/\\begin\{itemize\}/gi, '\n');
        text = text.replace(/\\end\{itemize\}/gi, '\n');
        text = text.replace(/\\begin\{enumerate\}/gi, '\n');
        text = text.replace(/\\end\{enumerate\}/gi, '\n');
        
        // Remplacer \item en conservant le contenu jusqu'au prochain item ou fin
        text = text.replace(/\\item\s+([\s\S]*?)(?=\\item|\\end|\n\n)/gi, '- $1\n');

        // 5. Code verbatim
        text = text.replace(/\\begin\{verbatim\}([\s\S]*?)\\end\{verbatim\}/gi, '\n```\n$1\n```\n');
        text = text.replace(/\\begin\{lstlisting\}([\s\S]*?)\\end\{lstlisting\}/gi, '\n```\n$1\n```\n');

        // 6. Citations & Références
        text = text.replace(/\\cite\{([\s\S]*?)\}/gi, '<sup>[$1]</sup>');
        text = text.replace(/\\ref\{([\s\S]*?)\}/gi, '`$1`');

        // 7. Retours à la ligne
        text = text.replace(/\\\\/g, '\n');
        text = text.replace(/\\newline/g, '\n');
        text = text.replace(/\\newpage/g, '\n---\n');

        // 8. Réinsérer les blocs mathématiques isolés intacts
        mathBlocks.forEach(item => {
            const mathDelimiter = item.display ? `$$\n${item.math}\n$$` : `$${item.math}$`;
            text = text.replace(item.placeholder, mathDelimiter);
        });

        // 9. Purger les macros orphelines non reconnues pour ne pas polluer l'affichage
        text = text.replace(/\\[a-zA-Z]+\*?(?:\{.*?\})?/g, '');

        return text;
    }

    confirmImport() {
        if (this.pendingImportText) {
            const markdown = this.pendingImportText;

            if (this.currentMode === 'wysiwyg') {
                const html = this.marked.parse(markdown);
                if (this.editor) {
                    this.editor.commands.setContent(html);
                }
            } else {
                if (this.codeMirror) {
                    this.codeMirror.setValue(markdown);
                } else if (this.hasInputTarget) {
                    this.inputTarget.value = markdown;
                }
            }

            this.#handleContentChange();
            this.#setStatus("Document importé avec succès.");
            setTimeout(() => this.#setStatus(''), 2000);
        }
        this.closePreviewModal();
        this.pendingImportText = null;
    }

    // ─────────────────────────────────────────────
    // Utilitaires Privés
    // ─────────────────────────────────────────────

    #displayOriginalityResults(data) {
        if (!this.hasOriginalityContentTarget) return;

        let html = `
            <div class="space-y-6">
                <div class="flex items-center justify-between bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div>
                        <div class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-1">Score d'Originalité</div>
                        <div class="text-4xl font-display font-black ${this.#getScoreColor(data.originality_score)}">
                            ${data.originality_score}%
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-1">Niveau de Risque</div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold ${this.#getLevelBadgeClass(data.level)}">
                            <span class="w-1.5 h-1.5 rounded-full ${this.#getLevelDotClass(data.level)}"></span>
                            ${data.level}
                        </span>
                    </div>
                </div>
        `;

        if (data.similar_passages && data.similar_passages.length > 0) {
            html += `
                <div>
                    <h4 class="font-bold text-djoliba text-sm mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        Passages similaires & Suggestions
                    </h4>
                    <div class="space-y-3">
            `;
            data.similar_passages.forEach(p => {
                html += `
                        <div class="bg-amber-50/50 p-4 rounded-2xl border border-amber-100 text-xs">
                            <div class="text-slate-600 italic font-medium mb-2">"${p.passage}"</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-amber-100/50">
                                <div>
                                    <span class="font-bold text-[10px] text-red-500 uppercase tracking-wider block mb-0.5">Risque</span>
                                    <span class="text-slate-700">${p.risk}</span>
                                </div>
                                <div>
                                    <span class="font-bold text-[10px] text-emerald-600 uppercase tracking-wider block mb-0.5">Suggestion</span>
                                    <span class="text-slate-800">${p.suggestion}</span>
                                </div>
                            </div>
                        </div>
                `;
            });
            html += `</div></div>`;
        } else {
            html += `
                <div class="bg-emerald-50/30 border border-emerald-100/50 p-6 rounded-2xl text-center space-y-2">
                    <div class="w-10 h-10 bg-emerald-100/50 rounded-full flex items-center justify-center text-emerald-600 mx-auto">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <p class="text-xs font-bold text-emerald-700">Manuscrit hautement original</p>
                    <p class="text-[10px] text-slate-500">Aucun passage similaire ou problématique n'a été détecté.</p>
                </div>
            `;
        }

        if (data.recommendations && data.recommendations.length > 0) {
            html += `
                <div class="border-t border-slate-100 pt-4">
                    <h4 class="font-bold text-djoliba text-sm mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-djoliba" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                        Recommandations d'Amélioration
                    </h4>
                    <ul class="space-y-1.5 pl-1">
            `;
            data.recommendations.forEach(r => {
                html += `
                        <li class="text-xs text-slate-600 flex items-start gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-djoliba mt-1.5 flex-shrink-0"></span>
                            ${r}
                        </li>
                `;
            });
            html += `</ul></div>`;
        }

        html += `</div>`;
        this.originalityContentTarget.innerHTML = html;
        this.openOriginalityModal();
    }

    #displayJournalResults(data) {
        if (!this.hasJournalContentTarget) return;

        let html = `<div class="space-y-4">`;

        if (data.journals && data.journals.length > 0) {
            data.journals.forEach(j => {
                html += `
                    <div class="border border-slate-100 p-5 rounded-2xl hover:bg-slate-50/50 hover:border-djoliba/10 transition-all space-y-3 relative group">
                        <div class="flex justify-between items-start gap-4">
                            <div>
                                <h4 class="font-bold text-djoliba text-sm group-hover:text-gold_prestige transition-colors">${j.name}</h4>
                                <div class="text-[10px] text-slate-400 font-medium mt-1">Éditeur : <span class="text-slate-600">${j.publisher}</span></div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-gold_prestige/10 text-gold_prestige border border-gold_prestige/20">
                                    IF: ${j.impact_factor}
                                </span>
                            </div>
                        </div>
                        
                        <div class="text-xs text-slate-500 leading-relaxed">
                            <span class="font-bold text-[9px] text-slate-400 uppercase tracking-wider block mb-0.5">Thématiques & Portée</span>
                            ${j.scope}
                        </div>
                        
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-xs italic text-slate-600 leading-relaxed">
                            <span class="font-bold text-[9px] text-slate-400 uppercase tracking-wider block not-italic mb-1">Justification IA</span>
                            "${j.match_reason}"
                        </div>
                `;

                if (j.url && j.url !== 'N/A' && j.url !== '#') {
                    html += `
                        <div class="pt-1 flex justify-end">
                            <a href="${j.url}" target="_blank" class="inline-flex items-center gap-1 text-[10px] font-bold text-blue-500 hover:text-blue-600 transition-colors">
                                Visiter le site officiel
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                            </a>
                        </div>
                    `;
                }
                html += `</div>`;
            });
        } else {
            html += `
                <div class="bg-slate-50 border border-slate-100 p-8 rounded-2xl text-center space-y-2">
                    <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-400 mx-auto">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <p class="text-xs font-bold text-slate-600">Aucune revue trouvée</p>
                    <p class="text-[10px] text-slate-400">Le modèle n'a pas pu formuler de recommandations de revues cibles adaptées à ce contenu.</p>
                </div>
            `;
        }

        html += `</div>`;
        this.journalContentTarget.innerHTML = html;
        this.openJournalModal();
    }

    #getScoreColor(score) {
        if (score >= 80) return 'text-emerald-500';
        if (score >= 50) return 'text-amber-500';
        return 'text-red-500';
    }

    #getLevelBadgeClass(level) {
        const lower = level.toLowerCase();
        if (lower === 'faible' || lower === 'low') return 'bg-emerald-50 text-emerald-600 border border-emerald-100';
        if (lower === 'moyen' || lower === 'medium') return 'bg-amber-50 text-amber-600 border border-amber-100';
        return 'bg-red-50 text-red-600 border border-red-100';
    }

    #getLevelDotClass(level) {
        const lower = level.toLowerCase();
        if (lower === 'faible' || lower === 'low') return 'bg-emerald-500';
        if (lower === 'moyen' || lower === 'medium') return 'bg-amber-500';
        return 'bg-red-500';
    }

    #setLoading(isLoading, message = '') {
        if (this.hasCheckBtnTarget) this.checkBtnTarget.disabled = isLoading;
        if (this.hasSuggestBtnTarget) this.suggestBtnTarget.disabled = isLoading;
        if (this.hasInputTarget) this.inputTarget.disabled = isLoading;
        if (this.hasImportBtnTarget) this.importBtnTarget.disabled = isLoading;
        
        if (isLoading) {
            this.#setStatus(message);
        } else {
            this.#setStatus('');
        }
    }

    #setStatus(text, isError = false) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = text;
        this.statusTarget.className = isError ? 'text-xs text-red-500 mt-2 font-medium' : 'text-xs text-slate-400 mt-2 italic';
    }

    // ─────────────────────────────────────────────
    // Méthodes Publiques API (Intégration Externe)
    // ─────────────────────────────────────────────

    /**
     * Définit dynamiquement le contenu de l'éditeur dans les deux modes
     * @param {string} wysiwygContent Le contenu Markdown à charger en WYSIWYG
     * @param {string} latexContent Le contenu LaTeX à charger en LaTeX brut
     * @param {string} mode Le mode d'édition à activer ('wysiwyg' ou 'latex')
     */
    setEditorContent(wysiwygContent, latexContent, mode = 'wysiwyg') {
        if (this.editor) {
            const initialHtml = wysiwygContent ? this.marked.parse(wysiwygContent) : '';
            this.editor.commands.setContent(initialHtml);
        }

        const rawLatex = latexContent || '';
        if (this.codeMirror) {
            this.codeMirror.setValue(rawLatex);
            this.codeMirror.refresh();
        } else if (this.hasInputTarget) {
            this.inputTarget.value = rawLatex;
        }

        // Forcer le mode pour mettre à jour l'affichage
        this.currentMode = (mode === 'wysiwyg') ? 'latex' : 'wysiwyg';
        this.setMode(mode);

        this.#updateCounters();
        this.#updatePreview();
    }

    /**
     * Récupère le contenu Markdown actuel selon le mode d'éditeur actif
     * @returns {string} Le texte Markdown ou LaTeX brut
     */
    getMarkdownContent() {
        return this.#getMarkdown();
    }

    // =============================================
    // TABLEAUX (TIPTAP TABLE EXTENSION)
    // =============================================

    insertTableModal() {
        if (!this.editor) return;
        this.editingTableEl = null;
        if (this.hasTableDeleteBtnTarget) this.tableDeleteBtnTarget.classList.add('hidden');
        if (this.hasTableCaptionInputTarget) this.tableCaptionInputTarget.value = '';
        if (this.hasTableLabelInputTarget) this.tableLabelInputTarget.value = '';
        this.openTableModal();
        this.renderGridEditor(3, 3);
    }

    openTableModal() {
        if (!this.hasTableModalTarget) return;
        const modal = this.tableModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeTableModal() {
        if (!this.hasTableModalTarget) return;
        const modal = this.tableModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    openEditTableModal(tableEl) {
        this.editingTableEl = tableEl;
        if (this.hasTableDeleteBtnTarget) this.tableDeleteBtnTarget.classList.remove('hidden');
        
        const caption = tableEl.getAttribute('data-caption') || '';
        const label = tableEl.getAttribute('data-label') || '';
        
        if (this.hasTableCaptionInputTarget) this.tableCaptionInputTarget.value = caption;
        if (this.hasTableLabelInputTarget) this.tableLabelInputTarget.value = label;
        
        const trs = tableEl.querySelectorAll('tr');
        const rows = trs.length;
        if (rows === 0) return;
        
        let cols = 0;
        const cellData = [];
        
        trs.forEach((tr) => {
            const cells = tr.querySelectorAll('th, td');
            cols = Math.max(cols, cells.length);
            const rowData = [];
            cells.forEach(cell => {
                rowData.push(cell.textContent.trim());
            });
            cellData.push(rowData);
        });
        
        this.openTableModal();
        this.renderGridEditor(rows, cols, cellData);
    }

    renderGridEditor(rows, cols, cellData = null) {
        if (!this.hasTableGridContainerTarget) return;
        const container = this.tableGridContainerTarget;
        container.innerHTML = '';
        
        const table = document.createElement('table');
        table.className = 'w-full border-collapse border border-slate-200 text-xs my-2';
        
        for (let r = 0; r < rows; r++) {
            const tr = document.createElement('tr');
            for (let c = 0; c < cols; c++) {
                const cellType = (r === 0) ? 'th' : 'td';
                const cell = document.createElement(cellType);
                cell.className = 'border border-slate-200 p-1 bg-slate-50';
                
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'w-full px-2 py-1 text-xs border border-transparent focus:border-djoliba focus:bg-white bg-slate-50 rounded transition-all focus:outline-none text-slate-800';
                input.placeholder = (r === 0) ? `Entête ${c+1}` : `Valeur`;
                
                if (cellData && cellData[r] && cellData[r][c] !== undefined) {
                    input.value = cellData[r][c];
                } else {
                    input.value = '';
                }
                
                input.dataset.row = r;
                input.dataset.col = c;
                
                cell.appendChild(input);
                tr.appendChild(cell);
            }
            table.appendChild(tr);
        }
        
        container.appendChild(table);
        this.activeTableRows = rows;
        this.activeTableCols = cols;
    }

    collectGridData() {
        const rows = this.activeTableRows || 3;
        const cols = this.activeTableCols || 3;
        const container = this.tableGridContainerTarget;
        const data = [];
        
        for (let r = 0; r < rows; r++) {
            const rowData = [];
            for (let c = 0; c < cols; c++) {
                const input = container.querySelector(`input[data-row="${r}"][data-col="${c}"]`);
                rowData.push(input ? input.value : '');
            }
            data.push(rowData);
        }
        return data;
    }

    addTableRow() {
        const rows = this.activeTableRows || 3;
        const cols = this.activeTableCols || 3;
        const currentData = this.collectGridData();
        this.renderGridEditor(rows + 1, cols, currentData);
    }
    
    addTableCol() {
        const rows = this.activeTableRows || 3;
        const cols = this.activeTableCols || 3;
        const currentData = this.collectGridData();
        this.renderGridEditor(rows, cols + 1, currentData);
    }
    
    removeTableRow() {
        const rows = this.activeTableRows || 3;
        const cols = this.activeTableCols || 3;
        if (rows <= 2) return;
        const currentData = this.collectGridData();
        this.renderGridEditor(rows - 1, cols, currentData);
    }
    
    removeTableCol() {
        const rows = this.activeTableRows || 3;
        const cols = this.activeTableCols || 3;
        if (cols <= 1) return;
        const currentData = this.collectGridData();
        this.renderGridEditor(rows, cols - 1, currentData);
    }

    confirmInsertTable() {
        const caption = this.hasTableCaptionInputTarget ? this.tableCaptionInputTarget.value.trim() : '';
        const label = this.hasTableLabelInputTarget ? this.tableLabelInputTarget.value.trim() : '';
        
        const rows = this.activeTableRows || 3;
        const cols = this.activeTableCols || 3;
        
        let tableHtml = `<table data-caption="${this.#escapeHtml(caption)}" data-label="${this.#escapeHtml(label)}" class="w-full border-collapse border border-slate-200 my-4 text-xs">`;
        
        const container = this.tableGridContainerTarget;
        
        tableHtml += '<thead><tr>';
        for (let c = 0; c < cols; c++) {
            const input = container.querySelector(`input[data-row="0"][data-col="${c}"]`);
            const val = input ? input.value.trim() : '';
            tableHtml += `<th class="border border-slate-200 p-2 bg-slate-50 text-djoliba font-bold">${this.#escapeHtml(val)}</th>`;
        }
        tableHtml += '</tr></thead><tbody>';
        
        for (let r = 1; r < rows; r++) {
            tableHtml += '<tr>';
            for (let c = 0; c < cols; c++) {
                const input = container.querySelector(`input[data-row="${r}"][data-col="${c}"]`);
                const val = input ? input.value.trim() : '';
                tableHtml += `<td class="border border-slate-200 p-2 text-slate-700">${this.#escapeHtml(val)}</td>`;
            }
            tableHtml += '</tr>';
        }
        tableHtml += '</tbody></table>';
        
        if (this.editingTableEl) {
            // Remplacer le tableau existant sélectionné
            this.editor.chain().focus().insertContent(tableHtml).run();
        } else {
            // Insérer le nouveau tableau et forcer un retour à la ligne juste après
            this.editor.chain()
                .focus()
                .insertContent(tableHtml)
                .insertContent('<p></p>')
                .run();
        }
        
        this.closeTableModal();
        this.#handleContentChange();
        this.#updateActiveNodes();
    }

    deleteTable() {
        let deleted = false;
        
        // 1. Essayer de supprimer via l'élément en cours d'édition (DOM)
        if (this.editingTableEl) {
            try {
                const pos = this.editor.view.posAtDOM(this.editingTableEl);
                if (pos !== undefined && pos !== null) {
                    const $pos = this.editor.state.doc.resolve(pos);
                    const tableNode = $pos.nodeAfter;
                    if (tableNode && tableNode.type.name === 'table') {
                        const tr = this.editor.state.tr.delete(pos, pos + tableNode.nodeSize);
                        this.editor.view.dispatch(tr);
                        deleted = true;
                    }
                }
            } catch (e) {
                console.warn("Échec de suppression du tableau par DOM:", e);
            }
        }
        
        // 2. Si non supprimé, chercher sous le curseur
        if (!deleted && this.editor) {
            const { state } = this.editor;
            const { selection } = state;
            const $from = selection.$from;
            
            let tableDepth = -1;
            for (let i = $from.depth; i >= 0; i--) {
                if ($from.node(i).type.name === 'table') {
                    tableDepth = i;
                    break;
                }
            }
            
            if (tableDepth !== -1) {
                const tablePos = $from.before(tableDepth);
                const tableNode = $from.node(tableDepth);
                const tr = state.tr.delete(tablePos, tablePos + tableNode.nodeSize);
                this.editor.view.dispatch(tr);
                deleted = true;
            }
        }
        
        this.closeTableModal();
        this.#handleContentChange();
        this.#updateActiveNodes();
    }

    deleteTableUnderCursor() {
        this.editingTableEl = null;
        this.deleteTable();
    }

    #escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // =============================================
    // FIGURES AVEC LÉGENDE
    // =============================================

    insertFigureModal() {
        if (!this.editor) return;
        this.editingFigureEl = null;
        if (this.hasFigureDeleteBtnTarget) this.figureDeleteBtnTarget.classList.add('hidden');
        this.figureUrlInputTarget.value = '';
        this.figureCaptionInputTarget.value = '';
        this.figureLabelInputTarget.value = '';
        if (this.hasFigureUploadStatusTarget) {
            this.figureUploadStatusTarget.classList.add('hidden');
            this.figureUploadStatusTarget.textContent = '';
        }
        this.openFigureModal();
    }

    openFigureModal() {
        if (!this.hasFigureModalTarget) return;
        const modal = this.figureModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeFigureModal() {
        if (!this.hasFigureModalTarget) return;
        const modal = this.figureModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    openEditFigureModal(figureEl) {
        this.editingFigureEl = figureEl;
        if (this.hasFigureDeleteBtnTarget) this.figureDeleteBtnTarget.classList.remove('hidden');
        
        const img = figureEl.querySelector('img');
        const figcaption = figureEl.querySelector('figcaption');
        const label = figureEl.getAttribute('data-label') || '';
        
        this.figureUrlInputTarget.value = img ? img.getAttribute('src') : '';
        this.figureCaptionInputTarget.value = figcaption ? figcaption.textContent.trim() : '';
        this.figureLabelInputTarget.value = label;
        
        if (this.hasFigureUploadStatusTarget) {
            this.figureUploadStatusTarget.classList.add('hidden');
            this.figureUploadStatusTarget.textContent = '';
        }
        
        this.openFigureModal();
    }

    confirmInsertFigure() {
        const imageUrl = this.figureUrlInputTarget.value.trim() || 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?w=600&auto=format&fit=crop';
        const caption = this.figureCaptionInputTarget.value.trim() || 'Figure sans titre';
        const label = this.figureLabelInputTarget.value.trim();

        const figureContent = [
            {
                type: 'figure',
                attrs: { label: label || '' },
                content: [
                    { type: 'image', attrs: { src: imageUrl } },
                    { type: 'figcaption', content: [{ type: 'text', text: caption }] }
                ]
            }
        ];

        if (this.editingFigureEl) {
            // Remplacer la figure existante sélectionnée
            const pos = this.editor.view.posAtDOM(this.editingFigureEl);
            if (pos !== undefined) {
                const $pos = this.editor.state.doc.resolve(pos);
                const figNode = $pos.nodeAfter;
                if (figNode && figNode.type.name === 'figure') {
                    this.editor.chain()
                        .focus()
                        .deleteRange({ from: pos, to: pos + figNode.nodeSize })
                        .insertContentAt(pos, figureContent)
                        .run();
                }
            }
        } else {
            // Nouvelle figure avec saut de ligne
            this.editor.chain()
                .focus()
                .insertContent(figureContent)
                .insertContent('<p></p>')
                .run();
        }
        
        this.closeFigureModal();
        this.#handleContentChange();
        this.#updateActiveNodes();
    }

    deleteFigure() {
        let deleted = false;
        
        // 1. Essayer de supprimer via l'élément en cours d'édition (DOM)
        if (this.editingFigureEl) {
            try {
                const pos = this.editor.view.posAtDOM(this.editingFigureEl);
                if (pos !== undefined && pos !== null) {
                    const $pos = this.editor.state.doc.resolve(pos);
                    const figNode = $pos.nodeAfter;
                    if (figNode && figNode.type.name === 'figure') {
                        const tr = this.editor.state.tr.delete(pos, pos + figNode.nodeSize);
                        this.editor.view.dispatch(tr);
                        deleted = true;
                    }
                }
            } catch (e) {
                console.warn("Échec de suppression de la figure par DOM:", e);
            }
        }
        
        // 2. Si non supprimée, chercher sous le curseur
        if (!deleted && this.editor) {
            const { state } = this.editor;
            const { selection } = state;
            const $from = selection.$from;
            
            let figDepth = -1;
            for (let i = $from.depth; i >= 0; i--) {
                if ($from.node(i).type.name === 'figure') {
                    figDepth = i;
                    break;
                }
            }
            
            if (figDepth !== -1) {
                const figPos = $from.before(figDepth);
                const figNode = $from.node(figDepth);
                const tr = state.tr.delete(figPos, figPos + figNode.nodeSize);
                this.editor.view.dispatch(tr);
                deleted = true;
            }
        }
        
        this.closeFigureModal();
        this.#handleContentChange();
        this.#updateActiveNodes();
    }

    deleteFigureUnderCursor() {
        this.editingFigureEl = null;
        this.deleteFigure();
    }

    triggerFigureImageUpload() {
        if (this.hasFigureImageFileInputTarget) {
            this.figureImageFileInputTarget.click();
        }
    }

    async handleFigureImageUpload(event) {
        const fileInput = event.currentTarget;
        const file = fileInput.files[0];
        if (!file) return;

        // Valider le type localement
        if (!file.type.startsWith('image/')) {
            this.#setFigureUploadStatus("Erreur : Le fichier doit être une image", true);
            return;
        }

        // Valider la taille localement (5 Mo max)
        if (file.size > 5 * 1024 * 1024) {
            this.#setFigureUploadStatus("Erreur : L'image dépasse 5 Mo", true);
            return;
        }

        const formData = new FormData();
        formData.append('image', file);

        const projectId = this.projectIdValue;
        const uploadUrl = `/api/projects/${projectId}/upload-image`;

        this.#setFigureUploadStatus("Téléchargement de l'image...", false, true);

        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (response.ok && result.success) {
                if (this.hasFigureUrlInputTarget) {
                    this.figureUrlInputTarget.value = result.url;
                }
                this.#setFigureUploadStatus("Image importée avec succès !", false);
            } else {
                const errorMsg = result.error?.message || "Erreur de chargement";
                this.#setFigureUploadStatus(`Erreur : ${errorMsg}`, true);
            }
        } catch (err) {
            console.error("Erreur d'upload d'image :", err);
            this.#setFigureUploadStatus("Erreur : Impossible d'envoyer l'image", true);
        } finally {
            fileInput.value = '';
        }
    }

    #setFigureUploadStatus(message, isError = false, isLoading = false) {
        if (!this.hasFigureUploadStatusTarget) return;
        const statusEl = this.figureUploadStatusTarget;
        statusEl.classList.remove('hidden', 'text-slate-400', 'text-red-500', 'text-emerald-500', 'animate-pulse');
        statusEl.textContent = message;

        if (isError) {
            statusEl.classList.add('text-red-500');
        } else if (isLoading) {
            statusEl.classList.add('text-slate-400', 'animate-pulse');
        } else {
            statusEl.classList.add('text-emerald-500');
            setTimeout(() => {
                if (statusEl.textContent === message) {
                    statusEl.classList.add('hidden');
                }
            }, 3000);
        }
    }

    // =============================================
    // NOTES DE BAS DE PAGE
    // =============================================

    insertFootnoteModal() {
        if (!this.editor) return;
        this.footnoteTextInputTarget.value = '';
        this.openFootnoteModal();
    }

    openFootnoteModal() {
        if (!this.hasFootnoteModalTarget) return;
        const modal = this.footnoteModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeFootnoteModal() {
        if (!this.hasFootnoteModalTarget) return;
        const modal = this.footnoteModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    confirmInsertFootnote() {
        const noteText = this.footnoteTextInputTarget.value.trim();
        if (!noteText) return;

        const html = this.editor.getHTML();
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const noteId = tempDiv.querySelectorAll('sup[data-fn]').length + 1;

        this.editor.chain().focus().insertContent(`<sup data-fn="${noteId}"><a href="#fn${noteId}">[${noteId}]</a></sup>`).run();

        let footnotesContainer = tempDiv.querySelector('.footnotes');
        if (!footnotesContainer) {
            const newFootnotesHtml = `<div class="footnotes"><hr class="my-4"><p id="fn${noteId}" class="text-xs text-slate-500 font-serif my-1">[${noteId}] ${noteText}</p></div>`;
            this.editor.chain().focus().insertContentAt(this.editor.state.doc.content.size, newFootnotesHtml).run();
        } else {
            const newNoteParagraph = `<p id="fn${noteId}" class="text-xs text-slate-500 font-serif my-1">[${noteId}] ${noteText}</p>`;
            footnotesContainer.innerHTML += newNoteParagraph;
            this.editor.commands.setContent(tempDiv.innerHTML);
        }

        this.closeFootnoteModal();
        this.#handleContentChange();
    }

    // =============================================
    // RÉFÉRENCES CROISÉES
    // =============================================

    insertReferenceModal() {
        if (!this.editor) return;
        
        const select = this.referenceLabelSelectTarget;
        select.innerHTML = '<option value="">-- Aucun label trouvé --</option>';

        const labels = this.getAllLabels();
        if (labels.length > 0) {
            select.innerHTML = '';
            labels.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.label;
                opt.textContent = item.text;
                select.appendChild(opt);
            });
        }

        this.openReferenceModal();
    }

    openReferenceModal() {
        if (!this.hasReferenceModalTarget) return;
        const modal = this.referenceModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeReferenceModal() {
        if (!this.hasReferenceModalTarget) return;
        const modal = this.referenceModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    confirmInsertReference() {
        const label = this.referenceLabelSelectTarget.value;
        if (!label) return;

        this.editor.chain().focus().insertContent(`<a href="#${label}" class="reference text-djoliba font-bold hover:underline">${label}</a>`).run();
        
        this.closeReferenceModal();
        this.#handleContentChange();
    }

    getAllLabels() {
        if (!this.editor) return [];

        const html = this.editor.getHTML();
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const labels = [];

        tempDiv.querySelectorAll('figure[data-label]').forEach(fig => {
            const label = fig.getAttribute('data-label');
            if (label) {
                const caption = fig.querySelector('figcaption')?.textContent || 'Figure';
                labels.push({ type: 'figure', label, text: `Figure: ${caption} (${label})` });
            }
        });

        tempDiv.querySelectorAll('[data-label]').forEach(el => {
            const label = el.getAttribute('data-label');
            const tagName = el.tagName.toLowerCase();
            if (label && tagName !== 'figure' && !labels.some(item => item.label === label)) {
                labels.push({ type: tagName, label, text: `${tagName.toUpperCase()}: ${label}` });
            }
        });

        return labels;
    }

    // =============================================
    // ANNULER / RÉTABLIR (UNDO / REDO)
    // =============================================

    undo() {
        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.chain().focus().undo().run();
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            this.codeMirror.undo();
            this.codeMirror.focus();
        }
    }

    redo() {
        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.chain().focus().redo().run();
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            this.codeMirror.redo();
            this.codeMirror.focus();
        }
    }

    // =============================================
    // BOÎTE À OUTILS LATEX (∑)
    // =============================================

    insertMathModal() {
        if (!this.editor && this.currentMode === 'wysiwyg') return;
        this.mathFormulaInputTarget.value = '';
        this.openMathModal();
    }

    openMathModal() {
        if (!this.hasMathModalTarget) return;
        const modal = this.mathModalTarget;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    closeMathModal() {
        if (!this.hasMathModalTarget) return;
        const modal = this.mathModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    confirmInsertMath() {
        const formula = this.mathFormulaInputTarget.value.trim();
        if (!formula) return;

        const isBlock = this.mathDisplaySelectTarget.value === 'block';
        const mathString = isBlock ? `\n$$${formula}$$\n` : `$${formula}$`;

        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.chain().focus().insertContent(mathString).run();
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            const doc = this.codeMirror.getDoc();
            const cursor = doc.getCursor();
            doc.replaceRange(mathString, cursor);
            this.codeMirror.focus();
        }

        this.closeMathModal();
        this.#handleContentChange();
    }

    // =============================================
    // SHORTCUTS & GENERAL BINDINGS
    // =============================================

    handleShortcuts(event) {
        // Intercepter Ctrl+F (ou Cmd+F) pour Rechercher & Remplacer
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') {
            const activeEl = document.activeElement;
            if (this.element.contains(activeEl)) {
                event.preventDefault();
                this.toggleSearchReplace();
            }
        }
    }

    // =============================================
    // RECHERCHER & REMPLACER
    // =============================================

    toggleSearchReplace() {
        if (!this.hasSearchReplaceBarTarget) return;

        const bar = this.searchReplaceBarTarget;
        if (bar.classList.contains('hidden')) {
            bar.classList.remove('hidden');
            this.searchInputTarget.focus();
            this.performSearch();
        } else {
            bar.classList.add('hidden');
            this.searchResults = [];
            this.currentSearchIndex = -1;
            this.searchIndexIndicatorTarget.textContent = '0/0';
        }
    }

    performSearch() {
        const query = this.searchInputTarget.value;
        this.searchResults = [];
        this.currentSearchIndex = -1;

        if (!query) {
            this.searchIndexIndicatorTarget.textContent = '0/0';
            return;
        }

        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.state.doc.descendants((node, pos) => {
                if (node.isText) {
                    const text = node.text;
                    let idx = 0;
                    while ((idx = text.toLowerCase().indexOf(query.toLowerCase(), idx)) !== -1) {
                        this.searchResults.push({
                            from: pos + idx,
                            to: pos + idx + query.length
                        });
                        idx += query.length;
                    }
                }
            });
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            const text = this.codeMirror.getValue();
            let idx = 0;
            while ((idx = text.toLowerCase().indexOf(query.toLowerCase(), idx)) !== -1) {
                this.searchResults.push({
                    from: idx,
                    to: idx + query.length
                });
                idx += query.length;
            }
        }

        const count = this.searchResults.length;
        if (count > 0) {
            this.currentSearchIndex = 0;
            this.#highlightMatch();
        } else {
            this.searchIndexIndicatorTarget.textContent = '0/0';
        }
    }

    nextMatch() {
        if (this.searchResults.length === 0) return;
        this.currentSearchIndex = (this.currentSearchIndex + 1) % this.searchResults.length;
        this.#highlightMatch();
    }

    prevMatch() {
        if (this.searchResults.length === 0) return;
        this.currentSearchIndex = (this.currentSearchIndex - 1 + this.searchResults.length) % this.searchResults.length;
        this.#highlightMatch();
    }

    #highlightMatch() {
        if (this.currentSearchIndex < 0 || this.currentSearchIndex >= this.searchResults.length) return;

        const match = this.searchResults[this.currentSearchIndex];
        this.searchIndexIndicatorTarget.textContent = `${this.currentSearchIndex + 1}/${this.searchResults.length}`;

        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.commands.setTextSelection({ from: match.from, to: match.to });
            this.editor.commands.scrollIntoView();
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            const doc = this.codeMirror.getDoc();
            const fromPos = doc.posFromIndex(match.from);
            const toPos = doc.posFromIndex(match.to);
            this.codeMirror.setSelection(fromPos, toPos);
            this.codeMirror.scrollIntoView({ from: fromPos, to: toPos });
            this.codeMirror.focus();
        }
    }

    replaceMatch() {
        if (this.currentSearchIndex < 0 || this.currentSearchIndex >= this.searchResults.length) return;

        const replacement = this.replaceInputTarget.value;
        const match = this.searchResults[this.currentSearchIndex];

        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.commands.insertContentAt({ from: match.from, to: match.to }, replacement);
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            const doc = this.codeMirror.getDoc();
            const fromPos = doc.posFromIndex(match.from);
            const toPos = doc.posFromIndex(match.to);
            doc.replaceRange(replacement, fromPos, toPos);
        }

        this.performSearch();
    }

    replaceAllMatches() {
        if (this.searchResults.length === 0) return;

        const replacement = this.replaceInputTarget.value;

        if (this.currentMode === 'wysiwyg' && this.editor) {
            for (let i = this.searchResults.length - 1; i >= 0; i--) {
                const match = this.searchResults[i];
                this.editor.commands.insertContentAt({ from: match.from, to: match.to }, replacement);
            }
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            const doc = this.codeMirror.getDoc();
            for (let i = this.searchResults.length - 1; i >= 0; i--) {
                const match = this.searchResults[i];
                const fromPos = doc.posFromIndex(match.from);
                const toPos = doc.posFromIndex(match.to);
                doc.replaceRange(replacement, fromPos, toPos);
            }
        }

        this.performSearch();
    }

    // =============================================
    // MODE FOCUS / ZEN
    // =============================================

    toggleFocusMode() {
        const root = this.element;
        if (!this.hasFocusBtnTarget) return;
        const focusBtn = this.focusBtnTarget;

        if (root.classList.contains('djoliba-focus-mode')) {
            root.classList.remove('djoliba-focus-mode');
            root.style.position = '';
            root.style.inset = '';
            root.style.zIndex = '';
            root.style.background = '';
            root.style.padding = '';
            root.style.height = '';
            root.style.display = '';
            root.style.flexDirection = '';

            if (this.hasEditorContainerTarget) {
                const editorEl = this.editorContainerTarget.querySelector('.tiptap');
                if (editorEl) editorEl.style.minHeight = '480px';
            }
            if (this.codeMirror) {
                this.codeMirror.getWrapperElement().style.height = '';
            }

            focusBtn.classList.remove('bg-slate-200', 'text-djoliba');
        } else {
            root.classList.add('djoliba-focus-mode');
            root.style.position = 'fixed';
            root.style.inset = '0';
            root.style.zIndex = '40';
            root.style.background = '#ffffff';
            root.style.padding = '2rem';
            root.style.height = '100vh';
            root.style.display = 'flex';
            root.style.flexDirection = 'column';

            if (this.hasEditorContainerTarget) {
                const editorEl = this.editorContainerTarget.querySelector('.tiptap');
                if (editorEl) editorEl.style.minHeight = 'calc(100vh - 120px)';
            }
            if (this.codeMirror) {
                this.codeMirror.getWrapperElement().style.height = 'calc(100vh - 120px)';
            }

            focusBtn.classList.add('bg-slate-200', 'text-djoliba');
        }
    }

    // =============================================
    // TABLE DES MATIÈRES / OUTLINE
    // =============================================

    toggleOutline() {
        if (!this.hasOutlinePanelTarget || !this.hasOutlineBtnTarget) return;

        const panel = this.outlinePanelTarget;
        const btn = this.outlineBtnTarget;

        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            btn.classList.add('bg-slate-200', 'text-djoliba');
            this.updateOutline();
        } else {
            panel.classList.add('hidden');
            btn.classList.remove('bg-slate-200', 'text-djoliba');
        }
    }

    updateOutline() {
        if (!this.hasOutlineContentTarget || !this.hasOutlinePanelTarget) return;

        if (this.outlinePanelTarget.classList.contains('hidden')) return;

        const outlineContainer = this.outlineContentTarget;
        outlineContainer.innerHTML = '';

        let headers = [];

        if (this.currentMode === 'wysiwyg' && this.editor) {
            this.editor.state.doc.descendants((node, pos) => {
                if (node.type.name === 'heading') {
                    headers.push({
                        level: node.attrs.level,
                        text: node.textContent,
                        pos: pos
                    });
                }
            });
        } else if (this.currentMode === 'latex' && this.codeMirror) {
            const text = this.codeMirror.getValue();
            const regex = /\\(section|subsection|subsubsection)\*?\{([^}]+)\}/g;
            let match;
            while ((match = regex.exec(text)) !== -1) {
                const type = match[1];
                const title = match[2];
                const level = type === 'section' ? 1 : (type === 'subsection' ? 2 : 3);
                headers.push({
                    level: level,
                    text: title,
                    index: match.index
                });
            }
        }

        if (headers.length === 0) {
            outlineContainer.innerHTML = '<p class="text-[11px] text-slate-400 italic">Aucun titre structuré (H1, H2, H3) trouvé.</p>';
            return;
        }

        headers.forEach(h => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-full text-left py-1 hover:text-djoliba transition-colors block truncate font-medium';

            if (h.level === 1) {
                btn.classList.add('pl-0', 'text-slate-700', 'font-bold');
            } else if (h.level === 2) {
                btn.classList.add('pl-3', 'text-slate-500', 'text-[11px]');
            } else {
                btn.classList.add('pl-6', 'text-slate-400', 'text-[10px]');
            }

            btn.textContent = h.text;

            btn.addEventListener('click', () => {
                if (this.currentMode === 'wysiwyg' && this.editor) {
                    this.editor.commands.focus(h.pos);
                    this.editor.commands.scrollIntoView();
                } else if (this.currentMode === 'latex' && this.codeMirror) {
                    const doc = this.codeMirror.getDoc();
                    const pos = doc.posFromIndex(h.index);
                    this.codeMirror.setCursor(pos);
                    this.codeMirror.scrollIntoView(pos);
                    this.codeMirror.focus();
                }
            });

            outlineContainer.appendChild(btn);
        });
    }

    // =============================================
    // VERSIONS & INSTANTANÉS (SNAPSHOTS)
    // =============================================

    openSnapshotModal() {
        if (!this.hasSnapshotModalTarget) return;
        const modal = this.snapshotModalTarget;
        this.snapshotNameInputTarget.value = '';
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.classList.add('opacity-100');
            const card = modal.querySelector('.modal-card');
            if (card) card.classList.remove('scale-95', 'opacity-0');
            if (card) card.classList.add('scale-100', 'opacity-100');
        }, 50);

        this.loadSnapshots();
    }

    closeSnapshotModal() {
        if (!this.hasSnapshotModalTarget) return;
        const modal = this.snapshotModalTarget;
        const card = modal.querySelector('.modal-card');
        if (card) card.classList.remove('scale-100', 'opacity-100');
        if (card) card.classList.add('scale-95', 'opacity-0');
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 300);
    }

    async loadSnapshots() {
        if (!this.hasSnapshotListTarget) return;

        const list = this.snapshotListTarget;
        list.innerHTML = '<div class="text-center py-4 text-xs text-slate-400">Chargement des versions...</div>';

        try {
            const response = await fetch(`/api/projects/${this.projectIdValue}/snapshots`);
            const result = await response.json();

            if (result.success) {
                const snapshots = result.data;
                list.innerHTML = '';

                if (snapshots.length === 0) {
                    if (this.hasSnapshotEmptyMsgTarget) this.snapshotEmptyMsgTarget.classList.remove('hidden');
                    return;
                }

                if (this.hasSnapshotEmptyMsgTarget) this.snapshotEmptyMsgTarget.classList.add('hidden');

                snapshots.forEach(item => {
                    const dateFormatted = new Date(item.created_at).toLocaleString('fr-FR', {
                        day: 'numeric',
                        month: 'short',
                        hour: '2-digit',
                        minute: '2-digit'
                    });

                    const card = document.createElement('div');
                    card.className = 'flex items-center justify-between p-4 bg-white border border-slate-100 rounded-xl hover:border-slate-200 shadow-sm transition-all';

                    const infoCol = document.createElement('div');
                    infoCol.className = 'space-y-1 min-w-0 pr-4';

                    const name = document.createElement('h5');
                    name.className = 'text-xs font-bold text-slate-700 truncate';
                    name.textContent = item.name;

                    const metaRow = document.createElement('div');
                    metaRow.className = 'flex items-center gap-2 text-[10px] text-slate-400 font-semibold';

                    const dateSpan = document.createElement('span');
                    dateSpan.textContent = dateFormatted;

                    const badge = document.createElement('span');
                    badge.className = item.mode === 'wysiwyg'
                        ? 'px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 text-[8px] font-bold uppercase'
                        : 'px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-600 text-[8px] font-bold uppercase';
                    badge.textContent = item.mode === 'wysiwyg' ? 'Visuel' : 'LaTeX';

                    metaRow.appendChild(dateSpan);
                    metaRow.appendChild(badge);
                    infoCol.appendChild(name);
                    infoCol.appendChild(metaRow);

                    const actions = document.createElement('div');
                    actions.className = 'flex items-center gap-2 flex-shrink-0';

                    const restoreBtn = document.createElement('button');
                    restoreBtn.type = 'button';
                    restoreBtn.className = 'px-3 py-1.5 border border-djoliba text-djoliba hover:bg-djoliba/5 text-[10px] font-bold rounded-lg transition-all';
                    restoreBtn.textContent = 'Restaurer';
                    restoreBtn.addEventListener('click', () => this.restoreSnapshot(item));

                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition-all';
                    deleteBtn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" viewbox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>';
                    deleteBtn.addEventListener('click', () => this.deleteSnapshot(item.id));

                    actions.appendChild(restoreBtn);
                    actions.appendChild(deleteBtn);
                    card.appendChild(infoCol);
                    card.appendChild(actions);

                    list.appendChild(card);
                });
            } else {
                list.innerHTML = '<div class="text-center py-4 text-xs text-red-500">Erreur lors de la récupération des versions.</div>';
            }
        } catch (err) {
            console.error(err);
            list.innerHTML = '<div class="text-center py-4 text-xs text-red-500">Erreur réseau.</div>';
        }
    }

    async createSnapshot() {
        const name = this.snapshotNameInputTarget.value.trim();
        const contentWysiwyg = this.editor ? this.turndownService.turndown(this.editor.getHTML()) : '';
        const contentLatex = this.codeMirror ? this.codeMirror.getValue() : (this.hasInputTarget ? this.inputTarget.value : '');
        const mode = this.currentMode;

        this.#setStatus("Création de l'instantané...");

        try {
            const response = await fetch(`/api/projects/${this.projectIdValue}/snapshots`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name,
                    content_wysiwyg: contentWysiwyg,
                    content_latex: contentLatex,
                    mode: mode
                })
            });
            const result = await response.json();

            if (result.success) {
                this.snapshotNameInputTarget.value = '';
                this.#setStatus("Instantané créé !");
                this.loadSnapshots();
            } else {
                this.#setStatus("Erreur lors de la création.", true);
            }
        } catch (err) {
            console.error(err);
            this.#setStatus("Erreur réseau.", true);
        }
    }

    async restoreSnapshot(snapshot) {
        if (!confirm(`Voulez-vous vraiment restaurer la version "${snapshot.name}" ? Votre travail non enregistré sera perdu.`)) {
            return;
        }

        try {
            this.setEditorContent(snapshot.content_wysiwyg, snapshot.content_latex, snapshot.mode);
            this.closeSnapshotModal();
            this.#setStatus("Version restaurée !");
            this.autosave();
        } catch (err) {
            console.error(err);
            this.#setStatus("Erreur lors de la restauration.", true);
        }
    }

    async deleteSnapshot(snapshotId) {
        if (!confirm("Voulez-vous supprimer définitivement cet instantané ?")) {
            return;
        }

        this.#setStatus("Suppression...");

        try {
            const response = await fetch(`/api/projects/${this.projectIdValue}/snapshots/${snapshotId}`, {
                method: 'DELETE'
            });
            const result = await response.json();

            if (result.success) {
                this.#setStatus("Instantané supprimé !");
                this.loadSnapshots();
            } else {
                this.#setStatus("Erreur de suppression.", true);
            }
        } catch (err) {
            console.error(err);
            this.#setStatus("Erreur réseau.", true);
        }
    }
}
