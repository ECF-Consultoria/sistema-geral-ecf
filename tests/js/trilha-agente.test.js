import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';
import { comQuemEsta, etapaDaTrilha } from '../../resources/js/lib/trilha.js';
import { ROTULO_CURTO } from '../../resources/js/lib/trilhaMapa.js';
import { MSG_PAROU, ROTULO_DO_PEDIR, vistaDoAjuste } from '../../resources/js/lib/trilhaAjuste.js';
import { ROTULO_DA_GERADA } from '../../resources/js/lib/trilhaPrevia.js';
import { MSG_EM_PREPARO, ROTULO_DA_CRIADA } from '../../resources/js/lib/trilhaAprovacao.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — "robô" virou "agente" (R9, retorno do 1º teste, 04/11/2026).
//
// POR QUE EXISTE: o usuário decidiu "mudar o nome de robô para agente" em TODA a
// trilha, para a equipe E para o cliente. Até a 4e a regra era a inversa (D59: a
// trilha não citava o "agente"; a 4e chegou a trocar o robô por "a equipe" no
// texto do cliente), e mais de dez testes de fonte proibiam "agente". Um texto
// novo escrito no hábito antigo passaria por todos eles. Aqui:
//
// 1. nenhuma string que a pessoa lê na tela da trilha diz "robô" — os pedaços
//    (`Components/Trilha/**`), as libs `trilha*` e as páginas `Pages/Trilha/**`,
//    lidos SEM comentários pelo próprio esbuild (comentário pode citar o nome
//    antigo; string, não);
// 2. nenhuma string PHP da trilha diz "robô" — as mensagens (`MSG_*`, status,
//    avisos, histórico, 422) de `app/Services/Trilha/**`, dos controllers, do
//    `Support` e dos Jobs da trilha, também sem os comentários;
// 3. os PREFIXOS de status que os dois lados escrevem continuam iguais: o D60
//    ("Agente refazendo · …", `SituacaoDasOfertas::REFAZENDO`) e o "Na fila do
//    agente" (`IaTrabalhando::AGENDADA` na linha, `MSG_PARTE_FILA` no ajuste) —
//    o rodapé do ajuste repete o que a Lista diz;
// 4. os rótulos que mais aparecem já dizem "agente" (o dono da etapa 4, o chip
//    do "Com quem está", a faixa do Mapa, o selo da foto gerada — "gerada pelo
//    agente" na equipe, "criada pelo agente" no cliente —, o [Pedir ajuste ao
//    agente]).
//
// Identificadores de código seguem "robo"/"Robo" sem acento (a etapa `robo`, o
// `papel: 'robo'`, `AjusteDoRobo`…): a régua procura "robô" COM acento.
// ═══════════════════════════════════════════════════════════════════════════

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const ROBO = /rob[ôÔ]/i;

/** Os arquivos de uma pasta (recursivo) com uma das extensões, em caminho relativo à raiz. */
function arquivos(pasta, extensoes) {
    const saida = [];
    const andar = (absoluta) => {
        for (const e of fs.readdirSync(absoluta, { withFileTypes: true })) {
            const p = path.join(absoluta, e.name);
            if (e.isDirectory()) andar(p);
            else if (extensoes.some((x) => e.name.endsWith(x))) saida.push(path.relative(RAIZ, p).split(path.sep).join('/'));
        }
    };
    andar(path.join(RAIZ, pasta));

    return saida.sort();
}

/** A fonte JS/JSX sem comentários — o esbuild os joga fora e deixa as strings e o texto do JSX como estão (utf8). */
function jsSemComentarios(relativo) {
    const fonte = fs.readFileSync(path.join(RAIZ, relativo), 'utf8');

    return esbuild.transformSync(fonte, { loader: 'jsx', jsx: 'preserve', charset: 'utf8', legalComments: 'none', format: 'esm' }).code;
}

