import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — GATE-03: o vocabulário NOVO, fechado.
//
// POR QUE EXISTE: a instrução do usuário (11/10/2026) foi "reproduza fielmente
// o layout, já tentei fazer um redesign e não saiu como eu esperava PORQUE VOCÊ
// ADAPTAVA MUITO O NOVO LAYOUT AO SISTEMA ATUAL". Este gate é a trava mecânica
// contra essa adaptação: nos arquivos desenhados nesta milestone, 13,5px tem de
// ser `text-[13.5px]` e não `text-sm`; a cor vem por NOME de token `pub-*` e
// não por hex; `ecf-bg`, `ecf-card`, `ecf-yellow`, `font-display` e
// `border-white/[0.08]` estão vetados (FID-04).
//
// É o irmão novo de `publicador-entrada.test.js`: aquele trava o que já existe,
// este trava o que nasce agora.
//
// ⚠️ A lista é uma CONSTANTE, não um glob. Glob pegaria arquivo que ainda não
// nasceu e o gate passaria por omissão — os planos 177-04/05/06 ACRESCENTAM os
// arquivos do header a esta lista quando eles existirem.
//
// ⚠️ Toda leitura passa por `lerSemComentarios`: a prosa em pt-BR deste projeto
// cita os próprios identificadores e os próprios hex do protótipo ("o da frente
// preenchido #FACC15"), e um gate cru passaria/reprovaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Mlb/Publicador/identidade';
const DIR_HEADER = 'resources/js/Components/Mlb/Publicador/header';
const DIR_VG = 'resources/js/Components/Mlb/Publicador/visaogeral';

const ARQUIVOS_NOVOS = [
    `${DIR}/identidade.js`,
    `${DIR}/GlyphDaFase.jsx`,
    `${DIR}/RotuloDaFase.jsx`,
    `${DIR}/SeloDoTipo.jsx`,
    `${DIR}/SeloDeStatusDaPublicacao.jsx`,
    `${DIR}/MapaDeCobertura.jsx`,
    `${DIR}/BadgeNova.jsx`,
    `${DIR}/TileDoProduto.jsx`,
    // Fase 177, plano 04 — a casca do header global e os atalhos de teclado.
    `${DIR_HEADER}/PublicadorHeader.jsx`,
    `${DIR_HEADER}/atalhosDoHeader.js`,
    // Fase 177, plano 05 — as abas-pílula da linha 2.
    `${DIR_HEADER}/AbasDoHeader.jsx`,
    // Fase 177, plano 06 — a identidade da conta na linha 1: o seletor de
    // empresa (botão + dropdown de 360px) e a pill de conexão (+ popover de
    // 320px), mais o módulo que virou a fonte única das contas recentes.
    `${DIR_HEADER}/SeletorDeEmpresaDoHeader.jsx`,
    `${DIR_HEADER}/ConexaoDoHeader.jsx`,
    'resources/js/Components/Mlb/Publicador/recentesDoPublicador.js',
    // Fase 178, plano 02 — o corpo do modal "Nova publicação": os literais de
    // tela (com a busca local) e o painel medida por medida.
    `${DIR_HEADER}/novaPublicacao.js`,
    `${DIR_HEADER}/PainelNovaPublicacao.jsx`,
    // Fase 178, plano 03 — a casca portada (620px a `top:110px`, o backdrop, o
    // fetch lazy e o foco no input).
    `${DIR_HEADER}/ModalNovaPublicacao.jsx`,
    // ─── Fase 179 — os 12 arquivos da Visão geral (cockpit) ───
    //
    // ⚠️ A lista REAL foi conferida com
    // `git diff --name-only --diff-filter=A 898d68c2..HEAD -- resources/js`
    // (11 arquivos já commitados) **mais** o `CockpitDaConta.jsx` deste plano
    // = 12. Não é o número do plano repetido: ele falava em 13, e o 13º
    // (`BlocosPreservados.jsx`) NÃO nasceu depois da decisão do usuário de
    // 12/10/2026 de tirar "Produtos por fase" e "Quem publicou" da tela.
    //
    // Plano 179-06 — a Jornada dos produtos:
    `${DIR_VG}/jornada.js`,
    `${DIR_VG}/JornadaDosProdutos.jsx`,
    // Plano 179-07 — "O que fazer agora", "Saúde da conta" e "Publicações no ML":
    `${DIR_VG}/acoes.js`,
    `${DIR_VG}/OQueFazerAgora.jsx`,
    `${DIR_VG}/SaudeDaConta.jsx`,
    `${DIR_VG}/PublicacoesNoMercadoLivre.jsx`,
    // Plano 179-08 — banner, faixa de Desempenho, recentes e atividade:
    `${DIR_VG}/recentes.js`,
    `${DIR_VG}/BannerDeIntegracao.jsx`,
    `${DIR_VG}/FaixaDeDesempenho.jsx`,
    `${DIR_VG}/PublicacoesRecentes.jsx`,
    `${DIR_VG}/AtividadeRecente.jsx`,
    // Plano 179-09 — a composição:
    `${DIR_VG}/CockpitDaConta.jsx`,
];

