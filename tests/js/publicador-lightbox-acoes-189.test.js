import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 189, plano 07 — AS AÇÕES DA FOTO DENTRO DO LIGHTBOX, COM O CONTEXTO DELE.
//
// ⚠️⚠️ **A DECISÃO QUE ESTE ARQUIVO EXISTE PARA TRAVAR — PERGUNTA-189-B (b),
// usuário, 19/10/2026:** com o lightbox aberto, ↑/↓ continuam navegando a fila
// por baixo. ➡️ "Aprovar foto" e "Reprovar" usam a oferta, a VERSÃO e a foto que
// o lightbox capturou na ABERTURA — **nunca** as do painel por baixo. Quem abre
// na oferta A e desce para a B aprovaria a foto da A **com a versão da B**: no
// melhor caso o servidor recusa (422 de `versao_esperada`); no pior, uma regra de
// versão mal casada passa — e **aprovar ENFILEIRA, sem desfazer**.
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DELE EXISTIR: "o que precisaria
// estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. FONTE       → ler a oferta/versão do PAINEL em vez do CONTEXTO (T-189-50).
//                   A fixture **DISCRIMINA**: oferta A versão 3 com 4 imagens ×
//                   oferta B versão 7 com 2 imagens, e a asserção é por
//                   **IGUALDADE campo a campo**, nunca `includes`. ⚠️ "Uma oferta
//                   só não distingue nada" — lição da 187-06, e é por isso que a
//                   isca refresca o contexto da B À FORÇA e cobra os dados da B.
//  2. FOTO        → agir sobre a foto do PAINEL em vez da do lightbox
//                   (T-189-51). A 3ª foto da A **não existe** na B (ela tem 2).
//  3. SEGUNDA VIA → remontar a rota/`parametros` aqui seria a SEGUNDA CÓPIA da
//                   escrita que `escritasDaColuna` já faz (régua §2). O gate
//                   cobra que a contagem de `router.post(` **não subiu** e que a
//                   escrita passa pelo helper ÚNICO.
//  4. EMPILHAMENTO→ o modal de reprovar ficar ATRÁS do lightbox: `z-index` **não
//                   escapa** de um contexto de empilhamento, e o `<aside>`
//                   `fixed z-[40]` CRIA um. A prova é por **ÁRVORE DE
//                   ANCESTRAIS**, com isca nas DUAS direções (casca DENTRO do
//                   `<aside>` reprova; IRMÃ passa).
//  5. BOTÃO       → **o achado do plan-checker de 19/10/2026.** O 189-06 deixou
//                   `onAprovarFoto`/`onReprovar` em `null`, e `podeAgirNaFoto` só
//                   desenha com handler FUNÇÃO: um plano que só provasse funções
//                   puras passaria inteiro **com o botão nunca nascendo**. ⚠️ E o
//                   lightbox é PORTAL — o renderizador de SERVIDOR do React **não
//                   desenha portal**, então o teste do composto passaria por
//                   OMISSÃO. ➡️ O fio atravessa `propsDaLateralDoLightbox`, e o
//                   gate renderiza a lateral REAL com essas props.
//  6. CLIQUE      → `renderToStaticMarkup` **não dispara evento nenhum**. Os
//                   handlers são chamados À MÃO, e com argumento ADVERSO: eles
//                   leem a foto do CONTEXTO, não do que a lateral lhes passa.
//  7. ESCAPE      → o `Escape` com modal sobre o lightbox fechar o lightbox (ou
//                   o painel) junto. Uma asserção por camada.
//  8. D2          → o lightbox ficar aberto com contexto VELHO depois da ação
//                   (T-189-54): a foto aprovada apareceria "A conferir", **com
//                   botões**, e o segundo clique tentaria aprovar o aprovado.
//  9. ADVERSO     → a TELA PRETA voltou SEIS vezes nesta milestone, a última **NO
//                   CLIQUE**: `String(valor)`, `hasOwnProperty` e qualquer
//                   `obj[chave]` **LANÇAM** com `{toString: null}`; `{a = null} =
//                   {}` só cobre `undefined`, **NÃO `null`**; e `id = 0` passa
//                   pela guarda de inteiro.
// 10. TERRITÓRIO  → "confirmar publicação", "segundo passo" ou a Fase 192
//                   antecipados. Gate de ausência **recortado à lista CONSTANTE**
//                   dos arquivos desta fase — varrer largo já BARROU UM DEPLOY
//                   neste projeto (`lucro_liquido` é campo legítimo do Portal).
// 11. TDZ         → **ACHADO HERDADO desta wave, com VETO contra o quinto.** O
//                   corpo do componente da página tem QUATRO leituras EAGER de
//                   `const` ainda não inicializada (TDZ → `ReferenceError` →
//                   TELA PRETA). Elas são das waves 188-08/09/12 e **não são
//                   desta fase consertar**; o gate é SUBCONJUNTO, então ele
//                   ACUSA um quinto e **fica verde quando as quatro forem
//                   consertadas** — âncora em número que muda é frágil, e gate
//                   que reprova pelo próprio conserto treina a ajustar número.
// 12. MANIFESTO   → `vocabulario-tamanhos` fora de LINHA PRÓPRIA ou sem a forma
//                   `>=`; id órfão; bijeção id ↔ `<verify>`.
// 13. FANTASMA    → caminho citado que não existe: sonda de AUSÊNCIA sobre
//                   arquivo inexistente passa CALADA. São DEZ fantasmas nesta
//                   milestone, TRÊS vindos de briefing.
// 14. CSS         → classe que o scanner do Tailwind não vê. ⚠️ A sonda de
//                   AUSÊNCIA de classe é feita na FONTE **SEM COMENTÁRIO**, nunca
//                   no CSS: o scanner LÊ COMENTÁRIO, e classe citada em docblock
//                   já virou regra de CSS real neste projeto.
//
// ⚠️⚠️ O QUE ESTE ARQUIVO **NÃO PROVA**: não há navegador nesta máquina, o
// `mysqld` local está PARADO, o banco não tem empresa com `ml_token` (a rota dá
// **404**), `pub_aprovacao_ofertas` está **VAZIA** e a fila só ganha oferta na
// Fase 194. *"A tela mostra X"* aqui significa SEMPRE *"o HTML gerado contém X"*.
// ⚠️ **O despacho real do `router.post` não é provado** — o que está provado é que
// a escrita atravessa o helper ÚNICO com a oferta e a versão certas. ⚠️ O
// `focus()` é provado com STUB que conta chamadas, nunca com foco de verdade.
// ⚠️ **A anexação de `ref`, a animação e o portal portando continuam NÃO
// PROVADOS**, e isso é declarado em vez de escondido.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

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
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: [
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@inertiajs/react',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(
        __dirname,
        `.pub-lbacoes189-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_LATERAL = `${DIR_APROV}/LateralDoLightbox.jsx`;
const REL_LIGHTBOX = `${DIR_APROV}/LightboxDaImagem.jsx`;
const REL_MODAL = `${DIR_APROV}/ModalDeReprovarImagem.jsx`;
const REL_CASCA = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_COLUNA = `${DIR_APROV}/ColunaDeFotos.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO_LB = 'app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php';
const REL_MANIFESTO = 'tests/gates/contagens-189-07.txt';
const REL_MANIFESTO_188_12 = 'tests/gates/contagens-188-12.txt';
const REL_REGUA = '.planning/phases/189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso/189-REGUA.md';
const REL_CSS_CONSTRUIDO = 'public/build';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. Sonda de AUSÊNCIA sobre
// arquivo inexistente passa CALADA, e foi assim que DEZ caminhos "provaram"
// território intocado medindo NADA.
// ⚠️ O protótipo fica FORA desta lista de propósito: ele **não é versionado**
// (D-189-2) e o bloco que depende dele degrada para `skip` EXPLÍCITO e NOMEADO.
const CAMINHOS_CITADOS = [
    DIR_APROV, REL_PAINEL, REL_LATERAL, REL_LIGHTBOX, REL_MODAL, REL_CASCA,
    REL_COLUNA, REL_PAGINA, REL_SERVICO_LB, REL_MANIFESTO, REL_MANIFESTO_188_12,
    REL_REGUA,
];

/** ⚠️ A ISCA do anti-fantasma: tem de continuar AUSENTE (`189-REGUA.md` §9 (c)). */
const ISCA_AUSENTE = 'resources/js/Pages/Mlb/NaoExiste9z7.jsx';

const FONTE_PAINEL = lerSemComentarios(REL_PAINEL);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const FONTE_LATERAL = lerSemComentarios(REL_LATERAL);
const FONTE_LIGHTBOX = lerSemComentarios(REL_LIGHTBOX);
const FONTE_MODAL = lerSemComentarios(REL_MODAL);
const FONTE_SERVICO_LB = lerSemComentarios(REL_SERVICO_LB);

/** O CRU, para as iscas que provam que a leitura SEM comentário não é cega. */
const CRU_PAINEL = fs.readFileSync(path.resolve(RAIZ, REL_PAINEL), 'utf8');
const CRU_PAGINA = fs.readFileSync(path.resolve(RAIZ, REL_PAGINA), 'utf8');

const TEXTO_MANIFESTO = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
const TEXTO_MANIFESTO_188_12 = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO_188_12), 'utf8');
const TEXTO_REGUA = fs.readFileSync(path.resolve(RAIZ, REL_REGUA), 'utf8');

const TEM_PROTOTIPO = fs.existsSync(path.resolve(RAIZ, PROTOTIPO));
const LINHAS_PROTOTIPO = TEM_PROTOTIPO
    ? fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8').split(/\r?\n/)
    : [];

// ⚠️⚠️ TODOS os bundles ANTES do primeiro `test()` — o `after()` do `node --test`
// já matou um arquivo inteiro **sem nenhum teste falhar**. E `await` dentro de
// callback síncrono é `SyntaxError`: o arquivo nem CARREGA.
const PAINEL = await montar(REL_PAINEL);
const LATERAL = await montar(REL_LATERAL);
const MODAL = await montar(REL_MODAL);

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));
const nada = () => {};

/** Conta OCORRÊNCIAS (não linhas: `grep -c` já deu 12 para uma só neste projeto). */
function ocorrencias(texto, agulha) {
    let n = 0;
    let i = texto.indexOf(agulha);
    while (i !== -1) {
        n += 1;
        i = texto.indexOf(agulha, i + agulha.length);
    }
    return n;
}

/**
 * A árvore do HTML **com ANCESTRAIS** — e é disso que o contexto de empilhamento
 * precisa.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)` nem `els[0]`**: os dois pegam o IRMÃO
 * ERRADO quando duas tags têm a mesma string de classes — **quatro gates deste
 * projeto estavam medindo o irmão errado**. Aqui cada elemento carrega a PILHA de
 * ancestrais, por índice ABSOLUTO.
 */
function arvoreComAncestrais(html) {
    const itens = [];
    const pilha = [];
    const re = /<(\/?)(div|span|button|aside|section|p|textarea|label|img|svg)\b([^>]*?)(\/?)>/g;
    let m = re.exec(html);
    while (m !== null) {
        const fechando = m[1] === '/';
        const autofechada = m[4] === '/' || m[2] === 'img';
        if (fechando) {
            pilha.pop();
        } else {
            const item = {
                indice: itens.length,
                tag: m[2],
                profundidade: pilha.length,
                atributos: m[3],
                classe: (m[3].match(/class="([^"]*)"/) || [])[1] ?? '',
                ancestrais: [...pilha],
            };
            itens.push(item);
            if (!autofechada) pilha.push(item.indice);
        }
        m = re.exec(html);
    }
    return itens;
}

/** As classes de um elemento como CONJUNTO — igualdade de TOKEN, nunca `includes`. */
const classesDe = (elemento) => new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));

/** Quem tem o token exato `z-[NN]` — `includes` casaria `z-[4]` em `z-[42]`. */
const comToken = (itens, token) => itens.filter((e) => classesDe(e).has(token));

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ A FIXTURE QUE DISCRIMINA — e ela discrimina em QUATRO campos
// ═══════════════════════════════════════════════════════════════════════════
//
// Oferta **A**: id `501`, versão **3**, **4** imagens, `ao_vivo: true`.
// Oferta **B**: id `777`, versão **7**, **2** imagens, `ao_vivo: false`.
// ⚠️ A 3ª foto (índice 2) **só existe na A** — se o código lesse a galeria do
// painel depois de um ↓, o índice 2 cairia fora da lista da B. **A fixture
// mataria a mutação sozinha**, e é por isso que ela é assim.

const VERIFICACOES = [
    { severidade: 'alerta', texto: 'Texto pequeno na lateral', glifo: '!', cor: 'ambar', ordinal: 1 },
];

