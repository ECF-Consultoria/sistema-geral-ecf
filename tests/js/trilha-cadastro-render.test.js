import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import {
    CADASTRO,
    CADASTRO_CATEGORIA_TRAVADA,
    CADASTRO_COM_CATEGORIA,
    CADASTRO_ONDA_COMECOU,
    CADASTRO_TRAVADO,
    CAMPOS_DA_CATEGORIA,
    CANDIDATAS,
    MOTIVO_DA_TRAVA,
    PROPS_TRABALHO,
    SALVO_COMPLETO,
    SALVO_INCOMPLETO,
    TERMOS_DA_CATEGORIA,
} from './_trilha-exemplo.js';
import { categoriaDaGaveta, estadoDoRetrato, gruposDaFicha, mexido, resultadoDoSalvar, textoDaIa } from '../../resources/js/lib/trilhaCadastro.js';
import { camposDeMedidas, gruposSemMedidas } from '../../resources/js/lib/medidasDoProduto.js';
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
    campo: 'resources/js/Components/Trilha/Cadastro/CampoDaFicha.jsx',
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

    const caixas = ['onde-esta', 'lista', 'ficha', 'ofertas'].map((c) => saida.indexOf(`data-caixa="${c}"`));
    assert.ok(caixas.every((i, n) => i > 0 && (n === 0 || i > caixas[n - 1])), `ordem das caixas: ${caixas}`);
    // A ordem do §5.3: nome, CATEGORIA (F2, logo abaixo do nome), família, ambientes, título e, sem variação, SKU,
    // custo, estoque e embalagem; as medidas do produto montado; a descrição no fim.
    const campos = ['nome', 'categoria', 'familia', 'ambientes', 'titulo', 'codigo', 'custo', 'estoque', 'volumes', 'medidas-do-produto', 'descricao']
        .map((c) => saida.indexOf(`data-campo="${c}"`));
    assert.ok(campos.every((i, n) => i > 0 && (n === 0 || i > campos[n - 1])), `ordem dos campos: ${campos}`);

    // O "Onde está" com o dono da trilha de trabalho.
    assert.ok(t.includes('Lista de produtos · Equipe ECF'), t);

    // A pílula e as faltas: as do SERVIDOR, nada recalculado.
    assert.match(saida, /data-caixa="lista"[\s\S]*?faltam 8/);
    for (const falta of CADASTRO.situacao.faltas) assert.ok(t.includes(falta), `falta não mostrada: ${falta}`);
    // F2: a categoria e a ficha se completam AQUI; só as fotos e o frete (F3) ainda apontam o Portal da empresa.
    assert.ok(t.includes('As fotos e o frete ainda não se completam por aqui: use o Portal da empresa.'), t);
    assert.ok(!/categoria|ficha/i.test(texto(saida.match(/data-nota-do-portal=""[\s\S]*?<\/div>/)?.[0] ?? '')), 'a nota não fala mais de categoria nem de ficha');
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

// ═══ F2 — a categoria e a ficha técnica ════════════════════════════════════
//
// Os hooks (`useCategoriaDaTrilha`, `useFichaDaTrilha`) são montados à mão, como o do cadastro: a gaveta desenha o
// que eles devolvem. A ficha "carregada" usa as funções de verdade sobre a definição do exemplo.

const BUSCA_PARADA = { situacao: 'parada', candidatas: [], consulta: null, mudouEm: 0 };

/** O que `useCategoriaDaTrilha` devolve, com `extra` por cima. */
const categoriaDoHook = (extra = {}) => ({
    sugestao: BUSCA_PARADA,
    sugerida: null,
    tentarSugestaoDeNovo: nada,
    aberto: null,
    consulta: '',
    mudarConsulta: nada,
    lista: BUSCA_PARADA,
    tentarListaDeNovo: nada,
    inicioDaArvore: null,
    aberturaDaArvore: 0,
    buscarNivel: async () => null,
    abrirBusca: nada,
    abrirNavegacao: nada,
    fechar: nada,
    escolher: nada,
    troca: null,
    confirmarTroca: nada,
    manterAtual: nada,
    termos: { categoria: null, dados: null, limite: null, carregando: false, erro: null },
    tentarTermosDeNovo: nada,
    ...extra,
});

