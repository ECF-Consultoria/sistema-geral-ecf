import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import {
    CADASTRO,
    CADASTRO_ONDA_COMECOU,
    CADASTRO_TRAVADO,
    MOTIVO_DA_TRAVA,
    PROPS_TRABALHO,
    SALVO_COMPLETO,
    SALVO_INCOMPLETO,
} from './_trilha-exemplo.js';
import { estadoDoRetrato, mexido, resultadoDoSalvar } from '../../resources/js/lib/trilhaCadastro.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 3ª entrega (02/11/2026), fatia F1: a gaveta de cadastro
// DESENHADA de verdade (React no servidor, com os imports reais).
//
// POR QUE EXISTE: a pasta `Components/Trilha/Cadastro/` chega à página por
// `lazy` (só para quem escreve) — no render da página ela aparece só como
// "Abrindo o cadastro…". Aqui os componentes são montados DIRETO e desenhados
// com o JSON do contrato (§6.3 do desenho), sem pedido nenhum: a ordem das
// caixas, os obrigatórios, as faltas lidas do servidor, o resultado do
// "Salvar", o modo leitura do produto que andou (e do 409), os avisos e a
// confirmação de saída no rodapé.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;
global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-cadastro-inertia-stub-${process.pid}.mjs`);
    fs.writeFileSync(stub, `
export function Head() { return null; }
export const router = { reload: () => {}, visit: () => {}, on: () => () => {} };
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
                alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
                external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios'],
            });
            const saida = path.join(__dirname, `.trilha-cadastro-${nome}-${process.pid}-${Date.now()}.mjs`);
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
    painel: 'resources/js/Components/Trilha/Cadastro/PainelDoCadastro.jsx',
    novo: 'resources/js/Components/Trilha/Cadastro/NovoProduto.jsx',
    quadros: 'resources/js/Components/Trilha/Cadastro/QuadrosDaLista.jsx',
    produto: 'resources/js/Components/Trilha/Cadastro/CaixaDoProduto.jsx',
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

const VOCABULARIO_PROIBIDO = [/prazo/i, /publicar até/i, /data de publicação/i, /próxima entrega/i, /planejad[oa]/i, /-KIT\b/, /-CBT\d/];

const nada = () => {};

/** O que `useCadastroDoProduto` devolve, montado à mão a partir do retrato (com `extra` por cima). */
function estadoDoHook(retrato, extra = {}) {
    const estado = extra.estado ?? (retrato ? estadoDoRetrato(retrato) : null);
    const leitura = extra.leitura ?? retrato?.edicao?.livre === false;

    return {
        retrato,
        estado,
        erroAoAbrir: null,
        tentarDeNovo: nada,
        carregando: !retrato,
        leitura,
        motivo: retrato?.edicao?.motivo ?? null,
        temMudanca: !leitura && !!retrato && mexido(estado, retrato),
        salvando: false,
        salvar: nada,
        erros: { porChave: {}, soltas: [] },
        erroGeral: null,
        nota: null,
        resultado: null,
        continuarDoResultado: nada,
        mudarProduto: nada,
        mudarVariacao: nada,
        guardiao: {},
        pedido: null,
        continuarEditando: nada,
        descartar: nada,
        salvarESeguir: nada,
        ...extra,
    };
}

const produtoDaTrilha = (id) => PROPS_TRABALHO.produtos.find((p) => p.id === id);

function desenharGaveta(retrato, extra = {}, { produto = produtoDaTrilha(retrato?.produto?.id ?? 9), links = PROPS_TRABALHO.links } = {}) {
    return renderToStaticMarkup(h(M.painel.VistaDoCadastro, {
        produto,
        dados: PROPS_TRABALHO,
        links,
        trabalho: true,
        c: estadoDoHook(retrato, extra),
        onAbrir: nada,
    }));
}

// ─── A gaveta `e:{id}` ──────────────────────────────────────────────────────

