import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import { CANDIDATAS, PREVIA_DA_PLANILHA, PROPS_TRABALHO, RESULTADO_DA_PLANILHA, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { parametrosDoModelo } from '../../resources/js/lib/modeloCompleto.js';
import {
    ARQUIVO_DA_TRILHA,
    LIMITE_DO_ARQUIVO,
    MSG_ARQUIVO_GRANDE,
    conferirArquivo,
    contagemDosFiltros,
    contarPrevia,
    copiaDoArquivo,
    erroDoArquivo,
    erroDoBaixar,
    filtrarProdutos,
    formularioDaPrevia,
    formularioDoGravar,
    jsonDoCorpo,
    linhasEmTexto,
    ondeEmTexto,
    pilulasDoResumo,
    planilhaPendente,
    podeGravar,
    produtosPorAba,
    resumoDaPrevia,
    rotuloDaSituacao,
    situacaoDoProduto,
    textoDoGravar,
    variacoesEmTexto,
    versoesDaPrevia,
} from '../../resources/js/lib/trilhaPlanilha.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 3ª entrega, fatia F7: a planilha por categoria (a TELA).
//
// POR QUE EXISTE: a gaveta `e:planilha` conversa com o servidor por um contrato
// só (§17.4 do desenho — a prévia e o gravar têm a MESMA forma) e manda de volta
// o MESMO arquivo com a versão de cada produto que já existia (F6). Um nome de
// campo trocado num lado só, ou o `versoes[…]` montado errado, faria o gravar
// recusar tudo (ou, pior, gravar por cima da aba velha) sem erro nenhum na tela.
// Aqui: as regras puras (`lib/trilhaPlanilha.js`), as chamadas (URL, método e
// corpo, com o axios de mentira), a gaveta DESENHADA com o JSON do contrato, a
// chave `e:planilha` na URL (só para quem escreve) e o `lazy` com o 2º caminho
// (learnings §30: sem ele a página some do manifest do Vite e o build sai 0).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;
global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

/**
 * Monta os arquivos da tela com o esbuild (o Inertia é de mentira). O axios fica de FORA: é o mesmo que este teste
 * importa, e é nele que as chamadas são trocadas. Tudo montado ANTES dos testes.
 */
async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-planilha-inertia-stub-${process.pid}.mjs`);
    fs.writeFileSync(stub, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: (o) => o?.onFinish?.(), visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
    const modulos = {};
    try {
        for (const [nome, relativo] of Object.entries(arquivos)) {
            const resultado = await esbuild.build({
                entryPoints: [path.resolve(RAIZ, relativo)],
                bundle: true,
                format: 'esm',
                platform: 'node',
                jsx: 'automatic',
                write: false,
                logLevel: 'silent',
                loader: { '.css': 'empty' },
                alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
                external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios'],
            });
            const saida = path.join(__dirname, `.trilha-planilha-${nome}-${process.pid}-${Date.now()}.mjs`);
            fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
            try {
                modulos[nome] = await import(pathToFileURL(saida).href);
            } finally {
                fs.rmSync(saida, { force: true });
            }
        }
    } finally {
        fs.rmSync(stub, { force: true });
    }

    return modulos;
}

const M = await montarTodos({
    escrita: 'resources/js/lib/trilhaEscrita.js',
    estado: 'resources/js/Components/Trilha/estadoDaTela.js',
    trilha: 'resources/js/lib/trilha.js',
    quadros: 'resources/js/Components/Trilha/Cadastro/QuadrosDaLista.jsx',
    planilha: 'resources/js/Components/Trilha/Cadastro/PlanilhaPorCategoria.jsx',
    categoria: 'resources/js/Components/Trilha/Cadastro/useCategoriaDaTrilha.js',
    pagina: 'resources/js/Pages/Trilha/Empresa.jsx',
});

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

const texto = (s) => s
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ');

/** A primeira tag `<nome …>` que contém `marca`. */
const tag = (html, marca, nome = 'button') => (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).find((t) => t.includes(marca)) ?? '';

/** Nada da trilha cita o Portal nem o Mercado Livre (a trilha SUBSTITUI o Portal e vai para a porta do cliente). */
const PROIBIDO = /Portal|\/portal|Mercado\s+Livre|Publicador/i;

/** Um arquivo .xlsx de mentira, com `n` bytes. */
const xlsx = (nome = 'camisetas.xlsx', n = 2048) => new File([new Uint8Array(n)], nome, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });

/** O estado da vista: o parado da gaveta com o que o teste muda por cima. */
const estado = (extra = {}) => ({ ...M.planilha.PLANILHA_PARADA, ...extra });
const desenharVista = (extra = {}, props = {}) => renderToStaticMarkup(h(M.planilha.VistaDaPlanilha, { p: estado(extra), onAbrir: () => {}, ...props }));

const CAMISETAS = { id: 'MLB31447', nome: 'Camisetas', caminho: 'Calçados, Roupas e Bolsas > Camisetas e Regatas > Camisetas' };
const CRIADOS = { id: 'MLB1902', nome: 'Criados-mudos', caminho: 'Casa, Móveis e Decoração > Quarto > Criados-mudos' };

// ─── As regras puras ────────────────────────────────────────────────────────

test('o arquivo é conferido antes de enviar: .xlsx, não vazio e até 2 MB', () => {
    assert.equal(conferirArquivo(null), 'Escolha a planilha preenchida.');
    assert.match(conferirArquivo(xlsx('camisetas.xls')), /\.xlsx/);
    assert.match(conferirArquivo(xlsx('camisetas.csv')), /\.xlsx/);
    assert.equal(conferirArquivo(xlsx('vazia.xlsx', 0)), 'O arquivo está vazio.');
    assert.match(conferirArquivo({ name: 'grande.xlsx', size: LIMITE_DO_ARQUIVO + 1 }), /limite é 2 MB/);
    assert.equal(conferirArquivo({ name: 'no-limite.xlsx', size: LIMITE_DO_ARQUIVO }), null);
    assert.equal(conferirArquivo(xlsx('CAMISETAS.XLSX')), null, 'a extensão sem caixa');
});

test('o corpo do gravar: o MESMO arquivo e `versoes[produto_id]=versao` só dos que já existiam na prévia', () => {
    const arquivo = xlsx();
    const previa = [...formularioDaPrevia(arquivo).entries()];
    assert.equal(previa.length, 1);
    assert.equal(previa[0][0], 'arquivo');
    assert.equal(previa[0][1].name, 'camisetas.xlsx');

    assert.deepEqual(versoesDaPrevia(PREVIA_DA_PLANILHA), { 9: 'a'.repeat(40), 12: 'b'.repeat(40), 2: 'c'.repeat(40) });
    const gravar = [...formularioDoGravar(arquivo, PREVIA_DA_PLANILHA).entries()];
    assert.deepEqual(gravar.map(([chave]) => chave), ['arquivo', 'versoes[2]', 'versoes[9]', 'versoes[12]']);
    assert.equal(gravar[0][1].name, 'camisetas.xlsx');
    assert.deepEqual(Object.fromEntries(gravar.slice(1)), { 'versoes[2]': 'c'.repeat(40), 'versoes[9]': 'a'.repeat(40), 'versoes[12]': 'b'.repeat(40) });

    // O produto novo (sem id) e o existente sem versão não levam nada; sem prévia, só o arquivo.
    assert.deepEqual(versoesDaPrevia({ produtos: [{ produto_id: null, versao: 'x' }, { produto_id: 5, versao: null }, { produto_id: 6, versao: '' }] }), {});
    assert.deepEqual([...formularioDoGravar(arquivo, null).keys()], ['arquivo']);
});

test('a cópia do arquivo guarda os bytes da escolha (o gravar manda exatamente o que a prévia viu)', async () => {
    const original = new File([new Uint8Array([80, 75, 3, 4, 9])], 'camisetas.xlsx', { type: 'application/octet-stream', lastModified: 123 });
    const copia = await copiaDoArquivo(original);
    assert.notEqual(copia, original);
    assert.equal(copia.name, 'camisetas.xlsx');
    assert.equal(copia.size, 5);
    assert.deepEqual([...new Uint8Array(await copia.arrayBuffer())], [80, 75, 3, 4, 9]);
    assert.equal(await copiaDoArquivo(null), null);
});

test('as contas vêm da LISTA (não dos `totais`), e erro ⇔ recusado', () => {
    assert.deepEqual(contarPrevia(PREVIA_DA_PLANILHA), { novos: 1, atualizados: 1, sem_mudanca: 1, recusados: 2, total: 5, gravaveis: 2, deFora: 2 });
    // Sem a lista, os totais.
    assert.deepEqual(contarPrevia({ totais: { novos: 3, atualizados: 0, sem_mudanca: 1, recusados: 1 } }).gravaveis, 3);
    assert.equal(contarPrevia(null).total, 0);

    // O produto com erro é recusado, diga a situação o que disser; situação estranha sem erro = sem mudança.
    assert.equal(situacaoDoProduto({ situacao: 'novo', erros: [{ linha: 4, coluna: null, mensagem: 'x' }] }), 'recusado');
    assert.equal(situacaoDoProduto({ situacao: 'qualquer' }), 'sem_mudanca');
    assert.equal(situacaoDoProduto({ situacao: 'atualizado', erros: [] }), 'atualizado');

    assert.deepEqual(rotuloDaSituacao({ situacao: 'novo' }), { chave: 'novo', rotulo: 'Novo', tom: 'ok' });
    assert.deepEqual(rotuloDaSituacao({ situacao: 'novo' }, true), { chave: 'novo', rotulo: 'Criado', tom: 'ok' });
    assert.equal(rotuloDaSituacao({ situacao: 'recusado', erros: [{}] }).rotulo, 'Fica de fora');
    assert.equal(rotuloDaSituacao({ situacao: 'recusado', erros: [{}] }, true).rotulo, 'Ficou de fora');
    assert.equal(rotuloDaSituacao({ situacao: 'sem_mudanca' }).rotulo, 'Sem mudança');
});

test('o botão do rodapé: "Gravar N produtos (M ficam de fora)", no singular e no "nada para gravar"', () => {
    assert.equal(textoDoGravar(PREVIA_DA_PLANILHA), 'Gravar 2 produtos (2 ficam de fora)');
    const so = (...situacoes) => ({ produtos: situacoes.map((s) => (s === 'recusado' ? { situacao: s, erros: [{}] } : { situacao: s })) });
    assert.equal(textoDoGravar(so('novo')), 'Gravar 1 produto');
    assert.equal(textoDoGravar(so('novo', 'recusado')), 'Gravar 1 produto (1 fica de fora)');
    assert.equal(textoDoGravar(so('novo', 'atualizado', 'sem_mudanca')), 'Gravar 2 produtos', 'o sem mudança não conta: não grava nada');
    assert.equal(textoDoGravar(so('recusado', 'recusado')), 'Nada para gravar');
    assert.equal(textoDoGravar(so('sem_mudanca')), 'Nada para gravar');

    assert.equal(podeGravar(PREVIA_DA_PLANILHA), true);
    assert.equal(podeGravar(RESULTADO_DA_PLANILHA), false, 'o resultado não grava de novo');
    assert.equal(podeGravar(so('recusado')), false);
    assert.equal(podeGravar(null), false);
});

test('o resumo: a frase do servidor quando vem; as pílulas pela lista, no passado depois de gravar', () => {
    assert.equal(resumoDaPrevia(PREVIA_DA_PLANILHA), PREVIA_DA_PLANILHA.mensagem);
    assert.equal(resumoDaPrevia({ ...PREVIA_DA_PLANILHA, mensagem: '' }), '1 novo, 1 atualizado, 1 sem mudança e 2 ficam de fora.');
    assert.equal(resumoDaPrevia({ ...RESULTADO_DA_PLANILHA, mensagem: null }), '1 criado, 1 atualizado, 1 sem mudança e 2 ficaram de fora.');
    assert.equal(resumoDaPrevia({ produtos: [] }), 'Nenhum produto na planilha.');
    assert.equal(resumoDaPrevia({ produtos: [{ situacao: 'novo' }] }), '1 novo.');

    assert.deepEqual(pilulasDoResumo(PREVIA_DA_PLANILHA).map((p) => [p.tom, p.texto]),
        [['ok', '1 novo'], ['run', '1 atualizado'], ['neutro', '1 sem mudança'], ['bad', '2 ficam de fora']]);
    assert.deepEqual(pilulasDoResumo({ produtos: [{ situacao: 'novo' }, { situacao: 'novo' }] }).map((p) => p.texto), ['2 novos']);
});

test('onde está na planilha: as linhas juntas em faixas, "Linha 5 · Variação", as variações em texto', () => {
    assert.equal(linhasEmTexto([4]), 'linha 4');
    assert.equal(linhasEmTexto([4, 5, 6, 7, 8, 9]), 'linhas 4 a 9');
    assert.equal(linhasEmTexto([10, 11]), 'linhas 10, 11');
    assert.equal(linhasEmTexto([9, 4, 5, 6, 6]), 'linhas 4 a 6 e 9');
    assert.equal(linhasEmTexto([4, 6, 8]), 'linhas 4, 6 e 8');
    assert.equal(linhasEmTexto([]), '');
    assert.equal(linhasEmTexto(null), '');

    assert.equal(ondeEmTexto({ linha: 11, coluna: 'Variação' }), 'Linha 11 · Variação');
    assert.equal(ondeEmTexto({ linha: 6, coluna: null }), 'Linha 6', 'o erro do produto inteiro vem sem coluna');
    assert.equal(ondeEmTexto({}), '');

    assert.equal(variacoesEmTexto({ novas: 6, atualizadas: 0 }), '6 variações novas');
    assert.equal(variacoesEmTexto({ novas: 1, atualizadas: 2 }), '1 variação nova e 2 atualizadas');
    assert.equal(variacoesEmTexto({ novas: 0, atualizadas: 1 }), '1 variação atualizada');
    assert.equal(variacoesEmTexto({ novas: 0, atualizadas: 0 }), '');
    assert.equal(variacoesEmTexto(null), '');
});

test('os produtos por aba (na ordem das abas) e os filtros "Ficam de fora" e "Com aviso"', () => {
    const grupos = produtosPorAba(PREVIA_DA_PLANILHA);
    assert.deepEqual(grupos.map((g) => [g.id, g.nome, g.linhas, g.produtos.map((p) => p.chave)]), [
        ['MLB31447', 'Camisetas', 8, ['CAM-01', 'REG-01']],
        ['MLB1902', 'Criados-mudos', 3, ['CRIADO-01', 'CRIADO-02', 'CJ-211']],
    ]);
    // A aba que não veio na lista vai no fim, com o id como nome; a aba sem produto (pelo filtro) sai.
    const estranho = { ...PREVIA_DA_PLANILHA, produtos: [...PREVIA_DA_PLANILHA.produtos, { chave: 'X-1', aba: 'MLB999', situacao: 'novo' }] };
    assert.deepEqual(produtosPorAba(estranho).map((g) => g.nome), ['Camisetas', 'Criados-mudos', 'MLB999']);
    assert.deepEqual(produtosPorAba(PREVIA_DA_PLANILHA, filtrarProdutos(PREVIA_DA_PLANILHA.produtos, 'com_aviso')).map((g) => g.id), ['MLB1902']);

    assert.deepEqual(filtrarProdutos(PREVIA_DA_PLANILHA.produtos, 'de_fora').map((p) => p.chave), ['REG-01', 'CJ-211']);
    assert.deepEqual(filtrarProdutos(PREVIA_DA_PLANILHA.produtos, 'com_aviso').map((p) => p.chave), ['CRIADO-01']);
    assert.equal(filtrarProdutos(PREVIA_DA_PLANILHA.produtos, 'todos').length, 5);
    assert.deepEqual(contagemDosFiltros(PREVIA_DA_PLANILHA.produtos), { todos: 5, de_fora: 2, com_aviso: 1 });
});

test('os erros: o do ARQUIVO (422 `errors.arquivo` e o 413 cru) e o do download (o 422 dentro do blob)', () => {
    const erro = (status, data = {}) => ({ isAxiosError: true, response: { status, data } });
    assert.equal(erroDoArquivo(erro(413, '<html>413</html>')), MSG_ARQUIVO_GRANDE);
    assert.equal(erroDoArquivo(erro(422, { errors: { arquivo: ['A planilha passa de 500 linhas de dados.'] } })), 'A planilha passa de 500 linhas de dados.');
    assert.equal(erroDoArquivo(erro(422, { errors: { 'arquivo.0': ['Não é a planilha da trilha.'] } })), 'Não é a planilha da trilha.');
    assert.equal(erroDoArquivo(erro(422, { errors: { versoes: ['x'] } })), null, 'outro campo: o erro de sempre');
    assert.equal(erroDoArquivo(erro(500)), null);
    assert.equal(erroDoArquivo(new Error('rede')), null);

    const corpo = JSON.stringify({ message: 'The given data was invalid.', errors: { categorias: ['“Calçados” não aceita produto novo: escolha outra.'] } });
    assert.equal(erroDoBaixar(422, corpo), '“Calçados” não aceita produto novo: escolha outra.');
    assert.match(erroDoBaixar(422, 'não é json'), /Não deu para montar a planilha/);
    assert.equal(erroDoBaixar(429, corpo), null, 'os outros códigos: o erro de sempre');

    assert.deepEqual(jsonDoCorpo('{"a":1}'), { a: 1 });
    assert.equal(jsonDoCorpo('<html>'), null);
    assert.equal(jsonDoCorpo(null), null);
    assert.equal(jsonDoCorpo('"texto"'), null);
});

test('a guarda: só a prévia conferida, com algo a gravar, ainda não gravada (e nenhum gravar no ar)', () => {
    assert.equal(planilhaPendente({ previa: PREVIA_DA_PLANILHA }), true);
    assert.equal(planilhaPendente({ previa: PREVIA_DA_PLANILHA, gravando: true }), false);
    assert.equal(planilhaPendente({ previa: RESULTADO_DA_PLANILHA }), false);
    assert.equal(planilhaPendente({ previa: { produtos: [{ situacao: 'sem_mudanca' }] } }), false);
    assert.equal(planilhaPendente({}), false);
    assert.equal(planilhaPendente(), false);
});

test('lib/trilhaPlanilha.js é pura: sem React, sem axios, sem classe do Tailwind, sem Portal nem Mercado Livre', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaPlanilha.js');
    assert.equal(fonte.match(/from ['"](?:react|axios|@inertiajs\/react)['"]/), null);
    assert.ok(!fonte.includes('className'));
    assert.equal(fonte.match(PROIBIDO), null);
});

// ─── As chamadas ao servidor ───────────────────────────────────────────────

/** Troca os métodos do axios por um que só anota o pedido e devolve `resposta`. */
function gravarPedidos(resposta = { data: {} }) {
    const pedidos = [];
    const originais = { get: axios.get, post: axios.post };
    for (const metodo of Object.keys(originais)) {
        axios[metodo] = async (url, corpo) => {
            pedidos.push({ metodo, url, corpo });

            return resposta;
        };
    }

    return { pedidos, restaurar: () => Object.assign(axios, originais) };
}

test('baixar: GET em `planilha.modelo` com `categorias[]` e a resposta em blob; o nome vem do servidor', async () => {
    const blob = new Blob(['xlsx']);
    const { pedidos, restaurar } = gravarPedidos({ data: blob, headers: { 'content-disposition': 'attachment; filename="camisetas-criados-mudos.xlsx"' } });
    let baixado;
    try {
        baixado = await M.escrita.baixarPlanilha(PROPS_TRABALHO.escrita, [CAMISETAS, CRIADOS]);
    } finally {
        restaurar();
    }
    assert.deepEqual(pedidos, [{
        metodo: 'get',
        url: '/trilha/459/planilha/modelo',
        corpo: { params: { categorias: ['MLB31447', 'MLB1902'] }, responseType: 'blob' },
    }]);
    assert.equal(baixado.arquivo, blob);
    assert.equal(baixado.nome, 'camisetas-criados-mudos.xlsx');

    // Na rede, os `params` saem como o §17.3 pede (`categorias[]=…`, um por categoria).
    assert.equal(decodeURIComponent(axios.getUri({ url: '/trilha/459/planilha/modelo', params: parametrosDoModelo([CAMISETAS, CRIADOS]) })),
        '/trilha/459/planilha/modelo?categorias[]=MLB31447&categorias[]=MLB1902');

    // Sem o nome na resposta (ou com um nome estranho), o padrão.
    const outro = gravarPedidos({ data: blob, headers: {} });
    try {
        assert.equal((await M.escrita.baixarPlanilha(PROPS_TRABALHO.escrita, [CAMISETAS])).nome, ARQUIVO_DA_TRILHA);
    } finally {
        outro.restaurar();
    }
});

test('prévia e gravar: POST multipart em `planilha.previa` e `planilha.gravar` — o gravar com o MESMO arquivo e as versões', async () => {
    const arquivo = xlsx();
    const { pedidos, restaurar } = gravarPedidos({ data: PREVIA_DA_PLANILHA });
    try {
        assert.deepEqual(await M.escrita.previaDaPlanilha(PROPS_TRABALHO.escrita, arquivo), PREVIA_DA_PLANILHA);
        await M.escrita.gravarPlanilha({ ...PROPS_TRABALHO.escrita, planilha: { ...PROPS_TRABALHO.escrita.planilha, gravar: '/trilha/459/planilha/' } }, arquivo, PREVIA_DA_PLANILHA);
    } finally {
        restaurar();
    }
    assert.deepEqual(pedidos.map((p) => [p.metodo, p.url]), [['post', '/trilha/459/planilha/previa'], ['post', '/trilha/459/planilha']]);
    assert.ok(pedidos[0].corpo instanceof FormData && pedidos[1].corpo instanceof FormData);
    assert.deepEqual([...pedidos[0].corpo.keys()], ['arquivo']);
    assert.equal(pedidos[0].corpo.get('arquivo').name, 'camisetas.xlsx');
    assert.deepEqual([...pedidos[1].corpo.keys()], ['arquivo', 'versoes[2]', 'versoes[9]', 'versoes[12]']);
    assert.equal(pedidos[1].corpo.get('versoes[9]'), 'a'.repeat(40));
});

test('sem `escrita.planilha` nada sai: o erro é da própria tela e chega legível', async () => {
    const semPlanilha = { ...PROPS_TRABALHO.escrita, planilha: undefined };
    const { pedidos, restaurar } = gravarPedidos();
    try {
        for (const chamada of [
            () => M.escrita.baixarPlanilha(semPlanilha, [CAMISETAS]),
            () => M.escrita.previaDaPlanilha(semPlanilha, xlsx()),
            () => M.escrita.gravarPlanilha(semPlanilha, xlsx(), PREVIA_DA_PLANILHA),
        ]) {
            await assert.rejects(chamada, (e) => e instanceof M.escrita.ErroDaTrilha && M.escrita.mensagemDoErro(e).includes('recarregue a página'));
        }
    } finally {
        restaurar();
    }
    assert.equal(pedidos.length, 0);
});

// ─── A chave `e:planilha` ───────────────────────────────────────────────────

test('chave `e:planilha`: só quem escreve abre; o cliente, quem não escreve e o "Ver como: Cliente" não abrem nada', () => {
    const E = M.estado;
    assert.equal(M.trilha.CHAVE_PLANILHA, 'e:planilha');
    assert.deepEqual(E.painelDaChave(PROPS_TRABALHO, 'e:planilha', { podeEditar: true }), { painel: 'planilha', chave: 'e:planilha' });
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:planilha', { podeEditar: false }), null);
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:planilha'), null);
    // A gaveta do produto continua igual (o `e:planilha` não é um produto).
    assert.equal(E.painelDaChave(PROPS_TRABALHO, 'e:9', { podeEditar: true }).painel, 'cadastro');

    assert.equal(E.buscaComEstado('', { etapa: 'lista', vista: 'trilha', abrir: 'e:planilha' }), '?abrir=e%3Aplanilha&etapa=lista');
    assert.deepEqual(E.lerEstadoDaUrl('?abrir=e:planilha&etapa=lista', PROPS_TRABALHO, { podeEditar: true }), { etapa: 'lista', vista: 'trilha', abrir: 'e:planilha' });
    assert.equal(E.lerEstadoDaUrl('?abrir=e:planilha', PROPS_TRABALHO, { podeEditar: false }).abrir, null);
    // Ao fechar, o foco volta para o "Usar planilha" (o `data-chave` dele).
    assert.deepEqual(E.candidatasDaVolta('e:planilha'), ['e:planilha']);
    assert.equal(E.etapaDoItem(PROPS_TRABALHO, 'e:planilha'), 'lista');
});

/** Desenha a página como se a URL tivesse a busca `busca`. */
function desenharPagina(props, busca = '') {
    global.window.location.search = busca;
    global.window.location.href = `http://localhost/trilha/459${busca}`;

    return renderToStaticMarkup(h(M.pagina.default, props));
}

