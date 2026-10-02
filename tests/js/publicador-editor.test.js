import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { criarRota } from '../../resources/js/Components/Publicador/apoio.js';
import {
    contarProntas, envioDasVariantes, envioDoRascunho, estadoDaConferencia, iguais, mesclarAlvos, mesclarComPendentes, mesclarVariantes,
    podeConferir, podePublicar, estadoDaIa, rascunhoPreenchido, resumoDoLancamento, textoDaEtapa, textoDaConferencia, totalDeAnuncios,
} from '../../resources/js/Components/Publicador/derivados.js';

const HOOK = 'resources/js/Components/Publicador/usePublicador.js';

/** Corpo de `const nome = …` até a próxima declaração no mesmo nível (4 espaços) do hook. */
const corpo = (fonte, nome) => {
    const i = fonte.indexOf(`const ${nome} = `);
    assert.ok(i >= 0, `não achei "const ${nome} = "`);
    const j = fonte.indexOf('\n    const ', i + 1);

    return fonte.slice(i, j < 0 ? undefined : j);
};

// ═══════════════════════════════════════════════════════════════════════
// Editor do Publicador (160-12): derivados puros + gates de fonte do hook.
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
        assert.equal(textoDaConferencia(estado, 3), texto);
    }
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
    const c = estadoDaIa({ status: 'concluido', publicador: { rascunho_id: 5, secoes: ['a'], variacoes: 2 } });
    assert.equal(c.estado, 'concluido');
    assert.deepEqual(c.secoes, ['a']);
    assert.equal(c.variacoes, 2);
    const e = estadoDaIa({ status: 'erro', erro: 'falhou' });
    assert.equal(e.estado, 'erro');
    assert.equal(e.erro, 'falhou');
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

// ═══════════════════════════════════════════════════════════════════════
// Casca do editor (160-13): barra, "Anunciar por IA" e faixa de produtos.
// ═══════════════════════════════════════════════════════════════════════

const MESA = 'resources/js/Components/Publicador/Mesa';
const CASCA = [`${MESA}/BarraDoEditor.jsx`, `${MESA}/BotaoAnunciarPorIa.jsx`, `${MESA}/FaixaDeProdutos.jsx`];

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