/** O que `useFichaDaTrilha` devolve com os campos da categoria do exemplo já carregados. */
function fichaDoHook(retrato, extra = {}) {
    const doProduto = gruposDaFicha(CAMPOS_DA_CATEGORIA.grupos, retrato);

    return {
        definicao: { categoria: 'MLB1902', grupos: CAMPOS_DA_CATEGORIA.grupos },
        doProduto,
        grupos: gruposSemMedidas(doProduto),
        medidas: camposDeMedidas(doProduto),
        carregando: false,
        indisponivel: false,
        tentarDeNovo: nada,
        sugeridos: new Set(),
        esquecerSugestao: nada,
        ia: { rodando: false, resultado: null },
        podeIa: false,
        preencherComIa: nada,
        ...extra,
    };
}

/** O trecho do HTML de uma caixa (`data-caixa="…"`) até a próxima. */
const caixa = (html, nome) => {
    const inicio = html.indexOf(`data-caixa="${nome}"`);
    const fim = html.indexOf('data-caixa="', inicio + 12);

    return inicio < 0 ? '' : html.slice(inicio, fim < 0 ? undefined : fim);
};

test('F2 — "Categoria sugerida" aparece SEM aplicar: a gaveta segue sem categoria, nada mexido, a ficha espera a escolha', () => {
    const sugestao = { situacao: 'pronta', candidatas: CANDIDATAS.categorias, consulta: 'Criado-mudo 2 gavetas', mudouEm: 1 };
    const saida = desenharGaveta(CADASTRO, { categoria: categoriaDoHook({ sugestao, sugerida: CANDIDATAS.categorias[1] }) });
    const t = texto(saida);

    assert.ok(t.includes('Categoria do Mercado Livre *'), 'o rótulo, com o * do obrigatório');
    assert.ok(t.includes('Categoria sugerida: Casa, Móveis e Decoração › Quarto › Criados-mudos'), t);
    assert.match(saida, /data-categoria-sugerida="MLB1902"/);
    for (const acao of ['usar-categoria-sugerida', 'ver-outras-categorias', 'navegar-categorias']) assert.ok(saida.includes(`data-acao="${acao}"`), acao);
    assert.ok(tag(saida, 'data-acao="usar-categoria-sugerida"').includes('bg-trilha-navy'), '"Usar esta" é a ação principal');

    // Nada foi aplicado (D-06): sem categoria na gaveta, a ficha e as medidas pedem a escolha; o "Salvar" fica desligado.
    assert.ok(!saida.includes('data-categoria-escolhida'));
    assert.ok(caixa(saida, 'ficha').includes('data-ficha-sem-categoria') && t.includes('Digite o nome do item: o sistema sugere a categoria e abre os campos dela.'));
    assert.ok(t.includes('Escolha a categoria para ver as medidas do produto.'));
    assert.ok(tag(saida, 'data-acao="salvar-cadastro"').includes('disabled=""'));
    assert.ok(t.includes('Sem categoria, vale o limite de 60 letras.') && t.includes('0 de 60'));

    // Sem candidata, ou com o catálogo fora: "Não achei pelo nome. [Buscar] [Navegar] [Tentar de novo]".
    const naoAchei = desenharGaveta(CADASTRO, { categoria: categoriaDoHook({ sugestao: { ...sugestao, situacao: 'nada', candidatas: [] } }) });
    assert.ok(texto(naoAchei).includes('Não achei pelo nome.'));
    for (const acao of ['buscar-categoria', 'navegar-categorias', 'tentar-sugestao-de-novo']) assert.ok(naoAchei.includes(`data-acao="${acao}"`), acao);
    // Candidatas, mas nenhuma usável: "Ver outras" leva a elas.
    const semUsavel = desenharGaveta(CADASTRO, { categoria: categoriaDoHook({ sugestao: { ...sugestao, candidatas: [CANDIDATAS.categorias[0]] } }) });
    assert.ok(texto(semUsavel).includes('Não achei uma categoria certa pelo nome (1 parecida).') && semUsavel.includes('data-acao="ver-outras-categorias"'));
});

