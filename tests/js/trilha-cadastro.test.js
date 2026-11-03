import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { CADASTRO, CADASTRO_TRAVADO, PROPS_TRABALHO, SALVO_COMPLETO, SALVO_COM_ERRO, SALVO_INCOMPLETO, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import {
    CHAVE_NOVO_PRODUTO,
    MENSAGEM_DOS_CAMPOS,
    aplicarResposta,
    avisoDaOndaComecada,
    camposMexidos,
    custoParaTexto,
    depoisDeSalvar,
    deveFecharNoEsc,
    errosPorCampo,
    estadoDoRetrato,
    gravarCadastro,
    linhasParaSalvar,
    mexido,
    nadaMudou,
    nomeNovo,
    opcoesQueCasam,
    resultadoDoSalvar,
    temRetrato,
} from '../../resources/js/lib/trilhaCadastro.js';
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
        produto: { nome: 'Criado-mudo 2 gavetas', titulo: '', familia: '', ambientes: [], descricao: '' },
        variacoes: [{ id: 91, codigo: 'CRIADO-01', custo: '', estoque: '', volumes: [] }],
    });
    assert.deepEqual(estadoDoRetrato(SALVO_INCOMPLETO).variacoes[0], {
        id: 91, codigo: 'CRIADO-01', custo: '1890,00', estoque: '4', volumes: [{ c: '50', l: '40', a: '60', kg: '18,5' }],
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
    assert.deepEqual(depois.variacoes[0], { id: 1, codigo: 'POLT-RIP', custo: '1890,50', estoque: '4', volumes: [{ c: '60', l: '40', a: '30', kg: '8,5' }] });

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
    assert.deepEqual(semDiferenca.totais, { criadas: 0, atualizadas: 0, sem_mudanca: 2, com_erro: 0 });
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
    assert.deepEqual(errosPorCampo([
        { chave: 'v91', mensagem: 'O título passa do limite.', campos: { titulo: 'O título passa do limite.', categoria: 'Categoria não encontrada.' } },
        { chave: 'v92', mensagem: 'Não deu para gravar esta linha agora.', campos: { linha: 'Não deu para gravar esta linha agora.' } },
        { chave: null, mensagem: 'Erro sem campo.', campos: {} },
    ]), {
        porChave: { v91: { titulo: 'O título passa do limite.' } },
        soltas: ['Categoria não encontrada.', 'Não deu para gravar esta linha agora.', 'Erro sem campo.'],
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
    assert.equal(etapaDaTrilha('lista', true).pronto, etapaDaTrilha('lista', false).pronto, 'o "Pronto quando" é a mesma régua');

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
