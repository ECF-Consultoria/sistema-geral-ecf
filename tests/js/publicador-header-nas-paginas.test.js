import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 07 — as 6 páginas do Publicador MONTAM com o header global.
//
// POR QUE ESTE ARQUIVO PRECISOU NASCER. Em 07/10/2026 um campo de presenter
// chegou como OBJETO, foi renderizado cru e "Objects are not valid as a React
// child" derrubou a página inteira — tela preta em produção. A troca deste
// plano repete exatamente o ingrediente do acidente: uma prop nova do servidor
// (`cabecalho`), em SEIS páginas de uma vez, todas no ar.
//
// E a rede não existia. Medido antes de escrever isto:
//   · `VisaoGeral.jsx`, `Produto.jsx` e `Alavancas.jsx` não tinham NENHUM teste
//     de render — apagar o header delas não reprovava nada;
//   · `PublicacaoEmLote.jsx` e `Configuracoes.jsx` só eram renderizados SEM a
//     prop `cabecalho`, isto é, só no caminho do default de parâmetro.
//
// Aqui cada uma das 6 é renderizada DE VERDADE (esbuild + react-dom/server) em
// três estados que acontecem em produção:
//   1. `cabecalho` OBJETO completo (o caminho normal);
//   2. `cabecalho: null` — o default de parâmetro NÃO cobre isto, e foi
//      justamente uma prop nula numa recarga parcial do Inertia que já derrubou
//      tela neste projeto;
//   3. `cabecalho` AUSENTE — a página antiga em cache, ou um controller que
//      ainda não manda a prop.
// Mais os campos de dentro do `cabecalho` chegando como objeto/array/nulo, que
// é a forma exata do acidente de 07/10.
//
// ⚠️ TODOS os bundles são montados AQUI, no topo e ANTES do primeiro `test()`:
// o `after()` apaga os stubs do esbuild, e um `montar()` tardio já pegou os
// stubs apagados no meio da compilação — um arquivo de teste inteiro morreu
// assim nesta semana, sem nenhuma asserção falhar e só na suíte completa.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR_PAGINAS = 'resources/js/Pages/Mlb/Publicador';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// Sem DOM nos testes deste projeto: `window` mínimo. As páginas leem
// `location.search` na montagem e registram ouvintes.
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.header-paginas-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.header-paginas-applayout-stub-${process.pid}.mjs`);
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

/** Compila a página de verdade (JSX + imports reais) e devolve os exports. */
async function montar(nomeDoArquivo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, DIR_PAGINAS, nomeDoArquivo)],
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
        external: [
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(__dirname, `.header-paginas-${nomeDoArquivo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── O `cabecalho` de verdade: a forma que `CabecalhoPublicadorService::para()`
//     devolve (plano 177-03). `publicacoes: null` é de propósito — o acervo pode
//     nunca ter sido coletado, e nesse caso a pílula não é desenhada; `null`
//     NUNCA vira `0` na tela.
const cabecalhoBase = (o = {}) => ({
    aba: 'produtos',
    empresa: {
        chave: 'company-459',
        nome: 'Dev 02 Testes API',
        identificador: '459',
        programa: 'polos',
        programa_rotulo: 'Polos',
        token: 'ativo',
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-12T10:00:00+00:00' },
    },
    company_id: 459,
    contadores: { produtos: 3, publicacoes: null, publicacoes_pedem_acao: null, alavancas: 2 },
    integracoes: {
        mercado_livre: { token: 'ativo' },
        erp: { declarado: null },
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-12T10:00:00+00:00' },
    },
    ...o,
});

const empresaBase = () => ({
    chave: 'company-459',
    nome: 'Dev 02 Testes API',
    identificador: '459',
    programa: 'polos',
    programa_rotulo: 'Polos',
    company_id: 459,
    token: 'ativo',
    portal: { situacao: 'sincronizado', novas: 0 },
    link_reconexao: null,
});

const conexoesBase = () => ({
    mercado_livre: { token: 'ativo' },
    publicacao_liberada: true,
    alavancas_liberada: false,
    portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: null },
    erp: { valor: null, rotulo: 'Não informado' },
});

/**
 * As 6 páginas, com o MÍNIMO de props que cada controller manda além do
 * `cabecalho`, e a aba que cada uma marca como ativa.
 */