test('F2 — "Usar esta" põe a categoria NA GAVETA ("nova · grava ao salvar"); "Ver outras" diz por que não serve; trocar pede confirmação', () => {
    const estado = estadoDoRetrato(CADASTRO);
    estado.produto.categoria = categoriaDaGaveta(CANDIDATAS.categorias[1]);
    const escolhida = desenharGaveta(CADASTRO, { estado, ficha: fichaDoHook(CADASTRO) });
    const t = texto(escolhida);
    assert.match(escolhida, /data-categoria-escolhida="MLB1902"/);
    assert.ok(t.includes('Casa, Móveis e Decoração › Quarto › Criados-mudos') && t.includes('nova · grava ao salvar'));
    assert.ok(t.includes('Há mudanças não salvas.') && !tag(escolhida, 'data-acao="salvar-cadastro"').includes('disabled=""'), 'escolher é mexer: o "Salvar" grava');
    assert.ok(escolhida.includes('data-acao="trocar-categoria"'));
    // A ficha abre com os campos da categoria escolhida (mesmo antes de salvar).
    assert.match(caixa(escolhida, 'ficha'), /data-caminho-da-ficha="MLB1902"/);

    // A lista de "Ver outras"/"Buscar": a que não é a última diz "mais específica" (abre a navegação nela); a fechada
    // para produto novo fica desligada; a usável diz "Usar esta".
    const lista = { situacao: 'pronta', candidatas: CANDIDATAS.categorias, consulta: 'Criado-mudo', mudouEm: 1 };
    const aberta = desenharGaveta(CADASTRO, { categoria: categoriaDoHook({ aberto: 'buscar', consulta: 'Criado-mudo', lista }) });
    assert.match(aberta, /data-painel-da-categoria="buscar"/);
    const item = (id) => texto(aberta.match(new RegExp(`data-candidata="${id}"[\\s\\S]*?</button>`))?.[0] ?? '');
    assert.ok(item('MLB1901').includes('Escolha uma mais específica'), item('MLB1901'));
    assert.ok(item('MLB1902').includes('Usar esta'), item('MLB1902'));
    assert.ok(tag(aberta, 'data-candidata="MLB1903"').includes('disabled=""') && item('MLB1903').includes('Não aceita produto novo'));
    assert.ok(tag(aberta, 'data-busca-de-categoria', 'input').includes('value="Criado-mudo"'));

    // "Navegar": a árvore nível a nível, dentro da gaveta (abre carregando o 1º nível).
    const arvore = desenharGaveta(CADASTRO, { categoria: categoriaDoHook({ aberto: 'navegar' }) });
    assert.match(arvore, /data-painel-da-categoria="navegar"/);
    assert.ok(texto(arvore).includes('Todas as categorias') && texto(arvore).includes('Carregando as categorias…'));

    // Trocar a que já estava: a confirmação DENTRO da gaveta (nada de window.confirm), com o foco em "Manter a atual".
    const troca = desenharGaveta(CADASTRO_COM_CATEGORIA, {
        categoria: categoriaDoHook({ troca: { id: 'MLB777', nome: 'Mesas de cabeceira', caminho_texto: 'Casa > Mesas de cabeceira' } }),
        ficha: fichaDoHook(CADASTRO_COM_CATEGORIA),
    });
    const tt = texto(troca);
    assert.match(tt, /Trocar para Casa › Mesas de cabeceira ?\?/);
    assert.ok(tt.includes('Os campos da ficha que a categoria nova não tem saem quando você salvar.'), tt);
    assert.ok(troca.includes('data-acao="confirmar-troca-de-categoria"') && troca.includes('data-acao="manter-categoria"'));
});

