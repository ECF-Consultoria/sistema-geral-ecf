import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { criarRota } from '../../resources/js/Components/Publicador/apoio.js';
import {
    conclusaoDaIa, contarProntas, envioDasVariantes, envioDoRascunho, esperaDaNovaTentativa, estadoDaConferencia, estadoDoSalvamento, iguais, mesclarAlvos,
    mesclarComPendentes, mesclarVariantes, pendenciasDaConferencia, podeConferir, podePublicar, estadoDaIa, rascunhoPreenchido,
    resumoDoLancamento, semRepetir, textoDaEtapa, textoDaConferencia, totalDeAnuncios,
} from '../../resources/js/Components/Publicador/derivados.js';

const HOOK = 'resources/js/Components/Publicador/usePublicador.js';
const PAGINA_EDITOR = 'resources/js/Pages/Mlb/Publicador/Editor.jsx';
const MESA = 'resources/js/Components/Publicador/Mesa';

/** Corpo de `const nome = …` até a próxima declaração no mesmo nível (4 espaços) do hook. */
const corpo = (fonte, nome) => {
    const i = fonte.indexOf(`const ${nome} = `);
    assert.ok(i >= 0, `não achei "const ${nome} = "`);
    const j = fonte.indexOf('\n    const ', i + 1);

    return fonte.slice(i, j < 0 ? undefined : j);
};

// ═══════════════════════════════════════════════════════════════════════
// Editor do Publicador (164-12): derivados puros + gates de fonte do hook.
// ═══════════════════════════════════════════════════════════════════════

test('criarRota monta a rota interna com o parâmetro do produto', () => {
    globalThis.route = (nome, params) => ({ nome, params });
    const r = criarRota('mlb.anuncios.publicador', 'produto')('salvar', 7);
    assert.deepEqual(r, { nome: 'mlb.anuncios.publicador.salvar', params: { produto: 7 } });
    const f = criarRota('mlb.anuncios.publicador', 'produto')('fotos.remover', 7, { imagem: 3 });
    assert.deepEqual(f.params, { produto: 7, imagem: 3 });
});

test('mesclarVariantes e mesclarAlvos: a cópia local vence o servidor', () => {
    const v = mesclarVariantes([{ chave: 'a', estoque: 1, rotulo: 'A' }], { a: { estoque: 9 } });
    assert.equal(v[0].estoque, 9);
    assert.equal(v[0].rotulo, 'A');
    const a = mesclarAlvos([{ listing_type_id: 'gold_pro', titulo: 'srv', ativo: true }], [{ listing_type_id: 'gold_pro', titulo: 'local' }]);
    assert.equal(a[0].titulo, 'local');
    assert.equal(a[0].ativo, true);
});

test('podeConferir', () => {
    const base = { disabled: false, bloqueiosLocais: 0, salvando: 0, schema: {} };
    assert.equal(podeConferir(base), true);
    assert.equal(podeConferir({ ...base, bloqueiosLocais: 1 }), false);
    assert.equal(podeConferir({ ...base, salvando: 1 }), false);
    assert.equal(podeConferir({ ...base, schema: null }), false);
    assert.equal(podeConferir({ ...base, disabled: true }), false);
});

test('podePublicar: só com conferência do ML que vale; AVISOS exige ciente; conta liberada', () => {
    const base = { disabled: false, conf: { vale: true, resultado: 'OK' }, sujo: false, ciente: false, salvando: 0, liberada: true };
    assert.equal(podePublicar(base), true);
    assert.equal(podePublicar({ ...base, liberada: false }), false);
    assert.equal(podePublicar({ ...base, conf: null }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: false, resultado: 'OK' } }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: true, resultado: 'BLOQUEADO' } }), false);
    assert.equal(podePublicar({ ...base, sujo: true }), false);
    assert.equal(podePublicar({ ...base, salvando: 1 }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: true, resultado: 'AVISOS' } }), false);
    assert.equal(podePublicar({ ...base, conf: { vale: true, resultado: 'AVISOS' }, ciente: true }), true);
});

test('D26: conferência local nunca libera publicar', () => {
    for (const resultado of ['LOCAL', 'OK', 'AVISOS']) {
        assert.equal(podePublicar({ conf: { vale: true, resultado, local: true }, ciente: true, liberada: true }), false);
    }
});

test('estadoDaConferencia e textoDaConferencia: textos literais da UI-SPEC', () => {
    const vale = { vale: true };
    // O 4º argumento diz que as pendências/avisos vieram todos do Mercado Livre (WR-F07).
    const casos = [
        [{ conf: null }, 'nao_conferido', 'Ainda não conferido no Mercado Livre'],
        [{ conf: null, aguardando: { tipo: 'conferencia' } }, 'conferindo', 'Conferindo cada anúncio com o Mercado Livre…'],
        [{ conf: { vale: false, resultado: 'OK' } }, 'editado', 'Editado depois da última conferência. Confira de novo.'],
        [{ conf: { ...vale, resultado: 'OK' }, sujo: true }, 'editado', 'Editado depois da última conferência. Confira de novo.'],
        [{ conf: { ...vale, resultado: 'OK' } }, 'ok', 'Conferido: o Mercado Livre aprovou. Você já pode publicar.'],
        [{ conf: { ...vale, resultado: 'AVISOS' } }, 'avisos', 'Conferido, com avisos do Mercado Livre'],
        [{ conf: { ...vale, resultado: 'BLOQUEADO' } }, 'bloqueado', 'O Mercado Livre apontou 3 pendência(s)'],
        [{ conf: { ...vale, resultado: 'ERRO' } }, 'erro', 'A conferência não terminou. Tente de novo.'],
    ];
    for (const [entrada, estado, texto] of casos) {
        assert.equal(estadoDaConferencia(entrada), estado);
        assert.equal(textoDaConferencia(estado, 3, true, true), texto);
    }
});

