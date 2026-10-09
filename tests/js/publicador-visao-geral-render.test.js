import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 173, Plano 06 — render REAL (esbuild + react-dom/server) de
// `PainelVisaoGeral.jsx` (os 7 blocos da Visão geral do Publicador), mesmo
// harness de `publicador-barra-abas-render.test.js` (Fase 173, plano 03):
// TODO campo chega do servidor (`PainelVisaoGeralService`) e pode chegar em
// formato inesperado — nunca pode derrubar a tela ("Objects are not valid
// as a React child", tela preta de 07/10). Esta página expõe dezenas de
// campos de uma vez, então o gate cobre pelo menos: lista vazia, campo
// nulo, campo em formato inesperado e conta sem Company (D23).
//
// `PainelVisaoGeral.jsx` fica em `Components/Mlb/Publicador/` (não dentro
// de `Pages/.../VisaoGeral.jsx`) de propósito — a página soma `AppLayout`
// em volta, e `AppLayout` arrasta sino de notificações/tema/Modo TV, uma
// árvore pesada demais só pra testar os blocos da Visão geral. Decisão
// documentada na SUMMARY da plan 06.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const ENTRY = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx');

// Stub de route() global — mesmo truque de `publicador-barra-abas-render.test.js`.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — substitui por
// stub inline (mesmo tratamento de `publicador-barra-abas-render.test.js`).
const STUB_INERTIA = path.join(__dirname, `.visao-geral-inertia-stub-${process.pid}.mjs`);
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
async function montarPainelVisaoGeral() {
    const resultado = await esbuild.build({
        entryPoints: [ENTRY],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `PainelVisaoGeral.jsx` importa `textoSeguro` de `BarraDaConta.jsx` (plan 03)
        // — isso arrasta o módulo inteiro (inclusive `@radix-ui/react-popover` via o
        // Trigger do "Trocar empresa" e `axios` via `SeletorEmpresaBusca.jsx`), mesmo
        // sem nunca renderizar `<BarraDaConta>` aqui. Mesmo `external` do molde.
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });

    const outfile = path.join(__dirname, `.visao-geral-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const empresaBase = (overrides = {}) => ({
    chave: 'company-459',
    tipo: 'company',
    id: 459,
    nome: 'Kive Shop Eletrônicos',
    identificador: 'company-459',
    programa: 'polos',
    programa_rotulo: 'Polos',
    company_id: 459,
    token: 'ativo',
    link_reconexao: null,
    portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-08T10:00:00Z' },
    conta_nome: null,
    conta_ml_id: null,
    ...overrides,
});

const indicadoresBase = (overrides = {}) => ({
    no_ar: 10,
    com_venda: 4,
    sem_oferta: 2,
    publicados_30d: 6,
    publicados_30d_pessoas: 2,
    acervo_disponivel: true,
    nunca_coletado: false,
    ...overrides,
});

const propsBase = (overrides = {}) => ({
    empresa: empresaBase(),
    liberada: true,
    indicadores: indicadoresBase(),
    oQueFazerAgora: [],
    situacaoProdutos: {
        rascunho: { numero: 1, rotulo: 'Rascunho' },
        conferidos: { numero: 2, rotulo: 'Conferidos' },
        publicados: { numero: 3, rotulo: 'Publicados' },
        com_problema: { numero: 0, rotulo: 'Com problema' },
    },
    ultimasPublicacoes: { disponivel: true, itens: [] },
    integracoes: {
        mercado_livre: { token: 'ativo' },
        publicacao_liberada: true,
        alavancas_liberada: false,
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-08T10:00:00Z' },
        erp: { valor: 'Bling', rotulo: 'Bling' },
    },
    identidadeResumo: { tem_identidade: false, texto_resumo: null },
    quemPublicou: { equipe: [], cliente: { quantidade: 0 }, origem_antiga: { quantidade: 0 } },
    abas: { company_id: 459 },
    ...overrides,
});

test('PainelVisaoGeral — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const { default: PainelVisaoGeral } = await montarPainelVisaoGeral();

    await contexto.test('props completas e válidas — renderiza todos os 7 blocos sem crashar', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase()));
        });
        assert.match(html, /No ar/);
        assert.match(html, /Com venda/);
        assert.match(html, /Publicados nos últimos 30 dias/);
        assert.match(html, /Sem oferta/);
        assert.match(html, /O que fazer agora/);
        assert.match(html, /Situação dos produtos/);
        assert.match(html, /Últimas publicações/);
        assert.match(html, /Integrações/);
        assert.match(html, /Identidade visual/);
        assert.match(html, /Quem publicou/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    // ─── indicadores.no_ar === null (nunca coletado) — "—" e "Atualizar agora", nunca "0" ───
    await contexto.test('nunca_coletado=true — "No ar"/"Com venda" mostram "—" e botão "Atualizar agora", nunca "0"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            indicadores: indicadoresBase({ no_ar: null, com_venda: null, nunca_coletado: true }),
        })));
        assert.match(html, /Atualizar agora/);
        assert.doesNotMatch(html, /\[object Object\]/);
        // O "—" aparece pelo menos duas vezes (No ar + Com venda); nunca "0" no lugar.
        const cartaoNoAr = html.match(/No ar<\/p>[\s\S]*?<\/p>/);
        assert.ok(cartaoNoAr, 'cartão "No ar" não encontrado');
        assert.match(cartaoNoAr[0], /—/);
    });

    await contexto.test('D23 (sem Company, acervo_disponivel=false) — "—" com explicação, SEM botão "Atualizar agora"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            indicadores: indicadoresBase({ no_ar: null, com_venda: null, acervo_disponivel: false, nunca_coletado: false }),
            abas: { company_id: null },
        })));
        assert.match(html, /Dispon[íi]vel s[óo] para empresas cadastradas no sistema/);
        assert.doesNotMatch(html, /Atualizar agora/);
    });

    // ─── oQueFazerAgora === [] — "Nada pendente nesta conta." ───
    await contexto.test('oQueFazerAgora vazio — "Nada pendente nesta conta."', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ oQueFazerAgora: [] })));
        assert.match(html, /Nada pendente nesta conta\./);
    });

    // ─── 1 linha de reconexão — SÓ essa linha, com botão pro link de reconexão ───
    await contexto.test('oQueFazerAgora com 1 linha de reconexão — renderiza só essa linha, com link de reconexão', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            oQueFazerAgora: [
                { texto: 'Conta precisa de reconexão', numero: null, destino: { acao: 'reconectar', url: 'https://ml.example/reconectar' } },
            ],
        })));
        assert.match(html, /Conta precisa de reconex[ãa]o/);
        assert.match(html, /Copiar link de reconex[ãa]o/);
        assert.doesNotMatch(html, /Nada pendente nesta conta\./);
    });

    await contexto.test('oQueFazerAgora com linha de rota — botão "Ver" navega pro destino', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            oQueFazerAgora: [
                { texto: 'Produtos com problema na publicação', numero: 3, destino: { rota: 'mlb.anuncios.publicador.produtos', params: { conta: 'company-459', filtro: 'com_problema' } } },
            ],
        })));
        assert.match(html, /Produtos com problema na publica[çc][ãa]o/);
        assert.match(html, />3<\/span>/);
        assert.match(html, />Ver<\/button>/);
    });

    await contexto.test('oQueFazerAgora com linha de sincronizar — reusa BotaoSincronizarPortal', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            oQueFazerAgora: [
                { texto: 'Ofertas novas no Portal', numero: 5, destino: { acao: 'sincronizar' } },
            ],
        })));
        assert.match(html, /Sincronizar do Portal/);
    });

    await contexto.test('oQueFazerAgora — item com formato inesperado (destino ausente, numero string) nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
                oQueFazerAgora: [{ texto: { foo: 'bar' }, numero: 'três', destino: null }],
            })));
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /foo/);
        });
    });

    // ─── integracoes.erp.valor como objeto — fallback seguro, nunca derruba a tela ───
    await contexto.test('integracoes.erp.valor em formato inesperado (objeto) — fallback seguro, sem crash', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
                integracoes: { mercado_livre: { token: 'ativo' }, publicacao_liberada: true, alavancas_liberada: false, portal: { situacao: 'sem_portal' }, erp: { valor: { foo: 'bar' }, rotulo: 'Não informado' } },
            })));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
        assert.match(html, /N[ãa]o informado/);
    });

    // ─── ultimasPublicacoes.disponivel === false — bloco desabilitado, nunca "nenhuma publicação" ───
    await contexto.test('ultimasPublicacoes.disponivel=false — texto explicativo D23, nunca "nenhuma publicação"', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            ultimasPublicacoes: { disponivel: false, itens: [] },
        })));
        assert.match(html, /Dispon[íi]vel s[óo] para empresas cadastradas no sistema/);
        assert.doesNotMatch(html, /Ver todas/);
    });

    await contexto.test('ultimasPublicacoes.disponivel=true mas itens=[] — "Nenhuma publicação ainda.", não o texto D23', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            ultimasPublicacoes: { disponivel: true, itens: [] },
        })));
        assert.match(html, /Nenhuma publica[çc][ãa]o ainda\./);
    });

    await contexto.test('ultimasPublicacoes com item válido — título, MLB, quem, vendas e situação aparecem', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            ultimasPublicacoes: {
                disponivel: true,
                itens: [{ titulo: 'Caneca azul 300ml', ml_item_id: 'MLB123456', tipo: 'classico', quem: { tipo: 'equipe', nome: 'Fulano' }, quando: '2026-10-08T10:00:00Z', vendas: 7, situacao: 'PUBLISHED' }],
            },
        })));
        assert.match(html, /Caneca azul 300ml/);
        assert.match(html, /MLB123456/);
        assert.match(html, /Fulano/);
        assert.match(html, />7<\/span>/);
        assert.match(html, /PUBLISHED/);
    });

    await contexto.test('item de ultimasPublicacoes com quem/vendas/quando em formato inesperado — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
                ultimasPublicacoes: {
                    disponivel: true,
                    itens: [{ titulo: { foo: 'bar' }, ml_item_id: { x: 1 }, tipo: 42, quem: 'string-inesperada', quando: 12345, vendas: 'sete', situacao: null }],
                },
            })));
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /foo/);
        });
    });

    // ─── identidadeResumo ───
    await contexto.test('identidadeResumo.tem_identidade=false — texto padrão de identidade não cadastrada', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            identidadeResumo: { tem_identidade: false, texto_resumo: null },
        })));
        assert.match(html, /N[ãa]o cadastrada\. Os criativos s[ãa]o gerados sem identidade\./);
    });

    await contexto.test('identidadeResumo.tem_identidade=true com 3 linhas — mostra as 3 linhas', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            identidadeResumo: { tem_identidade: true, texto_resumo: ['Linha 1', 'Linha 2', 'Linha 3'] },
        })));
        assert.match(html, /Linha 1/);
        assert.match(html, /Linha 2/);
        assert.match(html, /Linha 3/);
    });

    await contexto.test('identidadeResumo.texto_resumo em formato inesperado (string, não array) — cai no texto padrão, sem crash', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
                identidadeResumo: { tem_identidade: true, texto_resumo: 'não é array' },
            })));
            assert.match(html, /N[ãa]o cadastrada/);
        });
    });

    // ─── quemPublicou ───
    await contexto.test('quemPublicou com equipe, cliente e origem_antiga — todos aparecem; responsável marcado', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            quemPublicou: {
                equipe: [{ nome: 'Fulano', quantidade: 5, responsavel: true }, { nome: 'Ciclano', quantidade: 2, responsavel: false }],
                cliente: { quantidade: 1 },
                origem_antiga: { quantidade: 3 },
            },
        })));
        assert.match(html, /Fulano/);
        assert.match(html, /respons[áa]vel/);
        assert.match(html, /Ciclano/);
        assert.match(html, />Cliente<\/span>/);
        assert.match(html, /Origem antiga/);
    });

    await contexto.test('quemPublicou totalmente vazio — mensagem clara, sem crash', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            quemPublicou: { equipe: [], cliente: { quantidade: 0 }, origem_antiga: { quantidade: 0 } },
        })));
        assert.match(html, /Nenhuma publica[çc][ãa]o nos [úu]ltimos 30 dias\./);
    });

    // ─── situacaoProdutos ───
    await contexto.test('situacaoProdutos com as 4 chaves — todos os rótulos e números aparecem', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase()));
        assert.match(html, /Rascunho/);
        assert.match(html, /Conferidos/);
        assert.match(html, /Publicados/);
        assert.match(html, /Com problema/);
    });

    await contexto.test('situacaoProdutos em formato inesperado (array, não objeto) — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ situacaoProdutos: ['a', 'b'] })));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    // ─── Shape totalmente adverso — nunca lança ───
    await contexto.test('todas as props ausentes (undefined) — nunca lança, usa os defaults', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, {}));
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.match(html, /Nada pendente nesta conta\./);
        });
    });

    await contexto.test('empresa=null, indicadores=null — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ empresa: null, indicadores: null })));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    // ═══════════════════════════════════════════════════════════════════════
    // Fase 175, plano 10 (§7) — "Produtos por fase" e a coluna Fase.
    //
    // ⚠️ O bloco novo vai ABAIXO de "Situação dos produtos", não no lugar
    // dele: a §7 da ETAPA-3 manda substituir, mas a regra inviolável "nada
    // que existe pode sumir" vence (divergência já registrada pelo 175-08).
    // ═══════════════════════════════════════════════════════════════════════

    const porFaseBase = (overrides = {}) => ({
        sem_oferta: { numero: 4, rotulo: 'Sem oferta' },
        fase1_publicada: { numero: 3, rotulo: 'Fase 1 publicada' },
        fase2_preparacao: { numero: 2, rotulo: 'Fase 2 em preparação' },
        fase2_publicada: { numero: 1, rotulo: 'Fase 2 publicada' },
        fase3_mais: { numero: 0, rotulo: 'Fase 3+' },
        ...overrides,
    });

    await contexto.test('os DOIS blocos convivem: "Situação dos produtos" continua e "Produtos por fase" nasce abaixo', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ produtosPorFase: porFaseBase() })));
        });
        // O bloco de hoje, com os 4 rótulos dele, intacto.
        assert.match(html, /Situação dos produtos/);
        for (const rotulo of ['Rascunho', 'Conferidos', 'Publicados', 'Com problema']) {
            assert.ok(html.includes(rotulo), `rótulo da Situação ausente: ${rotulo}`);
        }
        // O bloco novo, com os 5 rótulos em pt-BR.
        assert.match(html, /Produtos por fase/);
        for (const rotulo of ['Sem oferta', 'Fase 1 publicada', 'Fase 2 em preparação', 'Fase 2 publicada', 'Fase 3+']) {
            assert.ok(html.includes(rotulo), `rótulo da fase ausente: ${rotulo}`);
        }
        // Na ordem: o novo vem DEPOIS do antigo.
        assert.ok(html.indexOf('Situação dos produtos') < html.indexOf('Produtos por fase'));
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('cada bucket é clicável (um <button> por bucket)', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ produtosPorFase: porFaseBase() })));
        const bloco = html.slice(html.indexOf('Produtos por fase'));
        const botoes = bloco.match(/<button type="button"/g) ?? [];
        assert.ok(botoes.length >= 5, `esperado ao menos 5 botões no bloco por fase, achou ${botoes.length}`);
    });

    await contexto.test('produtosPorFase ausente (servidor antigo) — o bloco simplesmente não aparece, e nada mais muda', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase()));
        assert.doesNotMatch(html, /Produtos por fase/);
        assert.match(html, /Situação dos produtos/);
    });

    await contexto.test('produtosPorFase com TODOS os números 0 — renderiza o bloco com zeros, não um vazio enigmático', () => {
        const zerado = Object.fromEntries(
            Object.entries(porFaseBase()).map(([chave, item]) => [chave, { numero: 0, rotulo: item.rotulo }]),
        );
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ produtosPorFase: zerado })));
        assert.match(html, /Produtos por fase/);
        assert.match(html, /Fase 3\+/);
        const bloco = html.slice(html.indexOf('Produtos por fase'));
        assert.ok((bloco.match(/>0</g) ?? []).length >= 5, 'os 5 zeros têm de aparecer');
    });

    await contexto.test('produtosPorFase em formato inesperado (array, número, campo objeto) nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({ produtosPorFase: 42 })));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
                produtosPorFase: { sem_oferta: { numero: { foo: 'bar' }, rotulo: [] }, fase1_publicada: 'nao-e-objeto' },
            })));
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /foo/);
        });
    });

    await contexto.test('"Prontos para a Fase 2" continua clicável com o destino novo (?filtro=publicados&fase=so_base)', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            oQueFazerAgora: [{
                texto: 'Prontos para a Fase 2',
                numero: 2,
                destino: {
                    rota: 'mlb.anuncios.publicador.produtos',
                    params: { conta: 'company-459', filtro: 'publicados', fase: 'so_base' },
                },
            }],
        })));
        assert.match(html, /Prontos para a Fase 2/);
        assert.match(html, />Ver<\/button>/);
    });

    await contexto.test('Últimas publicações ganham a coluna Fase, com rotulo_fase', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            ultimasPublicacoes: {
                disponivel: true,
                itens: [{
                    titulo: 'Kit 2 Cadeira Executiva', ml_item_id: 'MLB999', tipo: 'classico',
                    quem: { tipo: 'equipe', nome: 'Fulano' }, quando: '2026-10-08T10:00:00Z',
                    vendas: 3, situacao: 'PUBLISHED', fase: 2, rotulo_fase: 'Kit 2',
                }],
            },
        })));
        assert.match(html, /Kit 2 Cadeira Executiva/);
        assert.match(html, />Kit 2</);
        // Nenhum campo antigo saiu da linha.
        assert.match(html, /MLB999/);
        assert.match(html, /Fulano/);
        assert.match(html, /PUBLISHED/);
    });

    await contexto.test('item de Últimas publicações sem fase mostra "—" na coluna Fase', () => {
        const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
            ultimasPublicacoes: {
                disponivel: true,
                itens: [{
                    titulo: 'Caneca azul 300ml', ml_item_id: 'MLB123456', tipo: 'classico',
                    quem: { tipo: 'equipe', nome: 'Ciclano' }, quando: '2026-10-08T10:00:00Z',
                    vendas: 7, situacao: 'PUBLISHED',
                }],
            },
        })));
        // Todos os outros campos da linha estão preenchidos: o único "—" é da fase.
        //
        // ⚠️ O recorte termina no fim da SEÇÃO "Últimas publicações" (quick
        // 261009-t02). Antes ele ia até o fim do documento e só passava por
        // acidente: bastou a coluna lateral ganhar um cartão com estado vazio
        // ("Criativos por IA" sem dado escreve "—") para a contagem virar 2.
        // Limitar à seção prova o que a frase acima diz, e nada além.
        const daLinha = html.slice(html.indexOf('Caneca azul 300ml'));
        const linha = daLinha.slice(0, daLinha.indexOf('</section>'));
        assert.equal((linha.match(/—/g) ?? []).length, 1);
    });

    await contexto.test('rotulo_fase chegando como OBJETO não derruba a tela', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(PainelVisaoGeral, propsBase({
                ultimasPublicacoes: {
                    disponivel: true,
                    itens: [{ titulo: 'X', ml_item_id: 'MLB1', tipo: 'classico', quem: null, quando: null, vendas: 1, situacao: 'active', fase: {}, rotulo_fase: { foo: 'bar' } }],
                },
            })));
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /foo/);
        });
    });
});

test('PainelVisaoGeral — destinoDaFase: cada bucket vira um par (filtro, fase) que a lista de Produtos entende', async () => {
    const { destinoDaFase, itensPorFase } = await montarPainelVisaoGeral();
    assert.equal(typeof destinoDaFase, 'function');

    // Os dois filtros da lista: situação (chips de hoje) e fase (Fase 175).
    assert.deepEqual(destinoDaFase('fase1_publicada'), { filtro: 'publicados', fase: 'so_base' });
    assert.deepEqual(destinoDaFase('fase2_preparacao'), { filtro: 'rascunho', fase: 'so_kits' });
    assert.deepEqual(destinoDaFase('fase2_publicada'), { filtro: 'publicados', fase: 'so_kits' });
    assert.deepEqual(destinoDaFase('fase3_mais'), { filtro: 'todos', fase: 'so_kits' });
    // "Sem oferta" (= sem anúncio no ar) não é chip da lista: abre a lista inteira.
    assert.deepEqual(destinoDaFase('sem_oferta'), { filtro: 'todos', fase: 'todas' });
    assert.deepEqual(destinoDaFase('inventado'), { filtro: 'todos', fase: 'todas' });

    // A normalização aceita o mapa que o servidor manda HOJE...
    assert.equal(typeof itensPorFase, 'function');
    const doMapa = itensPorFase({
        fase3_mais: { numero: 1, rotulo: 'Fase 3+' },
        sem_oferta: { numero: 2, rotulo: 'Sem oferta' },
    });
    // ...e devolve na ordem do contrato, não na ordem em que as chaves chegaram.
    assert.deepEqual(doMapa.map((i) => i.chave), ['sem_oferta', 'fase3_mais']);
    assert.deepEqual(doMapa.map((i) => i.numero), [2, 1]);
    // ...e também a LISTA que o PLAN descrevia (divergência de contrato do 175-08).
    const daLista = itensPorFase([{ chave: 'fase2_publicada', numero: 5, rotulo: 'Fase 2 publicada' }]);
    assert.deepEqual(daLista, [{ chave: 'fase2_publicada', numero: 5, rotulo: 'Fase 2 publicada' }]);
    // Ausente ou inválido: lista vazia (o bloco não aparece).
    assert.deepEqual(itensPorFase(undefined), []);
    assert.deepEqual(itensPorFase(42), []);
});
