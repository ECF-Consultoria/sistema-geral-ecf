import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { digitoEan13, eanValido, gerarEan13, tomDaCor } from '../../resources/js/Components/Publicador/ferramentas.js';

// ═══════════════════════════════════════════════════════════════════════
// N9 — "abrir o editor grava" (medido no navegador em 21 e 22/10/2026).
//
// POR QUE EXISTE: abrir o editor de um produto pela 1ª vez disparava "Salvando…"
// e PUT /produtos/{id}/variantes + PUT /produtos/{id} sem a pessoa tocar em nada:
// os efeitos da tela aplicavam padrões (EAN gerado, Cor principal, fotos por
// variação, frete grátis obrigatório). A conferência virava "Editado: confira de
// novo", a revisão subia e o preparo da IA se adiava 5 min. O conserto foi
// aplicar os MESMOS padrões no servidor antes da 1ª conferência
// (`PadroesDoEditor`/`PadroesDoRascunhoService`); com o valor já certo, os
// efeitos não têm o que gravar.
//
// Este arquivo prova as duas metades do lado da tela:
// 1. paridade: o JS dá os mesmos números da fixture que o PHP também confere
//    (`tests/Unit/Publicador/PadroesDoEditorTest.php`);
// 2. abrir não grava: os efeitos REAIS (`useEfeitosDasVariacoes`,
//    `useEfeitosDoEnvio`), rodados como na montagem, não chamam `mudarVar` nem
//    `mudarRasc` — os únicos caminhos para o PUT (`agendar`) — com o estado que
//    o servidor devolve depois dos padrões. E, com cada padrão faltando, chamam
//    (senão o teste passaria por não rodar efeito nenhum).
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PARIDADE = JSON.parse(fs.readFileSync(path.resolve(RAIZ, 'tests/fixtures-ml/padroes-do-editor-paridade.json'), 'utf8'));

const sequencia = (valores) => {
    let i = 0;

    return () => valores[i++ % valores.length];
};

// ─── 1. Paridade com o PHP ──────────────────────────────────────────────────

test('paridade: dígito e validação do EAN-13 são os da fixture', () => {
    for (const c of PARIDADE.digitos) assert.equal(digitoEan13(c.base), c.digito, c.base);
    for (const c of PARIDADE.codigos) assert.equal(eanValido(c.codigo), c.valido, c.codigo);
});

test('paridade: o mesmo sorteio gera o mesmo EAN (inclusive quando o 1º já existe)', () => {
    for (const c of PARIDADE.sorteios) {
        const ean = gerarEan13(new Set(c.existentes), sequencia(c.sorteio));
        assert.equal(ean, c.ean);
        assert.ok(ean.startsWith('789') && eanValido(ean));
    }
});

test('paridade: o tom de cada nome de cor é o da fixture', () => {
    for (const c of PARIDADE.cores) assert.equal(tomDaCor(c.nome, PARIDADE.tons)?.id ?? null, c.tom, `cor "${c.nome}"`);
    for (const c of PARIDADE.cores_sem_celeste) assert.equal(tomDaCor(c.nome, PARIDADE.tons_sem_celeste)?.id ?? null, c.tom, `cor "${c.nome}"`);
});

// ─── 2. Os efeitos reais, rodados como na montagem ─────────────────────────

// O `react` das duas telas vira um stand-in: cada `useEffect` roda na hora (é o que a montagem faz), `useRef` e
// `useState` guardam o valor inicial. O resto (ícones, Radix…) segue o React de verdade, e nada é desenhado.
const REACT_DA_MONTAGEM = `
export const efeitos = [];
export function useEffect(fn) { const limpar = fn(); if (typeof limpar === 'function') efeitos.push(limpar); }
export function useRef(inicial) { return { current: inicial }; }
export function useState(inicial) { return [typeof inicial === 'function' ? inicial() : inicial, () => {}]; }
export function useMemo(fn) { return fn(); }
export function useCallback(fn) { return fn; }
export function useContext() { return null; }
export function useId() { return 'id'; }
export function createContext(v) { return { Provider: () => null, Consumer: () => null, _v: v }; }
export function forwardRef(f) { return f; }
export function memo(f) { return f; }
export const Fragment = 'Fragment';
export default { useEffect, useRef, useState, useMemo, useCallback, useContext, useId, createContext, forwardRef, memo, Fragment };
`;

const ALVOS = [/Mesa[\\/]FotosEVariacoes\.jsx$/, /Mesa[\\/]EtapaCondicoes\.jsx$/];