/** Uma imagem de revisão com a forma REAL de `FotosDoPainelService::para()`. */
function imagemDe(id, posicao, extra = {}) {
    return {
        id,
        imagem_id: 9000 + id,
        posicao,
        posicao_rotulo: `#${posicao}`,
        rotulo: `Foto ${posicao}`,
        titulo: `Foto ${posicao} — da fixture`,
        de_ia: true,
        tipo_rotulo: 'Gerada por IA',
        principal: posicao === 1,
        grupo: 'COLOR=id:52049',
        grupo_geral: null,
        estado: 'pending',
        estado_rotulo: 'A conferir',
        estado_cor: 'ambar',
        estado_dot: 'ambar',
        com_alerta: true,
        aprovada: false,
        aceita_acao: true,
        borda: 'neutra',
        anel: false,
        url_arquivo: `/fotos/${9000 + id}/arquivo`,
        marcadores: [],
        verificacoes: VERIFICACOES,
        ...extra,
    };
}

const IMAGENS_A = [
    imagemDe(9101, 1),
    imagemDe(9102, 2),
    imagemDe(9103, 3),
    imagemDe(9104, 4),
];
const IMAGENS_B = [imagemDe(8201, 1), imagemDe(8202, 2)];

const PAINEL_A = {
    oferta_id: 501,
    versao: 3,
    fotos: { imagens: IMAGENS_A, com_alerta_restantes: 1 },
    rodape: { botoes: { salvar: true } },
};

const PAINEL_B = {
    oferta_id: 777,
    versao: 7,
    fotos: { imagens: IMAGENS_B, com_alerta_restantes: 0 },
    rodape: { botoes: { salvar: false } },
};

const LIGHTBOX_PAYLOAD = { originais: { 9103: { url: '/fotos/1/arquivo', rotulo: 'Original' } } };

/** O contexto CAPTURADO na abertura, na oferta A — pela função do 189-06. */
const CONTEXTO_A = PAINEL.contextoDoLightbox(null, PAINEL_A, LIGHTBOX_PAYLOAD);

/** E o que sobra DEPOIS do ↓ para a B: o contexto da A, INTACTO (189-B (b)). */
const CONTEXTO_DEPOIS_DO_DESCE = PAINEL.contextoDoLightbox(CONTEXTO_A, PAINEL_B, LIGHTBOX_PAYLOAD);

/** ⚠️ A ISCA: o contexto refrescado da B **À FORÇA** (abertura na B). */
const CONTEXTO_B = PAINEL.contextoDoLightbox(null, PAINEL_B, LIGHTBOX_PAYLOAD);

/** Um espião de escrita: guarda TODA chamada e devolve `true` (escrita disparada). */
function espiao(retorno = true) {
    const chamadas = [];
    const fn = (pedido) => {
        chamadas.push(pedido);
        return retorno;
    };
    fn.chamadas = chamadas;
    return fn;
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. O ANTI-FANTASMA E A ENTRADA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ENTRADA: todo caminho citado EXISTE, a isca continua AUSENTE e a 189-B está RESPONDIDA 189_07', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((c) => !fs.existsSync(path.resolve(RAIZ, c)));
    assert.deepEqual(fantasmas, [], 'caminho citado que não existe — sonda de ausência sobre ele passa CALADA');

    assert.equal(
        fs.existsSync(path.resolve(RAIZ, ISCA_AUSENTE)),
        false,
        'a isca do anti-fantasma PASSOU A EXISTIR — ela precisa continuar ausente para provar que o `test -e` enxerga',
    );

    // ⚠️ A régua §6 manda conferir a linha da pergunta por `grep` na primeira ação
    // e PARAR se ela não estiver RESPONDIDA. Aqui ela é cobrada como gate.
    assert.equal(ocorrencias(TEXTO_REGUA, 'PERGUNTA-189-B: RESPONDIDA'), 1, 'a PERGUNTA-189-B deixou de estar RESPONDIDA');
    assert.equal(
        ocorrencias(TEXTO_REGUA, 'PERGUNTA-189-D: ABERTA, NÃO BLOQUEIA'),
        1,
        'a PERGUNTA-189-D mudou de estado — o D2 deste plano é um DEFAULT de pergunta ABERTA',
    );

    // ⚠️ Fontes lidas vazias fariam TODA sonda de ausência passar calada.
    const curtas = [
        [REL_PAINEL, FONTE_PAINEL], [REL_PAGINA, FONTE_PAGINA], [REL_LATERAL, FONTE_LATERAL],
        [REL_LIGHTBOX, FONTE_LIGHTBOX], [REL_MODAL, FONTE_MODAL], [REL_SERVICO_LB, FONTE_SERVICO_LB],
    ].filter(([, f]) => f.trim().length < 500).map(([rel]) => rel);
    assert.deepEqual(curtas, [], 'fonte lida veio vazia/curta — a leitura está quebrada');
});

test('⚠️ ENTRADA: os TRÊS named exports deste plano existem e são FUNÇÕES 189_07', () => {
    for (const nome of ['acaoDoLightbox', 'depoisDaAcaoNoLightbox', 'propsDaLateralDoLightbox']) {
        assert.equal(typeof PAINEL[nome], 'function', `\`${nome}\` não é named export FUNÇÃO de PainelDeRevisao.jsx`);
    }

    // ⚠️ E a FONTE declara os três numa linha de `export` cada — é o que o critério
    // de aceitação do plano conta por `grep -cE`.
    const casadas = (FONTE_PAINEL.match(/^export (function|const) (acaoDoLightbox|depoisDaAcaoNoLightbox|propsDaLateralDoLightbox)\b/gm) || []);
    assert.equal(casadas.length, 3, `as tres linhas de export dos nomes do plano nao estao todas la (achei ${casadas.length})`);

    // ⚠️ A lista de tipos é FECHADA: um terceiro tipo não escreve.
    assert.deepEqual(
        Object.keys(PAINEL.TIPOS_DA_ACAO_DO_LIGHTBOX).sort(),
        ['APROVAR', 'REPROVAR'],
        'a lista de tipos de ação do lightbox deixou de ser FECHADA nos dois',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. ⚠️⚠️ A FONTE DA OFERTA, DA VERSÃO E DA FOTO — A FIXTURE A v3 × B v7
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ FONTE: abrir na A (v3), descer para a B (v7) e aprovar a 3ª foto escreve com A/3/9103 189_07', () => {
    // ⚠️ Pré-condição MEDIDA, não presumida: o contexto depois do ↓ é o da A.
    assert.equal(CONTEXTO_DEPOIS_DO_DESCE.oferta_id, 501, 'o contexto se refrescou para a B — o 189-06 regrediu');
    assert.equal(CONTEXTO_DEPOIS_DO_DESCE.versao, 3, 'a VERSÃO do contexto virou a da B');
    assert.equal(CONTEXTO_DEPOIS_DO_DESCE.imagens.length, 4, 'as imagens do contexto viraram as da B');

    const escrever = espiao();
    const disparou = PAINEL.acaoDoLightbox({
        tipo: PAINEL.TIPOS_DA_ACAO_DO_LIGHTBOX.APROVAR,
        contexto: CONTEXTO_DEPOIS_DO_DESCE,
        indice: 2,
        escrever,
    });

    assert.equal(disparou, true, 'a ação não foi disparada');
    assert.equal(escrever.chamadas.length, 1, 'a escrita foi chamada mais de uma vez (ou nenhuma)');

    const pedido = escrever.chamadas[0];

    // ⚠️⚠️ IGUALDADE CAMPO A CAMPO — nunca `includes`, que casa PREFIXO e já mordeu
    // CINCO vezes neste projeto.
    assert.equal(pedido.tipo, 'aprovar', 'o tipo da ação não é "aprovar"');
    assert.equal(pedido.oferta, 501, '⚠️⚠️ A OFERTA SAIU DO PAINEL (777/B) em vez do CONTEXTO (501/A) — T-189-50');
    assert.equal(pedido.versao, 3, '⚠️⚠️ A VERSÃO SAIU DO PAINEL (7/B) em vez do CONTEXTO (3/A) — T-189-50');
    assert.equal(pedido.imagemId, 9103, '⚠️⚠️ A FOTO não é a 3ª da A — T-189-51');
    assert.equal(pedido.imagem, IMAGENS_A[2], 'a imagem entregue não é, por IDENTIDADE, a 3ª entrada da galeria da A');

    // ⚠️ E nada da B vazou: nem o id, nem a versão, nem uma imagem dela.
    assert.notEqual(pedido.oferta, 777, 'o id da oferta B vazou para a escrita');
    assert.notEqual(pedido.versao, 7, 'a versão da oferta B vazou para a escrita');
    for (const daB of IMAGENS_B) {
        assert.notEqual(pedido.imagemId, daB.id, `a imagem ${daB.id} (da B) vazou para a escrita`);
    }
});

test('⚠️⚠️ FONTE — A ISCA: o contexto refrescado da B À FORÇA dá os dados da B (a sonda DISTINGUE) 189_07', () => {
    // ⚠️⚠️ Sem esta isca, o caso acima poderia estar passando porque a função
    // IGNORA o contexto e tem 501/3 hardcoded em algum lugar. Aqui o MESMO
    // caminho, com o contexto da B, tem de dar os dados DA B.
    const escrever = espiao();
    const disparou = PAINEL.acaoDoLightbox({
        tipo: PAINEL.TIPOS_DA_ACAO_DO_LIGHTBOX.APROVAR,
        contexto: CONTEXTO_B,
        indice: 1,
        escrever,
    });

    assert.equal(disparou, true, 'a ação não foi disparada com o contexto da B');
    assert.equal(escrever.chamadas[0].oferta, 777, 'a sonda NÃO distingue as duas fontes — ela devolveu a A com o contexto da B');
    assert.equal(escrever.chamadas[0].versao, 7, 'idem para a versão');
    assert.equal(escrever.chamadas[0].imagemId, 8202, 'idem para a imagem');

    // ⚠️⚠️ E a 3ª foto NÃO EXISTE na B: índice fora da lista **não escreve**.
    const semTerceira = espiao();
    assert.equal(
        PAINEL.acaoDoLightbox({ tipo: 'aprovar', contexto: CONTEXTO_B, indice: 2, escrever: semTerceira }),
        false,
        'índice 2 numa galeria de 2 imagens disparou escrita',
    );
    assert.equal(semTerceira.chamadas.length, 0, 'escrita disparada com índice fora da lista');
});

test('⚠️⚠️ FONTE: com A = B (sem ter navegado) a ação usa a versão REFRESCADA do payload 189_07', () => {
    // O painel voltou com a MESMA oferta e uma versão NOVA: é o caso normal depois
    // de uma escrita bem-sucedida, e o contexto TEM de acompanhar.
    const PAINEL_A_V9 = { ...PAINEL_A, versao: 9 };
    const refrescado = PAINEL.contextoDoLightbox(CONTEXTO_A, PAINEL_A_V9, LIGHTBOX_PAYLOAD);

    assert.equal(refrescado.oferta_id, 501, 'a oferta mudou sem o painel ter mudado de oferta');
    assert.equal(refrescado.versao, 9, 'o contexto NÃO se refrescou na MESMA oferta — a escrita iria com a versão velha');

    const escrever = espiao();
    PAINEL.acaoDoLightbox({ tipo: 'aprovar', contexto: refrescado, indice: 0, escrever });
    assert.equal(escrever.chamadas[0].versao, 9, 'a ação escreveu com a versão VELHA numa oferta que não mudou');
});

test('⚠️ GUARDAS: nenhuma escrita com `undefined` — contexto, índice, imagem e tipo adversos 189_07', () => {
    const CASOS = [
        ['pedido nulo', null],
        ['pedido não-objeto', 'x'],
        ['sem contexto', { tipo: 'aprovar', indice: 0, escrever: nada }],
        ['contexto nulo', { tipo: 'aprovar', contexto: null, indice: 0, escrever: nada }],
        ['contexto array', { tipo: 'aprovar', contexto: [], indice: 0, escrever: nada }],
        ['contexto sem oferta', { tipo: 'aprovar', contexto: { versao: 3, imagens: IMAGENS_A }, indice: 0, escrever: nada }],
        ['contexto sem versão', { tipo: 'aprovar', contexto: { oferta_id: 501, imagens: IMAGENS_A }, indice: 0, escrever: nada }],
        ['oferta ZERO', { tipo: 'aprovar', contexto: { oferta_id: 0, versao: 3, imagens: IMAGENS_A }, indice: 0, escrever: nada }],
        ['versão ZERO', { tipo: 'aprovar', contexto: { oferta_id: 501, versao: 0, imagens: IMAGENS_A }, indice: 0, escrever: nada }],
        ['oferta string', { tipo: 'aprovar', contexto: { oferta_id: '501', versao: 3, imagens: IMAGENS_A }, indice: 0, escrever: nada }],
        ['imagens não-lista', { tipo: 'aprovar', contexto: { oferta_id: 501, versao: 3, imagens: 'abcd' }, indice: 0, escrever: nada }],
        ['índice negativo', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: -1, escrever: nada }],
        ['índice fora', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: 4, escrever: nada }],
        ['índice NaN', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: NaN, escrever: nada }],
        ['índice string', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: '2', escrever: nada }],
        ['índice nulo', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: null, escrever: nada }],
        ['imagem sem id', { tipo: 'aprovar', contexto: { oferta_id: 501, versao: 3, imagens: [{ rotulo: 'x' }] }, indice: 0, escrever: nada }],
        ['imagem com id ZERO', { tipo: 'aprovar', contexto: { oferta_id: 501, versao: 3, imagens: [{ id: 0 }] }, indice: 0, escrever: nada }],
        ['imagem não-objeto', { tipo: 'aprovar', contexto: { oferta_id: 501, versao: 3, imagens: ['x'] }, indice: 0, escrever: nada }],
        ['tipo desconhecido', { tipo: 'publicar', contexto: CONTEXTO_A, indice: 0, escrever: nada }],
        ['tipo ausente', { contexto: CONTEXTO_A, indice: 0, escrever: nada }],
        ['escrever ausente', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: 0 }],
        ['escrever não-função', { tipo: 'aprovar', contexto: CONTEXTO_A, indice: 0, escrever: 'x' }],
        ['tudo adverso', { tipo: { toString: null }, contexto: { toString: null }, indice: {}, escrever: nada }],
    ];

    for (const [nome, pedido] of CASOS) {
        const escrever = espiao();
        const comEspiao = pedido !== null && typeof pedido === 'object' && typeof pedido.escrever === 'function'
            ? { ...pedido, escrever }
            : pedido;

        assert.doesNotThrow(() => PAINEL.acaoDoLightbox(comEspiao), `\`acaoDoLightbox\` LANÇOU com ${nome}`);
        assert.equal(PAINEL.acaoDoLightbox(comEspiao), false, `\`acaoDoLightbox\` devolveu VERDADE com ${nome}`);
        assert.equal(escrever.chamadas.length, 0, `⚠️⚠️ ESCRITA DISPARADA com ${nome} — aprovar ENFILEIRA e não há volta`);
    }

    // ⚠️ ISCA: o caso BOM continua passando — se ele não passasse, as 23 recusas
    // acima estariam "provando" só que a função devolve `false` sempre.
    const bom = espiao();
    assert.equal(PAINEL.acaoDoLightbox({ tipo: 'aprovar', contexto: CONTEXTO_A, indice: 0, escrever: bom }), true, 'o caso BOM foi recusado — as guardas estão largas demais');
    assert.equal(bom.chamadas.length, 1, 'o caso BOM não escreveu');

    // ⚠️ `escrever` que devolve coisa que não é `true` → a ação NÃO é tida por
    // disparada (senão o degrau do D2 rodaria sobre escrita que não aconteceu).
    // ⚠️⚠️ **FURO MEDIDO NA PRÓPRIA SONDA, e consertado:** a primeira redação usava
    // `espiao(retorno)`, e `espiao` tem **parâmetro com DEFAULT** (`retorno =
    // true`) — então `espiao(undefined)` devolvia `true` e o caso `undefined`
    // acusava a função por um defeito **da sonda**. É a mesma família do
    // `{a = null} = {}` que só cobre `undefined`. ➡️ Aqui o retorno é FIXADO sem
    // default nenhum.
    for (const retorno of [false, undefined, null, 0, 1, 'ok', {}, true]) {
        const devolve = () => retorno;
        assert.equal(
            PAINEL.acaoDoLightbox({ tipo: 'aprovar', contexto: CONTEXTO_A, indice: 0, escrever: devolve }),
            retorno === true,
            `a ação foi tida por disparada com \`escrever\` devolvendo ${retorno === null ? 'null' : typeof retorno}`,
        );
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. ⚠️⚠️ O D2 DA PERGUNTA-189-D — DEFAULT DE PERGUNTA ABERTA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ D2: oferta do contexto ≠ oferta do painel → o lightbox FECHA; igual → fica aberto 189_07', () => {
    // ⚠️ O caso que o D2 existe para resolver: a pessoa desceu para a B e agiu na
    // foto da A. O contexto da A ficaria VELHO (T-189-54).
    const mudou = PAINEL.depoisDaAcaoNoLightbox({ contexto: CONTEXTO_DEPOIS_DO_DESCE, painelAtual: PAINEL_B });
    assert.equal(mudou.fechar, true, '⚠️⚠️ o lightbox NÃO fecha com o painel em outra oferta — o contexto velho mostraria a foto aprovada como pendente, COM BOTÕES');

    const mesma = PAINEL.depoisDaAcaoNoLightbox({ contexto: CONTEXTO_A, painelAtual: PAINEL_A });
    assert.equal(mesma.fechar, false, 'o lightbox fechou com a MESMA oferta — o contexto se refresca sozinho, não há por que fechar');

    // ⚠️⚠️ A ALTERNATIVA (recarregar o painel na A com `?oferta=`) NÃO é o default,
    // e é ESTA asserção que distingue um do outro — mesmo contrato do
    // `voltarParaAOferta` do D1.
    for (const caso of [
        { contexto: CONTEXTO_DEPOIS_DO_DESCE, painelAtual: PAINEL_B },
        { contexto: CONTEXTO_A, painelAtual: PAINEL_A },
    ]) {
        assert.equal(
            PAINEL.depoisDaAcaoNoLightbox(caso).recarregarNaOferta,
            null,
            'o default passou a RECARREGAR o painel na oferta do lightbox — essa é a ALTERNATIVA da 189-D2, não o default',
        );
    }

    // ⚠️ Comparação ESTRITA: `'501'` é OUTRA oferta, nunca "a mesma".
    assert.equal(
        PAINEL.depoisDaAcaoNoLightbox({ contexto: { oferta_id: 501, versao: 3 }, painelAtual: { oferta_id: '501' } }).fechar,
        false,
        'a comparação de oferta coagiu string para inteiro — `idPositivoDoPainel` recusa string, e sem oferta no painel o default NÃO fecha',
    );

    // ⚠️ Sem o que comparar, NÃO fecha: fechar por dado faltando tiraria a pessoa
    // da tela sem motivo.
    for (const [nome, caso] of [
        ['pedido nulo', null],
        ['pedido string', 'x'],
        ['sem contexto', { painelAtual: PAINEL_A }],
        ['sem painel', { contexto: CONTEXTO_A }],
        ['contexto sem oferta', { contexto: { versao: 3 }, painelAtual: PAINEL_A }],
        ['painel sem oferta', { contexto: CONTEXTO_A, painelAtual: { versao: 3 } }],
        ['tudo adverso', { contexto: { toString: null }, painelAtual: [] }],
    ]) {
        assert.doesNotThrow(() => PAINEL.depoisDaAcaoNoLightbox(caso), `o D2 LANÇOU com ${nome}`);
        assert.equal(PAINEL.depoisDaAcaoNoLightbox(caso).fechar, false, `o D2 fechou o lightbox com ${nome}`);
    }

    // ⚠️⚠️ ISCA DO D2 — a sonda enxerga o ramo que fecha? Se o `fechar` fosse
    // `false` SEMPRE, todas as asserções acima passariam e o gate não provaria nada.
    assert.equal(
        PAINEL.depoisDaAcaoNoLightbox({ contexto: { oferta_id: 1 }, painelAtual: { oferta_id: 2 } }).fechar,
        true,
        'a sonda do D2 está CEGA: nem com duas ofertas diferentes ela fecha',
    );
});