const PAGINAS = [
    {
        arquivo: 'VisaoGeral.jsx',
        aba: 'visao-geral',
        props: () => ({ empresa: empresaBase(), liberada: true, abas: { company_id: 459 } }),
    },
    {
        arquivo: 'Produtos.jsx',
        aba: 'produtos',
        props: () => ({
            empresa: empresaBase(),
            liberada: true,
            produtos: [],
            contagens: { todos: 0 },
            rascunhos_antigos: { total: 0, url: null },
            criativos_ia: { url: null },
            abas: { company_id: 459 },
            fila_publicacao: null,
        }),
    },
    {
        arquivo: 'Produto.jsx',
        aba: 'produtos',
        props: () => ({
            empresa: empresaBase(),
            liberada: true,
            produto: { id: 1, sku: 'ABC-01', nome: 'Puff Redondo' },
            abas: { company_id: 459 },
        }),
    },
    {
        arquivo: 'Alavancas.jsx',
        aba: 'alavancas',
        props: () => ({
            empresa: empresaBase(),
            alavancas: { liberada: true, tem_conta: true, tarefas_abertas: 2, item: null, aba: 'promocoes' },
        }),
    },
    {
        arquivo: 'Configuracoes.jsx',
        aba: 'configuracoes',
        props: () => ({
            empresa: empresaBase(),
            conexoes: conexoesBase(),
            programa: 'polos',
            responsavel: 'Fulano da Silva',
        }),
    },
    // ⚠️⚠️ Fase 187, plano 04 — A FILA "Aguardando aprovação".
    //
    // ⚠️ ELA ENTRA AQUI PORQUE ESTE GATE A COBROU, e isso é o comportamento
    // CERTO: o teste `as 6 páginas do Publicador estão todas aqui (nenhuma entra
    // calada)` faz `deepEqual` da pasta contra esta tabela, e uma página nova
    // reprova até alguém decidir, à mão, que ela monta o header. O plano 187-04
    // não previa este arquivo; o gate alheio reprovou por um motivo LEGÍTIMO, e a
    // declaração é a resposta — não o afrouxamento do gate.
    //
    // ⚠️ `aba: 'publicacoes'` é DECISÃO do plano 187-02:
    // `CabecalhoPublicadorService::ABAS` é lista FECHADA de cinco e a fila é uma
    // aba INTERNA da tela de Publicações (as duas abas do protótipo vivem dentro
    // do MESMO header). Mandar `'aprovacao'` faria o header global perder o
    // destaque de Publicações.
    //
    // ⚠️ E é aqui que a página da fila ganha RENDER REAL com `cabecalho` adverso
    // (objeto/array/nulo/ausente em cada campo) — o gate do próprio plano prova a
    // fonte e os dois COMPONENTES, e este prova a PÁGINA montada.
    {
        arquivo: 'Aprovacao.jsx',
        aba: 'publicacoes',
        props: () => ({
            empresa: { id: 459, nome: 'Dev 02 Testes API' },
            contador_da_aba: 0,
            // As 9 entradas como o servidor as manda (`FilaDeAprovacaoService::faixa`).
            faixa: [
                { chave: 'todas', rotulo: 'Todas', total: 0 },
                { chave: 'preparando', rotulo: 'Preparando', total: 0 },
                { chave: 'aguardando_aprovacao', rotulo: 'Aguardando aprovação', total: 0 },
                { chave: 'em_revisao_ecf', rotulo: 'Em revisão pela ECF', total: 0 },
                { chave: 'ajustes_solicitados', rotulo: 'Ajustes solicitados', total: 0 },
                { chave: 'aprovado', rotulo: 'Aprovado', total: 0 },
                { chave: 'publicando', rotulo: 'Publicando', total: 0 },
                { chave: 'falha', rotulo: 'Falha na publicação', total: 0 },
                { chave: 'publicado', rotulo: 'Publicado hoje', total: 0 },
            ],
            margem_minima: { valor: 12.0, origem: 'config' },
            etapa: 'todas',
        }),
    },
    {
        arquivo: 'PublicacaoEmLote.jsx',
        aba: 'produtos',
        props: () => ({
            empresa: empresaBase(),
            liberada: true,
            abas: { company_id: 459, alavancas_pendentes: 0 },
            linhas: [],
            fila: null,
            garantia_padrao: null,
            selecionados: [],
            config: { por_rodada_padrao: 5, por_rodada_maximo: 10, teto_por_minuto: 2, intervalo_padrao: 20, intervalo_minimo: 2, intervalo_maximo: 240, conferir_max: 100, polling_s: 10 },
        }),
    },
];

// ⚠️ Todos os 6 bundles AGORA, antes do primeiro `test()`.
const MODULOS = new Map();
for (const { arquivo } of PAGINAS) {
    MODULOS.set(arquivo, await montar(arquivo));
}

