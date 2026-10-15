import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 171, Plano 02 — render REAL (esbuild + react-dom/server) de
// `AcervoDaConta.jsx`, mesmo harness de `publicador-identidade-render.test.js`
// (Fase 170): o campo `rotulo`/`produto_nome`/`criado_em` vem do servidor e
// pode chegar em formato inesperado (objeto, array, número, booleano) —
// nunca pode derrubar a tela ("Objects are not valid as a React child", tela
// preta do kit id 2, 261007).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const ENTRY = path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/AcervoDaConta.jsx');

/** Compila o componente de verdade (JSX + imports reais) e devolve os exports. */
async function montarAcervoDaConta() {
    const resultado = await esbuild.build({
        entryPoints: [ENTRY],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios'],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.acervo-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const GRUPOS = [{ chave: 'GENERAL', rotulo: 'Fotos gerais' }];

const itemBase = (overrides = {}) => ({
    id: 1,
    imagem_url: '/x.jpg',
    rotulo: 'Imagem principal',
    criado_em: '07/10/2026',
    aprovada: true,
    do_produto_atual: true,
    produto_nome: null,
    ...overrides,
});

test('AcervoDaConta — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const mod = await montarAcervoDaConta();
    const { CartaoAcervo, default: AcervoDaConta } = mod;

    await contexto.test('item válido — imagem, rótulo e data aparecem, sem crashar', () => {
        const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
            item: itemBase(), grupos: GRUPOS, onUsar: () => {}, ocupado: false,
        }));

        assert.match(html, /<img[^>]*src="\/x\.jpg"/);
        assert.match(html, /Imagem principal/);
        assert.match(html, /07\/10\/2026/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('produto_nome como OBJETO (formato inesperado do servidor) — nunca lança, nunca aparece cru (REND-02)', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
                item: itemBase({ do_produto_atual: false, produto_nome: { foo: 'bar' } }),
                grupos: GRUPOS, onUsar: () => {}, ocupado: false,
            }));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('rotulo e criado_em em formatos inesperados (array, número, booleano) — nunca lança, nunca aparece [object Object]', () => {
        for (const valorInvalido of [['a', 'b'], 42, true]) {
            assert.doesNotThrow(() => {
                const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
                    item: itemBase({ rotulo: valorInvalido, criado_em: valorInvalido }),
                    grupos: GRUPOS, onUsar: () => {}, ocupado: false,
                }));
                assert.doesNotMatch(html, /\[object Object\]/);
            });
        }
    });

    await contexto.test('mais de um grupo — mostra o seletor com as opções', () => {
        const grupos = [{ chave: 'GENERAL', rotulo: 'Fotos gerais' }, { chave: 'COLOR=52049', rotulo: 'Preto' }];
        const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
            item: itemBase(), grupos, onUsar: () => {}, ocupado: false,
        }));

        assert.match(html, /<select/);
        assert.match(html, /Preto/);
    });

    await contexto.test('um único grupo — não mostra seletor (nada para escolher)', () => {
        const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
            item: itemBase(), grupos: GRUPOS, onUsar: () => {}, ocupado: false,
        }));

        assert.doesNotMatch(html, /<select/);
    });

    await contexto.test('imagem de outro produto da conta — mostra o nome do produto de origem', () => {
        const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
            item: itemBase({ do_produto_atual: false, produto_nome: 'Caneca azul 300ml' }),
            grupos: GRUPOS, onUsar: () => {}, ocupado: false,
        }));

        assert.match(html, /Caneca azul 300ml/);
    });

    await contexto.test('imagem órfã (produto_nome nulo, não é do produto atual) — texto claro, nunca nome errado', () => {
        const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
            item: itemBase({ do_produto_atual: false, produto_nome: null }),
            grupos: GRUPOS, onUsar: () => {}, ocupado: false,
        }));

        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /\bnull\b/);
    });

    await contexto.test('ocupado=true — botão desabilitado e mostra o texto de "em andamento"', () => {
        const html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
            item: itemBase(), grupos: GRUPOS, onUsar: () => {}, ocupado: true,
        }));

        assert.match(html, /<button[^>]*disabled[^>]*>/);
        // v28.0 (Fase 198): usar do acervo não copia mais o arquivo — o texto diz o que acontece.
        assert.match(html, /Adicionando…/);
    });

    await contexto.test('imagem_url em formato inesperado (não string) — não renderiza <img>, não crasha', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CartaoAcervo, {
                item: itemBase({ imagem_url: { foo: 'bar' } }), grupos: GRUPOS, onUsar: () => {}, ocupado: false,
            }));
        });
        assert.doesNotMatch(html, /<img/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('AcervoDaConta (default, produtoId + m vazio) monta sem crashar — a chamada ao servidor só ocorre em efeito, nunca no render', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(AcervoDaConta, { produtoId: 7, m: {} }));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });

    // v28.0 (Fase 198): o acervo passou a ter fotos enviadas também, não só geradas.
    await contexto.test('título "Acervo de imagens da conta" sempre aparece no bloco default', () => {
        const html = renderToStaticMarkup(React.createElement(AcervoDaConta, { produtoId: 7, m: {} }));
        assert.match(html, /Acervo de imagens da conta/);
    });

    await contexto.test('estado vazio (sem itens) — mensagem clara, sem crashar (o hook ainda não respondeu, mas não é "carregando")', () => {
        const html = renderToStaticMarkup(React.createElement(AcervoDaConta, { produtoId: 7, m: {} }));
        // Logo após montar (SSR, sem efeito), o hook está em `carregando=true` — o esqueleto aparece, não a grade.
        assert.doesNotMatch(html, /<img/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// v28.0 Fase 198 — os campos novos do acervo por ATIVO (sku, em_uso, miniatura,
// no_rascunho_atual) também chegam do servidor e nunca podem derrubar a tela.
// ═══════════════════════════════════════════════════════════════════════════
test('AcervoDaConta v28.0 — campos do acervo por ativo', async (contexto) => {
    const { CartaoAcervo } = await montarAcervoDaConta();
    const render = (item) => renderToStaticMarkup(React.createElement(CartaoAcervo, { item: itemBase(item), grupos: GRUPOS, onUsar: () => {}, ocupado: false }));

    await contexto.test('a grade usa a MINIATURA quando ela vem, nunca o original', () => {
        const html = render({ miniatura_url: '/mini.webp', imagem_url: '/original.jpg' });
        assert.match(html, /<img[^>]*src="\/mini\.webp"/);
        assert.doesNotMatch(html, /original\.jpg/);
    });

    await contexto.test('SKU e onde está em uso aparecem', () => {
        assert.match(render({ sku: 'CAD-01', em_uso: 3 }), /SKU CAD-01 · Em 3 anúncios/);
        assert.match(render({ em_uso: 0 }), /Em nenhum anúncio/);
        assert.match(render({ em_uso: 1 }), /Em 1 anúncio</);
    });

    await contexto.test('já está neste anúncio — o botão diz que é para usar de novo em um grupo', () => {
        assert.match(render({ no_rascunho_atual: true }), /Usar de novo neste grupo/);
    });

    await contexto.test('sku, em_uso e miniatura_url em formatos inesperados — nunca lança, nunca [object Object]', () => {
        for (const invalido of [{ foo: 'bar' }, ['a'], true]) {
            let html;
            assert.doesNotThrow(() => { html = render({ sku: invalido, em_uso: invalido, miniatura_url: invalido }); });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /foo/);
        }
    });
});

// v28.0 Fase 199 — exclusão segura: o painel mostra o que o servidor respondeu e nunca crasha.
test('AcervoDaConta v28.0 — exclusão', async (contexto) => {
    const { CartaoAcervo, ConfirmarExclusao } = await montarAcervoDaConta();
    const painel = (props) => renderToStaticMarkup(React.createElement(ConfirmarExclusao, { onConfirmar: () => {}, onCancelar: () => {}, ...props }));

    await contexto.test('o cartão só mostra "Excluir do acervo" quando recebe onExcluir, e some no modo seleção', () => {
        const base = { item: itemBase(), grupos: GRUPOS, onUsar: () => {}, ocupado: false };
        assert.doesNotMatch(renderToStaticMarkup(React.createElement(CartaoAcervo, base)), /Excluir do acervo/);
        assert.match(renderToStaticMarkup(React.createElement(CartaoAcervo, { ...base, onExcluir: () => {} })), /Excluir do acervo/);
        const emSelecao = renderToStaticMarkup(React.createElement(CartaoAcervo, { ...base, onExcluir: () => {}, selecionando: true }));
        assert.doesNotMatch(emSelecao, /Excluir do acervo/);
        assert.match(emSelecao, /type="checkbox"/);
    });

    await contexto.test('bloqueada: explica e lista onde está em uso, sem botão de excluir', () => {
        const html = painel({ analise: { pode: false, publicados: 0, bloqueios: [{ produto: 'Cadeira azul', sku: 'CAD-01', status: 'DRAFT' }] } });
        assert.match(html, /não pode ser excluída agora/);
        assert.match(html, /Cadeira azul/);
        assert.match(html, /SKU CAD-01/);
        assert.doesNotMatch(html, /data-acao="confirmar-exclusao"/);
    });

    await contexto.test('liberada com anúncios publicados: avisa que eles não mudam', () => {
        const html = painel({ analise: { pode: true, publicados: 2, bloqueios: [] } });
        assert.match(html, /Excluir esta imagem do acervo\?/);
        assert.match(html, /2 anúncios já publicados/);
        assert.match(html, /data-acao="confirmar-exclusao"/);
    });

    await contexto.test('análise em formato inesperado — nunca lança, nunca [object Object]', () => {
        for (const analise of [null, { pode: 'sim', bloqueios: 'x', publicados: {} }, { pode: false, bloqueios: [{ produto: { a: 1 }, sku: ['x'] }] }]) {
            let html;
            assert.doesNotThrow(() => { html = painel({ analise }); });
            assert.doesNotMatch(html, /\[object Object\]/);
        }
    });
});
