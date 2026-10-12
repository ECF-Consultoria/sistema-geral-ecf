import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 178, plano 02 — o corpo do modal "Nova publicação".
//
// Esta primeira parte (plano 02, Task 1) cobra o MÓDULO PURO
// `header/novaPublicacao.js`: os quatro literais de tela, a normalização do
// payload da wave 1 e a busca local sem acento sobre nome e SKU.
//
// ⚠️ O módulo é importado DIRETO (sem esbuild, sem bundle) exatamente como
// `identidade/identidade.js`: ele não tem JSX nem import de React de propósito.
// A razão não é só de teste — é `feedback_rollup_map_scope_bug.md`: variável de
// escopo do componente lida dentro de um `.map()` já foi ELIMINADA pelo Rollup
// no bundle de produção deste projeto. A classificação e o filtro moram num
// módulo puro para nunca dependerem disso.
//
// ⚠️ Os literais são conferidos por `assert.equal` (igualdade de string
// INTEIRA), nunca por `assert.match`: um `match` passaria com a frase do rodapé
// truncada, que é justamente uma das mutações obrigatórias deste plano.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const MODULO = pathToFileURL(
    path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/header/novaPublicacao.js'),
).href;

const { GRUPOS, VAZIO, RODAPE, PLACEHOLDER, CARREGANDO, ERRO, filtrarGrupos, normalizarPayload } =
    await import(MODULO);

// ─────────────────────────────────────────────────────────────────────────
// Fixtures no shape EXATO do contrato da wave 1 (178-01-SUMMARY):
//   { total, grupos: [ { chave, itens: [ {produto_id, nome, sku, nota, capa, url} ] } ] }
// ─────────────────────────────────────────────────────────────────────────

const espelho = {
    produto_id: 11,
    nome: 'Espelho Redondo Bisotê 60 cm',
    sku: 'ESP-RD-60',
    nota: 'Fase 1 publicada · 2 no ar',
    capa: 'https://http2.mlstatic.com/D_11-I.jpg',
    url: '/produto/11',
};

const poltrona = {
    produto_id: 12,
    nome: 'Poltrona Beny Veludo',
    sku: 'PLT-BENY-01',
    nota: 'Recebido do Portal do Cliente',
    capa: null,
    url: '/editor/12',
};

const mesa = {
    produto_id: 13,
    nome: 'Mesa Lateral Tora',
    sku: 'MSA-TORA-02',
    nota: 'Conferido, pronto para publicar',
    capa: null,
    url: '/editor/13',
};

const payloadCheio = () => ({
    total: 3,
    grupos: [
        { chave: 'prontos_fase2', itens: [espelho] },
        { chave: 'sem_publicacao', itens: [poltrona] },
        { chave: 'rascunhos', itens: [mesa] },
    ],
});

// ═══════════════════════════════════════════════════════════════════════════
// Os literais de tela (NOVA-02, NOVA-05, NOVA-06)
// ═══════════════════════════════════════════════════════════════════════════