test('F2 — a ficha: * nos obrigatórios, que vêm primeiro; "Outras características (N)" recolhidas; o texto da equipe; Marca com sugestões', () => {
    const saida = desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA) });
    const ficha = caixa(saida, 'ficha');
    const t = texto(ficha);

    assert.ok(t.includes('Ficha técnica do Mercado Livre'));
    assert.ok(t.includes('Os campos vêm da categoria do Mercado Livre. Os marcados com * são os obrigatórios da categoria; a revisão do Publicador ainda confere outros antes de publicar.'));
    assert.match(ficha, /data-caminho-da-ficha="MLB1902"/);
    assert.ok(t.includes('1 obrigatória faltando'), 'falta o Material (a Marca está salva)');
    // O filtro, com as contas de lib/fichaLayout (as medidas do produto montado ficam fora: são da Lista).
    for (const [chave, n] of [['todas', 7], ['faltando', 6], ['preenchidas', 1]]) {
        assert.match(ficha, new RegExp(`data-filtro-da-ficha="${chave}"[^>]*>[^<]*<span[^>]*>${n}<`), chave);
    }

    // As obrigatórias primeiro, cada uma com "*"; nada de `required` (o obrigatório vazio grava e vira falta).
    const obrigatorias = ficha.match(/data-grupo-tecnico="obrigatorias"[\s\S]*?data-grupo-tecnico="outras"/)?.[0] ?? '';
    const rotulos = [...obrigatorias.matchAll(/data-obrigatorio="sim"[\s\S]*?(<label[\s\S]*?<\/label>)/g)].map((m) => texto(m[1]).trim());
    assert.deepEqual(rotulos, ['Marca *', 'Material principal *']);
    assert.equal((obrigatorias.match(/data-obrigatorio="nao"/g) ?? []).length, 0);
    assert.doesNotMatch(saida, /\srequired=""/);
    assert.ok(tag(ficha, 'value="ECF Móveis"', 'input').includes('aria-required="true"'));
    // A Marca é texto livre com sugestões (`datalist`).
    assert.match(ficha, /<datalist[^>]*data-sugestoes=""[^>]*><option value="Genérica"><\/option><option value="ECF Móveis">/);

    // As outras, recolhidas: o botão diz quantas, e os campos não estão na tela.
    assert.ok(tag(ficha, 'data-acao="outras-caracteristicas"').includes('aria-expanded="false"'));
    assert.ok(t.includes('Outras características (5)'));
    assert.ok(!t.includes('Com gavetas') && !t.includes('Acabamento'));
    // Fora da ficha: o Modelo (o servidor nem manda) e, no produto que varia por cor, o campo Cor.
    assert.ok(!t.includes('Modelo'));
    const porCor = desenharGaveta({ ...CADASTRO_COM_CATEGORIA, produto: { ...CADASTRO_COM_CATEGORIA.produto, eixo: 'cor' } }, { ficha: fichaDoHook({ ...CADASTRO_COM_CATEGORIA, produto: { ...CADASTRO_COM_CATEGORIA.produto, eixo: 'cor' } }) });
    assert.ok(texto(caixa(porCor, 'ficha')).includes('Outras características (4)'));

    // As medidas do produto montado: na caixa da Lista, perto da embalagem — campos da ficha, com a unidade.
    const medidas = saida.match(/data-campo="medidas-do-produto"[\s\S]*?data-campo="descricao"/)?.[0] ?? '';
    assert.ok(caixa(saida, 'lista').includes('data-campo="medidas-do-produto"'));
    assert.ok(texto(medidas).includes('Largura') && texto(medidas).includes('Altura'));
    assert.equal((medidas.match(/data-unidade-sufixo=""[^>]*>cm</g) ?? []).length, 2);

    // Os campos da categoria ainda carregando / fora do ar.
    const carregando = caixa(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA, { carregando: true, grupos: [], medidas: [] }) }), 'ficha');
    assert.ok(texto(carregando).includes('Carregando os campos da categoria…'));
    const fora = caixa(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA, { indisponivel: true, grupos: [], medidas: [] }) }), 'ficha');
    assert.ok(texto(fora).includes('Não deu para carregar os campos da categoria agora.') && fora.includes('data-acao="recarregar-ficha"'));

    // O erro do passo 2 (422 por campo) marca o campo da ficha.
    const comErro = desenharGaveta(CADASTRO_COM_CATEGORIA, {
        ficha: fichaDoHook(CADASTRO_COM_CATEGORIA),
        erros: { porChave: {}, soltas: [], ficha: { MAIN_MATERIAL: 'Escolha uma das opções.' } },
        erroGeral: 'Os dados do produto foram salvos; confira os campos marcados na ficha.',
    });
    assert.match(comErro, /data-erro-do-campo-tecnico=""[^>]*>Escolha uma das opções\.</);
    assert.ok(texto(comErro).includes('Os dados do produto foram salvos; confira os campos marcados na ficha.'));
});