/**
 * A fonte PHP sem comentários (`//`, `#`, `/* … *\/` e docblocks): uma leitura de estado simples, que conhece as aspas
 * simples e duplas (com a barra que escapa) — um `/*` dentro de string não engole o código seguinte, e um `#[` é
 * atributo, não comentário. Heredoc seguiria como código (lido inteiro, mais estrito); a trilha não usa.
 */
function phpSemComentarios(fonte) {
    let saida = '';
    let i = 0;
    while (i < fonte.length) {
        const c = fonte[i];
        const d = fonte[i + 1];
        if (c === "'" || c === '"') {
            let j = i + 1;
            while (j < fonte.length && fonte[j] !== c) j += fonte[j] === '\\' ? 2 : 1;
            saida += fonte.slice(i, j + 1);
            i = j + 1;
        } else if (c === '/' && d === '*') {
            const fim = fonte.indexOf('*/', i + 2);
            saida += ' ';
            i = fim < 0 ? fonte.length : fim + 2;
        } else if ((c === '/' && d === '/') || (c === '#' && d !== '[')) {
            const fim = fonte.indexOf('\n', i);
            i = fim < 0 ? fonte.length : fim;
        } else {
            saida += c;
            i += 1;
        }
    }

    return saida;
}

/** As linhas (já sem comentários) com "robô", para a mensagem do teste dizer onde. */
const linhasComRobo = (codigo) => codigo.split('\n').filter((l) => ROBO.test(l)).map((l) => l.trim());

const FONTES_JS = [
    ...arquivos('resources/js/Components/Trilha', ['.js', '.jsx']),
    ...arquivos('resources/js/lib', ['.js']).filter((a) => /\/trilha[^/]*\.js$/.test(a)),
    ...arquivos('resources/js/Pages/Trilha', ['.jsx', '.js']),
];

const FONTES_PHP = [
    ...arquivos('app/Services/Trilha', ['.php']),
    ...arquivos('app/Http/Controllers/Trilha', ['.php']),
    ...arquivos('app/Support/Trilha', ['.php']),
    ...arquivos('app/Jobs/Trilha', ['.php']),
];

const constantePhp = (relativo, nome) => {
    const m = fs.readFileSync(path.join(RAIZ, relativo), 'utf8').match(new RegExp(`const ${nome} = '([^']*)';`));
    assert.ok(m, `${relativo}: const ${nome}`);

    return m[1];
};

test('a régua lê o que a pessoa lê: string e texto do JSX ficam, comentário sai (JS e PHP)', () => {
    const js = esbuild.transformSync("// o robô antigo\n/* o robô */\nconst a = 'O robô parou';\nconst b = <span>Robô</span>;\n", { loader: 'jsx', jsx: 'preserve', charset: 'utf8', legalComments: 'none', format: 'esm' }).code;
    // O esbuild reescreve as aspas; o texto fica (utf8, sem `ô`).
    assert.deepEqual(linhasComRobo(js), ['const a = "O robô parou";', 'const b = <span>Robô</span>;']);

    const php = phpSemComentarios("<?php\n// o robô\n# o robô\n/** o robô */\n#[Attr]\n$a = 'image/*'; $b = \"O ROBÔ parou\"; /* o robô */ $c = 'É \\'robô\\'';\n");
    assert.deepEqual(linhasComRobo(php), ["$a = 'image/*'; $b = \"O ROBÔ parou\";   $c = 'É \\'robô\\'';"]);
    assert.ok(php.includes('#[Attr]'), 'atributo não é comentário');
});

test('a tela da trilha não diz "robô" em lugar nenhum (pedaços, libs `trilha*` e páginas) — é "agente" (R9)', () => {
    assert.ok(FONTES_JS.length > 40, `as fontes foram achadas (${FONTES_JS.length})`);
    for (const obrigatorio of ['resources/js/lib/trilha.js', 'resources/js/lib/trilhaAjuste.js', 'resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx',
        'resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx', 'resources/js/Pages/Trilha/Empresa.jsx']) {
        assert.ok(FONTES_JS.includes(obrigatorio), obrigatorio);
    }
    const sobrou = FONTES_JS.flatMap((a) => linhasComRobo(jsSemComentarios(a)).map((l) => `${a}: ${l}`));
    assert.deepEqual(sobrou, []);
});