test('WR-F07 bloqueio da ficha achado na conferência do ML não vira "o Mercado Livre apontou"', () => {
    // Conta liberada: conferência camada L3, parada no passo 4 por um bloqueio L2.
    const l2 = { regra: 'V-ATT-01', severidade: 'BLOCKER', camada: 'L2', mensagem: 'Preencha a voltagem.', alvo: { etapa: 'E3', atributo: 'VOLTAGE' } };
    const l3 = { regra: 'V-SAL-01', severidade: 'BLOCKER', camada: 'L3', mensagem: 'Esta conta não pode publicar como Premium nesta categoria.', alvo: { etapa: 'E10', campo: 'tipo' } };
    const avisoL2 = { regra: 'V-TIT-02', severidade: 'WARNING', camada: 'L2', mensagem: 'Título curto.', alvo: { etapa: 'E7' } };
    const conf = { vale: true, local: false, resultado: 'BLOQUEADO', issues: [l2, avisoL2] };

    const p = pendenciasDaConferencia(conf);
    assert.deepEqual(p.bloqueios, [l2]);
    assert.deepEqual(p.avisos, [avisoL2]);
    assert.equal(p.bloqueiosDoMl, false);
    assert.equal(textoDaConferencia('bloqueado', p.bloqueios.length, true, p.bloqueiosDoMl), 'A conferência apontou 1 pendência(s)');
    assert.doesNotMatch(textoDaConferencia('bloqueado', 1, true, false), /Mercado Livre/);
    // Só do ML: aí sim o texto atribui a ele.
    assert.equal(pendenciasDaConferencia({ ...conf, issues: [l3] }).bloqueiosDoMl, true);
    // Misturado: texto neutro.
    assert.equal(pendenciasDaConferencia({ ...conf, issues: [l2, l3] }).bloqueiosDoMl, false);
    // Avisos só da ficha: "da conferência".
    assert.equal(textoDaConferencia('avisos', 0, true, false), 'Conferido, com avisos da conferência');
    // Conferência que não vale mais ou só local (D26): nada daqui (os locais cuidam).
    assert.deepEqual(pendenciasDaConferencia({ ...conf, vale: false }).bloqueios, []);
    assert.deepEqual(pendenciasDaConferencia({ ...conf, local: true }).bloqueios, []);
    assert.deepEqual(pendenciasDaConferencia(null).bloqueios, []);
});

test('WR-F07 semRepetir: o bloqueio da conferência que os locais já mostram não conta duas vezes', () => {
    const a = { regra: 'V-ATT-01', alvo: { etapa: 'E3', atributo: 'BRAND' } };
    const b = { regra: 'V-ATT-01', alvo: { etapa: 'E3', atributo: 'VOLTAGE' } };
    assert.deepEqual(semRepetir([a, b], [{ ...a, mensagem: 'outra' }]), [b]);
    assert.deepEqual(semRepetir([a], []), [a]);
});

test('WR-F07 usePublicador e revisão: todas as pendências da conferência entram e são listadas, com texto pela origem', () => {
    const f = lerSemComentarios(HOOK);
    assert.match(f, /const daConferencia = pendenciasDaConferencia\(conf\)/);
    assert.match(f, /semRepetir\(\[\.\.\.daConferencia\.bloqueios, \.\.\.daConferencia\.avisos\], locais\)/);
    assert.doesNotMatch(f, /p\.camada === 'L3'/);
    assert.match(f, /textoDaConferencia\(estadoConf, nPendencias, liberada, conferenciaDoMl\)/);
    assert.match(f, /bloqueios: daConferencia\.bloqueios/);
    // 04/10/2026 (3 etapas): quem lista as pendências é "Revisar e publicar", no fim da última etapa — TODAS
    // (locais + conferência + publicação, `pub.problemas`), por etapa, bloqueios primeiro, com "Corrigir em …".
    const l = lerSemComentarios(`${MESA}/Publicar.jsx`);
    assert.match(l, /const lista = pub\.problemas\.filter\(\(p\) => p\.severidade !== 'INFO'\)/);
    assert.match(l, /etapaDoProblema\(p\) === e\.chave/);
    assert.match(l, /Corrigir em \{e\.titulo\}/);
    assert.doesNotMatch(l, /camada === 'L3'/);
    assert.doesNotMatch(l, /Li os avisos do Mercado Livre/);
    // CR-B01: a publicação que falha mostra o motivo do servidor (conta trocada, outro vendedor).
    assert.match(l, /\{publicacao\.motivo && <p[^>]*>\{publicacao\.motivo\}<\/p>\}/);
});

test('D26: estados e textos locais nunca falam em aprovação do Mercado Livre', () => {
    assert.equal(estadoDaConferencia({ conf: { vale: true, resultado: 'LOCAL', local: true } }), 'local');
    assert.equal(estadoDaConferencia({ conf: { vale: true, resultado: 'BLOQUEADO', local: true } }), 'local_bloqueado');
    assert.equal(estadoDaConferencia({ conf: { vale: false, resultado: 'LOCAL', local: true } }), 'editado');
    assert.equal(textoDaConferencia('local', 0, false), 'Conferido aqui: nada falta na ficha. A validação no Mercado Livre espera a liberação desta conta.');
    assert.equal(textoDaConferencia('local_bloqueado', 2, false), 'A conferência local apontou 2 pendência(s). A validação no Mercado Livre espera a liberação desta conta.');
    assert.equal(textoDaConferencia('nao_conferido', 0, false), 'Ainda não conferido. Nesta conta a conferência é só local até a liberação.');
    assert.equal(textoDaConferencia('conferindo', 0, false), 'Conferindo os dados…');
    assert.doesNotMatch(textoDaConferencia('local', 0, false), /aprovou|apontou/);
});

test('contarProntas: sem schema só a categoria pode estar pronta', () => {
    assert.equal(contarProntas([], null), 1);
    assert.equal(contarProntas([], {}), 8);
    assert.equal(contarProntas([{ severidade: 'BLOCKER', alvo: { etapa: 'E2' } }], {}), 7);
    assert.equal(contarProntas([{ severidade: 'WARNING', alvo: { etapa: 'E2' } }], {}), 8);
});

