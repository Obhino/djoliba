export function getCitationNode(Node) {
    return Node.create({
        name: 'citation',
        group: 'inline',
        inline: true,
        atom: true,
        addAttributes() {
            return {
                citeKey:     { default: '' },
                displayText: { default: '' },
                style:       { default: 'apa' }
            };
        },
        parseHTML() {
            return [{
                tag: 'cite[data-cite-key]',
                getAttrs: dom => ({
                    citeKey:     dom.getAttribute('data-cite-key') || '',
                    displayText: dom.getAttribute('data-display') || dom.textContent || '',
                    style:       dom.getAttribute('data-style') || 'apa'
                })
            }];
        },
        renderHTML({ HTMLAttributes }) {
            return ['cite', {
                'data-cite-key': HTMLAttributes.citeKey,
                'data-display':  HTMLAttributes.displayText,
                'data-style':    HTMLAttributes.style,
                class:           'djoliba-citation inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-amber-50 border border-amber-200 text-amber-700 text-[11px] rounded font-mono cursor-default select-none hover:bg-amber-100 transition-colors',
                title:           `Référence : ${HTMLAttributes.citeKey}`
            }, HTMLAttributes.displayText || `[@${HTMLAttributes.citeKey}]`];
        }
    });
}
