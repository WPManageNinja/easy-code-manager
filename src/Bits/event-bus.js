import mitt from 'mitt';

export default {
    install: (app, options) => {
        app.config.globalProperties.$eventBus = mitt();
    }
}