test('F2 — cada tipo de campo da ficha, com os data-* do Portal: Sim/Não, unidade, várias opções, "Outro", "Não se aplica"', () => {
    const campos = Object.fromEntries(CAMPOS_DA_CATEGORIA.grupos.flatMap((g) => g.campos).map((c) => [c.id, c]));
    const desenhar = (id, atual = null, extra = {}) => renderToStaticMarkup(h(M.campo.default, { campo: campos[id], atual, onMudar: nada, ...extra }));

    const simNao = desenhar('WITH_DRAWERS', { valor: 'Sim', unidade: '' });
    assert.match(simNao, /data-campo-tecnico="" data-tipo="sim_nao" data-obrigatorio="nao"/);
    assert.ok(tag(simNao, 'data-opcao="Sim"').includes('aria-pressed="true"') && tag(simNao, 'data-opcao="Não"').includes('aria-pressed="false"'));

    const peso = desenhar('MAX_WEIGHT', { valor: '30.5', unidade: 'g' });
    assert.ok(tag(peso, 'value="30,5"', 'input'), 'o número com vírgula');
    assert.match(peso, /<select[^>]*aria-label="Unidade de Peso máximo suportado"/);
    assert.match(desenhar('WIDTH'), /data-unidade-sufixo=""[^>]*>cm</);

    const estilos = desenhar('STYLES', { valor: ['10'], unidade: '' });
    assert.ok(tag(estilos, 'data-opcao="10"', 'input').includes('checked=""') && !tag(estilos, 'data-opcao="11"', 'input').includes('checked'));
    assert.match(estilos, /data-multivalor=""/);

    const material = desenhar('MAIN_MATERIAL', { valor: 'Pinus', unidade: '', outro: true });
    assert.ok(texto(material).includes('Outro (escrever)'));
    assert.ok(tag(material, 'data-valor-proprio', 'input').includes('value="Pinus"'));
    assert.match(material, /data-obrigatorio="sim"/);

    const acabamento = desenhar('FINISH', { valor: 'Verniz', unidade: '', naoSeAplica: true });
    assert.match(acabamento, /data-nao-se-aplica="sim"/);
    assert.ok(tag(acabamento, 'data-acao="nao-se-aplica"', 'input').includes('checked=""'));
    assert.match(acabamento, /<fieldset disabled=""/, 'marcado, o controle trava (e o valor fica guardado)');
    assert.ok(!desenhar('BRAND').includes('data-acao="nao-se-aplica"'), 'só onde o servidor oferece');

    const comErroEIa = desenhar('BRAND', { valor: 'X', unidade: '' }, { erro: 'Até 60 letras.', sugeridoIa: true });
    assert.match(comErroEIa, /role="alert"[^>]*data-erro-do-campo-tecnico=""[^>]*>Até 60 letras\.</);
    assert.ok(tag(comErroEIa, 'value="X"', 'input').includes('aria-invalid="true"'));
    assert.match(comErroEIa, /data-sugestao-ia=""[^>]*>sugestão da IA</);
});

