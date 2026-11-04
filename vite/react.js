// =============================================================================
// Telas React do kit no build do Vite do aplicativo (starter React).
//
// As páginas, os layouts, os componentes, os hooks, os tipos e o `lib/` das
// telas do kit (login, cadastro, segundo fator, perfil, contas, chaves de API,
// webhooks…) moram neste pacote, em resources/react/ — com a MESMA árvore do
// resources/js/ do aplicativo. O projeto criado não recebe cópia: tem só o que
// é dele (app.tsx, a página inicial, o dashboard) e o que quiser mudar.
//
// A regra é a de todo o kit: O APLICATIVO VENCE. Cada `@/<caminho>`
// (o apelido do laravel-vite-plugin para resources/js) é procurado primeiro em
// resources/js/<caminho> do aplicativo e, se não existir, em
// vendor/twstec/kit-foundation/resources/react/<caminho>. Para mudar uma tela
// ou um componente, copie SÓ aquele arquivo para o mesmo caminho em
// resources/js/; ele passa a valer também para as telas do kit que o importam.
// As páginas seguem a mesma regra pelo resolvePage (lib/pages.ts).
//
// O que este plugin faz:
//
// 1. RESERVA DO `@/`: depois que o apelido vira `/resources/js/<caminho>`, se
//    nenhum arquivo do aplicativo responde (com as extensões de sempre ou
//    index.*), aponta para o mesmo caminho no pacote.
//
// 2. FONTES DO TAILWIND: o Tailwind do aplicativo não lê o vendor/; a linha
//    `@source` com a pasta das telas do kit entra no resources/css/app.css na
//    hora do build (o CSS do aplicativo não precisa citá-la).
//
// 3. `resolve.preserveSymlinks`: no monorepo o pacote é um LINK (path
//    repository do Composer). Sem isto, o Vite seguiria o link e procuraria
//    react, @inertiajs/react e os demais a partir de packages/foundation, fora
//    do node_modules do aplicativo.
//
// O tsconfig.json do starter faz a mesma reserva para os tipos (`paths` com os
// dois lugares, nessa ordem) e inclui a pasta do pacote na checagem.
// =============================================================================

import { existsSync, statSync } from 'node:fs';
import { resolve as resolvePath } from 'node:path';

/** Onde as telas do kit ficam, a partir da raiz do aplicativo. */
export const KIT_REACT = 'vendor/twstec/kit-foundation/resources/react';

const APPLICATION = 'resources/js';

const CANDIDATES = ['', '.tsx', '.ts', '.jsx', '.js', '/index.tsx', '/index.ts', '/index.jsx', '/index.js'];

function existing(base) {
    for (const suffix of CANDIDATES) {
        const file = base + suffix;

        if (existsSync(file) && statSync(file).isFile()) {
            return file;
        }
    }

    return null;
}

export default function kitReact() {
    let root = process.cwd();

    return {
        name: 'twstec-kit-react',
        enforce: 'pre',
        config(config) {
            if (config.root) {
                root = resolvePath(config.root);
            }

            return { resolve: { preserveSymlinks: true } };
        },
        configResolved(config) {
            root = config.root;
        },
        resolveId(source) {
            const path = source.split('?')[0];
            let relative = null;

            if (path.startsWith(`/${APPLICATION}/`)) {
                relative = path.slice(APPLICATION.length + 2);
            } else if (path.startsWith(`${root}/${APPLICATION}/`)) {
                relative = path.slice(root.length + APPLICATION.length + 2);
            }

            if (relative === null || existing(resolvePath(root, APPLICATION, relative))) {
                return null;
            }

            return existing(resolvePath(root, KIT_REACT, relative));
        },
        transform(code, id) {
            const file = id.split('?')[0];

            if (file === resolvePath(root, 'resources/css/app.css')) {
                return { code: `${code}\n@source '../../${KIT_REACT}';\n`, map: null };
            }

            return null;
        },
    };
}