/** Renderiza a página e devolve o HTML — sem engolir exceção nenhuma. */
function render(arquivo, props) {
    const { default: Pagina } = MODULOS.get(arquivo);
    assert.ok(Pagina, `${arquivo} não exporta um default`);

    return renderToStaticMarkup(React.createElement(Pagina, props));
}

/** Só o trecho do `<header>` — asserção de header não deve casar no corpo. */
function soOHeader(html) {
    const fim = html.indexOf('</header>');
    assert.ok(fim !== -1, 'o <header> do PublicadorHeader não foi renderizado');

    return html.slice(0, fim);
}

for (const { arquivo, aba, props } of PAGINAS) {
    test(`${arquivo} — monta com o header global nos 3 estados de \`cabecalho\``, async (contexto) => {
        await contexto.test('cabecalho OBJETO: header no topo, identidade e aba ativa certas', () => {
            let html;
            assert.doesNotThrow(() => { html = render(arquivo, { ...props(), cabecalho: cabecalhoBase({ aba }) }); });

            // ⚠️ O acidente de 07/10/2026 em uma linha: campo do servidor
            // renderizado cru. Se qualquer campo de `cabecalho` virar texto sem
            // tratamento, aparece aqui.
            assert.doesNotMatch(html, /\[object Object\]/);

            const header = soOHeader(html);
            // O header é o PRIMEIRO elemento da página (HEAD-14): fora do
            // container do corpo, para o `-mx-6 -mt-6` encostar nas bordas.
            // ⚠️ `z-30` desde 12/10/2026 (261012-mbw), NÃO `z-50` — ver
            // `publicador-header-zindex-ordem.test.js`. A regex segue ancorada
            // em `^` (o header é o PRIMEIRO elemento), só o valor mudou.
            assert.match(html, /^<div><header class="sticky top-0 z-30 -mx-6 -mt-6 /);
            // A identidade da conta (nome e chave) — REG-01, não pode se perder.
            assert.match(header, /Dev 02 Testes API/);
            assert.match(header, /company-459/);
            // A aba ativa é a da página, e é UMA só.
            assert.equal((header.match(/aria-current="page"/g) ?? []).length, aba === 'configuracoes' ? 0 : 1);
            // As quatro abas estão lá (HEAD-09: são quatro de propósito).
            for (const rotulo of ['Visão geral', 'Produtos', 'Publicações', 'Alavancas']) {
                assert.ok(header.includes(rotulo), `aba ausente no header: ${rotulo}`);
            }
            // Os contadores do servidor: produtos 3 e alavancas 2.
            assert.match(header, />3</);
            assert.match(header, />2</);
            // ⚠️ `publicacoes: null` NÃO desenha pílula e NUNCA escreve "0":
            // zero diria "não há", quando o certo é "não sabemos".
            //
            // ⚠️⚠️ REAPONTE DA FASE 187, PLANO 04, COM O VETO ESCRITO — a sonda
            // antiga reprovava o código CERTO. Ela exigia que NADA seguisse o
            // literal "Publicações", e isso valia por acidente: nenhuma das 6
            // páginas tinha a aba **Publicações** como ATIVA. A fila
            // (`Aprovacao.jsx`, `aba: 'publicacoes'`) tem — e a aba ativa ganha o
            // SUBLINHADO amarelo, que é um `<span aria-hidden="true">` VAZIO
            // logo depois do rótulo. A sonda antiga o confundia com a pílula.
            //
            // ⚠️ O invariante NÃO foi afrouxado, ficou mais preciso: o que não
            // pode aparecer é PÍLULA DE CONTADOR — `<span>` com TEXTO. O
            // sublinhado é `aria-hidden` e vazio, e por isso é aceito
            // explicitamente. Uma pílula com "0" (ou com qualquer número)
            // continua reprovando, e há asserção abaixo provando isso.
            const publicacoes = header.match(/Publicações((?:<span[^>]*>[^<]*<\/span>)?)/);
            assert.ok(publicacoes, 'aba Publicações não encontrada');
            const seguinteDePublicacoes = publicacoes[1] ?? '';
            const ehSublinhadoDaAbaAtiva = /aria-hidden="true"/.test(seguinteDePublicacoes)
                && />\s*<\/span>$/.test(seguinteDePublicacoes);
            assert.ok(
                seguinteDePublicacoes === '' || ehSublinhadoDaAbaAtiva,
                `pílula desenhada com contador nulo: ${publicacoes[0]}`,
            );
            // ⚠️ ISCA DA SONDA, nas duas direções: ela ACEITA o sublinhado e
            // RECUSA a pílula. Sem isto o reaponte poderia ter virado "aceita
            // qualquer coisa depois de Publicações".
            const sublinhado = '<span aria-hidden="true" class="absolute bottom-[-7px] bg-pub-accent"></span>';
            const pilula = '<span class="grid tabular-nums">0</span>';
            const sonda = (depois) => {
                const m = `Publicações${depois}`.match(/Publicações((?:<span[^>]*>[^<]*<\/span>)?)/);
                const g = m[1] ?? '';
                return g === '' || (/aria-hidden="true"/.test(g) && />\s*<\/span>$/.test(g));
            };
            assert.ok(sonda(sublinhado), 'ISCA: a sonda recusa o sublinhado da aba ATIVA');
            assert.ok(!sonda(pilula), 'ISCA: a sonda ACEITA uma pílula de contador — ela deixou de medir');
        });

        await contexto.test('cabecalho NULO não derruba a tela (o default de parâmetro não cobre null)', () => {
            let html;
            assert.doesNotThrow(() => { html = render(arquivo, { ...props(), cabecalho: null }); });
            assert.doesNotMatch(html, /\[object Object\]/);
            // E o header continua existindo, só sem conta: nada aparece vazio
            // nem com "0" — o que não tem destino fica desabilitado dizendo por quê.
            assert.match(soOHeader(html), /Nenhuma empresa aberta/);
        });

        await contexto.test('cabecalho AUSENTE não derruba a tela', () => {
            let html;
            assert.doesNotThrow(() => { html = render(arquivo, props()); });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.match(soOHeader(html), /Nenhuma empresa aberta/);
        });

        await contexto.test('CADA campo de cabecalho como objeto/array/nulo/ausente', () => {
            // ⚠️ Esta é a forma EXATA do acidente de 07/10/2026: campo do
            // presenter chegando num tipo que ninguém exercitou.
            for (const campo of ['aba', 'empresa', 'company_id', 'contadores', 'integracoes']) {
                for (const valor of [{ foo: 'bar' }, ['foo'], null, undefined]) {
                    let html;
                    assert.doesNotThrow(
                        () => { html = render(arquivo, { ...props(), cabecalho: cabecalhoBase({ [campo]: valor }) }); },
                        `${arquivo}: cabecalho.${campo} = ${JSON.stringify(valor)} derrubou a tela`,
                    );
                    assert.doesNotMatch(html, /\[object Object\]/, `${arquivo}: cabecalho.${campo} = ${JSON.stringify(valor)}`);
                    assert.doesNotMatch(html, /\bfoo\b/, `${arquivo}: cabecalho.${campo} = ${JSON.stringify(valor)} vazou o conteúdo cru`);
                }
            }
        });

        await contexto.test('conta travada: o selo do header aparece e o corpo não perde o seu aviso', () => {
            // ⚠️ `AvisoContaTravada` aparece em DOIS lugares e isso é de hoje,
            // não invenção da troca: o selo na linha 1 do header e a faixa no
            // corpo. As duas já conviviam antes (REG-01).
            const base = props();
            const travada = 'alavancas' in base
                ? { ...base, alavancas: { ...base.alavancas, liberada: false }, cabecalho: cabecalhoBase({ aba }) }
                : { ...base, liberada: false, cabecalho: cabecalhoBase({ aba }) };

            let html;
            assert.doesNotThrow(() => { html = render(arquivo, travada); });
            assert.doesNotMatch(html, /\[object Object\]/);
            // Configurações monta com `liberada` fixo (a tela é só leitura), e
            // Alavancas tem o aviso próprio dela — nas outras, o selo do header
            // é obrigatório.
            if (arquivo !== 'Configuracoes.jsx' && arquivo !== 'Alavancas.jsx') {
                assert.match(soOHeader(html), /nota-conta-travada|Publicação ainda não liberada|ainda não foi liberada/i);
            }
        });
    });
}

test('⚠️ as 6 páginas do Publicador estão todas aqui (nenhuma entra calada)', () => {
    // Sem isto, acrescentar uma página por conta e esquecer de testá-la passaria
    // por omissão — foi o que aconteceu com `Produto.jsx`, que ficou sem
    // nenhum teste de render desde a Fase 175.
    const naPasta = fs.readdirSync(path.resolve(RAIZ, DIR_PAGINAS))
        .filter((nome) => nome.endsWith('.jsx'))
        .sort();
    const cobertas = PAGINAS.map((p) => p.arquivo).sort();
    // `Editor.jsx` tem casca própria de etapas e `Tarefas.jsx` é a fila de
    // TODAS as contas (sem conta aberta) — nem uma nem outra monta o header.
    assert.deepEqual(naPasta, [...cobertas, 'Editor.jsx', 'Tarefas.jsx'].sort());
});