test('a gaveta na ordem do protótipo: Onde está, a Lista de produtos (nome → descrição) e as ofertas; as faltas são as do servidor', () => {
    const saida = desenharGaveta(CADASTRO);
    const t = texto(saida);

    // Cabeçalho: sobrelinha, nome e "SKU · família".
    for (const s of ['Produto da lista', 'Criado-mudo 2 gavetas', 'CRIADO-01 · sem família']) assert.ok(t.includes(s), `faltou: ${s}`);

    const caixas = ['onde-esta', 'lista', 'ofertas'].map((c) => saida.indexOf(`data-caixa="${c}"`));
    assert.ok(caixas.every((i, n) => i > 0 && (n === 0 || i > caixas[n - 1])), `ordem das caixas: ${caixas}`);
    // A ordem do §5.3: nome, família, ambientes, título e, sem variação, SKU, custo, estoque e embalagem; a descrição no fim.
    const campos = ['nome', 'familia', 'ambientes', 'titulo', 'codigo', 'custo', 'estoque', 'volumes', 'descricao'].map((c) => saida.indexOf(`data-campo="${c}"`));
    assert.ok(campos.every((i, n) => i > 0 && (n === 0 || i > campos[n - 1])), `ordem dos campos: ${campos}`);

    // O "Onde está" com o dono da trilha de trabalho.
    assert.ok(t.includes('Lista de produtos · Equipe ECF'), t);

    // A pílula e as faltas: as do SERVIDOR, nada recalculado.
    assert.match(saida, /data-caixa="lista"[\s\S]*?faltam 8/);
    for (const falta of CADASTRO.situacao.faltas) assert.ok(t.includes(falta), `falta não mostrada: ${falta}`);
    // O que esta fatia ainda não completa aqui aponta o Portal da empresa (saída de emergência até a F5).
    assert.ok(t.includes('Categoria, ficha técnica e fotos ainda não se completam por aqui'));
    assert.match(saida, /href="\/companies\/459\/portal"[^>]*data-link="portal"/);

    // O nome é o começo do foco.
    assert.ok(tag(saida, 'data-foco-inicial', 'input').includes('value="Criado-mudo 2 gavetas"'));
    // Rodapé: a dica da Lista e o "Salvar" desligado (nada mexido).
    assert.ok(t.includes('Ao completar, segue sozinho para o mapeamento.'));
    const salvar = tag(saida, 'data-acao="salvar-cadastro"');
    assert.ok(salvar.includes('disabled=""') && salvar.includes('type="submit"') && salvar.includes('form="'), salvar);
    assert.ok(!saida.includes('data-acao="mapear"'), 'na Lista não há o que mapear');
    // As ofertas (o produto ainda não tem): só leitura.
    assert.ok(t.includes('Ainda nenhuma oferta: o produto vira oferta depois do mapeamento.'));
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t.match(proibido), null, `${proibido}`);
});

test('os obrigatórios têm "*" (e aria-required), nunca `required`: o cadastro é aos poucos e o navegador barraria o "Salvar"', () => {
    const saida = desenharGaveta(CADASTRO);
    const rotulo = (campo) => texto(saida.match(new RegExp(`data-campo="${campo}"[\\s\\S]*?</(?:label|legend)>`))?.[0] ?? '');
    for (const campo of ['nome', 'familia', 'ambientes', 'titulo', 'codigo', 'custo', 'volumes', 'descricao']) {
        assert.match(rotulo(campo), /\*/, `${campo} é obrigatório`);
    }
    assert.doesNotMatch(rotulo('estoque'), /\*/, 'o estoque é opcional');
    assert.ok(tag(saida, 'data-foco-inicial', 'input').includes('aria-required="true"'));
    assert.doesNotMatch(saida, /\srequired=""/);
    // Os contadores: o título contra os 60 de quando não há categoria; a descrição contra os 5.000.
    assert.ok(texto(saida).includes('0 de 60') && texto(saida).includes('0 de 5.000'));
    // O título vazio com nome: "Começar pelo nome".
    assert.ok(saida.includes('data-acao="titulo-pelo-nome"'));
    assert.ok(!desenharGaveta(SALVO_INCOMPLETO).includes('data-acao="titulo-pelo-nome"'), 'com título, o atalho sai');
});

