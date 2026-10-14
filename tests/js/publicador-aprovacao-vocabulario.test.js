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
// Fase 186, plano 02 — o VOCABULÁRIO DO PORTÃO e o selo de andamento interno.
//
// ⚠️⚠️ A PERGUNTA OBRIGATÓRIA (GATE-01) — *"o que precisaria estar errado no
// mundo para este gate falhar?"* — respondida POR BLOCO, no comentário de cada
// um. Bloco sem resposta concreta é decorativo e não entra.
//
// O bug que este arquivo existe para impedir é o achado S3 da verificação da
// fase, e ele é de uma classe específica: **o selo que renderiza VAZIO**.
// Na primeira redação, o plano 186-02 usava as chaves INGLESAS do protótipo
// (`awaiting`, `ecf_review`, `changes`) e o plano 186-04 usava as pt-BR do banco
// (`aguardando_aprovacao`, `em_revisao_ecf`, `ajustes_solicitados`), sem nenhum
// tradutor entre os dois — e o componente devolvia `null` para chave
// desconhecida. Resultado: em produção os 8 estados sairiam como um retângulo
// com cor e SEM TEXTO, e a suíte inteira ficaria verde, porque "não quebrou" é
// o que um teste de render mede por omissão.
//
// Daí as duas asserções que são o coração do arquivo:
//   1. os 8 estados pt-BR rendem o RÓTULO de VOC-02 — um `test()` por estado,
//      para a CONTAGEM ser o sinal (laço com 8 asserções dentro reporta 1);
//   2. as 8 chaves INGLESAS rendem TEXTO NÃO-VAZIO dentro do elemento — não
//      basta "não lançou".
//
// ⚠️ TODOS os bundles são montados AQUI NO TOPO, antes do primeiro `test()`.
// O `after()` do `node --test` dispara quando os testes registrados terminam e
// já matou um arquivo INTEIRO deste projeto sem nenhum teste falhar (sintoma:
// 37 testes em vez de 94).
//
// ⚠️ MEDIDO POR SCRIPT em `design_handoff_publicador/Aprovacao.dc.html`
// (`grep -n`, 14/10/2026), nunca copiado da tabela do plano:
//   `CRIT` :961 · `DEF` :962 · `STM` :963-971 · `BLK` :973 · `SHORT` :974 ·
//   `SRC` :1125 · `ST` :1212 · `ORD` :1215 · `cta` :1240 · a pill :170-:172.
// Os 6 toasts: `design_handoff_publicador/README-v2.md`:294-:300.
// ═══════════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;

