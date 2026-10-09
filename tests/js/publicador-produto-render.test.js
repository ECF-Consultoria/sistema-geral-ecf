import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 175, Plano 175-04 — render REAL (esbuild + react-dom/server) de
// `PainelDoProduto.jsx` (os 6 blocos da §3 da ETAPA-3), mesmo harness de
// `publicador-visao-geral-render.test.js` (Fase 173, plano 06) e de
// `publicador-painel-criativos-render.test.js`.
//
// Por que render REAL e não regex sobre a fonte: foi exatamente por essa
// fenda que `kit.estrategia` (um OBJETO do presenter) chegou a produção
// sendo renderizado cru e derrubou a árvore React inteira — "Objects are
// not valid as a React child", tela preta de 05-07/10/2026. Esta tela expõe
// DEZENAS de campos novos de uma vez (família, ofertas, histórico,
// criativos, mapeamento), então o gate cobre lista vazia, campo nulo, campo
// em formato inesperado e conta sem Company (D23).
//
// `PainelDoProduto.jsx` fica em `Components/Mlb/Publicador/` (não dentro de
// `Pages/.../Produto.jsx`) de propósito — a página soma `AppLayout` em
// volta, e `AppLayout` arrasta sino de notificações/tema/Modo TV, uma
// árvore pesada demais só pra testar os blocos. Mesma decisão da 173-06.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const ENTRY = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx');

