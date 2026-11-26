import {php, phpLanguage} from '@codemirror/lang-php';
import {LanguageSupport, indentNodeProp, languageDataProp} from '@codemirror/language';
import {parseMixed} from '@lezer/common';
import {parser as javascriptParser} from '@lezer/javascript';

const opener = /^<<<[ \t]*(['"]?)JS\1(?:\r\n?|\n)/;
const substitution = /(?:^|[^\\])(?:\\\\)*\$(?:[a-zA-Z_\u0080-\uFFFF]|\{)/;

// Only the token colors change. Editing commands inside the nested syntax
// tree still use PHP's bracket, completion and indentation rules.
const inheritIndent = context => context.continue();
const heredocParser = javascriptParser.configure({
    props: [
        languageDataProp.add(type => type.isTop ? phpLanguage.data : undefined),
        indentNodeProp.add(() => inheritIndent)
    ]
});

const javascriptHeredocs = parseMixed((node, input) => {
    if (node.name !== 'HeredocString') return null;

    // Lezer supplies the complete PHP token, including both delimiters.
    const text = input.read(node.from, node.to);
    const opening = opener.exec(text);
    if (!opening) return null;
    const from = opening[0].length;
    const to = Math.max(text.lastIndexOf('\n'), text.lastIndexOf('\r'));
    if (from >= to) return null;
    // A nowdoc is literal. An interpolating heredoc keeps the PHP presentation.
    if (opening[1] !== "'" && substitution.test(text.slice(from, to))) return null;

    return {
        parser: heredocParser,
        overlay: [{from: node.from + from, to: node.from + to}]
    };
});

export default function phpEditorLanguage(plain) {
    const support = php({plain});
    return new LanguageSupport(support.language.configure({wrap: javascriptHeredocs}), support.support);
}