const ARQUIVOS_DO_PLANO = [REL_ANDAMENTO, REL_VOCABULARIO];

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Este projeto
// acumulou SETE caminhos fantasma em 5 fases, e gate que lê caminho inexistente
// não reprova: ele mede NADA e parece verde.
for (const caminho of [DIR_APROV, ...ARQUIVOS_DO_PLANO]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — o gate mediria NADA`);
    }
}

const STUB_INERTIA = path.join(__dirname, `.pub-aprov-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`, 'utf8');

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
        `.pub-aprov-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const MOD_ANDAMENTO = await montar(REL_ANDAMENTO);
const MOD_VOCABULARIO = await montar(REL_VOCABULARIO);

const {
    ANDAMENTOS,
    CHAVES_DE_ANDAMENTO,
    DO_PROTOTIPO,
    ORDEM_DA_FILA,
    LIVE,
    FAIXA_DE_ANDAMENTO,
    ehLive,
    abreRevisao,
    ctaDoAndamento,
    andamentoDe,
} = MOD_ANDAMENTO;

const {
    SELOS_DE_ORIGEM,
    ORIGEM_DO_PROTOTIPO,
    ABREVIACAO_DO_BLOQUEIO,
    ROTULO_DO_BLOQUEIO,
    BLOQUEIO_DO_PROTOTIPO,
    CRITERIOS,
    DEFEITOS,
    TOASTS,
    RESULTADO_ESTIMADO,
    MARGEM_DE_CONTRIBUICAO,
    INCOMPLETO,
    TUDO_CONFERIDO,
    BLOQUEADO_PREFIXO,
} = MOD_VOCABULARIO;

const FONTES = new Map(ARQUIVOS_DO_PLANO.map((c) => [c, lerSemComentarios(c)]));

// ─────────────────────────────────────────────────────────────────────────────
// A TABELA FID-01 — `STM` (`:963-971`), medida por script e transcrita aqui.
//
// ⚠️ Ela é a régua: os rótulos do código são comparados contra ESTA lista, e não
// contra a memória de quem escreveu. A ordem é a do protótipo.
// ─────────────────────────────────────────────────────────────────────────────
const STM_DO_PROTOTIPO = [
    ['preparing', 'Preparando'],
    ['awaiting', 'Aguardando aprovação'],
    ['ecf_review', 'Em revisão pela ECF'],
    ['changes', 'Ajustes solicitados'],
    ['approved', 'Aprovado'],
    ['publishing', 'Publicando'],
    ['failed', 'Falha na publicação'],
    ['published', 'Publicado'],
];

/** `SRC` (`:1125`) — os 3 selos de origem, medidos por script. */
const SRC_DO_PROTOTIPO = [
    ['conf', 'Confirmado'],
    ['est', 'Estimado'],
    ['miss', 'Pendente'],
];

/** `ORD` (`:1215`) — a ordenação da fila, pelas chaves do protótipo. */
const ORD_DO_PROTOTIPO = {
    failed: 0,
    awaiting: 1,
    ecf_review: 1,
    changes: 2,
    approved: 3,
    publishing: 4,
    published: 5,
    preparing: 6,
};

/** Os estados que trazem SPINNER em vez de bolinha (`:1253`: publishing ou preparing). */
const COM_SPINNER_NO_PROTOTIPO = ['preparing', 'publishing'];

// ═══════════════════════════════════════════════════════════════════════════════
// 1. AS CONTAGENS — 8/3/9/8/7/6/6
//
// O que precisaria estar errado para falhar: alguém acrescentar um 9º estado
// sem medir no protótipo, ou REMOVER um bloqueio/critério/defeito/toast na hora
// de "simplificar". Nenhum teste de render pega isso: a tela continuaria
// desenhando o que sobrou.
// ═══════════════════════════════════════════════════════════════════════════════

test('VOC — as contagens do vocabulário são as medidas no protótipo', async (contexto) => {
    const esperado = [
        ['ANDAMENTOS (STM)', ANDAMENTOS, 8],
        ['SELOS_DE_ORIGEM (SRC)', SELOS_DE_ORIGEM, 3],
        ['ABREVIACAO_DO_BLOQUEIO (SHORT)', ABREVIACAO_DO_BLOQUEIO, 9],
        ['ROTULO_DO_BLOQUEIO (BLK)', ROTULO_DO_BLOQUEIO, 8],
        ['CRITERIOS (CRIT)', CRITERIOS, 7],
        ['DEFEITOS (DEF)', DEFEITOS, 6],
        ['TOASTS (README §Toasts)', TOASTS, 6],
    ];

    for (const [nome, mapa, quantas] of esperado) {
        await contexto.test(`${nome} tem exatamente ${quantas} entradas`, () => {
            // ⚠️ A flag nasce DENTRO do laço (REND-04): o Rollup elimina
            // variável de escopo do componente usada só dentro de um callback,
            // e a disciplina vale também aqui para a próxima pessoa não
            // "otimizar" tirando a declaração de dentro.
            const chaves = Object.keys(mapa);
            assert.equal(chaves.length, quantas, `${nome}: ${chaves.length} entradas, esperava ${quantas}`);
        });
    }

    await contexto.test('FAIXA_DE_ANDAMENTO tem os 9 cards de `ST`, e o último é "Publicado hoje"', () => {
        assert.equal(FAIXA_DE_ANDAMENTO.length, 9);
        const ultimo = FAIXA_DE_ANDAMENTO[FAIXA_DE_ANDAMENTO.length - 1];
        // ⚠️ "Publicado hoje" é recorte DO DIA, não o estado inteiro. Trocar
        // pelo rótulo de `ANDAMENTOS.publicado` ("Publicado") faria a faixa
        // prometer o histórico completo e entregar o do dia.
        assert.equal(ultimo.rotulo, 'Publicado hoje');
        assert.equal(ultimo.chave, 'publicado');
        assert.equal(FAIXA_DE_ANDAMENTO[0].chave, 'todas', 'o 1º card é "sem filtro"');
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 2. AS CHAVES SÃO pt-BR — e é isto que fecha o S3
//
// O que precisaria estar errado para falhar: alguém voltar a usar as chaves do
// protótipo como chave de DADO (porque "a tabela do plano estava em inglês"),
// recriando o par JS-inglês × PHP-pt-BR sem tradutor.
// ═══════════════════════════════════════════════════════════════════════════════

test('VOC — as 8 chaves de dado são as do BANCO, em pt-BR', async (contexto) => {
    const DO_BANCO = [
        'preparando',
        'aguardando_aprovacao',
        'em_revisao_ecf',
        'ajustes_solicitados',
        'aprovado',
        'publicando',
        'falha',
        'publicado',
    ];

    await contexto.test('ANDAMENTOS tem exatamente as 8 chaves do PHP', () => {
        assert.deepEqual([...CHAVES_DE_ANDAMENTO].sort(), [...DO_BANCO].sort());
    });

    await contexto.test('a ORDEM das chaves é a do protótipo (a conferência FID-01 depende dela)', () => {
        assert.deepEqual(CHAVES_DE_ANDAMENTO, DO_BANCO);
    });

    await contexto.test('NENHUMA chave inglesa do protótipo é chave de ANDAMENTOS', () => {
        for (const [chaveDoPrototipo] of STM_DO_PROTOTIPO) {
            assert.ok(
                !Object.prototype.hasOwnProperty.call(ANDAMENTOS, chaveDoPrototipo),
                `"${chaveDoPrototipo}" virou chave de dado — é o bug S3 de volta`,
            );
        }
    });

    await contexto.test('ORDEM_DA_FILA e LIVE também são pt-BR, não as do protótipo', () => {
        for (const chave of Object.keys(ORDEM_DA_FILA)) {
            assert.ok(DO_BANCO.includes(chave), `ORDEM_DA_FILA tem a chave "${chave}", que não é do banco`);
        }
        for (const chave of LIVE) {
            assert.ok(DO_BANCO.includes(chave), `LIVE tem a chave "${chave}", que não é do banco`);
        }
    });

    await contexto.test('LIVE é exatamente os três "ao vivo" (AND-05)', () => {
        assert.deepEqual(LIVE, ['aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados']);
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 3. A BIJEÇÃO — os três mapas de conferência
//
// O que precisaria estar errado para falhar: `DO_PROTOTIPO` com 7 entradas (um
// estado ficaria SEM medição contra `STM`) ou com dois destinos iguais (um
// estado seria medido DUAS vezes e outro nenhuma). É exatamente a forma de um
// gate passar por omissão, e nada mais na suíte olharia para isso.
// ═══════════════════════════════════════════════════════════════════════════════

test('FID-01 — os mapas de conferência são BIJETIVOS com o vocabulário', async (contexto) => {
    /** Prova injetividade + sobrejetividade de um mapa contra um conjunto de destino. */
    const conferirBijecao = (nome, mapa, destinoEsperado) => {
        const origens = Object.keys(mapa);
        const destinos = Object.values(mapa);
        assert.equal(
            origens.length,
            destinoEsperado.length,
            `${nome}: ${origens.length} origens para ${destinoEsperado.length} destinos`,
        );
        assert.equal(
            new Set(destinos).size,
            destinos.length,
            `${nome}: há destino REPETIDO — um item seria medido duas vezes e outro nenhuma`,
        );
        assert.deepEqual(
            [...destinos].sort(),
            [...destinoEsperado].sort(),
            `${nome}: os destinos não cobrem o vocabulário`,
        );
    };

    await contexto.test('DO_PROTOTIPO ↔ ANDAMENTOS (8 ↔ 8)', () => {
        conferirBijecao('DO_PROTOTIPO', DO_PROTOTIPO, CHAVES_DE_ANDAMENTO);
        // E as ORIGENS são as 8 chaves medidas em `STM`, não outras 8 quaisquer.
        assert.deepEqual(
            Object.keys(DO_PROTOTIPO).sort(),
            STM_DO_PROTOTIPO.map(([k]) => k).sort(),
        );
    });

    await contexto.test('ORIGEM_DO_PROTOTIPO ↔ SELOS_DE_ORIGEM (3 ↔ 3)', () => {
        conferirBijecao('ORIGEM_DO_PROTOTIPO', ORIGEM_DO_PROTOTIPO, Object.keys(SELOS_DE_ORIGEM));
        assert.deepEqual(
            Object.keys(ORIGEM_DO_PROTOTIPO).sort(),
            SRC_DO_PROTOTIPO.map(([k]) => k).sort(),
        );
    });

    await contexto.test('BLOQUEIO_DO_PROTOTIPO ↔ união de SHORT e BLK (11 ↔ 11)', () => {
        const uniao = [...new Set([...Object.keys(ABREVIACAO_DO_BLOQUEIO), ...Object.keys(ROTULO_DO_BLOQUEIO)])];
        // 9 + 8 com 6 conceitos em comum = 11. O número está escrito para que
        // acrescentar bloqueio num mapa só seja um diff visível aqui.
        assert.equal(uniao.length, 11, `a união deu ${uniao.length} chaves, esperava 11`);
        conferirBijecao('BLOQUEIO_DO_PROTOTIPO', BLOQUEIO_DO_PROTOTIPO, uniao);
        assert.deepEqual(
            Object.keys(BLOQUEIO_DO_PROTOTIPO).sort(),
            ['adjust', 'alert', 'change', 'failed', 'freight', 'loss', 'margin', 'nophoto', 'photos', 'prep', 'title'],
        );
    });

    await contexto.test('os 6 conceitos compartilhados usam a MESMA chave nos dois mapas', () => {
        // ⚠️ Esta é a asserção que impede o S3 de renascer nos bloqueios: dar
        // nome diferente ao mesmo conceito nos dois mapas (um `frete_pendente`
        // e um `frete_ou_custo_ausente`) seria duas chaves para a mesma coisa,
        // sem tradutor.
        const compartilhados = Object.keys(ABREVIACAO_DO_BLOQUEIO)
            .filter((k) => Object.prototype.hasOwnProperty.call(ROTULO_DO_BLOQUEIO, k));
        assert.deepEqual(compartilhados.sort(), [
            'alteracao_nao_conferida',
            'ajuste_em_aberto',
            'fotos_a_conferir',
            'frete_ausente',
            'margem_abaixo_do_minimo',
            'resultado_negativo',
        ].sort());
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 4. OS RÓTULOS, BYTE A BYTE contra `STM` / `SRC`
//
// O que precisaria estar errado para falhar: paráfrase. "Aguardando" em vez de
// "Aguardando aprovação", "Falha" em vez de "Falha na publicação", "Em revisão"
// (o literal CRU que o plano 186-01 aposentou) em vez de "Em revisão pela ECF".
// ═══════════════════════════════════════════════════════════════════════════════

test('VOC-02 — os 8 rótulos de andamento são os de `STM`, byte a byte', async (contexto) => {
    for (const [chaveDoPrototipo, rotuloMedido] of STM_DO_PROTOTIPO) {
        await contexto.test(`${chaveDoPrototipo} → "${rotuloMedido}"`, () => {
            const nossa = DO_PROTOTIPO[chaveDoPrototipo];
            assert.ok(nossa, `DO_PROTOTIPO não traduz "${chaveDoPrototipo}"`);
            assert.equal(ANDAMENTOS[nossa].rotulo, rotuloMedido);
        });
    }

    await contexto.test('e "Em revisão pela ECF" é o rótulo COMPLETO (o cru foi aposentado)', () => {
        assert.equal(ANDAMENTOS.em_revisao_ecf.rotulo, 'Em revisão pela ECF');
        // ⚠️ O gate de ausência do literal CRU é do plano 186-01 e varre este
        // diretório; aqui a asserção é POSITIVA (o qualificado está inteiro),
        // que é a metade que aquele gate não dá.
        assert.ok(ANDAMENTOS.em_revisao_ecf.rotulo.endsWith('pela ECF'));
    });
});

test('VOC-04 — os 3 selos de origem são Confirmado / Estimado / Pendente', async (contexto) => {
    for (const [chaveDoPrototipo, rotuloMedido] of SRC_DO_PROTOTIPO) {
        await contexto.test(`${chaveDoPrototipo} → "${rotuloMedido}"`, () => {
            const nossa = ORIGEM_DO_PROTOTIPO[chaveDoPrototipo];
            assert.ok(nossa, `ORIGEM_DO_PROTOTIPO não traduz "${chaveDoPrototipo}"`);
            assert.equal(SELOS_DE_ORIGEM[nossa].rotulo, rotuloMedido);
        });
    }

    await contexto.test('os 3 rótulos são exatamente estes, e não mais nenhum', () => {
        assert.deepEqual(
            Object.values(SELOS_DE_ORIGEM).map((s) => s.rotulo).sort(),
            ['Confirmado', 'Estimado', 'Pendente'],
        );
    });
});

test('VOC-08 — os 6 toasts são os literais do README', async (contexto) => {
    const MEDIDOS = [
        'Aprovado — aguardando publicação',
        'Publicado e confirmado no Mercado Livre · MLB…',
        'Revisão salva',
        'Ajuste solicitado · Encaminhado para: …',
        'Foto reprovada e removida do conjunto final',
        'Gerando nova versão com IA',
    ];

    await contexto.test('os 6, byte a byte e sem sobra', () => {
        assert.deepEqual(Object.values(TOASTS).sort(), [...MEDIDOS].sort());
    });

    await contexto.test('⚠️ o travessão de "Aprovado — …" é EM DASH (U+2014), não hífen', () => {
        // Um hífen aqui passaria em qualquer leitura humana e mudaria o literal.
        assert.ok(TOASTS.aprovado.includes('—'), 'o travessão virou hífen');
        assert.ok(!TOASTS.aprovado.includes(' - '), 'o travessão virou hífen cercado de espaços');
    });

    await contexto.test('⚠️ as reticências são UM caractere (U+2026), não três pontos', () => {
        assert.ok(TOASTS.publicado.endsWith('…'));
        assert.ok(TOASTS.ajuste_solicitado.endsWith('…'));
        assert.ok(!TOASTS.publicado.includes('...'), 'as reticências viraram três pontos');
    });
});

test('CRIT / DEF — os 7 critérios e os 6 defeitos, nas chaves e nos rótulos medidos', async (contexto) => {
    await contexto.test('os 7 critérios', () => {
        assert.deepEqual(CRITERIOS, {
            cor: 'Cor',
            formato: 'Formato',
            material: 'Material',
            textura: 'Textura',
            proporcoes: 'Proporções',
            quantidade: 'Quantidade do kit',
            acessorios: 'Acessórios e componentes',
        });
    });

    await contexto.test('os 6 defeitos', () => {
        assert.deepEqual(DEFEITOS, {
            deform: 'Deformação',
            dup: 'Peça duplicada',
            falta: 'Peça ausente',
            texto: 'Texto ilegível',
            recorte: 'Recorte ruim',
            inventado: 'Elemento inventado pela IA',
        });
    });
});

test('BLOQ — as 9 abreviações e os 8 rótulos de bloqueio, medidos', async (contexto) => {
    await contexto.test('SHORT: as 9 abreviações, em minúscula (elas emendam depois de "Bloqueado: ")', () => {
        assert.deepEqual(Object.values(ABREVIACAO_DO_BLOQUEIO).sort(), [
            'fotos a conferir',
            'sem fotos',
            'frete pendente',
            'resultado negativo',
            'margem abaixo do mínimo',
            'alteração de preço não conferida',
            'ajuste em aberto',
            'título longo',
            'em preparação',
        ].sort());

        for (const [chave, texto] of Object.entries(ABREVIACAO_DO_BLOQUEIO)) {
            // A primeira letra minúscula é requisito de emenda, não estilo:
            // capitalizada daria "Bloqueado: Fotos a conferir".
            assert.equal(texto[0], texto[0].toLowerCase(), `${chave}: a abreviação começa com maiúscula`);
        }
    });

    await contexto.test('BLK: os 8 rótulos do filtro "Motivo de bloqueio"', () => {
        assert.deepEqual(Object.values(ROTULO_DO_BLOQUEIO).sort(), [
            'Fotos a conferir',
            'Alerta em foto de IA',
            'Resultado negativo',
            'Margem abaixo do mínimo',
            'Frete ou custo ausente',
            'Ajuste solicitado',
            'Alteração não conferida',
            'Falha na publicação',
        ].sort());
    });

    await contexto.test('o prefixo emendado dá exatamente a frase do protótipo', () => {
        assert.equal(BLOQUEADO_PREFIXO, 'Bloqueado: ');
        assert.equal(
            BLOQUEADO_PREFIXO + ABREVIACAO_DO_BLOQUEIO.frete_ausente,
            'Bloqueado: frete pendente',
        );
    });
});

test('VOC-03 — os literais fixos do portão, e o nome do número', async (contexto) => {
    await contexto.test('"Resultado estimado por venda" e "Margem de contribuição estimada"', () => {
        assert.equal(RESULTADO_ESTIMADO, 'Resultado estimado por venda');
        assert.equal(MARGEM_DE_CONTRIBUICAO, 'Margem de contribuição estimada');
    });

    await contexto.test('"Incompleto" e "Fotos, preço e conteúdo conferidos"', () => {
        // README §D: custo ou frete ausente NÃO vira zero. Zero afirmaria que o
        // frete é de graça; "Incompleto" afirma que não se sabe.
        assert.equal(INCOMPLETO, 'Incompleto');
        assert.equal(TUDO_CONFERIDO, 'Fotos, preço e conteúdo conferidos');
    });

    await contexto.test('nenhum literal do portão diz "lucro"', () => {
        const todos = [
            RESULTADO_ESTIMADO, MARGEM_DE_CONTRIBUICAO, INCOMPLETO, TUDO_CONFERIDO, BLOQUEADO_PREFIXO,
            ...Object.values(TOASTS),
            ...Object.values(ROTULO_DO_BLOQUEIO),
            ...Object.values(ABREVIACAO_DO_BLOQUEIO),
        ];
        for (const literal of todos) {
            assert.ok(!literal.toLowerCase().includes('lucro'), `o literal "${literal}" diz "lucro"`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 5. VOC-05 — COR NUNCA É O ÚNICO SINAL
//
// O que precisaria estar errado para falhar: um estado ganhar cor e perder o
// rótulo (ou o contrário) numa refatoração. A tela continuaria desenhando o
// retângulo colorido, e quem enxerga mal cor — ou usa leitor de tela — ficaria
// sem o estado.
// ═══════════════════════════════════════════════════════════════════════════════

test('VOC-05 — todo estado tem rótulo textual E classe de cor E forma', async (contexto) => {
    await contexto.test('o número de rótulos textuais é IGUAL ao de classes de cor', () => {
        const comRotulo = CHAVES_DE_ANDAMENTO
            .filter((k) => typeof ANDAMENTOS[k].rotulo === 'string' && ANDAMENTOS[k].rotulo.trim() !== '');
        const comCor = CHAVES_DE_ANDAMENTO
            .filter((k) => typeof ANDAMENTOS[k].texto === 'string' && ANDAMENTOS[k].texto.trim() !== '');
        assert.equal(comRotulo.length, 8);
        assert.equal(comCor.length, comRotulo.length, 'há estado com cor e sem rótulo (ou o contrário)');
    });

    for (const chave of ['preparando', 'aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados',
        'aprovado', 'publicando', 'falha', 'publicado']) {
        await contexto.test(`${chave} tem dot, texto, fundo e borda por NOME de token`, () => {
            const entrada = ANDAMENTOS[chave];
            for (const campo of ['dot', 'texto', 'fundo', 'borda']) {
                assert.match(entrada[campo], /pub-/, `${chave}.${campo} não usa token pub-*`);
                assert.doesNotMatch(entrada[campo], /#[0-9a-fA-F]{3,8}/, `${chave}.${campo} tem hex literal`);
            }
        });
    }
});

test('AND — o spinner é só de `preparando` e `publicando` (`:1253`)', async (contexto) => {
    const comSpinner = CHAVES_DE_ANDAMENTO.filter((k) => ANDAMENTOS[k].spinner === true);

    await contexto.test('exatamente 2 estados têm spinner', () => {
        assert.equal(comSpinner.length, 2);
    });

    await contexto.test('e são os traduzidos de `publishing` e `preparing`', () => {
        assert.deepEqual(
            [...comSpinner].sort(),
            COM_SPINNER_NO_PROTOTIPO.map((k) => DO_PROTOTIPO[k]).sort(),
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 6. AND-08 / AND-10 — ORDEM DA FILA, LIVE, CTA e `abreRevisao`
//
// O que precisaria estar errado para falhar: alguém "arrumar" o EMPATE em 1
// entre `aguardando_aprovacao` e `em_revisao_ecf` (a fila mudaria de ordem sem
// nenhum teste de render acusar), ou `abreRevisao` virar `true` para
// `publicando` (abriria edição de oferta que já está subindo para o ML).
// ═══════════════════════════════════════════════════════════════════════════════

test('AND-08 — ORDEM_DA_FILA é `ORD`, com o empate preservado', async (contexto) => {
    await contexto.test('cada chave do protótipo tem o MESMO número na nossa tabela', () => {
        for (const [chaveDoPrototipo, posicao] of Object.entries(ORD_DO_PROTOTIPO)) {
            const nossa = DO_PROTOTIPO[chaveDoPrototipo];
            assert.equal(ORDEM_DA_FILA[nossa], posicao, `${chaveDoPrototipo} → ${nossa}`);
        }
    });

    await contexto.test('8 chaves e 7 valores distintos — o empate é o 7º', () => {
        assert.equal(Object.keys(ORDEM_DA_FILA).length, 8);
        assert.equal(new Set(Object.values(ORDEM_DA_FILA)).size, 7);
        assert.equal(ORDEM_DA_FILA.aguardando_aprovacao, ORDEM_DA_FILA.em_revisao_ecf);
        assert.equal(ORDEM_DA_FILA.falha, 0, 'falha sai da frente da fila');
    });
});

test('AND-10 — `ctaDoAndamento` dá o rótulo da ação de `cta` (`:1240`)', async (contexto) => {
    const ESPERADO = [
        ['falha', 'Tentar de novo'],
        ['aprovado', 'Ver'],
        ['publicado', 'Ver'],
        ['publicando', 'Ver'],
        ['preparando', 'Aguarde'],
        ['aguardando_aprovacao', 'Revisar'],
        ['em_revisao_ecf', 'Revisar'],
        ['ajustes_solicitados', 'Revisar'],
    ];

    for (const [chave, rotulo] of ESPERADO) {
        await contexto.test(`${chave} → "${rotulo}"`, () => {
            assert.equal(ctaDoAndamento(chave), rotulo);
            // E o `cta` gravado na entrada bate com a função (uma regra, uma casa).
            assert.equal(ANDAMENTOS[chave].cta, rotulo);
        });
    }

    await contexto.test('chave desconhecida cai em "Revisar" (o ramo `senão` do protótipo)', () => {
        assert.equal(ctaDoAndamento('nao_existe_este_estado'), 'Revisar');
    });

    await contexto.test('⚠️ e valor ADVERSO não lança — devolve "Revisar"', () => {
        for (const adverso of [null, undefined, 42, NaN, '', { toString: null }, [], true]) {
            // ⚠️ A mensagem usa `typeof` + descrição FIXA. `String(adverso)` e
            // `JSON.stringify(adverso)` LANÇAM com `{toString:null}` — no plano
            // 180-05 isso matou 5 blocos DENTRO da mensagem de asserção, não no
            // código testado.
            const tipo = typeof adverso;
            assert.equal(ctaDoAndamento(adverso), 'Revisar', `ctaDoAndamento com valor do tipo ${tipo}`);
        }
    });
});

test('AND — `ehLive` e `abreRevisao` são os três ao vivo, e nada mais', async (contexto) => {
    for (const chave of CHAVES_DE_ANDAMENTO) {
        await contexto.test(`${chave}: ehLive=${LIVE.includes(chave)}`, () => {
            const esperado = LIVE.includes(chave);
            assert.equal(ehLive(chave), esperado);
            // ⚠️ `abreRevisao` É `ehLive` de propósito: duplicar a regra
            // deixaria as duas livres para divergir, e a divergência aqui
            // liberaria edição de oferta já publicada.
            assert.equal(abreRevisao(chave), esperado);
            assert.equal(ANDAMENTOS[chave].abreRevisao, esperado);
        });
    }

    await contexto.test('`preparando` NÃO abre revisão (a oferta ainda está sendo montada)', () => {
        assert.equal(abreRevisao('preparando'), false);
    });

    await contexto.test('⚠️ valor adverso não lança e devolve `false`', () => {
        for (const adverso of [null, undefined, 42, NaN, '', { toString: null }, [], true]) {
            const tipo = typeof adverso;
            assert.equal(ehLive(adverso), false, `ehLive com valor do tipo ${tipo}`);
            assert.equal(abreRevisao(adverso), false, `abreRevisao com valor do tipo ${tipo}`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 7. ⚠️⚠️ `andamentoDe()` NUNCA DEVOLVE `null` — o coração do S3
//
// O que precisaria estar errado para falhar: alguém pôr `return null` (ou
// `?? null`) no fallback, "porque chave desconhecida não tem o que desenhar".
// A consequência MEDIDA é o selo renderizando VAZIO em produção com toda a
// suíte verde — a diferença entre "o selo diz algo errado" (visível) e "o selo
// não diz nada" (invisível).
// ═══════════════════════════════════════════════════════════════════════════════

test('REND — `andamentoDe()` devolve sempre um objeto DESENHÁVEL', async (contexto) => {
    const CAMPOS = ['rotulo', 'dot', 'texto', 'fundo', 'borda', 'spinner', 'abreRevisao', 'cta', 'observacao'];

    for (const chave of CHAVES_DE_ANDAMENTO) {
        await contexto.test(`${chave} → a entrada conhecida, com os 9 campos`, () => {
            const saida = andamentoDe(chave);
            assert.notEqual(saida, null);
            for (const campo of CAMPOS) {
                assert.ok(campo in saida, `${chave}: falta o campo ${campo}`);
            }
            assert.equal(saida.rotulo, ANDAMENTOS[chave].rotulo);
            assert.equal(saida.conhecido, true);
        });
    }

    for (const [chaveInglesa] of STM_DO_PROTOTIPO) {
        await contexto.test(`⚠️ a chave do PROTÓTIPO "${chaveInglesa}" aparece COMO CHEGOU, nunca vazia`, () => {
            const saida = andamentoDe(chaveInglesa);
            assert.notEqual(saida, null, 'devolveu null — o selo renderizaria VAZIO');
            assert.equal(saida.rotulo, chaveInglesa, 'o rótulo não é a chave como chegou');
            assert.ok(saida.rotulo.trim() !== '', 'o rótulo ficou VAZIO');
            assert.equal(saida.conhecido, false);
            assert.equal(saida.abreRevisao, false, 'chave desconhecida não pode abrir revisão');
            // A forma e a cor são as NEUTRAS, não a de um estado conhecido.
            assert.match(saida.dot, /pub-neutral/);
            assert.match(saida.texto, /pub-neutral/);
        });
    }

    await contexto.test('⚠️ valor que NÃO é string vira "—", nunca "NaN" nem "[object Object]"', () => {
        for (const adverso of [null, undefined, 42, NaN, '', '   ', { toString: null }, [], true]) {
            const tipo = typeof adverso;
            const saida = andamentoDe(adverso);
            assert.notEqual(saida, null, `andamentoDe devolveu null para valor do tipo ${tipo}`);
            assert.equal(saida.rotulo, '—', `o rótulo não virou "—" para valor do tipo ${tipo}`);
            // ⚠️ `textoSeguro(NaN)` devolveria a STRING 'NaN'. Por isso este
            // fallback NÃO usa `textoSeguro`.
            assert.doesNotMatch(saida.rotulo, /NaN|\[object Object\]|undefined|null/);
        }
    });

    await contexto.test('⚠️ chave do PROTÓTIPO de Object não vaza função nenhuma', () => {
        // `ANDAMENTOS['toString']` devolveria uma FUNÇÃO num lookup ingênuo, e
        // função renderizada derruba a página inteira no React.
        for (const venenosa of ['toString', 'constructor', 'hasOwnProperty', '__proto__', 'valueOf']) {
            const saida = andamentoDe(venenosa);
            assert.equal(typeof saida.rotulo, 'string', `${venenosa}: o rótulo não é string`);
            assert.equal(saida.rotulo, venenosa);
            assert.equal(saida.conhecido, false);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 8. GATE-08 — TODO ARQUIVO NOVO É `.jsx`, e nenhum tem hex literal
//
// O que precisaria estar errado para falhar: alguém criar (ou renomear para)
// `.js` um arquivo de `aprovacao/`. MEDIDO: `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e NÃO `*.js` — classe num `.js` não gera CSS e não
// existe para a tela, com todo render verde.
// ═══════════════════════════════════════════════════════════════════════════════

test('GATE-08 — o diretório `aprovacao/` é 100% `.jsx`', async (contexto) => {
    await contexto.test('o `content` do Tailwind varre `*.jsx` e NÃO `*.js` (a premissa, medida)', () => {
        // ⚠️⚠️ AQUI A LEITURA É CRUA, e o motivo foi MEDIDO nesta rodada:
        // `lerSemComentarios()` remove blocos `/* … */`, e o glob do `content` é
        // `'./resources/js/**/*.jsx'` — o `/**/` no meio dele CASA com o padrão
        // de comentário de bloco. A fonte "sem comentários" chega aqui como
        // `'./resources/js*.jsx'`, e a asserção reprovava o arquivo CERTO.
        // É a armadilha do próprio learnings numa veste nova: o medidor comeu o
        // dado antes de medir.
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.match(config, /resources\/js\/\*\*\/\*\.jsx/);
        assert.ok(
            !/resources\/js\/\*\*\/\*\.js['"]/.test(config),
            'o `content` passou a varrer `*.js` — esta regra inteira precisa ser reavaliada',
        );
        // ⚠️ E o CONTROLE de que a leitura crua é mesmo necessária: na versão
        // sem comentários o glob NÃO sobrevive. Se um dia sobreviver, esta
        // asserção reprova e avisa que o helper mudou.
        assert.ok(
            !/resources\/js\/\*\*\/\*\.jsx/.test(lerSemComentarios('tailwind.config.js')),
            'o `lerSemComentarios` parou de comer o `/**/` do glob — o comentário acima ficou desatualizado',
        );
    });

    await contexto.test('nenhum `.js` em `aprovacao/`', () => {
        const noDisco = fs.readdirSync(path.resolve(RAIZ, DIR_APROV));
        const jsPuros = noDisco.filter((n) => n.endsWith('.js'));
        assert.deepEqual(jsPuros, [], 'há `.js` em `aprovacao/` — classe nele não geraria CSS');
        const jsx = noDisco.filter((n) => n.endsWith('.jsx'));
        assert.ok(jsx.length >= 2, `esperava ao menos 2 .jsx, achei ${jsx.length}`);
    });

    for (const [caminho, fonte] of FONTES) {
        await contexto.test(`${caminho} — zero hex literal, zero token da camada antiga`, () => {
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, 'hex literal fora da camada de tokens');
            assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/);
            assert.doesNotMatch(fonte, /\bfont-display\b/);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/);
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        });
    }

    await contexto.test('GATE-09 — nenhum `duration-[Nms]`, `delay-[…]` nem `ease-[…]`', () => {
        // MEDIDO: com `tailwindcss-animate` instalado essas classes ficam
        // ambíguas e NÃO COMPILAM neste projeto. Duração fora da escala é
        // `style={{ transitionDuration }}`; spinner é `animate-pub-spin`.
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /\bduration-\[/, `${caminho}: duration-[…] não compila aqui`);
            assert.doesNotMatch(fonte, /\bdelay-\[/, `${caminho}: delay-[…] não compila aqui`);
            assert.doesNotMatch(fonte, /\bease-\[/, `${caminho}: ease-[…] não compila aqui`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// 9. O SELO DE ANDAMENTO INTERNO — render REAL dos 8 estados (Task 2)
//
// Medidas do protótipo, MEDIDAS por `grep -n` em
// `design_handoff_publicador/Aprovacao.dc.html`:
//   · a pill        → :170  `display:flex;align-items:center;gap:6px;height:22px;`
//                           `padding:0 8px;border-radius:6px;white-space:nowrap;`
//                           `border:1px solid <borda>;background:<fundo>;`
//                           `color:<texto>;font-size:11.5px;font-weight:600`
//   · o anel girando→ :171  `width:9px;height:9px;border-radius:50%;`
//                           `border:1.5px solid <texto>;border-right-color:transparent`
//   · a bolinha     → :172  `width:6px;height:6px;border-radius:50%;background:<texto>`
//
// ⚠️⚠️ MEDIÇÃO QUE A TABELA DO PLANO NÃO TRAZIA: dentro da pill, a bolinha e o
// anel são pintados com `r.stColor`, que é `m[1]` — a COR DO TEXTO —, e **não**
// com `m[4]`, a cor de dot de `STM`. O `m[4]` é usado em outro lugar: nos cards
// da faixa de andamento (`:1217`, `dot: m[4]`), que são da Fase 187. Por isso a
// forma aqui é `bg-current`/`border-current`: mesmo valor computado da cor do
// texto que a própria pill declara, sem repetir a classe e sem chance de os dois
// divergirem. O campo `dot` de `ANDAMENTOS` continua existindo para a faixa.
//
// ⚠️ O destino deste componente está DECLARADO: ele é consumido pela coluna
// **Andamento** da tabela da fila (FILA-13) na **Fase 187**. Não é componente
// órfão — é a peça que aquela fase monta. Enquanto isso, render direto.
// ═══════════════════════════════════════════════════════════════════════════════

const REL_SELO = `${DIR_APROV}/SeloDeAndamentoInterno.jsx`;

if (!fs.existsSync(path.resolve(RAIZ, REL_SELO))) {
    throw new Error(`caminho inexistente: ${REL_SELO} — o gate mediria NADA`);
}

const MOD_SELO = await montar(REL_SELO);
const Selo = MOD_SELO.default;

FONTES.set(REL_SELO, lerSemComentarios(REL_SELO));

/** Render de servidor, sem comentários `<!-- -->` entre nós de texto. */
const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

/** A tag de abertura inteira que começa em `pos`. */
function tagEm(html, pos) {
    assert.ok(pos !== -1 && pos < html.length, 'posição fora do HTML');
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de abertura sem fechamento');
    return html.slice(pos, fim + 1);
}

/** O conjunto de classes de uma tag de abertura, ordenado. */
function classesDe(tag) {
    const m = tag.match(/class="([^"]*)"/);
    assert.ok(m, `a tag nao tem atributo class`);
    return m[1].split(/\s+/).filter(Boolean).sort();
}

/** O texto visível do HTML — tags fora, entidades das que aparecem aqui desfeitas. */
function textoDe(html) {
    return html
        .replace(/<[^>]*>/g, '')
        .replace(/&#x27;/g, "'")
        .replace(/&quot;/g, '"')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/\s+/g, ' ')
        .trim();
}

// ─────────────────────────────────────────────────────────────────────────────
// 9.1 — OS 8 ESTADOS pt-BR RENDEM O RÓTULO DE VOC-02
//
// O que precisaria estar errado para falhar: o componente voltar a usar as
// chaves do protótipo (e aí nenhuma das 8 chaves do BANCO casaria, e o selo
// sairia com o rótulo neutro em vez do estado). É o bug S3 pelo lado do render.
//
// ⚠️ UM `test()` POR ESTADO, nunca um laço com 8 asserções dentro: com o laço a
// contagem de testes seria 1 e perder um estado não moveria número nenhum.
// ─────────────────────────────────────────────────────────────────────────────

for (const chave of ['preparando', 'aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados',
    'aprovado', 'publicando', 'falha', 'publicado']) {
    test(`VOC-05 — o selo de \`${chave}\` traz forma + cor + TEXTO`, () => {
        const html = render(Selo, { andamento: chave });
        const esperado = ANDAMENTOS[chave].rotulo;

        // 1. TEXTO. É o que um leitor de tela ouve e o que quem enxerga mal cor lê.
        assert.ok(html.includes(esperado), `o rotulo esperado nao esta no HTML`);
        assert.equal(textoDe(html), esperado, 'o texto visivel nao e exatamente o rotulo');

        // 2. COR — por NOME de token, no elemento raiz.
        const raiz = tagEm(html, 0);
        const classes = classesDe(raiz);
        assert.ok(classes.includes(ANDAMENTOS[chave].texto), 'a classe de cor do texto nao esta na raiz');
        assert.ok(classes.includes(ANDAMENTOS[chave].fundo), 'a classe de fundo nao esta na raiz');
        assert.ok(classes.includes(ANDAMENTOS[chave].borda), 'a classe de borda nao esta na raiz');

        // 3. FORMA — bolinha ou anel, sempre uma das duas, sempre decorativa.
        assert.match(html, /aria-hidden="true"/, 'a forma nao esta marcada como decorativa');
        assert.match(html, /rounded-full/, 'nao ha forma redonda no selo');
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// 9.2 — ⚠️⚠️ AS 8 CHAVES DO PROTÓTIPO: O SELO NÃO FICA VAZIO
//
// O que precisaria estar errado para falhar: `andamentoDe()` voltar a devolver
// `null` (ou o componente fazer `if (!entrada) return null`). O sintoma em
// produção seria o selo renderizando um retângulo colorido SEM TEXTO, e um
// teste que só checasse "não lançou" passaria.
//
// ⚠️ Por isso a asserção é de TEXTO NÃO-VAZIO DENTRO DO ELEMENTO, e não de
// ausência de exceção.
// ─────────────────────────────────────────────────────────────────────────────

for (const [chaveInglesa] of STM_DO_PROTOTIPO) {
    test(`REND — a chave do prototipo \`${chaveInglesa}\` rende TEXTO NAO-VAZIO`, () => {
        const html = render(Selo, { andamento: chaveInglesa });

        assert.ok(html.trim() !== '', 'o componente nao renderizou nada');
        const texto = textoDe(html);
        assert.ok(texto !== '', 'O SELO RENDERIZOU VAZIO — e exatamente o bug S3');
        // Aparece COMO CHEGOU: não se apaga a linha nem se inventa um rótulo.
        assert.equal(texto, chaveInglesa);
        // E com a forma/cor NEUTRAS, não com a de um estado conhecido.
        const classes = classesDe(tagEm(html, 0));
        assert.ok(classes.includes('text-pub-neutral-txt'), 'a cor nao e a neutra');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/);
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// 9.3 — O SPINNER
//
// O que precisaria estar errado para falhar: o anel girando aparecer em
// `aprovado`/`publicado` (a linha pareceria estar trabalhando quando já
// terminou) ou desaparecer de `publicando` (a linha pareceria parada enquanto a
// oferta sobe para o ML).
// ─────────────────────────────────────────────────────────────────────────────

test('AND — o anel girando e so de `preparando` e `publicando`', async (contexto) => {
    for (const chave of CHAVES_DE_ANDAMENTO) {
        await contexto.test(`${chave}: spinner=${ANDAMENTOS[chave].spinner}`, () => {
            const html = render(Selo, { andamento: chave });
            const temSpinner = html.includes('animate-pub-spin');
            assert.equal(temSpinner, ANDAMENTOS[chave].spinner);

            if (temSpinner) {
                // ⚠️ `border-r-transparent` é o que ABRE o anel: sem ele a
                // forma é um círculo fechado e a rotação não se vê.
                assert.match(html, /border-r-transparent/, 'o anel ficou fechado — a rotacao nao aparece');
                assert.match(html, /w-\[9px\]/, 'o anel nao tem os 9px do prototipo');
            } else {
                assert.match(html, /w-\[6px\]/, 'a bolinha nao tem os 6px do prototipo');
                assert.doesNotMatch(html, /border-r-transparent/);
            }
        });
    }

    await contexto.test('⚠️ GATE-09: o spinner usa a animacao NOMEADA, nunca `duration-[Nms]`', () => {
        const html = render(Selo, { andamento: 'publicando' });
        assert.match(html, /animate-pub-spin/);
        // `duration-[…]`, `delay-[…]` e `ease-[…]` NÃO COMPILAM neste projeto
        // (`tailwindcss-animate` torna a classe ambígua).
        assert.doesNotMatch(html, /duration-\[/);
        assert.doesNotMatch(html, /delay-\[/);
        assert.doesNotMatch(html, /ease-\[/);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 9.4 — ⚠️ OS CASOS ADVERSOS: A TELA PRETA DE 07/10
//
// O que precisaria estar errado para falhar: `String(valor)`,
// `hasOwnProperty(valor)` ou QUALQUER `obj[valor]` sem guarda de tipo. Medido
// neste projeto: os três LANÇAM com `{ toString: null }`, e prop de NÓ chegando
// objeto comum faz o React lançar e derruba a PÁGINA INTEIRA. A tela preta
// voltou QUATRO vezes na Fase 180, sempre achada só pelo render adverso.
//
// ⚠️ A mensagem de asserção usa `typeof` + descrição FIXA. `String(adverso)` e
// `JSON.stringify(adverso)` matariam o bloco DENTRO da mensagem (plano 180-05,
// 5 blocos).
// ─────────────────────────────────────────────────────────────────────────────

test('REND-01/REND-03 — nenhum valor adverso derruba o selo', async (contexto) => {
    const ADVERSOS = [
        ['objeto comum', { a: 1 }],
        ['objeto com toString nulo', { toString: null }],
        ['objeto com toString que lanca', { toString() { throw new Error('nunca deve ser chamado'); } }],
        ['nulo', null],
        ['numero', 42],
        ['NaN', NaN],
        ['string vazia', ''],
        ['string de espacos', '   '],
        ['array', []],
        ['booleano', true],
        ['chave desconhecida', 'estado_que_nao_existe'],
        ['chave do prototipo de Object', 'toString'],
    ];

    for (const [nome, valor] of ADVERSOS) {
        await contexto.test(`andamento = ${nome} — renderiza e nao derruba`, () => {
            const tipo = typeof valor;
            let html = '';
            assert.doesNotThrow(() => {
                html = render(Selo, { andamento: valor });
            }, `o render lancou com valor do tipo ${tipo}`);

            assert.ok(html.trim() !== '', `nada renderizado`);
            // ⚠️ Asserção de ausência rodada com dado VAZIO é vazia por
            // construção — aqui ela roda com a prop PREENCHIDA (ou com o valor
            // adverso que é o assunto do caso).
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `vazou representacao interna, tipo ${tipo}`,
            );
            // E o selo continua dizendo ALGO: nunca um retângulo mudo.
            assert.ok(textoDe(html) !== '', `o selo ficou VAZIO, tipo ${tipo}`);
        });
    }

    await contexto.test('prop AUSENTE (componente chamado sem props) nao lanca', () => {
        let html = '';
        assert.doesNotThrow(() => { html = render(Selo, {}); });
        assert.ok(textoDe(html) !== '', 'sem props o selo ficou vazio');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/);
    });

    await contexto.test('props = null (o segundo argumento do createElement nulo) nao lanca', () => {
        let html = '';
        assert.doesNotThrow(() => { html = render(Selo, null); });
        assert.ok(textoDe(html) !== '');
    });

    await contexto.test('`className` adverso nao derruba nem vaza', () => {
        for (const adverso of [{ a: 1 }, 42, null, [], true]) {
            const tipo = typeof adverso;
            let html = '';
            assert.doesNotThrow(() => {
                html = render(Selo, { andamento: 'aprovado', className: adverso });
            }, `className do tipo ${tipo} derrubou o render`);
            assert.ok(html.includes('Aprovado'), `className do tipo ${tipo} comeu o rotulo`);
            assert.doesNotMatch(html, /\[object Object\]/);
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 9.5 — GATE DE HTML: IGUALDADE DE CONJUNTO DE CLASSES
//
// O que precisaria estar errado para falhar: uma classe da pill sumir (ou
// aparecer uma a mais) numa refatoração — `h-[22px]` virando `h-6` (24px),
// `px-[8px]` virando `px-2.5` (10px), `text-[11.5px]` virando `text-xs` (12px).
// A sonda de CSS NÃO pega nada disso: ela lê o último build e a mesma classe
// pode ter outro dono no repositório. São gates diferentes e não se substituem
// (GATE-02).
//
// ⚠️ A raiz é localizada pela POSIÇÃO 0 (é o elemento mais externo), nunca pela
// lista de classes: quatro gates deste projeto já mediram o irmão errado.
// ─────────────────────────────────────────────────────────────────────────────

/** A base da pill, igual nos 8 estados (`:170`). Fora do `test()` porque 9.7 também a usa. */
const BASE_DA_PILL = [
    'inline-flex',
    'items-center',
    'gap-[6px]',
    'h-[22px]',
    'px-[8px]',
    'rounded-pub-chip',
    'whitespace-nowrap',
    'border',
    'text-[11.5px]',
    'font-semibold',
];

/** As classes da bolinha (`:172`) e as do anel (`:171`), medidas. */
const CLASSES_DA_BOLINHA = ['bg-current', 'flex-none', 'h-[6px]', 'rounded-full', 'w-[6px]'];
const CLASSES_DO_ANEL = [
    'animate-pub-spin', 'border-[1.5px]', 'border-current', 'border-r-transparent',
    'flex-none', 'h-[9px]', 'rounded-full', 'w-[9px]',
];

test('GATE-02 (HTML) — a pill tem EXATAMENTE as classes medidas no prototipo', async (contexto) => {
    for (const chave of CHAVES_DE_ANDAMENTO) {
        await contexto.test(`${chave} — conjunto de classes da raiz, por igualdade`, () => {
            const html = render(Selo, { andamento: chave });
            const entrada = ANDAMENTOS[chave];
            const esperado = [...BASE_DA_PILL, entrada.fundo, entrada.borda, entrada.texto].sort();
            assert.deepEqual(classesDe(tagEm(html, 0)), esperado);
        });
    }

    await contexto.test('a FORMA tem as classes medidas (bolinha 6px / anel 9px com 1.5px)', () => {
        const comDot = render(Selo, { andamento: 'aprovado' });
        const tagDoDot = tagEm(comDot, comDot.indexOf('<span', 1));
        assert.deepEqual(classesDe(tagDoDot), [...CLASSES_DA_BOLINHA].sort());

        const comAnel = render(Selo, { andamento: 'publicando' });
        const tagDoAnel = tagEm(comAnel, comAnel.indexOf('<span', 1));
        assert.deepEqual(classesDe(tagDoAnel), [...CLASSES_DO_ANEL].sort());
    });

    await contexto.test('⚠️ substring nao prova medida: `z-3` casa em `z-30`, `w-[` casa em sombra', () => {
        // A asserção acima é de IGUALDADE DE CONJUNTO exatamente por isto: um
        // `includes('h-[2')` passaria com `h-[22px]`, `h-[24px]` e `h-[2px]`.
        const html = render(Selo, { andamento: 'aprovado' });
        const classes = classesDe(tagEm(html, 0));
        assert.ok(!classes.includes('h-[24px]'), 'a altura virou 24px');
        assert.ok(!classes.includes('text-[12px]'), 'o corpo virou 12px');
    });

    await contexto.test('⚠️ `className` entra NA RAIZ e nao come nenhuma classe da pill', () => {
        const html = render(Selo, { andamento: 'aprovado', className: 'ml-auto' });
        const classes = classesDe(tagEm(html, 0));
        assert.ok(classes.includes('ml-auto'), 'o className nao chegou');
        for (const base of BASE_DA_PILL) {
            assert.ok(classes.includes(base), `o className comeu uma classe da pill`);
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 9.6 — O ARQUIVO NOVO É `.jsx` E RESPEITA O VOCABULÁRIO
// ─────────────────────────────────────────────────────────────────────────────

test('GATE-08 — `SeloDeAndamentoInterno.jsx` e `.jsx` e sem hex/`text-sm`', () => {
    const fonte = FONTES.get(REL_SELO);
    assert.ok(REL_SELO.endsWith('.jsx'), 'o selo nao e .jsx — classe nele nao geraria CSS');
    assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/);
    assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/);
    assert.doesNotMatch(fonte, /\bfont-display\b/);
    // ⚠️ `assert.match(tag, /disabled/)` é asserção VAZIA: casa com
    // `disabled:opacity-40`. Aqui o selo não tem botão, e a prova de que ele não
    // ganhou um é a ausência do atributo COM o `=`.
    assert.doesNotMatch(fonte, /disabled=/);
    // O selo IMPORTA `andamentoDe` em vez de recriar a tabela (uma regra, uma casa).
    assert.match(fonte, /from '\.\/andamentoDoPortao'/);
    assert.match(fonte, /andamentoDe\(/);
});

// ═══════════════════════════════════════════════════════════════════════════════
// 9.7 — GATE DE CSS: A CLASSE GERA CSS COM O VALOR CERTO (GATE-02, o 2º eixo)
//
// O que precisaria estar errado para falhar: a classe estar PRESENTE com o
// VALOR errado. O token `pub-accent` apontando para outro amarelo, o raio do
// `pub-chip` mudando de 6 para 8, o alfa de `/[0.10]` arredondando para
// `/[0.12]`, `animate-pub-spin` deixando de existir. NADA disso o gate de HTML
// acima pega — ele só prova que a classe foi ESCRITA.
//
// ⚠️ E o inverso também vale: a sonda de CSS NÃO protege a fidelidade DESTE
// arquivo. Ela lê o CSS do ÚLTIMO BUILD, e a mesma classe pode ter outro dono
// no repositório inteiro. Os dois gates são necessários e nenhum substitui o
// outro.
//
// ⚠️ AS SETE NORMALIZAÇÕES DO CSS CONSTRUÍDO, todas MEDIDAS nesta rodada
// (14/10/2026, `public/build/assets/app-*.css`, 223.881 bytes) e não presumidas:
//   (i)   token SÓLIDO sai DECIMAL com a variável de opacidade:
//         `bg-pub-hover-2` → `--tw-bg-opacity: 1;background-color:rgb(22 22 22 / var(--tw-bg-opacity, 1))`
//   (ii)  modificador de opacidade em `background`/`border` sai em HEX DE 8
//         DÍGITOS, minúsculo: `bg-pub-accent/[0.10]` → `background-color:#facc151a`
//         e `border-pub-ok/[0.35]` → `border-color:#22c55e59`.
//         ⚠️ É o byte do alfa que distingue 0.10 (`1a`) de 0.12 (`1f`) e
//         0.35 (`59`) de 0.4 (`66`) — e é ele que a asserção lê.
//   (iii) o cssnano COME O ZERO DA FRENTE: `animation:spin .7s linear infinite`
//         (e não `0.7s`).
//   (iv)  o seletor vem ESCAPADO (`.h-\[22px\]`), e escapar só a barra
//         invertida transformaria os colchetes em classe de caracteres.
//   (v)   `bg-current`/`border-current` saem como `currentColor` literal, sem
//         variável de opacidade.
//   (vi)  o prefixo de variante faz parte do nome da classe (não há variante
//         nesta pill, mas a função `regraDe` já lida com isso).
//   (vii) `escapaRegex` escapa TODOS os metacaracteres, parênteses inclusive.
//
// ⚠️ A sonda PULA com razão ESCRITA quando o CSS é mais velho que os fontes —
// é o que acontece em CADA mutação da campanha, porque mutar toca o `.jsx`.
// `skip` NOMEADO, nunca `return` calado. É daí que vêm os DOIS REGIMES de
// contagem do manifesto (`tests/gates/contagens-186-02.txt`).
// ═══════════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex, parênteses inclusive (vii). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados (iv). */
function seletorDe(classe) {
    return `.${classe.replace(/[[\].:/%,]/g, (ch) => `\\${ch}`)}`;
}

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash do arquivo sai com
    // HÍFEN (`app-BhcXX3y8.css`) e um `grep` de nome quebraria nele.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        ...[REL_SELO, REL_ANDAMENTO, REL_VOCABULARIO].map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs),
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que os componentes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8') };
}

const CSS = carregarCssConstruido();

test('GATE-02 (CSS) — as classes da pill GERAM CSS com o valor certo', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);
        return;
    }

    /** O corpo da regra da classe, ou `null`. */
    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest e tem tamanho de CSS de verdade', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe', () => {
        // As duas iscas foram medidas como INEXISTENTES no repo inteiro
        // (`grep -rl` em `resources/`, `app/`, `tests/`, `tailwind.config.js`)
        // antes de serem usadas — é a lição do `w-[13px]`, que parecia inventado
        // e estava presente. `text-[97.5px]` FOI DESCARTADA como isca por este
        // motivo: ela aparece em `publicador-publicacoes-busca.test.js`.
        for (const isca of ['bg-pub-isca-186a2', 'h-[223px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca apareceu no CSS`);
        }
        // E o CONTROLE POSITIVO: a sonda ACHA o que existe. Sem ele, a negativa
        // acima passaria com a sonda cega.
        assert.notEqual(regraDe('h-[22px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('a geometria da pill: 22px de altura, 8px de lado, gap 6, raio 6', () => {
        assert.match(regraDe('h-[22px]'), /height:\s*22px/);
        assert.match(regraDe('px-[8px]'), /padding-left:\s*8px/);
        assert.match(regraDe('px-[8px]'), /padding-right:\s*8px/);
        assert.match(regraDe('gap-[6px]'), /gap:\s*6px/);
        assert.match(regraDe('rounded-pub-chip'), /border-radius:\s*6px/);
        assert.match(regraDe('border'), /border-width:\s*1px/);
        assert.match(regraDe('whitespace-nowrap'), /white-space:\s*nowrap/);
        assert.match(regraDe('inline-flex'), /display:\s*inline-flex/);
    });

    await contexto.test('⚠️ o corpo é 11.5px e o peso 600 — `text-sm` seria 14px (FID-03)', () => {
        assert.match(regraDe('text-[11.5px]'), /font-size:\s*11\.5px/);
        assert.match(regraDe('font-semibold'), /font-weight:\s*600/);
        // ⚠️ substring não prova medida: `text-[11px]` casa DENTRO de
        // `text-[11.5px]` numa busca ingênua. Aqui as duas regras são lidas
        // separadas e comparadas por valor.
        const onze = regraDe('text-[11px]');
        if (onze !== null) {
            assert.ok(!/font-size:\s*11\.5px/.test(onze), 'a regra de 11px carrega o valor de 11.5px');
        }
        assert.ok(!/font-size:\s*14px/.test(regraDe('text-[11.5px]')), 'o corpo virou 14px');
    });

    await contexto.test('a FORMA: bolinha 6px, anel 9px com borda de 1.5px e raio redondo', () => {
        assert.match(regraDe('w-[6px]'), /width:\s*6px/);
        assert.match(regraDe('h-[6px]'), /height:\s*6px/);
        assert.match(regraDe('w-[9px]'), /width:\s*9px/);
        assert.match(regraDe('h-[9px]'), /height:\s*9px/);
        assert.match(regraDe('border-[1.5px]'), /border-width:\s*1\.5px/);
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/);
        assert.match(regraDe('flex-none'), /flex:\s*none/);
    });

    await contexto.test('(v) a forma herda a cor do texto: `currentColor` literal', () => {
        // MEDIDO: `bg-current` NÃO ganha variável de opacidade — sai
        // `background-color:currentColor` cru. É o que faz a bolinha ter a
        // MESMA cor que o rótulo, como o protótipo pinta (`r.stColor`).
        assert.match(regraDe('bg-current'), /background-color:\s*currentColor/);
        assert.match(regraDe('border-current'), /border-color:\s*currentColor/);
        assert.match(regraDe('border-r-transparent'), /border-right-color:\s*transparent/);
    });

    await contexto.test('(iii) o anel usa a animação NOMEADA, com o zero da frente comido pelo cssnano', () => {
        // ⚠️ `.7s`, não `0.7s`: o cssnano come o zero da frente. Escrever
        // `0\.7s` na regex aqui reprovaria o CSS certo.
        assert.match(regraDe('animate-pub-spin'), /animation:\s*spin \.7s linear infinite/);
        // E o keyframe existe de verdade — animação apontando para keyframe
        // inexistente não gira e nenhum teste de HTML acusaria.
        assert.match(CSS.css, /@keyframes spin\{to\{transform:rotate\(360deg\)\}\}/);
        // ⚠️ DIVERGÊNCIA DECLARADA: o protótipo mede `.8s` (`:171`) e o token
        // existente é `.7s`. A asserção trava o valor REAL do token para que
        // mudá-lo seja um diff visível; o décimo de diferença está registrado na
        // 186-02-SUMMARY.
        assert.ok(!/animation:\s*spin \.8s/.test(regraDe('animate-pub-spin')), 'o token virou .8s sem registro');
    });

    await contexto.test('(i) tokens SÓLIDOS saem em DECIMAL, com a variável de opacidade', () => {
        assert.match(regraDe('bg-pub-hover-2'), /background-color:\s*rgb\(22 22 22 \/ var\(--tw-bg-opacity/);
        assert.match(regraDe('bg-pub-hover-3'), /background-color:\s*rgb\(26 26 26 \/ var\(--tw-bg-opacity/);
        assert.match(regraDe('border-pub-line-strong'), /border-color:\s*rgb\(46 46 46 \/ var\(--tw-border-opacity/);
        assert.match(regraDe('border-pub-line-off'), /border-color:\s*rgb\(63 63 70 \/ var\(--tw-border-opacity/);
    });

    await contexto.test('(i) as 6 cores de TEXTO dos 8 estados, em decimal', () => {
        assert.match(regraDe('text-pub-neutral-txt'), /color:\s*rgb\(161 161 170 \//);  // #A1A1AA
        assert.match(regraDe('text-pub-accent'), /color:\s*rgb\(250 204 21 \//);        // #FACC15
        assert.match(regraDe('text-pub-fase1'), /color:\s*rgb\(212 212 216 \//);        // #D4D4D8
        assert.match(regraDe('text-pub-warn-txt'), /color:\s*rgb\(251 191 36 \//);      // #FBBF24
        assert.match(regraDe('text-pub-ok-txt'), /color:\s*rgb\(74 222 128 \//);        // #4ADE80
        assert.match(regraDe('text-pub-danger-txt'), /color:\s*rgb\(248 113 113 \//);   // #F87171
    });

    await contexto.test('⚠️ (ii) as TINTAS saem em HEX DE 8 DÍGITOS — e o byte do alfa é o que importa', () => {
        // `1a` = 26/255 = 0.102 (o `0.10` do protótipo). `1f` seria 0.12.
        assert.match(regraDe('bg-pub-accent/[0.10]'), /background-color:\s*#facc151a/i);
        assert.match(regraDe('bg-pub-warn/[0.10]'), /background-color:\s*#f59e0b1a/i);
        assert.match(regraDe('bg-pub-ok/[0.10]'), /background-color:\s*#22c55e1a/i);
        assert.match(regraDe('bg-pub-danger/[0.10]'), /background-color:\s*#ef44441a/i);
        // `59` = 89/255 = 0.349 (`0.35`) e `66` = 102/255 = 0.4. São BYTES
        // diferentes: é isto que distingue a borda de `aprovado` da de `falha`.
        assert.match(regraDe('border-pub-accent/[0.35]'), /border-color:\s*#facc1559/i);
        assert.match(regraDe('border-pub-ok/[0.35]'), /border-color:\s*#22c55e59/i);
        assert.match(regraDe('border-pub-warn/[0.4]'), /border-color:\s*#f59e0b66/i);
        assert.match(regraDe('border-pub-danger/[0.4]'), /border-color:\s*#ef444466/i);
        // E o controle NEGATIVO do alfa: 0.35 não pode ter virado 0.4.
        assert.ok(!/#22c55e66/i.test(regraDe('border-pub-ok/[0.35]')), 'o alfa 0.35 virou 0.4');
        assert.ok(!/#f59e0b59/i.test(regraDe('border-pub-warn/[0.4]')), 'o alfa 0.4 virou 0.35');
    });

    await contexto.test('TODA classe que os 8 estados escrevem existe no CSS construído', () => {
        // ⚠️ Este sub-teste é o que impede a omissão: um token novo escrito num
        // dos 8 estados sem existir na camada geraria classe SEM CSS, e a cor
        // simplesmente não apareceria na tela. A lista sai de `ANDAMENTOS`, não
        // de uma cópia à mão.
        const usadas = new Set([...BASE_DA_PILL, ...CLASSES_DA_BOLINHA, ...CLASSES_DO_ANEL]);
        for (const chave of CHAVES_DE_ANDAMENTO) {
            for (const campo of ['fundo', 'borda', 'texto']) {
                usadas.add(ANDAMENTOS[chave][campo]);
            }
        }
        for (const classe of usadas) {
            assert.notEqual(regraDe(classe), null, `a classe ${classe} NÃO GERA CSS — a tela não a veria`);
        }
        // ⚠️ O número está escrito para que acrescentar classe à pill seja um
        // diff visível aqui. A aritmética, MEDIDA e conferida:
        //   10 da base + 5 da bolinha + 8 do anel − 2 em comum entre as duas
        //   formas (`flex-none` e `rounded-full`) = 21 de geometria;
        //   + 6 fundos distintos + 6 bordas distintas + 6 cores de texto
        //     distintas = 18 de cor (os 8 estados compartilham: `aprovado`,
        //     `publicando` e `publicado` têm exatamente o mesmo trio verde).
        //   21 + 18 = 39.
        assert.equal(usadas.size, 39, `a pill usa ${usadas.size} classes distintas, esperava 39`);
    });
});
