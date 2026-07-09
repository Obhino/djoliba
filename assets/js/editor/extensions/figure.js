export function getFigureNode(Node) {
    return Node.create({
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
}

export function getFigcaptionNode(Node) {
    return Node.create({
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
}
