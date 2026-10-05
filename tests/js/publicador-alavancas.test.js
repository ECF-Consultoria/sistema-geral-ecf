import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';
import { ROTULO_ACAO, ROTULO_ALAVANCA, ROTULO_RESULTADO, ROTULO_TIPO } from '../../resources/js/Components/Mlb/Alavancas/rotulos.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte das Alavancas do Publicador (Fase 166). Todo arquivo da pasta
// `Components/Mlb/Alavancas` entra sozinho na lista — os planos seguintes só
// acrescentam arquivos, nunca editam este gate. Lê a fonte SEM comentários.
// ═══════════════════════════════════════════════════════════════════════

const RAIZ = resolve(import.meta.dirname, '../..');
const PASTA = 'resources/js/Components/Mlb/Alavancas';
const PAGINA = 'resources/js/Pages/Mlb/Publicador/Alavancas.jsx';

const DA_PASTA = readdirSync(resolve(RAIZ, PASTA), { recursive: true })
    .map((c) => String(c).replaceAll('\\', '/'))
    .filter((c) => /\.jsx?$/.test(c))
    .map((c) => `${PASTA}/${c}`);
const ARQUIVOS = [...DA_PASTA, PAGINA];

const TAMANHOS_OK = new Set(['24', '15', '13', '11']);

for (const caminho of ARQUIVOS) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia: só 24/15/13/11px`, () => {
        for (const m of fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) {
            assert.ok(TAMANHOS_OK.has(m[1]), `tamanho fora do vocabulário: ${m[1]}px`);
        }
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    });

    test(`${caminho} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    });

    test(`${caminho} — sem amarelo sólido, sem select Radix, sem HTML injetado, sem caixa-alta`, () => {
        assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
        assert.doesNotMatch(fonte, /\buppercase\b/);
    });

    test(`${caminho} — sem contador de estrutura nem "N/M"`, () => {
        assert.doesNotMatch(fonte, /Faltam \$\{|'Falta 1'|>Completo<|Estrutura do anúncio|\{prontas\}|contarItensProntos|estadoDosItens/);
        assert.doesNotMatch(fonte, /\{[^{}]+\}\s*\/\s*\{[^{}]+\}/);
    });

    test(`${caminho} — no máximo um amarelo sólido (BotaoAcao primario)`, () => {
        assert.ok((fonte.match(/primario/g) ?? []).length <= 1, 'mais de uma ocorrência de `primario`');
    });

    test(`${caminho} — não usa endpoint do ML direto nem o wizard antigo`, () => {
        assert.doesNotMatch(fonte, /prices\/standard\/quantity|product_ads\/items|ads\/search|mlb\.anuncios\.wizard/);
    });
}

test('órfão — todo .jsx da pasta é importado por outro arquivo da pasta ou pela página (o build não compila o que ninguém importa)', () => {
    const todos = ARQUIVOS.map((c) => [c, lerSemComentarios(c)]);
    for (const [caminho] of todos.filter(([c]) => c.endsWith('.jsx') && c !== PAGINA)) {
        const nome = caminho.split('/').pop().replace(/\.jsx$/, '');
        const importado = todos.some(([outro, fonte]) => outro !== caminho && new RegExp(`import[^;]*from '[^']*/${nome}'`).test(fonte));
        assert.ok(importado, `${caminho} não é importado por ninguém`);
    }
});

test('AreaTabs — troca de rota entre as duas áreas e marca a atual', () => {
    const f = lerSemComentarios(`${PASTA}/AreaTabs.jsx`);
    assert.match(f, /mlb\.anuncios\.publicador\.alavancas\.index/);
    assert.match(f, /mlb\.anuncios\.publicador\.produtos/);
    assert.match(f, /router\.get/);
    assert.match(f, /aria-current/);
});

test('Produtos.jsx — a barra Publicar | Alavancas vem ANTES dos modos do anúncio', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');
    assert.match(f, /import AreaTabs /);
    assert.match(f, /<AreaTabs area="publicar"/);
    assert.ok(f.indexOf('<AreaTabs') < f.indexOf('<ModoAnuncioTabs'));
});

test('Alavancas.jsx — barra da área e estado sem conta', () => {
    const f = lerSemComentarios(PAGINA);
    assert.match(f, /area="alavancas"/);
    assert.match(f, /tem_conta/);
});

test('AvisoAlavancasTravadas — calmo, com o texto combinado e sem a palavra "Publicação"', () => {
    const f = lerSemComentarios(`${PASTA}/AvisoAlavancasTravadas.jsx`);
    assert.doesNotMatch(f, /red-|amber-|AlertTriangle/);
    assert.match(f, /Você pode ver e analisar tudo aqui/);
    assert.doesNotMatch(f, /Publicação/);
});