test('resumoDoLancamento e totalDeAnuncios', () => {
    const variantes = [
        { chave: 'a', ativa: true, precos: { gold_special: 10, gold_pro: 12 } },
        { chave: 'b', ativa: true, precos: {}, precos_efetivos: { gold_special: 30, gold_pro: 35 } },
        { chave: 'c', ativa: true, orfa: true, precos: { gold_special: 999 } },
        { chave: 'd', ativa: false, precos: { gold_special: 1 } },
    ];
    const alvos = [{ listing_type_id: 'gold_special', ativo: true }, { listing_type_id: 'gold_pro', ativo: false }];
    const r = resumoDoLancamento({}, { envio: { modo: 'custom' } }, variantes, alvos);
    assert.equal(r.modoLogistico, 'Envio próprio');
    assert.deepEqual(r.classico, { ativo: true, n: 2, min: 10, max: 30 });
    assert.equal(r.premium.ativo, false);
    assert.equal(r.total, 2);
    assert.equal(totalDeAnuncios(alvos, variantes), 2);
    assert.equal(resumoDoLancamento({}, {}, [], []).modoLogistico, 'Mercado Envios');
});

test('rascunhoPreenchido', () => {
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: {}, alvos: [{ titulo: '' }], descricao: '' }), false);
    assert.equal(rascunhoPreenchido({ rascunho: { categoria_id: 'MLB1' } }, { atributos: {}, alvos: [], descricao: '' }), true);
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: { BRAND: { value_name: 'X' } }, alvos: [], descricao: '' }), true);
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: {}, alvos: [{ titulo: 'T' }], descricao: '' }), true);
    assert.equal(rascunhoPreenchido({ rascunho: {} }, { atributos: {}, alvos: [], descricao: 'texto' }), true);
});

// ─── CR-F01: ação de estrutura não descarta o que foi digitado ───

const ENVIO = { modo: 'me2', frete_gratis: false, retirada: false };
const rascDe = (extra = {}) => ({
    atributos: { BRAND: { value_name: 'Acme' }, MODEL: { value_name: 'X1' } },
    alvos: [{ listing_type_id: 'gold_special', titulo: 'Velho', ativo: true }, { listing_type_id: 'gold_pro', titulo: null, ativo: true }],
    condicao: 'new', descricao: 'antes', envio: ENVIO, garantia: null,
    ...extra,
});
const varsDe = (extra = {}) => ({ a: { ativa: true, estoque: 1, estoque_depositos: null, precos: {}, atributos: {} }, ...extra });

test('CR-F01 iguais: profunda, null ≡ undefined e [] ≡ {} (o PHP manda [] para mapa vazio)', () => {
    assert.equal(iguais({ a: [1, { b: 2 }] }, { a: [1, { b: 2 }] }), true);
    assert.equal(iguais({ a: 1 }, { a: 2 }), false);
    assert.equal(iguais(null, undefined), true);
    assert.equal(iguais([], {}), true);
    assert.equal(iguais([1], [1, 2]), false);
    assert.equal(iguais({ x: { value_name: 'a' } }, { x: undefined }), false);
});

test('CR-F01 título digitado durante a ação de foto fica na tela e segue por salvar (cenário 1)', () => {
    const base = { rasc: rascDe(), vars: varsDe() };
    const local = { rasc: rascDe({ alvos: [{ listing_type_id: 'gold_special', titulo: 'Novo', ativo: true }, { listing_type_id: 'gold_pro', titulo: null, ativo: true }] }), vars: varsDe() };
    const servidor = { rasc: rascDe(), vars: varsDe() };
    const r = mesclarComPendentes({ servidor, local, base });
    assert.equal(r.rasc.alvos[0].titulo, 'Novo');
    assert.deepEqual(r.pendente, { rasc: true, vars: false });
    assert.deepEqual(envioDoRascunho(r.rasc, servidor.rasc), { alvos: r.rasc.alvos });
});

test('CR-F01 descrição digitada enquanto as fotos sobem não volta (cenário 2)', () => {
    const base = { rasc: rascDe(), vars: varsDe() };
    const local = { rasc: rascDe({ descricao: 'texto novo' }), vars: varsDe() };
    // O servidor responde à foto com o rascunho de antes (sem a descrição, que ainda não chegou lá).
    const r = mesclarComPendentes({ servidor: { rasc: rascDe(), vars: varsDe() }, local, base });
    assert.equal(r.rasc.descricao, 'texto novo');
    assert.equal(r.pendente.rasc, true);
});

test('CR-F01 troca de categoria: o que o servidor descartou sai; o editado e o apagado durante a ação ficam como na tela', () => {
    const base = { rasc: rascDe({ atributos: { BRAND: { value_name: 'Acme' }, MODEL: { value_name: 'X1' }, COLOR: { value_name: 'Azul' } } }), vars: varsDe() };
    // Durante a ação: BRAND editado, COLOR apagado.
    const local = { rasc: rascDe({ atributos: { BRAND: { value_name: 'Acme Pro' }, MODEL: { value_name: 'X1' } } }), vars: varsDe() };
    // O servidor (categoria nova) descartou MODEL e ainda tem COLOR e o BRAND antigo.
    const servidor = { rasc: rascDe({ atributos: { BRAND: { value_name: 'Acme' }, COLOR: { value_name: 'Azul' }, VOLTAGE: { value_name: '110' } } }), vars: varsDe() };
    const r = mesclarComPendentes({ servidor, local, base });
    assert.deepEqual(r.rasc.atributos, { BRAND: { value_name: 'Acme Pro' }, VOLTAGE: { value_name: '110' } });
    assert.equal(r.pendente.rasc, true);
});