let modulo = null;
async function efeitosDaTela() {
    if (modulo) return modulo;
    const entrada = `
        export { useEfeitosDasVariacoes } from ${JSON.stringify(path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/FotosEVariacoes.jsx'))};
        export { useEfeitosDoEnvio } from ${JSON.stringify(path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx'))};
        export { efeitos } from 'react-da-montagem';
    `;
    const resultado = await esbuild.build({
        stdin: { contents: entrada, resolveDir: RAIZ, loader: 'js' },
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        loader: { '.js': 'jsx' },
        plugins: [{
            name: 'react-da-montagem',
            setup(build) {
                build.onResolve({ filter: /^react-da-montagem$/ }, () => ({ path: 'react-da-montagem', namespace: 'montagem' }));
                // Só as duas telas sob teste usam o stand-in; o resto importa o React de verdade (externo).
                build.onResolve({ filter: /^react$/ }, (a) => (ALVOS.some((re) => re.test(a.importer))
                    ? { path: 'react-da-montagem', namespace: 'montagem' }
                    : { path: 'react', external: true }));
                // Pacote do node_modules fica externo; o `@/…` é o alias do projeto (o resolvedor padrão cuida).
                build.onResolve({ filter: /^[^./]/ }, (a) => (a.path.startsWith('@/') || path.isAbsolute(a.path) ? undefined : { path: a.path, external: true }));
                build.onLoad({ filter: /.*/, namespace: 'montagem' }, () => ({ contents: REACT_DA_MONTAGEM, loader: 'js' }));
            },
        }],
    });
    const arquivo = path.join(__dirname, `.padroes-do-editor-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    try {
        modulo = await import(pathToFileURL(arquivo).href);
    } finally {
        fs.rmSync(arquivo, { force: true });
    }

    return modulo;
}

const TONS = PARIDADE.tons;
const tom = (nome) => TONS.find((t) => t.name === nome);

/**
 * O estado que o servidor devolve DEPOIS dos padrões (`PadroesDoRascunhoService`): GTIN e Cor principal gravados
 * (sem `origem`: o atributo de variante não a guarda), envio com o frete grátis que a faixa exige.
 */
function estadoCompleto({ eixo = 'COLOR' } = {}) {
    const porCor = eixo === 'COLOR';
    const valores = porCor ? [{ id: '52028', nome: 'Azul-marinho' }, { id: null, nome: 'Grafite' }] : [{ id: null, nome: 'P' }, { id: null, nome: 'M' }];
    const chave = porCor ? 'COLOR' : 'SIZE';
    const variantes = valores.map((v, i) => ({
        chave: `${chave}:${v.nome}`, rotulo: v.nome, ativa: true, orfa: false, publicada: false,
        valores: { [chave]: v },
        estoque: 3, estoque_depositos: null,
        precos: { gold_special: 199.9, gold_pro: 229.9 }, precos_efetivos: {}, portal: {},
        atributos: {
            SELLER_SKU: { value_name: `CAD-${i}` },
            GTIN: { value_name: ['7890123456784', '7899054376109'][i] },
            ...(porCor ? { MAIN_COLOR: { value_id: String(tom(i === 0 ? 'Azul' : 'Cinza').id), value_name: i === 0 ? 'Azul' : 'Cinza' } } : {}),
        },
    }));

    return {
        rascunho: {
            id: 1, revisao: 7, status: 'DRAFT', categoria_id: 'MLB193945', condicao: 'new', descricao: 'Cadeira.',
            envio: { modo: 'me2', frete_gratis: true, retirada: false }, garantia: null,
            fotos_por_variante: ! porCor, incluir_geral: true,
        },
        eixos: [{ chave, nome: porCor ? 'Cor' : 'Tamanho', defines_picture: porCor, customizado: false, valores: valores.map((v) => ({ chave: v.nome, ...v })) }],
        variantes,
        alvos: [{ listing_type_id: 'gold_special', titulo: 'Cadeira', ativo: true }, { listing_type_id: 'gold_pro', titulo: 'Cadeira Giratória', ativo: true }],
        atributos: { BRAND: { value_name: 'ECF' } },
        schema: {
            categoria_id: 'MLB193945',
            atributos: {
                GTIN: { id: 'GTIN', secao: 'VARIANTE', valores: [] },
                MAIN_COLOR: { id: 'MAIN_COLOR', secao: 'VARIANTE', valores: TONS },
            },
        },
        conta: { modos_envio: ['me2', 'custom', 'not_specified'], erro: null },
    };
}

/** O `m` da tela, montado do estado como o `usePublicador` faz, com as escritas gravadas em vez de agendadas. */
function mesa(estado, { frete = { obrigatorio: true, conhecido: true } } = {}) {
    const escritas = [];
    const consultas = [];
    const m = {
        estado,
        schema: estado.schema,
        disabled: false,
        rasc: { atributos: estado.atributos, alvos: estado.alvos, condicao: estado.rascunho.condicao, descricao: estado.rascunho.descricao, envio: estado.rascunho.envio, garantia: estado.rascunho.garantia },
        variantes: estado.variantes,
        alvos: estado.alvos,
        frete,
        mudarRasc: (mudanca) => escritas.push({ onde: 'PUT /produtos/{id}', mudanca }),
        mudarVar: (chave, patch) => escritas.push({ onde: 'PUT /produtos/{id}/variantes', chave, patch }),
        consultarFrete: () => consultas.push('GET /frete'),
    };

    return { m, escritas, consultas };
}

async function abrir(estado, opcoes) {
    const { useEfeitosDasVariacoes, useEfeitosDoEnvio, efeitos } = await efeitosDaTela();
    const { m, escritas, consultas } = mesa(estado, opcoes);
    useEfeitosDasVariacoes(m);
    useEfeitosDoEnvio(m);
    // A consulta do frete espera 1,5 s (`ESPERA_FRETE`); a limpeza da montagem cancela o relógio.
    efeitos.splice(0).forEach((limpar) => limpar());

    return { escritas, consultas };
}

test('abrir não grava: rascunho com os padrões do servidor (cores que definem a foto) não faz PUT', async () => {
    const { escritas } = await abrir(estadoCompleto());
    assert.deepEqual(escritas, []);
});

test('abrir não grava: variação sem eixo de foto, com "fotos por variação" já ligado, não faz PUT', async () => {
    const { escritas } = await abrir(estadoCompleto({ eixo: 'SIZE' }));
    assert.deepEqual(escritas, []);
});

test('abrir não grava mesmo quando o servidor não achou tom (o nome fica sem Cor principal e a tela também não acha)', async () => {
    const e = estadoCompleto();
    e.variantes[1].valores.COLOR = { id: null, nome: 'Nogueira' };
    delete e.variantes[1].atributos.MAIN_COLOR;
    const { escritas } = await abrir(e);
    assert.deepEqual(escritas, []);
});

// O contraprova: cada padrão faltando, a tela grava (o efeito está rodando de verdade).

test('contraprova: variação sem código ganha o EAN gerado (a decisão do usuário: o EAN gerado FICA)', async () => {
    const e = estadoCompleto();
    delete e.variantes[0].atributos.GTIN;
    const { escritas } = await abrir(e);
    assert.equal(escritas.length, 1);
    assert.equal(escritas[0].onde, 'PUT /produtos/{id}/variantes');
    const gtin = escritas[0].patch({ atributos: {} }).atributos.GTIN.value_name;
    assert.ok(eanValido(gtin) && gtin.startsWith('789'), gtin);
});

test('contraprova: sem Cor principal, a tela grava a do nome da cor', async () => {
    const e = estadoCompleto();
    delete e.variantes[0].atributos.MAIN_COLOR;
    const { escritas } = await abrir(e);
    assert.equal(escritas.length, 1);
    assert.deepEqual(escritas[0].patch({ atributos: {} }).atributos.MAIN_COLOR, { value_id: String(tom('Azul').id), value_name: 'Azul', origem: 'auto' });
});

test('contraprova: sem "fotos por variação" num eixo que não define a foto, a tela liga', async () => {
    const e = estadoCompleto({ eixo: 'SIZE' });
    e.rascunho.fotos_por_variante = false;
    const { escritas } = await abrir(e);
    assert.deepEqual(escritas.map((x) => x.mudanca), [{ fotos_por_variante: true }]);
});

test('contraprova: frete grátis obrigatório desmarcado, a tela marca', async () => {
    const e = estadoCompleto();
    e.rascunho.envio = { modo: 'me2', frete_gratis: false, retirada: false };
    const { escritas } = await abrir(e);
    assert.equal(escritas.length, 1);
    assert.deepEqual(escritas[0].mudanca({ envio: e.rascunho.envio }), { envio: { modo: 'me2', frete_gratis: true, retirada: false } });
});

test('contraprova: forma de envio que a conta não tem volta para Mercado Envios', async () => {
    const e = estadoCompleto();
    e.conta.modos_envio = ['me2', 'not_specified'];
    e.rascunho.envio = { modo: 'custom', frete_gratis: false, retirada: false };
    const { escritas } = await abrir(e, { frete: null });
    assert.equal(escritas.length, 1);
    assert.deepEqual(escritas[0].mudanca({ envio: e.rascunho.envio }), { envio: { modo: 'me2', frete_gratis: false, retirada: false } });
});
