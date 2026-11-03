import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import {
    CADASTRO, CADASTRO_CATEGORIA_TRAVADA, CADASTRO_COM_CATEGORIA, CADASTRO_COM_FOTOS, CADASTRO_CORES_E_TAMANHOS, CADASTRO_FOTOS_COMPLETAS,
    CADASTRO_ME1, CADASTRO_TRAVADO, CAMPOS_DA_CATEGORIA, CANDIDATAS, PROPS_TRABALHO, SALVO_COMPLETO, SALVO_COM_ERRO, SALVO_INCOMPLETO,
    paraCliente,
} from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import {
    AVISO_DO_SKU_ABSORVIDO,
    CHAVE_NOVO_PRODUTO,
    LISTA_ESTAVEL_MS,
    acaoDoEnter,
    ambientesEfetivos,
    produtoNovoMexido,
    MENSAGEM_DOS_CAMPOS,
    aplicarResposta,
    aplicarSugestoesNaFicha,
    avisoDaOndaComecada,
    caminhoParaTela,
    camposMexidos,
    candidataUsavel,
    categoriaDaGaveta,
    categoriaTravada,
    cliqueValeNaLista,
    custoParaTexto,
    depoisDaFichaRecusada,
    depoisDeSalvar,
    deveFecharNoEsc,
    embalagemMexida,
    erroDaCategoriaNo422,
    errosPorCampo,
    estadoDoRetrato,
    contadorDeFotos,
    fichaMexida,
    fichaParaSalvar,
    fotosPorCor,
    gravarCadastro,
    gruposDaFicha,
    linhasParaSalvar,
    logisticaDasVariacoes,
    mexido,
    nadaMudou,
    nomeNovo,
    opcoesQueCasam,
    podePreencherComIa,
    porQueNaoUsar,
    remessaDeFotos,
    resultadoDoSalvar,
    sugestaoDaCategoria,
    temRetrato,
    textoDaBusca,
    textoDaIa,
    textoDoEnvio,
    urlOriginalDaFoto,
    vaiPorMe1,
    // F4
    AVISO_DA_COR_NOVA_NO_MAPEADO,
    AVISO_DA_VARIACAO_NOVA_NO_MAPEADO,
    avisoDaCorNova,
    cartoesDasVariacoes,
    chaveDaVariacao,
    comCustoECaixasIguais,
    conferirCores,
    conferirGravacaoDasCores,
    custoECaixasIguais,
    custoIgualEmCadaCor,
    errosDasLinhasNo422,
    estadoDaEstrutura,
    estruturaMexida,
    exclusaoMudou,
    itensParaConferir,
    linhasDaEstrutura,
    modoDasVariacoes,
    montadasDaPrevia,
    novasDaCor,
    novasDoTamanho,
    refsSugeridas,
    textosDaExclusao,
    trocaDeEixo,
    valorComparavel,
    // F6
    MENSAGEM_DA_VERSAO,
    conflitoDeVersao,
    textoDoConflito,
    travadoNo409,
    versaoDepoisDe,
} from '../../resources/js/lib/trilhaCadastro.js';
import { avisosEmTexto } from '../../resources/js/Components/Trilha/formatos.js';
import { acaoDoItem, comQuemEsta, etapaDaTrilha } from '../../resources/js/lib/trilha.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 3ª entrega (02/11/2026): o cadastro do produto DENTRO
// da trilha, fatia F1 — as regras PURAS da gaveta (`lib/trilhaCadastro.js`) e
// a chave `e:` no estado da tela.
//
// POR QUE EXISTE: o "Salvar" manda as linhas pela régua do Portal, onde `''` é
// "não mexi" e `null` é "limpar" — uma troca entre os dois APAGA custo, família
// ou título no servidor. O cadastro é aos poucos: o que a pessoa digitou e
// ainda não salvou não pode sumir numa resposta, e o "mexido" é o que liga o
// "Salvar" e a guarda de saída. E o `e:` é painel de ESCRITA: quem não escreve
// cai no painel de leitura, e o `e:novo` não abre nada para ele.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

