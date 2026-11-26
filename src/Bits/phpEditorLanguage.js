import {php} from '@codemirror/lang-php';
import {javascriptLanguage} from '@codemirror/lang-javascript';
import {highlightingFor, syntaxTree} from '@codemirror/language';
import {Decoration, ViewPlugin} from '@codemirror/view';
import {Prec} from '@codemirror/state';
import {highlightTree} from '@lezer/highlight';

const opener = /^<<<[ \t]*(['"]?)JS\1[ \t]*$/;
const closer = /^[ \t]*JS(?![a-zA-Z0-9_\u0080-\uFFFF])/;
const substitution = /(?:^|[^\\])(?:\\\\)*\$(?:[a-zA-Z_\u0080-\uFFFF]|\{)/;

function closingLine(doc, tokenEnd) {
    const last = doc.lineAt(tokenEnd);
    if (closer.test(last.text)) {
        return last.from;
    }
    if (last.number === doc.lines) {
        return doc.length;
    }

    // The PHP tokenizer can stop at the JS prefix of JSON.stringify. PHP needs
    // a complete identifier. Continue to its real closing line in that case.
    let pos = last.to + 1;
    for (const line of doc.iterLines(last.number + 1)) {
        if (closer.test(line)) return pos;
        pos += line.length + 1;
    }
    return doc.length;
}

function decorations(view, previousTrees, visibleTrees) {
    const {state, viewport} = view;
    const {doc} = state;
    const marks = [];
    const highlighter = {style: tags => highlightingFor(state, tags)};
    let consumed = 0;

    syntaxTree(state).iterate({to: viewport.to, enter(node) {
        if (node.to <= consumed) return false;
        if (node.name !== 'HeredocString' || node.from < consumed) return;
        const first = doc.lineAt(node.from);
        const opening = opener.exec(first.text.slice(node.from - first.from));
        if (!opening) return;

        const end = closingLine(doc, node.to);
        consumed = end;
        const start = first.to + 1;
        if (end === doc.length || end <= viewport.from || start >= viewport.to || start >= end) return;
        const body = doc.sliceString(start, end);
        // A nowdoc is literal. Keep PHP styling when a heredoc interpolates PHP.
        if (opening[1] !== "'" && substitution.test(body)) return;

        // Scrolling, or editing another block, can change the visible marks
        // without changing this JavaScript. Retain trees only for visible bodies.
        const tree = previousTrees.get(body) || javascriptLanguage.parser.parse(body);
        visibleTrees.set(body, tree);
        highlightTree(tree, highlighter, (from, to, classes) => {
            marks.push(Decoration.mark({class: classes}).range(start + from, start + to));
        }, Math.max(0, viewport.from - start), Math.min(body.length, viewport.to - start));
    }});
    return Decoration.set(marks);
}

const heredocColors = ViewPlugin.fromClass(class {
    constructor(view) {
        this.trees = new Map();
        this.draw(view);
    }
    draw(view) {
        const visibleTrees = new Map();
        this.decorations = decorations(view, this.trees, visibleTrees);
        this.trees = visibleTrees;
    }
    update(update) {
        if (update.docChanged || update.viewportChanged || update.transactions.some(tr => tr.reconfigured) ||
            syntaxTree(update.startState) !== syntaxTree(update.state)) {
            this.draw(update.view);
        }
    }
}, {decorations: plugin => plugin.decorations});

export default function phpEditorLanguage(plain) {
    return [php({plain}), Prec.highest(heredocColors)];
}