/**
 * Os corpos de texto do README (§Design Tokens e as telas). FID-03 é o motivo
 * de 13.5 e 14.5 estarem aqui: o passo do Tailwind não tem meio pixel, então
 * quem arredondasse para `text-sm` (14px) mudaria o layout.
 *
 * ⚠️ Fase 179, plano 09 — `8.5` entrou, e é uma AMPLIAÇÃO da lista permitida,
 * não um endurecimento. Por isso a medida fica escrita ao lado, para a próxima
 * pessoa saber que não é arredondamento nem descuido:
 *
 *   · `new_layout_publicador/Visao Geral.dc.html` declara `font-size:8.5px`
 *     **3 vezes** — as iniciais do avatar de 24px (`:104`), as do thumb de
 *     26px (`:197`) e o ícone de 22px do sub-item (`:210`).
 *     ⚠️ As linhas do plano (`:97`/`:192`/`:204`) estão deslocadas; estas
 *     foram medidas com `grep -n`.
 *   · E `text-[8.5px]` aparece **3 vezes** nos arquivos novos:
 *     `JornadaDosProdutos.jsx` (1) e `OQueFazerAgora.jsx` (2). Nenhum dos 5
 *     arquivos do plano 179-08 o usa — medido com `grep`, não presumido.
 *
 * Três declarações no protótipo, três usos no código. Nenhum valor foi
 * REMOVIDO desta lista.
 */
const TAMANHOS_PERMITIDOS = new Set([
    8.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34,
]);

const FONTES = new Map(ARQUIVOS_NOVOS.map((caminho) => [caminho, lerSemComentarios(caminho)]));

/**
 * ⚠️ O gate que impede a OMISSÃO — Fase 179, plano 09.
 *
 * `ARQUIVOS_NOVOS` é uma constante de propósito (ver o comentário do topo:
 * glob pegaria arquivo que ainda não nasceu). Mas constante tem o defeito
 * simétrico: **esquecer um arquivo faz este gate passar sem acusar nada**, e
 * aquele arquivo fica sem conferência contra `text-sm`, `ecf-*`,
 * `font-display`, hex literal e breakpoint.
 *
 * Este teste fecha o furo para os dois diretórios que são 100% desta
 * milestone: `identidade/` (Fase 177) e `visaogeral/` (Fase 179). Arquivo novo
 * ali dentro reprova até alguém decidir, à mão, que ele entra na lista.
 *
 * ⚠️ `header/` fica FORA desta checagem de propósito: aquele diretório tem
 * arquivos que NÃO são desta milestone, e varrê-lo inteiro reprovaria trabalho
 * de outra fase.
 */
const DIRETORIOS_FECHADOS = [DIR, DIR_VG];

