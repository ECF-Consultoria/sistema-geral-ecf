import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════════
// Fase 186, plano 09 — OS GATES TRANSVERSAIS DO LADO DO RENDER.
//
// ⚠️⚠️ A PERGUNTA DE GATE-01, respondida POR BLOCO (bloco sem resposta concreta
// é decorativo e NÃO entra). Para o arquivo inteiro:
//
//   **o que precisaria estar errado no mundo?** Que a área nova do portão
//   passasse a (a) injetar HTML cru vindo do servidor, (b) usar uma classe do
//   Tailwind que o `content` do `tailwind.config.js` NÃO varre — e então a tela
//   sai SEM COR, sem erro de build e sem nenhum teste vermelho —, ou (c) desenhar
//   um estado identificável SÓ POR COR, invisível para quem não distingue
//   vermelho de âmbar e invisível em print cinza.
//
// ⚠️⚠️ **NENHUM EXECUTOR VIU TELA RENDERIZADA.** Não há navegador neste
// ambiente. O que este arquivo mede é:
//   · **HTML** — string de `renderToStaticMarkup`, em memória, sem CSS aplicado;
//   · **CSS** — o arquivo construído em `public/build/assets/*.css`, lido pelo
//     manifest (⚠️ **pelo JSON**: o hash do Vite sai com HÍFEN).
// É a régua GATE-02 ("dois gates por elemento: CSS **e** HTML") aplicada à fase
// inteira. Estrutura e literal, nunca aparência.
//
// ⚠️ TODOS os bundles são montados AQUI NO TOPO, antes do primeiro `test()`.
// O `after()` do `node --test` dispara quando os testes registrados terminam e
// já matou um arquivo INTEIRO deste projeto sem nenhum teste falhar.
//
// ⚠️ `String.raw` em toda regex de sonda, e **borda nos dois lados** de todo
// substring: `set` e `falta` são palavras comuns em pt-BR e em código.
// ═══════════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_SELO = `${DIR_APROV}/SeloDeAndamentoInterno.jsx`;
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;

/** ⚠️ LISTA CONSTANTE, não glob: arquivo fora dela faria o gate passar por OMISSÃO. */
const ARQUIVOS_NOVOS = [REL_SELO, REL_ANDAMENTO, REL_VOCABULARIO];

