import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// O REGISTRO DE ATIVIDADE DO AGENTE (E1, 23/10/2026) — a peça de tela.
//
// ⚠️⚠️ **ANTES o cartão "Atividade" mostrava a MESMA tabela da fila**, com outro
// título: era a fila repetida, não registro de atividade (e o docblock do
// `LeituraDoAgente` já prometia "atividade" que não existia). Agora é a
// projeção das três fontes que o agente e o portão já persistem, agrupada por
// dia, filtrável e paginada — **sem tabela nova** (§5.5 do plano).
//
// ⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DELE EXISTIR: "o que precisaria
// estar errado no mundo para ele falhar?" — **"nada" é ACHADO.**
//
//  1. RENDER   → `String(valor)`, `obj[chave]` e prop de NÓ chegando objeto
//                comum **lançam** e a página fica PRETA. Voltou SEIS vezes nesta
//                milestone, a última no clique. Render REAL com campo objeto,
//                nulo, ausente, NaN e array.
//  2. RÓTULO   → a peça traduzir estado por conta própria. Dois vocabulários
//                (um em PHP, um em JS) divergem — erro medido nas Fases 186/187.
//                Todo rótulo vem do servidor, e o gate cobra a AUSÊNCIA de mapa
//                de tradução no arquivo.
//  3. LINK     → link que não leva a lugar nenhum (decisão D4: "botão sem
//                destino não nasce"; *"desabilitado com explicação"* foi
//                oferecido e RECUSADO).
//  4. FILTRO   → filtro em `useState`, que faria recarregar perder o recorte
//                (COL-03). Ele viaja pela URL, e o gate cobra isso na fonte.
//  5. AVISO    → o filtro de resultado exclui duas das três fontes de propósito;
//                sem o aviso a pessoa acha que perdeu registro.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const REL_PECA = 'resources/js/Components/Mlb/Publicador/agente/RegistroDeAtividade.jsx';
const REL_CONTEUDO = 'resources/js/Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx';
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Agente.jsx';

const FONTE_PECA = lerSemComentarios(REL_PECA);
const FONTE_CONTEUDO = lerSemComentarios(REL_CONTEUDO);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

// ⚠️ Montado ANTES do primeiro `test()`: o `after()` do `node --test` já matou um
// arquivo inteiro neste projeto quando a montagem ficou dentro dos casos.
const resultado = await esbuild.build({
    entryPoints: [path.resolve(RAIZ, REL_PECA)],
    bundle: true,
    format: 'esm',
    platform: 'node',
    jsx: 'automatic',
    write: false,
    logLevel: 'silent',
    alias: { '@': path.resolve(RAIZ, 'resources/js') },
    external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
});
const OUT = path.join(__dirname, `.atividade-render-${process.pid}-${Date.now()}.mjs`);
fs.writeFileSync(OUT, resultado.outputFiles[0].text, 'utf8');
const MOD = await import(pathToFileURL(OUT).href);
after(() => fs.rmSync(OUT, { force: true }));

const RegistroDeAtividade = MOD.default;
const { LinhaDaAtividade } = MOD;