test('gaveta `e:planilha` pela URL: quem escreve vê a gaveta (o JS chega por lazy); o cliente e o "Ver como: Cliente", nada', () => {
    const saida = desenharPagina(PROPS_TRABALHO, '?abrir=e:planilha&etapa=lista');
    const inicio = saida.indexOf('data-painel-lateral="e:planilha"');
    assert.ok(inicio >= 0, 'a gaveta abre');
    const gaveta = saida.slice(inicio);
    assert.match(gaveta, /role="dialog" aria-modal="true"/);
    assert.ok(texto(gaveta).includes('Planilha por categoria') && texto(gaveta).includes('Abrindo a planilha…'), texto(gaveta));
    assert.match(gaveta, /aria-busy="true"/);

    for (const [nome, props] of [['cliente', paraCliente(PROPS_TRABALHO)], ['sem escrita', { ...PROPS_TRABALHO, pode_editar: false, escrita: null }]]) {
        const html = desenharPagina(props, '?abrir=e:planilha');
        assert.ok(!html.includes('role="dialog"') && !html.includes('data-carregando-planilha'), nome);
    }
    global.window.location.search = '?abrir=e:planilha';
    const comoCliente = renderToStaticMarkup(h(M.pagina.TelaDaTrilha, { ...PROPS_TRABALHO, verComo: 'cliente', onVerComo: () => {} }));
    assert.ok(!comoCliente.includes('role="dialog"'), 'Ver como: Cliente');
    global.window.location.search = '';
});

