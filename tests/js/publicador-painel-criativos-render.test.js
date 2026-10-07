import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Gate de RENDER (261007) — não de estrutura.
//
// Os outros testes deste diretório (`publicador-mesa.test.js` etc.) leem a
// FONTE como texto e conferem padrões com regex — nenhum deles monta o
// componente. Foi exatamente por essa fenda que `kit.estrategia` (um OBJETO
// de verdade do `PublicadorCriativoKitPresenter`, nunca string) chegou a
// produção sendo renderizado cru em `{kit.estrategia}` e derrubou a árvore
// React inteira ("Objects are not valid as a React child") — tela preta no
// kit id 2, 05-07/10/2026.
//
// Este arquivo compila `PainelCriativos.jsx` de verdade com esbuild (o mesmo
// motor do Vite) e renderiza no servidor (`react-dom/server`) com o JSON real
// que o presenter manda — a mesma defesa que pegaria isto antes do deploy.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const ENTRY = path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/PainelCriativos.jsx');

/** Compila o componente de verdade (JSX + os imports reais dele) e devolve o `export default`. */
async function montarPainelCriativos() {
    const resultado = await esbuild.build({
        entryPoints: [ENTRY],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });

    // Precisa viver DENTRO da árvore do projeto (não em os.tmpdir()): a resolução de módulos ESM
    // do Node para `import 'react'` sobe os diretórios a partir do arquivo até achar um
    // `node_modules` — um temp fora da árvore nunca acha o `node_modules` do projeto.
    const outfile = path.join(__dirname, `.painel-criativos-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        const mod = await import(pathToFileURL(outfile).href);

        return mod.default;
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** `c` mínimo (valor de `useCriativosDoPublicador`) para a fase 'kit' — ações nunca chamadas aqui. */
const c = (kit) => ({
    fase: 'kit',
    kit,
    erro: null,
    processando: null,
    confirmacaoRecusada: false,
    motivos: {},
    alvo: { grupo: 'GERAL' },
    fechar: () => {},
    limparErro: () => {},
    recusarConfirmacao: () => {},
    mostrarConfirmacao: () => {},
    gerar: () => {},
    aprovar: () => {},
    regenerar: () => {},
    aprovarKit: () => {},
    novoKit: () => {},
    mudarMotivo: () => {},
});

/** Kit no formato REAL do presenter (campos de `PublicadorCriativoKitPresenter::paraTela()`). */
const kitBase = (overrides = {}) => ({
    kit_id: 2,
    grupo: 'GERAL',
    status: 'planejado',
    etapa: null,
    em_andamento: false,
    erro: null,
    estrategia: null,
    minimo_aprovadas: 4,
    prontas: 0,
    aprovadas: 0,
    prontas_sem_risco: 0,
    reprovadas: 0,
    referencias: [],
    slots: [],
    ...overrides,
});

// Dump real de produção (kit id 2, 05/10/2026) — o objeto que derrubava a tela.
const ESTRATEGIA_REAL = {
    publico: 'Pessoas que buscam mobiliar ou renovar a sala de estar ou ambiente de escritório com uma mesa de centro de design contemporâneo.',
    direcao_visual: 'Iluminação neutra e elegante, estética minimalista e moderna, valorizando o volume da base piramidal e a integração com o ambiente.',
    proposta_de_valor: 'Mesa de centro com base pirâmide que agrega sofisticação e apoio funcional para ambientes residenciais e corporativos.',
};

test('PainelCriativos — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const PainelCriativos = await montarPainelCriativos();

    await contexto.test('kit.estrategia objeto (formato real de produção) — nunca crua, vira as 3 linhas rotuladas', () => {
        const kit = kitBase({ estrategia: ESTRATEGIA_REAL });
        const html = renderToStaticMarkup(React.createElement(PainelCriativos, { c: c(kit), titulo: 'Grupo teste' }));

        assert.match(html, /Para quem é/);
        assert.match(html, /O que destaca/);
        assert.match(html, /Como vai parecer/);
        assert.match(html, /mobiliar ou renovar/);
        assert.match(html, /base pirâmide/);
        assert.match(html, /Iluminação neutra/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('kit.estrategia string (formato alternativo aceito) — renderiza direto', () => {
        const kit = kitBase({ estrategia: 'Texto corrido de estratégia.' });
        const html = renderToStaticMarkup(React.createElement(PainelCriativos, { c: c(kit), titulo: 'Grupo teste' }));

        assert.match(html, /Texto corrido de estratégia\./);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('kit.estrategia em formato inesperado (array, número) — nunca lança, nunca aparece cru', () => {
        for (const estrategiaInvalida of [['publico', 'x'], 42, true]) {
            const kit = kitBase({ estrategia: estrategiaInvalida });
            assert.doesNotThrow(() => {
                const html = renderToStaticMarkup(React.createElement(PainelCriativos, { c: c(kit), titulo: 'Grupo teste' }));
                assert.doesNotMatch(html, /\[object Object\]/);
            });
        }
    });

    await contexto.test('kit.erro, slot.erro, slot.rotulo, slot.objetivo, validacao_mensagem e explicacao em formato inesperado — nunca lança', () => {
        const kit = kitBase({
            status: 'planejado',
            estrategia: ESTRATEGIA_REAL,
            slots: [
                {
                    indice: 1,
                    tipo: 'hero',
                    rotulo: { pt: 'Hero' }, // formato inesperado: objeto em vez de string
                    objetivo: ['destaque', 'produto'], // formato inesperado: array
                    status: 'pronto',
                    etapa: null,
                    erro: null,
                    imagem_url: null,
                    modelo: null,
                    latencia_ms: null,
                    regeneracoes: 0,
                    regeneracoes_restantes: 2,
                    validacao_status: 'reprovada',
                    validacao_mensagem: { aviso: 'risco' }, // formato inesperado
                    validacao_problemas: [{ gravidade: 'alta', explicacao: { nota: 'algo' } }], // explicacao inesperada
                    pode_aprovar: false,
                    exige_confirmacao_risco: true,
                    no_anuncio: false,
                    imagem_id: null,
                },
                {
                    indice: 2,
                    tipo: 'detalhe',
                    rotulo: 'Detalhe',
                    objetivo: 'Mostrar o produto de perto',
                    status: 'erro',
                    etapa: null,
                    erro: { mensagem: 'falhou' }, // formato inesperado
                    imagem_url: null,
                    modelo: null,
                    latencia_ms: null,
                    regeneracoes: 1,
                    regeneracoes_restantes: 1,
                    validacao_status: null,
                    validacao_mensagem: null,
                    validacao_problemas: [],
                    pode_aprovar: false,
                    exige_confirmacao_risco: false,
                    no_anuncio: false,
                    imagem_id: null,
                },
            ],
            referencias: [{ indice: 0, nome: { arquivo: 'foto.jpg' }, url: 'http://x/ref0' }], // nome inesperado
        });

        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelCriativos, { c: c(kit), titulo: 'Grupo teste' }));
        });

        // Os campos em formato inesperado não aparecem crus — mas o resto da tela (fallbacks,
        // campos válidos do segundo slot) continua de pé.
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.match(html, /Detalhe/);
        assert.match(html, /Mostrar o produto de perto/);
        assert.match(html, /Risco apontado pela validação automática\./);
        assert.match(html, /Risco apontado automaticamente\./);
    });

    await contexto.test('kit.estrategia ausente (null) — não renderiza nada, sem lançar', () => {
        const kit = kitBase({ estrategia: null });
        assert.doesNotThrow(() => {
            renderToStaticMarkup(React.createElement(PainelCriativos, { c: c(kit), titulo: 'Grupo teste' }));
        });
    });
});
