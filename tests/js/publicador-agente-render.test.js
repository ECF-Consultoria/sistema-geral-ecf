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
// AG-07 do Agente ECF Publicador (20/10/2026) — a tela da loja.
//
// Render REAL (esbuild + react-dom/server) da página `Agente.jsx` inteira —
// header incluído — e do `ConteudoDoAgente` com o JSON do controller e com
// lixo: campo em formato inesperado nunca derruba a tela (a lição das "telas
// pretas" da Aprovação, quick 261020-tdz: renderizar o default export pega o
// que o teste por fonte não pega).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = 'resources/js/Pages/Mlb/Publicador/Agente.jsx';
const CONTEUDO = 'resources/js/Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.agente-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: { flash: {}, errors: {} } }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.agente-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

async function montar(arquivo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, arquivo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
            '@/Layouts/AppLayout': STUB_APPLAYOUT,
        },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover', '@radix-ui/react-dialog'],
    });
    const outfile = path.join(__dirname, `.agente-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const AGENTE = {
    configuracao: {
        existe: true, versao: 3, habilitado: true, controle: 'ativo', motivo_controle: null, modalidade: 'gold_special',
        estoque_minimo: 1, limite_diario: 5, teto_fila: 10, janela_inicio: '08:00', janela_fim: '18:00',
        escopo: { incluir: [], excluir: [] }, atualizado_em: '2026-10-20T13:00:00-03:00',
        // ⚠️ O calendário (E1, 23/10/2026): ISO, 1 = segunda … 7 = domingo.
        dias_semana: [1, 7],
    },
    dias_opcoes: [
        { valor: 1, rotulo: 'Seg' }, { valor: 2, rotulo: 'Ter' }, { valor: 3, rotulo: 'Qua' },
        { valor: 4, rotulo: 'Qui' }, { valor: 5, rotulo: 'Sex' }, { valor: 6, rotulo: 'S\u00e1b' },
        { valor: 7, rotulo: 'Dom' },
    ],
    contadores: { aguardando_dados: 1, preparando: 2, admitidos_hoje: 3, aguardando_aprovacao: 4, publicacao: 0, falhas: 0, publicadas: 0 },
    fila: [
        {
            id: 'a1', produto: { id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' }, estado: 'aguardando_aprovacao', etapa: 'encaminhada',
            origem: 'manual', motivo: null, bloqueios: [], tentativas: 0, solicitada_por: 'Vitória',
            atualizada_em: '2026-10-20T13:05:00-03:00', oferta: { id: 7, andamento: 'aguardando_aprovacao', url: '/meus/1/aprovacao?oferta=7' },
            url_editor: '/editor/10',
        },
        {
            id: 'b2', produto: { id: 11, nome: 'Mesa Lateral', sku: 'MES-01' }, estado: 'bloqueada', etapa: 'elegibilidade', origem: 'automatica',
            motivo: 'Informe o custo do produto no cadastro.',
            bloqueios: [{ codigo: 'sem_fotos', mensagem: 'O produto não tem foto utilizável.', url_correcao: '/editor/11' }],
            tentativas: 0, solicitada_por: null, atualizada_em: '2026-10-20T13:06:00-03:00', oferta: null, url_editor: '/editor/11',
        },
    ],
};

test('Agente.jsx: a página inteira (com o header) renderiza com o JSON do controller', async () => {
    const { default: Agente } = await montar(PAGINA);
    const html = renderToStaticMarkup(React.createElement(Agente, {
        empresa: { nome: 'Loja ECF', chave: 'company-1' }, agente: AGENTE, produtos: [{ id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' }], cabecalho: null,
    }));

    assert.match(html, /Agente da loja/);
    assert.match(html, /Rotina e fila/);
    assert.match(html, /Configuração essencial/);
    // 23/10/2026 (E1): o cartao deixou de se chamar "Atividade" e passou a ser o "Registro de
    // atividade" — a peca nova projeta as TRES fontes, em vez de repetir a tabela da fila.
    assert.match(html, /Registro de atividade/);
});

// ⚠️⚠️ **REAPONTADO EM 23/10/2026, e o link NÃO se perdeu — mudou de cartão.**
//
// Antes, a linha da fila em `aguardando_aprovacao` aparecia no cartão "Atividade", que
// renderizava a fila INTEIRA. O cartão "Rotina e fila" mostra só `admitida`/`preparando`/
// `bloqueada`, então aquela linha **não** sai mais por ali.
// ➡️ Ela sai pelo **Registro de atividade** (E1), com o link vindo do servidor — e é o que
// este caso passa a provar, por isso ele agora monta a prop `atividade`. Reapontar e deixar
// de provar são coisas diferentes: aqui o invariante *"o destino real chega à pessoa"* continua
// cobrado, no lugar onde ele agora vive.
const ATIVIDADE_COM_OFERTA = {
    dias: [{
        dia: '2026-10-20', rotulo: 'Hoje', linhas: [{
            id: 'aprovacao-7', fonte: 'aprovacao', fonte_rotulo: 'Aprovação', origem: 'manual',
            origem_rotulo: 'Uma pessoa', quando: '2026-10-20T13:05:00-03:00', hora: '13:05', dia: '2026-10-20',
            titulo: 'Aprovação', resultado: 'aprovado', resultado_rotulo: null, motivo: null, versao: 1,
            autor: 'Vitória', produto: { id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' },
            links: { produto: '/editor/10', previa: '/previa/10', oferta: '/meus/1/aprovacao?oferta=7' },
        }],
    }],
    pagina: 1, paginas: 1, total: 1, por_pagina: 25,
    filtros: { de: null, ate: null, fonte: null, origem: null, resultado: null, produto_id: null },
    opcoes: { fontes: [], origens: [], resultados: [] },
};

test('ConteudoDoAgente: estados, unidades dos contadores e os destinos reais', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: AGENTE, produtos: [], atividade: ATIVIDADE_COM_OFERTA,
    }));

    assert.match(html, />Ligado</);
    assert.match(html, /Prepara: Clássico/);
    assert.match(html, /Pausar/);
    assert.match(html, /Nada vai ao Mercado Livre sem uma pessoa/);
    // Unidade declarada em cada contador.
    assert.match(html, /produtos · últimas 24 h/);
    assert.match(html, />ofertas</);
    // Destinos reais vindos do servidor, nunca montados na tela.
    // ⚠️ O link da oferta agora vem pelo Registro de atividade, montado pelo SERVIDOR.
    assert.match(html, /href="\/meus\/1\/aprovacao\?oferta=7"/);
    assert.match(html, /href="\/editor\/11"[^>]*>corrigir</);
    assert.match(html, /Faltam dados/);
    assert.match(html, /O produto não tem foto utilizável\./);
});

test('ConteudoDoAgente: loja desligada não oferece pausar nem preparar', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { configuracao: { existe: false, habilitado: false, versao: 0 }, contadores: {}, fila: [] },
    }));

    assert.match(html, />Desligado</);
    assert.doesNotMatch(html, />Pausar</);
    assert.match(html, /Ligue o agente na configuração para preparar/);
    assert.match(html, /O agente ainda não fez nada nesta loja/);
    assert.match(html, /Ainda não configurado/);
});

test('ConteudoDoAgente: pausado oferece Retomar e não promete o Preparar agora', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, controle: 'pausado', motivo_controle: 'Conferindo as fotos.' } },
        produtos: [{ id: 10, nome: 'Cadeira Executiva', sku: 'CAD-AZ' }],
    }));

    assert.match(html, />Pausado</);
    assert.match(html, />Retomar</);
    assert.doesNotMatch(html, />Pausar</);
    assert.match(html, /Motivo: Conferindo as fotos\./);
    assert.match(html, /Retome o agente para preparar\./);
});

test('ConteudoDoAgente: lixo nas props não derruba a tela', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    // 23/10/2026: o caso do VENENO entrou. `Number({toString:null})` LANCA, e o `numero()`
    // deste arquivo convertia sem guarda de tipo desde 20/10 — os casos abaixo nunca punham
    // objeto num campo NUMERICO (`contadores`, `versao`), entao o defeito passava calado.
    const VENENO = { toString: null };
    for (const agente of [null, undefined, {}, { configuracao: null, contadores: 'x', fila: 'nao-e-lista' },
        { fila: [null, { estado: 'estado-novo', bloqueios: 'x', produto: 5, oferta: { url: null } }] },
        { contadores: { aguardando_dados: VENENO, preparando: VENENO }, configuracao: { versao: VENENO, existe: true }, fila: [] },
        { contadores: VENENO, configuracao: VENENO, fila: [{ tentativas: VENENO, produto: VENENO }] }]) {
        assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(ConteudoDoAgente, { agente, produtos: 'x' })));
    }
});

test('Agente.jsx: escritas pelas rotas nomeadas, com a versão que a pessoa viu', () => {
    const pagina = lerSemComentarios(PAGINA);
    const conteudo = lerSemComentarios(CONTEUDO);

    assert.match(pagina, /router\.put\(route\('mlb\.anuncios\.publicador\.agente\.configuracao', \{ conta \}\)/);
    assert.match(pagina, /router\.post\(route\('mlb\.anuncios\.publicador\.agente\.executar', \{ conta \}\)/);
    assert.match(conteudo, /versao_esperada: numero\(configuracao\?\.versao\)/);
    // Nada de protótipo: sem window.ECFA, tema.js, support.js nem localStorage.
    for (const f of [pagina, conteudo]) {
        assert.doesNotMatch(f, /ECFA|tema\.js|support\.js|localStorage/);
    }
});

// ════════════════════════════════════════════════════════════════════════
// O CALENDÁRIO da janela de preparo (E1, 23/10/2026)
//
// ⚠️ DE CADA CASO, A PERGUNTA RESPONDIDA ANTES DELE EXISTIR:
//  1. DOMINGO  → o `7` de domingo ser comido por um `.filter(Boolean)` ou por um
//                `if (dia)`. **É por isto que o projeto usa ISO** (1..7) e não o
//                `dayOfWeek` 0-based: `0` é *falsy* e domingo sumiria. O caso prova
//                que domingo RENDERIZA e sai MARCADO.
//  2. SINAL    → a cor ser o único sinal do dia marcado (VOC-05 proíbe). O marcado
//                tem `aria-pressed="true"`, e a frase diz os dias por extenso.
//  3. VAZIO    → "nenhum dia marcado" ser lido como "o agente está travado". A tela
//                diz, por extenso, que vale qualquer dia.
//  4. MEIA-NOITE → a consequência da janela que cruza a meia-noite ficar implícita.
//                O aviso aparece **só** quando início > fim.
//  5. LIXO     → `dias_opcoes`/`dias_semana` em formato torto derrubando a tela.
// ════════════════════════════════════════════════════════════════════════

test('o calendário: os sete dias com rótulo do servidor, e domingo SOBREVIVE 23_10', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, { agente: AGENTE, produtos: [] }));

    // Os sete, em pt-BR, vindos de `dias_opcoes` — a tela não tem lista própria.
    for (const rotulo of ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'S\u00e1b', 'Dom']) {
        assert.ok(html.includes(`>${rotulo}<`), `o dia "${rotulo}" não apareceu`);
    }
    // E nenhum dia aparece como NÚMERO cru.
    assert.doesNotMatch(html, /aria-pressed="(true|false)"[^>]*>\s*[1-7]\s*</, 'um dia apareceu como número cru na tela');

    // ⚠⚠ DOMINGO (7) sai MARCADO. Com 0-based ele seria `0`, *falsy*, e desapareceria —
    // este caso é o que prova que o ISO está sendo respeitado de ponta a ponta.
    assert.match(html, /aria-pressed="true"[^>]*>Dom</, 'domingo estava marcado e não saiu marcado — sintoma do 0-based *falsy*');
    assert.match(html, /aria-pressed="true"[^>]*>Seg</, 'segunda estava marcada e não saiu marcada');
    // E um dia NÃO marcado sai como `false` — sem isto, `aria-pressed="true"` passaria se
    // TODOS viessem marcados.
    assert.match(html, /aria-pressed="false"[^>]*>Qua</, 'quarta NÃO estava marcada e saiu marcada');

    // ⚠️ A COR NÃO É O ÚNICO SINAL (VOC-05): a frase diz os dias por extenso.
    assert.match(html, /Ele prepara sozinho às Seg, Dom\./, 'a frase não diz os dias marcados por extenso');
});

test('o calendário vazio diz que vale QUALQUER dia, e não parece travado 23_10', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, dias_semana: [] } }, produtos: [],
    }));

    // ⚠️ Sem esta frase, "nenhum marcado" seria lido como "o agente está parado" — e o
    // servidor trata lista vazia como TODO dia.
    assert.match(html, /Nenhum dia marcado: ele pode preparar em qualquer dia da semana\./, 'a tela não explica que nenhum dia marcado vale todo dia');
    assert.doesNotMatch(html, /aria-pressed="true"[^>]*>(Seg|Ter|Qua|Qui|Sex|S\u00e1b|Dom)</, 'um dia saiu marcado com o calendário vazio');
});

test('o aviso da meia-noite aparece SÓ quando a janela cruza a meia-noite 23_10', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    const AVISO = /a madrugada conta como o dia em que a janela começou/;

    // 22:00 → 02:00 cruza: o aviso aparece, porque a consequência não pode ficar implícita.
    const cruza = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, janela_inicio: '22:00', janela_fim: '02:00' } },
    }));
    assert.match(cruza, AVISO, 'a janela cruza a meia-noite e a tela não avisa');

    // 08:00 → 18:00 não cruza: o aviso NÃO aparece, senão seria ruído fixo.
    const naoCruza = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, janela_inicio: '08:00', janela_fim: '18:00' } },
    }));
    assert.doesNotMatch(naoCruza, AVISO, 'o aviso da meia-noite aparece numa janela que não cruza — é ruído fixo');

    // E sem horário nenhum também não aparece.
    const semHora = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, janela_inicio: '', janela_fim: '' } },
    }));
    assert.doesNotMatch(semHora, AVISO, 'o aviso da meia-noite aparece sem horário configurado');
});

test('⚠️ lixo no calendário não derruba a tela 23_10', async () => {
    const { default: ConteudoDoAgente } = await montar(CONTEUDO);
    // ⚠️ `{toString: null}` faz `Number(` e `String(` LANÇAREM — é a assinatura das telas pretas.
    const VENENO = { toString: null };
    const CASOS = [
        { dias_semana: 'nao-e-lista', dias_opcoes: 'x' },
        { dias_semana: [0, 8, -1, null, 'x', VENENO], dias_opcoes: [null, 'x', 42, { valor: VENENO, rotulo: VENENO }] },
        { dias_semana: VENENO, dias_opcoes: VENENO },
        { dias_semana: [1, 1, 1], dias_opcoes: [{ valor: 1, rotulo: null }, { valor: 1, rotulo: 'Seg' }] },
    ];

    for (const caso of CASOS) {
        assert.doesNotThrow(
            () => renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
                agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, dias_semana: caso.dias_semana }, dias_opcoes: caso.dias_opcoes },
            })),
            // ⚠️ NUNCA `String(caso)` aqui: `{toString:null}` lançaria DENTRO da mensagem.
            'um formato torto do calendário derrubou a tela',
        );
    }

    // E o caso mais torto não vaza lixo para a tela.
    const html = renderToStaticMarkup(React.createElement(ConteudoDoAgente, {
        agente: { ...AGENTE, configuracao: { ...AGENTE.configuracao, dias_semana: [0, 9, VENENO] }, dias_opcoes: [{ valor: VENENO, rotulo: VENENO }] },
    }));
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, 'formato inesperado do calendário vazou para a tela');
    assert.ok(html.length > 500, `o HTML veio com ${html.length} bytes — render vazio não prova nada`);
});

test('o calendário viaja na gravacão, e a tela não tem lista de dias própria 23_10', () => {
    const conteudo = lerSemComentarios(CONTEUDO);

    // O formulário manda `dias_semana` no PUT.
    assert.match(conteudo, /dias_semana: lista\(form\.dias_semana\)/, 'o calendário não viaja na gravação');

    // ⚠⚠ **UM VOCABULÁRIO SÓ:** os rótulos vêm de `dias_opcoes`, do servidor. Uma segunda lista
    // em JS divergiria — erro medido nas Fases 186/187, e lá a regra ficou: chaves pt-BR nos dois
    // lados, e NUNCA um `||` aceitando as duas formas.
    for (const nome of ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo']) {
        assert.equal(conteudo.includes(`'${nome}'`), false, `a tela tem um rótulo próprio para ${nome} — o rótulo vem do servidor`);
    }
    assert.equal(/const DIAS\b/.test(conteudo), false, 'a tela ganhou uma lista de dias própria');

    // ⚠️ ISCA do leitor: a fonte foi lida de verdade.
    assert.match(conteudo, /diasOpcoes/, 'a fonte lida não é a do componente — as asserções acima seriam vazias');
});
