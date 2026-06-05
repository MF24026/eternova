/// <reference types="vite/client" />

// Declare *.vue modules as typed so vue-tsc resolves SFC imports without TS7016 errors.
// Without this shim, `import Foo from '@/Components/Foo.vue'` implicitly has `any` type.
declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent;
    export default component;
}