test('CR-F01 variantes: estoque digitado durante a ação fica; variante nova vem do servidor; a que sumiu cai', () => {
    const base = { rasc: rascDe(), vars: varsDe({ b: { ativa: true, estoque: 5, precos: {}, atributos: {} } }) };
    const local = { rasc: rascDe(), vars: varsDe({ a: { ativa: true, estoque: 9, estoque_depositos: null, precos: {}, atributos: {} }, b: { ativa: true, estoque: 5, precos: {}, atributos: {} } }) };
    const servidor = { rasc: rascDe(), vars: { a: { ativa: true, estoque: 1, estoque_depositos: null, precos: { gold_special: 30 }, atributos: {} }, c: { ativa: true, estoque: 0, precos: {}, atributos: {} } } };
    const r = mesclarComPendentes({ servidor, local, base });
    assert.equal(r.vars.a.estoque, 9);
    assert.deepEqual(r.vars.a.precos, { gold_special: 30 });
    assert.deepEqual(r.vars.c, servidor.vars.c);
    assert.equal(r.vars.b, undefined);
    assert.deepEqual(r.pendente, { rasc: false, vars: true });
    assert.deepEqual(envioDasVariantes(r.vars, servidor.vars), { a: { estoque: 9 } });
});

test('CR-F01 sem edição pendente a resposta do servidor vence inteira; sem cópia local (abertura) também', () => {
    const base = { rasc: rascDe(), vars: varsDe() };
    const servidor = { rasc: rascDe({ descricao: 'da IA', garantia: { tipo: 'fabrica' } }), vars: varsDe() };
    const r = mesclarComPendentes({ servidor, local: { rasc: rascDe(), vars: varsDe() }, base });
    assert.deepEqual(r.rasc, servidor.rasc);
    assert.deepEqual(r.pendente, { rasc: false, vars: false });
    const abertura = mesclarComPendentes({ servidor, local: { rasc: null, vars: {} }, base: { rasc: null, vars: {} } });
    assert.equal(abertura.rasc, servidor.rasc);
    // Opção de foto ligada na tela (não vem do servidor) e ainda não salva: fica.
    const comOpcao = mesclarComPendentes({ servidor, local: { rasc: rascDe({ incluir_geral: true }), vars: varsDe() }, base });
    assert.equal(comOpcao.rasc.incluir_geral, true);
});

test('CR-F01 envioDoRascunho e envioDasVariantes: só o que difere da base; nulo quando nada', () => {
    assert.equal(envioDoRascunho(rascDe(), rascDe()), null);
    assert.deepEqual(envioDoRascunho(rascDe({ descricao: 'x' }), rascDe()), { descricao: 'x' });
    assert.equal(envioDasVariantes(varsDe(), varsDe()), null);
    assert.deepEqual(envioDasVariantes(varsDe({ a: { ativa: false, estoque: 1, estoque_depositos: null, precos: {}, atributos: {} } }), varsDe()), { a: { ativa: false } });
});