test('⚠️⚠️ D2: o DEFAULT está DECLARADO como default de pergunta ABERTA, com a alternativa nomeada 189_07', () => {
    // ⚠️⚠️ Bloco que lê COMENTÁRIO DE PROPÓSITO (a inversão fica declarada): a
    // declaração de que a escolha é um DEFAULT de pergunta ABERTA **é** o
    // artefato. Sem ela, quem vier depois lê a escolha como decidida pelo usuário.
    const EXIGIDOS = [
        'PERGUNTA-189-D',
        'ABERTA',
        'DEFAULT APLICADO',
        'ALTERNATIVA',
        'oferta=A',
    ];
    const faltando = EXIGIDOS.filter((t) => !CRU_PAINEL.toLowerCase().includes(t.toLowerCase()));
    assert.deepEqual(faltando, [], 'a declaração do D2 como DEFAULT de pergunta ABERTA saiu do docblock');

    // ⚠️ ISCA: a sonda enxerga ausência? Um texto sintético sem os termos é acusado.
    const cegas = EXIGIDOS.filter((t) => 'prosa qualquer sem nada disso'.toLowerCase().includes(t.toLowerCase()));
    assert.deepEqual(cegas, [], 'a sonda da declaração casa texto que NÃO tem os termos — ela está mais larga que o invariante');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. ⚠️⚠️ O BOTÃO NASCE DE VERDADE — `propsDaLateralDoLightbox` + render REAL
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ BOTÃO: com `ao_vivo` e `pending`, a lateral renderizada com ESTAS props MOSTRA Aprovar foto e Reprovar 189_07', () => {
    const escrever = espiao();
    const props = PAINEL.propsDaLateralDoLightbox({
        contexto: CONTEXTO_A, indice: 2, zoom: 1, onFechar: nada, onAlternarZoom: nada, escrever,
    });

    // ⚠️ Os handlers são FUNÇÃO — `podeAgirNaFoto` (189-05) só desenha com função,
    // e é exatamente por isso que o 189-06 não desenhava nada.
    assert.equal(typeof props.onAprovarFoto, 'function', '`onAprovarFoto` não é função — o botão não nasceria (D4)');
    assert.equal(typeof props.onReprovar, 'function', '`onReprovar` não é função — o botão não nasceria (D4)');

    // CASO 1 — `ao_vivo: true` + `pending` + `aceita_acao: true`: OS DOIS NASCEM.
    const html = render(LATERAL.default, props);
    assert.ok(html.length > 500, `a lateral saiu curta demais (${html.length} bytes) — a sonda mediria zero casos`);
    assert.match(html, /Aprovar foto/, '⚠️⚠️ "Aprovar foto" NÃO nasceu com a oferta ao vivo e a foto pendente');
    assert.match(html, /Reprovar/, '⚠️⚠️ "Reprovar" NÃO nasceu com a oferta ao vivo e a foto pendente');

    // CASO 2 — `regenerating` (`aceita_acao: false`): NENHUM dos dois (IMG-09).
    const contextoRegen = {
        ...CONTEXTO_A,
        imagens: [imagemDe(9201, 1, { estado: 'regenerating', aceita_acao: false, url_arquivo: null })],
    };
    const htmlRegen = render(LATERAL.default, PAINEL.propsDaLateralDoLightbox({
        contexto: contextoRegen, indice: 0, zoom: 1, onFechar: nada, onAlternarZoom: nada, escrever,
    }));
    assert.ok(htmlRegen.length > 300, 'a lateral de `regenerating` saiu vazia — a sonda mediria zero casos');
    assert.doesNotMatch(htmlRegen, /Aprovar foto/, '"Aprovar foto" nasceu em `regenerating` — o servidor recusa (422) e a tela estaria oferecendo o que ele nega');
    assert.doesNotMatch(htmlRegen, /Reprovar/, '"Reprovar" nasceu em `regenerating`');

    // CASO 3 — fora do `live` (`ao_vivo: false`): NENHUM dos dois.
    const htmlForaDoAr = render(LATERAL.default, PAINEL.propsDaLateralDoLightbox({
        contexto: { ...CONTEXTO_A, ao_vivo: false }, indice: 2, zoom: 1, onFechar: nada, onAlternarZoom: nada, escrever,
    }));
    assert.ok(htmlForaDoAr.length > 300, 'a lateral fora do `live` saiu vazia — a sonda mediria zero casos');
    assert.doesNotMatch(htmlForaDoAr, /Aprovar foto/, '"Aprovar foto" nasceu numa oferta que NÃO está ao vivo');
    assert.doesNotMatch(htmlForaDoAr, /Reprovar/, '"Reprovar" nasceu numa oferta que NÃO está ao vivo');

    // ⚠️ E o `ao_vivo` que a lateral recebe é o do CONTEXTO, não o do painel.
    assert.equal(props.aoVivo, true, 'o `ao_vivo` da lateral não é o do CONTEXTO');
    assert.equal(
        PAINEL.propsDaLateralDoLightbox({ contexto: CONTEXTO_DEPOIS_DO_DESCE, indice: 0, escrever }).aoVivo,
        true,
        '⚠️ o `ao_vivo` virou o do painel de baixo (a B está FORA do ar) — ele é do contexto capturado na abertura',
    );
});