test('com algo mexido: "Há mudanças não salvas." e o "Salvar" ligado; os campos mostram o que a pessoa digitou', () => {
    const estado = estadoDoRetrato(CADASTRO);
    estado.produto.titulo = 'Criado-Mudo Jatobá';
    estado.produto.familia = 'Criados-mudos';
    estado.variacoes[0].custo = '1.890,00';
    estado.variacoes[0].volumes = [{ c: '50', l: '40', a: '60', kg: '18,5' }];
    const saida = desenharGaveta(CADASTRO, { estado });
    const t = texto(saida);

    assert.ok(t.includes('Há mudanças não salvas.'));
    assert.ok(!tag(saida, 'data-acao="salvar-cadastro"').includes('disabled=""'), 'mexido: o "Salvar" liga');
    assert.ok(t.includes('18 de 60'), 'o contador acompanha o título');
    assert.ok(saida.includes('value="1.890,00"') && saida.includes('value="18,5"'));
    // Família nova: a etiqueta diz que ela nasce no "Salvar".
    assert.match(saida, /data-nome-novo=""[^>]*>nova · criada ao salvar/);
});

test('salvo com faltas: a pílula "faltam 2"; completo: "completa" e "Mapear agora" no rodapé', () => {
    assert.match(desenharGaveta(SALVO_INCOMPLETO), /faltam 2/);
    const completo = desenharGaveta(SALVO_COMPLETO);
    assert.match(completo, /data-caixa="lista"[\s\S]*?>completa</);
    assert.ok(texto(completo).includes('Mapear agora'));
    assert.ok(!completo.includes('data-nota-do-portal'), 'nada a completar fora daqui');
});

test('o resultado do "Salvar" toma o lugar do formulário: "Lista completa…" + "Mapear agora"; ou "Ainda faltam…" + "Continuar editando"', () => {
    const completo = desenharGaveta(SALVO_COMPLETO, { resultado: resultadoDoSalvar(CADASTRO, SALVO_COMPLETO) });
    const tc = texto(completo);
    assert.ok(tc.includes('Lista completa: o produto seguiu para o mapeamento.'));
    assert.match(completo, /data-resultado-do-cadastro="completo"/);
    assert.ok(tag(completo, 'data-acao="mapear-agora"').includes('bg-trilha-navy'), '"Mapear agora" é a ação principal');
    assert.ok(completo.includes('data-acao="outro-produto"') && completo.includes('data-acao="continuar-editando"'));
    assert.ok(!completo.includes('<form') && !completo.includes('data-acao="salvar-cadastro"'), 'o formulário sai');

    const incompleto = desenharGaveta(SALVO_INCOMPLETO, { resultado: resultadoDoSalvar(CADASTRO, SALVO_INCOMPLETO, SALVO_INCOMPLETO.criadas_nas_listas) });
    const ti = texto(incompleto);
    for (const s of ['Salvo. Ainda faltam: categoria, fotos (0 de 3).', 'Criamos a família Criados-mudos e o ambiente Quarto na lista da empresa.', '+ Outro produto', 'Continuar editando']) {
        assert.ok(ti.includes(s), `faltou: ${s}`);
    }
    assert.ok(!incompleto.includes('data-acao="mapear-agora"'), 'ainda na Lista: nada de mapear');
    assert.ok(tag(incompleto, 'data-acao="continuar-editando"').includes('bg-trilha-navy'));
});

