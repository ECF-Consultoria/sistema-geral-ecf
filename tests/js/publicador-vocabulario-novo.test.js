import test from 'node:test';
import assert from 'node:assert/strict';
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
];

/**
 * Os corpos de texto do README (§Design Tokens e as telas). FID-03 é o motivo
 * de 13.5 e 14.5 estarem aqui: o passo do Tailwind não tem meio pixel, então
 * quem arredondasse para `text-sm` (14px) mudaria o layout.
 */
const TAMANHOS_PERMITIDOS = new Set([
    10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34,
]);

const FONTES = new Map(ARQUIVOS_NOVOS.map((caminho) => [caminho, lerSemComentarios(caminho)]));

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
        for (const [caminho, fonte] of FONTES) {
            const esperado = caminho.endsWith('GlyphDaFase.jsx');
            for (const classe of ['w-[15px]', 'h-[18px]']) {
                assert.equal(
                    fonte.includes(classe),
                    esperado,
                    esperado
                        ? `GlyphDaFase.jsx perdeu a medida ${classe} da marca`
                        : `${caminho} redesenha o glyph (${classe}) — o glyph tem UMA casa`,
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