// ─── O quadro "Pela planilha" ───────────────────────────────────────────────

test('o quadro "Pela planilha" (§17.9): os 3 passos e "Usar planilha", que abre `e:planilha` — só com o endereço da planilha', () => {
    const abertos = [];
    const comPlanilha = renderToStaticMarkup(h(M.quadros.default, { comPlanilha: true, onAbrir: (chave) => abertos.push(chave) }));
    const t = texto(comPlanilha);
    assert.ok(t.includes('Pelo sistema') && t.includes('Pela planilha'));
    assert.ok(t.includes('1. Escolha as categorias dos produtos. 2. Baixe a planilha já com os campos da ficha técnica de cada uma. 3. Preencha e suba de volta.'), t);
    const usar = tag(comPlanilha, 'data-acao="usar-planilha"');
    assert.ok(usar.includes('data-chave="e:planilha"') && usar.includes('type="button"'), usar);
    assert.ok(t.includes('Usar planilha'));
    assert.equal(t.match(PROIBIDO), null);

    // Sem o endereço (servidor antigo) ou sem quem abra: só o "Pelo sistema".
    for (const props of [{}, { comPlanilha: false, onAbrir: () => {} }, { comPlanilha: true }]) {
        const html = renderToStaticMarkup(h(M.quadros.default, props));
        assert.ok(!texto(html).includes('Pela planilha') && !html.includes('usar-planilha'), JSON.stringify(Object.keys(props)));
    }
});

