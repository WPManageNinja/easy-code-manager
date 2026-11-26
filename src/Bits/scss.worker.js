import {compileString} from 'sass';

self.onmessage = ({data}) => {
    try {
        const result = compileString(data, {sourceMap: false});
        self.postMessage({css: result.css});
    } catch (error) {
        const start = error.span?.start;
        self.postMessage({
            message: error.sassMessage || error.message,
            line: start ? start.line + 1 : null,
            column: start ? start.column + 1 : null
        });
    }
};