test('produto que andou: só leitura com o motivo no topo, sem "Salvar", com "Abrir no Publicador"; as cores (Portal) só se leem', () => {
    const saida = desenharGaveta(CADASTRO_TRAVADO);
    const t = texto(saida);

    assert.ok(saida.indexOf('data-motivo-da-trava') < saida.indexOf('data-caixa="onde-esta"'), 'o motivo vem no topo');
    assert.ok(t.includes(`Só leitura ${MOTIVO_DA_TRAVA}`), t);
    assert.match(saida, /<fieldset disabled=""/);
    assert.ok(!saida.includes('data-acao="salvar-cadastro"'));
    assert.match(saida, /href="\/mlb\/anuncios\/publicador\/produtos\/72\/editor"[^>]*data-link="publicador"/);
    // Fora da Lista: "Mapear de novo" continua (mapear não é mexer no cadastro).
    assert.ok(t.includes('Mapear de novo'));
    // Duas cores: as variações só para leitura nesta fatia.
    for (const s of ['Este produto tem 2 variações', 'Natural', 'CJ-211-NAT', 'R$ 290,00', 'Mel', 'CJ-211-MEL', 'R$ 310,00']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    assert.ok(!saida.includes('data-campo="custo"'), 'sem campo de custo com várias variações');
    // Com categoria, a dica do título não fala em "sem categoria".
    assert.ok(t.includes('É o título do Clássico; o robô escreve o do Premium.') && !t.includes('Sem categoria, vale o limite'));
    assert.ok(texto(desenharGaveta(CADASTRO)).includes('Sem categoria, vale o limite de 60 letras.'));
    // As ofertas com o produto, só leitura (abrem o painel delas).
    assert.match(saida, /data-abrir="o:s-2"/);
});

test('409 no "Salvar" (o produto andou enquanto a gaveta estava aberta): o mesmo modo leitura, com o motivo do servidor', () => {
    const saida = desenharGaveta(SALVO_INCOMPLETO, { leitura: true, motivo: MOTIVO_DA_TRAVA });
    assert.ok(texto(saida).includes(MOTIVO_DA_TRAVA));
    assert.match(saida, /<fieldset disabled=""/);
    assert.ok(!saida.includes('data-acao="salvar-cadastro"'));
    assert.ok(texto(saida).includes('Só leitura.'), 'o rodapé diz que é só leitura');
});

test('avisos: a onda que começou (usa IA) e o produto que já está no Publicador; erro de campo marcado e no rodapé', () => {
    const saida = desenharGaveta(CADASTRO_ONDA_COMECOU);
    const t = texto(saida);
    assert.ok(t.includes('A Onda 1 · simples já começou: ao salvar, o robô prepara este produto com o que estiver aqui (usa IA).'));
    assert.ok(t.includes('Este produto já está no Publicador: texto, ficha, custo e embalagem que você mudar seguem para lá; categoria e fotos, não.'));
    assert.ok(!desenharGaveta(CADASTRO).includes('data-aviso-da-onda'), 'sem onda começada, sem aviso');
    // O produto travado não mostra o aviso da onda (nada a salvar).
    assert.ok(!desenharGaveta({ ...CADASTRO_TRAVADO, edicao: { ...CADASTRO_TRAVADO.edicao, onda_comecou: { id: 7, nome: 'Onda 1' } } }).includes('data-aviso-da-onda'));

    const comErro = desenharGaveta(CADASTRO, {
        erros: { porChave: { v91: { custo: 'Use números no formato 1.234,56.', titulo: 'O título passa do limite.' } }, soltas: [] },
        erroGeral: 'Confira os campos marcados: o que está neles não foi salvo.',
    });
    assert.match(comErro, /data-erro-do-campo="custo"[^>]*>Use números no formato 1\.234,56\.</);
    assert.match(comErro, /data-erro-do-campo="titulo"[^>]*>O título passa do limite\.</);
    assert.match(comErro, /role="alert"[^>]*data-estado-do-rodape="erro"[^>]*>Confira os campos marcados/);
    assert.ok(tag(comErro, 'inputMode="decimal"', 'input').includes('aria-invalid="true"'));
});

test('fechar com algo mexido: a confirmação no RODAPÉ da gaveta (Salvar e fechar · Descartar · Continuar editando), sem window.confirm', () => {
    const estado = estadoDoRetrato(CADASTRO);
    estado.produto.titulo = 'Novo';
    const saida = desenharGaveta(CADASTRO, { estado, pedido: { seguir: nada } });
    assert.ok(texto(saida).includes('Há alterações não salvas.'));
    for (const acao of ['salvar-e-fechar', 'descartar', 'continuar-editando']) assert.ok(saida.includes(`data-acao="${acao}"`), acao);
    assert.ok(!saida.includes('data-acao="salvar-cadastro"'), 'o rodapé vira a confirmação');
    assert.ok(tag(saida, 'data-acao="descartar"').includes('text-trilha-bad'));
    const fonte = ['PainelDoCadastro.jsx', 'PecasDoCadastro.jsx', 'useCadastroDoProduto.js', 'NovoProduto.jsx']
        .map((f) => lerSemComentarios(`resources/js/Components/Trilha/Cadastro/${f}`)).join('\n');
    assert.equal(fonte.match(/window\.confirm|\balert\(/), null);
});

test('abrindo: "Abrindo o cadastro do produto…" (ocupado); o GET que falhou tem "Tentar de novo"', () => {
    const abrindo = desenharGaveta(null, { carregando: true }, { produto: produtoDaTrilha(9) });
    assert.ok(texto(abrindo).includes('Abrindo o cadastro do produto…') && texto(abrindo).includes('Criado-mudo 2 gavetas'));
    assert.match(abrindo, /aria-busy="true"/);
    assert.ok(!abrindo.includes('<form'));

    const falhou = desenharGaveta(null, { erroAoAbrir: 'O servidor falhou. Tente de novo em instantes.' }, { produto: produtoDaTrilha(9) });
    assert.ok(texto(falhou).includes('O servidor falhou. Tente de novo em instantes.'));
    assert.ok(falhou.includes('data-acao="tentar-de-novo"'));
});

// ─── `e:novo` e o quadro da Lista ───────────────────────────────────────────

test('"+ Produto na lista" (e:novo): só Nome e SKU, "Criar produto" ligado ao formulário; nada de categoria ainda', () => {
    const saida = renderToStaticMarkup(h(M.novo.default, { escrita: PROPS_TRABALHO.escrita, onCriado: async () => {}, onAvisar: nada }));
    const t = texto(saida);
    for (const s of ['Produto novo', 'Nome do item', 'SKU', 'O código do produto na loja; único na empresa.', 'Criar produto',
        'Depois de criar, o produto abre aqui para completar o cadastro.']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    assert.equal((saida.match(/<input\b/g) ?? []).length, 2, 'dois campos');
    const idDoForm = saida.match(/<form id="([^"]+)"/)?.[1];
    const criar = tag(saida, 'data-acao="criar-produto"');
    assert.ok(idDoForm && criar.includes(`form="${idDoForm}"`) && criar.includes('type="submit"'), criar);
    assert.ok(tag(saida, 'data-foco-inicial', 'input').includes('aria-required="true"'));
    for (const proibido of VOCABULARIO_PROIBIDO) assert.equal(t.match(proibido), null, `${proibido}`);
});

test('o quadro "Pelo sistema" da equipe (na pasta lazy): um produto por vez, aqui dentro', () => {
    const t = texto(renderToStaticMarkup(h(M.quadros.default)));
    assert.ok(t.includes('Pelo sistema') && t.includes('“+ Produto na lista” pede o nome e o SKU'));
    assert.ok(!t.includes('Pela planilha'));
});

test('as peças do produto: a família nova ganha "(nova)"; o SKU do Bling é só leitura', () => {
    const produto = { nome: 'Mesa', familia: 'Mesas', ambientes: ['Sala', 'Varanda'], titulo: '' };
    const listas = { familias: [{ id: 1, nome: 'Mesas' }], ambientes: [{ id: 1, nome: 'Sala' }] };
    const saida = renderToStaticMarkup(h(M.produto.CamposDoProduto, { produto, listas, onCampo: nada }));
    assert.ok(!saida.includes('data-nome-novo'), 'a família existe');
    // O ambiente que não existe na lista: "(novo)" no chip.
    assert.match(texto(saida), /Varanda \(novo\)/);
    assert.match(saida, /aria-label="Tirar Sala"/);

    const nova = renderToStaticMarkup(h(M.produto.CamposDoProduto, { produto: { ...produto, familia: 'Mesas de centro' }, listas, onCampo: nada }));
    assert.match(nova, /data-nome-novo=""/);

    const bling = renderToStaticMarkup(h(M.produto.CampoDoSku, { variacao: { codigo: 'BL-1', estoque: '3' }, bling: true, onCampo: nada }));
    assert.ok(tag(bling, 'value="BL-1"', 'input').includes('readonly=""'));
    assert.ok(texto(bling).includes('Vem do Bling: muda lá.'));

    // O estoque tem a trava DELE (`estoque_do_bling`: só com a sincronização de estoque ligada) — o SKU do Bling
    // não leva o estoque junto.
    const estoqueLivre = renderToStaticMarkup(h(M.produto.CampoDoEstoque, { variacao: { codigo: 'BL-1', estoque: '3' }, onCampo: nada }));
    const campoLivre = tag(estoqueLivre, 'value="3"', 'input');
    assert.ok(campoLivre && !campoLivre.includes('readonly'));
    const estoqueDoBling = renderToStaticMarkup(h(M.produto.CampoDoEstoque, { variacao: { codigo: 'BL-1', estoque: '3' }, bling: true, onCampo: nada }));
    assert.ok(tag(estoqueDoBling, 'value="3"', 'input').includes('readonly=""'));
    assert.ok(texto(estoqueDoBling).includes('Vem do Bling: muda lá.'));
});