test('novaPublicacao — os literais de tela, byte a byte', async (contexto) => {
    await contexto.test('os 3 grupos na ordem do protótipo (:214) com rótulo, hint e CTA de :213', () => {
        assert.deepEqual(
            GRUPOS.map((g) => g.chave),
            ['prontos_fase2', 'sem_publicacao', 'rascunhos'],
        );

        assert.equal(GRUPOS[0].rotulo, 'PRONTOS PARA FASE 2');
        assert.equal(GRUPOS[0].hint, 'reaproveita a Fase 1');
        assert.equal(GRUPOS[0].cta, 'Criar combo');
        assert.equal(GRUPOS[0].tom, 'accent');

        assert.equal(GRUPOS[1].rotulo, 'SEM PUBLICAÇÃO');
        assert.equal(GRUPOS[1].hint, '1 unidade por oferta');
        assert.equal(GRUPOS[1].cta, 'Publicar Fase 1');
        assert.equal(GRUPOS[1].tom, 'solido');

        assert.equal(GRUPOS[2].rotulo, 'RASCUNHOS');
        assert.equal(GRUPOS[2].hint, 'retome de onde parou');
        assert.equal(GRUPOS[2].cta, 'Continuar');
        assert.equal(GRUPOS[2].tom, 'discreto');
    });

    await contexto.test('o literal do vazio (NOVA-05)', () => {
        assert.equal(VAZIO, 'Nenhum produto encontrado');
    });

    await contexto.test('a frase do rodapé (NOVA-06) — string INTEIRA, com o ponto final', () => {
        assert.equal(
            RODAPE,
            'A Fase 2 usa o mesmo Publicador e reaproveita categoria, atributos e ficha técnica da Fase 1.',
        );
    });

    await contexto.test('o placeholder do input (protótipo :137)', () => {
        assert.equal(PLACEHOLDER, 'Qual produto você quer publicar?');
    });

    await contexto.test('as frases de carregando e de erro — o protótipo não tem, mas lista vazia em silêncio é proibida', () => {
        assert.equal(CARREGANDO, 'Carregando…');
        assert.equal(ERRO, 'Não foi possível carregar os produtos agora. Tente de novo em instantes.');
    });

    await contexto.test('o rótulo em caixa alta é o TEXTO, não um text-transform', () => {
        // O protótipo :143 não tem `text-transform`. Se o rótulo chegasse em
        // caixa baixa e a tela usasse `uppercase`, a leitura de tela e o
        // copiar/colar divergiriam do desenho.
        for (const grupo of GRUPOS) {
            assert.equal(grupo.rotulo, grupo.rotulo.toLocaleUpperCase('pt-BR'));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// normalizarPayload — a fronteira entre o servidor e o JSX
// ═══════════════════════════════════════════════════════════════════════════

test('normalizarPayload — nada do servidor entra no JSX sem passar por aqui', async (contexto) => {
    await contexto.test('payload bom atravessa inteiro', () => {
        const normal = normalizarPayload(payloadCheio());
        assert.equal(normal.total, 3);
        assert.equal(normal.grupos.length, 3);
        assert.deepEqual(normal.grupos.map((g) => g.chave), ['prontos_fase2', 'sem_publicacao', 'rascunhos']);
        assert.equal(normal.grupos[0].itens[0].nome, 'Espelho Redondo Bisotê 60 cm');
    });

    await contexto.test('dados nulo / string / número / array / ausente → total nulo e grupos vazio', () => {
        for (const dados of [null, undefined, 'ops', 42, [], [{ chave: 'rascunhos' }], true]) {
            const normal = normalizarPayload(dados);
            assert.equal(normal.total, null, `total deveria ser null para ${JSON.stringify(dados)}`);
            assert.deepEqual(normal.grupos, [], `grupos deveria ser [] para ${JSON.stringify(dados)}`);
        }
    });

    await contexto.test('total não finito vira null — NaN é typeof number e escreveria "NaN" na tela', () => {
        for (const total of [NaN, '12', {}, null, undefined, Infinity, -Infinity]) {
            assert.equal(normalizarPayload({ total, grupos: [] }).total, null, `total ${String(total)}`);
        }
        assert.equal(normalizarPayload({ total: 0, grupos: [] }).total, 0);
        assert.equal(normalizarPayload({ total: 7, grupos: [] }).total, 7);
    });

    await contexto.test('grupos não array vira [] e grupo sem itens array vira itens: []', () => {
        assert.deepEqual(normalizarPayload({ total: 1, grupos: 'x' }).grupos, []);
        assert.deepEqual(normalizarPayload({ total: 1, grupos: null }).grupos, []);

        const normal = normalizarPayload({
            total: 1,
            grupos: [{ chave: 'rascunhos' }, { chave: 'prontos_fase2', itens: 'nao-array' }, null],
        });
        assert.equal(normal.grupos.length, 3);
        for (const grupo of normal.grupos) {
            assert.ok(Array.isArray(grupo.itens), 'itens tem de ser array em todo grupo normalizado');
            assert.deepEqual(grupo.itens, []);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// filtrarGrupos — a ordem (NOVA-02), o grupo vazio (NOVA-03) e a busca (NOVA-04)
// ═══════════════════════════════════════════════════════════════════════════

const filtrar = (consulta, grupos = payloadCheio().grupos) => filtrarGrupos({ grupos, consulta });
const nomesDe = (saida) => saida.flatMap((g) => g.itens.map((i) => i.nome));

test('filtrarGrupos — a ordem dos grupos é contrato (NOVA-02)', async (contexto) => {
    await contexto.test('sem consulta: os 3 grupos na ordem de GRUPOS, já com rótulo/hint/cta/tom', () => {
        const saida = filtrar('');
        assert.deepEqual(saida.map((g) => g.chave), ['prontos_fase2', 'sem_publicacao', 'rascunhos']);
        assert.deepEqual(saida.map((g) => g.rotulo), ['PRONTOS PARA FASE 2', 'SEM PUBLICAÇÃO', 'RASCUNHOS']);
        assert.deepEqual(saida.map((g) => g.hint), ['reaproveita a Fase 1', '1 unidade por oferta', 'retome de onde parou']);
        assert.deepEqual(saida.map((g) => g.cta), ['Criar combo', 'Publicar Fase 1', 'Continuar']);
        assert.deepEqual(saida.map((g) => g.tom), ['accent', 'solido', 'discreto']);
    });

    await contexto.test('servidor mandando fora de ordem NÃO muda a ordem da tela', () => {
        const foraDeOrdem = [
            { chave: 'rascunhos', itens: [mesa] },
            { chave: 'sem_publicacao', itens: [poltrona] },
            { chave: 'prontos_fase2', itens: [espelho] },
        ];
        assert.deepEqual(
            filtrar('', foraDeOrdem).map((g) => g.chave),
            ['prontos_fase2', 'sem_publicacao', 'rascunhos'],
        );
    });

    await contexto.test('grupo de chave desconhecida é ignorado, sem lançar', () => {
        const comIntruso = [
            ...payloadCheio().grupos,
            { chave: 'erros_de_publicacao', itens: [{ ...mesa, nome: 'Intruso' }] },
        ];
        const saida = filtrar('', comIntruso);
        assert.equal(saida.length, 3);
        assert.ok(!nomesDe(saida).includes('Intruso'));
    });
});

test('filtrarGrupos — grupo sem item SAI da lista (NOVA-03)', async (contexto) => {
    await contexto.test('grupo com itens: [] não aparece', () => {
        const saida = filtrarGrupos({
            grupos: [
                { chave: 'prontos_fase2', itens: [] },
                { chave: 'sem_publicacao', itens: [poltrona] },
                { chave: 'rascunhos', itens: [] },
            ],
            consulta: '',
        });
        assert.deepEqual(saida.map((g) => g.chave), ['sem_publicacao']);
    });

    await contexto.test('grupo que perde TODOS os itens pela busca também sai', () => {
        const saida = filtrar('espelho');
        assert.deepEqual(saida.map((g) => g.chave), ['prontos_fase2']);
    });

    await contexto.test('grupo ausente no payload não aparece', () => {
        const saida = filtrarGrupos({ grupos: [{ chave: 'rascunhos', itens: [mesa] }], consulta: '' });
        assert.deepEqual(saida.map((g) => g.chave), ['rascunhos']);
    });
});

test('filtrarGrupos — a busca ignora acento e caixa, sobre nome E sku (NOVA-04)', async (contexto) => {
    await contexto.test('"espelho" acha "Espelho Redondo Bisotê 60 cm"', () => {
        assert.deepEqual(nomesDe(filtrar('espelho')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"ESPELHO" (caixa alta) acha o mesmo item', () => {
        assert.deepEqual(nomesDe(filtrar('ESPELHO')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"bisote" (sem acento) acha "Bisotê"', () => {
        assert.deepEqual(nomesDe(filtrar('bisote')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"BISOTÊ" (com acento e em caixa alta) acha igual', () => {
        assert.deepEqual(nomesDe(filtrar('BISOTÊ')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"esp-rd" acha pelo SKU "ESP-RD-60"', () => {
        assert.deepEqual(nomesDe(filtrar('esp-rd')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('"plt-beny" acha o item de outro grupo pelo SKU', () => {
        const saida = filtrar('plt-beny');
        assert.deepEqual(saida.map((g) => g.chave), ['sem_publicacao']);
        assert.deepEqual(nomesDe(saida), ['Poltrona Beny Veludo']);
    });

    await contexto.test('"  espelho  " com espaço em volta acha igual — a consulta é trimada', () => {
        assert.deepEqual(nomesDe(filtrar('  espelho  ')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('a busca atravessa a fronteira nome→sku, como o `p[0] + \' \' + p[1]` do protótipo :214', () => {
        assert.deepEqual(nomesDe(filtrar('60 cm esp')), ['Espelho Redondo Bisotê 60 cm']);
    });

    await contexto.test('consulta que não casa nada devolve [] — lista VAZIA, não grupo vazio', () => {
        const saida = filtrar('cadeira gamer');
        assert.deepEqual(saida, []);
        assert.equal(saida.length, 0);
    });
});

test('filtrarGrupos — casos adversos não lançam (REND-01/02)', async (contexto) => {
    await contexto.test('grupos nulo / string / número / objeto / ausente → []', () => {
        assert.deepEqual(filtrarGrupos({ grupos: null, consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ grupos: 'x', consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ grupos: 7, consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ grupos: { chave: 'rascunhos' }, consulta: '' }), []);
        assert.deepEqual(filtrarGrupos({ consulta: 'espelho' }), []);
        assert.deepEqual(filtrarGrupos({}), []);
        assert.deepEqual(filtrarGrupos(), []);
    });

    await contexto.test('consulta nula / objeto / número não lança e não vira "[object Object]"', () => {
        // ⚠️ consulta objeto NÃO pode achar nada: `normalizar` devolve '' para
        // não-texto, e '' é o mesmo que "sem busca" — então todos os grupos
        // voltam. O que não pode acontecer é LANÇAR ou filtrar por
        // "[object object]".
        assert.equal(filtrarGrupos({ grupos: payloadCheio().grupos, consulta: null }).length, 3);
        assert.equal(filtrarGrupos({ grupos: payloadCheio().grupos, consulta: {} }).length, 3);
        assert.equal(filtrarGrupos({ grupos: payloadCheio().grupos, consulta: 60 }).length, 1);
    });

    await contexto.test('item com nome e sku OBJETO não casa nada e não lança', () => {
        const grupos = [{ chave: 'rascunhos', itens: [{ ...mesa, nome: {}, sku: {} }] }];
        assert.deepEqual(filtrarGrupos({ grupos, consulta: 'object' }), []);
        assert.deepEqual(filtrarGrupos({ grupos, consulta: 'mesa' }), []);
        // Sem busca o item continua na lista — quem decide o que mostrar de um
        // campo-objeto é o JSX (`textoSeguro`), não o filtro.
        assert.equal(filtrarGrupos({ grupos, consulta: '' })[0].itens.length, 1);
    });

    await contexto.test('item nulo / {} / sem campos dentro de itens não lança', () => {
        const grupos = [{ chave: 'rascunhos', itens: [null, {}, undefined, mesa] }];
        assert.doesNotThrow(() => filtrarGrupos({ grupos, consulta: '' }));
        assert.deepEqual(nomesDe(filtrarGrupos({ grupos, consulta: 'tora' })), ['Mesa Lateral Tora']);
    });

    await contexto.test('a saída de filtrarGrupos NUNCA compartilha o array de itens do payload', () => {
        // Mutar a saída não pode corromper o payload que ainda está em estado.
        const payload = payloadCheio();
        const saida = filtrarGrupos({ grupos: payload.grupos, consulta: '' });
        saida[0].itens.push({ nome: 'fantasma' });
        assert.equal(payload.grupos[0].itens.length, 1);
    });
});