/** `Components/Trilha/estadoDaTela.js` montado (ele importa pelo apelido `@`). */
async function montarEstado() {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, 'resources/js/Components/Trilha/estadoDaTela.js')],
        bundle: true,
        format: 'esm',
        platform: 'node',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
    });
    const saida = path.join(__dirname, `.trilha-cadastro-estado-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const E = await montarEstado();

/** O estado da gaveta com mudanças por cima do retrato. */
function comMudancas(retrato, { produto = {}, variacoes = {} } = {}) {
    const e = estadoDoRetrato(retrato);

    return {
        produto: { ...e.produto, ...produto },
        variacoes: e.variacoes.map((v) => ({ ...v, ...(variacoes[v.id] ?? {}) })),
    };
}

// ─── Do retrato para os campos ──────────────────────────────────────────────

test('estadoDoRetrato: os campos em texto, como se digita (custo com vírgula, medidas sem zero à toa, nulo vira vazio)', () => {
    assert.deepEqual(estadoDoRetrato(CADASTRO), {
        produto: { nome: 'Criado-mudo 2 gavetas', categoria: null, titulo: '', familia: '', ambientes: [], ambiente_digitado: '', descricao: '', eixo: '', eixo_2: '' },
        variacoes: [{ id: 91, codigo: 'CRIADO-01', custo: '', estoque: '', volumes: [], valor: '', valor_2: '' }],
        ficha: {},
    });
    assert.deepEqual(estadoDoRetrato(SALVO_INCOMPLETO).variacoes[0], {
        id: 91, codigo: 'CRIADO-01', custo: '1890,00', estoque: '4', volumes: [{ c: '50', l: '40', a: '60', kg: '18,5' }], valor: '', valor_2: '',
    });
    assert.equal(custoParaTexto('890.00'), '890,00');
    assert.equal(custoParaTexto(0), '0,00', 'custo zero é um valor, não "sem custo"');
    assert.equal(custoParaTexto(null), '');
    // O estoque 0 é um número; não some.
    assert.equal(estadoDoRetrato({ ...CADASTRO, variacoes: [{ ...CADASTRO.variacoes[0], estoque: 0 }] }).variacoes[0].estoque, '0');
});

// ─── O "mexido" ─────────────────────────────────────────────────────────────

test('mexido: só o que muda para o servidor — espaço nas pontas, ordem dos ambientes e caixa em branco não contam', () => {
    assert.equal(mexido(estadoDoRetrato(CADASTRO), CADASTRO), false, 'recém-aberta');
    assert.equal(mexido(null, CADASTRO), false);
    assert.equal(mexido(estadoDoRetrato(CADASTRO), null), false);

    assert.equal(mexido(comMudancas(CADASTRO, { produto: { titulo: 'Criado-mudo' } }), CADASTRO), true);
    assert.equal(mexido(comMudancas(CADASTRO, { produto: { nome: '  Criado-mudo 2 gavetas  ' } }), CADASTRO), false);
    assert.equal(mexido(comMudancas(CADASTRO, { variacoes: { 91: { volumes: [{ c: '', l: ' ', a: '', kg: '' }] } } }), CADASTRO), false, 'a caixa em branco do "+ caixa"');
    assert.equal(mexido(comMudancas(CADASTRO, { variacoes: { 91: { volumes: [{ c: '50', l: '', a: '', kg: '' }] } } }), CADASTRO), true);

    const comAmbientes = { ...CADASTRO, produto: { ...CADASTRO.produto, ambientes: ['Sala de estar', 'Cozinha'] } };
    assert.equal(mexido(comMudancas(comAmbientes, { produto: { ambientes: ['Cozinha', 'Sala de estar'] } }), comAmbientes), false);
    assert.equal(mexido(comMudancas(comAmbientes, { produto: { ambientes: ['Cozinha'] } }), comAmbientes), true);

    // A descrição colada do Windows (\r\n) é a mesma que o servidor guardou.
    const comDescricao = { ...CADASTRO, produto: { ...CADASTRO.produto, descricao: 'Linha 1\nLinha 2' } };
    assert.equal(mexido(comMudancas(comDescricao, { produto: { descricao: 'Linha 1\r\nLinha 2\n' } }), comDescricao), false);

    assert.deepEqual(camposMexidos(comMudancas(CADASTRO, { produto: { familia: 'Mesas' }, variacoes: { 91: { custo: '10' } } }), CADASTRO), {
        produto: ['familia'],
        variacoes: { 91: ['custo'] },
    });
});

// ─── As linhas do "Salvar" ──────────────────────────────────────────────────

test('linhasParaSalvar: só o que mudou, na 1ª linha os campos do produto, e nada de produto_id nem grupo', () => {
    assert.deepEqual(linhasParaSalvar(estadoDoRetrato(CADASTRO), CADASTRO), [], 'nada mudou: nada vai');

    const estado = comMudancas(CADASTRO, {
        produto: { titulo: 'criado-mudo jatobá', familia: 'Criados-mudos', ambientes: ['Quarto', ' Quarto '], descricao: '  Jatobá maciço.\r\n' },
        variacoes: { 91: { custo: '1.890,00', estoque: '4', volumes: [{ c: '50', l: '40', a: '60', kg: '18,5' }, { c: '', l: '', a: '', kg: '' }] } },
    });
    assert.deepEqual(linhasParaSalvar(estado, CADASTRO), [{
        chave: 'v91',
        codigo: 'CRIADO-01',
        nome: 'Criado-mudo 2 gavetas',
        id: 91,
        titulo: 'criado-mudo jatobá',
        familia: 'Criados-mudos',
        volumes: [{ c: '50', l: '40', a: '60', kg: '18,5' }],
        custo: '1.890,00',
        estoque: '4',
        ambientes: ['Quarto'],
        descricao: 'Jatobá maciço.',
    }]);
});

test('linhasParaSalvar: esvaziar o que o servidor tinha vai `null` (limpar); o campo não mexido nunca vai — nem como ""', () => {
    const [linha] = linhasParaSalvar(comMudancas(SALVO_INCOMPLETO, {
        produto: { titulo: '', familia: ' ', descricao: '', ambientes: [] },
        variacoes: { 91: { custo: '', estoque: '', volumes: [] } },
    }), SALVO_INCOMPLETO);
    assert.deepEqual(linha, {
        chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo 2 gavetas', id: 91,
        titulo: null, familia: null, volumes: [], custo: null, estoque: null, ambientes: [], descricao: null,
    });

    // Só o custo mudou: o resto (família, título…) não vai, e o '' de um campo vazio não mexido também não.
    const [soCusto] = linhasParaSalvar(comMudancas(CADASTRO, { variacoes: { 91: { custo: '10' } } }), CADASTRO);
    assert.deepEqual(soCusto, { chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo 2 gavetas', id: 91, custo: '10' });
    for (const proibida of ['produto_id', 'grupo', 'categoria_texto', 'variacao', 'volumes_texto', 'company_id']) {
        assert.ok(!(proibida in soCusto), proibida);
    }

    // Trocar o SKU leva a linha (código e nome vão sempre).
    assert.deepEqual(linhasParaSalvar(comMudancas(CADASTRO, { variacoes: { 91: { codigo: 'CM-02' } } }), CADASTRO),
        [{ chave: 'v91', codigo: 'CM-02', nome: 'Criado-mudo 2 gavetas', id: 91 }]);
    // Trocar o nome também.
    assert.deepEqual(linhasParaSalvar(comMudancas(CADASTRO, { produto: { nome: 'Criado-mudo' } }), CADASTRO),
        [{ chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo', id: 91 }]);
});

test('linhasParaSalvar com duas variações: o produto vai só na 1ª; a 2ª só quando ela mudou', () => {
    const doisRetrato = CADASTRO_TRAVADO;
    const soProduto = linhasParaSalvar(comMudancas(doisRetrato, { produto: { titulo: 'Cadeira Palhinha' } }), doisRetrato);
    assert.deepEqual(soProduto.map((l) => l.chave), ['v21']);
    assert.equal(soProduto[0].titulo, 'Cadeira Palhinha');

    const soSegunda = linhasParaSalvar(comMudancas(doisRetrato, { variacoes: { 22: { estoque: '5' } } }), doisRetrato);
    assert.deepEqual(soSegunda, [{ chave: 'v22', codigo: 'CJ-211-MEL', nome: 'Cadeira jatobá assento palhinha', id: 22, estoque: '5' }]);
    assert.ok(!('titulo' in soSegunda[0]) && !('familia' in soSegunda[0]), 'o produto não vai na 2ª linha');
});

// ─── A ordem do "Salvar" ────────────────────────────────────────────────────

// ─── O contrato com o servidor, de ponta a ponta ────────────────────────────
//
// Os dois retratos abaixo são os que o servidor DEVOLVEU (integração da F1, 03/11/2026: criar e abrir, depois o
// "Salvar" com o corpo deste teste), recortados ao que a gaveta lê. Os corpos esperados são LITERAIS: os mesmos que
// tests/Feature/Trilha/CadastroDePontaAPontaTest.php manda às rotas e confere no banco. Mudar um lado sem o outro
// quebra um dos dois testes.

const RETRATO_AO_NASCER = {
    ...CADASTRO,
    produto: { id: 1, nome: 'Poltrona Ripada', titulo: null, familia: null, ambientes: [], categoria: null, descricao: null, eixo: null, eixo_2: null, origem_ml: false },
    variacoes: [{ ...CADASTRO.variacoes[0], id: 1, codigo: 'POLT-RIP' }],
    listas: { familias: [], ambientes: [] },
};

const RETRATO_DEPOIS_DO_SALVAR = {
    ...RETRATO_AO_NASCER,
    produto: {
        ...RETRATO_AO_NASCER.produto, nome: 'Poltrona Ripada Bege', titulo: 'Poltrona Ripada Bege Para Sala', familia: 'Poltronas',
        ambientes: ['Sala de estar', 'Varanda'], descricao: 'Linha 1\nLinha 2',
    },
    // O custo e as medidas voltam como NÚMERO (o cast do model), não como o texto que foi.
    variacoes: [{ ...RETRATO_AO_NASCER.variacoes[0], custo: 1890.5, estoque: 4, volumes: [{ c: 60, l: 40, a: 30, kg: 8.5 }], logistica: 'me2_full' }],
    listas: { familias: [{ id: 1, nome: 'Poltronas' }], ambientes: [{ id: 1, nome: 'Sala de estar' }, { id: 2, nome: 'Varanda' }] },
    situacao: { ...CADASTRO.situacao, faltas: ['categoria', 'fotos (0 de 3)'] },
};

test('o corpo do "Salvar" é o que o servidor lê (o mesmo literal do CadastroDePontaAPontaTest)', () => {
    // O que a pessoa digita na gaveta recém-criada — com uma caixa em branco que ficou para trás.
    const digitado = comMudancas(RETRATO_AO_NASCER, {
        produto: { nome: 'Poltrona Ripada Bege', titulo: 'poltrona ripada bege para sala', familia: 'Poltronas', ambientes: ['Sala de estar', 'Varanda'], descricao: 'Linha 1\r\nLinha 2' },
        variacoes: { 1: { custo: '1.890,50', estoque: '4', volumes: [{ c: '60', l: '40', a: '30', kg: '8,5' }, { c: '', l: '', a: '', kg: '' }] } },
    });
    assert.deepEqual(linhasParaSalvar(digitado, RETRATO_AO_NASCER), [{
        chave: 'v1', codigo: 'POLT-RIP', nome: 'Poltrona Ripada Bege', id: 1,
        titulo: 'poltrona ripada bege para sala', familia: 'Poltronas',
        volumes: [{ c: '60', l: '40', a: '30', kg: '8,5' }],
        custo: '1.890,50', estoque: '4', ambientes: ['Sala de estar', 'Varanda'], descricao: 'Linha 1\nLinha 2',
    }]);

    // Depois do "Salvar", os campos são os do servidor (título capitalizado, custo "1890,50"): nada fica "mexido".
    const depois = depoisDeSalvar(digitado, RETRATO_DEPOIS_DO_SALVAR, []);
    assert.equal(mexido(depois, RETRATO_DEPOIS_DO_SALVAR), false);
    assert.deepEqual(depois.variacoes[0], { id: 1, codigo: 'POLT-RIP', custo: '1890,50', estoque: '4', volumes: [{ c: '60', l: '40', a: '30', kg: '8,5' }], valor: '', valor_2: '' });

    // Esvaziar custo, família e título (só espaço também conta): vai `null` — e nada mais além de código e nome.
    const esvaziado = comMudancas(RETRATO_DEPOIS_DO_SALVAR, { produto: { familia: '', titulo: '   ' }, variacoes: { 1: { custo: '' } } });
    assert.deepEqual(linhasParaSalvar(esvaziado, RETRATO_DEPOIS_DO_SALVAR), [{
        chave: 'v1', codigo: 'POLT-RIP', nome: 'Poltrona Ripada Bege', id: 1, titulo: null, familia: null, custo: null,
    }]);
    assert.deepEqual(resultadoDoSalvar(RETRATO_AO_NASCER, RETRATO_DEPOIS_DO_SALVAR, { familias: ['Poltronas'], ambientes: ['Sala de estar', 'Varanda'] }), {
        tipo: 'incompleto',
        texto: 'Salvo. Ainda faltam: categoria, fotos (0 de 3).',
        faltas: ['categoria', 'fotos (0 de 3)'],
        extra: 'Criamos a família Poltronas e os ambientes Sala de estar e Varanda na lista da empresa.',
    });
});

test('gravarCadastro: linhas em lotes, na ordem; o 1º erro de campo PARA; a ficha só depois das linhas; a versão vai adiante', async () => {
    const estado = comMudancas(CADASTRO_TRAVADO, { produto: { titulo: 'Novo' }, variacoes: { 22: { estoque: '5' } } });
    const pedidos = [];
    const r = await gravarCadastro({
        estado,
        retrato: CADASTRO_TRAVADO,
        porPedido: 1,
        versao: 'v-1',
        enviarLinhas: async (linhas, { versao }) => {
            pedidos.push(['linhas', linhas.map((l) => l.chave), versao]);

            return { erros: [], avisos: [`aviso ${pedidos.length}`], criadas_nas_listas: { familias: [], ambientes: [] }, versao: `v-${pedidos.length + 1}` };
        },
        gravarFicha: async ({ versao }) => {
            pedidos.push(['ficha', versao]);

            return { ok: true, versao: 'v-ficha' };
        },
        fichaMudou: true,
    });
    assert.deepEqual(pedidos, [['linhas', ['v21'], 'v-1'], ['linhas', ['v22'], 'v-2'], ['ficha', 'v-3']]);
    assert.equal(r.ok, true);
    assert.equal(r.versao, 'v-ficha');
    assert.deepEqual(r.avisos, ['aviso 1', 'aviso 2']);

    // Erro de campo no 1º lote (HTTP 200 com `erros`): o 2º lote e a ficha nem saem.
    const parados = [];
    const comErro = await gravarCadastro({
        estado,
        retrato: CADASTRO_TRAVADO,
        porPedido: 1,
        enviarLinhas: async (linhas) => {
            parados.push(linhas[0].chave);

            return { erros: [{ chave: 'v21', campos: { titulo: 'O título passa do limite.' }, mensagem: 'O título passa do limite.' }] };
        },
        gravarFicha: async () => parados.push('ficha'),
        fichaMudou: true,
    });
    assert.deepEqual(parados, ['v21']);
    assert.equal(comErro.ok, false);
    assert.equal(comErro.passo, 'linhas');
    assert.equal(comErro.erros.length, 1);

    // O pedido que lança (rede, 409, 422 do Laravel) também para, e diz o passo.
    const falha = new Error('409');
    const lancou = await gravarCadastro({ estado, retrato: CADASTRO_TRAVADO, enviarLinhas: async () => { throw falha; } });
    assert.deepEqual([lancou.ok, lancou.passo, lancou.falha], [false, 'linhas', falha]);

    // Sem mudança nenhuma: nenhum pedido.
    let chamou = false;
    const nada = await gravarCadastro({ estado: estadoDoRetrato(CADASTRO), retrato: CADASTRO, enviarLinhas: async () => { chamou = true; } });
    assert.deepEqual([nada.ok, nada.nada, chamou], [true, true, false]);

    // O lote é o do retrato (`limites.linhas`) quando não se diz outro.
    const lotes = [];
    await gravarCadastro({
        estado,
        retrato: { ...CADASTRO_TRAVADO, limites: { ...CADASTRO_TRAVADO.limites, linhas: 200 } },
        enviarLinhas: async (linhas) => {
            lotes.push(linhas.length);

            return { erros: [] };
        },
    });
    assert.deepEqual(lotes, [2]);
});

test('nadaMudou: os `totais` dos lotes somados; zerados (o servidor não achou diferença), a tela não diz "Salvo"', async () => {
    const estado = comMudancas(CADASTRO_TRAVADO, { produto: { titulo: 'Novo' }, variacoes: { 22: { estoque: '5' } } });
    const lote = (criadas, atualizadas, semMudanca) => async () => ({ erros: [], totais: { criadas, atualizadas, sem_mudanca: semMudanca, com_erro: 0 } });

    const semDiferenca = await gravarCadastro({ estado, retrato: CADASTRO_TRAVADO, porPedido: 1, enviarLinhas: lote(0, 0, 1) });
    assert.deepEqual(semDiferenca.totais, { criadas: 0, atualizadas: 0, sem_mudanca: 2, com_erro: 0, absorvidos_da_espera: 0 });
    assert.equal(nadaMudou(semDiferenca), true);

    const umGravou = await gravarCadastro({ estado, retrato: CADASTRO_TRAVADO, enviarLinhas: lote(0, 1, 1) });
    assert.equal(nadaMudou(umGravou), false);
    // Sem `totais` na resposta não dá para saber: conta como gravado.
    assert.equal(nadaMudou(await gravarCadastro({ estado, retrato: CADASTRO_TRAVADO, enviarLinhas: async () => ({ erros: [] }) })), false);
    assert.equal(nadaMudou({ totais: { criadas: 0, atualizadas: 0 }, erros: [{ chave: 'v21' }] }), false, 'erro de campo não é "nada mudou"');
});

// ─── A resposta ─────────────────────────────────────────────────────────────

test('errosPorCampo: o erro de cada linha no campo dela; o que não tem campo na gaveta vai para o rodapé', () => {
    assert.deepEqual(errosPorCampo(SALVO_COM_ERRO.erros), { porChave: { v91: { custo: 'Use números no formato 1.234,56.' } }, soltas: [] });
    // F2: a categoria é campo da gaveta — o serviço a chama de `categoria_ml_id` ("mais específica"), e o erro cai nela.
    assert.deepEqual(errosPorCampo([
        { chave: 'v91', mensagem: 'O título passa do limite.', campos: { titulo: 'O título passa do limite.', categoria_ml_id: 'Escolha uma categoria mais específica (a última do caminho).' } },
        { chave: 'v92', mensagem: 'Não deu para gravar esta linha agora.', campos: { linha: 'Não deu para gravar esta linha agora.' } },
        { chave: null, mensagem: 'Erro sem campo.', campos: {} },
    ]), {
        porChave: { v91: { titulo: 'O título passa do limite.', categoria: 'Escolha uma categoria mais específica (a última do caminho).' } },
        soltas: ['Não deu para gravar esta linha agora.', 'Erro sem campo.'],
    });
    assert.deepEqual(errosPorCampo(null), { porChave: {}, soltas: [] });
    assert.equal(MENSAGEM_DOS_CAMPOS, 'Confira os campos marcados: o que está neles não foi salvo.');
});

test('aplicarResposta (ações imediatas): o que a pessoa digitou e não salvou fica; o resto vem do retrato novo', () => {
    // A pessoa mexeu no custo e no título; uma ação imediata (cor nova, F4) devolve o retrato com outra variação.
    const estado = comMudancas(SALVO_INCOMPLETO, { produto: { titulo: 'Meu título' }, variacoes: { 91: { custo: '999,00' } } });
    const novo = {
        ...SALVO_INCOMPLETO,
        produto: { ...SALVO_INCOMPLETO.produto, descricao: 'Mudou no servidor.' },
        variacoes: [
            { ...SALVO_INCOMPLETO.variacoes[0], estoque: 7 },
            { ...SALVO_INCOMPLETO.variacoes[0], id: 92, codigo: 'CRIADO-01-2', custo: 1890 },
        ],
    };
    const junto = aplicarResposta(estado, SALVO_INCOMPLETO, novo);
    assert.equal(junto.produto.titulo, 'Meu título', 'mexido e não salvo: fica');
    assert.equal(junto.produto.descricao, 'Mudou no servidor.', 'não mexido: vem do servidor');
    assert.equal(junto.variacoes[0].custo, '999,00');
    assert.equal(junto.variacoes[0].estoque, '7');
    assert.deepEqual(junto.variacoes.map((v) => v.id), [91, 92], 'a variação nova entra como veio');
    assert.equal(junto.variacoes[1].codigo, 'CRIADO-01-2');
    // Depois de juntar, continua mexido (o título e o custo ainda não foram salvos).
    assert.equal(mexido(junto, novo), true);
    assert.deepEqual(camposMexidos(junto, novo), { produto: ['titulo'], variacoes: { 91: ['custo'] } });
});

test('depoisDeSalvar: sem erro, os campos são os do servidor (normalizados); a linha com erro fica como a pessoa deixou', () => {
    const digitado = comMudancas(CADASTRO, { produto: { titulo: 'criado-mudo 2 gavetas jatobá' }, variacoes: { 91: { custo: '1.890' } } });
    assert.deepEqual(depoisDeSalvar(digitado, SALVO_INCOMPLETO, []), estadoDoRetrato(SALVO_INCOMPLETO));
    assert.equal(mexido(depoisDeSalvar(digitado, SALVO_INCOMPLETO, []), SALVO_INCOMPLETO), false, 'salvo: nada mexido');

    const comErro = depoisDeSalvar(digitado, SALVO_COM_ERRO, SALVO_COM_ERRO.erros);
    assert.equal(comErro.variacoes[0].custo, '1.890');
    assert.equal(comErro.produto.titulo, 'criado-mudo 2 gavetas jatobá', 'os campos do produto andam com a 1ª linha');
    assert.equal(mexido(comErro, SALVO_COM_ERRO), true, 'o que não gravou continua para salvar');
});

test('resultadoDoSalvar: "Lista completa…" só quando o produto SAIU da Lista; senão "Ainda faltam…" com as faltas do servidor', () => {
    assert.deepEqual(resultadoDoSalvar(CADASTRO, SALVO_INCOMPLETO, SALVO_INCOMPLETO.criadas_nas_listas), {
        tipo: 'incompleto',
        texto: 'Salvo. Ainda faltam: categoria, fotos (0 de 3).',
        faltas: ['categoria', 'fotos (0 de 3)'],
        extra: 'Criamos a família Criados-mudos e o ambiente Quarto na lista da empresa.',
    });
    assert.deepEqual(resultadoDoSalvar(CADASTRO, SALVO_COMPLETO), {
        tipo: 'completo', texto: 'Lista completa: o produto seguiu para o mapeamento.', faltas: [], extra: null,
    });
    // Já estava fora da Lista: "Salvo." (não diz que "seguiu").
    assert.equal(resultadoDoSalvar(SALVO_COMPLETO, SALVO_COMPLETO).tipo, 'salvo');
    assert.equal(resultadoDoSalvar(CADASTRO_TRAVADO, CADASTRO_TRAVADO).texto, 'Salvo.');
    assert.equal(temRetrato(SALVO_INCOMPLETO), true);
    assert.equal(temRetrato({ erros: [] }), false);
});

// ─── Família e ambientes ────────────────────────────────────────────────────

test('lista com busca: começa com primeiro, depois contém; sem caixa nem acento; o nome que não existe vira "(nova)"', () => {
    const familias = [{ id: 1, nome: 'Sofás' }, { id: 2, nome: 'Mesas' }, { id: 3, nome: 'Mesinhas' }, { id: 4, nome: 'Cadeiras de mesa' }];
    assert.deepEqual(opcoesQueCasam(familias, 'mes'), { opcoes: ['Mesas', 'Mesinhas', 'Cadeiras de mesa'], novo: 'mes' });
    assert.deepEqual(opcoesQueCasam(familias, 'SOFAS'), { opcoes: ['Sofás'], novo: null }, 'existe (sem acento nem caixa): não é nova');
    assert.deepEqual(opcoesQueCasam(familias, ''), { opcoes: ['Sofás', 'Mesas', 'Mesinhas', 'Cadeiras de mesa'], novo: null });
    // Os ambientes já escolhidos não aparecem de novo.
    assert.deepEqual(opcoesQueCasam(['Sala', 'Quarto', 'Cozinha'], '', { fora: ['quarto'] }).opcoes, ['Sala', 'Cozinha']);
    assert.equal(opcoesQueCasam(['Sala'], 'Varanda').novo, 'Varanda');
    assert.equal(nomeNovo(familias, 'sofas'), false);
    assert.equal(nomeNovo(familias, 'Criados-mudos'), true);
    assert.equal(nomeNovo(familias, '  '), false);
});

// ─── Avisos, Esc e chave ────────────────────────────────────────────────────

test('aviso da onda começada: o nome livre da onda, sem "Onda Onda"; o Esc que um campo tratou não fecha a gaveta', () => {
    assert.equal(avisoDaOndaComecada({ nome: 'Onda 1 · simples' }), 'A Onda 1 · simples já começou: ao salvar, o robô prepara este produto com o que estiver aqui (usa IA).');
    assert.equal(avisoDaOndaComecada({ nome: 'Black Friday' }), 'A onda “Black Friday” já começou: ao salvar, o robô prepara este produto com o que estiver aqui (usa IA).');
    assert.equal(avisoDaOndaComecada(null), null);

    assert.equal(deveFecharNoEsc({ key: 'Escape', defaultPrevented: false }), true);
    assert.equal(deveFecharNoEsc({ key: 'Escape', defaultPrevented: true }), false, 'a lista aberta fechou a si mesma');
    assert.equal(deveFecharNoEsc({ key: 'Enter', defaultPrevented: false }), false);
    assert.equal(CHAVE_NOVO_PRODUTO, 'e:novo');
});

test('chave `e:` — quem escreve abre o cadastro; o cliente e o "Ver como: Cliente" caem no painel de leitura; `e:novo` só para quem escreve', () => {
    const dados = PROPS_TRABALHO;
    const quem = { podeEditar: true };

    assert.deepEqual(E.painelDaChave(dados, 'e:9', quem), { painel: 'cadastro', chave: 'e:9', produto: dados.produtos.find((p) => p.id === 9) });
    assert.deepEqual(E.painelDaChave(dados, 'e:novo', quem), { painel: 'cadastro', chave: 'e:novo', produto: null });
    // O produto travado abre na gaveta também (só leitura: quem diz é o servidor).
    assert.equal(E.painelDaChave(dados, 'e:2', quem).painel, 'cadastro');
    // Produto que não existe (apagado por outra pessoa): nada.
    assert.equal(E.painelDaChave(dados, 'e:999', quem), null);

    // Sem escrita: o `e:9` vira o painel do produto; o `e:novo` não abre nada.
    for (const [nome, d] of [['sem escrita', dados], ['cliente', paraCliente(PROPS_TRABALHO)], ['Ver como: Cliente', E.paraVisaoCliente(dados)]]) {
        const p9 = E.painelDaChave(d, 'e:9', { podeEditar: false });
        assert.deepEqual([p9?.painel, p9?.tipo, p9?.chave], ['item', 'p', 'p:9'], nome);
        assert.equal(E.painelDaChave(d, 'e:novo', { podeEditar: false }), null, nome);
    }

    // A URL na forma do Laravel (`e%3A9`), lida de volta nas duas formas.
    assert.equal(E.buscaComEstado('', { etapa: 'lista', vista: 'trilha', abrir: 'e:9' }), '?abrir=e%3A9&etapa=lista');
    assert.equal(E.buscaComEstado('', { etapa: null, vista: 'trilha', abrir: 'e:novo' }), '?abrir=e%3Anovo');
    assert.deepEqual(E.lerEstadoDaUrl('?abrir=e%3A9&etapa=lista', dados, quem), { etapa: 'lista', vista: 'trilha', abrir: 'e:9' });
    assert.deepEqual(E.lerEstadoDaUrl('?abrir=e:novo', dados, quem), { etapa: null, vista: 'trilha', abrir: 'e:novo' });
    assert.deepEqual(E.lerEstadoDaUrl('?abrir=e%3Anovo', dados, { podeEditar: false }), { etapa: null, vista: 'trilha', abrir: null });
    // O cliente com o link `e:9` fica com a chave (o painel dele é o de leitura, como o `m:`).
    assert.equal(E.lerEstadoDaUrl('?abrir=e%3A9', paraCliente(PROPS_TRABALHO), { podeEditar: false }).abrir, 'e:9');
    assert.equal(E.lerEstadoDaUrl('?abrir=e%3A999', dados, quem).abrir, null);

    // "Abrir na trilha" do Mapa: a etapa da linha do produto.
    assert.equal(E.etapaDoItem(dados, 'e:9'), 'lista');
    assert.equal(E.etapaDoItem(dados, 'e:novo'), 'lista');

    // O foco ao fechar: a linha do produto (`p:`); o produto novo, o "+ Produto na lista".
    assert.deepEqual(E.candidatasDaVolta('e:9'), ['e:9', 'p:9']);
    assert.deepEqual(E.candidatasDaVolta('m:5'), ['m:5', 'p:5']);
    assert.deepEqual(E.candidatasDaVolta('e:novo'), ['e:novo']);
    assert.deepEqual(E.candidatasDaVolta('w:7'), ['w:7', 'w:nova']);
    assert.deepEqual(E.candidatasDaVolta(null), []);

    // A trava é da equipe: o "Ver como: Cliente" corta `cadastro_travado` como o servidor.
    assert.ok(dados.produtos.some((p) => p.cadastro_travado));
    assert.ok(E.paraVisaoCliente(dados).produtos.every((p) => !('cadastro_travado' in p)));
    assert.ok(paraCliente(PROPS_TRABALHO).produtos.every((p) => !('cadastro_travado' in p)));
});

test('textos da etapa 1 com a trilha de trabalho: o dono é a equipe; o cliente acompanha; o travado diz "Ver"', () => {
    assert.equal(etapaDaTrilha('lista', true).dono, 'Equipe ECF');
    assert.equal(etapaDaTrilha('lista', false).dono, 'Cliente');
    assert.equal(etapaDaTrilha('lista', true).faz, 'A equipe cadastra cada produto aqui: nome, categoria, ficha técnica, cores, fotos, medidas e custo. Completo, ele segue sozinho para o mapeamento.');
    // O "Pronto quando" é a mesma régua, MENOS o "frete ME1": com a trilha de trabalho ele é aviso (pergunta 3, F3).
    const semMe1 = etapaDaTrilha('lista', false).pronto.replace(' (sem pendência de frete ME1)', '');
    const comTrabalho = etapaDaTrilha('lista', true).pronto;
    assert.ok(comTrabalho.startsWith(semMe1), comTrabalho);
    assert.ok(comTrabalho.endsWith('segue com o aviso “vai por ME1 (transportadora)”.') && !comTrabalho.includes('frete ME1'), comTrabalho);

    assert.equal(acaoDoItem('lista', 'cliente', true, { trabalho: true }), 'Acompanhar');
    assert.equal(acaoDoItem('lista', 'cliente', true, { trabalho: false }), 'Completar');
    assert.equal(acaoDoItem('lista', 'equipe', true, { trabalho: true }), 'Completar');
    assert.equal(acaoDoItem('lista', 'equipe', true, { trabalho: true, travado: true }), 'Ver');
    assert.equal(acaoDoItem('mapa', 'equipe', true, { trabalho: true, travado: true }), 'Mapear', 'a trava é do cadastro, não do mapear');

    const [daLista] = comQuemEsta(PROPS_TRABALHO, 'equipe', true);
    assert.deepEqual([daLista.dono, daLista.descricao, daLista.etapa], ['Equipe ECF', 'cadastrar a lista', 'lista']);
    assert.equal(comQuemEsta(PROPS_TRABALHO, 'cliente', true)[0].descricao, 'cadastrando seus produtos');
    assert.equal(comQuemEsta(PROPS_TRABALHO, 'equipe', false)[0].dono, 'Cliente');
});

test('lib/trilhaCadastro.js é pura: sem React, sem axios, sem classe do Tailwind', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaCadastro.js');
    assert.equal(fonte.match(/from ['"](?:react|axios|@inertiajs\/react)['"]/), null);
    assert.equal(fonte.match(/className|\b(?:bg|text|border|fill|stroke)-trilha-/), null);
    const doHook = fs.readFileSync(path.resolve(RAIZ, 'resources/js/Components/Trilha/Cadastro/useCadastroDoProduto.js'), 'utf8');
    assert.ok(!doHook.includes('className'), 'o hook é .js: classe ali não é gerada');
});

// ═══ F2 — a categoria e a ficha técnica ════════════════════════════════════

test('F2 — sugestaoDaCategoria: a 1ª que é a última do caminho E aceita produto novo; o que não serve diz por quê', () => {
    assert.equal(sugestaoDaCategoria(CANDIDATAS.categorias)?.id, 'MLB1902');
    // Sem nenhuma usável (a que não é a última e a fechada para produto novo): nenhuma sugestão.
    assert.equal(sugestaoDaCategoria([CANDIDATAS.categorias[0], CANDIDATAS.categorias[2]]), null);
    // O servidor que não soube dizer (`null`): essa não se sugere.
    assert.equal(sugestaoDaCategoria([{ ...CANDIDATAS.categorias[1], folha: null, anunciavel: null }]), null);
    assert.equal(sugestaoDaCategoria([{ ...CANDIDATAS.categorias[1], anunciavel: null }]), null);
    assert.equal(sugestaoDaCategoria(null), null);
    assert.equal(sugestaoDaCategoria([{ id: 'Poltronas', folha: true, anunciavel: true }]), null, 'só id de categoria de verdade');

    assert.deepEqual(CANDIDATAS.categorias.map((c) => [candidataUsavel(c), porQueNaoUsar(c)]), [
        [false, 'Escolha uma mais específica'],
        [true, null],
        [false, 'Não aceita produto novo'],
    ]);

    // A categoria como a gaveta guarda — da busca, da árvore (caminho em lista) e do retrato; o texto sem id não conta.
    assert.deepEqual(categoriaDaGaveta(CANDIDATAS.categorias[1]), { id: 'MLB1902', nome: 'Criados-mudos', caminho_texto: 'Casa, Móveis e Decoração > Quarto > Criados-mudos' });
    assert.deepEqual(categoriaDaGaveta({ id: 'mlb5', nome: 'Poltronas', caminho: ['Casa', 'Poltronas'], folha: true }), { id: 'MLB5', nome: 'Poltronas', caminho_texto: 'Casa > Poltronas' });
    assert.equal(categoriaDaGaveta({ id: null, nome: 'Poltrona decorativa', estado: 'a_confirmar' }), null, 'o texto colado ("a confirmar") não é categoria');
    assert.equal(estadoDoRetrato({ ...CADASTRO, produto: { ...CADASTRO.produto, categoria: { id: null, nome: 'Poltrona' } } }).produto.categoria, null);
    assert.equal(caminhoParaTela(CANDIDATAS.categorias[1]), 'Casa, Móveis e Decoração › Quarto › Criados-mudos');

    // A busca pelo nome: de 2 a 120 letras.
    assert.equal(textoDaBusca(' a '), null);
    assert.equal(textoDaBusca('  Criado-mudo  '), 'Criado-mudo');
    assert.equal(textoDaBusca('x'.repeat(300)).length, 120);

    // A lista que acabou de mudar não aceita clique (a pessoa mirou na de antes — demo de 23/10).
    assert.equal(cliqueValeNaLista({ mudouEm: 1000, desceuEm: 1000 + LISTA_ESTAVEL_MS - 1, agora: 9000 }), false);
    assert.equal(cliqueValeNaLista({ mudouEm: 1000, desceuEm: 1000 + LISTA_ESTAVEL_MS, agora: 9000 }), true);
    assert.equal(cliqueValeNaLista({ mudouEm: 1000, agora: 1100 }), false, 'sem o mouse (teclado): conta a partir de agora');

    // Com o produto no Publicador, a categoria trava (D12).
    assert.equal(categoriaTravada(CADASTRO_CATEGORIA_TRAVADA), true);
    assert.equal(categoriaTravada(CADASTRO_COM_CATEGORIA), false);
    assert.equal(categoriaTravada({ situacao: { no_publicador: true, categoria_travada: false } }), true);
});

test('F2 — "Usar esta" só põe a categoria no ESTADO: fica mexido, e ela vai pelo id na 1ª linha do "Salvar"', () => {
    const estado = estadoDoRetrato(CADASTRO);
    const usada = { ...estado, produto: { ...estado.produto, categoria: categoriaDaGaveta(sugestaoDaCategoria(CANDIDATAS.categorias)) } };
    assert.equal(mexido(estado, CADASTRO), false);
    assert.equal(mexido(usada, CADASTRO), true);
    assert.deepEqual(camposMexidos(usada, CADASTRO).produto, ['categoria']);
    assert.deepEqual(linhasParaSalvar(usada, CADASTRO), [{ chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo 2 gavetas', id: 91, categoria_ml_id: 'MLB1902' }]);

    // A categoria que o servidor já tem não vai (nem como texto); trocar vai só o id novo.
    const igual = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    assert.deepEqual(linhasParaSalvar(igual, CADASTRO_COM_CATEGORIA), []);
    const [linha] = linhasParaSalvar({ ...igual, produto: { ...igual.produto, categoria: { id: 'MLB1903', nome: 'x', caminho_texto: 'x' } } }, CADASTRO_COM_CATEGORIA);
    assert.equal(linha.categoria_ml_id, 'MLB1903');
    assert.ok(!('categoria_texto' in linha) && !('categoria' in linha), JSON.stringify(linha));

    // Duas variações: a categoria anda com o produto, na 1ª linha só.
    const duas = estadoDoRetrato(CADASTRO_TRAVADO);
    duas.produto.categoria = { id: 'MLB9', nome: 'Cadeiras', caminho_texto: 'Casa > Cadeiras' };
    duas.variacoes[1].estoque = '9';
    assert.deepEqual(linhasParaSalvar(duas, CADASTRO_TRAVADO).map((l) => [l.chave, l.categoria_ml_id ?? null]), [['v21', 'MLB9'], ['v22', null]]);

    // Uma ação imediata (F3/F4) não apaga a categoria escolhida e ainda não salva.
    assert.equal(aplicarResposta(usada, CADASTRO, SALVO_INCOMPLETO).produto.categoria.id, 'MLB1902');

    // O 422 da trilha (não anunciável, ou o produto já no Publicador) cai no campo da categoria.
    const recusa = (errors) => ({ isAxiosError: true, response: { status: 422, data: { errors } } });
    assert.equal(erroDaCategoriaNo422(recusa({ 'linhas.0.categoria_ml_id': ['A categoria já está no Publicador; troque no editor de lá.'] })), 'A categoria já está no Publicador; troque no editor de lá.');
    assert.equal(erroDaCategoriaNo422(recusa({ 'linhas.0.custo': ['Use números.'] })), null);
    assert.equal(erroDaCategoriaNo422({ response: { status: 409, data: { errors: { categoria: ['x'] } } } }), null);
});

test('F2 — a ficha no estado da gaveta: os salvos viram valores, o "mexido" compara o corpo do servidor, o eixo do produto sai', () => {
    const estado = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    assert.deepEqual(estado.ficha, { BRAND: { valor: 'ECF Móveis', unidade: '' } });
    const grupos = gruposDaFicha(CAMPOS_DA_CATEGORIA.grupos, CADASTRO_COM_CATEGORIA);
    assert.equal(mexido(estado, CADASTRO_COM_CATEGORIA, { grupos }), false);
    assert.equal(mexido({ ...estado, ficha: { ...estado.ficha, MAIN_MATERIAL: { valor: '1', unidade: '' } } }, CADASTRO_COM_CATEGORIA, { grupos }), true);

    // Digitar e apagar não conta (a unidade padrão que ficou no campo não é valor) — com e sem a definição.
    const apagado = { ...estado, ficha: { ...estado.ficha, MAX_WEIGHT: { valor: '', unidade: 'kg' } } };
    assert.equal(fichaMexida(apagado, CADASTRO_COM_CATEGORIA, { grupos }), false);
    assert.equal(fichaMexida(apagado, CADASTRO_COM_CATEGORIA), false);

    // A opção gravada como TEXTO ("Madeira", sem o id) é a mesma que escolher a opção pelo id.
    const comNome = { ...CADASTRO_COM_CATEGORIA, ficha: { salvos: [...CADASTRO_COM_CATEGORIA.ficha.salvos, { id: 'MAIN_MATERIAL', nome: 'Material principal', valor: 'Madeira', valor_id: null, unidade: null }] } };
    const peloId = estadoDoRetrato(comNome);
    peloId.ficha = { ...peloId.ficha, MAIN_MATERIAL: { valor: '1', unidade: '', outro: false } };
    assert.equal(fichaMexida(peloId, comNome, { grupos }), false);
    // O estado sem ficha (o formato da F1) não conta como mexido.
    assert.equal(fichaMexida({ produto: estado.produto, variacoes: estado.variacoes }, CADASTRO_COM_CATEGORIA), false);

    // O eixo das variações (a cadeira varia por COR): o campo Cor sai da ficha; sem eixo, fica.
    const ids = (g) => g.flatMap((x) => x.campos.map((c) => c.id));
    assert.ok(ids(grupos).includes('COLOR'));
    assert.ok(!ids(gruposDaFicha(CAMPOS_DA_CATEGORIA.grupos, CADASTRO_TRAVADO)).includes('COLOR'));
    // As medidas do produto montado continuam (gravam com a ficha).
    assert.ok(ids(grupos).includes('WIDTH'));
});

test('F2 — o "Salvar" com a ficha: as linhas (com a categoria) primeiro, a ficha INTEIRA depois; o 422 da ficha deixa o passo 1 gravado', async () => {
    const definicao = { categoria: 'MLB1902', grupos: CAMPOS_DA_CATEGORIA.grupos, carregando: false };
    const sem = estadoDoRetrato(CADASTRO);
    const estado = {
        ...sem,
        produto: { ...sem.produto, categoria: categoriaDaGaveta(CANDIDATAS.categorias[1]) },
        ficha: { BRAND: { valor: 'ECF Móveis', unidade: '' }, MAIN_MATERIAL: { valor: '1', unidade: '' }, WIDTH: { valor: '45', unidade: 'cm' } },
    };
    const so = (p) => ({ gravar: p.gravar, esperar: p.esperar, pulou: p.pulou });

    // Sem categoria na tela, a ficha não vai (ela exige a categoria GRAVADA).
    assert.equal(fichaParaSalvar({ estado: sem, retrato: CADASTRO, definicao }).gravar, false);
    // Os campos da categoria nova ainda carregando: espera (mandar agora apagaria a ficha). Fora do ar: as linhas
    // vão, a ficha fica para o próximo "Salvar".
    assert.deepEqual(so(fichaParaSalvar({ estado, retrato: CADASTRO, definicao: { categoria: null, grupos: null, carregando: true } })), { gravar: false, esperar: true, pulou: false });
    assert.deepEqual(so(fichaParaSalvar({ estado, retrato: CADASTRO, definicao: { categoria: null, grupos: null, carregando: false } })), { gravar: false, esperar: false, pulou: true });

    const plano = fichaParaSalvar({ estado, retrato: CADASTRO, definicao });
    assert.equal(plano.gravar, true);
    assert.equal(plano.categoria, 'MLB1902', 'a categoria da tela vai junto (o servidor confere com a gravada)');
    // A ficha INTEIRA (o PUT substitui), com as medidas do produto montado.
    assert.deepEqual(plano.atributos, [{ id: 'BRAND', valor: 'ECF Móveis' }, { id: 'MAIN_MATERIAL', valor: '1' }, { id: 'WIDTH', valor: '45', unidade: 'cm' }]);

    const pedidos = [];
    const r = await gravarCadastro({
        estado,
        retrato: CADASTRO,
        enviarLinhas: async (linhas) => {
            pedidos.push(['linhas', linhas]);

            return { ...SALVO_INCOMPLETO, erros: [] };
        },
        gravarFicha: async () => {
            pedidos.push(['ficha']);

            return CADASTRO_COM_CATEGORIA;
        },
        fichaMudou: plano.gravar,
    });
    assert.deepEqual(pedidos.map(([p]) => p), ['linhas', 'ficha']);
    assert.equal(pedidos[0][1][0].categoria_ml_id, 'MLB1902', 'a categoria grava no passo 1, antes da ficha');
    assert.deepEqual([r.ok, r.linhas, r.ficha], [true, true, true]);
    assert.equal(r.resposta, CADASTRO_COM_CATEGORIA, 'o retrato novo é o do último passo');
    // As linhas não mudaram nada no servidor, mas a ficha gravou: não é "nada mudou".
    assert.equal(nadaMudou({ ...r, totais: { criadas: 0, atualizadas: 0 } }), false);

    // Só a ficha mudou: nenhuma linha sai.
    const soFicha = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    soFicha.ficha = { ...soFicha.ficha, MAIN_MATERIAL: { valor: '2', unidade: '' } };
    const planoSo = fichaParaSalvar({ estado: soFicha, retrato: CADASTRO_COM_CATEGORIA, definicao });
    assert.deepEqual(planoSo.atributos, [{ id: 'BRAND', valor: 'ECF Móveis' }, { id: 'MAIN_MATERIAL', valor: '2' }]);
    const passos = [];
    const rSo = await gravarCadastro({
        estado: soFicha,
        retrato: CADASTRO_COM_CATEGORIA,
        enviarLinhas: async () => passos.push('linhas'),
        gravarFicha: async () => {
            passos.push('ficha');

            return CADASTRO_COM_CATEGORIA;
        },
        fichaMudou: planoSo.gravar,
    });
    assert.deepEqual([passos, rSo.linhas, rSo.ok], [['ficha'], false, true]);

    // Nada mudou na ficha nem na categoria: ela não vai.
    assert.equal(fichaParaSalvar({ estado: estadoDoRetrato(CADASTRO_COM_CATEGORIA), retrato: CADASTRO_COM_CATEGORIA, definicao }).gravar, false);

    // Trocar a categoria regrava a ficha inteira: a ficha vazia LIMPA a gravada (os campos que a nova não tem saem).
    const trocada = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    trocada.produto.categoria = { id: 'MLB777', nome: 'Mesas de cabeceira', caminho_texto: 'Casa > Mesas de cabeceira' };
    const planoTroca = fichaParaSalvar({
        estado: trocada,
        retrato: CADASTRO_COM_CATEGORIA,
        definicao: { categoria: 'MLB777', grupos: [{ grupo: 'G', campos: [{ id: 'SHAPE', nome: 'Formato', tipo: 'texto', obrigatorio: false }] }], carregando: false },
    });
    assert.deepEqual([planoTroca.gravar, planoTroca.atributos, planoTroca.categoria], [true, [], 'MLB777']);

    // A ficha recusada (422 por campo): para no passo 2 — e o passo 1 JÁ gravou (`linhas`).
    const falha = { isAxiosError: true, response: { status: 422, data: { errors: { 'atributos.MAIN_MATERIAL': ['Escolha uma das opções.'] } } } };
    const recusada = await gravarCadastro({
        estado,
        retrato: CADASTRO,
        enviarLinhas: async () => ({ ...SALVO_INCOMPLETO, erros: [] }),
        gravarFicha: async () => {
            throw falha;
        },
        fichaMudou: true,
    });
    assert.deepEqual([recusada.ok, recusada.passo, recusada.linhas, recusada.falha], [false, 'ficha', true, falha]);
    // Na gaveta: o produto e as variações do retrato novo (a categoria já gravada), a ficha como a pessoa deixou.
    const depois = depoisDaFichaRecusada(estado, CADASTRO_COM_CATEGORIA);
    assert.equal(depois.produto.categoria.id, 'MLB1902');
    assert.deepEqual(depois.ficha, estado.ficha);
    assert.equal(mexido(depois, CADASTRO_COM_CATEGORIA, { grupos: gruposDaFicha(CAMPOS_DA_CATEGORIA.grupos, CADASTRO_COM_CATEGORIA) }), true, 'a ficha continua para salvar');
    // O erro de campo no passo 1 para antes da ficha: ela fica como estava na tela.
    assert.deepEqual(depoisDeSalvar(estado, SALVO_COM_ERRO, SALVO_COM_ERRO.erros).ficha, estado.ficha);
});

test('F2b — "Preencher com IA": só os vazios recebem; o preenchido e o "não se aplica" ficam; nada grava (fica mexido)', () => {
    const estado = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    estado.ficha.FINISH = { valor: '', unidade: '', naoSeAplica: true };
    const sugestoes = [
        { id: 'BRAND', nome: 'Marca', valor: 'Outra marca', valor_id: null, unidade: null },
        { id: 'MAIN_MATERIAL', nome: 'Material principal', valor: 'Madeira', valor_id: '1', unidade: null },
        { id: 'MAX_WEIGHT', nome: 'Peso máximo suportado', valor: '30', valor_id: null, unidade: 'kg' },
        { id: 'FINISH', nome: 'Acabamento', valor: 'Verniz', valor_id: null, unidade: null },
        { id: 'HEIGHT', nome: 'Altura', valor: null, valor_id: '-1', unidade: null },
    ];
    const r = aplicarSugestoesNaFicha(estado.ficha, sugestoes);
    assert.deepEqual([...r.aplicados].sort(), ['HEIGHT', 'MAIN_MATERIAL', 'MAX_WEIGHT']);
    assert.equal(r.naoSeAplica, 1);
    assert.equal(r.ficha.BRAND.valor, 'ECF Móveis', 'o que a pessoa preencheu não muda');
    assert.equal(r.ficha.FINISH.naoSeAplica, true, '"não se aplica" marcado não muda');
    assert.equal(r.ficha.MAIN_MATERIAL.valor, '1');
    assert.deepEqual(r.ficha.MAX_WEIGHT, { valor: '30', unidade: 'kg' });
    assert.equal(estado.ficha.MAIN_MATERIAL, undefined, 'a ficha de entrada não é alterada');
    assert.deepEqual(aplicarSugestoesNaFicha(r.ficha, sugestoes).aplicados, [], 'de novo: nada mais a preencher');

    const grupos = gruposDaFicha(CAMPOS_DA_CATEGORIA.grupos, CADASTRO_COM_CATEGORIA);
    assert.equal(mexido({ ...estado, ficha: r.ficha }, CADASTRO_COM_CATEGORIA, { grupos }), true, 'nada gravou: o "Salvar" é que grava');

    assert.equal(textoDaIa({ aplicados: 3, naoSeAplica: 1 }), 'A IA preencheu 2 campos e marcou 1 como "Não se aplica". Confira os marcados "sugestão da IA" e clique em Salvar.');
    assert.equal(textoDaIa({ aplicados: 0, naoSeAplica: 0 }), 'A IA não achou mais nada para preencher com o que o produto informa.');
    assert.equal(textoDaIa({ erro: 'A IA não respondeu agora. Tente de novo em instantes.' }), 'A IA não respondeu agora. Tente de novo em instantes.');

    // Só com a categoria SALVA e a mesma na tela (o servidor recusa a tela que trocou sem salvar).
    assert.equal(podePreencherComIa(CADASTRO_COM_CATEGORIA, estado), true);
    assert.equal(podePreencherComIa(CADASTRO, estadoDoRetrato(CADASTRO)), false, 'sem categoria');
    const escolhida = estadoDoRetrato(CADASTRO);
    escolhida.produto.categoria = categoriaDaGaveta(CANDIDATAS.categorias[1]);
    assert.equal(podePreencherComIa(CADASTRO, escolhida), false, 'escolhida e ainda não salva');
    const trocou = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    trocou.produto.categoria = { id: 'MLB777', nome: 'x', caminho_texto: 'x' };
    assert.equal(podePreencherComIa(CADASTRO_COM_CATEGORIA, trocou), false, 'trocou e não salvou');
});

// ═══ Revisão da F1 (03/11/2026) ════════════════════════════════════════════

test('revisão F1 — Enter com a lista de sugestões aberta NUNCA envia o formulário: escolhe a marcada ou a 1ª', () => {
    // "Pol" com "Poltronas" na lista: o Enter antes caía no submit implícito e o "Salvar" criava a família "Pol".
    const { opcoes, novo } = opcoesQueCasam([{ id: 1, nome: 'Poltronas' }, { id: 2, nome: 'Mesas' }], 'Pol');
    const itens = [...opcoes.map((nome) => ({ nome, novo: false })), { nome: novo, novo: true }];
    assert.deepEqual(itens.map((i) => i.nome), ['Poltronas', 'Pol'], 'o "(nova)" vem por último');

    assert.deepEqual(acaoDoEnter({ listaAberta: true, ativa: -1, itens }), { prevenir: true, escolher: itens[0], semEscolha: false });
    assert.deepEqual(acaoDoEnter({ listaAberta: true, ativa: 1, itens }), { prevenir: true, escolher: itens[1], semEscolha: false }, 'a marcada pelas setas');
    // O campo que junta sem lista (ambientes) também não junta o texto cru com a lista aberta: vale a escolha.
    assert.deepEqual(acaoDoEnter({ listaAberta: true, ativa: -1, itens, temSemEscolha: true }).semEscolha, false);
    // Lista fechada: o ambiente digitado vira chip (sem enviar); a família segue o padrão do navegador.
    assert.deepEqual(acaoDoEnter({ listaAberta: false, temSemEscolha: true }), { prevenir: true, escolher: null, semEscolha: true });
    assert.deepEqual(acaoDoEnter({ listaAberta: false, temSemEscolha: false }), { prevenir: false, escolher: null, semEscolha: false });

    // O campo com sugestões decide o Enter por esta regra (e não tem outro caminho até o submit).
    const pecas = lerSemComentarios('resources/js/Components/Trilha/Cadastro/PecasDoCadastro.jsx');
    assert.match(pecas, /evento\.key === 'Enter'\) \{\s*const acao = acaoDoEnter\(\{ listaAberta: mostra, ativa, itens, temSemEscolha: !!aoEnterSemEscolha \}\);\s*if \(acao\.prevenir\) evento\.preventDefault\(\);/);
});

test('revisão F1 — o ambiente digitado e não juntado CONTA: é mexido, vai no "Salvar" e a guarda o vê', () => {
    const estado = estadoDoRetrato(CADASTRO);
    assert.equal(estado.produto.ambiente_digitado, '');
    const digitado = { ...estado, produto: { ...estado.produto, ambientes: ['Quarto'], ambiente_digitado: '  Varanda ' } };
    assert.deepEqual(ambientesEfetivos(digitado.produto), ['Quarto', 'Varanda']);
    assert.equal(mexido(digitado, CADASTRO), true);
    assert.deepEqual(linhasParaSalvar(digitado, CADASTRO)[0].ambientes, ['Quarto', 'Varanda']);

    // Só o texto, sem chip nenhum: já é mexido (o "Salvar" liga e fechar pergunta).
    const soTexto = { ...estado, produto: { ...estado.produto, ambiente_digitado: 'Varanda' } };
    assert.equal(mexido(soTexto, CADASTRO), true);
    assert.deepEqual(linhasParaSalvar(soTexto, CADASTRO)[0].ambientes, ['Varanda']);

    // O digitado igual a um chip (sem caixa nem acento) não repete nem conta; só espaço não conta.
    const comSala = { ...CADASTRO, produto: { ...CADASTRO.produto, ambientes: ['Sala de estar'] } };
    const base = estadoDoRetrato(comSala);
    assert.equal(mexido({ ...base, produto: { ...base.produto, ambiente_digitado: 'sala de ESTAR' } }, comSala), false);
    assert.equal(mexido({ ...base, produto: { ...base.produto, ambiente_digitado: '   ' } }, comSala), false);

    // Uma ação imediata não apaga o que está digitado; depois do "Salvar", o campo zera (o retrato novo tem o chip).
    assert.equal(aplicarResposta(digitado, CADASTRO, SALVO_INCOMPLETO).produto.ambiente_digitado, '  Varanda ');
    assert.equal(depoisDeSalvar(digitado, SALVO_INCOMPLETO, []).produto.ambiente_digitado, '');
});

test('revisão F1 — o SKU que tinha anúncio na espera: o resultado diz que ficou ligado e vai para a etapa 6 (nada de "mapeamento")', async () => {
    const absorvido = { ...SALVO_COMPLETO, situacao: { ...SALVO_COMPLETO.situacao, etapa: null }, edicao: { livre: false, motivo: 'O anúncio deste SKU já está no ar.', onda_comecou: null } };
    assert.deepEqual(resultadoDoSalvar(CADASTRO, absorvido, null, { absorvidos: 1 }), {
        tipo: 'absorvido', texto: AVISO_DO_SKU_ABSORVIDO, faltas: [], extra: null,
    });
    assert.equal(AVISO_DO_SKU_ABSORVIDO, 'Este SKU já tinha anúncio: o produto ficou ligado a ele e vai direto para a etapa 6.');
    assert.equal(resultadoDoSalvar(CADASTRO, SALVO_COMPLETO, null, { absorvidos: 0 }).tipo, 'completo', 'sem absorção, como antes');

    // O `absorvidos_da_espera` dos lotes é somado (é dele que o hook tira o aviso).
    const estado = comMudancas(CADASTRO_TRAVADO, { variacoes: { 21: { codigo: 'SKU-NA-ESPERA' }, 22: { estoque: '5' } } });
    const r = await gravarCadastro({
        estado,
        retrato: CADASTRO_TRAVADO,
        porPedido: 1,
        enviarLinhas: async (linhas) => ({ erros: [], totais: { criadas: 0, atualizadas: 1, sem_mudanca: 0, com_erro: 0, absorvidos_da_espera: linhas[0].chave === 'v21' ? 1 : 0 } }),
    });
    assert.equal(r.totais.absorvidos_da_espera, 1);
    assert.equal(nadaMudou(r), false);
});

test('revisão F1 — o produto novo (e:novo) tem a guarda simples: nome ou SKU digitados e ainda não criado', () => {
    assert.equal(produtoNovoMexido({ nome: '', codigo: '' }), false);
    assert.equal(produtoNovoMexido({ nome: '   ', codigo: '' }), false);
    assert.equal(produtoNovoMexido({ nome: 'Poltrona', codigo: '' }), true);
    assert.equal(produtoNovoMexido({ nome: '', codigo: 'POLT-1' }), true);
    assert.equal(produtoNovoMexido({ nome: 'Poltrona', codigo: 'POLT-1', criando: true }), false, 'durante o envio, nada a perder');
    assert.equal(produtoNovoMexido({ nome: 'Poltrona', codigo: 'POLT-1', criado: true }), false);

    const novo = lerSemComentarios('resources/js/Components/Trilha/Cadastro/NovoProduto.jsx');
    assert.match(novo, /useGuardaDaGaveta\(guardiao\)/);
    assert.match(novo, /mexido: \(\) => !!agora\.current\.mexido/);
    assert.match(novo, /addEventListener\('beforeunload'/);
});

test('F2 — os hooks da categoria e da ficha são .js sem classe; "Mercado Livre" fora da lib pura', () => {
    for (const f of ['useCategoriaDaTrilha.js', 'useFichaDaTrilha.js']) {
        const fonte = fs.readFileSync(path.resolve(RAIZ, `resources/js/Components/Trilha/Cadastro/${f}`), 'utf8');
        assert.ok(!fonte.includes('className'), f);
    }
    assert.equal(lerSemComentarios('resources/js/lib/trilhaCadastro.js').match(/Mercado\s+Livre/i), null);
});

// ═══ F3 — as fotos e o frete ═══════════════════════════════════════════════

test('F3 — fotosPorCor: sem variação, uma galeria; um eixo, uma por variação; cor E tamanho, uma por COR, na dona', () => {
    // O produto que não varia: uma galeria só, na variação dele.
    assert.deepEqual(fotosPorCor(CADASTRO), [{ chave: 'v91', variacaoId: 91, cor: null, tamanhos: [], fotos: [], onde: 'neste produto' }]);
    assert.deepEqual(fotosPorCor(CADASTRO_COM_FOTOS)[0].fotos.map((f) => f.id), [501, 502]);

    // Duas cores, um eixo: cada cor (cada variação) tem a sua.
    const cadeira = fotosPorCor(CADASTRO_TRAVADO);
    assert.deepEqual(cadeira.map((g) => [g.cor, g.variacaoId, g.onde]), [['Natural', 21, 'nesta cor'], ['Mel', 22, 'nesta cor']]);

    // Cor E tamanho: as fotos são da cor e moram na dona (a 1ª com foto, senão a 1ª) — a Branca no M, a Preta no P.
    const camiseta = fotosPorCor(CADASTRO_CORES_E_TAMANHOS);
    assert.deepEqual(camiseta.map((g) => [g.cor, g.variacaoId, g.tamanhos, g.fotos.length, g.onde]), [
        ['Branca', 122, ['P', 'M'], 3, 'nesta cor'],
        ['Preta', 123, ['P', 'M'], 0, 'nesta cor'],
    ]);
    // Sem a marca do servidor, a régua do Portal (`gruposPorCor`) dá a mesma dona.
    const semMarca = { ...CADASTRO_CORES_E_TAMANHOS, variacoes: CADASTRO_CORES_E_TAMANHOS.variacoes.map(({ dona_da_cor, ...v }) => v) };
    assert.deepEqual(fotosPorCor(semMarca).map((g) => g.variacaoId), [122, 123]);
    // A marca do servidor vale quando difere (ele é quem diz onde as fotos moram).
    const marcada = { ...semMarca, variacoes: semMarca.variacoes.map((v) => ({ ...v, dona_da_cor: v.id === 121 || v.id === 124 })) };
    assert.deepEqual(fotosPorCor(marcada).map((g) => g.variacaoId), [121, 124]);

    // Outro eixo (voltagem): a galeria é da variação.
    const voltagem = { ...CADASTRO_TRAVADO, produto: { ...CADASTRO_TRAVADO.produto, eixo: 'voltagem' } };
    assert.deepEqual(fotosPorCor(voltagem).map((g) => g.onde), ['nesta variação', 'nesta variação']);
    assert.deepEqual(fotosPorCor({ variacoes: [] }), []);
    assert.deepEqual(fotosPorCor(null), []);
});

test('F3 — o contador da galeria ("2 de 3" âmbar, "3 fotos" verde) e a foto grande pela `url_original`', () => {
    assert.deepEqual(contadorDeFotos(0), { texto: '0 de 3', tom: 'warn', completo: false });
    assert.deepEqual(contadorDeFotos(2), { texto: '2 de 3', tom: 'warn', completo: false });
    assert.deepEqual(contadorDeFotos(3), { texto: '3 fotos', tom: 'ok', completo: true });
    assert.deepEqual(contadorDeFotos(7), { texto: '7 fotos', tom: 'ok', completo: true });
    assert.deepEqual(contadorDeFotos(1, 1), { texto: '1 foto', tom: 'ok', completo: true });
    assert.equal(contadorDeFotos('x').texto, '0 de 3');

    const [primeira] = CADASTRO_COM_FOTOS.variacoes[0].fotos;
    assert.equal(urlOriginalDaFoto(primeira), '/trilha/459/fotos/91/501?original=1');
    assert.equal(urlOriginalDaFoto({ url: '/trilha/459/fotos/91/501' }), '/trilha/459/fotos/91/501?original=1');
    assert.equal(urlOriginalDaFoto({ url: '/x?v=2' }), '/x?v=2&original=1');
    assert.equal(urlOriginalDaFoto({}), null);
    assert.equal(textoDoEnvio(1), '1 foto enviada.');
    assert.equal(textoDoEnvio(3), '3 fotos enviadas.');
});

test('F3 — a remessa: vai o que cabe, numa remessa só; o grande demais e o formato errado ficam de fora com aviso; passou do teto, NADA vai', () => {
    const MB = 1024 * 1024;
    const arq = (name, mb = 1) => ({ name, size: mb * MB });

    // Três que servem: vão as três, sem aviso.
    assert.deepEqual(remessaDeFotos([arq('a.jpg'), arq('b.PNG'), arq('c.webp')], 0), { arquivos: [arq('a.jpg'), arq('b.PNG'), arq('c.webp')], avisos: [] });

    // O grande demais (12 MB) e o de formato errado ficam de fora; os outros vão.
    const misto = remessaDeFotos([arq('a.jpg'), arq('grande.jpg', 12), arq('anim.gif')], 1);
    assert.deepEqual(misto.arquivos.map((a) => a.name), ['a.jpg']);
    assert.deepEqual(misto.avisos, [
        '"anim.gif" não está num formato aceito. Envie JPG, PNG ou WebP.',
        '“grande.jpg” tem 12 MB; o limite é 10 MB. Escolha uma foto menor.',
    ]);

    // O teto de tamanho é o do retrato (`limites.foto_mb`).
    const cinco = remessaDeFotos([arq('a.jpg', 6)], 0, { mb: 5 });
    assert.deepEqual(cinco.arquivos, []);
    assert.match(cinco.avisos[0], /o limite é 5 MB/);

    // Mais do que cabe: tudo ou nada, como o servidor — e o aviso diz quantas cabem, no lugar da galeria.
    assert.deepEqual(remessaDeFotos([arq('a.jpg'), arq('b.jpg')], 11), { arquivos: [], avisos: ['Cabem mais 1 foto nesta cor. Escolha menos e envie de novo.'] });
    assert.deepEqual(remessaDeFotos([arq('a.jpg'), arq('b.jpg'), arq('c.jpg')], 9, { limite: 10, onde: 'neste produto' }).avisos,
        ['Cabem mais 1 foto neste produto. Escolha menos e envie de novo.']);
    assert.deepEqual(remessaDeFotos([arq('a.jpg')], 12), { arquivos: [], avisos: ['Já há 12 fotos nesta cor, o máximo. Exclua alguma para enviar outra.'] });
    // Os que ficaram de fora não contam no teto: 2 servem, 1 é grande, cabem 2 → as 2 vão.
    assert.deepEqual(remessaDeFotos([arq('a.jpg'), arq('b.jpg'), arq('c.jpg', 30)], 10).arquivos.map((a) => a.name), ['a.jpg', 'b.jpg']);
    assert.deepEqual(remessaDeFotos([], 0), { arquivos: [], avisos: [] });
    assert.deepEqual(remessaDeFotos(null, 0), { arquivos: [], avisos: [] });
});

test('F3 — aplicarResposta com FOTO e campo mexido: a foto entra (no retrato) e nada do que não foi salvo se perde', () => {
    // A pessoa mexeu no título, no custo, numa caixa e na ficha; enviou 2 fotos antes de salvar.
    const antigo = CADASTRO_COM_CATEGORIA;
    const estado = {
        ...comMudancas(antigo, { produto: { titulo: 'Meu título' }, variacoes: { 91: { custo: '999,00', volumes: [{ c: '51', l: '40', a: '60', kg: '18,5' }] } } }),
        ficha: { ...estadoDoRetrato(antigo).ficha, BRAND: { valor: 'Minha marca', unidade: '' } },
    };
    const novo = {
        ...antigo,
        variacoes: [{ ...antigo.variacoes[0], fotos: CADASTRO_COM_FOTOS.variacoes[0].fotos }],
        situacao: { ...antigo.situacao, faltas: ['ficha: Material principal', 'fotos (2 de 3)'] },
    };
    const junto = aplicarResposta(estado, antigo, novo);

    assert.equal(junto.produto.titulo, 'Meu título');
    assert.equal(junto.variacoes[0].custo, '999,00');
    assert.deepEqual(junto.variacoes[0].volumes, [{ c: '51', l: '40', a: '60', kg: '18,5' }]);
    assert.equal(junto.ficha.BRAND.valor, 'Minha marca', 'a ficha mexida fica');
    assert.deepEqual(camposMexidos(junto, novo), { produto: ['titulo'], variacoes: { 91: ['custo', 'volumes'] } });
    assert.equal(mexido(junto, novo), true, 'o "Salvar" continua com o que mudou');
    assert.equal(fichaMexida(junto, novo), true);
    // As fotos vêm do retrato novo (não moram no estado).
    assert.deepEqual(fotosPorCor(novo)[0].fotos.map((f) => f.id), [501, 502]);
    assert.equal('fotos' in junto.variacoes[0], false);
    // Nada mexido: a foto não liga o "Salvar".
    const limpo = aplicarResposta(estadoDoRetrato(antigo), antigo, novo);
    assert.equal(mexido(limpo, novo), false);
});

test('F3 — o frete: a logística de cada variação como o retrato diz; o ME1 é aviso; caixa não salva segura o "Conferir"', () => {
    assert.deepEqual(logisticaDasVariacoes(CADASTRO), [{ id: 91, nome: '', codigo: 'CRIADO-01', logistica: 'pendente' }]);
    assert.deepEqual(logisticaDasVariacoes(CADASTRO_CORES_E_TAMANHOS).map((v) => [v.nome, v.logistica]), [
        ['Branca · P', 'me2'], ['Branca · M', 'me2'], ['Preta · P', 'me2'], ['Preta · M', 'me1'],
    ]);
    assert.equal(logisticaDasVariacoes({ variacoes: [{ id: 1, logistica: 'qualquer' }] })[0].logistica, 'pendente');
    assert.equal(vaiPorMe1(CADASTRO_ME1), true);
    assert.equal(vaiPorMe1(SALVO_INCOMPLETO), false);

    // O aviso vem do servidor e não é falta: o produto completo tem `faltas` vazio.
    assert.deepEqual(CADASTRO_ME1.situacao.faltas, []);
    assert.deepEqual(avisosEmTexto(CADASTRO_ME1.situacao.avisos), ['vai por ME1 (transportadora)']);
    assert.deepEqual(avisosEmTexto(['a', ' a ', '', null, { texto: 'b' }]), ['a', 'b']);
    assert.deepEqual(avisosEmTexto(undefined), []);

    assert.equal(embalagemMexida(estadoDoRetrato(SALVO_INCOMPLETO), SALVO_INCOMPLETO), false);
    assert.equal(embalagemMexida(comMudancas(SALVO_INCOMPLETO, { variacoes: { 91: { custo: '1,00' } } }), SALVO_INCOMPLETO), false, 'só a caixa conta');
    assert.equal(embalagemMexida(comMudancas(SALVO_INCOMPLETO, { variacoes: { 91: { volumes: [{ c: '1', l: '1', a: '1', kg: '1' }] } } }), SALVO_INCOMPLETO), true);
});

// ═══ F4 (03/11/2026): cores, tamanhos e exclusões ═══════════════════════════
//
// POR QUE EXISTE: a variação sem nome, repetida ou de outro tipo vira, EM SILÊNCIO, um anúncio separado no
// Publicador — a conferência das cores roda na tela antes de gravar, com a régua do servidor. "+ Cor" e "+ Tamanho"
// gravam na hora e levam junto só a ESTRUTURA (a 1ª cor nomeia a variação que já existe no mesmo pedido): o custo
// digitado e não salvo não pode ir junto, nem sumir. E a variação nova vai com custo e caixas EXPLÍCITOS — sem eles
// o servidor copiaria os da 1ª variação do banco, e o tamanho novo de uma cor de custo próprio nasceria errado.

/** A camiseta com a Preta mais cara e de caixa maior ("diferente por cor"). */
const CAMISETA_POR_COR = {
    ...CADASTRO_CORES_E_TAMANHOS,
    variacoes: CADASTRO_CORES_E_TAMANHOS.variacoes.map((v) => (v.valor === 'Preta' ? { ...v, custo: 31.5, volumes: [{ c: 32, l: 26, a: 4, kg: 0.35 }] } : v)),
};

test('F4 — conferirCores: sem valor com 2+, repetida sem caixa nem acento, o par cor·tamanho e o outro tipo', () => {
    const it = (chave, valor, extra = {}) => ({ chave, eixo: 'cor', valor, eixo_2: '', valor_2: '', ...extra });

    // Uma variação só: nada a conferir (ela entra no grupo de qualquer jeito).
    assert.deepEqual(conferirCores([it('v1', '')]), { ok: true, porChave: {}, lista: [] });

    // Sem valor com duas ou mais.
    const vazio = conferirCores([it('v1', 'Bege'), it('v2', '  ')]);
    assert.equal(vazio.ok, false);
    assert.deepEqual(Object.keys(vazio.porChave), ['v2']);
    assert.equal(vazio.porChave.v2.valor, 'Preencha a cor desta variação: com duas ou mais, cada uma precisa da sua.');

    // Repetida sem caixa, sem acento e sem espaço sobrando: a 1ª fica, a outra é marcada.
    const repetida = conferirCores([it('v1', 'Pérola'), it('v2', ' perola  '), it('v3', 'Areia')]);
    assert.deepEqual(repetida.lista, [{ chave: 'v2', campo: 'valor', mensagem: 'A cor “perola” se repete neste produto.' }]);
    assert.equal(valorComparavel('  Pérola   Clara '), 'perola clara');
    assert.equal(valorComparavel('Açaí'), 'acai');

    // Com o 2º tipo: o PAR se repete. Mesmo cartão → o tamanho; outro cartão com a mesma cor → a cor.
    const par = (chave, valor, valor2, grupo) => it(chave, valor, { eixo_2: 'tamanho', valor_2: valor2, grupo });
    assert.deepEqual(conferirCores([par('v1', 'Branca', 'P', 'a'), par('v2', 'Branca', 'p', 'a')]).porChave, { v2: { valor_2: 'O tamanho “p” se repete em Branca.' } });
    assert.deepEqual(conferirCores([par('v1', 'Branca', 'P', 'a'), par('v2', 'BRANCA', 'P', 'b')]).porChave, { v2: { valor: 'A cor “BRANCA” já existe neste produto.' } });
    assert.ok(conferirCores([par('v1', 'Branca', 'P', 'a'), par('v2', 'Branca', 'M', 'a')]).ok, 'Branca P e Branca M são variações diferentes');
    assert.deepEqual(conferirCores([par('v1', 'Branca', 'P', 'a'), par('v2', 'Preta', '', 'b')]).porChave, { v2: { valor_2: 'Preencha o tamanho desta variação.' } });

    // Outro tipo: vale o da maioria (no empate, o primeiro); o diferente é marcado no eixo.
    const tipo = conferirCores([it('v1', 'Bege'), it('v2', 'Areia'), it('v3', '110V', { eixo: 'voltagem' })]);
    assert.deepEqual(tipo.lista, [{ chave: 'v3', campo: 'eixo', mensagem: 'Esta variação usa outro tipo (Voltagem); todas precisam usar o mesmo.' }]);
});

test('F4 — a conferência de uma gravação só barra o que ELA cria (como o servidor); o que já estava gravado só aparece', () => {
    // Já gravado com duas cores iguais (veio de fora): mexer no título não é barrado.
    const gravado = { ...CADASTRO_TRAVADO, variacoes: CADASTRO_TRAVADO.variacoes.map((v) => ({ ...v, valor: 'Natural' })), edicao: { livre: true } };
    const soTitulo = estadoDoRetrato(gravado);
    soTitulo.produto.titulo = 'Outro título';
    const r = conferirGravacaoDasCores(soTitulo, gravado);
    assert.equal(r.ok, true);
    assert.deepEqual(r.porChave, { v22: { valor: 'A cor “Natural” se repete neste produto.' } }, 'mostra o que já estava');

    // Renomear a Preta para "branca": a gravação CRIA a repetição — barra, nas variações da Preta.
    const renomeada = estadoDoRetrato(CADASTRO_CORES_E_TAMANHOS);
    renomeada.variacoes = renomeada.variacoes.map((v) => (v.valor === 'Preta' ? { ...v, valor: 'bránca ' } : v));
    const barrada = conferirGravacaoDasCores(renomeada, CADASTRO_CORES_E_TAMANHOS);
    assert.equal(barrada.ok, false);
    assert.deepEqual(barrada.novos.map((p) => [p.chave, p.campo]), [['v123', 'valor'], ['v124', 'valor']]);

    // "+ Cor" com o nome de uma que já existe: a nova é marcada (vai no mini-formulário).
    const camiseta = estadoDoRetrato(CADASTRO_CORES_E_TAMANHOS);
    const novas = novasDaCor(camiseta, 'Branca');
    const comNova = conferirGravacaoDasCores(camiseta, CADASTRO_CORES_E_TAMANHOS, novas);
    assert.deepEqual(comNova.novos.map((p) => [p.chave, p.campo]), [['nova-1', 'valor'], ['nova-2', 'valor']]);
    assert.equal(comNova.porChave['nova-1'].valor, 'A cor “Branca” já existe neste produto.');
});

test('F4 — "+ Cor": a 1ª cor nomeia a variação que já existe no MESMO pedido; a nova vai com custo e caixas explícitos (os GRAVADOS)', () => {
    // A pessoa escolheu "Cor", deu nome à cor do que já existe e, sem salvar, mudou o custo.
    const estado = estadoDoRetrato(SALVO_INCOMPLETO);
    estado.produto.eixo = 'cor';
    estado.variacoes[0].valor = 'Bege';
    estado.variacoes[0].custo = '999,00';
    const novas = novasDaCor(estado, 'Areia', { codigos: refsSugeridas(estado.variacoes, 1) });
    assert.deepEqual(novas, [{ chave: 'nova-1', valor: 'Areia', valor_2: '', codigo: 'CRIADO-01-2', referencia: 91, grupo: 'nova-cor' }]);

    assert.deepEqual(linhasDaEstrutura(estado, SALVO_INCOMPLETO, novas), [
        // A que já existe: só a estrutura (o eixo e o nome da cor) — o custo mexido e não salvo NÃO vai.
        { chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo 2 gavetas', id: 91, eixo: 'cor', valor: 'Bege' },
        // A nova: tudo explícito, com o custo e as caixas GRAVADOS da referência; o estoque começa vazio.
        {
            chave: 'nova-1', codigo: 'CRIADO-01-2', nome: 'Criado-mudo 2 gavetas', eixo: 'cor', valor: 'Areia', eixo_2: null,
            volumes: [{ c: '50', l: '40', a: '60', kg: '18,5' }], custo: '1890,00', estoque: null,
        },
    ]);

    // O mesmo pelo "Salvar" (`linhasParaSalvar` com a variação nova no estado): a mesma régua; sem `produto_id` nem
    // categoria em texto na linha nova.
    const comNova = estadoDaEstrutura(estado, SALVO_INCOMPLETO, novas);
    const linhas = linhasParaSalvar(comNova, SALVO_INCOMPLETO);
    assert.equal(linhas.length, 2);
    assert.ok(!('produto_id' in linhas[1]) && !('categoria_texto' in linhas[1]) && !('id' in linhas[1]));
    assert.equal(chaveDaVariacao(comNova.variacoes[1]), 'nova-1');
    assert.equal(chaveDaVariacao({ id: 91 }), 'v91');
});

test('F4 — "+ Tamanho" (uma por cor) e "+ Cor" com tamanhos (uma por tamanho): custo e caixas da COR certa, não da 1ª variação', () => {
    const estado = estadoDoRetrato(CAMISETA_POR_COR);
    const tamanhos = novasDoTamanho(CAMISETA_POR_COR, estado, 'G', { codigos: refsSugeridas(estado.variacoes, 2) });
    assert.deepEqual(tamanhos, [
        { chave: 'novo-1', valor: 'Branca', valor_2: 'G', codigo: 'CAM-BR-P-2', referencia: 121, grupo: 'v121' },
        { chave: 'novo-2', valor: 'Preta', valor_2: 'G', codigo: 'CAM-BR-P-3', referencia: 123, grupo: 'v123' },
    ]);
    const linhas = linhasDaEstrutura(estado, CAMISETA_POR_COR, tamanhos);
    assert.deepEqual(linhas.map((l) => [l.chave, l.valor, l.valor_2, l.eixo, l.eixo_2, l.custo, l.volumes]), [
        ['novo-1', 'Branca', 'G', 'cor', 'tamanho', '25,00', [{ c: '30', l: '25', a: '3', kg: '0,3' }]],
        ['novo-2', 'Preta', 'G', 'cor', 'tamanho', '31,50', [{ c: '32', l: '26', a: '4', kg: '0,35' }]],
    ], 'só as novas: nada da estrutura mudou');

    // "+ Cor" com cor E tamanho: uma variação por tamanho, com o custo do mesmo tamanho na 1ª cor que o tem.
    const cores = novasDaCor(estado, 'Azul', { codigos: ['CAM-AZ-P', 'CAM-AZ-M'] });
    assert.deepEqual(cores.map((n) => [n.chave, n.valor, n.valor_2, n.codigo, n.referencia]), [['nova-1', 'Azul', 'P', 'CAM-AZ-P', 121], ['nova-2', 'Azul', 'M', 'CAM-AZ-M', 122]]);
});

test('F4 — refsSugeridas: a régua do Portal (o menor número livre a partir de 2), uma diferente da outra', () => {
    assert.deepEqual(refsSugeridas([{ codigo: 'POLT-RIP' }], 3), ['POLT-RIP-2', 'POLT-RIP-3', 'POLT-RIP-4']);
    // O número que já existe (sem caixa) é pulado; o que foi excluído volta a ser oferecido.
    assert.deepEqual(refsSugeridas([{ codigo: 'POLT-RIP' }, { codigo: 'polt-rip-2' }, { codigo: 'POLT-RIP-4' }], 2), ['POLT-RIP-3', 'POLT-RIP-5']);
    assert.deepEqual(refsSugeridas([{ codigo: 'X' }], 1, { usadas: ['X-2'] }), ['X-3'], 'os SKUs já digitados no formulário contam');
    assert.deepEqual(refsSugeridas([{ codigo: '' }], 2), ['', ''], 'sem Ref na 1ª, nada a sugerir');
    assert.deepEqual(refsSugeridas([], 1), ['']);
});

test('F4 — os cartões: com cor E tamanho, um por COR (o valor GRAVADO: não pula enquanto se digita); um tipo só, um por variação', () => {
    const estado = estadoDoRetrato(CADASTRO_CORES_E_TAMANHOS);
    estado.variacoes[0].valor = 'Branca Neve';
    const cartoes = cartoesDasVariacoes(CADASTRO_CORES_E_TAMANHOS, estado);
    assert.deepEqual(cartoes.map((c) => [c.chave, c.ids, c.dona, c.valor]), [['v121', [121, 122], 122, 'Branca Neve'], ['v123', [123, 124], 123, 'Preta']]);
    assert.deepEqual(cartoes[0].galeria, { chave: 'v121', variacaoId: 122, cor: 'Branca', tamanhos: ['P', 'M'], fotos: CADASTRO_CORES_E_TAMANHOS.variacoes[1].fotos, onde: 'nesta cor' });
    assert.deepEqual(cartoes[0].linhas.map((l) => [l.id, l.valor_2, l.codigo]), [[121, 'P', 'CAM-BR-P'], [122, 'M', 'CAM-BR-M']]);

    const umTipo = cartoesDasVariacoes(CADASTRO_TRAVADO, estadoDoRetrato(CADASTRO_TRAVADO));
    assert.deepEqual(umTipo.map((c) => [c.chave, c.ids, c.valor]), [['v21', [21], 'Natural'], ['v22', [22], 'Mel']]);
    assert.deepEqual(cartoesDasVariacoes(CADASTRO, estadoDoRetrato(CADASTRO)).map((c) => c.galeria.onde), ['neste produto']);
    assert.deepEqual(itensParaConferir(estado, CADASTRO_CORES_E_TAMANHOS).map((i) => i.grupo), ['v121', 'v121', 'v123', 'v123']);
});

test('F4 — "Este produto varia?": o modo pelo eixo; trocar o tipo é conteúdo (grava no "Salvar") e tem as suas travas', () => {
    assert.equal(modoDasVariacoes({ eixo: null }, 1), 'nao');
    assert.equal(modoDasVariacoes({ eixo: '' }, 3), 'sem-tipo');
    assert.equal(modoDasVariacoes({ eixo: 'cor' }, 2), 'cor');
    assert.equal(modoDasVariacoes({ eixo: 'cor', eixo_2: 'tamanho' }, 4), 'cor-tamanho');
    assert.equal(modoDasVariacoes({ eixo: 'voltagem' }, 2), 'outro');
    assert.equal(modoDasVariacoes({ eixo: 'voltagem', eixo_2: 'tamanho' }, 2), 'dois');

    // Não varia → Cor (uma variação): troca direto, e o estado fica mexido (vai no "Salvar", com o eixo na linha).
    const um = trocaDeEixo(estadoDoRetrato(SALVO_INCOMPLETO), { eixo: 'cor', eixo_2: '' });
    assert.ok(um.pode && um.mudou && !um.confirmar);
    assert.equal(estruturaMexida(um.estado, SALVO_INCOMPLETO), true);
    um.estado.variacoes[0].valor = 'Bege';
    assert.deepEqual(linhasParaSalvar(um.estado, SALVO_INCOMPLETO), [{ chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo 2 gavetas', id: 91, eixo: 'cor', valor: 'Bege' }]);
    // E de volta para "não varia": o eixo e o valor saem (null = limpar).
    const corGravada = { ...SALVO_INCOMPLETO, produto: { ...SALVO_INCOMPLETO.produto, eixo: 'cor' }, variacoes: [{ ...SALVO_INCOMPLETO.variacoes[0], valor: 'Bege' }] };
    const nao = trocaDeEixo(estadoDoRetrato(corGravada), { eixo: '', eixo_2: '' });
    assert.equal(nao.estado.variacoes[0].valor, '');
    assert.deepEqual(linhasParaSalvar(nao.estado, corGravada), [{ chave: 'v91', codigo: 'CRIADO-01', nome: 'Criado-mudo 2 gavetas', id: 91, eixo: null, valor: null }]);

    // "Não varia" com duas variações: não dá.
    const duas = trocaDeEixo(estadoDoRetrato(CADASTRO_TRAVADO), { eixo: '', eixo_2: '' });
    assert.deepEqual([duas.pode, duas.motivo], [false, 'Para o produto não variar, ele fica com uma variação só: remova as outras antes.']);
    // Tirar o tamanho com cor de dois tamanhos: a cor se repetiria — não dá.
    const camiseta = trocaDeEixo(estadoDoRetrato(CADASTRO_CORES_E_TAMANHOS), { eixo: 'cor', eixo_2: '' });
    assert.deepEqual([camiseta.pode, camiseta.motivo], [false, 'Há cor com mais de uma variação de tamanho: remova as que sobram antes (fica uma por cor).']);
    // Um tamanho por cor: pede confirmação (e só então troca); o 2º valor sai de todas.
    const umPorCor = { ...CADASTRO_CORES_E_TAMANHOS, variacoes: [CADASTRO_CORES_E_TAMANHOS.variacoes[0], CADASTRO_CORES_E_TAMANHOS.variacoes[2]] };
    const tirar = trocaDeEixo(estadoDoRetrato(umPorCor), { eixo: 'cor', eixo_2: '' });
    assert.deepEqual([tirar.pode, tirar.confirmar], [true, 'O tamanho sai de todas as variações (P) quando você salvar.']);
    assert.deepEqual(tirar.estado.variacoes.map((v) => v.valor_2), ['', '']);
    assert.deepEqual(linhasParaSalvar(tirar.estado, umPorCor).map((l) => [l.chave, l.eixo_2, l.valor_2]), [['v121', null, null], ['v123', null, null]]);
});

test('F4 — custo e caixas: "iguais em todas as cores" quando o gravado é igual; igualar copia da 1ª; "por cor" iguala dentro da cor', () => {
    assert.equal(custoECaixasIguais(CADASTRO_CORES_E_TAMANHOS.variacoes), true);
    assert.equal(custoECaixasIguais(CAMISETA_POR_COR.variacoes), false);
    assert.equal(custoECaixasIguais(estadoDoRetrato(CADASTRO_CORES_E_TAMANHOS).variacoes), true, '"25" e "25,00" são o mesmo custo');
    assert.equal(custoECaixasIguais([]), true);

    const estado = estadoDoRetrato(CAMISETA_POR_COR);
    const iguais = comCustoECaixasIguais(estado);
    assert.deepEqual(iguais.variacoes.map((v) => v.custo), ['25,00', '25,00', '25,00', '25,00']);
    assert.deepEqual(camposMexidos(iguais, CAMISETA_POR_COR).variacoes, { 123: ['custo', 'volumes'], 124: ['custo', 'volumes'] });

    // "Por cor": o tamanho novo da Preta (nasceu com os GRAVADOS) passa a ter o custo da Preta que está na tela.
    const naTela = estadoDoRetrato(CAMISETA_POR_COR);
    naTela.variacoes = naTela.variacoes.map((v) => (v.id === 123 || v.id === 124 ? { ...v, custo: '33,00' } : v));
    const comG = { ...CAMISETA_POR_COR, variacoes: [...CAMISETA_POR_COR.variacoes, { ...CAMISETA_POR_COR.variacoes[2], id: 125, codigo: 'CAM-PR-G', valor_2: 'G', dona_da_cor: false }] };
    const juntos = aplicarResposta(naTela, CAMISETA_POR_COR, comG);
    assert.equal(juntos.variacoes.find((v) => v.id === 125).custo, '31,50', 'a nova entra como veio');
    assert.deepEqual(custoIgualEmCadaCor(juntos, comG).variacoes.map((v) => v.custo), ['25,00', '25,00', '33,00', '33,00', '33,00']);
});

test('F4 — aplicarResposta depois de "+ Cor": a cor nova entra, a estrutura da tela vira a gravada e o custo não salvo fica', () => {
    const estado = estadoDoRetrato(SALVO_INCOMPLETO);
    estado.produto.eixo = 'cor';
    estado.variacoes[0].valor = 'Bege';
    estado.variacoes[0].custo = '999,00';
    const novo = {
        ...SALVO_INCOMPLETO,
        produto: { ...SALVO_INCOMPLETO.produto, eixo: 'cor' },
        variacoes: [{ ...SALVO_INCOMPLETO.variacoes[0], valor: 'Bege' }, { ...SALVO_INCOMPLETO.variacoes[0], id: 92, codigo: 'CRIADO-01-2', valor: 'Areia', estoque: null }],
        mapear_de_novo: false,
    };
    const junto = aplicarResposta(estado, SALVO_INCOMPLETO, novo);
    assert.deepEqual(junto.variacoes.map((v) => [v.id, v.valor, v.custo]), [[91, 'Bege', '999,00'], [92, 'Areia', '1890,00']]);
    assert.equal(estruturaMexida(junto, novo), false, 'a estrutura já é a gravada');
    assert.deepEqual(camposMexidos(junto, novo), { produto: [], variacoes: { 91: ['custo'] } });
});

test('F4 — os erros do 422 das cores pelo lugar na gaveta: `linhas.{i}.campo` pela linha mandada, `variacoes.{id}`, e os `erros[]`', () => {
    const linhas = [{ chave: 'v91', id: 91 }, { chave: 'nova-1', codigo: 'X-2' }];
    const falha = {
        response: {
            status: 422,
            data: {
                message: 'Confira as variações marcadas.',
                errors: { 'linhas.1.valor': ['“Bege” já existe neste produto: cada variação precisa de um valor diferente.'], 'variacoes.93.valor': ['Falta a cor desta variação.'], outra: ['Solta.'] },
                erros: [
                    { indice: 1, chave: 'nova-1', variacao_id: null, codigo: 'X-2', motivo: 'repetida', mensagem: 'x', campos: { valor: '“Bege” já existe neste produto: cada variação precisa de um valor diferente.' } },
                    { indice: null, chave: null, variacao_id: 93, codigo: 'X-3', motivo: 'sem_valor', mensagem: 'x', campos: { valor: 'Falta a cor desta variação.' } },
                    { indice: 0, chave: 'v91', variacao_id: 91, codigo: 'X', motivo: 'outro_tipo', mensagem: 'x', campos: { eixo_2: 'Esta variação é por Cor, e as outras por Cor e Tamanho.' } },
                ],
            },
        },
    };
    assert.deepEqual(errosDasLinhasNo422(falha, linhas), {
        porChave: {
            'nova-1': { valor: '“Bege” já existe neste produto: cada variação precisa de um valor diferente.' },
            v93: { valor: 'Falta a cor desta variação.' },
            v91: { eixo_2: 'Esta variação é por Cor, e as outras por Cor e Tamanho.' },
        },
        soltas: ['Solta.'],
    });
    assert.equal(errosDasLinhasNo422({ response: { status: 409, data: {} } }, linhas), null);
    // O erro de linha só com o id da variação (sem chave) também acha o lugar.
    assert.deepEqual(errosPorCampo([{ chave: null, variacao_id: 22, campos: { valor_2: 'Falta o tamanho.' } }]).porChave, { v22: { valor_2: 'Falta o tamanho.' } });
});

test('F4 — o aviso da variação nova num produto mapeado: o `mapear_de_novo` do servidor manda', () => {
    assert.equal(avisoDaCorNova({ mapear_de_novo: true }), AVISO_DA_COR_NOVA_NO_MAPEADO);
    assert.equal(AVISO_DA_COR_NOVA_NO_MAPEADO, 'Cor nova salva. Este produto já foi mapeado: mapeie de novo para a cor ganhar os combos.');
    assert.equal(avisoDaCorNova({ mapear_de_novo: true }, { cor: false }), AVISO_DA_VARIACAO_NOVA_NO_MAPEADO);
    assert.equal(avisoDaCorNova({ mapear_de_novo: false, situacao: { mapeado_em: '2026-11-01' }, totais: { criadas: 1 } }), null);
    // Sem a chave: o retrato diz (variação criada num produto com a marca de mapeado).
    assert.equal(avisoDaCorNova({ situacao: { mapeado_em: '2026-11-01' }, totais: { criadas: 1 } }), AVISO_DA_COR_NOVA_NO_MAPEADO);
    assert.equal(avisoDaCorNova({ situacao: { mapeado_em: null }, totais: { criadas: 1 } }), null);
});

test('F4 — "Excluir produto": os textos da prévia (o que sai junto), as montadas confirmadas e o "mudou no meio do caminho"', () => {
    const previa = {
        produtos: [{ id: 12, nome: 'Camiseta básica', codigo: null, variacoes: 4, em_uso: false }],
        montadas: [{ id: 70, sku: 'CAM-BR-P-CB2', nome: 'Combo 2 · Camiseta', fase: 'combo', em_uso: false }, { id: 71, sku: 'KT-9', nome: null, fase: 'kit', em_uso: true }],
        totais: { produtos: 1, variacoes: 4, montadas: 2, em_uso: 1 },
        nao_encontrados: 0,
    };
    assert.deepEqual(textosDaExclusao(previa), {
        frase: 'O produto sai do cadastro com 4 variações, as fotos e a ficha técnica. Não dá para desfazer.',
        montadas: [{ id: 70, tipo: 'Combo', sku: 'CAM-BR-P-CB2', nome: 'Combo 2 · Camiseta' }, { id: 71, tipo: 'Kit', sku: 'KT-9', nome: '' }],
        fraseDasMontadas: '2 ofertas montadas usam este produto e saem junto:',
        emUso: 'Parte disto já está ligada a anúncio ou a preparo: o que estava ligado fica solto.',
        botao: 'Excluir o produto e as 2 ofertas',
    });
    assert.deepEqual(montadasDaPrevia(previa), [70, 71]);
    const sozinho = textosDaExclusao({ produtos: [{ id: 9, variacoes: 1 }], montadas: [], totais: { variacoes: 1, montadas: 0, em_uso: 0 } });
    assert.deepEqual([sozinho.frase, sozinho.fraseDasMontadas, sozinho.emUso, sozinho.botao], [
        'O produto sai do cadastro com 1 variação, as fotos e a ficha técnica. Não dá para desfazer.', null, null, 'Excluir produto',
    ]);
    assert.deepEqual(montadasDaPrevia({ montadas: [] }), []);
    assert.equal(exclusaoMudou({ response: { status: 422, data: { errors: { montadas: ['O que sai junto mudou.'] } } } }), true);
    assert.equal(exclusaoMudou({ response: { status: 422, data: { errors: { produto: ['x'] } } } }), false);
});

// ─── F6: a aba velha ────────────────────────────────────────────────────────

test('F6 — os dois 409: o da TRAVA (`travado`) vira só leitura; o da VERSÃO (sem `travado`) pede "Recarregar"', () => {
    const erro = (status, data) => ({ isAxiosError: true, response: { status, data } });
    const daTrava = erro(409, { travado: true, motivo: 'A oferta “Cadeira” já saiu do Planejamento.', message: 'A oferta “Cadeira” já saiu do Planejamento.' });
    const daVersao = erro(409, {
        versao_atual: 'b'.repeat(40),
        message: 'Este produto mudou depois que você abriu (outra pessoa salvou nele). Recarregue para ver o que mudou.',
    });

    assert.deepEqual([travadoNo409(daTrava), conflitoDeVersao(daTrava)], [true, false]);
    assert.deepEqual([travadoNo409(daVersao), conflitoDeVersao(daVersao)], [false, true]);
    // Nenhum dos dois fora do 409 (o 422 da categoria, a rede, o 404 de quem já sumiu).
    for (const outro of [erro(422, { errors: { categoria: ['x'] } }), erro(404, {}), new Error('rede'), null]) {
        assert.deepEqual([travadoNo409(outro), conflitoDeVersao(outro)], [false, false]);
    }

    // O texto do rodapé é o do servidor; sem ele, o nosso.
    assert.equal(textoDoConflito(daVersao), daVersao.response.data.message);
    assert.equal(textoDoConflito(erro(409, {})), MENSAGEM_DA_VERSAO);
    assert.equal(textoDoConflito(null), MENSAGEM_DA_VERSAO);
    assert.match(MENSAGEM_DA_VERSAO, /^Este produto mudou depois que você abriu/);
});

test('F6 — a versão que a gaveta conhece: a escrita CONFERIDA adota a da resposta; foto e frete não — e a diferente é o produto mudado por fora', () => {
    const v1 = 'a'.repeat(40);
    const v2 = 'b'.repeat(40);

    // Linhas, ficha, remover cor: o pedido levou a versão e passou — a resposta é desta gravação.
    assert.deepEqual(versaoDepoisDe(v1, { versao: v2 }, { conferida: true }), { versao: v2, mudou: false });
    // Foto e frete não mudam a versão: a mesma, nada a dizer.
    assert.deepEqual(versaoDepoisDe(v1, { versao: v1 }), { versao: v1, mudou: false });
    // Foto que volta com OUTRA versão: alguém gravou por fora. A gaveta fica com a dela (o próximo "Salvar" leva o 409) e avisa já.
    assert.deepEqual(versaoDepoisDe(v1, { versao: v2 }), { versao: v1, mudou: true });
    // Sem versão conhecida (o retrato não tinha) ou sem versão na resposta.
    assert.deepEqual(versaoDepoisDe(null, { versao: v2 }), { versao: v2, mudou: false });
    assert.deepEqual(versaoDepoisDe(v1, { mensagem: 'ok' }), { versao: v1, mudou: false });
    assert.deepEqual(versaoDepoisDe(v1, null, { conferida: true }), { versao: v1, mudou: false });
});

test('F6 — o "Salvar" encadeia a versão (linhas → ficha) e, no 409 da versão na ficha, diz que as linhas JÁ gravaram e com qual versão', async () => {
    const estado = comMudancas(CADASTRO_TRAVADO, { produto: { titulo: 'Novo' } });
    const enviadas = [];
    const versaoDaFicha = { isAxiosError: true, response: { status: 409, data: { versao_atual: 'x'.repeat(40), message: 'Este produto mudou depois que você abriu…' } } };
    const r = await gravarCadastro({
        estado,
        retrato: CADASTRO_TRAVADO,
        versao: 'v-da-gaveta',
        enviarLinhas: async (linhas, { versao }) => {
            enviadas.push(['linhas', versao]);

            return { ...CADASTRO_TRAVADO, erros: [], avisos: [], versao: 'v-das-linhas' };
        },
        gravarFicha: async ({ versao }) => {
            enviadas.push(['ficha', versao]);
            throw versaoDaFicha;
        },
        fichaMudou: true,
    });

    assert.deepEqual(enviadas, [['linhas', 'v-da-gaveta'], ['ficha', 'v-das-linhas']]);
    assert.deepEqual([r.ok, r.passo, r.linhas, r.versao], [false, 'ficha', true, 'v-das-linhas']);
    assert.equal(conflitoDeVersao(r.falha), true);
    assert.equal(temRetrato(r.resposta), true, 'o retrato das linhas vem junto: a gaveta mostra o que já gravou');

    // O 409 no 1º pedido: nada gravou, e a versão continua a da gaveta.
    const logo = await gravarCadastro({ estado, retrato: CADASTRO_TRAVADO, versao: 'v-da-gaveta', enviarLinhas: async () => { throw versaoDaFicha; } });
    assert.deepEqual([logo.passo, logo.linhas, logo.versao, logo.resposta], ['linhas', true, 'v-da-gaveta', null]);
});
