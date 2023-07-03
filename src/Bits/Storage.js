const generator = key => 'fsnip-' + key;

export default class Storage {
    static get(key, defaultValue = '') {
        let value = localStorage.getItem(generator(key));

        if (value && ['{', '['].indexOf(value[0]) !== -1) {
            value = JSON.parse(value);
        }

        if (!value) {
            return defaultValue;
        }

        return value;
    }

    static set(key, value) {
        if (typeof value === 'object') {
            value = JSON.stringify(value);
        }

        localStorage.setItem(generator(key), value);
    }

    static remove(key) {
        localStorage.removeItem(generator(key));
    }

    static clear() {
        localStorage.clear();
    }
}