test('F2 — categoria TRAVADA (o produto já está no Publicador): só leitura, "Para trocar, use o editor do Publicador"; o resto segue editável', () => {
    const produto = { ...produtoDaTrilha(9), url_publicador: '/mlb/anuncios/publicador/produtos/90/editor' };
    const saida = desenharGaveta(CADASTRO_CATEGORIA_TRAVADA, { ficha: fichaDoHook(CADASTRO_CATEGORIA_TRAVADA) }, { produto });
    const t = texto(saida);
    assert.match(saida, /data-categoria-travada=""/);
    assert.ok(t.includes('Casa, Móveis e Decoração › Quarto › Criados-mudos') && t.includes('Para trocar, use o editor do Publicador.'));
    assert.match(saida, /href="\/mlb\/anuncios\/publicador\/produtos\/90\/editor"[^>]*data-link="publicador-categoria"/);
    for (const acao of ['trocar-categoria', 'usar-categoria-sugerida', 'buscar-categoria', 'navegar-categorias']) assert.ok(!saida.includes(`data-acao="${acao}"`), acao);
    // O produto continua editável (só a categoria trava): sem o fieldset desligado, e a ficha aberta.
    assert.doesNotMatch(saida, /<fieldset disabled=""/);
    assert.match(caixa(saida, 'ficha'), /data-filtro-da-ficha="todas"/);

    // O produto que andou (só leitura): a categoria aparece, sem nenhuma ação.
    const travado = desenharGaveta(CADASTRO_TRAVADO);
    assert.match(travado, /data-categoria-travada=""/);
    assert.ok(texto(travado).includes('Casa › Móveis › Cadeiras'));
    assert.ok(!travado.includes('data-acao="trocar-categoria"') && !travado.includes('Para trocar, use o editor do Publicador.'));
});

test('F2b — "Preencher com IA" só com a categoria SALVA ("usa IA" ao lado); escolhida e não salva, só o aviso; o resultado e as sugestões marcadas', () => {
    const salva = desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA, { podeIa: true }) });
    assert.ok(salva.includes('data-acao="preencher-com-ia"'));
    assert.match(salva, /data-usa-ia=""[^>]*>usa IA</);

    // Escolhida e ainda não salva: nada de botão — só o aviso.
    const estado = estadoDoRetrato(CADASTRO);
    estado.produto.categoria = categoriaDaGaveta(CANDIDATAS.categorias[1]);
    const naoSalva = desenharGaveta(CADASTRO, { estado, ficha: fichaDoHook(CADASTRO) });
    assert.ok(!naoSalva.includes('data-acao="preencher-com-ia"'));
    assert.ok(texto(naoSalva).includes('Com a categoria salva, a IA pode preencher os campos vazios (usa IA).'));
    // Trocada e não salva: o aviso pede para salvar a nova.
    const trocada = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    trocada.produto.categoria = { id: 'MLB777', nome: 'Mesas de cabeceira', caminho_texto: 'Casa > Mesas de cabeceira' };
    const tt = texto(desenharGaveta(CADASTRO_COM_CATEGORIA, { estado: trocada, ficha: fichaDoHook(CADASTRO_COM_CATEGORIA, { definicao: { categoria: 'MLB777', grupos: CAMPOS_DA_CATEGORIA.grupos } }) }));
    assert.ok(tt.includes('Salve a categoria nova para a IA preencher os campos vazios (usa IA).'), tt);
    // O produto só para leitura não oferece nada.
    assert.ok(!desenharGaveta(CADASTRO_TRAVADO, { ficha: fichaDoHook(CADASTRO_TRAVADO) }).includes('usa IA'));

    // Depois do clique: o resultado em uma frase e os campos que a IA preencheu marcados (ainda não salvos).
    const r = { aplicados: 2, naoSeAplica: 0 };
    const estadoIa = estadoDoRetrato(CADASTRO_COM_CATEGORIA);
    estadoIa.ficha.MAIN_MATERIAL = { valor: '1', unidade: '' };
    const depois = desenharGaveta(CADASTRO_COM_CATEGORIA, {
        estado: estadoIa,
        ficha: fichaDoHook(CADASTRO_COM_CATEGORIA, { podeIa: true, sugeridos: new Set(['MAIN_MATERIAL']), ia: { rodando: false, resultado: { ...r, texto: textoDaIa(r) } } }),
    });
    assert.match(depois, /data-resultado-ia="ok"/);
    assert.ok(texto(depois).includes('A IA preencheu 2 campos. Confira os marcados "sugestão da IA" e clique em Salvar.'));
    assert.equal((depois.match(/data-sugestao-ia=""/g) ?? []).length, 1);
    assert.ok(texto(depois).includes('Há mudanças não salvas.'), 'nada gravou: o "Salvar" é que grava');
    // Rodando: o botão fica ocupado (continua focável).
    const rodando = desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA, { podeIa: true, ia: { rodando: true, resultado: null } }) });
    assert.ok(tag(rodando, 'data-acao="preencher-com-ia"').includes('aria-disabled="true"') && texto(rodando).includes('A IA está lendo o produto…'));
});