// Stub de route() global — mesmo truque de `publicador-visao-geral-render.test.js`.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — substitui por
// stub inline (mesmo tratamento da 173-06).
const STUB_INERTIA = path.join(__dirname, `.produto-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/** Compila o componente de verdade (JSX + imports reais) e devolve os exports. */
async function montarPainelDoProduto() {
    const resultado = await esbuild.build({
        entryPoints: [ENTRY],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `textoSeguro` vem de `BarraDaConta.jsx`, o que arrasta
        // `@radix-ui/react-popover` (Trocar empresa) e `axios`
        // (`SeletorEmpresaBusca.jsx`); `ModalDetalheAnuncio` arrasta `recharts`
        // e `@radix-ui/react-dialog`. Nada disso é renderizado aqui.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    const outfile = path.join(__dirname, `.produto-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const produtoBase = (overrides = {}) => ({
    id: 10,
    sku: 'CAD-01',
    nome: 'Cadeira Executiva ECF',
    origem: 'publicador',
    oferta_id: null,
    categoria: 'Casa, Móveis e Decoração › Cadeiras de Escritório',
    estoque_total: 7,
    foto_url: null,
    editor_url: '/editor/10',
    base_excluido: false,
    ...overrides,
});

const faseBase = (overrides = {}) => ({
    produto_id: 10,
    fase: 1,
    rotulo: '1 unidade',
    sku: 'CAD-01',
    quantidade_kit: 1,
    estado: { chave: 'publicado', rotulo: 'publicado', faltam: 0 },
    estado_fase: 'publicada',
    ofertas_no_ar: 2,
    estoque_proprio: true,
    estoque_calculado_valor: null,
    rascunho_id: 55,
    editor_url: '/editor/10',
    ...overrides,
});

const ofertaBase = (overrides = {}) => ({
    fase: 1,
    produto_id: 10,
    listing_type_id: 'gold_special',
    tipo_rotulo: 'Clássico',
    titulo: 'Cadeira Executiva ECF Giratória',
    ml_item_id: 'MLB1111',
    preco: 199.9,
    vendas: 4,
    vendas_publicacao: 4,
    visitas: 120,
    situacao: 'active',
    visitas_nao_avaliadas: false,
    detalhe_disponivel: true,
    detalhe_motivo: null,
    ...overrides,
});

const propsBase = (overrides = {}) => ({
    empresa: {
        chave: 'empresa-7', nome: 'Polo das Fases', programa: 'polos', programa_rotulo: 'Polos',
        company_id: 459, token: 'ativo', portal: { situacao: 'sincronizado', novas: 0 },
    },
    liberada: true,
    produto: produtoBase(),
    fase_destacada: null,
    fases: [faseBase()],
    proxima_fase: { numero: 2, quantidade_sugerida: 2, habilitado: true, motivo: null },
    ofertas: [ofertaBase()],
    historico: [],
    criativos: [],
    mapeamento: {
        vazio: false,
        medidas: { comprimento: 60, largura: 50, altura: 110, unidade: 'cm' },
        peso: 12.5,
        material: 'Couro sintético',
        ean: '7896553367645',
    },
    abas: { company_id: 459 },
    ...overrides,
});

test('PainelDoProduto — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const { default: PainelDoProduto } = await montarPainelDoProduto();

    await contexto.test('props completas — os 6 blocos da §3 aparecem sem crashar', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase()));
        });
        assert.match(html, /Cadeira Executiva ECF/);
        assert.match(html, /CAD-01/);
        assert.match(html, /Cadeiras de Escrit[óo]rio/);
        assert.match(html, /Editar Fase 1/);
        assert.match(html, /Fases/);
        assert.match(html, /An[úu]ncios no ar/);
        assert.match(html, /MLB1111/);
        assert.match(html, /Hist[óo]rico/);
        assert.match(html, /Criativos/);
        assert.match(html, /Mapeamento/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    // ─── Bloco 2: fases ───
    await contexto.test('fases vazio renderiza "Fase 1 · Não iniciada" com "Começar rascunho", nunca lista vazia', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({ fases: [] })));
        assert.match(html, /Fase 1/);
        assert.match(html, /N[ãa]o iniciada/);
        assert.match(html, /Come[çc]ar rascunho/);
    });

    await contexto.test('fase igual a fase_destacada recebe o anel amarelo', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            fase_destacada: 2,
            fases: [faseBase(), faseBase({ produto_id: 11, fase: 2, rotulo: 'Kit 2', sku: 'CAD-01-KIT2', quantidade_kit: 2 })],
        })));
        assert.match(html, /Kit 2/);
        assert.match(html, /ring-ecf-yellow/);
    });

    await contexto.test('combo vinculado mostra "estoque próprio" com o valor calculado ao lado', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            fases: [faseBase(), faseBase({
                produto_id: 11, fase: 3, rotulo: 'Kit 3', quantidade_kit: 3,
                estoque_proprio: true, estoque_calculado_valor: 2,
            })],
        })));
        assert.match(html, /estoque pr[óo]prio/i);
        assert.match(html, /calculado/i);
    });

    await contexto.test('proxima_fase.habilitado=false renderiza o botão desabilitado COM o motivo visível (D23)', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            proxima_fase: { numero: 2, quantidade_sugerida: 2, habilitado: false, motivo: 'Publique a Fase 1 primeiro' },
        })));
        assert.match(html, /Criar Fase 2/);
        assert.match(html, /Publique a Fase 1 primeiro/);
        assert.match(html, /disabled/);
    });

    await contexto.test('proxima_fase.habilitado=true ainda fica desabilitado nesta plan, com "Em breve nesta tela"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase()));
        assert.match(html, /Criar Fase 2/);
        assert.match(html, /Em breve nesta tela/);
        assert.match(html, /disabled/);
    });

    // ─── Bloco 3: ofertas ───
    await contexto.test('ofertas vazio renderiza "Nenhum anúncio no ar ainda", nunca "0 anúncios"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({ ofertas: [], fases: [faseBase({ ofertas_no_ar: 0 })] })));
        assert.match(html, /Nenhum an[úu]ncio no ar ainda/);
        assert.doesNotMatch(html, /0 an[úu]ncios/);
    });

    await contexto.test('oferta sem acervo mostra "—" em preço/vendas/visitas, nunca 0', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            ofertas: [ofertaBase({ preco: null, vendas: null, visitas: null, situacao: null, visitas_nao_avaliadas: null })],
        })));
        assert.match(html, /MLB1111/);
        assert.match(html, /—/);
        assert.doesNotMatch(html, /R\$ 0,00/);
    });

    await contexto.test('detalhe_disponivel=false renderiza "Detalhe" DESABILITADO com o detalhe_motivo (D23), nunca escondido', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            ofertas: [ofertaBase({ detalhe_disponivel: false, detalhe_motivo: 'Disponível só para empresas cadastradas no sistema' })],
            abas: { company_id: null },
        })));
        assert.match(html, /Detalhe/);
        assert.match(html, /disabled/);
        assert.match(html, /Dispon[íi]vel s[óo] para empresas cadastradas no sistema/);
    });

    await contexto.test('visitas_nao_avaliadas=true avisa que a coleta ainda não passou', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            ofertas: [ofertaBase({ visitas: null, visitas_nao_avaliadas: true })],
        })));
        assert.match(html, /ainda n[ãa]o coletad/i);
    });

    // ─── Bloco 4: histórico ───
    await contexto.test('histórico com quem nulo (linha migrada) renderiza "origem antiga", nunca "undefined"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            historico: [{ tipo: 'publicacao', quando: '2026-10-08T10:00:00Z', quem: null, detalhe: '2 anúncios criados', fase: 1 }],
        })));
        assert.match(html, /origem antiga/i);
        assert.doesNotMatch(html, /undefined/);
        assert.match(html, /2 an[úu]ncios criados/);
    });

    await contexto.test('histórico vazio renderiza "Nada registrado ainda."', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({ historico: [] })));
        assert.match(html, /Nada registrado ainda\./);
    });

    await contexto.test('histórico com os 5 tipos renderiza os 5 rótulos, sem "undefined"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            historico: [
                { tipo: 'publicacao', quando: '2026-10-08T10:00:00Z', quem: 'Fulano', detalhe: '1 anúncio criado', fase: 1 },
                { tipo: 'criativos', quando: '2026-10-07T10:00:00Z', quem: 'Ciclano', detalhe: 'Kit de criativos aprovado', fase: 1 },
                { tipo: 'ia', quando: '2026-10-06T10:00:00Z', quem: null, detalhe: 'A IA preencheu o anúncio', fase: 1 },
                { tipo: 'fase_criada', quando: '2026-10-05T10:00:00Z', quem: null, detalhe: 'Fase 2 · Kit 2', fase: 2 },
                { tipo: 'combo_vinculado', quando: '2026-10-04T10:00:00Z', quem: null, detalhe: 'Combo vinculado como Fase 3', fase: 3 },
            ],
        })));
        assert.match(html, /Publica[çc][ãa]o/);
        assert.match(html, /Criativos aprovados/);
        assert.match(html, /IA preencheu/);
        assert.match(html, /Fase criada/);
        assert.match(html, /Combo vinculado/);
        assert.doesNotMatch(html, /undefined/);
    });

    // ─── Bloco 5: criativos ───
    await contexto.test('criativos vazio não renderiza miniatura nenhuma e não lança', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({ criativos: [] })));
        });
        assert.doesNotMatch(html, /<img/);
        assert.match(html, /Nenhum criativo aprovado ainda\./);
    });

    await contexto.test('criativos com miniaturas renderiza as imagens pela url do servidor', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            criativos: [{
                fase: 1, produto_id: 10, rotulo: '1 unidade',
                miniaturas: [{ indice: 1, url: '/criativos/kit/9/slots/1/imagem' }],
            }],
        })));
        assert.match(html, /\/criativos\/kit\/9\/slots\/1\/imagem/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    // ─── Bloco 6: mapeamento ───
    await contexto.test('mapeamento.vazio=true renderiza o bloco âmbar "não informado" com TODOS os rótulos', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            mapeamento: {
                vazio: true,
                medidas: { comprimento: null, largura: null, altura: null, unidade: 'cm' },
                peso: null, material: null, ean: null,
            },
        })));
        assert.match(html, /n[ãa]o informado/i);
        assert.match(html, /amber/);
        assert.match(html, /Medidas/);
        assert.match(html, /Peso/);
        assert.match(html, /Material/);
        assert.match(html, /EAN/);
    });

    await contexto.test('mapeamento preenchido mostra medidas, peso, material e EAN', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase()));
        assert.match(html, /60/);
        assert.match(html, /12,5|12\.5/);
        assert.match(html, /Couro sint[ée]tico/);
        assert.match(html, /7896553367645/);
    });

    // ─── Dado adverso: a lição da tela preta de 07/10 ───
    await contexto.test('produto.nome chegando como OBJETO não derruba a tela (textoSeguro)', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
                produto: produtoBase({ nome: {}, sku: { foo: 'bar' }, categoria: { x: 1 }, estoque_total: 'sete' }),
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('oferta com todos os campos em formato inesperado nunca lança', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
                ofertas: [{
                    fase: 'um', produto_id: null, listing_type_id: 42, tipo_rotulo: { a: 1 },
                    titulo: { foo: 'bar' }, ml_item_id: { x: 1 }, preco: 'cem', vendas: 'quatro',
                    visitas: {}, situacao: [], visitas_nao_avaliadas: 'talvez',
                    detalhe_disponivel: 'sim', detalhe_motivo: {},
                }],
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('fase com estado/rotulo em formato inesperado nunca lança', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
                fases: [{ produto_id: {}, fase: 'x', rotulo: {}, sku: [], estado: 'nao-e-objeto', estado_fase: 99, ofertas_no_ar: 'duas' }],
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('histórico com item em formato inesperado nunca lança', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
                historico: [{ tipo: 99, quando: 12345, quem: { nome: 'x' }, detalhe: { foo: 'bar' }, fase: 'um' }],
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('criativos/mapeamento em formato inesperado (string, número) nunca lançam', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
                criativos: 'nao-e-array',
                mapeamento: 42,
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.match(html, /n[ãa]o informado/i);
    });

    await contexto.test('base_excluido=true avisa em âmbar que o produto base do kit foi excluído', () => {
        const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({
            produto: produtoBase({ base_excluido: true }),
        })));
        assert.match(html, /base deste kit foi exclu[íi]do/i);
    });

    await contexto.test('todas as props ausentes (undefined) — nunca lança, usa os defaults', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelDoProduto, {}));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.match(html, /Nenhum an[úu]ncio no ar ainda/);
        assert.match(html, /Nada registrado ainda\./);
        assert.match(html, /N[ãa]o iniciada/);
    });

    await contexto.test('produto=null e fases=null — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelDoProduto, propsBase({ produto: null, fases: null, proxima_fase: null })));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });
});
