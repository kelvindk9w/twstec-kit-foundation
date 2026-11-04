import type { ComponentType } from 'react';

// As páginas do Inertia em dois lugares, com a regra do kit: O APLICATIVO
// VENCE. A página `auth/login` é procurada primeiro em
// resources/js/pages/auth/login.tsx do aplicativo e, sem ela, nas telas do kit
// (vendor/twstec/kit-foundation/resources/react/pages). Para mudar uma tela do
// kit, copie só aquele arquivo para resources/js/pages/ no mesmo caminho.
//
// Os caminhos são a partir da raiz do aplicativo (o Vite os resolve assim em
// qualquer arquivo); cada página vira um pedaço próprio do build.

type PageModule = { default: ComponentType };

const application = import.meta.glob<PageModule>(
    '/resources/js/pages/**/*.tsx',
);
const kit = import.meta.glob<PageModule>(
    '/vendor/twstec/kit-foundation/resources/react/pages/**/*.tsx',
);

export async function resolvePage(name: string): Promise<ComponentType> {
    const load =
        application[`/resources/js/pages/${name}.tsx`] ??
        kit[`/vendor/twstec/kit-foundation/resources/react/pages/${name}.tsx`];

    if (!load) {
        throw new Error(`Page not found: ${name}`);
    }

    return (await load()).default;
}