test('F2 — o título com os termos e o limite da categoria: o chip do termo que o título já tem vem marcado', () => {
    const termos = { categoria: 'MLB1902', dados: TERMOS_DA_CATEGORIA, limite: 70, carregando: false, erro: null };
    const saida = desenharGaveta(CADASTRO_COM_CATEGORIA, { categoria: categoriaDoHook({ termos }), ficha: fichaDoHook(CADASTRO_COM_CATEGORIA) });
    const t = texto(saida);
    assert.ok(t.includes('28 de 70'), 'o contador contra o limite da categoria');
    assert.ok(!t.includes('Sem categoria, vale o limite'));
    assert.match(saida, /data-termos-mais-buscados="3"/);
    assert.ok(tag(saida, 'data-termo="jatobá"').includes('aria-pressed="true"'), 'o título tem "Jatobá"');
    assert.ok(tag(saida, 'data-termo="mesa de cabeceira"').includes('aria-pressed="false"'));
    // Termos fora do ar: dá para digitar, com "Tentar de novo".
    const fora = desenharGaveta(CADASTRO_COM_CATEGORIA, {
        categoria: categoriaDoHook({ termos: { ...termos, dados: null, erro: 'Não deu para carregar os termos agora. O título pode ser digitado normalmente.' } }),
    });
    assert.match(fora, /data-erro-termos=""/);
});

// ═══ Revisão da F1 (03/11/2026) ════════════════════════════════════════════

test('revisão F1 — o ambiente digitado e não juntado: a gaveta diz que ele entra ao salvar, e o "Salvar" liga', () => {
    const listas = { familias: [], ambientes: [{ id: 1, nome: 'Sala' }] };
    const campos = renderToStaticMarkup(h(M.produto.CamposDoProduto, {
        produto: { nome: 'Mesa', familia: '', ambientes: ['Sala'], ambiente_digitado: 'Varanda', titulo: '' }, listas, onCampo: nada,
    }));
    assert.ok(tag(campos, 'role="combobox"', 'input') && campos.includes('value="Varanda"'), 'o texto mora no estado da gaveta');
    assert.match(campos, /data-ambiente-digitado=""[^>]*>“Varanda” entra como ambiente ao salvar \(Enter junta agora\)\.</);
    // Igual a um chip, nada a avisar.
    const repetido = renderToStaticMarkup(h(M.produto.CamposDoProduto, {
        produto: { nome: 'Mesa', familia: '', ambientes: ['Sala'], ambiente_digitado: 'sala', titulo: '' }, listas, onCampo: nada,
    }));
    assert.ok(!repetido.includes('data-ambiente-digitado'));

    const estado = estadoDoRetrato(CADASTRO);
    estado.produto.ambiente_digitado = 'Varanda';
    const gaveta = desenharGaveta(CADASTRO, { estado });
    assert.ok(texto(gaveta).includes('Há mudanças não salvas.'));
    assert.ok(!tag(gaveta, 'data-acao="salvar-cadastro"').includes('disabled=""'));
});