test('GATE-03 — nenhum arquivo de `identidade/` ou `visaogeral/` fica FORA de ARQUIVOS_NOVOS', async (contexto) => {
    for (const diretorio of DIRETORIOS_FECHADOS) {
        await contexto.test(`${diretorio} — a lista cobre exatamente os .js/.jsx da pasta`, () => {
            const noDisco = readdirSync(resolve(import.meta.dirname, '../..', diretorio))
                .filter((nome) => nome.endsWith('.js') || nome.endsWith('.jsx'))
                .map((nome) => `${diretorio}/${nome}`)
                .sort();
            const declarados = ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${diretorio}/`)).sort();

            // Sem isto, um diretório vazio (ou renomeado) passaria calado.
            assert.ok(noDisco.length > 0, `${diretorio} não tem nenhum .js/.jsx — o caminho está errado?`);
            assert.deepEqual(
                noDisco,
                declarados,
                `${diretorio}: arquivo no disco fora de ARQUIVOS_NOVOS (ou o contrário). Arquivo fora da lista passa SEM conferência de vocabulário — e o gate passaria POR OMISSÃO.`,
            );
        });
    }

    await contexto.test('os 12 arquivos da Fase 179 estão na lista', () => {
        assert.equal(
            ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${DIR_VG}/`)).length,
            12,
            'a Fase 179 criou 12 arquivos em `visaogeral/` (11 nas waves 1-2 + CockpitDaConta)',
        );
    });
});

test('GATE-03 — vocabulário novo fechado, arquivo por arquivo', async (contexto) => {
    for (const caminho of ARQUIVOS_NOVOS) {
        const fonte = FONTES.get(caminho);

        // ─── 1. tamanhos de texto: só os do README, e nunca a escala nomeada ───
        await contexto.test(`${caminho} — todo text-[Xpx] está na lista do README e nenhuma escala nomeada`, () => {
            const encontrados = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => Number(m[1]));
            for (const tamanho of encontrados) {
                assert.ok(
                    TAMANHOS_PERMITIDOS.has(tamanho),
                    `${caminho}: text-[${tamanho}px] não está na lista de tamanhos do README`,
                );
            }
            // FID-03: `text-sm` NÃO é 13,5px. A escala nomeada arredonda.
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        });

        // ─── 2. pesos: só 400/500/600/700 ───
        await contexto.test(`${caminho} — só os 4 pesos do README (400/500/600/700)`, () => {
            assert.doesNotMatch(fonte, /font-(thin|extralight|light|extrabold|black)\b/);
        });

        // ─── 3. FID-04: nada da camada antiga ───
        await contexto.test(`${caminho} — FID-04: nenhum token ecf-*, nenhum font-display, nenhum border-white/[0.08]`, () => {
            assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/);
            assert.doesNotMatch(fonte, /\bfont-display\b/);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/);
        });

        // ─── 4. TOK-15: a cor vem por nome de token, nunca por hex ───
        await contexto.test(`${caminho} — TOK-15: nenhum hex literal`, () => {
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/);
        });

        // ─── 5. FID-05: nenhum breakpoint novo ───
        await contexto.test(`${caminho} — FID-05: nenhum breakpoint`, () => {
            assert.doesNotMatch(fonte, /\b(sm|md|lg|xl|2xl):/);
        });

        // ─── 6. comportamento que FICA ───
        await contexto.test(`${caminho} — sem o select do shadcn e sem dangerouslySetInnerHTML`, () => {
            assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
            assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
        });
    }
});