/** O JSON que o `AtividadeDoAgente` devolve — a forma, não um palpite. */
const ATIVIDADE = {
    dias: [
        {
            dia: '2026-10-23',
            rotulo: 'Hoje',
            linhas: [
                {
                    id: 'preparo-9', fonte: 'preparo', fonte_rotulo: 'Preparo', origem: 'automatica',
                    origem_rotulo: 'O agente', quando: '2026-10-23T11:02:00-03:00', hora: '11:02', dia: '2026-10-23',
                    titulo: 'Preparo de produto', resultado: 'bloqueada', resultado_rotulo: 'Faltam dados',
                    motivo: 'Informe o custo do produto no cadastro.', versao: 3, autor: 'Sistema',
                    produto: { id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' },
                    links: { produto: '/editor/10', previa: '/previa/10' },
                },
                {
                    id: 'aprovacao-4', fonte: 'aprovacao', fonte_rotulo: 'Aprovação', origem: 'manual',
                    origem_rotulo: 'Uma pessoa', quando: '2026-10-23T10:40:00-03:00', hora: '10:40', dia: '2026-10-23',
                    titulo: 'Aprovação', resultado: 'aprovado', resultado_rotulo: null,
                    motivo: 'conferida na prévia', versao: 2, autor: 'Vitória',
                    produto: { id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' },
                    links: { produto: '/editor/10', previa: '/previa/10', oferta: '/meus/1/aprovacao?oferta=4' },
                },
            ],
        },
        {
            dia: '2026-10-22',
            rotulo: 'Ontem',
            linhas: [
                {
                    id: 'configuracao-2', fonte: 'configuracao', fonte_rotulo: 'Configuração', origem: 'manual',
                    origem_rotulo: 'Uma pessoa', quando: '2026-10-22T17:30:00-03:00', hora: '17:30', dia: '2026-10-22',
                    titulo: 'Configuração salva (versão 2)', resultado: null,
                    resultado_rotulo: 'Agente pausado nesta versão', motivo: null, versao: 2, autor: 'Maycon',
                    produto: null, links: {},
                },
            ],
        },
    ],
    pagina: 1,
    paginas: 2,
    total: 28,
    por_pagina: 25,
    filtros: { de: null, ate: null, fonte: null, origem: null, resultado: null, produto_id: null },
    opcoes: {
        fontes: [{ valor: 'preparo', rotulo: 'Preparo' }, { valor: 'configuracao', rotulo: 'Configuração' }, { valor: 'aprovacao', rotulo: 'Aprovação' }],
        origens: [{ valor: 'automatica', rotulo: 'O agente' }, { valor: 'manual', rotulo: 'Uma pessoa' }],
        resultados: [{ valor: 'bloqueada', rotulo: 'Faltam dados' }, { valor: 'concluida', rotulo: 'Preparo concluído' }],
    },
};

test('as três fontes, os grupos de dia e a contagem com unidade', () => {
    const html = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: ATIVIDADE }));

    // Os grupos por dia, por extenso — "3 d" foi RECUSADO pelo usuário nesta milestone.
    assert.match(html, />Hoje</, 'o grupo de hoje não apareceu');
    assert.match(html, />Ontem</, 'o grupo de ontem não apareceu');

    // As três fontes, em pt-BR: nome de tabela não vai para a tela.
    for (const rotulo of ['Preparo', 'Configuração', 'Aprovação']) {
        assert.ok(html.includes(`>${rotulo}<`), `a fonte "${rotulo}" não apareceu rotulada na tela`);
    }
    assert.doesNotMatch(html, /pub_agente|pub_aprovacao|UNION/, 'nome de tabela ou de SQL vazou para a tela');

    // ⚠️ A contagem diz a UNIDADE: "registros", não um número solto.
    assert.match(html, /28 registros/, 'a contagem não disse a unidade');

    // O desfecho de cada linha, e o motivo.
    assert.match(html, /Faltam dados/, 'o desfecho do preparo não apareceu');
    assert.match(html, /Agente pausado nesta versão/, 'o estado da versão da configuração não apareceu');
    assert.match(html, /Informe o custo do produto no cadastro\./, 'o motivo não apareceu');

    // ⚠️ E o título da configuração NÃO afirma o que mudou — é o que o servidor pode provar.
    assert.match(html, /Configuração salva \(versão 2\)/, 'o título da linha de configuração mudou de forma');
});

test('os links só aparecem quando o servidor os manda — nenhum é montado na tela', () => {
    const html = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: ATIVIDADE }));

    assert.match(html, /href="\/editor\/10"/, 'o link do editor não apareceu');
    assert.match(html, /href="\/previa\/10"/, 'o link da prévia não apareceu');
    assert.match(html, /href="\/meus\/1\/aprovacao\?oferta=4"/, 'o link da Aprovação não apareceu');

    // ⚠️ A linha da configuração tem `links: {}` — e NÃO ganha link nenhum. Link que não leva a
    // lugar nenhum é dívida (decisão D4).
    const soConfiguracao = renderToStaticMarkup(React.createElement(LinhaDaAtividade, {
        linha: ATIVIDADE.dias[1].linhas[0],
    }));
    assert.doesNotMatch(soConfiguracao, /<a /, 'a linha sem destino ganhou link');
    assert.match(soConfiguracao, /Configuração salva/, 'a linha sem link também perdeu o conteúdo — o recorte está vazio');

    // ⚠️ E a peça não MONTA rota: tudo vem pronto do servidor. `route(` aqui seria a tela
    // adivinhando a URL da Aprovação, que depende de `Company` e daria 404 sem ela.
    assert.doesNotMatch(FONTE_PECA, /\broute\(/, 'a peça passou a montar rota — o destino vem do servidor');
});