test('revisão F1 — o SKU que tinha anúncio na espera: o aviso no lugar do formulário, sem "Mapear agora"; depois, só leitura pelo retrato', () => {
    const motivo = 'O anúncio deste SKU já está no ar: o cadastro não muda mais por aqui.';
    const absorvido = {
        ...SALVO_INCOMPLETO,
        situacao: { ...SALVO_INCOMPLETO.situacao, etapa: null, faltas: [] },
        edicao: { livre: false, motivo, onda_comecou: null },
    };
    const resultado = resultadoDoSalvar(CADASTRO, absorvido, null, { absorvidos: 1 });
    const saida = desenharGaveta(absorvido, { resultado });
    const t = texto(saida);
    assert.match(saida, /data-resultado-do-cadastro="absorvido" role="alert"/);
    assert.ok(t.includes('Este SKU já tinha anúncio: o produto ficou ligado a ele e vai direto para a etapa 6.'));
    assert.ok(!t.includes('Lista completa') && !saida.includes('data-acao="mapear-agora"'));
    assert.match(saida, /data-acao="continuar-editando"[^>]*>Ver o cadastro</);

    // De volta ao cadastro: o retrato novo diz que não é mais editável — só leitura, com o motivo do servidor.
    const depois = desenharGaveta(absorvido);
    assert.ok(texto(depois).includes(`Só leitura ${motivo}`));
    assert.match(depois, /<fieldset disabled=""/);
    assert.ok(!depois.includes('data-acao="salvar-cadastro"'));
});

test('revisão F1 — logo depois de criar, o foco vai para o "Usar esta" da sugestão (uma vez, se a pessoa não saiu do nome)', () => {
    const escolha = lerSemComentarios('resources/js/Components/Trilha/Cadastro/EscolhaDaCategoria.jsx');
    assert.match(escolha, /<Botao ref=\{refDoUsarEsta\} primario data-acao="usar-categoria-sugerida"/);
    const painel = lerSemComentarios('resources/js/Components/Trilha/Cadastro/PainelDoCadastro.jsx');
    assert.match(painel, /if \(!recemCriado \|\| focouNaSugestao\.current \|\| !sugerida \|\| !usarEsta\.current/);
    assert.match(painel, /if \(!noComeco \|\| c\.temMudanca\) return;\s*focouNaSugestao\.current = true;\s*usarEsta\.current\.focus\(\);/);
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /recemCriado\.current = id;\s*trocarAberto\(chaveDeCadastro\(\{ id \}\)\);/);
    assert.match(pagina, /recemCriado=\{recemCriado\.current === painel\.produto\.id\}/);
});

test('revisão F1 — o comentário da guarda não promete o VOLTAR do navegador (o popstate do Inertia não passa pelo `before`)', () => {
    const fonte = fs.readFileSync(path.resolve(RAIZ, 'resources/js/Components/Trilha/Cadastro/useCadastroDoProduto.js'), 'utf8');
    assert.ok(!fonte.includes('o voltar que vira visita'));
    assert.ok(fonte.includes('O que a guarda NÃO cobre: o botão VOLTAR do navegador.'));
});

test('F2 — as listas abrem dentro da gaveta: nada de portal nem Radix; Esc fecha só a lista (preventDefault)', () => {
    const fonte = ['EscolhaDaCategoria.jsx', 'CaixaDaFicha.jsx', 'CampoDaFicha.jsx']
        .map((f) => lerSemComentarios(`resources/js/Components/Trilha/Cadastro/${f}`)).join('\n');
    assert.equal(fonte.match(/createPortal|@radix-ui|Components\/Portal\//), null);
    const escolha = lerSemComentarios('resources/js/Components/Trilha/Cadastro/EscolhaDaCategoria.jsx');
    assert.match(escolha, /evento\.key !== 'Escape' \|\| evento\.defaultPrevented \|\| !cat\.aberto\) return;\s*evento\.preventDefault\(\);\s*cat\.fechar\(\);/);
});