test('GATE-03 — IDENT-08: a identidade é compartilhada, não copiada', async (contexto) => {
    await contexto.test('oklch( aparece SÓ em identidade.js', () => {
        for (const [caminho, fonte] of FONTES) {
            const esperado = caminho.endsWith('identidade.js');
            assert.equal(
                fonte.includes('oklch('),
                esperado,
                esperado
                    ? 'identidade.js deixou de calcular o tom do tile'
                    : `${caminho} recalcula oklch — o tom do produto tem UMA casa (identidade.js)`,
            );
        }
    });

    await contexto.test('a geometria do retângulo do glyph aparece SÓ em GlyphDaFase.jsx', () => {
        // ⚠️ Fase 177, plano 05 — a asserção passou a ser sobre o PAR de
        // medidas, e isso é um ENDURECIMENTO, não um relaxamento.
        //
        // Antes conferia `w-[15px]` e `h-[18px]` cada um por conta própria, e
        // `h-[18px]` solto NÃO é "o glyph": a pílula de contador das abas tem
        // 18px de altura por medida do protótipo
        // (`PublicadorHeader.dc.html:175`) e não redesenha glyph nenhum. O que
        // identifica o retângulo da marca é a geometria INTEIRA —
        // `h-[18px] w-[15px] rounded-[4px] border-[1.6px]` — mais a presença
        // das duas medidas juntas em qualquer ordem. É a mesma lição dos 4
        // furos do gate do plano 177-04: substring compartilhada não prova
        // nada; o que prova é o conjunto no elemento certo.
        const GEOMETRIA = 'h-[18px] w-[15px] rounded-[4px] border-[1.6px]';

        for (const [caminho, fonte] of FONTES) {
            const esperado = caminho.endsWith('GlyphDaFase.jsx');
            assert.equal(
                fonte.includes(GEOMETRIA),
                esperado,
                esperado
                    ? 'GlyphDaFase.jsx perdeu a geometria do retângulo da marca'
                    : `${caminho} redesenha o glyph — o glyph tem UMA casa`,
            );
            if (!esperado) {
                assert.ok(
                    !(fonte.includes('w-[15px]') && fonte.includes('h-[18px]')),
                    `${caminho} combina as duas medidas do retângulo do glyph — o glyph tem UMA casa`,
                );
            }
        }
    });

    await contexto.test('quem precisa do glyph IMPORTA o componente, não reimplementa', () => {
        const rotulo = FONTES.get(`${DIR}/RotuloDaFase.jsx`);
        assert.match(rotulo, /from '\.\/GlyphDaFase'/);
        // E o rótulo é quem dá o nome acessível: o glyph é decorativo.
        // ⚠️ GATE-05: com o `=` no fim. `/aria-hidden/` casaria a palavra dentro
        // de um comentário ou de uma classe e seria asserção vazia.
        assert.match(FONTES.get(`${DIR}/GlyphDaFase.jsx`), /aria-hidden=/);
    });

    await contexto.test('o tile IMPORTA iniciais/tomDoProduto de identidade.js', () => {
        const tile = FONTES.get(`${DIR}/TileDoProduto.jsx`);
        assert.match(tile, /from '\.\/identidade'/);
        assert.match(tile, /iniciais/);
        assert.match(tile, /tomDoProduto/);
    });

    await contexto.test('o texto do servidor passa por textoSeguro nos componentes que o renderizam', () => {
        for (const arquivo of ['SeloDoTipo.jsx', 'SeloDeStatusDaPublicacao.jsx']) {
            const fonte = FONTES.get(`${DIR}/${arquivo}`);
            assert.match(fonte, /from '\.\.\/BarraDaConta'/, `${arquivo} recriou o textoSeguro`);
            assert.match(fonte, /textoSeguro\(/);
        }
    });
});

test('GATE-03 — a camada de tokens é a única casa dos hex', async (contexto) => {
    const config = lerSemComentarios('tailwind.config.js');

    await contexto.test('tailwind.config.js continua guardando o amarelo do Publicador', () => {
        assert.match(config, /#FACC15/);
    });

    await contexto.test('nenhum componente de identidade repete esse hex', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /#FACC15/i, `${caminho} escreveu o hex em vez de usar pub-accent`);
        }
    });
});