// ─── Contrato de rotas: parâmetro de caminho vai pelo Ziggy, nunca em `params` do axios ───

/** Rotas do grupo `publicador.alavancas.` cujo caminho tem parâmetro além de `{conta}`. */
function rotasComParametro() {
    const fonte = readFileSync(resolve(RAIZ, 'routes/mlb_anuncios.php'), 'utf8');
    const desde = fonte.slice(fonte.indexOf("->name('publicador.alavancas.')"));
    // O grupo acaba no primeiro `});` recuado em 12 espaços; o que vem depois é de outras rotas.
    const grupo = desde.slice(0, desde.search(/\r?\n {12}\}\);/));
    const achadas = {};
    for (const m of grupo.matchAll(/Route::(?:get|post)\('([^']*)'[^;]*?->name\('([^']+)'\);/g)) {
        const params = [...m[1].matchAll(/\{(\w+)\}/g)].map((p) => p[1]);
        if (params.length > 0) achadas[m[2]] = params;
    }

    return achadas;
}

/** Texto entre os parênteses da chamada que abre em `inicio` (o índice do `(`). */
function argumentosDe(fonte, inicio) {
    let nivel = 0;
    for (let i = inicio; i < fonte.length; i += 1) {
        if (fonte[i] === '(') nivel += 1;
        if (fonte[i] === ')') {
            nivel -= 1;
            if (nivel === 0) return fonte.slice(inicio, i + 1);
        }
    }

    return fonte.slice(inicio);
}

test('contrato de rotas — a lista mínima de rotas com parâmetro de caminho foi achada', () => {
    const rotas = rotasComParametro();
    assert.deepEqual(rotas['promocoes.itens'], ['promocao']);
    assert.deepEqual(rotas['produtos.promocoes'], ['item']);
    assert.deepEqual(rotas['exclusao.item'], ['item']);
    assert.deepEqual(rotas['atacado.item'], ['item']);
    assert.deepEqual(rotas['atacado.recomendacoes'], ['item']);
    assert.deepEqual(rotas['historico.mostrar'], ['escrita']);
    assert.deepEqual(rotas.lotes, ['lote']);
});

test('contrato de rotas — toda chamada a rota com parâmetro de caminho o informa', () => {
    const rotas = rotasComParametro();
    for (const caminho of ARQUIVOS) {
        const fonte = lerSemComentarios(caminho);
        for (const [nome, params] of Object.entries(rotas)) {
            const re = new RegExp(`(?:useLeitura|rota)\\(\\s*'${nome.replace('.', '\\.')}'`, 'g');
            for (const m of fonte.matchAll(re)) {
                const args = argumentosDe(fonte, m.index + m[0].indexOf('('));
                for (const p of params) {
                    assert.match(args, new RegExp(`\\b${p}\\b`), `${caminho}: Rota ${nome} chamada sem o parâmetro de caminho ${p}`);
                }
            }
        }
    }
});

test('useAlavancas — o caminho é montado pelo Ziggy (nada de `{ params }` no axios.get)', () => {
    const f = lerSemComentarios(`${PASTA}/useAlavancas.js`);
    assert.doesNotMatch(f, /axios\.get\([^)]*\{\s*params/);
    assert.match(f, /rota\(nome, conta, /);
});

// ─── Rótulos espelham as constantes do PHP ───

const doPhp = (arquivo, constante) => {
    const fonte = readFileSync(resolve(RAIZ, arquivo), 'utf8');
    const bloco = fonte.match(new RegExp(`const ${constante} = \\[([\\s\\S]*?)\\];`))?.[1] ?? '';

    return [...bloco.matchAll(/'([A-Za-z_]+)'/g)].map((m) => m[1]);
};

test('rotulos — ROTULO_TIPO espelha TiposDePromocao::TODOS e tem 12 tipos', () => {
    const php = doPhp('app/Services/Publicador/Alavancas/TiposDePromocao.php', 'TODOS');
    assert.equal(php.length, 12);
    assert.deepEqual(Object.keys(ROTULO_TIPO).sort(), [...php].sort());
});

test('rotulos — ROTULO_ALAVANCA espelha PubAlavancaEscrita::ALAVANCAS', () => {
    assert.deepEqual(Object.keys(ROTULO_ALAVANCA).sort(), doPhp('app/Models/PubAlavancaEscrita.php', 'ALAVANCAS').sort());
});

test('rotulos — ROTULO_RESULTADO cobre os 5 resultados do modelo', () => {
    assert.deepEqual(Object.keys(ROTULO_RESULTADO).sort(), ['ERRO', 'INCERTO', 'OK', 'PENDENTE', 'RECUSADA']);
});

test('rotulos — ROTULO_ACAO tem as 15 ações do registro', () => {
    assert.equal(Object.keys(ROTULO_ACAO).length, 15);
});