test('⚠️⚠️ lixo nas props não derruba a tela — a lição das telas pretas', () => {
    // ⚠️ `{toString: null}` é o caso que faz `String(valor)` LANÇAR, e foi o que deixou a
    // Aprovação preta seis vezes nesta milestone.
    const VENENO = { toString: null };
    const CASOS = [
        null, undefined, {}, 'nao-e-objeto', 42, [],
        { dias: 'nao-e-lista', total: 'x', pagina: null, paginas: NaN, filtros: 'x', opcoes: 7 },
        { dias: [null, undefined, 'x'], total: VENENO },
        { dias: [{ dia: VENENO, rotulo: VENENO, linhas: VENENO }] },
        { dias: [{ dia: '2026-10-23', rotulo: 'Hoje', linhas: [null, undefined, 42, 'x'] }] },
        {
            dias: [{
                dia: '2026-10-23', rotulo: 'Hoje', linhas: [{
                    id: VENENO, fonte: VENENO, fonte_rotulo: VENENO, origem_rotulo: VENENO, hora: VENENO,
                    titulo: VENENO, resultado_rotulo: VENENO, motivo: VENENO, autor: VENENO,
                    produto: VENENO, links: VENENO, versao: VENENO,
                }],
            }],
        },
        { dias: [{ dia: 'd', rotulo: 'r', linhas: [{ produto: { nome: VENENO, sku: [] }, links: { previa: 7, produto: null } }] }] },
        { ...ATIVIDADE, opcoes: { fontes: [null, { valor: VENENO }, 'x'], origens: VENENO, resultados: null } },
        { ...ATIVIDADE, paginas: 0, pagina: -3 },
    ];

    for (const atividade of CASOS) {
        assert.doesNotThrow(
            () => renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade, onFiltrar: () => {} })),
            // ⚠️ NUNCA `String(caso)` nem `JSON.stringify(caso)` na mensagem: `{toString:null}`
            // lançaria DENTRO da mensagem de erro e esconderia o defeito real.
            'uma forma adversa de `atividade` derrubou a peça',
        );
    }

    // ⚠️ E o HTML do caso mais torto não vaza lixo para a tela.
    const html = renderToStaticMarkup(React.createElement(RegistroDeAtividade, {
        atividade: { dias: [{ dia: 'd', rotulo: 'r', linhas: [{ titulo: VENENO, produto: VENENO, links: VENENO }] }], total: NaN },
    }));
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, 'formato inesperado vazou para a tela');

    // ⚠️ ISCA: a sonda acha o lixo quando ele está lá — sem isto o `doesNotMatch` acima
    // passaria por recorte vazio.
    assert.match('x [object Object] y', /\[object Object\]|NaN|undefined/, 'a sonda de lixo está CEGA');
    assert.ok(html.length > 200, `o HTML veio com ${html.length} bytes — render vazio não prova nada`);
});