test('GATE-03 — os literais de IDENT-02 e IDENT-05 estão byte a byte', async (contexto) => {
    const rotulo = FONTES.get(`${DIR}/RotuloDaFase.jsx`);
    const mapa = FONTES.get(`${DIR}/MapaDeCobertura.jsx`);

    await contexto.test('RotuloDaFase: "Simples · 1 un." / "Combo · N un." / "Fora do Publicador"', () => {
        assert.ok(rotulo.includes('Simples · 1 un.'), 'literal da Fase 1 parafraseado');
        assert.ok(rotulo.includes('Fora do Publicador'), 'literal do Legado parafraseado');
        assert.match(rotulo, /Combo · \$\{unidades\} un\./, 'literal da Fase 2 parafraseado');
    });

    await contexto.test('MapaDeCobertura: "publicada" / "não criada" no nome acessível', () => {
        assert.ok(mapa.includes("'publicada'"), mapa);
        assert.ok(mapa.includes("'não criada'"));
        // E o título é o MESMO texto do aria-label (IDENT-07).
        assert.match(mapa, /title=\{descricao\}/);
        assert.match(mapa, /aria-label=\{descricao\}/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 07 — o header antigo não volta por engano.
//
// ⚠️ AQUI A LISTA É UM GLOB, de propósito, e é o OPOSTO da constante
// `ARQUIVOS_NOVOS` acima: lá a constante existe para que arquivo novo não entre
// no gate sem alguém olhar; aqui o glob existe para que PÁGINA nova não escape
// dele. Uma página criada amanhã dentro de `Pages/Mlb/Publicador/` que montasse
// `BarraDaConta`/`AbasDaConta` passaria calada por uma lista fixa.
//
// `import { textoSeguro } from '.../BarraDaConta'` continua PERMITIDO: a função
// mora naquele arquivo desde a Fase 172 e o header novo também a importa de lá
// (uma regra, uma casa). O que está vetado é o COMPONENTE: o `default` de
// `BarraDaConta` e qualquer import de `AbasDaConta`.
//
// ⚠️ ESCOPO: só `Pages/Mlb/Publicador/`. As três páginas de `/mlb/anuncios`
// (`AnunciarMassa`, `AnunciosHistorico`, `MeusAnuncios`) são do plano 177-08 e
// ainda montam o header antigo — incluí-las aqui reprovaria trabalho alheio em
// andamento.
// ═══════════════════════════════════════════════════════════════════════════

const DIR_PAGINAS_PUBLICADOR = 'resources/js/Pages/Mlb/Publicador';

const PAGINAS_DO_PUBLICADOR = readdirSync(resolve(import.meta.dirname, '../..', DIR_PAGINAS_PUBLICADOR))
    .filter((nome) => nome.endsWith('.jsx'))
    .map((nome) => `${DIR_PAGINAS_PUBLICADOR}/${nome}`);

test('GATE-01 — nenhuma página do Publicador monta BarraDaConta nem AbasDaConta (o header é o PublicadorHeader)', async (contexto) => {
    // Sem isto, um glob que não achasse nada passaria vazio e por omissão.
    assert.ok(PAGINAS_DO_PUBLICADOR.length >= 7, `esperava ao menos 7 páginas, achei ${PAGINAS_DO_PUBLICADOR.length}`);

    for (const caminho of PAGINAS_DO_PUBLICADOR) {
        const fonte = lerSemComentarios(caminho);

        await contexto.test(`${caminho} — zero import de AbasDaConta`, () => {
            // Qualquer forma de import daquele módulo, inclusive com o default
            // renomeado (`import Abas from '.../AbasDaConta'`).
            assert.doesNotMatch(fonte, /import[^;]*from\s*'[^']*\/AbasDaConta'/);
        });

        await contexto.test(`${caminho} — de BarraDaConta só o named textoSeguro, nunca o default`, () => {
            for (const m of fonte.matchAll(/import\s+([^;]*?)\s+from\s*'[^']*\/BarraDaConta'/g)) {
                // `{ textoSeguro }` passa; `BarraDaConta`, `Barra` ou
                // `BarraDaConta, { textoSeguro }` reprovam.
                assert.ok(
                    m[1].trimStart().startsWith('{'),
                    `${caminho} importou o COMPONENTE de BarraDaConta: ${m[0]}`,
                );
            }
        });

        await contexto.test(`${caminho} — zero <BarraDaConta> e zero <AbasDaConta> no JSX`, () => {
            assert.doesNotMatch(fonte, /<BarraDaConta[\s/>]/);
            assert.doesNotMatch(fonte, /<AbasDaConta[\s/>]/);
        });
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 07 — HEAD-14: o header existe e nasce no lugar certo.
//
// POR QUE ESTE GATE PRECISOU EXISTIR: o teste acima (GATE-01) prova que o
// header ANTIGO não volta, e isso não é a mesma coisa que provar que o NOVO
// está lá. Medido: apagar `<PublicadorHeader …>` de `Produto.jsx` não reprovava
// NADA na suíte inteira — aquela página não tinha gate nenhum. E o
// `PublicacaoEmLote` só era lido por testes que não olhavam o topo da tela.
//
// E não basta "está no arquivo": o header TEM de vir ANTES do container do
// corpo. É ele que carrega o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>`
// (HEAD-14); dentro de um container com `px-8` ele não encostaria nas bordas e
// FID-05 (conteúdo de 1600px com 36px de padding) ficaria impossível.
//
// ⚠️ Fase 179, plano 09 — REAPONTE DE VALOR, não remoção nem afrouxamento.
// Antes o container do corpo era procurado por UM literal fixo
// (`mx-auto max-w-[1240px]`) em todas as 6 páginas. A Visão geral virou o
// cockpit e o container dela passou a ser o do protótipo
// (`mx-auto flex max-w-[1600px] …`, FID-05: 1600px de conteúdo com 36px de
// padding — exatamente o que este comentário sempre disse que era o objetivo).
// O que a asserção garante continua sendo o MESMO: o header nasce ANTES do
// container do corpo. O que mudou é o literal com que cada página é
// localizada, e ele virou uma tabela por página para que nenhuma delas seja
// medida pelo container de outra.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As 6 páginas por conta que montam o header global (plano 177-07), e a aba
 * que CADA uma marca como ativa.
 *
 * ⚠️ O valor esperado está aqui porque "tem header" não bastava: a prova por
 * mutação achou um FURO — trocar `aba="configuracoes"` por `aba="produtos"` na
 * tela de Configurações passava calado, e a tela marcaria a aba Produtos como
 * ativa estando em outro lugar.
 *
 * ⚠️ `Produto.jsx` é `produtos` de propósito: é o DETALHE de um produto, não
 * uma aba nova. `PublicacaoEmLote.jsx` também. E `Configuracoes.jsx` é
 * `configuracoes`, que NÃO é uma das quatro abas — Configurações virou o ícone
 * de engrenagem da linha 1 (HEAD-08), e o valor serve para nenhuma das quatro
 * ficar ativa.
 */
/**
 * O literal que localiza o container do corpo de CADA página.
 *
 * ⚠️ É uma tabela e não um literal único porque a Visão geral (Fase 179) mede
 * 1600px e as outras cinco seguem em 1240px. Um literal único voltaria a
 * reprovar/passar pelo motivo errado na primeira página que mudasse de medida.
 */
const CONTAINER_POR_PAGINA = new Map([
    ['VisaoGeral.jsx', 'mx-auto flex max-w-[1600px]'],
    ['Produtos.jsx', 'mx-auto max-w-[1240px]'],
    ['Produto.jsx', 'mx-auto max-w-[1240px]'],
    ['Alavancas.jsx', 'mx-auto max-w-[1240px]'],
    ['Configuracoes.jsx', 'mx-auto max-w-[1240px]'],
    ['PublicacaoEmLote.jsx', 'mx-auto max-w-[1240px]'],
]);

const ABA_POR_PAGINA = new Map([
    ['VisaoGeral.jsx', 'visao-geral'],
    ['Produtos.jsx', 'produtos'],
    ['Produto.jsx', 'produtos'],
    ['Alavancas.jsx', 'alavancas'],
    ['Configuracoes.jsx', 'configuracoes'],
    ['PublicacaoEmLote.jsx', 'produtos'],
]);

const PAGINAS_COM_HEADER = [...ABA_POR_PAGINA.keys()];

/**
 * As que NÃO têm header de conta, com o motivo escrito:
 *  · `Editor.jsx` tem a própria casca de etapas (e é território de outro dev —
 *    REG-03 proíbe tocá-la nesta fase);
 *  · `Tarefas.jsx` é a fila de alavancas de TODAS as contas numa lista só, sem
 *    conta aberta (está escrito no topo do arquivo desde a Fase 172).
 */
const PAGINAS_SEM_HEADER = ['Editor.jsx', 'Tarefas.jsx'];

test('HEAD-14 — as 6 páginas por conta montam o PublicadorHeader antes do container do corpo', async (contexto) => {
    // ⚠️ Esta primeira asserção é o que impede o gate de passar POR OMISSÃO:
    // página nova em `Pages/Mlb/Publicador/` não entra calada em nenhuma das
    // duas listas — alguém tem de decidir se ela tem header de conta ou não.
    await contexto.test('as duas listas cobrem exatamente os .jsx da pasta', () => {
        const declaradas = [...PAGINAS_COM_HEADER, ...PAGINAS_SEM_HEADER].sort();
        const naPasta = PAGINAS_DO_PUBLICADOR.map((c) => c.split('/').pop()).sort();
        assert.deepEqual(naPasta, declaradas);
    });

    await contexto.test('e toda página com header tem container declarado (nenhuma medida por omissão)', () => {
        assert.deepEqual([...CONTAINER_POR_PAGINA.keys()].sort(), [...PAGINAS_COM_HEADER].sort());
    });

    for (const nome of PAGINAS_COM_HEADER) {
        const fonte = lerSemComentarios(`${DIR_PAGINAS_PUBLICADOR}/${nome}`);

        await contexto.test(`${nome} — importa e monta <PublicadorHeader>, uma vez só`, () => {
            assert.match(fonte, /import PublicadorHeader from '@\/Components\/Mlb\/Publicador\/header\/PublicadorHeader'/);
            assert.equal((fonte.match(/<PublicadorHeader\b/g) ?? []).length, 1);
        });

        await contexto.test(`${nome} — marca a aba ativa certa (aba="${ABA_POR_PAGINA.get(nome)}")`, () => {
            // ⚠️ A prop `aba` é lida DA TAG, não procurada no arquivo inteiro:
            // `aba="produtos"` solto apareceria em qualquer outro lugar da
            // página e a asserção passaria pelo motivo errado.
            const tag = fonte.match(/<PublicadorHeader\b[\s\S]*?(?:\/>|>)/);
            assert.ok(tag, 'tag de <PublicadorHeader> não encontrada');
            assert.match(tag[0], new RegExp(`aba="${ABA_POR_PAGINA.get(nome)}"`));
            // E só UMA prop `aba` na tag (nunca duas, nunca a de outra tela).
            assert.equal((tag[0].match(/\baba="/g) ?? []).length, 1);
        });

        await contexto.test(`${nome} — o header vem ANTES do container do corpo (HEAD-14)`, () => {
            const marca = CONTAINER_POR_PAGINA.get(nome);
            assert.ok(marca, `${nome} não tem container declarado em CONTAINER_POR_PAGINA`);
            const header = fonte.indexOf('<PublicadorHeader');
            const corpo = fonte.indexOf(marca);
            assert.ok(header !== -1, 'header não encontrado');
            assert.ok(corpo !== -1, `container do corpo ("${marca}") não encontrado em ${nome}`);
            assert.ok(header < corpo, `${nome}: o header nasceu DENTRO do container do corpo — com o padding dele o -mx-6 não encosta nas bordas`);
        });

        await contexto.test(`${nome} — passa cabecalho com o ?? null explícito`, () => {
            // O default de parâmetro só cobre `undefined`; prop NULA numa
            // recarga parcial do Inertia já derrubou tela neste projeto.
            assert.match(fonte, /cabecalho=\{cabecalho \?\? null\}/);
        });
    }

    for (const nome of PAGINAS_SEM_HEADER) {
        await contexto.test(`${nome} — segue SEM header de conta, de propósito`, () => {
            const fonte = lerSemComentarios(`${DIR_PAGINAS_PUBLICADOR}/${nome}`);
            assert.doesNotMatch(fonte, /<PublicadorHeader\b/);
        });
    }
});