test('⚠️⚠️ BOTÃO: sem destino os handlers saem `null` — e aí a lateral NÃO desenha 189_07', () => {
    const SEM_DESTINO = [
        ['sem `escrever`', { contexto: CONTEXTO_A, indice: 2 }],
        ['`escrever` não-função', { contexto: CONTEXTO_A, indice: 2, escrever: 'x' }],
        ['contexto nulo', { contexto: null, indice: 2, escrever: nada }],
        ['contexto sem oferta', { contexto: { versao: 3, imagens: IMAGENS_A }, indice: 2, escrever: nada }],
        ['contexto sem versão', { contexto: { oferta_id: 501, imagens: IMAGENS_A }, indice: 2, escrever: nada }],
        ['índice fora', { contexto: CONTEXTO_A, indice: 9, escrever: nada }],
        ['imagem sem id', { contexto: { oferta_id: 501, versao: 3, imagens: [{ rotulo: 'x' }] }, indice: 0, escrever: nada }],
        ['pedido nulo', null],
    ];

    for (const [nome, pedido] of SEM_DESTINO) {
        const props = PAINEL.propsDaLateralDoLightbox(pedido);
        assert.equal(props.onAprovarFoto, null, `\`onAprovarFoto\` não é \`null\` com ${nome} — botão sem destino (D4)`);
        assert.equal(props.onReprovar, null, `\`onReprovar\` não é \`null\` com ${nome}`);

        const html = render(LATERAL.default, props);
        assert.doesNotMatch(html, /Aprovar foto/, `"Aprovar foto" nasceu com ${nome}`);
        assert.doesNotMatch(html, /Reprovar/, `"Reprovar" nasceu com ${nome}`);
    }
});

test('⚠️⚠️ CLIQUE: o handler da lateral escreve com A/3/9103 e IGNORA o argumento que recebe 189_07', () => {
    // ⚠️⚠️ `renderToStaticMarkup` **não dispara `onClick`**, e a TELA PRETA da
    // 187-06 foi **NO CLIQUE**. O handler é chamado À MÃO.
    const escrever = espiao();
    const props = PAINEL.propsDaLateralDoLightbox({
        contexto: CONTEXTO_DEPOIS_DO_DESCE, indice: 2, zoom: 1, escrever,
    });

    // ⚠️ A lateral chama `onAprovarFoto(imagem)` com a imagem que ela desenha. Aqui
    // o argumento é ADVERSO de propósito: a fonte da verdade é o CONTEXTO, e
    // confiar no argumento tornaria o nó desenhado a fonte — que é exatamente o
    // que a PERGUNTA-189-B decide.
    assert.doesNotThrow(() => props.onAprovarFoto({ id: 99999, toString: null }), 'o clique de Aprovar foto LANÇOU com argumento adverso');
    assert.equal(escrever.chamadas.length, 1, 'o clique de Aprovar foto não escreveu');
    assert.equal(escrever.chamadas[0].oferta, 501, 'o clique escreveu com a oferta do painel');
    assert.equal(escrever.chamadas[0].versao, 3, 'o clique escreveu com a versão do painel');
    assert.equal(escrever.chamadas[0].imagemId, 9103, 'o clique escreveu com o id do ARGUMENTO em vez do id do contexto');
    assert.equal(escrever.chamadas[0].tipo, 'aprovar', 'o tipo do clique de Aprovar foto não é "aprovar"');

    assert.doesNotThrow(() => props.onReprovar(null), 'o clique de Reprovar LANÇOU com argumento nulo');
    assert.equal(escrever.chamadas.length, 2, 'o clique de Reprovar não chamou o caminho de escrita');
    assert.equal(escrever.chamadas[1].tipo, 'reprovar', 'o tipo do clique de Reprovar não é "reprovar"');
    assert.equal(escrever.chamadas[1].oferta, 501, 'o Reprovar levou a oferta do painel');
    assert.equal(escrever.chamadas[1].versao, 3, 'o Reprovar levou a versão do painel');
    assert.equal(escrever.chamadas[1].imagemId, 9103, 'o Reprovar levou a foto errada');

    // ⚠️ Os DOIS cliques, sem nenhum argumento: a tela chama assim quando a
    // imagem não chega (e `undefined` é o caso que `{a = null} = {}` cobre).
    assert.doesNotThrow(() => props.onAprovarFoto(), 'o clique sem argumento LANÇOU');
    assert.doesNotThrow(() => props.onReprovar(), 'o clique sem argumento LANÇOU');
    assert.equal(escrever.chamadas.length, 4, 'os cliques sem argumento não escreveram');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. ⚠️⚠️ O CONTEXTO DE EMPILHAMENTO — O MODAL **ACIMA** DO LIGHTBOX
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ EMPILHAMENTO: o fundo `z-[42]` do modal NÃO é descendente do `<aside>` `z-[40]` 189_07', () => {
    // ⚠️⚠️ **A ARMADILHA, medida no planejamento:** `z-index` **não escapa** de um
    // contexto de empilhamento, e o `<aside>` do painel é `fixed z-[40]` → ele CRIA
    // um. Se o modal fosse montado DENTRO dele, o `z-[42]` valeria **dentro do
    // 40** e o modal aberto a partir do lightbox (`z-[41]`, na raiz) ficaria
    // **ATRÁS** dele: a pessoa clica "Reprovar" e nada aparece (T-189-53).
    // ⚠️ `189-CONTRATO-188.md` A1/INV-04 mediu `FORA`; este gate prova que continua.
    const modalAberto = React.createElement(MODAL.default, {
        key: 'modal-de-reprovar',
        aberto: true,
        imagem: IMAGENS_A[2],
        defeitos: null,
        motivo: '',
        onMotivo: nada,
        onFechar: nada,
        onSubmeter: nada,
    });

    const html = renderToStaticMarkup(React.createElement(PAINEL.RaizDoPainel, {
        navegacao: { anterior_id: null, proxima_id: null },
        identificacao: { titulo: 'Oferta da fixture' },
        modais: [modalAberto],
    }));

    assert.ok(html.length > 1000, `a raiz do painel saiu curta demais (${html.length} bytes) — o gate mediria zero casos`);

    const itens = arvoreComAncestrais(html);
    const fundos = comToken(itens, 'z-[42]');
    assert.equal(fundos.length, 1, `esperava UM fundo \`z-[42]\` na árvore, achei ${fundos.length} — o gate precisa saber QUAL elemento mede`);

    const asides = itens.filter((e) => e.tag === 'aside' && classesDe(e).has('z-[40]'));
    assert.equal(asides.length, 1, `esperava UM \`<aside>\` \`z-[40]\`, achei ${asides.length}`);

    const idDoAside = asides[0].indice;
    assert.equal(
        fundos[0].ancestrais.includes(idDoAside),
        false,
        '⚠️⚠️ o fundo `z-[42]` do modal É DESCENDENTE do `<aside>` `z-[40]` — o `z-index` dele vale DENTRO do painel e o modal fica ATRÁS do lightbox `z-[41]` (T-189-53)',
    );

    // ⚠️ E ele é IRMÃO do `<aside>`: mesma profundidade, mesmo pai.
    assert.equal(
        fundos[0].profundidade,
        asides[0].profundidade,
        'o fundo do modal não está na MESMA profundidade do `<aside>` — ele deixou de ser irmão dele',
    );
    assert.deepEqual(
        fundos[0].ancestrais,
        asides[0].ancestrais,
        'o fundo do modal e o `<aside>` deixaram de ter os MESMOS ancestrais',
    );
});

test('⚠️⚠️ EMPILHAMENTO — AS DUAS ISCAS: casca DENTRO do `<aside>` REPROVA, irmã PASSA 189_07', () => {
    // ⚠️⚠️ Sem estas duas iscas, "nenhum achado" não significaria nada: a sonda
    // poderia estar cega para a relação de ancestralidade.
    const DENTRO = '<div><aside class="fixed z-[40] font-pub"><div class="fixed inset-0 z-[42]"></div></aside></div>';
    const IRMA = '<div><aside class="fixed z-[40] font-pub"></aside><div class="fixed inset-0 z-[42]"></div></div>';

    for (const [nome, html, esperado] of [['DENTRO', DENTRO, true], ['IRMÃ', IRMA, false]]) {
        const itens = arvoreComAncestrais(html);
        const fundo = comToken(itens, 'z-[42]')[0];
        const aside = itens.find((e) => e.tag === 'aside' && classesDe(e).has('z-[40]'));

        assert.notEqual(fundo, undefined, `a sonda não achou o fundo \`z-[42]\` na árvore ${nome}`);
        assert.notEqual(aside, undefined, `a sonda não achou o \`<aside>\` \`z-[40]\` na árvore ${nome}`);
        assert.equal(
            fundo.ancestrais.includes(aside.indice),
            esperado,
            `a sonda de ancestralidade está ERRADA na árvore ${nome}`,
        );
    }

    // ⚠️⚠️ ISCA DO TOKEN: `includes` casaria `z-[4]` dentro de `z-[42]`. A leitura é
    // por IGUALDADE DE TOKEN no conjunto, e isso é cobrado.
    const QUASE = '<div class="z-[4] z-[420]"></div>';
    assert.equal(comToken(arvoreComAncestrais(QUASE), 'z-[42]').length, 0, 'a leitura de token casou `z-[4]`/`z-[420]` como `z-[42]` — ela está mais larga que o invariante');
    assert.equal(comToken(arvoreComAncestrais('<div class="a z-[42] b"></div>'), 'z-[42]').length, 1, 'a leitura de token está CEGA para o token exato');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. ⚠️ O ESCAPE COM MODAL SOBRE O LIGHTBOX
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ ESCAPE: com modal SOBRE o lightbox, fecha SÓ o modal — uma asserção por camada 189_07', () => {
    const r = PAINEL.resolverAtalhoDoPainel;

    // Camada 1 — modal aberto POR CIMA do lightbox: fecha o MODAL.
    const comModal = r({ tecla: 'Escape', temModal: true, temLightbox: true });
    assert.equal(comModal.acao, PAINEL.ACOES.FECHAR_MODAL, 'o `Escape` com modal sobre o lightbox não fechou o modal');

    // Camada 2 — só o lightbox: fecha o LIGHTBOX (e NÃO o painel).
    assert.equal(
        r({ tecla: 'Escape', temModal: false, temLightbox: true }).acao,
        PAINEL.ACOES.FECHAR_LIGHTBOX,
        'o `Escape` com o lightbox aberto não fechou o lightbox',
    );

    // Camada 3 — nenhum dos dois: fecha o PAINEL.
    assert.equal(
        r({ tecla: 'Escape', temModal: false, temLightbox: false }).acao,
        PAINEL.ACOES.FECHAR_PAINEL,
        'o `Escape` sem modal e sem lightbox não fechou o painel',
    );

    // ⚠️⚠️ E a ação de fechar o modal **não mexe em índice nem em oferta**: o
    // lightbox segue na MESMA foto e com o MESMO zoom, porque nada do estado dele é
    // devolvido por esta tecla.
    assert.equal(comModal.imagem, null, 'o `Escape` do modal devolveu um índice — ele trocaria a foto do lightbox');
    assert.equal(comModal.oferta, null, 'o `Escape` do modal devolveu uma oferta — ele navegaria a fila');

    // ⚠️ Com modal aberto, ↑/↓/←/→ ficam INERTES: navegar a fila por trás de um
    // modal trocaria a oferta que o modal está confirmando.
    for (const tecla of ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']) {
        assert.equal(
            r({ tecla, temModal: true, temLightbox: true, anteriorId: 1, proximaId: 2, imagemDoLightbox: 0, totalDoLightbox: 4 }),
            null,
            `\`${tecla}\` agiu com o modal aberto sobre o lightbox`,
        );
    }

    // ⚠️ ISCA: a mesma tecla SEM modal, com o lightbox aberto, AGE (senão o bloco
    // acima estaria "provando" só que o resolvedor devolve `null` sempre).
    const semModal = r({ tecla: 'ArrowRight', temModal: false, temLightbox: true, imagemDoLightbox: 3, totalDoLightbox: 4 });
    assert.equal(semModal.acao, PAINEL.ACOES.MOVER_LIGHTBOX, 'a sonda do teclado está CEGA: ←/→ não movem o lightbox nem sem modal');
    assert.equal(semModal.imagem, 0, 'o ←/→ do lightbox deixou de DAR A VOLTA (o painel CLAMPA; são regras diferentes de propósito)');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. ⚠️⚠️ NENHUMA ESCRITA NOVA, E A FONTE PROVA QUE O CONTEXTO É A FONTE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ESCRITA: a contagem de `router.post(` NÃO subiu — o helper ÚNICO continua único 189_07', () => {
    // ⚠️⚠️ MEDIDO no HEAD de partida deste plano (`c23bab8d`, 20/10/2026), na fonte
    // SEM COMENTÁRIO: `PainelDeRevisao.jsx` tem **1** (o `escreverNoPainel`, que é
    // O helper) e a página tem **1** (o `lerDoPainel`/navegação). ⚠️ Omitir
    // `versao_esperada` é "sobrescrever em silêncio", que o critério 17 proíbe — e
    // é o que um `router.post` direto faria (T-189-52).
    assert.equal(ocorrencias(FONTE_PAINEL, 'router.post('), 1, '⚠️⚠️ apareceu `router.post(` novo em PainelDeRevisao.jsx — a escrita TEM de passar pelo helper único');
    assert.equal(ocorrencias(FONTE_PAGINA, 'router.post('), 1, '⚠️⚠️ apareceu `router.post(` novo na página');

    // ⚠️ E as peças do lightbox não escrevem por si: ZERO nas três.
    for (const [rel, fonte] of [[REL_LATERAL, FONTE_LATERAL], [REL_LIGHTBOX, FONTE_LIGHTBOX], [REL_MODAL, FONTE_MODAL]]) {
        assert.equal(ocorrencias(fonte, 'router.post('), 0, `${rel} passou a escrever direto`);
    }

    // ⚠️ ISCA NAS DUAS DIREÇÕES: a sonda enxerga o que deve e não o que não deve.
    assert.equal(ocorrencias('router.post(rota, corpo, opcoes)', 'router.post('), 1, 'a sonda de `router.post(` está CEGA');
    assert.equal(ocorrencias('escreverNoPainel({ rota, versao })', 'router.post('), 0, 'a sonda de `router.post(` acusa o helper único — ela é mais larga que o invariante');
});