test('⚠️ nenhum rótulo é traduzido na tela, e nada de `String(` nem `localStorage`', () => {
    // ⚠️⚠️ **DOIS VOCABULÁRIOS DIVERGEM.** Os rótulos (`fonte_rotulo`, `origem_rotulo`, `titulo`,
    // `resultado_rotulo`) chegam prontos do `AtividadeDoAgente`. Mapa de tradução aqui viraria
    // uma segunda fonte de verdade — foi erro medido nas Fases 186/187, e lá a regra ficou:
    // **um vocabulário só, chaves pt-BR nos dois lados, e NUNCA um `||` aceitando as duas formas.**
    for (const chave of ['admitida', 'preparando', 'aguardando_aprovacao', 'concluida', 'bloqueada', 'falhou', 'cancelada']) {
        assert.equal(FONTE_PECA.includes(`${chave}:`), false, `a peça traduz o estado \`${chave}\` por conta própria — o rótulo vem do servidor`);
    }
    for (const chave of ['automatica:', 'manual:', 'ao_salvar:']) {
        assert.equal(FONTE_PECA.includes(chave), false, `a peça traduz a origem \`${chave}\` por conta própria`);
    }

    // ⚠️ `String(` LANÇA com `{toString:null}`; `localStorage` é estado de negócio no navegador,
    // RECUSADO pelo usuário desde a 187.
    assert.doesNotMatch(FONTE_PECA, /\bString\(/, '`String(` entrou na peça — ele LANÇA com `{toString:null}`');
    assert.doesNotMatch(FONTE_PECA, /localStorage|sessionStorage/, 'estado de negócio foi para o navegador');

    // ⚠️ ISCA do leitor, nas duas direções: a fonte foi lida de verdade e o
    // `lerSemComentarios` não comeu o código — sem isso os `doesNotMatch` seriam vazios.
    assert.match(FONTE_PECA, /export default function RegistroDeAtividade\(/, 'a peça desapareceu da fonte lida');
    assert.ok(FONTE_PECA.length > 2000, `a fonte da peça veio com ${FONTE_PECA.length} bytes — a leitura está quebrada`);
    assert.ok('a String( b'.includes('String('), 'a sonda de `String(` está CEGA');
});

test('⚠️⚠️ o filtro e a página viajam pela URL, nunca em `useState` (COL-03)', () => {
    // ⚠️ Filtro em estado local faria recarregar, compartilhar o link ou voltar no navegador
    // perderem o recorte. É a mesma decisão da fila de Aprovação, e lá há gate próprio.
    assert.equal(FONTE_PECA.includes('useState'), false, 'a peça da atividade ganhou estado local — o recorte vive na URL');
    assert.equal(FONTE_PECA.includes('useEffect'), false, 'a peça da atividade ganhou efeito');

    // E a PÁGINA faz a recarga PARCIAL: `only: ['atividade']`, porque as props pesadas do
    // controller são closures e o Inertia não avalia as que a recarga não pede.
    assert.match(FONTE_PAGINA, /only:\s*\['atividade'\]/, 'a paginação não é parcial — ela reconsultaria configuração, fila e os 500 produtos');
    assert.match(FONTE_PAGINA, /router\.get\(route\('mlb\.anuncios\.publicador\.agente', \{ conta \}\)/, 'a atividade não navega pela rota nomeada da própria tela');

    // ⚠️ Chave vazia é OMITIDA da querystring — sem isso "Limpar filtros" produziria
    // `?fonte=&origem=&pagina=1` e o link limpo deixaria de ser limpo.
    assert.match(FONTE_PAGINA, /v !== null && v !== undefined && v !== ''/, 'a querystring deixou de omitir valor vazio');
});

test('o cartão da Atividade passou a montar a peça, e não a tabela da fila', () => {
    // ⚠️ O que este caso impede: alguém reapontar o cartão para a `TabelaDeExecucoes` de novo —
    // que é exatamente o que havia antes, e era a fila repetida com outro título.
    assert.match(FONTE_CONTEUDO, /<RegistroDeAtividade/, 'o cartão não monta a peça da atividade');
    assert.match(FONTE_CONTEUDO, /titulo="Registro de atividade"/, 'o cartão perdeu o título do registro');
    assert.match(FONTE_CONTEUDO, /atividade=\{atividade\}/, 'a prop `atividade` não chega à peça');

    // ⚠️ A `TabelaDeExecucoes` continua existindo e serve "Rotina e fila" — ela NÃO foi apagada.
    // Mas o cartão do registro não a usa mais.
    assert.ok(FONTE_CONTEUDO.includes('TabelaDeExecucoes'), 'a tabela da fila foi apagada junto — ela ainda serve Rotina e fila');
    const depoisDoTitulo = FONTE_CONTEUDO.slice(FONTE_CONTEUDO.indexOf('titulo="Registro de atividade"'));
    assert.equal(depoisDoTitulo.includes('TabelaDeExecucoes'), false, 'o cartão do registro voltou a usar a tabela da fila');

    // E a página repassa a prop e o callback.
    assert.match(FONTE_PAGINA, /atividade=\{atividade\}/, 'a página não repassa `atividade`');
    assert.match(FONTE_PAGINA, /onFiltrarAtividade=\{filtrarAtividade\}/, 'a página não repassa o callback do filtro');
});

test('o filtro de resultado AVISA que deixa duas fontes de fora', () => {
    // ⚠️ O resultado só existe no preparo. Filtrar e ver configuração e aprovação sumirem faria a
    // pessoa achar que perdeu registro — então a tela DIZ. (O servidor devolve o filtro aplicado
    // nas props justamente para isto ser possível.)
    const comResultado = { ...ATIVIDADE, filtros: { ...ATIVIDADE.filtros, resultado: 'bloqueada' } };
    const html = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: comResultado }));

    assert.match(html, /O resultado só existe no preparo/, 'a tela não avisou que o filtro exclui duas fontes');
    assert.match(html, /Limpar filtros/, '"Limpar filtros" não apareceu com filtro aplicado');
    assert.match(html, /no recorte/, 'a contagem não disse que está recortada');

    // ⚠️ E SEM filtro o aviso NÃO aparece, nem o "Limpar filtros" — senão seriam ruído fixo.
    const semFiltro = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: ATIVIDADE }));
    assert.doesNotMatch(semFiltro, /O resultado só existe no preparo/, 'o aviso aparece sem filtro — é ruído fixo');
    assert.doesNotMatch(semFiltro, /Limpar filtros/, '"Limpar filtros" aparece sem filtro nenhum para limpar');
    assert.doesNotMatch(semFiltro, /no recorte/, 'a contagem diz "no recorte" sem recorte');
});

