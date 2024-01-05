// Error handling class
// Usable in all components
export default class Errors {
    constructor() {
        this.errors = {}
    }

    get(field) {
        if (this.errors[field]) {
            return this.errors[field]
        }
    }

    first(field) {
        if (this.errors[field]) {
            if (typeof this.errors[field] === 'string') {
                return this.errors[field];
            } else {
                let keys = Object.keys(this.errors[field]);
                return keys.length ? this.errors[field][keys[0]] : '';
            }
        }
    }

    has(field) {
        return !!this.errors[field]
    }

    record(errors) {
        this.errors = errors
    }

    clear(field) {
        if (field) {
            this.errors[field] = null
        } else {
            this.errors = {}
        }
    }
}