test('a Lista passa `comPlanilha` (de `escrita.planilha`) e o `onAbrir` ao quadro da equipe', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    // F8: a mesma linha passa também `comImportacao` (ver tests/js/trilha-importar-ml.test.js).
    assert.match(pagina, /<PainelDaLista \{\.\.\.propsDoPainel\} comPlanilha=\{podeEditar && !!escrita\?\.planilha\} comImportacao=\{[^}]*\} \/>/);
    assert.match(lerSemComentarios('resources/js/Components/Trilha/PainelDaLista.jsx'), /<QuadrosDaLista onAbrir=\{onAbrir\} comPlanilha=\{comPlanilha\} comMercadoLivre=\{comImportacao\} \/>/);
});

// ─── A gaveta desenhada ─────────────────────────────────────────────────────

test('gaveta vazia: o passo 1 (categorias) e o 2 (enviar); "Baixar" desligado sem categoria; nada de Gravar', () => {
    const saida = desenharVista();
    const t = texto(saida);
    for (const s of ['Planilha por categoria', 'Cadastrar pela planilha', '1. Baixe a planilha', '0 de 10', 'Nenhuma categoria escolhida ainda.',
        'Buscar categoria', 'Navegar', 'Escolha a categoria para baixar', '2. Envie a planilha preenchida', 'Enviar planilha',
        'As fotos entram depois, pela gaveta de cada produto.', 'Nada grava antes da conferência']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    assert.ok(tag(saida, 'data-acao="baixar-planilha"').includes('disabled=""'));
    assert.ok(tag(saida, 'data-acao="enviar-planilha"').includes('bg-trilha-navy'), 'sem prévia, enviar é a ação principal');
    const seletor = tag(saida, 'data-seletor-da-planilha', 'input');
    assert.ok(seletor.includes('type="file"') && seletor.includes('hidden=""') && seletor.includes('accept=".xlsx,'), seletor);
    assert.ok(!saida.includes('data-acao="gravar-planilha"') && !saida.includes('data-caixa="previa"'));
    assert.equal(t.match(PROIBIDO), null);
});

test('com categorias: a lista com "Tirar", o contador e o "Baixar a planilha (2 categorias, uma aba cada)"; com 10, o teto', () => {
    const duas = desenharVista({ categorias: [CAMISETAS, CRIADOS] });
    const t = texto(duas);
    assert.ok(t.includes('2 de 10') && t.includes('Camisetas') && t.includes('Casa, Móveis e Decoração › Quarto › Criados-mudos'), t);
    assert.equal((duas.match(/data-categoria-escolhida="/g) ?? []).length, 2);
    assert.ok(tag(duas, 'aria-label="Tirar Camisetas"').includes('data-acao="tirar-categoria"'));
    const baixar = tag(duas, 'data-acao="baixar-planilha"');
    assert.ok(!baixar.includes('disabled=""') && t.includes('Baixar a planilha (2 categorias, uma aba cada)'), baixar);
    assert.ok(texto(desenharVista({ categorias: [CAMISETAS] })).includes('Baixar a planilha desta categoria'));

    const dez = Array.from({ length: 10 }, (_, i) => ({ id: `MLB${i + 1}`, nome: `Categoria ${i + 1}`, caminho: '' }));
    const cheia = desenharVista({ categorias: dez, cabeMais: false });
    assert.ok(texto(cheia).includes('Cabem até 10 categorias por planilha. Tire uma para escolher outra.') && texto(cheia).includes('10 de 10'));
    assert.ok(!cheia.includes('data-acao="buscar-categoria"'));

    // Baixando, baixada e o erro do download.
    assert.ok(texto(desenharVista({ categorias: [CAMISETAS], baixando: true })).includes('Montando a planilha…'));
    assert.ok(tag(desenharVista({ categorias: [CAMISETAS], baixando: true }), 'data-acao="baixar-planilha"').includes('aria-disabled="true"'));
    assert.ok(texto(desenharVista({ categorias: [CAMISETAS], baixada: 'camisetas.xlsx' })).includes('Baixada: camisetas.xlsx. Preencha e envie no passo 2.'));
    assert.match(desenharVista({ categorias: [CAMISETAS], erroDoBaixar: '“Calçados” não aceita produto novo.' }),
        /role="alert"[^>]*data-erro-da-planilha="baixar"[^>]*>“Calçados” não aceita produto novo\.</);
});

test('a busca e a árvore da F2 na planilha: várias categorias, "Adicionar" e "escolhida" no lugar de "Usar esta" e "atual"', () => {
    const CAT = M.categoria.CATEGORIA_PARADA;
    const lista = { situacao: 'pronta', candidatas: CANDIDATAS.categorias, consulta: 'criado', mudouEm: 0 };
    const busca = (categorias) => desenharVista({ categorias, cat: { ...CAT, aberto: 'buscar', consulta: 'criado', lista } });

    const nenhuma = busca([]);
    assert.ok(nenhuma.includes('data-busca-de-categoria'), 'a busca da F2 abre na gaveta');
    assert.ok(texto(nenhuma).includes('Adicionar') && !texto(nenhuma).includes('Usar esta'));
    assert.ok(texto(nenhuma).includes('Escolha uma mais específica') && texto(nenhuma).includes('Não aceita produto novo'));
    // Aberta a lista, os botões "Buscar categoria"/"Navegar" saem (o "Fechar" dela volta).
    assert.ok(!nenhuma.includes('data-acao="buscar-categoria"'));

    const escolhida = busca([CRIADOS]);
    assert.ok(texto(escolhida).includes('escolhida') && !texto(escolhida).includes(' atual '));
    assert.equal(texto(escolhida).match(PROIBIDO), null);

    const arvore = desenharVista({ cat: { ...CAT, aberto: 'navegar', buscarNivel: async () => null } });
    assert.ok(arvore.includes('data-painel-da-categoria="navegar"') && texto(arvore).includes('Carregando as categorias…'));
});

test('a prévia (§17.4): por aba, a situação de cada produto, os erros por linha e coluna, os avisos e o que vai faltar', () => {
    const saida = desenharVista({ previa: PREVIA_DA_PLANILHA, nomeDoArquivo: 'camisetas.xlsx' });
    const t = texto(saida);

    assert.ok(saida.includes('data-caixa="previa"') && t.includes('3. Confira antes de gravar'));
    assert.ok(t.includes(PREVIA_DA_PLANILHA.mensagem));
    for (const s of ['1 novo', '1 atualizado', '1 sem mudança', '2 ficam de fora']) assert.ok(t.includes(s), `faltou a pílula ${s}`);
    // As abas, na ordem, com as linhas.
    assert.ok(t.indexOf('Camisetas 8 linhas') < t.indexOf('Criados-mudos 3 linhas') && t.indexOf('Camisetas 8 linhas') >= 0, t);

    /** O pedaço do HTML de um produto (até o próximo). */
    const produto = (chave) => {
        const inicio = saida.indexOf(`data-produto-da-planilha="${chave}"`);
        assert.ok(inicio >= 0, chave);
        const proximo = saida.indexOf('data-produto-da-planilha=', inicio + 1);

        return saida.slice(inicio, proximo > 0 ? proximo : undefined);
    };
    const camiseta = texto(produto('CAM-01'));
    assert.ok(camiseta.includes('Camiseta básica') && camiseta.includes('CAM-01 · linhas 4 a 9 · 6 variações novas') && camiseta.includes('Novo'), camiseta);
    assert.ok(camiseta.includes('Depois de gravar, na Lista ainda falta: fotos (0 de 3), descrição'));
    assert.ok(produto('CAM-01').startsWith('data-produto-da-planilha="CAM-01" data-situacao="novo"'));

    const regata = texto(produto('REG-01'));
    assert.ok(regata.includes('Fica de fora') && regata.includes('Nada deste produto grava:'), regata);
    assert.ok(regata.includes('Linha 11 · Variação: Informe a variação'), regata);
    assert.ok(!regata.includes('ainda falta'));

    const cadeira = texto(produto('CJ-211'));
    assert.ok(cadeira.includes('Linha 6: Este produto já andou na trilha'), 'o erro do produto inteiro, sem coluna');

    const criado = texto(produto('CRIADO-01'));
    assert.ok(criado.includes('Atualizado') && criado.includes('1 variação atualizada') && criado.includes('Aviso:') && criado.includes('Linha 4 · Material:'), criado);
    assert.ok(texto(produto('CRIADO-02')).includes('Sem mudança'));
    // Na prévia, nada de abrir o produto (ele ainda não mudou).
    assert.ok(!saida.includes('abrir-produto-da-planilha'));

    // O filtro aparece (há de fora e com aviso), com as contas.
    assert.ok(saida.includes('data-filtro-da-planilha'));
    assert.ok(t.includes('Todos (5)') && t.includes('Ficam de fora (2)') && t.includes('Com aviso (1)'), t);
    assert.ok(t.includes('O produto que fica de fora não grava nada — nem a ficha.'));

    // O rodapé: "Gravar N produtos (M ficam de fora)", principal e ligado; o envio vira "Enviar outra planilha".
    const gravar = tag(saida, 'data-acao="gravar-planilha"');
    assert.ok(gravar.includes('bg-trilha-navy') && !gravar.includes('disabled=""'), gravar);
    assert.ok(t.includes('Gravar 2 produtos (2 ficam de fora)') && t.includes('Grava os que estão certos; os de fora ficam como estavam.'));
    assert.ok(t.includes('Enviar outra planilha') && t.includes('camisetas.xlsx'));
    assert.ok(!tag(saida, 'data-acao="enviar-planilha"').includes('bg-trilha-navy'), 'com prévia, a principal é o Gravar');
    assert.equal(t.match(PROIBIDO), null);
});

test('o filtro "Ficam de fora" mostra só os recusados; nada a gravar desliga o botão e diz por quê', () => {
    const deFora = desenharVista({ previa: PREVIA_DA_PLANILHA, filtro: 'de_fora' });
    assert.deepEqual([...deFora.matchAll(/data-produto-da-planilha="([^"]+)"/g)].map((m) => m[1]), ['REG-01', 'CJ-211']);

    const tudoFora = { ...PREVIA_DA_PLANILHA, produtos: PREVIA_DA_PLANILHA.produtos.filter((p) => p.situacao === 'recusado'), mensagem: '' };
    const saida = desenharVista({ previa: tudoFora });
    assert.ok(tag(saida, 'data-acao="gravar-planilha"').includes('disabled=""'));
    assert.ok(texto(saida).includes('Nada para gravar') && texto(saida).includes('Corrija a planilha e envie de novo.'));
    assert.ok(texto(desenharVista({ previa: { ...PREVIA_DA_PLANILHA, produtos: [PREVIA_DA_PLANILHA.produtos[3]] } })).includes('Nada muda com esta planilha.'));

    // Gravando: o botão ocupado (continua focável); o erro do gravar no rodapé.
    const gravando = desenharVista({ previa: PREVIA_DA_PLANILHA, gravando: true });
    assert.ok(tag(gravando, 'data-acao="gravar-planilha"').includes('aria-disabled="true"') && texto(gravando).includes('Gravando…'));
    assert.match(desenharVista({ previa: PREVIA_DA_PLANILHA, erroDoGravar: 'O servidor falhou. Tente de novo em instantes.' }),
        /role="alert"[^>]*data-erro-da-planilha="gravar"/);
});

test('o envio: "Conferindo a planilha…" e o erro do arquivo (role="alert") no passo 2', () => {
    const enviando = desenharVista({ enviando: true, nomeDoArquivo: 'camisetas.xlsx' });
    assert.ok(texto(enviando).includes('Conferindo a planilha…') && texto(enviando).includes('nada é gravado agora'));
    assert.ok(tag(enviando, 'data-acao="enviar-planilha"').includes('aria-disabled="true"'));
    assert.match(desenharVista({ erroDoEnvio: 'A planilha passa de 500 linhas de dados.' }),
        /role="alert"[^>]*data-erro-da-planilha="enviar"[^>]*>A planilha passa de 500 linhas de dados\.</);
});

test('o resultado: no passado, "Abrir o produto" só no que gravou, e "Enviar outra planilha"; os passos 1 e 2 saem', () => {
    const abertos = [];
    const saida = desenharVista({ resultado: RESULTADO_DA_PLANILHA }, { onAbrir: (c) => abertos.push(c) });
    const t = texto(saida);
    assert.ok(saida.includes('data-caixa="resultado"') && t.includes('Resultado') && t.includes(RESULTADO_DA_PLANILHA.mensagem));
    for (const s of ['1 criado', '2 ficaram de fora', 'Criado', 'Ficou de fora', 'Nada deste produto foi gravado:', 'Na Lista ainda falta: fotos (0 de 3), descrição']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    assert.ok(!saida.includes('data-caixa="baixar"') && !saida.includes('data-caixa="enviar"') && !saida.includes('data-acao="gravar-planilha"'));
    // "Abrir o produto": a camiseta criada (130), o criado-mudo atualizado (9) e o sem mudança (12); o recusado não.
    assert.equal((saida.match(/data-acao="abrir-produto-da-planilha"/g) ?? []).length, 3);
    assert.ok(t.includes('A Lista já mostra o que foi gravado.') && tag(saida, 'data-acao="outra-planilha"'));
    assert.equal(t.match(PROIBIDO), null);
});

test('a guarda: a prévia não gravada pede confirmação no rodapé ("Descartar" / "Continuar aqui", o foco no que não perde)', () => {
    const saida = desenharVista({ previa: PREVIA_DA_PLANILHA, pedido: { seguir: () => {} } });
    assert.ok(saida.includes('data-confirmacao'));
    assert.ok(texto(saida).includes('A planilha conferida ainda não foi gravada.'));
    assert.ok(tag(saida, 'data-acao="descartar"') && tag(saida, 'data-acao="continuar-editando"'));
    assert.ok(!saida.includes('data-acao="gravar-planilha"'), 'a confirmação toma o rodapé');
});

test('sem `escrita.planilha` a gaveta avisa e não deixa baixar nem enviar', () => {
    const saida = desenharVista({ temPlanilha: false, categorias: [CAMISETAS] });
    assert.ok(saida.includes('data-planilha-indisponivel') && texto(saida).includes('Recarregue a página.'));
    assert.ok(tag(saida, 'data-acao="baixar-planilha"').includes('disabled=""') && tag(saida, 'data-acao="enviar-planilha"').includes('disabled=""'));
});

test('a gaveta de verdade (com o hook) desenha do zero com a escrita da equipe', () => {
    const saida = renderToStaticMarkup(h(M.planilha.default, { escrita: PROPS_TRABALHO.escrita }));
    assert.ok(texto(saida).includes('1. Baixe a planilha') && !saida.includes('data-planilha-indisponivel'));
    const semPlanilha = renderToStaticMarkup(h(M.planilha.default, { escrita: { ...PROPS_TRABALHO.escrita, planilha: undefined } }));
    assert.ok(semPlanilha.includes('data-planilha-indisponivel'));
});

// ─── O `lazy` e as cores ────────────────────────────────────────────────────

test('o `lazy` da planilha: na página E no 2º caminho (`CadastroSobDemanda.jsx`), nunca import estático', () => {
    // Learnings §30: um `lazy` da pasta `Cadastro/` sem o 2º caminho tira `Pages/Trilha/Empresa.jsx` do manifest do
    // Vite (o build sai 0 e a rota cai com "Unable to locate file in Vite manifest").
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /const PlanilhaPorCategoria = lazy\(\(\) => import\('@\/Components\/Trilha\/Cadastro\/PlanilhaPorCategoria'\)\);/);
    assert.equal(pagina.match(/^\s*import\b[^;]*Cadastro\//m), null);
    const sobDemanda = lerSemComentarios('resources/js/Pages/Trilha/CadastroSobDemanda.jsx');
    assert.match(sobDemanda, /PlanilhaPorCategoria: \(\) => import\('@\/Components\/Trilha\/Cadastro\/PlanilhaPorCategoria'\),/);
    assert.equal(sobDemanda.match(/^\s*import\b/m), null);
    // A gaveta usa o fallback próprio (o JS chega depois) e só abre no painel `planilha`.
    assert.match(pagina, /painel\?\.painel === 'planilha'[\s\S]*?<Suspense key=\{chaveDoConteudo\} fallback=\{<AbrindoAPlanilha \/>\}>/);
});

test('a gaveta e o quadro: só cores trilha-*, nenhum texto do Portal, do Publicador ou do Mercado Livre', () => {
    // F8: o quadro ganhou o "Do Mercado Livre" (D40: o nome do marketplace pode aparecer na porta da equipe, dentro do
    // `lazy`) — nele o PROIBIDO é só o Portal e o Publicador. A gaveta da planilha continua sem nenhum dos três.
    const SEM_PORTAL = /Portal|\/portal|Publicador/i;
    for (const [arquivo, proibido] of [
        ['resources/js/Components/Trilha/Cadastro/PlanilhaPorCategoria.jsx', PROIBIDO],
        ['resources/js/Components/Trilha/Cadastro/QuadrosDaLista.jsx', SEM_PORTAL],
    ]) {
        const fonte = lerSemComentarios(arquivo);
        assert.equal(fonte.match(proibido), null, arquivo);
        assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null, arquivo);
    }
});
