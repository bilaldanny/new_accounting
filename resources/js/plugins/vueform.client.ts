import type { App as VueApp } from 'vue';
import Vueform from '@vueform/vueform';
import vueformConfig from '../../../vueform.config';

type ResolveVuePlugin = <T>(module: T) => T;

export function registerVueform(app: VueApp, resolveVuePlugin: ResolveVuePlugin): void {
    app.use(resolveVuePlugin(Vueform), vueformConfig);
}
