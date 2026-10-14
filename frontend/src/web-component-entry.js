import { defineCustomElement } from 'vue';
import { createPinia } from 'pinia';
import { I18nInjectionKey } from 'vue-i18n';
import WebComponent from './WebComponent.ce.vue';
import i18n from './i18n.js';

const pinia = createPinia();

const ScheduleElement = defineCustomElement(WebComponent, {
  configureApp(app) {
    app.use(pinia);
    app.use(i18n);
    // Inside a custom element, vue-i18n's `useI18n()` resolves the instance via
    // `inject(I18nInjectionKey)` instead of the per-app symbol used in normal apps
    // (see getI18nInstance in vue-i18n). Provide it explicitly so it is reachable.
    app.provide(I18nInjectionKey, i18n);
  },
});

// Register the custom element
if (!customElements.get('barcamp-schedule')) {
  customElements.define('barcamp-schedule', ScheduleElement);
}