test('⚠️⚠️ FONTE: o fio do lightbox chama `escritasDaColuna` com o CONTEXTO, nunca com `ofertaDoPainel` 189_07', () => {
    // ⚠️⚠️ O recorte é o CORPO do adaptador, achado por **ÂNCORA DE TEXTO** (nunca
    // por número de linha: ponteiros apodrecem, e nesta própria fase um commit
    // alheio deslocou três).
    const ABRE = 'const escreverPeloLightbox = (pedido) => {';
    const inicio = FONTE_PAGINA.indexOf(ABRE);
    assert.notEqual(inicio, -1, 'o adaptador de escrita do lightbox desapareceu da página (ou foi renomeado)');

    const fim = FONTE_PAGINA.indexOf('\n    };', inicio);
    assert.notEqual(fim, -1, 'não achei o fim do adaptador — o recorte sairia vazio, e gate sobre zero casos não prova nada');

    const corpo = FONTE_PAGINA.slice(inicio, fim);
    assert.ok(corpo.length > 200, `o recorte do adaptador saiu curto demais (${corpo.length} bytes)`);

    // ⚠️⚠️ A ASSERÇÃO CENTRAL DA DECISÃO DO USUÁRIO.
    assert.ok(corpo.includes('ofertaId: alvo.oferta'), '⚠️⚠️ a oferta da escrita do lightbox deixou de vir do contexto');
    assert.ok(corpo.includes('versao: alvo.versao'), '⚠️⚠️ a VERSÃO da escrita do lightbox deixou de vir do contexto — T-189-50');
    assert.ok(corpo.includes('escritasDaColuna('), 'o adaptador deixou de reusar `escritasDaColuna` (INV-03/A6) — remontar a rota aqui é a SEGUNDA cópia da escrita');
    assert.equal(corpo.includes('ofertaDoPainel'), false, '⚠️⚠️ `ofertaDoPainel` entrou no fio do lightbox — depois de um ↑/↓ ela é de OUTRA oferta');
    assert.equal(corpo.includes('versaoDoPainel'), false, '⚠️⚠️ `versaoDoPainel` entrou no fio do lightbox — T-189-50');

    // ⚠️ ISCA NAS DUAS DIREÇÕES do recorte.
    assert.ok('x ofertaId: alvo.oferta y'.includes('ofertaId: alvo.oferta'), 'a sonda do contexto está CEGA');
    assert.equal('ofertaId: ofertaDoPainel'.includes('ofertaId: alvo.oferta'), false, 'a sonda do contexto casa a fonte ERRADA');
    assert.ok('ofertaId: ofertaDoPainel'.includes('ofertaDoPainel'), 'a sonda de `ofertaDoPainel` está CEGA');

    // ⚠️ E o restante da página CONTINUA usando `ofertaDoPainel` (as escritas do
    // painel não mudaram) — se não usasse, a asserção de ausência acima seria
    // **vazia por construção**.
    assert.ok(ocorrencias(FONTE_PAGINA, 'ofertaDoPainel') > 10, 'a página parou de usar `ofertaDoPainel` — a asserção de ausência no recorte viraria vazia por construção');
});