const REL_MANIFEST = 'public/build/manifest.json';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Este projeto
// acumulou SEIS caminhos fantasma em quatro fases, e gate que lê caminho
// inexistente não reprova: ele mede NADA e parece verde.
for (const caminho of [DIR_APROV, ...ARQUIVOS_NOVOS, REL_MANIFEST]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — o gate mediria NADA`);
    }
}

const STUB_INERTIA = path.join(__dirname, `.pub-fech-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(
    STUB_INERTIA,
    `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`,
    'utf8',
);

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

/** Compila um módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montar(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
        },
        external: [
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(
        __dirname,
        `.pub-fech-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const MOD_SELO = await montar(REL_SELO);
const MOD_ANDAMENTO = await montar(REL_ANDAMENTO);
const MOD_VOCABULARIO = await montar(REL_VOCABULARIO);

const { ANDAMENTOS, CHAVES_DE_ANDAMENTO } = MOD_ANDAMENTO;
const { SELOS_DE_ORIGEM, ABREVIACAO_DO_BLOQUEIO, ROTULO_DO_BLOQUEIO, CRITERIOS, DEFEITOS } = MOD_VOCABULARIO;
const SeloDeAndamentoInterno = MOD_SELO.default ?? MOD_SELO.SeloDeAndamentoInterno;

/** O CSS construído, achado pelo MANIFEST (pelo JSON) e nunca por nome fixo. */
const CSS_CONSTRUIDO = (() => {
    const manifest = JSON.parse(fs.readFileSync(path.resolve(RAIZ, REL_MANIFEST), 'utf8'));
    // A entrada de CSS do bundle principal é a do `resources/css/app.css`, que o
    // Laravel Vite plugin registra como asset do `app.jsx`.
    const candidatos = new Set();
    for (const [, valor] of Object.entries(manifest)) {
        for (const css of valor.css ?? []) {
            candidatos.add(css);
        }
        if (typeof valor.file === 'string' && valor.file.endsWith('.css')) {
            candidatos.add(valor.file);
        }
    }
    let texto = '';
    for (const rel of candidatos) {
        const abs = path.resolve(RAIZ, 'public/build', rel);
        if (fs.existsSync(abs)) {
            texto += fs.readFileSync(abs, 'utf8');
        }
    }
    return texto;
})();

// ═══════════════════════════════════════════════════════════════════════════════
// (0) A SONDA NÃO ESTÁ CEGA — o preâmbulo de toda a medição abaixo.
// ═══════════════════════════════════════════════════════════════════════════════

// ⚠️ O que precisaria estar errado: o `montar()` devolver módulo vazio, o CSS
// construído sair como string vazia, ou o manifest não ter entrada de CSS. Nos
// três casos TODAS as asserções deste arquivo passariam por varrer o nada — e é
// a forma de gate vazio que esta fase mediu mais vezes.
test('fechamento: os três módulos montaram e o CSS construído foi achado pelo manifest', () => {
    assert.equal(CHAVES_DE_ANDAMENTO.length, 8, 'os 8 andamentos internos (AND-01)');
    assert.equal(Object.keys(SELOS_DE_ORIGEM).length, 3, 'os 3 selos de origem (VOC-04)');
    assert.equal(Object.keys(ABREVIACAO_DO_BLOQUEIO).length, 9, 'as 9 abreviações (`SHORT`)');
    assert.equal(Object.keys(ROTULO_DO_BLOQUEIO).length, 8, 'os 8 rótulos (`BLK`)');
    assert.equal(Object.keys(CRITERIOS).length, 7, 'os 7 critérios de fidelidade');
    assert.equal(Object.keys(DEFEITOS).length, 6, 'os 6 defeitos');
    assert.equal(typeof SeloDeAndamentoInterno, 'function', 'o selo tem de ser componente');

    assert.ok(
        CSS_CONSTRUIDO.length > 100000,
        `o CSS construído saiu com ${CSS_CONSTRUIDO.length} bytes — abaixo disso a varredura de classe mede o NADA`,
    );
});

// ═══════════════════════════════════════════════════════════════════════════════
// (e) `dangerouslySetInnerHTML` AUSENTE NA ÁREA NOVA
// ═══════════════════════════════════════════════════════════════════════════════

// ⚠️ O que precisaria estar errado: alguém injetar HTML cru numa tela que exibe
// texto vindo do servidor (motivo de reprovação de imagem, justificativa de
// exceção, nota de fonte financeira) — texto que um operador DIGITA. É XSS
// armazenado no caminho mais curto possível.
//
// ⚠️ **COM ISCA:** `className` TEM de ser achado pela mesma leitura. Sem ela, a
// ausência poderia ser ausência de ARQUIVO, não ausência de defeito.
test('fechamento (e): nenhum `dangerouslySetInnerHTML` na área nova, com isca de `className`', () => {
    const infratores = [];
    let varridos = 0;
    let comClassName = 0;

    for (const rel of ARQUIVOS_NOVOS) {
        varridos += 1;
        const fonte = lerSemComentarios(rel);
        if (new RegExp(String.raw`dangerouslySetInnerHTML`).test(fonte)) {
            infratores.push(rel);
        }
        if (new RegExp(String.raw`className`).test(fonte)) {
            comClassName += 1;
        }
    }

    assert.equal(varridos, 3, 'os 3 arquivos novos de `aprovacao/`');
    assert.deepEqual(infratores, [], 'REND: HTML cru injetado na área nova');

    // ISCA: `className` existe em pelo menos um dos três (o selo é componente).
    assert.ok(comClassName >= 1, 'ISCA quebrada: `className` não foi achado em nenhum dos 3 — a leitura devolveu vazio');

    // ISCA da sonda: ela TEM de pegar a forma num texto sintético.
    assert.ok(
        new RegExp(String.raw`dangerouslySetInnerHTML`).test('<div dangerouslySetInnerHTML={{ __html: x }} />'),
        'ISCA quebrada: a sonda não pega um `dangerouslySetInnerHTML` evidente',
    );
});

// ═══════════════════════════════════════════════════════════════════════════════
// (h) OS DOIS GATES POR ELEMENTO — a classe está no HTML **E** no CSS construído
// ═══════════════════════════════════════════════════════════════════════════════

// ⚠️⚠️ O que precisaria estar errado: um mapa de classe do Tailwind viver num
// módulo que o `content` do `tailwind.config.js` NÃO varre. Ele aponta para
// `*.jsx` e **NÃO** para `*.js`: a classe simplesmente **não entra no CSS**, a
// tela sai sem cor, e NADA fica vermelho — nem o build, nem a suíte.
//
// É por isso que a régua GATE-02 desta milestone exige **dois gates por
// elemento**: um que prova que o HTML pede a classe, e outro que prova que o CSS
// construído a TEM. Um sozinho é meia prova.
test('fechamento (h): toda classe `pub-*` dos módulos novos existe no CSS construído, com isca', () => {
    // As classes de cor declaradas nos mapas — lidas dos módulos, não recopiadas.
    const usadas = new Set();
    for (const campos of Object.values(ANDAMENTOS)) {
        for (const chave of ['dot', 'texto', 'fundo', 'borda']) {
            if (typeof campos[chave] === 'string') usadas.add(campos[chave]);
        }
    }
    for (const campos of Object.values(SELOS_DE_ORIGEM)) {
        for (const chave of ['texto', 'borda', 'fundo']) {
            if (typeof campos[chave] === 'string') usadas.add(campos[chave]);
        }
    }

    assert.ok(usadas.size >= 20, `o laço juntou só ${usadas.size} classes — gate sobre poucos casos não prova nada`);

    // ⚠️ A classe vai ao CSS pelo NOME BASE: o Tailwind escapa `/` e `[` no
    // seletor (`.bg-pub-accent\/\[0\.10\]`), então a comparação é pelo prefixo
    // antes do modificador de opacidade.
    const ausentes = [];
    for (const classe of usadas) {
        const base = classe.split('/')[0];
        if (!CSS_CONSTRUIDO.includes(`.${base}`)) {
            ausentes.push(classe);
        }
    }
    assert.deepEqual(ausentes, [], 'GATE-08: classe pedida pelo módulo e AUSENTE do CSS construído — a tela sai sem cor, em silêncio');

    // ⚠️ ISCA: uma classe sintética TEM de sair ausente. Se ela aparecesse
    // "presente", o `includes` estaria casando qualquer coisa e as 20+ linhas
    // acima não provariam nada.
    assert.ok(
        !CSS_CONSTRUIDO.includes('.bg-pub-naoexiste9z7'),
        'ISCA quebrada: a classe sintética aparece no CSS — a sonda está cega',
    );

    // ⚠️ ISCA do outro lado: uma classe que EXISTE tem de ser achada.
    assert.ok(
        CSS_CONSTRUIDO.includes('.bg-pub-accent'),
        'ISCA quebrada: uma classe real do portão não é achada no CSS — a leitura do manifest pegou o arquivo errado',
    );
});

// ═══════════════════════════════════════════════════════════════════════════════
// (d) COR NUNCA É O ÚNICO SINAL — provado no HTML RENDERIZADO, um `test()` por estado
// ═══════════════════════════════════════════════════════════════════════════════

// ⚠️ O que precisaria estar errado: um estado sair como retângulo colorido e
// SEM TEXTO. Já foi achado nesta fase (achado S3): com chave desconhecida o
// componente devolvia `null`, e a suíte ficava verde porque "não quebrou" é o
// que um teste de render mede por omissão.
//
// ⚠️ **UM `test()` POR ESTADO, de propósito:** um laço com 8 asserções dentro
// reporta **1** teste, e aí a CONTAGEM do manifesto deixa de ser sinal. Com 8
// `test()` a remoção de um estado derruba o número — que é a trava do GATE-11.
for (const chave of Object.keys(ANDAMENTOS)) {
    const esperado = ANDAMENTOS[chave].rotulo;

    test(`fechamento (d): o estado "${chave}" rende o rótulo "${esperado}" junto da cor`, () => {
        const html = renderToStaticMarkup(React.createElement(SeloDeAndamentoInterno, { andamento: chave }));

        assert.ok(html.length > 0, 'render vazio: o componente devolveu `null` para uma chave LEGÍTIMA');
        assert.ok(
            html.includes(esperado),
            `VOC-05: o estado "${chave}" não trouxe o rótulo textual "${esperado}" — sobra só a cor`,
        );

        // ⚠️ E a cor TEM de estar lá também: gate que só confere texto deixaria
        // passar o estado sem nenhum sinal visual.
        const temCor = new RegExp(String.raw`(?:bg|text|border)-pub-`).test(html);
        assert.ok(temCor, `o estado "${chave}" não trouxe nenhuma classe de cor — o selo ficaria invisível`);

        // ⚠️ E o texto não pode ser só o travessão de "sem rótulo".
        assert.notEqual(esperado.trim(), '—', `o estado "${chave}" tem rótulo vazio no mapa`);
    });
}

// ⚠️ A ISCA do bloco acima, em teste PRÓPRIO: chave desconhecida NÃO pode
// render o rótulo de um estado legítimo. Sem ela, os 8 testes acima poderiam
// estar passando por o componente render SEMPRE o mesmo texto.
test('fechamento (d): ISCA — chave desconhecida não rende nenhum dos 8 rótulos', () => {
    const html = renderToStaticMarkup(
        React.createElement(SeloDeAndamentoInterno, { andamento: 'chave_sintetica_9z7' }),
    );
    const vazados = Object.values(ANDAMENTOS)
        .map((c) => c.rotulo)
        .filter((r) => html.includes(r));

    assert.deepEqual(
        vazados,
        [],
        'ISCA quebrada: a chave sintética rendeu um rótulo real — o componente ignora a prop e os 8 testes acima não provam nada',
    );
});

// ═══════════════════════════════════════════════════════════════════════════════
// (c) JARGÃO — o lado do RENDER. Nenhuma chave inglesa sai no HTML.
// ═══════════════════════════════════════════════════════════════════════════════

// ⚠️ O que precisaria estar errado: o selo render a CHAVE em vez do RÓTULO —
// `awaiting`, `ecf_review`, `changes` na tela em vez de "Aguardando aprovação",
// "Em revisão pela ECF", "Ajustes solicitados". É o achado S3 pela porta da
// frente, e a regra-mestra do projeto é explícita contra jargão sem explicação.
//
// ⚠️ O gate distingue CHAVE de TEXTO: ele olha o **HTML renderizado**, que é o
// que a pessoa lê — e não o identificador no código, onde a chave é legítima.
test('fechamento (c): nenhuma chave inglesa do protótipo sai no HTML renderizado dos 8 estados', () => {
    const JARGAO = [
        'awaiting', 'ecf_review', 'changes', 'photos', 'nophoto', 'freight',
        'loss', 'margin', 'adjust', 'conf', 'est', 'miss', 'offer', 'prep', 'failed',
    ];
    assert.equal(JARGAO.length, 15, '15 chaves inglesas do protótipo que não podem virar texto');

    const infratores = [];
    let rendes = 0;

    for (const chave of Object.keys(ANDAMENTOS)) {
        rendes += 1;
        const html = renderToStaticMarkup(React.createElement(SeloDeAndamentoInterno, { andamento: chave }));
        // ⚠️ Só o TEXTO: as classes do Tailwind contêm palavras inglesas de
        // propósito, e varrer o HTML inteiro reprovaria o desenho correto.
        const texto = html.replace(/<[^>]*>/g, ' ');
        for (const termo of JARGAO) {
            // ⚠️ BORDA NOS DOIS LADOS: sem ela `est` casa "Estimado" e `conf`
            // casa "conferidos" — e o gate reprovaria o vocabulário CERTO.
            if (new RegExp(String.raw`(?<![\p{L}\p{N}_])` + termo + String.raw`(?![\p{L}\p{N}_])`, 'iu').test(texto)) {
                infratores.push(`${chave} rende o jargão "${termo}" em "${texto.trim()}"`);
            }
        }
    }

    assert.equal(rendes, 8, 'os 8 estados foram renderizados');
    assert.deepEqual(infratores, [], 'VOC-06: chave inglesa do protótipo saindo como TEXTO DE TELA');

    // ⚠️ ISCA: a sonda com borda TEM de pegar o jargão isolado...
    const sonda = (termo, texto) =>
        new RegExp(String.raw`(?<![\p{L}\p{N}_])` + termo + String.raw`(?![\p{L}\p{N}_])`, 'iu').test(texto);
    assert.ok(sonda('margin', 'Bloqueado: margin'), 'ISCA quebrada: a sonda não pega o jargão isolado');
    assert.ok(sonda('est', 'origem est'), 'ISCA quebrada: a sonda não pega `est` com borda');
    // ...e TEM de NÃO pegar quando é substring de palavra pt-BR legítima.
    assert.ok(!sonda('est', 'Estimado'), 'ISCA quebrada: `est` casando "Estimado" reprovaria o selo CORRETO');
    assert.ok(!sonda('conf', 'Fotos, preço e conteúdo conferidos'), 'ISCA quebrada: `conf` casando "conferidos" reprovaria o rodapé CORRETO');
});

// ═══════════════════════════════════════════════════════════════════════════════
// (g) O LADO JSX DA SINCRONIA — as 5 listas, LIDAS do módulo montado
// ═══════════════════════════════════════════════════════════════════════════════

// ⚠️ O que precisaria estar errado: alguém acrescentar entrada num dos mapas e
// o lado PHP (`VocabularioDoPortao`, `PubAprovacaoOferta`) ficar atrás. Não há
// enum compartilhado entre PHP e JS neste projeto — a sincronia é manual, e o
// gate PHP deste plano a mede LENDO este arquivo. Aqui se prova o que aquele
// gate assume: que as cinco listas estão **declaradas e não vazias** no JSX, com
// os tamanhos que a fase afirma.
//
// ⚠️ Sem este bloco o gate PHP poderia passar comparando dois vazios.
test('fechamento (g): as 5 listas do módulo `.jsx` têm os tamanhos que a fase afirma, com isca', () => {
    assert.equal(Object.keys(ANDAMENTOS).length, 8, '8 estados de andamento interno');
    assert.equal(Object.keys(CRITERIOS).length, 7, '7 critérios de fidelidade');
    assert.equal(Object.keys(DEFEITOS).length, 6, '6 defeitos de foto de IA');
    assert.equal(Object.keys(SELOS_DE_ORIGEM).length, 3, '3 selos de origem');
    assert.equal(Object.keys(ABREVIACAO_DO_BLOQUEIO).length, 9, '9 abreviações de bloqueio');
    assert.equal(Object.keys(ROTULO_DO_BLOQUEIO).length, 8, '8 rótulos `BLK`');

    // ⚠️ Nenhum valor vazio: mapa com chave e rótulo em branco é mapa que rende
    // retângulo sem texto, e o tamanho sozinho não pega isso.
    const vazios = [];
    for (const [nome, mapa] of Object.entries({ CRITERIOS, DEFEITOS, ABREVIACAO_DO_BLOQUEIO, ROTULO_DO_BLOQUEIO })) {
        for (const [chave, valor] of Object.entries(mapa)) {
            if (typeof valor !== 'string' || valor.trim() === '') vazios.push(`${nome}[${chave}]`);
        }
    }
    for (const [chave, campos] of Object.entries(ANDAMENTOS)) {
        if (typeof campos.rotulo !== 'string' || campos.rotulo.trim() === '') vazios.push(`ANDAMENTOS[${chave}].rotulo`);
    }
    for (const [chave, campos] of Object.entries(SELOS_DE_ORIGEM)) {
        if (typeof campos.rotulo !== 'string' || campos.rotulo.trim() === '') vazios.push(`SELOS_DE_ORIGEM[${chave}].rotulo`);
    }
    assert.deepEqual(vazios, [], 'VOC-05: entrada de mapa com rótulo vazio rende retângulo sem texto');

    // ⚠️ ISCA: a detecção de vazio TEM de pegar um mapa sintético.
    const sintetico = { a: 'ok', b: '   ' };
    const pegos = Object.entries(sintetico)
        .filter(([, v]) => typeof v !== 'string' || v.trim() === '')
        .map(([k]) => k);
    assert.deepEqual(pegos, ['b'], 'ISCA quebrada: a detecção de rótulo vazio não pega um caso evidente');
});
