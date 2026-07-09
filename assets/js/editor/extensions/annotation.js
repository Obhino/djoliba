export function getAnnotationMark(Mark) {
    return Mark.create({
        name: 'annotation',
        addAttributes() {
            return {
                id: { default: null },
                comment: { default: '' },
                author: { default: '' },
                createdAt: { default: '' }
            };
        },
        parseHTML() {
            return [{
                tag: 'span[data-annotation-id]',
                getAttrs: dom => ({
                    id: dom.getAttribute('data-annotation-id'),
                    comment: dom.getAttribute('data-comment') || '',
                    author: dom.getAttribute('data-author') || '',
                    createdAt: dom.getAttribute('data-created-at') || ''
                })
            }];
        },
        renderHTML({ HTMLAttributes }) {
            return ['span', {
                'data-annotation-id': HTMLAttributes.id,
                'data-comment': HTMLAttributes.comment,
                'data-author': HTMLAttributes.author,
                'data-created-at': HTMLAttributes.createdAt,
                class: 'djoliba-annotation bg-amber-100 border-b border-amber-300 cursor-pointer hover:bg-amber-200/80 transition-colors'
            }, 0];
        }
    });
}
