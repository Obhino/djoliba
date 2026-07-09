export class LatexConverter {
    static htmlToLatex(html) {
        if (!html) return '';

        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        const walk = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.textContent;
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                return '';
            }

            let childrenText = '';
            node.childNodes.forEach(child => {
                childrenText += walk(child);
            });

            const tagName = node.tagName.toLowerCase();

            switch (tagName) {
                case 'cite': {
                    const citeKey = node.getAttribute('data-cite-key');
                    return citeKey ? `\\cite{${citeKey}}` : childrenText;
                }
                case 'h1':
                    return `\\section{${childrenText.trim()}}\n\n`;
                case 'h2':
                    return `\\subsection{${childrenText.trim()}}\n\n`;
                case 'h3':
                    return `\\subsubsection{${childrenText.trim()}}\n\n`;
                case 'strong':
                case 'b':
                    return `\\textbf{${childrenText}}`;
                case 'em':
                case 'i':
                    return `\\textit{${childrenText}}`;
                case 'p':
                    return `${childrenText.trim()}\n\n`;
                case 'ul':
                    return `\\begin{itemize}\n${childrenText}\\end{itemize}\n\n`;
                case 'ol':
                    return `\\begin{enumerate}\n${childrenText}\\end{enumerate}\n\n`;
                case 'li':
                    return `  \\item ${childrenText.trim()}\n`;
                case 'br':
                    return `\\\\\n`;
                default:
                    return childrenText;
            }
        };

        let latex = '';
        doc.body.childNodes.forEach(child => {
            latex += walk(child);
        });

        return latex.replace(/\n{3,}/g, '\n\n').trim();
    }

    static latexToHtml(latex) {
        if (!latex) return '';

        let html = latex;

        // Convert citations: \cite{cle} -> <cite data-cite-key="cle">[@cle]</cite>
        html = html.replace(/\\cite\{([^}]+)\}/gi, (match, key) => {
            const cleanKey = key.trim();
            return `<cite data-cite-key="${cleanKey}">[@${cleanKey}]</cite>`;
        });

        // Convert lists first (itemize & enumerate)
        html = html.replace(/\\begin{itemize}([\s\S]*?)\\end{itemize}/g, (match, content) => {
            const items = content.split(/\\item/).map(item => item.trim()).filter(Boolean);
            return '<ul>' + items.map(item => `<li>${item}</li>`).join('') + '</ul>';
        });

        html = html.replace(/\\begin{enumerate}([\s\S]*?)\\end{enumerate}/g, (match, content) => {
            const items = content.split(/\\item/).map(item => item.trim()).filter(Boolean);
            return '<ol>' + items.map(item => `<li>${item}</li>`).join('') + '</ol>';
        });

        // Convert inline styles
        html = html.replace(/\\textbf{([^}]+)}/g, '<strong>$1</strong>');
        html = html.replace(/\\(textit|emph){([^}]+)}/g, '<em>$2</em>');

        // Convert headings
        html = html.replace(/\\section{([^}]+)}/g, '<h1>$1</h1>');
        html = html.replace(/\\subsection{([^}]+)}/g, '<h2>$1</h2>');
        html = html.replace(/\\subsubsection{([^}]+)}/g, '<h3>$1</h3>');

        // Convert double newlines to paragraphs
        const paragraphs = html.split(/\n\n+/);
        html = paragraphs.map(p => {
            p = p.trim();
            if (!p) return '';
            if (/^<(ul|ol|h1|h2|h3)/i.test(p)) return p;
            return `<p>${p.replace(/\n/g, '<br>')}</p>`;
        }).join('');

        return html;
    }
}