test('BarraDoEditor — 56px sticky, sem pendências, amarelo sólido só no primário e condicionado', () => {
    const f = lerSemComentarios(`${MESA}/BarraDoEditor.jsx`);
    assert.match(f, /sticky -top-6/);
    assert.match(f, /\bh-14\b/);
    assert.doesNotMatch(f, /pendencias/);
    assert.doesNotMatch(f, /bg-ecf-yellow(?![/\w-])/);
    assert.equal((f.match(/from-\[#FFE600\]/g) ?? []).length, 1);
    assert.match(f, /primario \? PRIMARIO : SECUNDARIO/);
    assert.match(f, /primario=\{! primarioNaLateral\}/);
    assert.match(f, /aria-live="polite"/);
});

test('BarraDoEditor — D26: conta não liberada vira "Conferir dados" com Lock e aria-describedby da nota', () => {
    const f = lerSemComentarios(`${MESA}/BarraDoEditor.jsx`);
    assert.match(f, /Conferir dados/);
    assert.match(f, /Conferir no Mercado Livre/);
    assert.match(f, /aria-describedby=\{pub\.liberada \? undefined : 'nota-conta-travada'\}/);
    assert.match(f, /A publicação é liberada conta a conta\. Peça ao time de desenvolvimento\./);
    assert.match(f, /Nesta conta a conferência é só local: a validação no Mercado Livre espera a liberação da conta\./);
    assert.match(f, /pub\.liberada \? Rocket : Lock/);
    assert.match(f, /podeConferir/);
    assert.match(f, /podePublicar/);
});

test('BarraDoEditor — rótulos do publicar e estado publicado', () => {
    const f = lerSemComentarios(`${MESA}/BarraDoEditor.jsx`);
    for (const t of ['Publicar 1 anúncio', 'Publicar o que faltou', 'Publicando…', 'Publicado no Mercado Livre', 'Voltar aos produtos', 'Salvando…', 'ML conectado']) {
        assert.ok(f.includes(t), `falta o texto "${t}"`);
    }
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

test('FaixaDeProdutos — aria-current, setas, "Ver todos", "+ Produto" e selo compacto', () => {
    const f = lerSemComentarios(`${MESA}/FaixaDeProdutos.jsx`);
    assert.match(f, /aria-current=\{ativo \? 'page' : undefined\}/);
    assert.match(f, /ArrowRight/);
    assert.match(f, /Ver todos/);
    assert.match(f, /ModalNovoProduto/);
    assert.match(f, /SeloStatusProduto status=\{p\.status\} compacto/);
    assert.match(f, /@radix-ui\/react-popover/);
    assert.match(f, /w-\[360px\]/);
    assert.match(f, /mlb\.anuncios\.publicador\.editor/);
    assert.doesNotMatch(f, /bg-ecf-yellow(?![/\w-])/);
});

// ═══════════════════════════════════════════════════════════════════════
// Lateral e página do editor (160-13).
// ═══════════════════════════════════════════════════════════════════════

const PAGINA = 'resources/js/Pages/Mlb/Publicador/Editor.jsx';
const LATERAIS = [`${MESA}/LateralValidacao.jsx`, `${MESA}/LateralResumo.jsx`, PAGINA];

for (const caminho of LATERAIS) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia 24/15/13/11px, pesos 400/700, sem sombra, sem HTML cru, sem gradiente amarelo`, () => {
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl)\b/);
        assert.doesNotMatch(fonte, /text-\[(?!24px\]|15px\]|13px\]|11px\])[0-9.]+px\]/);
        assert.doesNotMatch(fonte, /font-(medium|semibold|extrabold|light|thin|black)\b/);
        assert.doesNotMatch(fonte, /\bshadow-(sm|md|lg|xl)\b/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
        assert.doesNotMatch(fonte, /portal\.auth/);
        // O gradiente amarelo do botão primário mora só na barra; a lateral usa o botão dela.
        assert.doesNotMatch(fonte, /from-\[#FFE600\]/);
    });
}

test('LateralValidacao — 8 verificações pela fonte única, nota calma e D26 sem alarme', () => {
    const f = lerSemComentarios(`${MESA}/LateralValidacao.jsx`);
    assert.match(f, /SECOES\.map/);
    assert.match(f, /Validação no Mercado Livre/);
    assert.match(f, /prontos/);
    assert.match(f, /Prontidão de envio/);
    assert.match(f, /Falta pouco/);
    assert.match(f, /Tudo pronto\. Pode conferir no Mercado Livre\./);
    assert.match(f, /Li os avisos do Mercado Livre e quero publicar assim mesmo\./);
    assert.match(f, /conferencia\.local/);
    assert.match(f, /variante="linha"/);
    assert.match(f, /pub\.conferencia\.texto/);
    assert.match(f, /Ir para /);
    assert.doesNotMatch(f, /AlertTriangle/);
    // A caixa "Li os avisos" nunca aparece na conferência local.
    assert.match(f, /! local && avisosMl\.length > 0/);
    assert.doesNotMatch(f, /text-red-|border-red-|bg-red-/);
});

test('LateralResumo — pares do resumo, apoio por estado (D26) e andamento por item', () => {
    const f = lerSemComentarios(`${MESA}/LateralResumo.jsx`);
    for (const t of ['Resumo do lançamento', 'Conta de destino', 'Modo logístico', 'Anúncios Clássico', 'Anúncios Premium', 'Total',
        'Libera quando o Mercado Livre aprovar a conferência.', 'Complete os itens da validação e confira no Mercado Livre.',
        'Publicando…', 'Publicado no Mercado Livre', 'Parte foi publicada', 'Não foi publicado']) {
        assert.ok(f.includes(t), `falta "${t}"`);
    }
    assert.equal((f.match(/esperam a liberação desta conta/g) ?? []).length, 1);
    assert.match(f, /AvisoContaTravada variante="nota"/);
    assert.match(f, /BotaoPublicar pub=\{pub\} primario=\{primario\}/);
    assert.match(f, /publicador\.descricao/);
    assert.match(f, /plano_b/);
});

test('Editor.jsx — compõe os 7 cards com m={pub.m}, sem abas nem rodapé fixo, duas colunas só em 1360px', () => {
    const f = lerSemComentarios(PAGINA);
    assert.match(f, /usePublicador\(\{ produtoId: produto\.id/);
    assert.match(f, /useIaDoPublicador\(/);
    assert.equal((f.match(/m=\{pub\.m\}/g) ?? []).length, 7);
    for (const c of ['CardProduto', 'CardFichaTecnica', 'CardVariacoes', 'CardFotos', 'CardTiposEPrecos', 'CardLogistica', 'CardDescricao']) {
        assert.match(f, new RegExp(`import ${c} from '@/Components/Publicador/Mesa/${c}'`));
    }
    assert.doesNotMatch(f, /ModoAnuncioTabs/);
    assert.doesNotMatch(f, /fixed bottom-/);
    assert.match(f, /min-\[1360px\]:grid-cols-\[minmax\(0,800px\)_320px\]/);
    assert.match(f, /min-\[1360px\]:top-\[56px\]/);
    assert.match(f, /matchMedia\(FAIXA_LARGA\)/);
    assert.match(f, /removeEventListener\('change'/);
    assert.match(f, /primarioNaLateral=\{largo\}/);
    assert.match(f, /primario=\{largo\}/);
    assert.match(f, /Não foi possível abrir o produto\./);
    assert.match(f, /A conta do Mercado Livre precisa ser reconectada antes de conferir ou publicar\./);
    assert.match(f, /A IA preencheu/);
    assert.match(f, /A IA não montou as variações\. Defina-as no card Variações\./);
    assert.match(f, /A IA não conseguiu preparar este anúncio\. Nada foi alterado\. Tente de novo ou preencha à mão\./);
    assert.match(f, /await pub\.descarregar\(\)/);
});