test('as mensagens PHP da trilha não dizem "robô" (serviços, controllers, Support e Jobs) — é "agente" (R9)', () => {
    assert.ok(FONTES_PHP.length > 25, `as fontes foram achadas (${FONTES_PHP.length})`);
    for (const obrigatorio of ['app/Services/Trilha/AjusteDoRoboNaTrilha.php', 'app/Services/Trilha/SituacaoDasOfertas.php',
        'app/Services/Trilha/Ondas/OndasDaTrilha.php', 'app/Http/Controllers/Trilha/OndasDaTrilhaController.php', 'app/Jobs/Trilha/AjustarPeloRoboNaTrilhaJob.php']) {
        assert.ok(FONTES_PHP.includes(obrigatorio), obrigatorio);
    }
    const sobrou = FONTES_PHP.flatMap((a) => linhasComRobo(phpSemComentarios(fs.readFileSync(path.join(RAIZ, a), 'utf8'))).map((l) => `${a}: ${l}`));
    assert.deepEqual(sobrou, []);
});

test('os prefixos de status são os mesmos nos dois lados: "Agente refazendo · …" (D60) e "Na fila do agente"', () => {
    const refazendo = constantePhp('app/Services/Trilha/SituacaoDasOfertas.php', 'REFAZENDO');
    assert.equal(refazendo, 'Agente refazendo');
    // O rodapé do ajuste (a tela) repete o status da linha (o servidor).
    assert.equal(vistaDoAjuste({ situacao: 'fazendo', partes: {} }).titulo, refazendo);
    assert.equal(vistaDoAjuste({ situacao: 'fazendo', partes: { titulo: { situacao: 'fazendo' } } }).titulo, `${refazendo} · título`);

    const situacao = fs.readFileSync(path.join(RAIZ, 'app/Services/Trilha/SituacaoDasOfertas.php'), 'utf8');
    assert.match(situacao, /IaTrabalhando::AGENDADA\s*=> 'Na fila do agente',/);
    assert.equal(constantePhp('app/Services/Trilha/AjusteDoRoboNaTrilha.php', 'MSG_PARTE_FILA'), 'Na fila do agente.');
    assert.equal(vistaDoAjuste({ situacao: 'fila' }).titulo, 'Na fila do agente');
    assert.equal(vistaDoAjuste({ situacao: 'fila', partes: { descricao: { situacao: 'fila' } } }).titulo, 'Na fila do agente · descrição');
    // As paradas da linha (lidas pelo prefixo no Mapa) já nasceram "agente" — e seguem.
    assert.match(situacao, /'O agente parou: '/);
});

test('os rótulos que mais aparecem dizem "agente": a etapa 4, o chip, o Mapa, o selo da foto e o [Pedir ajuste]', () => {
    for (const trabalho of [false, true]) assert.equal(etapaDaTrilha('robo', trabalho).dono, 'Agente');
    assert.match(etapaDaTrilha('robo', true).faz, /o agente monta o anúncio/);
    for (const visao of ['equipe', 'cliente']) {
        const chip = comQuemEsta({ produtos: [], ofertas: [] }, visao, true).find((c) => c.chave === 'robo');
        assert.deepEqual([chip.dono, chip.papel, chip.etapa], ['Agente', 'robo', 'robo'], visao);
    }
    assert.equal(ROTULO_CURTO.robo, 'Agente');
    assert.equal(ROTULO_DA_GERADA, 'gerada pelo agente');
    // O cliente: o que o agente fez é "do agente" (a 4e tinha posto "criada pela equipe").
    assert.equal(ROTULO_DA_CRIADA, 'criada pelo agente');
    assert.equal(MSG_EM_PREPARO, 'Este anúncio ainda está sendo preparado pelo agente.');
    assert.equal(ROTULO_DO_PEDIR, 'Pedir ajuste ao agente');
    assert.match(MSG_PAROU, /O agente continua o ajuste/);
});