test('⚠️⚠️ FONTE: a lateral é montada com `propsDaLateralDoLightbox(`, e NÃO com handler `null` 189_07', () => {
    // ⚠️⚠️ **Gate de fonte, e ele é o que fecha o achado do plan-checker:** um
    // plano que esquecesse de ligar os handlers passaria todo teste puro, e o botão
    // nunca nasceria. A lateral tem de ser montada ESPALHANDO a função.
    assert.ok(FONTE_PAGINA.includes('propsDaLateralDoLightbox('), '`propsDaLateralDoLightbox(` não é chamada na página');
    assert.ok(
        /<LateralDoLightbox\s*\{\.\.\.propsDaLateralDoLightbox\(/.test(FONTE_PAGINA),
        '⚠️⚠️ a `<LateralDoLightbox>` deixou de ser montada espalhando `propsDaLateralDoLightbox(…)` — props montadas à mão furam a ÚNICA fonte',
    );

    // ⚠️⚠️ E os handlers não voltaram a `null` (era o estado do 189-06).
    assert.equal(FONTE_PAGINA.includes('onAprovarFoto={null}'), false, '⚠️⚠️ `onAprovarFoto={null}` voltou — o botão não nasce (D4) e esta wave existe para ligá-lo');
    assert.equal(FONTE_PAGINA.includes('onReprovar={null}'), false, '⚠️⚠️ `onReprovar={null}` voltou na página');

    // ⚠️ ISCA NAS DUAS DIREÇÕES: uma fonte sintética com handler `null` é ACUSADA,
    // e o espalhamento de verdade NÃO é.
    const SINTETICA_RUIM = '<LateralDoLightbox imagem={x} onAprovarFoto={null} onReprovar={null} />';
    assert.ok(SINTETICA_RUIM.includes('onAprovarFoto={null}'), 'a sonda do handler `null` está CEGA');
    assert.equal(
        /<LateralDoLightbox\s*\{\.\.\.propsDaLateralDoLightbox\(/.test(SINTETICA_RUIM),
        false,
        'a sonda do espalhamento casa uma montagem À MÃO — ela está mais larga que o invariante',
    );
    assert.ok(
        /<LateralDoLightbox\s*\{\.\.\.propsDaLateralDoLightbox\(/.test('<LateralDoLightbox {...propsDaLateralDoLightbox({ contexto })} />'),
        'a sonda do espalhamento está CEGA',
    );

    // ⚠️ `lerSemComentarios` NÃO é cega: o CRU tem o que a fonte sem comentário não
    // tem. Sem esta prova, toda sonda de ausência acima poderia estar lendo vazio.
    assert.ok(CRU_PAGINA.includes('PERGUNTA-189-B'), 'o CRU da página não tem a citação da pergunta — a prova de que a leitura sem comentário corta algo não existe');
    assert.equal(FONTE_PAGINA.includes('PERGUNTA-189-B'), false, '`lerSemComentarios` deixou de tirar o comentário — toda sonda deste arquivo passaria pelo motivo errado');
});

test('⚠️⚠️ FONTE: o modal aberto pelo lightbox busca a foto na galeria DO CONTEXTO 189_07', () => {
    // ⚠️⚠️ **O defeito que esta asserção impede:** depois de um ↑/↓ a foto da oferta
    // A **não está** em `painel.fotos.imagens` (já é da B). Buscar lá devolveria
    // `null`, o modal abriria **sem a foto** — rótulo sem valor é casca vazia (D4) —
    // e a pessoa confirmaria a reprovação **sem ver o que reprova**.
    assert.ok(FONTE_PAGINA.includes('const galeriaDoModal'), 'a galeria do modal deixou de ser uma derivação própria');
    assert.ok(
        /galeriaDoModal = contextoDaFotoNoModal !== null\s*\?\s*imagensDoLb/.test(FONTE_PAGINA),
        '⚠️⚠️ a galeria do modal deixou de ser a do CONTEXTO quando ele foi aberto pelo lightbox',
    );

    // ⚠️⚠️ E a escrita do modal usa a oferta/versão **DO CONTEXTO** quando há
    // contexto, e a do painel quando não há.
    // ⚠️⚠️ **FURO MEDIDO PELO DESENHO DA CAMPANHA DE MUTAÇÃO, e consertado:** a
    // primeira redação cobrava só `includes('const ofertaDaEscritaDoModal')` — ou
    // seja, a EXISTÊNCIA do nome. Uma mutação que trocasse o corpo por
    // `= ofertaDoPainel` **SOBREVIVERIA**, e a escrita do modal iria com a oferta
    // errada. ➡️ Agora a DERIVAÇÃO é cobrada, não o nome.
    const DERIVACOES = [
        [/ofertaDaEscritaDoModal = contextoDaFotoNoModal === null\s*\?\s*ofertaDoPainel\s*:\s*inteiroSeguro\(contextoDaFotoNoModal\.oferta\)/, 'a OFERTA'],
        [/versaoDaEscritaDoModal = contextoDaFotoNoModal === null\s*\?\s*versaoDoPainel\s*:\s*inteiroSeguro\(contextoDaFotoNoModal\.versao\)/, 'a VERSÃO'],
    ];
    for (const [re, qual] of DERIVACOES) {
        assert.ok(re.test(FONTE_PAGINA), `⚠️⚠️ ${qual} da escrita do modal deixou de ser DERIVADA do contexto do lightbox — T-189-50`);
    }

    // ⚠️ ISCA NAS DUAS DIREÇÕES de cada derivação.
    assert.ok(
        DERIVACOES[0][0].test('const ofertaDaEscritaDoModal = contextoDaFotoNoModal === null\n        ? ofertaDoPainel\n        : inteiroSeguro(contextoDaFotoNoModal.oferta);'),
        'a sonda da derivação da OFERTA está CEGA',
    );
    assert.equal(
        DERIVACOES[0][0].test('const ofertaDaEscritaDoModal = ofertaDoPainel;'),
        false,
        'a sonda da derivação da OFERTA casa o atalho que IGNORA o contexto — ela é mais larga que o invariante',
    );
    assert.ok(
        DERIVACOES[1][0].test('const versaoDaEscritaDoModal = contextoDaFotoNoModal === null\n        ? versaoDoPainel\n        : inteiroSeguro(contextoDaFotoNoModal.versao);'),
        'a sonda da derivação da VERSÃO está CEGA',
    );
    assert.equal(
        DERIVACOES[1][0].test('const versaoDaEscritaDoModal = versaoDoPainel;'),
        false,
        'a sonda da derivação da VERSÃO casa o atalho que IGNORA o contexto',
    );
    assert.ok(FONTE_PAGINA.includes('oferta: ofertaId, imagem: imagemId'), 'o ramo do LIGHTBOX deixou de levar a oferta derivada do contexto');

    // ⚠️⚠️ **E O CAMINHO DO PAINEL FICOU BYTE A BYTE**, que é o que o próprio plano
    // manda (*"sem mudar nada para quem abre pelo painel"*) e o que
    // `publicador-painel-modal-reprovar-188.test.js:581` cobra LITERALMENTE. ⚠️ A
    // primeira redação desta wave trocou esta linha por uma derivação e aquele gate
    // REPROVOU — **e ele estava CERTO**. Nenhum gate alheio foi editado.
    assert.ok(
        FONTE_PAGINA.includes('parametros: { company: empresaId, oferta: ofertaDoPainel, imagem: imagemId }'),
        '⚠️⚠️ o caminho do PAINEL deixou de escrever com `oferta: ofertaDoPainel` — o gate do 188-12 REPROVA, e com razão',
    );

    // ⚠️⚠️ **SEGUNDA CÓPIA DO LITERAL DA ROTA, COM TESTE DE IGUALDADE** (régua §2:
    // *"nunca uma segunda regra sem teste de igualdade"*). O ramo do lightbox e o
    // do painel repetem o NOME da rota; se um divergir, um dos dois aponta para o
    // nada e **só um** deles seria descoberto em produção.
    const nomesDaRota = [...FONTE_PAGINA.matchAll(/rota: '(mlb\.anuncios\.meus\.aprovacao\.painel\.imagem\.reprovar)'/g)].map((m) => m[1]);
    assert.equal(nomesDaRota.length, 2, `esperava as DUAS cópias do nome da rota de reprovar (painel e lightbox), achei ${nomesDaRota.length}`);
    assert.equal(new Set(nomesDaRota).size, 1, 'as duas cópias do nome da rota de reprovar DIVERGIRAM — uma delas aponta para o nada');

    // ⚠️ E a rota EXISTE pelo nome, no arquivo de rotas (anti-fantasma de ROTA).
    const rotas = fs.readFileSync(path.resolve(RAIZ, 'routes/mlb_anuncios.php'), 'utf8');
    assert.ok(rotas.includes("name('meus.aprovacao.painel.imagem.reprovar')"), 'a rota de reprovar imagem NÃO existe — os dois ramos apontariam para o nada');

    // ⚠️⚠️ **E o `oferta` do ESTADO do modal continua sendo o do PAINEL** — medido,
    // não escolhido: `modalAberto` é `modalDoPainel.oferta === ofertaDoPainel ?
    // modalDoPainel : null` (188-12). Gravar ali a oferta do lightbox faria
    // `modalAberto` sair `null` com o painel em outra oferta, e **o modal não seria
    // desenhado** — exatamente no caso que esta wave resolve.
    assert.ok(
        /modalAberto = modalDoPainel !== null && objetoSeguro\(modalDoPainel\)\?\.oferta === ofertaDoPainel/.test(FONTE_PAGINA),
        'a derivação de `modalAberto` do 188-12 mudou — a decisão de guardar `oferta: ofertaDoPainel` depende dela',
    );

    // ⚠️ ISCA das duas direções para a sonda da galeria.
    assert.ok(
        /galeriaDoModal = contextoDaFotoNoModal !== null\s*\?\s*imagensDoLb/.test('const galeriaDoModal = contextoDaFotoNoModal !== null\n        ? imagensDoLb'),
        'a sonda da galeria está CEGA',
    );
    assert.equal(
        /galeriaDoModal = contextoDaFotoNoModal !== null\s*\?\s*imagensDoLb/.test('const galeriaDoModal = fotosDoPainel.imagens;'),
        false,
        'a sonda da galeria casa a galeria ERRADA',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. ⚠️ O TERRITÓRIO: AS AUSÊNCIAS, RECORTADAS À LISTA CONSTANTE DESTA FASE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ TERRITÓRIO: nada de segundo passo, nada da Fase 192 e nada sem dono — com ISCA 189_07', () => {
    // ⚠️⚠️ **GATE MAIS LARGO QUE O INVARIANTE — QUATORZE ocorrências nesta
    // milestone**, e uma **BARROU UM DEPLOY** (`lucro_liquido` é campo LEGÍTIMO do
    // Portal). ➡️ O recorte é a **lista CONSTANTE** dos arquivos desta fase. O
    // painel inteiro legitimamente tem "Aprovar para publicação": **recorte, não
    // varra**.
    const FONTES_DA_FASE = [
        [REL_LIGHTBOX, FONTE_LIGHTBOX],
        [REL_LATERAL, FONTE_LATERAL],
        [REL_SERVICO_LB, FONTE_SERVICO_LB],
    ];

    const PROIBIDOS = [
        [/Confirmar publica[çc][ãa]o/i, 'segundo passo — aprovar ENFILEIRA num clique, e não há desfazer'],
        [/\bDesfazer\b/i, 'idem — não há desfazer nesta tela'],
        [/Cancelar aprova[çc][ãa]o/i, 'idem'],
        [/segundo passo/i, 'idem'],
        [/Gerar novamente/i, '"✦ Gerar novamente com IA" é da Fase 192 (a ÚLTIMA desta milestone)'],
        [/Comparar lado a lado/i, 'ficou SEM DONO (as Fases 190/191 saíram de escopo em 20/10/2026)'],
        [/localStorage|sessionStorage/, 'o estado de revisão NÃO mora no navegador'],
        [/\bString\(/, '`String(` LANÇA com `{toString: null}`'],
        [/hasOwnProperty\s*\[|\.hasOwnProperty\(/, '`hasOwnProperty` cru LANÇA — a busca de chave coage para string'],
        [/JSON\.stringify/, '`JSON.stringify` mente (`NaN` vira `null`) e LANÇA em cíclico'],
        [/lucro\s+l[ií]quido/i, 'o vocabulário travado é "Resultado estimado por venda"'],
        [/ArquivosDeImagem|ImagemAssetService|Acervo/, 'o acervo está VETADO (o R2 é privado desde 16/10)'],
        [/MlPublicacaoService|PublicacaoService/, 'a escrita no ML é da Fase 193'],
        [/useCriativosDoPublicador/, 'o Creative Engine está VETADO'],
    ];

    const achados = FONTES_DA_FASE.flatMap(([rel, fonte]) => PROIBIDOS
        .filter(([re]) => re.test(fonte))
        .map(([re, por]) => `${rel}: ${re.source} (${por})`));
    assert.deepEqual(achados, [], 'ausência violada nos arquivos desta fase');

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES: cada regex contra um caso que ela DEVE casar.
    const CEBO = `
        <button>Confirmar publicação</button> <button>Desfazer</button> <button>Cancelar aprovação</button>
        'o segundo passo' <button>✦ Gerar novamente com IA</button>
        <button>Comparar lado a lado na revisão detalhada</button>
        localStorage.getItem('x'); sessionStorage.clear(); String(x);
        o.hasOwnProperty('k'); JSON.stringify(o); 'lucro líquido';
        ArquivosDeImagem; ImagemAssetService; Acervo; MlPublicacaoService; PublicacaoService;
        import x from './useCriativosDoPublicador';
    `;
    const cegas = PROIBIDOS.filter(([re]) => !re.test(CEBO)).map(([re]) => re.source);
    assert.deepEqual(cegas, [], 'regex que não casa o próprio cebo — "nenhum achado" não significa nada');

    // ⚠️ E o RECORTE tem isca: a lista é CONSTANTE e NOMEADA, nunca uma CONTAGEM
    // (asserção de `length === N` treina o executor seguinte a subir o número).
    assert.deepEqual(
        FONTES_DA_FASE.map(([rel]) => rel),
        [REL_LIGHTBOX, REL_LATERAL, REL_SERVICO_LB],
        'a lista varrida deixou de ser EXATAMENTE os arquivos próprios desta fase',
    );

    // ⚠️⚠️ E a prova de que isto é RECORTE e não varredura: o diretório
    // `aprovacao/` tem MUITO mais arquivos que os três, e os três são SUBCONJUNTO
    // ESTRITO dele. Varrer o diretório inteiro acusaria peças das Fases 186/187/188
    // que legitimamente têm "Aprovar para publicação" — é a família *"gate mais
    // largo que o invariante"*, com QUATORZE ocorrências nesta milestone.
    const todosOsJsxDaPasta = fs.readdirSync(path.resolve(RAIZ, DIR_APROV)).filter((f) => f.endsWith('.jsx'));
    assert.ok(todosOsJsxDaPasta.length > 10, `o diretório \`aprovacao/\` tem só ${todosOsJsxDaPasta.length} \`.jsx\` — a leitura está quebrada`);
    const doRecorteNaPasta = FONTES_DA_FASE
        .map(([rel]) => path.basename(rel))
        .filter((nome) => todosOsJsxDaPasta.includes(nome));
    assert.ok(
        doRecorteNaPasta.length < todosOsJsxDaPasta.length,
        'o recorte deixou de ser SUBCONJUNTO ESTRITO do diretório — ele virou varredura',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. ⚠️⚠️ O ACHADO HERDADO: USO-ANTES-DA-DECLARAÇÃO (TDZ) NA PÁGINA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ TDZ: VETO contra um QUINTO uso-antes-da-declaração no corpo do componente da página 189_07', () => {
    // ⚠️⚠️ **ACHADO DESTA WAVE — JÁ CONSERTADO em 20/10/2026** pela quick
    // `261020-tdz` (commit `85c3b46b`). O corpo do componente da página lia
    // `const` **antes** de elas serem inicializadas, em posições EAGER (prop de
    // JSX lida direto, ou **teste de ternário**). Em JavaScript isso é TDZ:
    // `ReferenceError: Cannot access 'X' before initialization` — ou seja,
    // **TELA PRETA** no instante em que o painel abre com payload.
    //
    // ⚠️⚠️ **ERAM CINCO, NÃO QUATRO — e o quinto era INVISÍVEL PARA A SONDA DAQUI.**
    // Esta wave achou quatro (`abrirModalDoPainel`, `abrirModalDeReprovar`,
    // `registrarConflito`, `precoComSimulacao`, das waves 188-09/11/12). O quinto,
    // **`podeEscreverNoPainel`**, era declarado em `:1107` e lido EAGER **quatro
    // vezes** (`:1013`, `:1015`, `:1016`, `:1017`) na forma
    // `prop={!podeEscreverNoPainel ? null : …}` — o **teste** do ternário, que é
    // avaliado na hora. ⚠️ **A regex abaixo NÃO casa essa forma** (medido: casa
    // `prop={nome}` e `{...nome}`, e **não** casa `prop={!nome ? …}` nem
    // `prop={nome === …}`). **A sonda LARGA e o render de verdade vivem em
    // `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js`**, que instancia o
    // `default export` — coisa que **nenhum** gate deste projeto fazia, e foi por
    // isso que quatro waves publicaram o defeito.
    //
    // ⚠️⚠️ **DUAS CORREÇÕES DE RAIZ EM 20/10/2026, e as duas eram desta família:**
    //
    // (1) **A LISTA DE PERMISSÃO SAIU.** Enquanto os defeitos existiam, um
    //     `HERDADOS` mantinha o gate verde para eles e acusava só um "quinto".
    //     Consertados os cinco, essa lista virava **PONTO CEGO**: reintroduzir o
    //     defeito em qualquer um dos cinco nomes passaria **filtrado**. Agora a
    //     asserção é `antesDaHora` **VAZIA, sem exceção** — e por isso ela também
    //     pega **regressão** nos cinco, o que a forma antiga não pegava.
    //
    // (2) **A PROVA DE NÃO-CEGUEIRA SAIU DO DEFEITO REAL E FOI PARA CEBO SINTÉTICO.**
    //     Ela era `assert.ok(antesDaHora.length > 0)`, isto é, *"a sonda enxerga
    //     porque os defeitos ainda estão lá"* — logo o gate **REPROVAVA PELO
    //     PRÓPRIO CONSERTO**, exatamente a família que o comentário antigo daqui
    //     dizia evitar (e foi o que aconteceu: `25/24/1` no dia do conserto).
    //     Agora a sonda roda sobre uma **fonte sintética** com um caso plantado, e
    //     a prova **não desaparece quando o código está certo**.
    //
    // ⚠️ Os cinco nomes seguem listados **só como guarda de renome** (abaixo), nunca
    // como permissão.
    const CONSERTADOS_EM_20_10 = ['podeEscreverNoPainel', 'abrirModalDoPainel', 'abrirModalDeReprovar', 'registrarConflito', 'precoComSimulacao'];

    // ⚠️⚠️ UMA FUNÇÃO SÓ, usada na página REAL **e** no cebo sintético abaixo.
    // Se houvesse duas cópias da regex, o cebo provaria *"uma regex desta forma
    // funciona"* em vez de *"a regex que este gate usa funciona"* — e um conserto
    // numa cópia só deixaria a outra mentindo. **Mesma função, uma verdade.**
    const varrerUsoAntesDaDeclaracao = (fonte) => {
        const linhasDaFonte = fonte.split('\n');
        const declarado = new Map();
        linhasDaFonte.forEach((l, i) => {
            const m = l.match(/^ {4}const ([A-Za-z_$][\w$]*) =/);
            if (m !== null && !declarado.has(m[1])) declarado.set(m[1], i);
        });
        const achados = [];
        for (const [nome, linhaDecl] of declarado) {
            const re = new RegExp(`(?:[A-Za-z_$][\\w$]*=\\{${nome}\\}|\\{\\.\\.\\.${nome}\\})`);
            if (linhasDaFonte.slice(0, linhaDecl).some((l) => re.test(l))) achados.push(nome);
        }
        return { declarado, achados };
    };

    const { declarado: declaradoEm, achados: antesDaHora } = varrerUsoAntesDaDeclaracao(FONTE_PAGINA);

    assert.ok(declaradoEm.size > 50, `achei só ${declaradoEm.size} declarações no corpo do componente — a sonda está quebrada`);

    // ⚠️⚠️ A PROVA DE NÃO-CEGUEIRA, AGORA EM CEBO SINTÉTICO — e é esta troca que
    // tira o gate da família "reprova pelo próprio conserto". A mesma varredura
    // roda sobre uma fonte FABRICADA com UM caso plantado: se o mecanismo quebrar,
    // ela devolve zero AQUI e o gate reprova, **mesmo com a página real correta**.
    const CEBO = [
        '    const leitorDoCebo = (',
        '        <Peca cebo189={ceboEmTdz} />',
        '    );',
        '    const ceboEmTdz = 1;',
        '    const leitorDiferido = (',
        '        <Peca aoAgir={() => ceboLegitimo(1)} />',
        '    );',
        '    const ceboLegitimo = () => 2;',
    ].join('\n');
    const { achados: achadosNoCebo } = varrerUsoAntesDaDeclaracao(CEBO);

    // ⚠️ ISCA NAS DUAS DIREÇÕES, dentro do próprio cebo: `ceboEmTdz` é lido EAGER e
    // **tem** de aparecer; `ceboLegitimo` é **chamado dentro de uma arrow** (diferido,
    // legítimo) e **não** pode aparecer. Se a sonda ficar cega, o primeiro sai e isto
    // reprova; se ficar larga, o segundo entra e isto reprova.
    //
    // ⚠️⚠️ **LIMITE MEDIDO DESTA SONDA, em 20/10/2026, e ele está aqui por escrito
    // porque eu descobri tentando escrever a isca errada:** a varredura é **POR
    // LINHA**, então ela **NÃO distingue** `prop={nome}` escrito **dentro de uma
    // arrow na MESMA linha** — por exemplo
    // `const a = () => <X y={nomeDeBaixo} />;` seria acusado como EAGER, embora
    // seja diferido e legítimo. **Medido: a página real não tem essa forma** (a
    // varredura devolve vazio), então hoje não há falso positivo. ⚠️ **Se algum dia
    // houver, NÃO afrouxe esta sonda:** a prova larga e de verdade — que **instancia
    // o `default export`** — mora em
    // `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js`, e é ela que decide.
    // Esta aqui é a guarda **estática e barata** que roda junto do resto da wave.
    assert.deepEqual(achadosNoCebo, ['ceboEmTdz'], 'o cebo sintético não deu o resultado exato: a sonda está CEGA (não achou o EAGER) ou LARGA (acusou o DIFERIDO)');

    // ⚠️⚠️ E AQUI NÃO HÁ LISTA DE PERMISSÃO: a página real tem de devolver VAZIO.
    // Isso pega caso NOVO **e** regressão em qualquer um dos cinco de 20/10 — o
    // que a forma com `HERDADOS` **não** pegava.
    assert.deepEqual(antesDaHora, [], '⚠️⚠️ uso-antes-da-declaração no corpo do componente da página — isso é TDZ (`ReferenceError`), ou seja TELA PRETA quando o painel abre com payload. ⚠️ Se for um dos cinco de 20/10/2026, é REGRESSÃO do commit `85c3b46b`');

    // ⚠️ ISCA NAS DUAS DIREÇÕES: a sonda casa a leitura EAGER e **não** casa a
    // leitura DIFERIDA (dentro de arrow), que é legítima.
    const re = new RegExp('(?:[A-Za-z_$][\\w$]*=\\{alvo\\}|\\{\\.\\.\\.alvo\\})');
    assert.ok(re.test('                onReprovar={alvo}'), 'a sonda de TDZ está CEGA para a leitura EAGER');
    assert.ok(re.test('                {...alvo}'), 'a sonda de TDZ está CEGA para o espalhamento');
    assert.equal(re.test('                onReprovar={() => alvo(1)}'), false, 'a sonda de TDZ acusa leitura DIFERIDA (dentro de arrow) — ela é mais larga que o invariante');

    // ⚠️ GUARDA DE RENOME: os cinco consertados em 20/10 continuam EXISTINDO na
    // página. Não é mais permissão — é que o conserto do `85c3b46b` foi mover
    // **estes** cinco blocos, e se um deles for renomeado ou sumir, a mensagem de
    // regressão acima passa a citar um nome que não existe, e quem ler não acha o
    // caso. **Renomear é permitido; atualizar esta lista junto é obrigatório.**
    const sumiram = CONSERTADOS_EM_20_10.filter((n) => !declaradoEm.has(n));
    assert.deepEqual(sumiram, [], 'nome da lista `CONSERTADOS_EM_20_10` que não existe mais na página — reancore a lista (ela existe para a mensagem de regressão citar nome real)');
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. ⚠️ O RENDER ADVERSO — A TELA PRETA, INCLUSIVE NO CLIQUE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ADVERSO: cada campo objeto/nulo/ausente/NaN/array, a prop inteira nula, E NO CLIQUE 189_07', () => {
    // ⚠️⚠️ A TELA PRETA voltou SEIS vezes nesta milestone, a última **NO CLIQUE**.
    // `String(valor)`, `hasOwnProperty` e **qualquer `obj[chave]`** LANÇAM com
    // `{toString: null}`; `textoSeguro(NaN)` devolve `'NaN'`; e prop de NÓ
    // chegando objeto comum MATA A PÁGINA.
    const VALORES = [null, undefined, {}, [], NaN, 0, -1, '', 'x', { toString: null }, true, 1.5];

    const CAMPOS = ['contexto', 'indice', 'zoom', 'onFechar', 'onAlternarZoom', 'escrever', 'tipo', 'painelAtual'];

    for (const campo of CAMPOS) {
        for (const valor of VALORES) {
            const base = { contexto: CONTEXTO_A, indice: 2, zoom: 1, onFechar: nada, onAlternarZoom: nada, escrever: nada, tipo: 'aprovar', painelAtual: PAINEL_A };
            const pedido = { ...base, [campo]: valor };
            const rotulo = `${campo} = ${valor === null ? 'null' : typeof valor}`;

            assert.doesNotThrow(() => PAINEL.acaoDoLightbox(pedido), `\`acaoDoLightbox\` LANÇOU com ${rotulo}`);
            assert.doesNotThrow(() => PAINEL.depoisDaAcaoNoLightbox(pedido), `\`depoisDaAcaoNoLightbox\` LANÇOU com ${rotulo}`);

            let props = null;
            assert.doesNotThrow(() => { props = PAINEL.propsDaLateralDoLightbox(pedido); }, `\`propsDaLateralDoLightbox\` LANÇOU com ${rotulo}`);

            // ⚠️⚠️ **E O RENDER DE VERDADE**, com essas props adversas.
            let html = '';
            assert.doesNotThrow(() => { html = render(LATERAL.default, props); }, `a lateral LANÇOU no RENDER com ${rotulo}`);
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `a lateral vazou dado cru na tela com ${rotulo}`,
            );

            // ⚠️⚠️ **E O CLIQUE**, que é onde a última tela preta aconteceu.
            if (typeof props.onAprovarFoto === 'function') {
                assert.doesNotThrow(() => props.onAprovarFoto(valor), `o clique de Aprovar foto LANÇOU com ${rotulo}`);
            }
            if (typeof props.onReprovar === 'function') {
                assert.doesNotThrow(() => props.onReprovar(valor), `o clique de Reprovar LANÇOU com ${rotulo}`);
            }
        }
    }

    // ⚠️ A prop INTEIRA nula/ausente/adversa nas três funções.
    for (const pedido of [null, undefined, 'x', 7, [], { toString: null }]) {
        assert.doesNotThrow(() => PAINEL.acaoDoLightbox(pedido), `\`acaoDoLightbox\` LANÇOU com a prop inteira ${typeof pedido}`);
        assert.doesNotThrow(() => PAINEL.depoisDaAcaoNoLightbox(pedido), `\`depoisDaAcaoNoLightbox\` LANÇOU com a prop inteira ${typeof pedido}`);
        assert.doesNotThrow(() => render(LATERAL.default, PAINEL.propsDaLateralDoLightbox(pedido)), `a lateral LANÇOU no render com a prop inteira ${typeof pedido}`);
    }

    // ⚠️ Imagem com campos adversos DENTRO do contexto — o `.id` é lido, e a
    // `originais[id]` é indexada por ele.
    for (const valor of [null, undefined, {}, [], NaN, 'x', { toString: null }]) {
        const contexto = { oferta_id: 501, versao: 3, ao_vivo: true, imagens: [{ id: 9103, estado: valor, aceita_acao: valor, rotulo: valor, url_arquivo: valor, verificacoes: valor }], originais: valor };
        let html = '';
        assert.doesNotThrow(() => { html = render(LATERAL.default, PAINEL.propsDaLateralDoLightbox({ contexto, indice: 0, escrever: nada })); }, `a lateral LANÇOU com campo de imagem = ${typeof valor}`);
        assert.doesNotMatch(html, /\[object Object\]|NaN|Invalid Date/, `a lateral vazou dado cru com campo de imagem = ${typeof valor}`);
    }

    // ⚠️⚠️ ISCA DA SONDA DE VAZAMENTO: ela enxerga `[object Object]` e `NaN`? Se não
    // enxergasse, todas as asserções acima seriam vazias por construção.
    assert.match('<span>[object Object]</span>', /\[object Object\]|NaN|undefined|Invalid Date/, 'a sonda de vazamento está CEGA para `[object Object]`');
    assert.match('<span>NaN</span>', /\[object Object\]|NaN|undefined|Invalid Date/, 'a sonda de vazamento está CEGA para `NaN`');
    assert.doesNotMatch('<span>Aprovar foto</span>', /\[object Object\]|NaN|undefined|Invalid Date/, 'a sonda de vazamento acusa HTML limpo — ela é mais larga que o invariante');
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. ⚠️ O PROTÓTIPO: `:1330` e a camada do modal
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ PROTÓTIPO `:1330`: o lightbox age sobre O SEU conjunto, e o modal abre POR CIMA 189_07', (t) => {
    // ⚠️⚠️ `design_handoff_publicador/` **não é versionado** (D-189-2): sem ele o
    // bloco degrada para `skip` EXPLÍCITO E NOMEADO, nunca para silêncio.
    if (!TEM_PROTOTIPO) {
        t.skip('SKIP NOMEADO: `design_handoff_publicador/Aprovacao.dc.html` não está no disco (não é versionado — D-189-2)');
        return;
    }

    // ⚠️ `sed -n '1330p'` equivale a `linhas[1329]` (1-based × 0-based).
    const l1330 = LINHAS_PROTOTIPO[1329] ?? '';
    assert.ok(l1330.length > 0, 'a linha `:1330` do protótipo veio vazia — o ponteiro apodreceu');
    assert.match(l1330, /approveImg\(\s*lb\.set/, '⚠️ `:1330` deixou de aprovar sobre o conjunto DO LIGHTBOX (`lb.set`) — é essa linha que a PERGUNTA-189-B (b) reproduz');
    assert.match(l1330, /openModal\(\s*'reject'/, '⚠️ `:1330` deixou de abrir o modal de reprovar a partir do lightbox');
    assert.match(l1330, /set:\s*lb\.set/, '⚠️ `:1330` deixou de passar o conjunto DO LIGHTBOX ao modal');

    // ⚠️ E a camada do modal no protótipo é ACIMA do lightbox — a ORDEM, nunca o
    // número (aqui o remapeamento é `41` < `42`, e o `100`/`90` de lá é RECUSADO).
    const janela = LINHAS_PROTOTIPO.slice(770, 780).join('\n');
    const zModal = Number((janela.match(/z-index:\s*(\d+)/) || [])[1] ?? NaN);
    const zLightbox = Number((LINHAS_PROTOTIPO.slice(722, 730).join('\n').match(/z-index:\s*(\d+)/) || [])[1] ?? NaN);
    assert.ok(Number.isFinite(zModal) && Number.isFinite(zLightbox), `não achei os dois z do protótipo (modal=${zModal}, lightbox=${zLightbox})`);
    assert.ok(zModal > zLightbox, `no protótipo o modal (${zModal}) deixou de estar ACIMA do lightbox (${zLightbox})`);

    // ⚠️ E o remapeamento do projeto preserva a ORDEM: casca `z-[42]` > lightbox `z-[41]`.
    const zCascaDoProjeto = Number((lerSemComentarios(REL_CASCA).match(/CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\]/) || [])[1] ?? NaN);
    const zLightboxDoProjeto = Number((FONTE_LIGHTBOX.match(/z-\[(4\d)\]/) || [])[1] ?? NaN);
    assert.ok(Number.isFinite(zCascaDoProjeto) && Number.isFinite(zLightboxDoProjeto), `não achei os dois z do projeto (casca=${zCascaDoProjeto}, lightbox=${zLightboxDoProjeto})`);
    assert.ok(zCascaDoProjeto > zLightboxDoProjeto, `a ORDEM quebrou: a casca de modal (${zCascaDoProjeto}) tem de ficar ACIMA do lightbox (${zLightboxDoProjeto})`);
    assert.notEqual(zLightboxDoProjeto, zModal, 'o número do protótipo foi COPIADO para a peça — a régua preserva a ORDEM, nunca os números de lá');
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. ⚠️ O MANIFESTO E A BIJEÇÃO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ MANIFESTO: `vocabulario-tamanhos` em LINHA PRÓPRIA com a forma `>=`, e ZERO id órfão 189_07', () => {
    const linhas = TEXTO_MANIFESTO.split(/\r?\n/)
        .map((l) => l.trim())
        .filter((l) => l !== '' && !l.startsWith('#'));

    const ids = linhas.map((l) => l.split(/\s+/)[0]);

    // ⚠️⚠️ BIJEÇÃO id ↔ `<verify>` — os ids do manifesto são EXATAMENTE os usados
    // pelos `<verify>` deste plano. Id fora de todo `<verify>` é ÓRFÃO (gate sobre
    // zero casos), e `<verify>` sem id ABORTA o harness.
    assert.deepEqual(
        [...ids].sort(),
        ['acoes-do-lightbox', 'vocabulario-tamanhos'],
        'os ids do manifesto deixaram de ser exatamente os dois dos `<verify>` deste plano',
    );

    // ⚠️⚠️ LINHA PRÓPRIA e forma `>=` com N MEDIDO (o arquivo faz um lote de
    // sub-testes POR ARQUIVO varrido: a contagem CRESCE POR CONSTRUÇÃO, e é por
    // isso que a forma é `>=` e nunca `=`).
    const linhaDoVocab = linhas.filter((l) => l.startsWith('vocabulario-tamanhos'));
    assert.equal(linhaDoVocab.length, 1, '`vocabulario-tamanhos` não está em UMA linha própria');
    assert.match(linhaDoVocab[0], /::\s*tests=>=\d+$/, '`vocabulario-tamanhos` não está na forma `>=N`');

    // ⚠️ O id deste plano traz os DOIS regimes (pristino, mutado) — a campanha de
    // mutação usa `cp -p`, e `mtime` pode trocar o regime da camada de CSS.
    const linhaDoGate = linhas.filter((l) => l.startsWith('acoes-do-lightbox'));
    assert.equal(linhaDoGate.length, 1, '`acoes-do-lightbox` não está em UMA linha própria');
    assert.match(linhaDoGate[0], /::\s*tests=\d+,\d+$/, '`acoes-do-lightbox` deixou de declarar os DOIS regimes');
    assert.ok(linhaDoGate[0].includes('tests/js/publicador-lightbox-acoes-189.test.js'), 'a linha do gate não aponta para este arquivo');

    // ⚠️ Nenhum arquivo em DOIS ids deste manifesto (no modo `js` o harness roda o
    // arquivo INTEIRO: dois ids sobre o mesmo arquivo somariam a mesma contagem).
    // ⚠️⚠️ **FURO MEDIDO NA PRÓPRIA SONDA, e consertado:** o manifesto separa as
    // colunas por DOIS espaços, e `split(/\s+/)` devolve uma entrada VAZIA no fim
    // de cada linha — duas linhas davam duas entradas `''` e o `Set` as colapsava,
    // acusando "o mesmo arquivo em dois ids" num manifesto correto. É a família
    // *"gate que reprova pelo próprio conserto"*.
    const arquivos = linhas
        .flatMap((l) => l.split('::')[0].trim().split(/\s+/).slice(2))
        .filter((a) => a !== '');
    assert.ok(arquivos.length >= 2, `a sonda achou só ${arquivos.length} arquivo(s) no manifesto — ela está quebrada`);
    assert.equal(new Set(arquivos).size, arquivos.length, 'o mesmo arquivo aparece em dois ids deste manifesto');

    // ⚠️ ISCA: a sonda ENXERGA a duplicata? Um manifesto sintético com o MESMO
    // arquivo em dois ids é acusado.
    const SINTETICO = ['a  js  x.test.js  ::  tests=1', 'b  js  x.test.js  ::  tests=1']
        .flatMap((l) => l.split('::')[0].trim().split(/\s+/).slice(2))
        .filter((a) => a !== '');
    assert.notEqual(new Set(SINTETICO).size, SINTETICO.length, 'a sonda de duplicata está CEGA');

    // ⚠️⚠️ A CONTAGEM ALHEIA do 188-12 é **CONFERIDA**, não copiada: este plano
    // **não tocou** `ModalDeReprovarImagem.jsx`, então o manifesto dele fica
    // INALTERADO. A asserção lê o arquivo dele e exige que a linha exista.
    assert.match(TEXTO_MANIFESTO_188_12, /^\s*\S+\s+js\s+.*publicador-painel-modal-reprovar-188\.test\.js.*tests=/m, 'o manifesto do 188-12 deixou de declarar o gate do modal de reprovar — ele é o vizinho que este plano NÃO toca');
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. ⚠️ O CSS DAS CLASSES TOCADAS (tarefa 2) e o MANIFEST do build
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CSS: as classes do botão de abrir e do Reprovar estão no CSS CONSTRUÍDO, com os valores REAIS 189_07', (t) => {
    const dir = path.resolve(RAIZ, REL_CSS_CONSTRUIDO, 'assets');
    if (!fs.existsSync(dir)) {
        t.skip('SKIP NOMEADO: `public/build/assets` não existe — rode `npm run build` antes deste gate');
        return;
    }

    const arquivos = fs.readdirSync(dir).filter((f) => f.endsWith('.css'));
    assert.ok(arquivos.length > 0, 'não há CSS construído em `public/build/assets` — o gate mediria zero casos');

    const css = arquivos.map((f) => fs.readFileSync(path.join(dir, f), 'utf8')).join('\n');
    assert.ok(css.length > 10000, `o CSS construído saiu curto demais (${css.length} bytes)`);

    // ⚠️⚠️ **ESCAPE GERAL, de UMA fonte só, escrito pela FERRAMENTA de escrita.** O
    // heredoc desta máquina **COME UMA BARRA INVERTIDA**, e foi assim que um probe
    // reportou NOVE classes "faltando" que ESTÃO no CSS (§14.1 da 189-06-SUMMARY).
    const escapar = (classe) => classe.replace(/[.*+?^${}()|[\]\\/]/g, (c) => `\\${c}`);

    // ⚠️⚠️ ISCA DO PRÓPRIO ESCAPE (é o furo de ferramenta da 189-06, travado): ele
    // escapa de verdade? Se não escapasse, TODA classe sairia "faltando".
    assert.equal(escapar('h-[30px]'), 'h-\\[30px\\]', 'o escape de classe está QUEBRADO — a sonda reportaria "faltando" para classe que ESTÁ no CSS');
    assert.equal(escapar('bg-pub-page/[0.85]'), 'bg-pub-page\\/\\[0\\.85\\]', 'o escape da barra/ponto/colchete está QUEBRADO');

    const EXIGIDAS = [
        'bg-pub-page/[0.85]',
        'px-[11px]',
        'h-[30px]',
        'bottom-[10px]',
        'right-[10px]',
        'gap-[6px]',
        'border-pub-danger/[0.45]',
    ];

    const faltando = EXIGIDAS.filter((c) => !css.includes(`.${escapar(c)}`));
    assert.deepEqual(faltando, [], 'classe do §D/§C da `189-FID01.md` que NÃO chegou ao CSS construído');

    // ⚠️⚠️ A ISCA: uma classe que NÃO existe **no projeto inteiro** tem de sair
    // FALTANDO. Sem ela, "0 faltando" poderia ser uma sonda cega.
    // ⚠️⚠️ **ISCA ERRADA, MEDIDA E TROCADA — e o achado é do briefing ao contrário:**
    // a primeira redação usava `bg-pub-page/[0.86]` "porque não existe". Ela
    // **EXISTE**: é a classe do `PublicadorHeader.jsx:174` (o header global,
    // INTOCÁVEL), e a isca REPROVAVA com tudo certo. ➡️ As três abaixo foram
    // medidas com `grep -r` em `resources/` **e** no CSS construído: ZERO nas duas
    // pontas, como o plano manda.
    for (const inexistente of ['bg-pub-page/[0.87]', 'px-[1234px]', 'gap-[61px]']) {
        assert.equal(css.includes(`.${escapar(inexistente)}`), false, `a isca \`${inexistente}\` ESTÁ no CSS — a sonda não distingue nada`);
    }

    // ⚠️ E a prova de que a sonda NÃO é cega: a classe do header (que existe por
    // razão alheia a esta fase) é ACHADA. *"Isca que devolve ZERO é ACHADO."*
    assert.ok(css.includes(`.${escapar('bg-pub-page/[0.86]')}`), 'a sonda de CSS está CEGA: ela não acha nem a classe do header, que ESTÁ no CSS');

    // ⚠️⚠️ **O CORPO DA REGRA, e o DUPLO escape que a primeira redação errou.** Para
    // `String.includes` basta a forma **CSS-escapada** (`bg-pub-page\/\[0\.85\]`).
    // Para `RegExp` aquelas barras invertidas precisam ser escapadas **de novo** —
    // sem isso o padrão casa o texto SEM barra, que não existe no CSS, e o gate
    // reportava "(sem corpo)" para uma regra que está lá. Duas camadas de escape,
    // de UMA fonte só.
    const paraRegex = (classe) => escapar(classe).replace(/[.*+?^${}()|[\]\\]/g, (c) => `\\${c}`);

    // ⚠️ ISCA DO SEGUNDO ESCAPE: ele enxerga a regra real?
    assert.ok(
        new RegExp(`\\.${paraRegex('h-[30px]')}\\s*\\{`).test(css),
        'o segundo escape (para RegExp) está QUEBRADO — o gate reportaria "sem corpo" para regra que ESTÁ no CSS',
    );
    assert.equal(
        new RegExp(`\\.${paraRegex('h-[307px]')}\\s*\\{`).test(css),
        false,
        'o segundo escape casa classe INEXISTENTE — ele está mais largo que o invariante',
    );

    // ⚠️⚠️ **A OPACIDADE EM `background` SAI HEX DE 8 DÍGITOS** — e a forma real é
    // LIDA do CSS, não presumida (o plano manda ler antes de escrever a regex).
    const corpoDe = (classe) => (css.match(new RegExp(`\\.${paraRegex(classe)}\\s*\\{[^}]*\\}`)) || [])[0] ?? '';

    const regra = corpoDe('bg-pub-page/[0.85]');
    assert.ok(regra.length > 0, 'não achei o CORPO da regra de `bg-pub-page/[0.85]` — provar pela presença do seletor é mais fraco que provar pelo corpo');
    assert.match(regra, /background-color:\s*(#[0-9a-fA-F]{6,8}|rgba?\()/, `o corpo da regra de \`bg-pub-page/[0.85]\` não tem cor de fundo: ${regra}`);

    const regraBorda = corpoDe('border-pub-danger/[0.45]');
    assert.ok(regraBorda.length > 0, 'não achei o CORPO da regra de `border-pub-danger/[0.45]`');
    assert.match(regraBorda, /border-color:\s*(#[0-9a-fA-F]{6,8}|rgba?\()/, `o corpo da regra de \`border-pub-danger/[0.45]\` não tem cor de borda: ${regraBorda}`);

    // ⚠️ E as três GEOMETRIAS do botão de abrir, também pelo CORPO.
    for (const [classe, propriedade] of [['h-[30px]', /height:\s*30px/], ['px-[11px]', /padding-(left|right):\s*11px/], ['gap-[6px]', /gap:\s*6px/]]) {
        const corpo = corpoDe(classe);
        assert.ok(corpo.length > 0, `não achei o CORPO da regra de \`${classe}\``);
        assert.match(corpo, propriedade, `o corpo de \`${classe}\` não tem a propriedade esperada: ${corpo}`);
    }
});

test('⚠️ BUILD: a página está no manifest (lido pelo JSON) e a isca NÃO está 189_07', (t) => {
    const caminho = path.resolve(RAIZ, REL_CSS_CONSTRUIDO, 'manifest.json');
    if (!fs.existsSync(caminho)) {
        t.skip('SKIP NOMEADO: `public/build/manifest.json` não existe — rode `npm run build` antes deste gate');
        return;
    }

    // ⚠️⚠️ Pelo JSON, nunca por `grep` no texto: **página fora do manifest SOME da
    // tela**, e o `grep` casaria a chave dentro de outro valor.
    const manifest = JSON.parse(fs.readFileSync(caminho, 'utf8'));
    const chaves = Object.keys(manifest);

    assert.ok(chaves.length > 50, `o manifest tem só ${chaves.length} entradas — a leitura está quebrada`);
    assert.ok(chaves.includes(REL_PAGINA), `⚠️⚠️ \`${REL_PAGINA}\` NÃO está no manifest — a página SOME da tela`);
    assert.equal(chaves.includes(ISCA_AUSENTE), false, `a isca \`${ISCA_AUSENTE}\` está no manifest — ela existe para provar que a leitura distingue`);

    // ⚠️⚠️ **NENHUMA ASSERÇÃO SOBRE AS PEÇAS AQUI, e a razão é medida:** peça
    // importada pela página **não** ganha entrada própria no manifest (ela entra no
    // chunk da página). Uma asserção `ok(… || true)` passaria sempre e seria
    // **asserção vazia** — a família que vira falso positivo depois. ➡️ A presença
    // das peças no bundle é provada pelo CSS construído (o gate acima), que só tem
    // as classes delas se o scanner as leu.
    const entradaDaPagina = manifest[REL_PAGINA];
    assert.equal(typeof entradaDaPagina?.file, 'string', 'a entrada da página no manifest não tem `file` — o Vite não gerou o ativo dela');
});