test('a paginação aparece só com mais de uma página, e diz onde a pessoa está', () => {
    const html = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: ATIVIDADE }));
    assert.match(html, /Página 1 de 2/, 'a paginação não diz onde a pessoa está');
    assert.match(html, />Anterior</, 'o botão Anterior não apareceu');
    assert.match(html, />Próxima</, 'o botão Próxima não apareceu');
    // ⚠️ `disabled=` com o sinal de igual: `/disabled/` sozinho casa `disabled:opacity-40`, que é
    // CLASSE do Tailwind — asserção vazia já medida neste projeto.
    assert.match(html, /<button[^>]*disabled=""[^>]*>\s*Anterior/, 'na primeira página o Anterior não está desabilitado');

    // ⚠️ Com uma página só a paginação NÃO nasce.
    const umaPagina = renderToStaticMarkup(React.createElement(RegistroDeAtividade, {
        atividade: { ...ATIVIDADE, paginas: 1, total: 3 },
    }));
    assert.doesNotMatch(umaPagina, /Página 1 de/, 'a paginação apareceu com uma página só');
    assert.doesNotMatch(umaPagina, />Próxima</, 'o botão Próxima apareceu com uma página só');
});

test('loja sem atividade diz isso, e com filtro diz outra coisa', () => {
    const vazia = { dias: [], pagina: 1, paginas: 1, total: 0, filtros: ATIVIDADE.filtros, opcoes: ATIVIDADE.opcoes };
    const html = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: vazia }));
    assert.match(html, /O agente ainda não fez nada nesta loja\./, 'a loja sem atividade não disse isso');

    // ⚠️ Com filtro a frase é OUTRA: "ainda não fez nada" seria MENTIRA quando há registros
    // fora do recorte — e levaria a pessoa a achar que o agente está parado.
    const recorteVazio = { ...vazia, filtros: { ...ATIVIDADE.filtros, fonte: 'configuracao' } };
    const comFiltro = renderToStaticMarkup(React.createElement(RegistroDeAtividade, { atividade: recorteVazio }));
    assert.match(comFiltro, /Nenhum registro neste recorte\./, 'o recorte vazio não foi distinguido da loja parada');
    assert.doesNotMatch(comFiltro, /ainda não fez nada/, 'o recorte vazio afirmou que o agente está parado');
});
