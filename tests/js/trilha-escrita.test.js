import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import { MAPEAMENTO, PROPS_TRABALHO } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { acaoDoItem, comQuemEsta, etapaDaTrilha, ETAPA_POR_CHAVE, ORDEM } from '../../resources/js/lib/trilha.js';
import { resumoDoFluxo } from '../../resources/js/lib/trilhaMapa.js';
import {
    andamentoDaOnda,
    avisosDaOferta,
    diaMes,
    ehPlanejavel,
    emTexto,
    hojeEmSaoPaulo,
    ondasEmOrdem,
    problemaDaOnda,
    proximoNomeDeOnda,
    quandoDaOnda,
    quandoDaOndaPorExtenso,
    rotuloNoSeletor,
    somarDias,
    textoDaOndaCriada,
    ajudaDaDataDaOnda,
    quandoDaOfertaNaOnda,
} from '../../resources/js/lib/trilhaOndas.js';
import {
    alternarCombo,
    alternarJuntar,
    alternarParceiro,
    corpoDoMapeamento,
    escolhasIniciais,
    estadoDaLinha,
    maximoDeParceiros,
    mudarQuantidade,
    ordenarParceiros,
    podeJuntar,
    proximoParaMapear,
    textoDasCriadas,
    textoDasDescartadas,
    textoDoMapeamento,
} from '../../resources/js/lib/trilhaMapeamento.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 2ª entrega (02/11/2026): as regras PURAS das escritas.
//
// POR QUE EXISTE: a trilha passou a gravar (ondas e mapeamento). O servidor é
// quem decide; a tela só monta o pedido e escreve o resultado — e é aí que um
// erro passa calado: a data de hoje no fuso errado oferece um dia que o servidor
// recusa; o corpo do mapeamento com um parceiro que não casa vira 422; o 422 do
// Laravel (que fala inglês nesta instalação) chega cru à pessoa; a recarga que
// empilha uma entrada no histórico. Cada um tem um teste aqui, e as chamadas
// HTTP são conferidas com um axios de mentira (URL, método e corpo).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

/**
 * `lib/trilhaEscrita.js` montado com o Inertia de mentira (o esbuild o embute no
 * pacote: ele anota as recargas num global). O axios fica de fora: é o MESMO que
 * este teste importa, e é nele que as chamadas são trocadas. Montado ANTES dos
 * testes — com `await` no meio do arquivo, o executor já roda os de cima.
 */
