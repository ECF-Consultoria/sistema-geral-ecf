import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
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