test('CR-F01 usePublicador: toda ação de estrutura descarrega antes, na fila, e mescla a resposta', () => {
    const f = lerSemComentarios(HOOK);
    for (const nome of ['escolherCategoria', 'salvarEixos', 'enviarFotos', 'atribuirFotos', 'removerFoto', 'reenviarFoto']) {
        assert.match(corpo(f, nome), /estruturar\(/, nome);
        assert.doesNotMatch(corpo(f, nome), /chamar\(/, `${nome} não chama o servidor por fora da fila`);
    }
    const e = corpo(f, 'estruturar');
    assert.match(e, /enfileirar\(async \(\) => \{\s*await salvarTudoAgora\(\);/);
    assert.match(e, /chamar\(fazer, \{ tudo: true \}\)/);
    assert.match(corpo(f, 'chamar'), /if \(tudo\) aplicarServidor\(data, \{ mesclar: true \}\)/);
    assert.match(corpo(f, 'aplicarServidor'), /mesclarComPendentes\(/);
    // "descarregar" espera o que já está em voo: entra na fila.
    assert.match(f, /const descarregar = \(\) => enfileirar\(salvarTudoAgora\)/);
    assert.doesNotMatch(f, /setRasc\(doEstado/);
    assert.doesNotMatch(f, /rascRef\.current = rasc;/);
});

// ─── CR-F02: o salvamento automático não apaga o que a IA gravou ───

test('CR-F02 salvamento manda só o campo editado: características e títulos da IA não voltam ao servidor', () => {
    const base = rascDe();
    // A pessoa só mexeu na garantia; atributos, títulos e descrição ficam fora do PUT.
    assert.deepEqual(envioDoRascunho(rascDe({ garantia: { tipo: 'vendedor', tempo: 3, unidade: 'meses' } }), base), {
        garantia: { tipo: 'vendedor', tempo: 3, unidade: 'meses' },
    });
});

test('CR-F02 releitura depois da IA: o que ela gravou entra; o pendente da pessoa fica, campo a campo', () => {
    const base = { rasc: rascDe(), vars: varsDe() };
    // Antes da IA a pessoa editou BRAND, e esse salvamento falhou.
    const local = { rasc: rascDe({ atributos: { BRAND: { value_name: 'Minha marca' }, MODEL: { value_name: 'X1' } } }), vars: varsDe() };
    const servidor = {
        rasc: rascDe({
            atributos: { BRAND: { value_name: 'Acme' }, MODEL: { value_name: 'X1' }, COLOR: { value_name: 'Preto' }, MATERIAL: { value_name: 'Aço' } },
            alvos: [{ listing_type_id: 'gold_special', titulo: 'Título da IA', ativo: true }, { listing_type_id: 'gold_pro', titulo: 'Título da IA', ativo: true }],
            descricao: 'Descrição da IA',
        }),
        vars: varsDe(),
    };
    const r = mesclarComPendentes({ servidor, local, base });
    assert.equal(r.rasc.atributos.BRAND.value_name, 'Minha marca');
    assert.equal(r.rasc.atributos.COLOR.value_name, 'Preto');
    assert.equal(r.rasc.atributos.MATERIAL.value_name, 'Aço');
    assert.equal(r.rasc.alvos[0].titulo, 'Título da IA');
    assert.equal(r.rasc.descricao, 'Descrição da IA');
    // O que sai depois é o mapa mesclado (com o da IA), não a cópia de antes da IA.
    assert.deepEqual(Object.keys(envioDoRascunho(r.rasc, servidor.rasc)), ['atributos']);
    assert.equal(envioDoRascunho(r.rasc, servidor.rasc).atributos.COLOR.value_name, 'Preto');
});

test('CR-F02 Editor: mesa só leitura enquanto a IA trabalha e releitura no fim (concluída ou com erro)', () => {
    const f = lerSemComentarios(PAGINA_EDITOR);
    assert.match(f, /pausado: ia\.estado === 'andamento'/);
    assert.match(f, /onConcluiu: \(\) => depoisDaIa\.current\(\)/);
    assert.match(f, /onFalhou: \(\) => depoisDaIa\.current\(\)/);
    assert.match(f, /depoisDaIa\.current = pub\.recarregarDepoisDaIa/);
    assert.match(f, /o anúncio fica só para leitura/);
    assert.doesNotMatch(f, /onConcluiu: \(\) => pub\.recarregar\(\)/);
    const ia = lerSemComentarios('resources/js/Components/Publicador/useIaDoPublicador.js');
    assert.match(ia, /else aoFalhar\.current\?\.\(/);
});

test('CR-F02 usePublicador: pausa, só o editado no PUT e releitura depois do descarregar, sem PUT correndo com o GET', () => {
    const f = lerSemComentarios(HOOK);
    assert.match(corpo(f, 'disabled'), /\|\| pausado \|\| relendo/);
    for (const nome of ['salvarRascAgora', 'salvarVarsAgora']) {
        const c = corpo(f, nome);
        assert.ok(c.indexOf('if (pausadoRef.current) return false;') > 0 && c.indexOf('if (pausadoRef.current) return false;') < c.indexOf('axios.put('), `${nome}: pausa antes do PUT`);
    }
    assert.match(corpo(f, 'salvarRascAgora'), /axios\.put\(rota\('salvar', produtoId\), envio\)/);
    assert.match(corpo(f, 'salvarVarsAgora'), /\{ variantes: envio \}/);
    const reler = corpo(f, 'reler');
    assert.ok(reler.indexOf('await salvarTudoAgora()') < reler.indexOf("axios.get(rota('abrir'"), 'descarrega antes do GET');
    assert.match(reler, /aplicarServidor\(data, \{ mesclar: true \}\)/);
    assert.match(corpo(f, 'recarregar'), /await enfileirar\(\(\) => reler\(\{ descarregarAntes: true \}\)\)/);
    assert.match(corpo(f, 'recarregarDepoisDaIa'), /enfileirar\(\(\) => reler\(\{ descarregarAntes: false \}\)\)/);
    assert.doesNotMatch(f, /setRecarga/);
    // A saída do produto não manda mais o documento inteiro.
    assert.doesNotMatch(f, /axios\.put\(rota\('salvar', produtoId\), rascRef\.current\)/);
    assert.doesNotMatch(f, /variantes: varsRef\.current/);
});

// ─── WR-F02: salvamento que falha tenta de novo e não diz "Salvo" ───

test('WR-F02 esperaDaNovaTentativa: 2 s, 5 s, 15 s e então desiste', () => {
    assert.equal(esperaDaNovaTentativa(1), 2000);
    assert.equal(esperaDaNovaTentativa(2), 5000);
    assert.equal(esperaDaNovaTentativa(3), 15000);
    assert.equal(esperaDaNovaTentativa(4), null);
});

test('WR-F02 estadoDoSalvamento: "Salvo" só sem nada por salvar; falha vira "tentando" ou "falhou"', () => {
    const salvoEm = new Date();
    assert.equal(estadoDoSalvamento({ salvoEm }), 'salvo');
    assert.equal(estadoDoSalvamento({}), null);
    assert.equal(estadoDoSalvamento({ salvando: 1, salvoEm }), 'salvando');
    assert.equal(estadoDoSalvamento({ pendente: true, salvoEm }), 'pendente');
    assert.equal(estadoDoSalvamento({ pendente: true, pausado: true, salvoEm }), 'pausado');
    assert.equal(estadoDoSalvamento({ pendente: true, falha: { mensagem: 'x', desistiu: false }, salvoEm }), 'tentando');
    assert.equal(estadoDoSalvamento({ falha: { mensagem: 'x', desistiu: true }, salvoEm }), 'falhou');
    // A nova tentativa em voo aparece como "Salvando…".
    assert.equal(estadoDoSalvamento({ salvando: 1, falha: { mensagem: 'x', desistiu: false }, salvoEm }), 'salvando');
});

test('WR-F02 usePublicador: falha reagenda com espera crescente, avisa ao desistir e guarda a saída da página', () => {
    const f = lerSemComentarios(HOOK);
    for (const [nome, tipo] of [['salvarRascAgora', 'rasc'], ['salvarVarsAgora', 'vars']]) {
        const c = corpo(f, nome);
        assert.match(c, /fundo: true/, nome);
        assert.match(c, new RegExp(`salvamentoFalhou\\('${tipo}', falha\\)`), nome);
        assert.match(c, new RegExp(`salvamentoEmDia\\('${tipo}'\\)`), nome);
    }
    const falhou = corpo(f, 'salvamentoFalhou');
    assert.match(falhou, /esperaDaNovaTentativa\(\+\+tentativas\.current\[tipo\]\)/);
    assert.match(falhou, /setTimeout\(/);
    assert.match(falhou, /desistiu: true/);
    assert.match(falhou, /setErro\(/);
    // Fechar a aba / F5 e navegação do Inertia.
    assert.match(f, /addEventListener\('beforeunload', aoFecharAba\)/);
    assert.match(f, /removeEventListener\('beforeunload', aoFecharAba\)/);
    assert.match(f, /router\.on\('before'/);
    assert.match(f, /visita\.prefetch \|\| visita\.only\?\.length/);
    assert.match(f, /window\.confirm\(CONFIRMA_SAIR\)/);
    assert.match(f, /salvamento: \{\s*estado: estadoDoSalvamento\(/);
});

test('WR-F02 BarraDoEditor: "Salvo há" só no estado salvo; falha mostra "Não salvo"', () => {
    const f = lerSemComentarios(`${MESA}/BarraDoEditor.jsx`);
    assert.match(f, /pub\.salvamento/);
    assert.match(f, /estado === 'salvo' && texto/);
    assert.ok(f.includes('Não salvo — tentando de novo'));
    assert.match(f, /Não salvo — \{mensagem\}/);
    assert.doesNotMatch(f, /pub\.salvando > 0 \? /);
});

// ─── Gates de fonte do hook ───

test('usePublicador: rotas internas, esperas e limites do piloto', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/usePublicador.js');
    assert.match(f, /criarRota\('mlb\.anuncios\.publicador', 'produto'\)/);
    assert.match(f, /ESPERA_SALVAR = 900/);
    assert.match(f, /INTERVALO_ANDAMENTO = 2500/);
    assert.match(f, /LIMITE_ANDAMENTO = 4 \* 60 \* 1000/);
    assert.doesNotMatch(f, /portal\.auth/);
});

test('derivados.js é puro: sem React nem alias @', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/derivados.js');
    assert.doesNotMatch(f, /from 'react'|from '@\//);
    assert.match(f, /local_bloqueado/);
});

// ─── Anunciar por IA ───

test('textoDaEtapa: textos literais das etapas', () => {
    assert.equal(textoDaEtapa('analise'), 'Analisando o produto…');
    assert.equal(textoDaEtapa('titulos'), 'Escrevendo títulos…');
    assert.equal(textoDaEtapa('descricao'), 'Escrevendo a descrição…');
    assert.equal(textoDaEtapa('ficha'), 'Montando a ficha…');
    assert.equal(textoDaEtapa('rascunho'), 'Preenchendo o rascunho…');
});

test('estadoDaIa: parado, andamento, concluido e erro', () => {
    assert.equal(estadoDaIa(null).estado, 'parado');
    for (const status of ['pendente', 'rodando']) {
        const r = estadoDaIa({ status, etapa: 'ficha' });
        assert.equal(r.estado, 'andamento');
        assert.equal(r.texto, 'Montando a ficha…');
    }
    // WR-F04: o contrato do servidor (`IaParaRascunhoService::resumo`) manda `secoes` como NÚMERO.
    const c = estadoDaIa({ status: 'concluido', publicador: { rascunho_id: 5, secoes: 3, variacoes: true, aviso: null, sobrescreveu: true } });
    assert.equal(c.estado, 'concluido');
    assert.equal(c.secoes, 3);
    assert.equal(c.variacoes, true);
    const e = estadoDaIa({ status: 'erro', erro: 'falhou' });
    assert.equal(e.estado, 'erro');
    assert.equal(e.erro, 'falhou');
    // Sem mensagem do servidor, a página usa o próprio texto.
    assert.equal(estadoDaIa({ status: 'erro', erro: null }).erro, null);
});

test('WR-F04 conclusaoDaIa: seções como número, aviso do servidor e "só o vazio" quando pediu substituir', () => {
    assert.deepEqual(conclusaoDaIa({ secoes: 3, variacoes: true, aviso: null, sobrescreveu: true }, { pediuSubstituir: true }),
        { secoes: 3, aviso: null, soPreencheuOVazio: false, semVariacoes: false });
    // Pediu "Substituir", mas editou durante a geração: o servidor só preencheu o vazio.
    assert.equal(conclusaoDaIa({ secoes: 2, variacoes: false, sobrescreveu: false }, { pediuSubstituir: true }).soPreencheuOVazio, true);
    // Sem pedir "Substituir", sobrescreveu=false é o normal — nada a avisar.
    assert.equal(conclusaoDaIa({ secoes: 2, sobrescreveu: false }, { pediuSubstituir: false }).soPreencheuOVazio, false);
    // Publicação que começou no meio: zero seções e o aviso do servidor.
    const parou = conclusaoDaIa({ secoes: 0, variacoes: false, aviso: 'A publicação começou enquanto a IA preenchia; ela parou ali e não mexeu mais no anúncio.', sobrescreveu: false }, { pediuSubstituir: true });
    assert.equal(parou.secoes, 0);
    assert.match(parou.aviso, /A publicação começou enquanto a IA preenchia/);
    assert.equal(parou.soPreencheuOVazio, false);
    assert.equal(parou.semVariacoes, false);
    // O formato antigo (lista) não vira "N seções".
    assert.equal(conclusaoDaIa({ secoes: ['a', 'b'] }).secoes, 0);
    assert.equal(conclusaoDaIa(null).secoes, 0);
});

test('WR-F04 Editor: faixa da IA lê o número, mostra aviso, "só o vazio" e o erro do servidor', () => {
    const f = lerSemComentarios(PAGINA_EDITOR);
    assert.doesNotMatch(f, /secoes\?\.length/);
    assert.match(f, /conclusaoDaIa\(ia\.resumo, \{ pediuSubstituir: ia\.pediuSubstituir \}\)/);
    assert.match(f, /conclusao\.aviso && /);
    assert.match(f, /Como houve edição durante a geração, a IA só preencheu o que estava vazio\./);
    assert.match(f, /ia\.erro && /);
    assert.doesNotMatch(f, /Nada foi alterado/);
    // A faixa abre a etapa das variações quando a IA não montou as variações.
    assert.match(f, /onClick=\{\(\) => irPara\('detalhes'\)\}/);
    const ia = lerSemComentarios('resources/js/Components/Publicador/useIaDoPublicador.js');
    assert.match(ia, /setPediuSubstituir\(substituir === true\)/);
    assert.match(ia, /pediuSubstituir,/);
});

test('useIaDoPublicador: rotas, polling, limite e sessionStorage', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/useIaDoPublicador.js');
    assert.match(f, /mlb\.anuncios\.ia\.analise\.store/);
    assert.match(f, /mlb\.anuncios\.ia\.analise\.status/);
    assert.match(f, /produto_id: produtoId/);
    assert.match(f, /substituir/);
    assert.match(f, /INTERVALO = 2500/);
    assert.match(f, /LIMITE = 15 \* 60 \* 1000/);
    assert.match(f, /publicador\.ia\.\$\{produtoId\}/);
    assert.match(f, /sessionStorage\.removeItem/);
    assert.match(f, /aoConcluir\.current\?\.\(/);
});

test('useCriativosDoPublicador: rotas por produto com kit_id, polling com limpeza, teto de espera, retomada, instância e contexto', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/useCriativosDoPublicador.js');
    assert.match(f, /criarRota\('mlb\.anuncios\.publicador', 'produto'\)/);
    assert.match(f, /criativos\.atual/);
    assert.match(f, /criativos\.kit\.planejar/);
    assert.match(f, /criativos\.kit\.status/);
    assert.match(f, /criativos\.kit\.gerar/);
    assert.match(f, /criativos\.slot\.regenerar/);
    assert.match(f, /criativos\.slot\.aprovar/);
    assert.match(f, /criativos\.kit\.aprovar/);
    assert.match(f, /kit: kit\.kit_id/);
    assert.match(f, /INTERVALO = 5000/);
    assert.match(f, /LIMITE = 27 \* 60 \* 1000/);
    assert.match(f, /clearInterval\(t\)/);
    assert.match(f, /publicador\.criativos\.\$\{produtoId\}/);
    assert.match(f, /export const CriativosDoPublicador = createContext\(null\)/);
    assert.match(f, /aoAprovar\.current\?\.\(/);
    assert.match(f, /new FormData/);
    assert.match(f, /reivindicar/);
    assert.doesNotMatch(f, /kit_token|\btoken\b/);
});

test('Editor — cria o hook dos criativos e envolve a página com o contexto', () => {
    const f = lerSemComentarios(PAGINA_EDITOR);
    assert.match(f, /useCriativosDoPublicador\(\{/);
    assert.match(f, /criativos_ia === true/);
    assert.match(f, /onAprovou: \(\) => pub\.recarregar\(\)/);
    assert.match(f, /<CriativosDoPublicador\.Provider value=\{criativos\}>/);
});

// ═══════════════════════════════════════════════════════════════════════
// Casca do editor (Conceito E, 03/10/2026): barra com a trilha, seletor de
// produto (ex-faixa de produtos), "Anunciar por IA" e as ações de publicação.
// ═══════════════════════════════════════════════════════════════════════

const CASCA = [`${MESA}/BarraDoEditor.jsx`, `${MESA}/BotaoAnunciarPorIa.jsx`, `${MESA}/SeletorDeProdutos.jsx`];

for (const caminho of CASCA) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia 24/15/13/11px, pesos 400/700, sem sombra nem HTML cru`, () => {
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl)\b/);
        assert.doesNotMatch(fonte, /text-\[(?!24px\]|15px\]|13px\]|11px\])[0-9.]+px\]/);
        assert.doesNotMatch(fonte, /font-(medium|semibold|extrabold|light|thin|black)\b/);
        assert.doesNotMatch(fonte, /\bshadow-(sm|md|lg|xl)\b/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
        assert.doesNotMatch(fonte, /portal\.auth/);
    });
}

test('BarraDoEditor — 56px sticky, trilha "Publicador MLB / empresa / produto" (o produto é o seletor), salvamento real e SEM amarelo sólido', () => {
    const f = lerSemComentarios(`${MESA}/BarraDoEditor.jsx`);
    assert.match(f, /sticky -top-6/);
    assert.match(f, /\bh-14\b/);
    assert.doesNotMatch(f, /pendencias/);
    assert.doesNotMatch(f, /bg-ecf-yellow(?![/\w-])/);
    assert.doesNotMatch(f, /from-\[#FFE600\]/);
    assert.match(f, /<SeletorDeProdutos /);
    assert.match(f, /aria-label="Trilha"/);
    assert.match(f, /aria-live="polite"/);
    // Conferir e Publicar moram no fim da etapa "Condições de venda", não na barra.
    assert.doesNotMatch(f, /data-acao="conferir"|data-acao="publicar"|BotaoPublicar|BotaoConferir/);
    // Nada inventado da referência do Stitch: o salvamento é o real (automático).
    assert.doesNotMatch(f, /Salvar esta|Oficial MLB/);
});

test('BarraDoEditor — estado publicado, salvamento e conexão', () => {
    const f = lerSemComentarios(`${MESA}/BarraDoEditor.jsx`);
    for (const t of ['Publicado no Mercado Livre', 'Voltar aos produtos', 'Salvando…', 'Salvo há', 'Não salvo', 'ML conectado', 'Reconectar']) {
        assert.ok(f.includes(t), `falta o texto "${t}"`);
    }
});

test('SeletorDeProdutos — popover com busca, aria-current, setas, "+ Produto" e selo compacto; sem contador de progresso', () => {
    const f = lerSemComentarios(`${MESA}/SeletorDeProdutos.jsx`);
    assert.match(f, /@radix-ui\/react-popover/);
    assert.match(f, /aria-current=\{ativo \? 'page' : undefined\}/);
    assert.match(f, /ArrowDown/);
    assert.match(f, /ModalNovoProduto/);
    assert.match(f, /SeloStatusProduto status=\{p\.status\} compacto/);
    assert.match(f, /mlb\.anuncios\.publicador\.editor/);
    assert.match(f, /Buscar por SKU ou nome/);
    assert.doesNotMatch(f, /prontas|\{total\}/);
    assert.match(f, /data-produto-em-edicao/);
    assert.doesNotMatch(f, /bg-ecf-yellow(?![/\w-])/);
});

test('AcoesDePublicacao — D26: conta não liberada vira "Conferir dados" com Lock e aria-describedby da nota', () => {
    const f = lerSemComentarios(`${MESA}/AcoesDePublicacao.jsx`);
    assert.match(f, /Conferir dados/);
    assert.match(f, /Conferir no Mercado Livre/);
    assert.equal((f.match(/aria-describedby=\{pub\.liberada \? undefined : 'nota-conta-travada'\}/g) ?? []).length, 2);
    assert.match(f, /A publicação é liberada conta a conta\. Peça ao time de desenvolvimento\./);
    assert.match(f, /Nesta conta a conferência é só local: a validação no Mercado Livre espera a liberação da conta\./);
    assert.match(f, /pub\.liberada \? Rocket : Lock/);
    assert.match(f, /podeConferir/);
    assert.match(f, /podePublicar/);
    assert.doesNotMatch(f, /bg-ecf-yellow(?![/\w-])/);
    for (const t of ['Publicar 1 anúncio', 'Publicar o que faltou', 'Publicando…']) assert.ok(f.includes(t), `falta o texto "${t}"`);
});

test('BotaoAnunciarPorIa — confirma só com rascunho preenchido, descarrega antes e mostra a etapa', () => {
    const f = lerSemComentarios(`${MESA}/BotaoAnunciarPorIa.jsx`);
    assert.match(f, /rascunhoPreenchido\(pub\.m\.estado, pub\.m\.rasc\)/);
    assert.match(f, /await pub\.descarregar\(\)/);
    assert.match(f, /ia\.disparar\(substituir\)/);
    assert.equal((f.match(/Substituir o que já está preenchido/g) ?? []).length, 1);
    for (const t of ['Manter como está', 'Substituir com a IA', 'IA preparando…', 'Anunciar por IA']) assert.ok(f.includes(t), t);
    assert.doesNotMatch(f, /bg-ecf-yellow(?![/\w-])/);
});

// ═══════════════════════════════════════════════════════════════════════
// Página: etapas como no Mercado Livre (pedido do cliente, 04/10/2026; 4ª
// etapa Imagens em 07/10/2026, D1/Fase 169).
// ═══════════════════════════════════════════════════════════════════════

const PAGINA = 'resources/js/Pages/Mlb/Publicador/Editor.jsx';

test(`${PAGINA} — tipografia 24/15/13/11px, pesos 400/700, sem sombra, sem HTML cru, sem gradiente amarelo (ele mora em botoes.jsx)`, () => {
    const fonte = lerSemComentarios(PAGINA);
    assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl)\b/);
    assert.doesNotMatch(fonte, /text-\[(?!24px\]|15px\]|13px\]|11px\])[0-9.]+px\]/);
    assert.doesNotMatch(fonte, /font-(medium|semibold|extrabold|light|thin|black)\b/);
    assert.doesNotMatch(fonte, /\bshadow-(sm|md|lg|xl)\b/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    assert.doesNotMatch(fonte, /from-\[#FFE600\]/);
});

test('Editor.jsx — 4 etapas: só os nomes no topo, uma coluna com as seções da etapa, Voltar/Continuar; etapa sobrevive ao F5', () => {
    const f = lerSemComentarios(PAGINA);
    assert.match(f, /usePublicador\(\{\s*produtoId: produto\.id/);
    assert.match(f, /useIaDoPublicador\(/);
    assert.match(f, /<Etapas atual=\{etapa\}/);
    assert.match(f, /<EtapaProduto m=\{m\} \/>/);
    assert.match(f, /<EtapaDetalhes m=\{m\}( descricaoIa=\{descricaoIa\})? \/>/);
    // Fase 170 (D2, IDENT-01/04): ganhou `produtoId` para a identidade visual da conta (170-02).
    // Fase 173, plano 07: ganhou `empresa` para o link "Gerenciar em Configurações da conta".
    assert.match(f, /<EtapaImagens m=\{m\} produtoId=\{produto\.id\} empresa=\{empresa\} \/>/);
    assert.match(f, /<EtapaCondicoes m=\{m\}>/);
    assert.match(f, /<Publicar pub=\{pub\}/);
    // Os desenhos recusados não voltam: árvore, inspetor, contador de estrutura, trilho, lateral.
    assert.doesNotMatch(f, /Arvore|Inspetor|ItemDoCentro|estadoDosItens|contarItensProntos|prontas=|LateralValidacao|EtapaRevisar|Trilho|PainelPublicar/);
    assert.doesNotMatch(f, /minmax\(0,800px\)|max-w-\[800px\]/);
    assert.match(f, /max-w-\[1200px\]/);
    // "Continuar": descarrega, confere a etapa pelo servidor e só avança sem bloqueio; senão marca e leva ao 1º campo.
    assert.match(f, /await pub\.descarregar\(\);\s*setVerificar/);
    assert.match(f, /bloqueiosDaEtapa\(etapa, pub\.problemas, \{ temCategoria \}\)/);
    assert.match(f, /setTentou\(\(t\) => \(\{ \.\.\.t, \[etapa\]: true \}\)\)/);
    assert.match(f, /querySelector\('\[aria-invalid="true"\]'\)/);
    assert.match(f, /<ErrosDaEtapa value=\{\{ mostrar, problemas: pub\.problemas \}\}>/);
    // "Corrigir em…" leva à etapa já marcada.
    assert.match(f, /onIrPara=\{\(chave\) => irPara\(chave, \{ marcar: true \}\)\}/);
    // Um amarelo por tela: "Continuar" nas etapas 1 e 2; na 3, Conferir/Publicar (em Publicar.jsx).
    assert.equal((f.match(/BotaoAcao primario/g) ?? []).length, 1);
    assert.match(f, /\{proxima && \(/);
    assert.match(f, /sticky -bottom-6/);
    // Os efeitos do anúncio inteiro rodam SEMPRE, em qualquer etapa.
    assert.match(f, /useEfeitosDasVariacoes\(m\)/);
    assert.match(f, /useEfeitosDoEnvio\(m\)/);
    // A etapa vai para a URL (sem mexer no estado do Inertia) e para o sessionStorage por produto.
    assert.match(f, /PARAMETRO_ETAPA = 'etapa'/);
    assert.match(f, /window\.history\.replaceState\(window\.history\.state, '', url\)/);
    assert.match(f, /sessionStorage\.setItem\(chaveGuardada\(produtoId\), chave\)/);
    assert.match(f, /etapaValida\(/);
    assert.match(f, /Não foi possível abrir o produto\./);
    // WR-B04/WR-F07: sem token a conferência local roda; só a do ML e a publicação pedem reconectar.
    assert.match(f, /A conta do Mercado Livre precisa ser reconectada antes de conferir no Mercado Livre ou publicar\./);
    assert.match(f, /A IA preencheu/);
    assert.match(f, /A IA não conseguiu preparar este anúncio\./);
});