async function montarEscrita() {
    globalThis.__recargasDaTrilha = [];
    const stub = path.join(__dirname, `.trilha-escrita-inertia-stub-${process.pid}.mjs`);
    // Com `__recargaManual`, a recarga fica "no ar" (como no Inertia: o `cancel` sai na hora, no `onCancelToken`,
    // e cancelar dispara o `onFinish`) até o teste chamar o `onFinish` dela.
    fs.writeFileSync(stub, `export const router = { reload(opcoes) {
    globalThis.__recargasDaTrilha.push(opcoes);
    if (globalThis.__recargaManual) {
        opcoes.onCancelToken?.({ cancel: () => { opcoes.cancelada = true; opcoes.onFinish?.(); } });
        return;
    }
    opcoes?.onFinish?.();
} };
`, 'utf8');
    let resultado;
    try {
        resultado = await esbuild.build({
            entryPoints: [path.resolve(RAIZ, 'resources/js/lib/trilhaEscrita.js')],
            bundle: true,
            format: 'esm',
            platform: 'node',
            write: false,
            logLevel: 'silent',
            alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
            external: ['axios'],
        });
    } finally {
        fs.rmSync(stub, { force: true });
    }
    const saida = path.join(__dirname, `.trilha-escrita-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const escrita = await montarEscrita();

// ─── As ondas ───────────────────────────────────────────────────────────────

test('datas da onda: dia/mês sem fuso, "hoje" em São Paulo (a virada das 21h UTC) e soma no calendário', () => {
    assert.equal(diaMes('2026-11-05'), '05/11');
    assert.equal(diaMes('2026-11-05T23:30:00-03:00'), '05/11', 'só a parte da data, sem converter fuso');
    assert.equal(diaMes(null), '');

    // 23:59 em São Paulo ainda é o dia 1º; 00:07 já é o dia 2 (o servidor está em UTC: 02:59 e 03:07).
    assert.equal(hojeEmSaoPaulo(new Date('2026-11-02T02:59:00Z')), '2026-11-01');
    assert.equal(hojeEmSaoPaulo(new Date('2026-11-02T03:07:00Z')), '2026-11-02');

    assert.equal(somarDias('2026-11-02', 1), '2026-11-03');
    assert.equal(somarDias('2026-12-31', 1), '2027-01-01');
    assert.equal(somarDias('2028-02-28', 1), '2028-02-29', 'ano bissexto');
    assert.equal(somarDias('2026-11-02', 365), '2027-11-02');
    assert.equal(somarDias('02/11/2026', 1), '', 'formato que não é o do campo de data');
});

test('ondas: ordem por data e, no empate, por id; "começa"/"começou" — nunca prazo', () => {
    const ondas = [
        { id: 9, nome: 'B', data: '2026-11-05', comecou: false },
        { id: 7, nome: 'A', data: '2026-11-03', comecou: true },
        { id: 12, nome: 'C', data: '2026-11-05', comecou: false },
        { id: 8, nome: 'D', data: '2026-11-10', comecou: false },
        { id: 4, nome: 'E', data: '2026-11-05', comecou: false },
    ];
    assert.deepEqual(ondasEmOrdem(ondas).map((w) => w.id), [7, 4, 9, 12, 8]);
    assert.deepEqual(ondas.map((w) => w.id), [9, 7, 12, 8, 4], 'a lista de quem chamou não muda');

    assert.equal(quandoDaOnda(ondas[0]), 'começa 05/11');
    assert.equal(quandoDaOnda(ondas[1]), 'começou 03/11');
    assert.equal(quandoDaOndaPorExtenso(ondas[0]), 'começa em 05/11');
    assert.equal(quandoDaOndaPorExtenso(ondas[1]), 'começou em 03/11');
    assert.equal(rotuloNoSeletor(ondas[0]), 'B · começa 05/11');
    assert.equal(rotuloNoSeletor(ondas[1]), 'A · começou 03/11 · começa na hora', 'pôr numa onda que começou faz o robô começar na hora');

    // O andamento do cartão: as ofertas da onda e quantas já estão na revisão ou no ar.
    assert.deepEqual(andamentoDaOnda(PROPS_TRABALHO, 7), { total: 4, prontas: 2, porcento: 50, concluida: false });
    assert.deepEqual(andamentoDaOnda(PROPS_TRABALHO, 9), { total: 1, prontas: 0, porcento: 0, concluida: false });
    assert.deepEqual(andamentoDaOnda(PROPS_TRABALHO, 99), { total: 0, prontas: 0, porcento: 0, concluida: false });
    const tudoPronto = { ofertas: [{ id: 'a', onda_id: 3, etapa: 'lanc' }, { id: 'b', onda_id: 3, etapa: 'revisao' }] };
    assert.equal(andamentoDaOnda(tudoPronto, 3).concluida, true);
});

test('onda nova: o nome sugerido é o primeiro "Onda N" livre; a validação fala como o servidor', () => {
    assert.equal(proximoNomeDeOnda([]), 'Onda 1');
    assert.equal(proximoNomeDeOnda(PROPS_TRABALHO.ondas), 'Onda 4');
    assert.equal(proximoNomeDeOnda([{ nome: 'Onda 2' }, { nome: 'onda 3' }]), 'Onda 4', 'pula os nomes que já existem');

    const hoje = '2026-11-02';
    assert.equal(problemaDaOnda({ nome: 'Onda 2', data: '2026-11-05' }, { hoje }), null);
    assert.equal(problemaDaOnda({ nome: 'Onda 2', data: hoje }, { hoje }), null, 'hoje = começar agora');
    assert.equal(problemaDaOnda({ nome: '  ', data: '2026-11-05' }, { hoje }), 'Dê um nome de 1 a 80 caracteres à onda.');
    assert.equal(problemaDaOnda({ nome: 'x'.repeat(81), data: '2026-11-05' }, { hoje }), 'Dê um nome de 1 a 80 caracteres à onda.');
    assert.equal(problemaDaOnda({ nome: 'Onda 2', data: '2026-11-01' }, { hoje }), 'Escolha hoje ou um dia depois: hoje faz o robô começar agora.');
    assert.equal(problemaDaOnda({ nome: 'Onda 2', data: '2027-11-03' }, { hoje }), 'Escolha uma data até um ano para a frente.');
    assert.equal(problemaDaOnda({ nome: 'Onda 2', data: '2027-11-02' }, { hoje }), null, 'hoje + 365 ainda vale');
    assert.equal(problemaDaOnda({ nome: 'Onda 2', data: '' }, { hoje }), 'Escolha o dia em que o robô começa.');
    // A onda que já começou fica com a data travada: só o nome é conferido.
    assert.equal(problemaDaOnda({ nome: 'Onda 1', data: '2026-11-01' }, { hoje, dataTravada: true }), null);

    // Nome repetido na loja: a régua do servidor (sem caixa, sem acento, espaços limpos) e o texto dele.
    const outrasOndas = [{ nome: 'Onda 2' }, { nome: 'Lançamento  Natal' }];
    assert.equal(problemaDaOnda({ nome: 'onda 2', data: '2026-11-05' }, { hoje, outrasOndas }), 'Já existe uma onda com esse nome nesta loja.');
    assert.equal(problemaDaOnda({ nome: ' LANCAMENTO natal ', data: '2026-11-05' }, { hoje, outrasOndas }), 'Já existe uma onda com esse nome nesta loja.');
    assert.equal(problemaDaOnda({ nome: 'Onda 3', data: '2026-11-05' }, { hoje, outrasOndas }), null);
    assert.equal(proximoNomeDeOnda([{ nome: 'ONDÁ 2' }]), 'Onda 3', 'a sugestão pula o nome que o servidor recusaria');
});

test('avisos da oferta antes da onda: peça com pendência na Lista (todos) e fotos das peças ainda não preparadas (só a equipe)', () => {
    const kit = PROPS_TRABALHO.ofertas.find((o) => o.id === 'o-31');
    // O rack já tem a simples na revisão; a simples do painel ainda não apareceu (o painel está no mapeamento).
    assert.deepEqual(avisosDaOferta(kit, PROPS_TRABALHO), [
        'As fotos das peças entram como estiverem: a simples de Painel ripado para TV 2,20 m ainda não foi preparada.',
    ]);
    assert.deepEqual(avisosDaOferta(kit, PROPS_TRABALHO, { cliente: true }), [], 'o preparo das fotos é da equipe');

    // Uma peça volta para a Lista: é aviso, não trava — no dia da onda o robô prepara com o que houver (o cliente
    // também lê, e é ele quem completa).
    const comPendencia = { ...PROPS_TRABALHO, produtos: PROPS_TRABALHO.produtos.map((p) => (p.id === 4 ? { ...p, etapa: 'lista' } : p)) };
    assert.equal(avisosDaOferta(kit, comPendencia, { cliente: true })[0],
        'O produto Rack para TV 1,80 m madeira maciça tem pendência na Lista: no dia da onda o robô prepara com o que estiver lá. Complete antes da data.');
    const duasNaLista = { ...comPendencia, produtos: comPendencia.produtos.map((p) => (p.id === 5 ? { ...p, etapa: 'lista' } : p)) };
    assert.equal(avisosDaOferta(kit, duasNaLista, { cliente: true })[0],
        'Os produtos Rack para TV 1,80 m madeira maciça e Painel ripado para TV 2,20 m têm pendência na Lista: no dia da onda o robô prepara com o que estiver lá. Complete antes da data.');
    for (const aviso of avisosDaOferta(kit, duasNaLista)) assert.equal(aviso.match(/só prepara quando|espera/), null, `pendência é aviso, não espera: ${aviso}`);

    // Combo e simples não falam das fotos das peças.
    assert.deepEqual(avisosDaOferta(PROPS_TRABALHO.ofertas.find((o) => o.id === 'c-3-2'), PROPS_TRABALHO), []);
    assert.equal(emTexto(['A', 'B', 'C']), 'A, B e C');
    assert.equal(emTexto(['A', 'A']), 'A');
});

// ─── O mapeamento ───────────────────────────────────────────────────────────

test('mapeamento: parceiros na ordem do painel (casa → sugerido → ambiente → nome), nada pré-marcado fora o combo que já existe', () => {
    assert.deepEqual(ordenarParceiros(MAPEAMENTO.parceiros).map((p) => p.produto_id), [4, 11, 1, 2]);
    assert.deepEqual(MAPEAMENTO.parceiros.map((p) => p.produto_id), [2, 1, 11, 4], 'a lista do servidor não muda');

    const inicio = escolhasIniciais(MAPEAMENTO);
    assert.deepEqual(inicio, { combos: [2], marcados: {}, quantidades: {}, juntar: false }, 'só o Combo 2 (já existe em todas as cores) vem marcado');
    assert.deepEqual(corpoDoMapeamento(inicio, MAPEAMENTO), { combos: [2], parceiros: [], juntar: false });

    // O combo travado não sai; os outros entram e saem, e o corpo vai em ordem.
    let e = alternarCombo(inicio, MAPEAMENTO, 2, false);
    assert.deepEqual(e.combos, [2]);
    e = alternarCombo(alternarCombo(e, MAPEAMENTO, 6, true), MAPEAMENTO, 3, true);
    assert.deepEqual(corpoDoMapeamento(e, MAPEAMENTO).combos, [2, 3, 6]);
    e = alternarCombo(e, MAPEAMENTO, 6, false);
    assert.deepEqual(corpoDoMapeamento(e, MAPEAMENTO).combos, [2, 3]);
});

test('mapeamento: mudar a quantidade marca o parceiro; "Juntar todos" só com 2 ou mais; o que não casa nunca vai no corpo', () => {
    let e = escolhasIniciais(MAPEAMENTO);
    e = mudarQuantidade(e, MAPEAMENTO, 11, 2);
    assert.deepEqual(corpoDoMapeamento(e, MAPEAMENTO).parceiros, [{ produto_id: 11, quantidade: 2 }], 'a quantidade já marca a linha');

    assert.equal(alternarJuntar(e, MAPEAMENTO, true).juntar, false, 'com um só marcado, "Juntar" não liga');
    e = alternarParceiro(e, MAPEAMENTO, 4, true);
    e = alternarJuntar(e, MAPEAMENTO, true);
    // Na ordem do painel (o rack sugerido antes da prateleira), com a quantidade de cada um.
    assert.deepEqual(corpoDoMapeamento(e, MAPEAMENTO), { combos: [2], parceiros: [{ produto_id: 4, quantidade: 1 }, { produto_id: 11, quantidade: 2 }], juntar: true });

    // Desmarcar até sobrar um desliga o "Juntar".
    e = alternarParceiro(e, MAPEAMENTO, 4, false);
    assert.equal(e.juntar, false);
    assert.equal(corpoDoMapeamento({ ...e, juntar: true }, MAPEAMENTO).juntar, false, 'nem forçando no estado');

    // A cadeira não casa: marcada à força, fica fora do corpo (o servidor daria 422).
    const forcado = { ...e, marcados: { ...e.marcados, 2: true } };
    assert.deepEqual(corpoDoMapeamento(forcado, MAPEAMENTO).parceiros.map((p) => p.produto_id), [11]);
    // Quantidade fora de 1 a 6 vira 1.
    assert.equal(mudarQuantidade(escolhasIniciais(MAPEAMENTO), MAPEAMENTO, 1, 9).quantidades[1], 1);
});

test('mapeamento: o limite de parceiros é o de produtos numa oferta menos um (o controller recusa mais de 5)', () => {
    assert.equal(maximoDeParceiros(MAPEAMENTO), 5);
    assert.equal(maximoDeParceiros({ max_componentes: 3 }), 2);
    assert.equal(maximoDeParceiros({}), 5, 'sem o número do servidor, o do sistema (6 componentes)');

    const muitos = { ...MAPEAMENTO, parceiros: Array.from({ length: 7 }, (_, i) => ({ produto_id: 100 + i, nome: `P${i}`, casa: true, sugerido: null, ambiente: null })) };
    let e = escolhasIniciais(muitos);
    for (let i = 0; i < 7; i++) e = alternarParceiro(e, muitos, 100 + i, true);
    assert.equal(Object.keys(e.marcados).length, 5, 'o sexto e o sétimo não entram');
    assert.equal(mudarQuantidade(e, muitos, 106, 3).marcados[106], undefined, 'mudar a quantidade de um fora do limite não o marca');
    assert.equal(corpoDoMapeamento(e, muitos).parceiros.length, 5);
    assert.equal(podeJuntar(5, muitos), true);
    assert.equal(podeJuntar(6, muitos), false);
    assert.equal(podeJuntar(1, muitos), false);
});

test('mapeamento: os rótulos de "Vai ser criado", do concluído e da marca de mapeado', () => {
    assert.deepEqual(['sempre', 'nova', 'nova_parcial', 'ja_existe', 'nao_da'].map((estado) => estadoDaLinha({ estado })), [
        { rotulo: 'sempre', tom: 'neutro' },
        { rotulo: 'nova', tom: 'ok' },
        { rotulo: 'nova em parte', tom: 'ok' },
        { rotulo: 'já existe', tom: 'neutro' },
        { rotulo: 'não dá', tom: 'bad' },
    ]);
    // O mesmo texto do servidor (`MapeamentoNaTrilha::concluir`): não promete onde as ofertas ficaram.
    assert.equal(textoDasCriadas(0), 'Mapeamento concluído: nenhuma oferta nova.');
    assert.equal(textoDasCriadas(1), '1 oferta criada.');
    assert.equal(textoDasCriadas(3), '3 ofertas criadas.');
    assert.equal(textoDasDescartadas(0), null);
    assert.equal(textoDasDescartadas(2), '2 sugestões do sistema que não foram escolhidas foram descartadas.');
    // Nunca "planejado".
    for (const t of [textoDasCriadas(0), textoDasCriadas(1), textoDasCriadas(4)]) assert.equal(t.match(/planejad/i), null, t);

    assert.equal(proximoParaMapear(PROPS_TRABALHO, 99)?.id, 5);
    assert.equal(proximoParaMapear(PROPS_TRABALHO, 5), null, 'o painel ripado era o último');

    // A data no fuso de São Paulo (o ISO do servidor vem com o deslocamento); o cliente não recebe o nome.
    assert.equal(textoDoMapeamento('2026-11-01T23:30:00-03:00', 'Rafael'), 'Mapeado em 01/11 por Rafael');
    assert.equal(textoDoMapeamento('2026-11-02T02:30:00Z', null), 'Mapeado em 01/11');
    assert.equal(textoDoMapeamento(null, 'Rafael'), null);
});

// ─── Textos da trilha de trabalho ───────────────────────────────────────────

test('textos das etapas com a trilha de trabalho: siglas do sistema e a data que é o começo do robô — nada de prazo', () => {
    for (const chave of ORDEM) assert.equal(etapaDaTrilha(chave, false), ETAPA_POR_CHAVE[chave], `${chave}: sem trabalho, os de hoje`);

    assert.equal(etapaDaTrilha('plano', true).pronto, 'Cada oferta numa onda, com o dia em que o robô começa.');
    assert.equal(etapaDaTrilha('plano', true).faz, 'As ofertas entram em ondas com data: no dia da onda o robô começa a preparar. Normalmente as simples vêm primeiro, mas a ordem depende da lista do cliente.');
    assert.equal(etapaDaTrilha('robo', true).faz, 'Na data da onda, o robô monta o anúncio com a lista do cliente: título, descrição e imagens a partir das fotos.');
    assert.match(etapaDaTrilha('mapa', true).faz, /SKU-CB2.*KT-SKU1-SKU2.*CT4-SKU1-SKU2.*Não precisa esperar a simples vender\.$/);
    assert.equal(etapaDaTrilha('mapa', true).pronto, ETAPA_POR_CHAVE.mapa.pronto, 'o "Pronto quando" do mapeamento fica');

    const todos = ORDEM.map((k) => etapaDaTrilha(k, true)).flatMap((e) => [e.pronto, e.faz]).join(' ');
    for (const proibido of [/prazo/i, /publicar até/i, /data de publicação/i, /próxima entrega/i, /planejad/i, /por enquanto/i, /-CBT/, /\+SKU|SKU\d\+/, /-KIT\b/]) {
        assert.equal(todos.match(proibido), null, `texto proibido com a trilha de trabalho: ${proibido}`);
    }

    // Sem a chave também: a data da onda nunca é prazo, e "próxima entrega" é bastidor — o cliente lê estes textos.
    assert.equal(etapaDaTrilha('plano', false).pronto, 'Cada oferta numa onda, com o dia em que o robô começa.');
    const deHoje = ORDEM.map((k) => etapaDaTrilha(k, false)).flatMap((e) => [e.pronto, e.faz]).join(' ');
    for (const proibido of [/prazo/i, /publicar até/i, /data de publicação/i, /próxima entrega/i]) {
        assert.equal(deHoje.match(proibido), null, `texto proibido sem a trilha de trabalho: ${proibido}`);
    }
});

// ─── A oferta sem produto, o que ainda se planeja e o robô parado ──────────

test('oferta sem produto na Lista: "Abrir" (não "Planejar") e fora da conta do Estrategista e do "sem onda"', () => {
    assert.equal(acaoDoItem('plano', 'equipe', true, { semProduto: true }), 'Abrir');
    assert.equal(acaoDoItem('plano', 'equipe', true), 'Planejar');
    assert.equal(acaoDoItem('plano', 'cliente', true, { semProduto: true }), 'Acompanhar');

    // A Casa Jatobá com a trilha de trabalho: 1 produto por mapear + 2 ofertas por planejar (a avulsa sem produto fica fora).
    assert.equal(comQuemEsta(PROPS_TRABALHO, 'equipe').find((d) => d.dono === 'Estrategista').quantidade, 3);
    const plano = resumoDoFluxo(PROPS_TRABALHO).find((p) => p.chave === 'plano');
    assert.equal(`${plano.numero} ${plano.unidade} · ${plano.detalhe}`, '6 em ondas · 2 sem onda');
    // Sem ondas, só a sem produto no Planejamento: nada "esperando onda".
    const soAvulsa = { produtos: [], ofertas: PROPS_TRABALHO.ofertas.filter((o) => o.id === 'o-41'), ondas: [] };
    assert.equal(resumoDoFluxo(soAvulsa).find((p) => p.chave === 'plano').detalhe, '');
});

test('robô: "parada" no resumo é só o agente que parou ou o que não começou — a pendência na Lista é aviso', () => {
    const oferta = (id, status, alerta) => ({ id, tipo: 'Simples', etapa: 'robo', status, alerta, componentes: [], anuncios: [] });
    const dados = {
        produtos: [],
        ondas: [],
        ofertas: [
            oferta('s-1', 'O agente parou: título', true),
            oferta('s-2', 'Ainda não começou (desde 03/11)', true),
            oferta('s-3', 'Na fila · com pendência na Lista', true),
            oferta('s-4', 'Na fila · começou em 03/11', false),
            oferta('s-5', 'O agente está escrevendo título e descrição', false),
        ],
    };
    const robo = resumoDoFluxo(dados).find((p) => p.chave === 'robo');
    assert.equal(`${robo.numero} ${robo.unidade} · ${robo.detalhe}`, '5 preparando · 2 paradas');
});

test('o que ainda entra em onda é o `planejavel` do servidor; a dica da onda recém-criada pede para pôr a oferta nela', () => {
    assert.equal(ehPlanejavel({ planejavel: false, etapa: 'plano' }), false, 'o servidor manda');
    assert.equal(ehPlanejavel({ planejavel: true, etapa: 'robo' }, { comecou: true }), true);
    // Sem o campo, a régua de antes: com produto, no Planejamento ou numa onda que não começou.
    assert.equal(ehPlanejavel({ etapa: 'plano', sem_produto: false }), true);
    assert.equal(ehPlanejavel({ etapa: 'plano', sem_produto: true }), false);
    assert.equal(ehPlanejavel({ etapa: 'robo' }, { comecou: false }), true);
    assert.equal(ehPlanejavel({ etapa: 'robo' }, { comecou: true }), false);
    assert.equal(ehPlanejavel({ etapa: 'revisao' }), false);

    // A ajuda da data: a onda NOVA nasce vazia — com a data de hoje, começa na hora o que entrar nela.
    const hoje = '2026-11-02';
    assert.equal(ajudaDaDataDaOnda({ nova: true, data: hoje, hoje }), 'Hoje: o que entrar nesta onda começa na hora.');
    assert.equal(ajudaDaDataDaOnda({ nova: false, data: hoje, hoje }), 'Hoje: o robô começa assim que a onda for salva.');
    assert.equal(ajudaDaDataDaOnda({ nova: true, data: '2026-11-05', hoje }), 'O dia em que o robô começa a preparar as ofertas desta onda.');
    assert.equal(ajudaDaDataDaOnda({ comecou: true, data: '2026-11-01', hoje }), 'Já começou: a data não muda mais.');

    // A linha da oferta numa onda por começar: "começa 05/11" só para quem espera a data.
    const agendada = { id: 9, nome: 'Onda 2', data: '2026-11-05', comecou: false };
    assert.equal(quandoDaOfertaNaOnda({ planejavel: true }, agendada), 'começa 05/11');
    assert.equal(quandoDaOfertaNaOnda({}, agendada), 'começa 05/11', 'sem o campo, como antes');
    assert.equal(quandoDaOfertaNaOnda({ planejavel: false }, agendada), 'já começou');
    assert.equal(quandoDaOfertaNaOnda({ planejavel: false, sem_produto: true }, agendada), null);
    assert.equal(quandoDaOfertaNaOnda({ planejavel: false }, { ...agendada, comecou: true }), 'começou 05/11');

    assert.equal(textoDaOndaCriada({ id: 9, nome: 'Onda 4', data: '2026-11-12', comecou: false }), 'Onda 4 criada (começa em 12/11). Agora coloque esta oferta nela.');
    assert.equal(textoDaOndaCriada({ id: 9, nome: 'Onda 4', data: '2026-11-02', comecou: true }), 'Onda 4 criada (começa hoje). Agora coloque esta oferta nela.');
});

// ─── As chamadas ao servidor ───────────────────────────────────────────────

// O módulo das escritas é montado no começo do arquivo (`escrita`, lá em cima), antes de qualquer teste.

/** As recargas que o Inertia de mentira recebeu (ele as anota aqui e termina cada uma na hora: `onFinish`). */
const recargas = globalThis.__recargasDaTrilha;

/** Troca os métodos do axios por um que só anota o pedido e devolve `resposta`. */
function gravarPedidos(resposta = { mensagem: 'ok' }) {
    const pedidos = [];
    const originais = { get: axios.get, post: axios.post, put: axios.put, delete: axios.delete };
    for (const metodo of Object.keys(originais)) {
        axios[metodo] = async (url, corpo) => {
            pedidos.push({ metodo, url, corpo });

            return { data: resposta };
        };
    }

    return { pedidos, restaurar: () => Object.assign(axios, originais) };
}

test('as escritas vão aos endereços de `escrita`, com o método e o corpo do contrato', async () => {
    const { pedidos, restaurar } = gravarPedidos({ mensagem: 'Na Onda 2. O robô começa em 05/11.' });
    const E = { ...PROPS_TRABALHO.escrita, produtos: `${PROPS_TRABALHO.escrita.produtos}/` };
    try {
        assert.deepEqual(await escrita.criarOnda(E, { nome: 'Onda 4', data: '2026-11-12' }), { mensagem: 'Na Onda 2. O robô começa em 05/11.' });
        await escrita.editarOnda(E, 9, { data: '2026-11-06' });
        await escrita.apagarOnda(E, 9);
        await escrita.planejarItens(E, ['c-2-4'], 9);
        await escrita.planejarItens(E, ['c-2-4']);
        await escrita.carregarMapeamento(E, 5);
        await escrita.previaDoMapeamento(E, 5, { combos: [3], parceiros: [{ produto_id: 4, quantidade: 1 }], juntar: false });
        await escrita.concluirMapeamento(E, 5, { combos: [], parceiros: [], juntar: false });
    } finally {
        restaurar();
    }

    assert.deepEqual(pedidos, [
        { metodo: 'post', url: 'http://localhost/trilha/459/ondas', corpo: { nome: 'Onda 4', data: '2026-11-12' } },
        { metodo: 'put', url: 'http://localhost/trilha/459/ondas/9', corpo: { data: '2026-11-06' } },
        { metodo: 'delete', url: 'http://localhost/trilha/459/ondas/9', corpo: undefined },
        { metodo: 'put', url: 'http://localhost/trilha/459/planejamento', corpo: { itens: ['c-2-4'], onda_id: 9 } },
        { metodo: 'put', url: 'http://localhost/trilha/459/planejamento', corpo: { itens: ['c-2-4'], onda_id: null } },
        // A barra no fim do endereço não duplica.
        { metodo: 'get', url: 'http://localhost/trilha/459/produtos/5/mapeamento', corpo: undefined },
        {
            metodo: 'post',
            url: 'http://localhost/trilha/459/produtos/5/mapeamento/previa',
            corpo: { combos: [3], parceiros: [{ produto_id: 4, quantidade: 1 }], juntar: false },
        },
        { metodo: 'post', url: 'http://localhost/trilha/459/produtos/5/mapeamento', corpo: { combos: [], parceiros: [], juntar: false } },
    ]);

    // Sem endereço (a porta do cliente nunca recebe nenhum), nada sai — e o erro tem texto para a pessoa.
    await assert.rejects(() => escrita.criarOnda(null, { nome: 'x', data: '2026-11-12' }), (e) => e instanceof escrita.ErroDaTrilha);
    assert.equal(escrita.mensagemDoErro(new escrita.ErroDaTrilha('Esta tela não pode gravar: recarregue a página.')), 'Esta tela não pode gravar: recarregue a página.');
});

test('cadastro (3ª entrega): abrir, criar e gravar as linhas sob `escrita.produtos`; a `versao` só vai quando há uma', async () => {
    const { pedidos, restaurar } = gravarPedidos({ produto: { id: 9 } });
    const E = { ...PROPS_TRABALHO.escrita, produtos: `${PROPS_TRABALHO.escrita.produtos}/` };
    try {
        assert.deepEqual(await escrita.abrirCadastro(E, 9), { produto: { id: 9 } });
        await escrita.criarProduto(E, { nome: 'Poltrona Ripada Bege', codigo: 'POLT-RIP-BG' });
        await escrita.gravarLinhas(E, 9, [{ chave: 'v91', id: 91, codigo: 'CRIADO-01', nome: 'Criado-mudo', custo: '10' }]);
        await escrita.gravarLinhas(E, 9, [{ chave: 'v91', id: 91, codigo: 'CRIADO-01', nome: 'Criado-mudo' }], { versao: 'abc' });
    } finally {
        restaurar();
    }

    assert.deepEqual(pedidos, [
        { metodo: 'get', url: 'http://localhost/trilha/459/produtos/9/cadastro', corpo: undefined },
        // Criar leva SÓ o nome e o SKU.
        { metodo: 'post', url: 'http://localhost/trilha/459/produtos', corpo: { nome: 'Poltrona Ripada Bege', codigo: 'POLT-RIP-BG' } },
        { metodo: 'post', url: 'http://localhost/trilha/459/produtos/9/linhas', corpo: { linhas: [{ chave: 'v91', id: 91, codigo: 'CRIADO-01', nome: 'Criado-mudo', custo: '10' }] } },
        { metodo: 'post', url: 'http://localhost/trilha/459/produtos/9/linhas', corpo: { linhas: [{ chave: 'v91', id: 91, codigo: 'CRIADO-01', nome: 'Criado-mudo' }], versao: 'abc' } },
    ]);
    assert.equal(escrita.urlDoProduto(E, 12), 'http://localhost/trilha/459/produtos/12');

    // Sem endereço (a porta do cliente nunca recebe nenhum), nada sai.
    await assert.rejects(() => escrita.abrirCadastro(null, 9), (e) => e instanceof escrita.ErroDaTrilha);
    await assert.rejects(() => escrita.criarProduto({ ondas: 'x' }, { nome: 'a', codigo: 'b' }), (e) => e instanceof escrita.ErroDaTrilha);
});

test('cadastro F2: a categoria sob `escrita.categorias` (busca, árvore, campos, termos) e a ficha sob `escrita.produtos`', async () => {
    const { pedidos, restaurar } = gravarPedidos({ categorias: [], indisponivel: true });
    // O servidor manda `categorias` relativo (como as fotos); a barra no fim não duplica.
    const E = { ...PROPS_TRABALHO.escrita, categorias: `${PROPS_TRABALHO.escrita.categorias}/` };
    const atributos = [{ id: 'BRAND', valor: 'ECF Móveis' }, { id: 'WIDTH', valor: '45', unidade: 'cm' }];
    try {
        assert.deepEqual(await escrita.buscarCategorias(E, 'Criado-mudo 2 gavetas'), { categorias: [], indisponivel: true });
        await escrita.nivelDaArvore(E);
        await escrita.nivelDaArvore(E, 'MLB1574');
        await escrita.camposDaCategoria(E, 'MLB1902');
        await escrita.termosDoTitulo(E, 'MLB1902', 'Criado-mudo & cia');
        await escrita.termosDoTitulo(E, 'MLB1902');
        await escrita.gravarFicha(E, 9, atributos, { categoria: 'MLB1902' });
        await escrita.gravarFicha(E, 9, [], { versao: 'abc' });
        await escrita.fichaPelaIa(E, 9, { categoria: 'MLB1902', titulo: 'Criado-Mudo Jatobá', descricao: 'Jatobá maciço.', atributos });
    } finally {
        restaurar();
    }

    assert.deepEqual(pedidos, [
        // O texto vai na query, codificado.
        { metodo: 'get', url: '/trilha/459/categorias?q=Criado-mudo%202%20gavetas', corpo: undefined },
        // Sem `pai`, o 1º nível.
        { metodo: 'get', url: '/trilha/459/categorias/arvore', corpo: undefined },
        { metodo: 'get', url: '/trilha/459/categorias/arvore?pai=MLB1574', corpo: undefined },
        { metodo: 'get', url: '/trilha/459/categorias/MLB1902/campos', corpo: undefined },
        { metodo: 'get', url: '/trilha/459/categorias/MLB1902/titulo?nome=Criado-mudo%20%26%20cia', corpo: undefined },
        { metodo: 'get', url: '/trilha/459/categorias/MLB1902/titulo', corpo: undefined },
        // A ficha INTEIRA, com a categoria que a gaveta tinha aberta (o servidor confere com a gravada).
        { metodo: 'put', url: 'http://localhost/trilha/459/produtos/9/ficha-tecnica', corpo: { atributos, categoria: 'MLB1902' } },
        // A ficha vazia também vai (é o "limpar"); a `versao` só quando há uma.
        { metodo: 'put', url: 'http://localhost/trilha/459/produtos/9/ficha-tecnica', corpo: { atributos: [], versao: 'abc' } },
        // A IA lê o que está na tela (nada gravado) — e não grava.
        {
            metodo: 'post',
            url: 'http://localhost/trilha/459/produtos/9/ficha-tecnica/ia',
            corpo: { categoria: 'MLB1902', titulo: 'Criado-Mudo Jatobá', descricao: 'Jatobá maciço.', atributos },
        },
    ]);

    // Sem o endereço das categorias (a porta do cliente nunca recebe nenhum), nada sai — e o erro tem texto para a pessoa.
    await assert.rejects(() => escrita.buscarCategorias({ produtos: 'x' }, 'mesa'), (e) => e instanceof escrita.ErroDaTrilha);
    await assert.rejects(() => escrita.camposDaCategoria(null, 'MLB1'), (e) => e instanceof escrita.ErroDaTrilha);
    await assert.rejects(() => escrita.gravarFicha({ categorias: 'x' }, 9, []), (e) => e instanceof escrita.ErroDaTrilha);
    // O 422 da IA (sem categoria salva) chega com o texto do servidor.
    assert.equal(
        escrita.mensagemDoErro({ isAxiosError: true, response: { status: 422, data: { message: 'Escolha a categoria e salve o produto antes de usar a IA.' } } }),
        'Escolha a categoria e salve o produto antes de usar a IA.',
    );
});

test('cadastro F3: enviar fotos (multipart, um `imagens[]` por arquivo), excluir foto e conferir o frete — sob `escrita.produtos`', async () => {
    const { pedidos, restaurar } = gravarPedidos({ produto: { id: 9 }, variacoes: [], mensagem: '2 fotos enviadas.' });
    const E = { ...PROPS_TRABALHO.escrita, produtos: `${PROPS_TRABALHO.escrita.produtos}/` };
    const frente = new File(['a'], 'frente.jpg', { type: 'image/jpeg' });
    const lado = new File(['b'], 'lado.png', { type: 'image/png' });
    try {
        assert.deepEqual(await escrita.enviarFotos(E, 9, 91, [frente, lado]), { produto: { id: 9 }, variacoes: [], mensagem: '2 fotos enviadas.' });
        await escrita.excluirFoto(E, 9, 91, 501);
        await escrita.conferirFrete(E, 9);
    } finally {
        restaurar();
    }

    assert.deepEqual(pedidos.map(({ metodo, url }) => [metodo, url]), [
        ['post', 'http://localhost/trilha/459/produtos/9/variacoes/91/fotos'],
        ['delete', 'http://localhost/trilha/459/produtos/9/variacoes/91/fotos/501'],
        ['post', 'http://localhost/trilha/459/produtos/9/frete'],
    ]);
    // O envio é multipart: os arquivos na ordem escolhida, todos no mesmo campo `imagens[]` (o servidor é tudo ou nada).
    const envio = pedidos[0].corpo;
    assert.ok(envio instanceof FormData, 'multipart');
    assert.deepEqual(envio.getAll('imagens[]').map((f) => f.name), ['frente.jpg', 'lado.png']);
    assert.deepEqual([...envio.keys()], ['imagens[]', 'imagens[]']);
    assert.equal(pedidos[1].corpo, undefined);
    assert.deepEqual(pedidos[2].corpo, {});
    assert.equal(escrita.urlDaVariacao(E, 9, 91), 'http://localhost/trilha/459/produtos/9/variacoes/91');

    // Sem endereço (a porta do cliente nunca recebe nenhum), nada sai.
    await assert.rejects(() => escrita.enviarFotos(null, 9, 91, [frente]), (e) => e instanceof escrita.ErroDaTrilha);
    await assert.rejects(() => escrita.excluirFoto({ ondas: 'x' }, 9, 91, 501), (e) => e instanceof escrita.ErroDaTrilha);
    await assert.rejects(() => escrita.conferirFrete(null, 9), (e) => e instanceof escrita.ErroDaTrilha);

    // A trava vale também para as fotos: o 409 traz o motivo do servidor.
    const motivo = 'A oferta “Cadeira” já saiu do Planejamento (o robô já preparou, está na revisão ou no ar).';
    assert.equal(escrita.mensagemDoErro({ isAxiosError: true, response: { status: 409, data: { travado: true, motivo } } }), motivo);
});

test('cadastro: o 409 do produto que andou traz o motivo do servidor; o 422 da criação marca o campo', () => {
    const erro = (status, data) => ({ isAxiosError: true, response: { status, data } });
    const m = escrita.mensagemDoErro;
    const motivo = 'A oferta “Cadeira” já saiu do Planejamento (o robô já preparou, está na revisão ou no ar).';

    assert.equal(m(erro(409, { travado: true, motivo, message: motivo })), motivo);
    assert.equal(m(erro(409, { message: 'Este produto mudou depois que você abriu.' })), 'Este produto mudou depois que você abriu.');
    assert.equal(m(erro(409, {})), 'Este produto não pode mais ser mudado daqui. Recarregue a página.');
    assert.equal(m(erro(409, { message: 'Conflict' })), 'Conflict', 'texto curto do servidor ainda vale');

    // SKU repetido: o 422 cai no próprio campo; a mensagem do framework (inglês) não.
    assert.deepEqual(escrita.errosDosCampos(erro(422, { errors: { codigo: ['Este SKU já existe nesta empresa.'], nome: ['The nome field is required.'] } })),
        { codigo: 'Este SKU já existe nesta empresa.' });
    assert.deepEqual(escrita.errosDosCampos(erro(409, { travado: true })), {});
    assert.deepEqual(escrita.errosDosCampos(new Error('rede')), {});
});

test('o erro para a pessoa: a primeira mensagem do 422; texto nosso quando o servidor fala inglês ou não fala', () => {
    const erro = (status, data) => ({ isAxiosError: true, response: { status, data } });
    const m = escrita.mensagemDoErro;

    assert.equal(m(erro(422, {
        message: 'A Onda 2 já começou: a data não muda mais. (and 1 more error)',
        errors: { data: ['A Onda 2 já começou: a data não muda mais.'], nome: ['Dê um nome de 1 a 80 caracteres à onda.'] },
    })), 'A Onda 2 já começou: a data não muda mais.');
    // A regra sem texto próprio chega em inglês (APP_LOCALE=en): não vai para a tela.
    assert.equal(m(erro(422, { message: 'The parceiros field must not have more than 5 items.', errors: { parceiros: ['The parceiros field must not have more than 5 items.'] } })),
        'Confira o que foi preenchido e tente de novo.');
    assert.equal(m(erro(422, { message: 'Escolha produtos da sua lista.' })), 'Escolha produtos da sua lista.');
    assert.equal(m(erro(403, { message: 'Você não tem acesso ao portal desta empresa.' })), 'Você não tem acesso ao portal desta empresa.');
    assert.equal(m(erro(403, { message: 'This action is unauthorized.' })), 'Você não tem permissão para mudar esta trilha.');
    assert.equal(m(erro(404, { message: 'No query results for model [App\\Models\\TrilhaOnda] 7' })), 'Não achei o que você pediu: talvez tenha sido apagado. Recarregue a página.');
    assert.equal(m(erro(419, { message: 'CSRF token mismatch.' })), 'A sessão expirou. Recarregue a página e tente de novo.');
    assert.equal(m(erro(429, { message: 'Too Many Attempts.' })), 'Muitos pedidos seguidos. Espere um minuto e tente de novo.');
    assert.equal(m(erro(500, { message: 'Server Error' })), 'O servidor falhou. Tente de novo em instantes.');
    assert.equal(m({ isAxiosError: true, request: {}, message: 'Network Error' }), 'Não consegui falar com o servidor. Confira a conexão e tente de novo.');
    assert.equal(m(new TypeError("Cannot read properties of undefined (reading 'x')")), 'Não deu certo. Tente de novo.', 'erro de código não vai cru para a pessoa');
});

test('recarregar: só os dados da trilha, sem rolar, SUBSTITUINDO a entrada do histórico — e termina quando a resposta chega', async () => {
    recargas.length = 0;
    await escrita.recarregar();
    assert.equal(recargas.length, 1);
    const { onFinish, onCancelToken, ...opcoes } = recargas[0];
    assert.equal(typeof onFinish, 'function');
    assert.equal(typeof onCancelToken, 'function');
    assert.deepEqual(opcoes, { only: ['produtos', 'ofertas', 'ondas', 'gerado_em'], preserveScroll: true, replace: true });
});

test('recarregar: duas recargas no ar — a anterior é cancelada e quem esperava por ela espera pela última', async () => {
    // O Inertia aplica as respostas na ordem em que CHEGAM: a mais velha, chegando depois, apagaria a mais nova.
    globalThis.__recargaManual = true;
    recargas.length = 0;
    try {
        const terminou = [];
        const primeira = escrita.recarregar().then(() => terminou.push('primeira'));
        const segunda = escrita.recarregar().then(() => terminou.push('segunda'));
        assert.equal(recargas.length, 2);
        assert.equal(recargas[0].cancelada, true, 'a recarga anterior é cancelada antes de a nova sair');
        assert.equal(recargas[1].cancelada, undefined);

        await new Promise((r) => setTimeout(r, 0));
        assert.deepEqual(terminou, [], 'o cancelamento não libera quem esperava: a última ainda está no ar');

        recargas[1].onFinish();
        await Promise.all([primeira, segunda]);
        assert.deepEqual(terminou.sort(), ['primeira', 'segunda'], 'as duas terminam com a última resposta');

        // Uma terceira, depois de tudo, não cancela nada.
        escrita.recarregar();
        assert.equal(recargas[1].cancelada, undefined);
        recargas[2].onFinish();
    } finally {
        globalThis.__recargaManual = false;
    }
});

test('as libs da 2ª entrega não têm classe do Tailwind (em .js ela não é gerada)', () => {
    for (const arquivo of ['resources/js/lib/trilhaOndas.js', 'resources/js/lib/trilhaMapeamento.js', 'resources/js/lib/trilhaEscrita.js', 'resources/js/lib/trilhaCadastro.js']) {
        const fonte = lerSemComentarios(arquivo);
        assert.equal(fonte.match(/className|\b(?:bg|text|border|fill|stroke)-trilha-/), null, arquivo);
    }
});
