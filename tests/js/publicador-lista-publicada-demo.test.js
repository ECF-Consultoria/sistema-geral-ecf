import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Demo 23/10/2026 — a lista final depois de publicar (teste no navegador, #459).
//
// N17: cada anúncio diz o frete com que saiu, inclusive "a combinar" (aviso 4057).
// N23: a variação mostra a cor de verdade, não "Único".
// H21: anúncio que o Mercado Livre pausou ou pôs em revisão não fica "Publicado".
// O servidor está em `SituacaoDoPublicadoTest`.
// ═══════════════════════════════════════════════════════════════════════════

const PUBLICAR = 'resources/js/Components/Publicador/Mesa/Publicar.jsx';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

test('N17 — o frete com que o anúncio saiu aparece na hora; "a combinar" em âmbar', () => {
    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /\{i\.envio && <span className=\{i\.envio\.a_combinar \? 'text-amber-300' : 'text-white\/45'\} data-envio-do-anuncio=\{i\.envio\.modo\}>· \{i\.envio\.texto\}<\/span>\}/);
    // A linha antiga do frete do publicado só aparece quando o envio não foi lido (itens de antes).
    assert.match(f, /\{! i\.envio && i\.frete\?\.aplicavel === false && <span/);
});

test('N23 — o nome da variação vem do servidor e "Único" não aparece', () => {
    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /const r = i\.rotulo \?\? variantes\.find\(\(v\) => v\.chave === i\.variante_chave\)\?\.rotulo \?\? null;/);
    assert.match(f, /return r && r !== 'Único' \? r : null;/);
    assert.doesNotMatch(f, /\?\.rotulo \?\? '—'/, 'nem o travessão de antes');
});

test('H21 — o que o Mercado Livre segurou aparece no item e tira o "Publicado" do topo', () => {
    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /PUBLICADO_RETIDO: 'Criado no Mercado Livre, mas nem tudo está no ar'/);
    assert.match(f, /\{i\.situacao_ml\.texto\}\{i\.situacao_ml\.motivo \? `: \$\{i\.situacao_ml\.motivo\}` : ''\}/);
    assert.match(f, /\? \{ tom: 'aviso', texto: 'Criado no Mercado Livre, mas nem tudo está no ar: veja abaixo' \}/);
});

test('H21 — depois de publicar, a tela relê só a publicação por alguns minutos', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/usePublicador.js');
    assert.match(f, /const RELER_DEPOIS_DE_PUBLICAR = 8 \* 60 \* 1000;/);
    assert.match(f, /setEstado\(\(e\) => \(e && e\.publicacao\?\.id === data\.publicacao\.id \? \{ \.\.\.e, publicacao: data\.publicacao \} : e\)\)/);
});

test('A1 — cada anúncio diz o que aconteceu com a promoção automática (o ML recusa a do anúncio novo)', () => {
    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /const COR_PROMOCAO = \{ ok: 'text-emerald-400', aviso: 'text-amber-300', neutro: 'text-white\/45' \};/);
    assert.match(f, /\{i\.ml_item_id && i\.promocao && \(/);
    assert.match(f, /data-promocao-do-anuncio=\{i\.promocao\.status\}>\s*\{i\.promocao\.texto\}\./);
});

test('A1 — a Prévia abre SEM a promoção: a caixa vem desmarcada', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaPrevia.jsx');
    assert.match(f, /const \[comPromocao, setComPromocao\] = useState\(false\);/);
    const a = lerSemComentarios('resources/js/Components/Publicador/Mesa/AnuncioNoMl.jsx');
    assert.match(a, /comPromocao = false,/);
});

// ─── "Publica na conta …": o apelido no ML, não o nome cadastrado (pós-demo, 22/10) ────────────

const STUB_INERTIA = path.join(__dirname, `.lista-publicada-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montarPublicar() {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, PUBLICAR)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.lista-publicada-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('"Publica na conta" usa o apelido da conta no ML, com o nome cadastrado de reserva, e mantém o id do ML', async () => {
    const { contaQuePublica } = await montarPublicar();
    const empresa = { nome: 'Dev 02 Testes API', conta_nome: 'Dev 02 Testes API', conta_ml_id: '1555596317' };
    const pub = (conta) => ({ m: { estado: { conta } } });

    assert.deepEqual(contaQuePublica(pub({ nickname: 'MGSTOREL' }), empresa), { nome: 'MGSTOREL', mlId: '1555596317' });
    // Conta lida antes de 22/10 (sem apelido) ou apelido em formato estranho: o nome cadastrado.
    assert.deepEqual(contaQuePublica(pub({ nickname: null }), empresa), { nome: 'Dev 02 Testes API', mlId: '1555596317' });
    assert.equal(contaQuePublica(pub({ nickname: { x: 1 } }), empresa).nome, 'Dev 02 Testes API');
    assert.equal(contaQuePublica(pub({ nickname: '  ' }), { nome: 'Loja X' }).nome, 'Loja X');
    assert.equal(contaQuePublica({ m: {} }, { nome: 'Loja X', conta_ml_id: 123 }).mlId, '123');
    assert.deepEqual(contaQuePublica({}, {}), { nome: null, mlId: null }, 'sem nada, não diz nada');

    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /const conta = contaQuePublica\(pub, empresa\);/);
    assert.match(f, /\{pub\.liberada && conta\.nome && <p className="text-\[13px\] text-white\/45" data-conta-que-publica>Publica na conta \{conta\.nome\}\{conta\.mlId \? ` · ML \$\{conta\.mlId\}` : ''\}\.<\/p>\}/);
});
