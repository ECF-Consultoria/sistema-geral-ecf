// ─── Exemplo de props da Trilha de produtos (porta da EQUIPE) ───────────────
//
// O formato é o contrato entre `App\Http\Controllers\Trilha\*` e as telas
// `Pages/Trilha/*`. Os dados imitam a "Casa Jatobá" do protótipo do Erlon e
// cobrem todas as etapas, um produto com duas cores, combo agrupado, kit,
// combit, oferta sem produto na lista, publicação com falha e anúncio parcial.
//
// O nome começa com `_` para o `node --test "tests/js/**/*.test.js"` não o
// tomar por teste. `paraCliente()` imita o que o servidor tira na porta do
// cliente (cliente.ecf…): nada de link do Publicador, de preparo, de MLB, da
// lista de sugestões, de quem mapeou nem de quem criou a onda — e nenhuma
// escrita.
//
// `PROPS_EQUIPE` é a empresa SEM a trilha de trabalho (a 1ª entrega, como as
// outras empresas continuam). `PROPS_TRABALHO` é a mesma loja com a trilha de
// trabalho ligada (2ª entrega, 02/11/2026): ondas, mapeamento e os status da
// régua da onda, para a equipe com escrita. `MAPEAMENTO`, `PREVIA` e `CONCLUIDO`
// são as respostas JSON do painel de mapear (o contrato de
// `App\Http\Controllers\Trilha\MapeamentoDaTrilhaController`).
//
// 3ª entrega (o cadastro na trilha): cada produto da equipe com trabalho leva
// `cadastro_travado` (o que já andou só se lê); `CADASTRO` e as respostas do
// "Salvar" são o JSON da gaveta `e:{id}` (o contrato do §6.3 do desenho, de
// `App\Http\Controllers\Trilha\CadastroDaTrilhaController`).
//
// 4ª entrega, 4a: `ESCRITA.revisao.previa` (a URL com o marcador `__ITEM__`) e o
// JSON da prévia do anúncio (`PREVIA_DO_ANUNCIO`, `PREVIA_NAO_PRONTA` e o 409
// `CONFLITO_DA_PREVIA`) — o contrato do §19.5 do desenho.
//
// 4c: `ESCRITA.revisao.preco` (a URL do `PUT`, com o marcador) e as respostas do
// preço ajustável (`PREVIA_COM_PRECO`, `PREVIA_POR_COR`, `PRECO_SALVO`,
// `PRECO_ABAIXO_DO_MINIMO` e os erros 409/422) — o contrato do §19.11.
//
// 4d: `ESCRITA.revisao.publicar` e `ESCRITA.revisao.publicacao` (o `POST` e o `GET`,
// com o marcador) e as respostas de aprovar e publicar (`PUBLICACAO_*`, as prévias
// com a publicação andando e os erros 403/409/422) — o contrato do §19.13.
//
// 4f: `CLIENTE_ESCRITA` (a base das fotos do CLIENTE, com o marcador `__PRODUTO__`),
// `PROPS_CLIENTE_COM_FOTOS` (a porta do cliente de verdade com ela) e o quadro de
// fotos (`QUADRO_*`, `CONFLITO_DAS_FOTOS`, `ERROS_DAS_FOTOS`…) — o contrato do §19.15.

export const PROPS_EQUIPE = {
    visao: 'equipe',
    sessao_equipe: false,
    empresa: { id: 459, nome: 'Casa Jatobá', logo_url: null },
    voltar: { url: '/trilha', rotulo: 'Empresas' },
    trabalho: false,
    pode_editar: false,
    escrita: null,
    links: {
        portal_abrir: '/companies/459/portal',
        planejamento: null,
        produtos: null,
        publicador: '/mlb/anuncios/publicador/empresas/company-459',
    },
    ondas: [],
    gerado_em: '2026-10-30T18:00:00-03:00',
    produtos: [
        {
            id: 1, nome: 'Mesa de jantar jatobá 6 lugares', codigo: 'CJ-210', skus: ['CJ-210'], familia: 'Mesas', ambientes: ['Sala de jantar'],
            categoria: 'Mesas de Jantar', variacoes: 1, custo_min: 1480, custo_max: 1480, medidas: '180 × 90 × 76 cm', embalagem: '186 × 96 × 18 cm · 42 kg',
            fotos_min: 5, tem_descricao: true, ficha: 'completa', faltas: [], etapa: null,
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: '/mlb/anuncios/publicador/produtos/71/editor',
        },
        {
            id: 2, nome: 'Cadeira jatobá assento palhinha', codigo: 'CJ-211', skus: ['CJ-211-NAT', 'CJ-211-MEL'], familia: 'Cadeiras', ambientes: ['Sala de jantar'],
            categoria: 'Cadeiras', variacoes: 2, custo_min: 290, custo_max: 310, medidas: '45 × 52 × 92 cm', embalagem: '50 × 56 × 60 cm · 7 kg',
            fotos_min: 4, tem_descricao: true, ficha: 'completa', faltas: [], etapa: null,
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: '/mlb/anuncios/publicador/produtos/72/editor',
        },
        {
            id: 3, nome: 'Aparador buffet 4 portas jatobá', codigo: 'CJ-212', skus: ['CJ-212'], familia: 'Aparadores', ambientes: ['Sala de jantar'],
            categoria: 'Aparadores', variacoes: 1, custo_min: 1150, custo_max: 1150, medidas: '160 × 42 × 80 cm', embalagem: '166 × 48 × 30 cm · 38 kg',
            fotos_min: 4, tem_descricao: true, ficha: 'completa', faltas: [], etapa: null,
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: '/mlb/anuncios/publicador/produtos/73/editor',
        },
        {
            id: 4, nome: 'Rack para TV 1,80 m madeira maciça', codigo: 'CJ-213', skus: ['CJ-213'], familia: 'Racks', ambientes: ['Sala de estar'],
            categoria: 'Racks', variacoes: 1, custo_min: 890, custo_max: 890, medidas: '180 × 40 × 55 cm', embalagem: '186 × 46 × 22 cm · 31 kg',
            fotos_min: 6, tem_descricao: true, ficha: 'completa', faltas: [], etapa: 'mapa',
            sugestoes_pendentes: [
                { fase: 'combo', nome: 'Combo 2 Racks para TV 1,80 m', sku: 'CJ-213-CB2' },
                { fase: 'kit', nome: 'Rack para TV 1,80 m + Painel ripado 2,20 m', sku: 'KT-CJ-213-CJ-214' },
            ],
            sugestoes_pendentes_total: 2, url_ficha: null, url_publicador: '/mlb/anuncios/publicador/produtos/74/editor',
        },
        {
            id: 5, nome: 'Painel ripado para TV 2,20 m', codigo: 'CJ-214', skus: ['CJ-214'], familia: 'Racks', ambientes: ['Sala de estar'],
            categoria: 'Painéis para TV', variacoes: 1, custo_min: 760, custo_max: 760, medidas: '220 × 3 × 140 cm', embalagem: '226 × 8 × 30 cm · 22 kg',
            fotos_min: 3, tem_descricao: true, ficha: 'completa', faltas: [], etapa: 'mapa',
            sugestoes_pendentes: [{ fase: 'kit', nome: 'Rack para TV 1,80 m + Painel ripado 2,20 m', sku: 'KT-CJ-213-CJ-214' }],
            sugestoes_pendentes_total: 1, url_ficha: null, url_publicador: null,
        },
        {
            id: 6, nome: 'Banqueta alta jatobá 75 cm', codigo: 'CJ-215', skus: ['CJ-215'], familia: 'Cadeiras', ambientes: ['Cozinha'],
            categoria: 'Banquetas', variacoes: 1, custo_min: 210, custo_max: 210, medidas: '38 × 38 × 75 cm', embalagem: '42 × 42 × 40 cm · 5 kg',
            fotos_min: 1, tem_descricao: false, ficha: 'completa', faltas: ['descrição', 'fotos (1 de 3)'], etapa: 'lista',
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: null,
        },
        {
            id: 7, nome: 'Sofá 3 lugares linho cru', codigo: 'CJ-216', skus: ['CJ-216'], familia: 'Sofás', ambientes: ['Sala de estar'],
            categoria: 'Sofás', variacoes: 1, custo_min: 1950, custo_max: 1950, medidas: '210 × 92 × 85 cm', embalagem: null,
            fotos_min: 2, tem_descricao: false, ficha: 'incompleta',
            faltas: ['medidas', 'peso', 'descrição', 'fotos (2 de 3)', 'ficha: material do estofado'], etapa: 'lista',
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: null,
        },
        {
            id: 8, nome: 'Poltrona decorativa jatobá', codigo: 'CJ-217', skus: ['CJ-217'], familia: null, ambientes: [],
            categoria: 'Poltronas', variacoes: 1, custo_min: 620, custo_max: 620, medidas: null, embalagem: '80 × 85 × 60 cm · 14 kg',
            fotos_min: 3, tem_descricao: true, ficha: 'nao_conferida', faltas: ['família', 'ambiente'], etapa: 'lista',
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: null,
        },
        {
            id: 9, nome: 'Criado-mudo 2 gavetas', codigo: null, skus: ['CRIADO-01'], familia: null, ambientes: [],
            categoria: null, variacoes: 1, custo_min: null, custo_max: null, medidas: null, embalagem: null,
            fotos_min: 0, tem_descricao: false, ficha: 'sem_categoria',
            faltas: ['medidas', 'custo', 'categoria', 'título', 'família', 'ambiente', 'descrição', 'fotos (0 de 3)'], etapa: 'lista',
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: null,
        },
        {
            id: 10, nome: 'Espelho redondo moldura de madeira 60 cm', codigo: 'CJ-307', skus: ['CJ-307'], familia: 'Decoração', ambientes: ['Sala de estar'],
            categoria: 'Espelhos', variacoes: 1, custo_min: 140, custo_max: 140, medidas: '60 × 3 × 60 cm', embalagem: '66 × 8 × 66 cm · 6 kg',
            fotos_min: 4, tem_descricao: true, ficha: 'completa', faltas: [], etapa: null,
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: '/mlb/anuncios/publicador/produtos/80/editor',
        },
        {
            id: 11, nome: 'Prateleira flutuante jatobá 1 m', codigo: 'CJ-302', skus: ['CJ-302'], familia: 'Racks', ambientes: ['Sala de estar'],
            categoria: 'Prateleiras', variacoes: 1, custo_min: 180, custo_max: 180, medidas: '100 × 25 × 4 cm', embalagem: '106 × 30 × 8 cm · 4 kg',
            fotos_min: 4, tem_descricao: true, ficha: 'completa', faltas: [], etapa: null,
            sugestoes_pendentes: [], sugestoes_pendentes_total: 0, url_ficha: null, url_publicador: '/mlb/anuncios/publicador/produtos/81/editor',
        },
    ],
    ofertas: [
        {
            id: 's-1', tipo: 'Simples', nome: 'Mesa de jantar jatobá 6 lugares', sku: 'CJ-210', skus: ['CJ-210'],
            componentes: [{ produto_id: 1, quantidade: 1, sku: 'CJ-210', nome: 'Mesa de jantar jatobá 6 lugares' }],
            etapa: 'lanc', status: 'No ar · Clássico e Premium', alerta: false,
            anuncios: [{ tipo: 'Clássico', mlb: 'MLB3412345678', situacao: null }, { tipo: 'Premium', mlb: 'MLB3412345679', situacao: null }],
            preparo: { titulo: 'pronto', modelo: 'pronto', descricao: 'pronto', imagens: 'pronto' },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/71/editor',
        },
        {
            id: 's-2', tipo: 'Simples', nome: 'Cadeira jatobá assento palhinha', sku: 'CJ-211', skus: ['CJ-211-NAT', 'CJ-211-MEL'],
            componentes: [{ produto_id: 2, quantidade: 1, sku: 'CJ-211', nome: 'Cadeira jatobá assento palhinha' }],
            etapa: 'revisao', status: 'Pré-pronto · esperando a revisão', alerta: false, anuncios: [],
            preparo: { titulo: 'pronto', modelo: 'pronto', descricao: 'pronto', imagens: 'pronto' },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/72/editor?etapa=previa',
        },
        {
            id: 'c-2-4', tipo: 'Combo', nome: 'Combo 4 · Cadeira jatobá assento palhinha', sku: 'CJ-211-NAT-CB4', skus: ['CJ-211-NAT-CB4', 'CJ-211-MEL-CB4'],
            componentes: [{ produto_id: 2, quantidade: 4, sku: 'CJ-211', nome: 'Cadeira jatobá assento palhinha' }],
            etapa: 'plano', status: 'Espera a oferta simples ir ao ar', alerta: false, anuncios: [], preparo: null,
            onda_id: null, sem_produto: false, url_publicador: null,
        },
        {
            id: 's-3', tipo: 'Simples', nome: 'Aparador buffet 4 portas jatobá', sku: 'CJ-212', skus: ['CJ-212'],
            componentes: [{ produto_id: 3, quantidade: 1, sku: 'CJ-212', nome: 'Aparador buffet 4 portas jatobá' }],
            etapa: 'robo', status: 'O agente está escrevendo título e descrição', alerta: false, anuncios: [],
            preparo: { titulo: 'pronto', modelo: 'fazendo', descricao: 'fila', imagens: 'fila' },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/73/editor',
        },
        {
            id: 's-4', tipo: 'Simples', nome: 'Rack para TV 1,80 m madeira maciça', sku: 'CJ-213', skus: ['CJ-213'],
            componentes: [{ produto_id: 4, quantidade: 1, sku: 'CJ-213', nome: 'Rack para TV 1,80 m madeira maciça' }],
            etapa: 'revisao', status: 'Faltam 2 itens para publicar', alerta: true, anuncios: [],
            preparo: { titulo: 'pronto', modelo: 'pronto', descricao: 'pronto', imagens: null },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/74/editor?etapa=previa',
        },
        {
            id: 'o-31', tipo: 'Kit', nome: 'Kit rack 1,80 m + painel ripado 2,20 m jatobá', sku: 'KT-CJ-213-CJ-214', skus: ['KT-CJ-213-CJ-214'],
            componentes: [
                { produto_id: 4, quantidade: 1, sku: 'CJ-213', nome: 'Rack para TV 1,80 m madeira maciça' },
                { produto_id: 5, quantidade: 1, sku: 'CJ-214', nome: 'Painel ripado para TV 2,20 m' },
            ],
            etapa: 'plano', status: 'Sem onda', alerta: false, anuncios: [], preparo: null,
            onda_id: null, sem_produto: false, url_publicador: null,
        },
        {
            id: 'o-32', tipo: 'Combit', nome: 'Mesa de jantar jatobá 6 lugares + 6 cadeiras palhinha', sku: 'CT6-CJ-210-CJ-211', skus: ['CT6-CJ-210-CJ-211'],
            componentes: [
                { produto_id: 1, quantidade: 1, sku: 'CJ-210', nome: 'Mesa de jantar jatobá 6 lugares' },
                { produto_id: 2, quantidade: 6, sku: 'CJ-211', nome: 'Cadeira jatobá assento palhinha' },
            ],
            etapa: 'robo', status: 'Na fila do agente', alerta: false, anuncios: [],
            preparo: { titulo: 'fila', modelo: 'fila', descricao: 'fila', imagens: null },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/90/editor',
        },
        {
            id: 's-10', tipo: 'Simples', nome: 'Espelho redondo moldura de madeira 60 cm', sku: 'CJ-307', skus: ['CJ-307'],
            componentes: [{ produto_id: 10, quantidade: 1, sku: 'CJ-307', nome: 'Espelho redondo moldura de madeira 60 cm' }],
            etapa: 'lanc', status: 'No ar · falta o Premium', alerta: false,
            anuncios: [{ tipo: 'Clássico', mlb: 'MLB3488800012', situacao: 'Pausado' }],
            preparo: { titulo: 'pronto', modelo: 'pronto', descricao: 'pronto', imagens: 'pronto' },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/80/editor',
        },
        {
            id: 's-11', tipo: 'Simples', nome: 'Prateleira flutuante jatobá 1 m', sku: 'CJ-302', skus: ['CJ-302'],
            componentes: [{ produto_id: 11, quantidade: 1, sku: 'CJ-302', nome: 'Prateleira flutuante jatobá 1 m' }],
            etapa: 'revisao', status: 'A publicação falhou', alerta: true, anuncios: [],
            preparo: { titulo: 'pronto', modelo: 'pronto', descricao: 'pronto', imagens: 'erro' },
            onda_id: null, sem_produto: false, url_publicador: '/mlb/anuncios/publicador/produtos/81/editor?etapa=previa',
        },
        {
            id: 'o-40', tipo: 'Simples', nome: 'Nicho decorativo hexagonal', sku: 'CJ-303', skus: ['CJ-303'],
            componentes: [{ produto_id: null, quantidade: 1, sku: 'CJ-303', nome: 'Nicho decorativo hexagonal' }],
            etapa: 'lanc', status: 'No ar · Clássico e Premium', alerta: false,
            anuncios: [{ tipo: 'Clássico', mlb: 'MLB3400011223', situacao: null }, { tipo: 'Premium', mlb: 'MLB3400011224', situacao: null }],
            preparo: null, onda_id: null, sem_produto: true, url_publicador: null,
        },
    ],
};

/** O que a porta do cliente recebe: o mesmo, sem o que é só da equipe. */
export function paraCliente(props = PROPS_EQUIPE) {
    return {
        ...props,
        visao: 'cliente',
        voltar: { url: '/portal/459/inicio', rotulo: 'Voltar ao portal' },
        // O cliente nunca escreve: nem `pode_editar`, nem endereço de escrita.
        pode_editar: false,
        escrita: null,
        // Com a trilha de trabalho o mapeamento é da equipe: o atalho para o Planejamento do Portal sai.
        links: {
            portal_abrir: null,
            planejamento: props.trabalho ? null : '/portal/459/estrutura/sugestoes',
            produtos: '/portal/459/estrutura/produtos',
            publicador: null,
        },
        // A trava do cadastro (3ª entrega) é da equipe: `cadastro_travado` sai só em `paraEquipe`.
        produtos: props.produtos.map(({ url_publicador, sugestoes_pendentes, cadastro_travado, ...p }) => ({
            ...p,
            sugestoes_pendentes: [],
            mapeado_por: null,
            url_ficha: `/portal/459/estrutura/produtos/${p.id}`,
        })),
        ofertas: props.ofertas.map(({ url_publicador, preparo, ...o }) => ({
            ...o,
            preparo: null,
            anuncios: (o.anuncios ?? []).map((a) => ({ ...a, mlb: null })),
        })),
        // As ondas vão ao cliente só com nome, data e se começou (`ApresentacaoDaTrilha::ONDA_CLIENTE`).
        ondas: (props.ondas ?? []).map(({ id, nome, data, comecou }) => ({ id, nome, data, comecou: !!comecou })),
    };
}

// ═══ 2ª entrega: a mesma loja com a trilha de trabalho ═══════════════════════

/** Os endereços das escritas (`ApresentacaoDaTrilha::escrita`). */
export const ESCRITA = {
    ondas: 'http://localhost/trilha/459/ondas',
    planejamento: 'http://localhost/trilha/459/planejamento',
    produtos: 'http://localhost/trilha/459/produtos',
    // 3ª entrega, F2: a base das leituras da categoria e da ficha — relativa, como o servidor manda.
    categorias: '/trilha/459/categorias',
    // F7: a planilha por categoria (§17.3 do desenho) — baixar o modelo, a prévia e o gravar.
    planilha: {
        modelo: '/trilha/459/planilha/modelo',
        previa: '/trilha/459/planilha/previa',
        gravar: '/trilha/459/planilha',
    },
    // F8: importar os anúncios da loja (§18.3 do desenho) — listar, importar, o estado e limpar.
    mercado_livre: {
        anuncios: '/trilha/459/mercado-livre/anuncios',
        importar: '/trilha/459/mercado-livre/importar',
        estado: '/trilha/459/mercado-livre/estado',
        limpar: '/trilha/459/mercado-livre/limpar',
    },
    // 4ª entrega, 4a: a prévia do anúncio (§19.4 do desenho) — com o marcador `__ITEM__` no lugar do id do item.
    revisao: {
        previa: '/trilha/459/ofertas/__ITEM__/previa',
        // 4c: o preço ajustável (§19.11) — `PUT`, com o mesmo marcador.
        preco: '/trilha/459/ofertas/__ITEM__/preco',
        // 4d: aprovar e publicar (§19.13) — o `POST` e o `GET` do acompanhamento, com o mesmo marcador.
        publicar: '/trilha/459/ofertas/__ITEM__/publicar',
        publicacao: '/trilha/459/ofertas/__ITEM__/publicacao',
        // 4b: pedir ajuste ao robô (§19.14) — o `POST` e o `GET` do acompanhamento na MESMA URL, com o mesmo marcador.
        ajuste: '/trilha/459/ofertas/__ITEM__/ajuste',
    },
};

/**
 * As ondas como o servidor manda (pela data). A "Onda 1" já começou (03/11); a
 * "Onda 2" e a "Onda 3" começam depois. O id não segue a data de propósito.
 */
const ONDAS = [
    { id: 7, nome: 'Onda 1 · simples', data: '2026-11-03', comecou: true, criada_por: 'Rafael', criada_em: '2026-11-01T10:00:00-03:00' },
    { id: 9, nome: 'Onda 2', data: '2026-11-05', comecou: false, criada_por: 'Rafael', criada_em: '2026-11-02T09:00:00-03:00' },
    { id: 8, nome: 'Onda 3 · kits', data: '2026-11-10', comecou: false, criada_por: 'Erlon', criada_em: '2026-11-01T11:00:00-03:00' },
];

/** Cada oferta com a onda e o status da régua da onda (`SituacaoDasOfertas::naOnda`) quando ainda não andou. */
const NA_TRILHA_DE_TRABALHO = {
    's-2': { onda_id: 7 },
    's-3': { onda_id: 7 },
    's-4': { onda_id: 7 },
    'c-2-4': { onda_id: 9, etapa: 'robo', status: 'Na fila · começa em 05/11', alerta: false, preparo: null },
    'o-31': { onda_id: null, etapa: 'plano', status: 'Sem onda · falta escolher a data', alerta: true },
    'o-32': { onda_id: 8, etapa: 'robo', status: 'Na fila · começa em 10/11', alerta: false, preparo: null },
};

/** O Combo 2 do aparador, nascido de um mapeamento na trilha: no Planejamento, sem onda. */
const COMBO_DO_APARADOR = {
    id: 'c-3-2', tipo: 'Combo', nome: 'Combo 2 · Aparador buffet 4 portas jatobá', sku: 'CJ-212-CB2', skus: ['CJ-212-CB2'],
    componentes: [{ produto_id: 3, quantidade: 2, sku: 'CJ-212', nome: 'Aparador buffet 4 portas jatobá' }],
    etapa: 'plano', status: 'Sem onda · falta escolher a data', alerta: true, anuncios: [], preparo: null,
    onda_id: null, sem_produto: false, url_publicador: null,
};

/** O Combo 2 da mesa: na Onda 1, que já começou — e ainda não andou (o robô está na fila). */
const COMBO_DA_MESA = {
    id: 'c-1-2', tipo: 'Combo', nome: 'Combo 2 · Mesa de jantar jatobá 6 lugares', sku: 'CJ-210-CB2', skus: ['CJ-210-CB2'],
    componentes: [{ produto_id: 1, quantidade: 2, sku: 'CJ-210', nome: 'Mesa de jantar jatobá 6 lugares' }],
    etapa: 'robo', status: 'Na fila · começou em 03/11', alerta: false, anuncios: [], preparo: null,
    onda_id: 7, sem_produto: false, url_publicador: null,
};

/** Uma oferta da Lista SKUs sem produto na Lista e sem rascunho: fica fora das ondas (`TrilhaDaEmpresa`). */
const AVULSA_SEM_PRODUTO = {
    id: 'o-41', tipo: 'Simples', nome: 'Cabideiro de parede jatobá', sku: 'CJ-305', skus: ['CJ-305'],
    componentes: [{ produto_id: null, quantidade: 1, sku: 'CJ-305', nome: 'Cabideiro de parede jatobá' }],
    etapa: 'plano', status: 'Fora das ondas · sem produto na Lista', alerta: false, anuncios: [], preparo: null,
    onda_id: null, sem_produto: true, url_publicador: null,
};

/**
 * Os produtos com o cadastro TRAVADO (3ª entrega, pergunta 1): algum item da trilha que os leva já andou — a mesa
 * e o espelho estão no ar, a cadeira e a prateleira na revisão, o aparador com o robô e o rack com a Simples na
 * revisão. O painel ripado (só no kit que não andou) e os da Lista continuam editáveis.
 */
const TRAVADOS = new Set([1, 2, 3, 4, 10, 11]);

/** As que ainda entram, mudam ou saem de onda (`planejavel` = tem produto na Lista e não andou). */
const PLANEJAVEIS = new Set(['c-2-4', 'o-31', 'o-32', 'c-3-2', 'c-1-2']);

export const PROPS_TRABALHO = {
    ...PROPS_EQUIPE,
    trabalho: true,
    pode_editar: true,
    escrita: ESCRITA,
    ondas: ONDAS,
    produtos: PROPS_EQUIPE.produtos.map((p) => {
        // 3ª entrega: o produto que algum item da trilha já levou adiante tem o cadastro só para leitura.
        const travado = { cadastro_travado: TRAVADOS.has(p.id) };
        // O rack: a Simples dele já andou (está na revisão) — com a trilha de trabalho, ele já está mapeado.
        if (p.id === 4) return { ...p, ...travado, etapa: null, mapeado_em: null, mapeado_por: null };
        // O aparador foi mapeado na trilha.
        if (p.id === 3) return { ...p, ...travado, mapeado_em: '2026-11-01T14:32:00-03:00', mapeado_por: 'Rafael' };

        return { ...p, ...travado, mapeado_em: null, mapeado_por: null };
    }),
    ofertas: [
        ...PROPS_EQUIPE.ofertas.map((o) => ({ ...o, ...(NA_TRILHA_DE_TRABALHO[o.id] ?? {}) })),
        COMBO_DO_APARADOR,
        COMBO_DA_MESA,
        AVULSA_SEM_PRODUTO,
    ].map((o) => ({
        ...o,
        planejavel: PLANEJAVEIS.has(o.id),
        // A equipe pode tirar da onda a oferta que está numa onda que ainda não começou (`pode_tirar`, as duas portas).
        pode_tirar: o.onda_id != null && !ONDAS.find((w) => w.id === o.onda_id)?.comecou,
    })),
};

/** `GET {escrita.produtos}/5/mapeamento` — o painel ripado (3 cores, a Preto ainda sem oferta). Parceiros fora de ordem. */
export const MAPEAMENTO = {
    produto: {
        id: 5,
        nome: 'Painel ripado para TV 2,20 m',
        codigo: 'CJ-214',
        familia: 'Racks',
        ambientes: ['Sala de estar'],
        foto: '/storage/estrutura/cj-214-nat.jpg',
        cores: [
            { variacao_id: 51, valor: 'Natural', sku: 'CJ-214-NAT', foto: '/storage/estrutura/cj-214-nat.jpg', tem_oferta: true },
            { variacao_id: 52, valor: 'Mel', sku: 'CJ-214-MEL', foto: null, tem_oferta: true },
            { variacao_id: 53, valor: 'Preto', sku: 'CJ-214-PT', foto: null, tem_oferta: false },
        ],
    },
    combos: [
        { quantidade: 2, sugerido: true, existe: 'todas' },
        { quantidade: 3, sugerido: false, existe: 'nenhuma' },
        { quantidade: 4, sugerido: false, existe: 'algumas' },
        { quantidade: 6, sugerido: false, existe: 'nenhuma' },
    ],
    parceiros: [
        { produto_id: 2, nome: 'Cadeira jatobá assento palhinha', codigo: 'CJ-211', foto: null, ambiente: null, sugerido: null, casa: false, motivo: 'As cores não casam com as deste produto.' },
        { produto_id: 1, nome: 'Mesa de jantar jatobá 6 lugares', codigo: 'CJ-210', foto: null, ambiente: null, sugerido: null, casa: true, motivo: null },
        { produto_id: 11, nome: 'Prateleira flutuante jatobá 1 m', codigo: 'CJ-302', foto: null, ambiente: 'Sala de estar', sugerido: null, casa: true, motivo: null },
        { produto_id: 4, nome: 'Rack para TV 1,80 m madeira maciça', codigo: 'CJ-213', foto: '/storage/estrutura/cj-213.jpg', ambiente: 'Sala de estar', sugerido: 1, casa: true, motivo: null },
    ],
    mapeado_em: null,
    mapeado_por: null,
    max_componentes: 6,
};

/** `POST …/mapeamento/previa` com Combo 3, o rack 1× e a prateleira 2× (uma linha não dá). */
export const PREVIA = {
    linhas: [
        {
            chave: 's-5', tipo: 'Simples', nome: 'Painel ripado para TV 2,20 m', skus: ['CJ-214-NAT', 'CJ-214-MEL'], composicao: null,
            estado: 'sempre', detalhe: 'A cor Preto ainda não tem oferta: salve o produto no cadastro.', avisos: [],
        },
        {
            chave: 'c-5-2', tipo: 'Combo', nome: 'Combo 2 · Painel ripado para TV 2,20 m', skus: ['CJ-214-NAT-CB2', 'CJ-214-MEL-CB2'],
            composicao: '2× CJ-214-NAT', estado: 'ja_existe', detalhe: 'Já existe: CJ-214-NAT-CB2, CJ-214-MEL-CB2.', avisos: [],
        },
        {
            chave: 'c-5-3', tipo: 'Combo', nome: 'Combo 3 · Painel ripado para TV 2,20 m', skus: ['CJ-214-NAT-CB3', 'CJ-214-MEL-CB3'],
            composicao: '3× CJ-214-NAT', estado: 'nova', detalhe: 'Uma por cor (2); no Publicador as cores viram um anúncio só.', avisos: [],
        },
        {
            chave: 'c-5-4', tipo: 'Combo', nome: 'Combo 4 · Painel ripado para TV 2,20 m', skus: ['CJ-214-MEL-CB4'],
            composicao: '4× CJ-214-NAT', estado: 'nova_parcial', detalhe: 'Nova em 1 de 2 cores (as outras já existem).', avisos: [],
        },
        {
            chave: 'novo-1', tipo: 'Kit', nome: 'Kit rack 1,80 m + painel ripado 2,20 m natural', skus: ['KT-CJ-213-CJ-214-NAT'],
            composicao: '1× CJ-213 + 1× CJ-214-NAT', estado: 'nova', detalhe: null, avisos: ['O título passa de 60 caracteres: confira no Publicador.'],
        },
        {
            chave: 'novo-2', tipo: 'Combit', nome: 'Painel ripado 2,20 m natural + 2 prateleiras flutuantes', skus: ['CT2-CJ-214-NAT-CJ-302'],
            composicao: '1× CJ-214-NAT + 2× CJ-302', estado: 'nao_da', detalhe: 'O SKU passa de 120 caracteres: encurte na Lista SKUs.', avisos: [],
        },
    ],
    novas: 3,
    pode_concluir: false,
    mensagem: 'O SKU passa de 120 caracteres: encurte na Lista SKUs.',
};

/** `POST …/mapeamento` — o "Concluir": o Combo 3 (um item, duas cores) e o kit. */
export const CONCLUIDO = {
    criadas: [
        { chave: 'c-5-3', tipo: 'Combo', nome: 'Combo 3 · Painel ripado para TV 2,20 m', skus: ['CJ-214-NAT-CB3', 'CJ-214-MEL-CB3'] },
        { chave: 'o-77', tipo: 'Kit', nome: 'Kit rack 1,80 m + painel ripado 2,20 m natural', skus: ['KT-CJ-213-CJ-214-NAT'] },
    ],
    descartadas: 1,
    mensagem: '2 ofertas criadas.',
};

// ═══ 3ª entrega: a gaveta de cadastro (`e:{id}`) ═════════════════════════════

/** As listas da empresa (família e ambientes), como a rota 1 manda. */
const LISTAS = {
    familias: [
        { id: 1, nome: 'Aparadores' }, { id: 2, nome: 'Cadeiras' }, { id: 3, nome: 'Decoração' },
        { id: 4, nome: 'Mesas' }, { id: 5, nome: 'Racks' }, { id: 6, nome: 'Sofás' },
    ],
    ambientes: [{ id: 1, nome: 'Cozinha' }, { id: 2, nome: 'Sala de estar' }, { id: 3, nome: 'Sala de jantar' }],
};

const EIXOS = { cor: 'Cor', tamanho: 'Tamanho', voltagem: 'Voltagem', material: 'Material', sabor: 'Sabor', outro: 'Outro' };
const LIMITES = { fotos_por_variacao: 12, foto_mb: 10, descricao: 5000, linhas: 200 };

/**
 * `GET {escrita.produtos}/9/cadastro` — o criado-mudo, recém-criado pela trilha (só nome e SKU). A forma é a que o
 * servidor manda de verdade (conferida na integração da F1): o custo e as medidas vêm como NÚMERO (o cast do model),
 * não como o texto "890.00" do exemplo do desenho.
 */
export const CADASTRO = {
    produto: {
        id: 9, nome: 'Criado-mudo 2 gavetas', titulo: null, familia: null, ambientes: [], categoria: null,
        descricao: null, eixo: null, eixo_2: null, origem_ml: false,
    },
    variacoes: [
        {
            id: 91, codigo: 'CRIADO-01', valor: null, valor_2: null, ordem: 1, custo: null, estoque: null, volumes: [],
            bling: false, estoque_do_bling: false, dona_da_cor: true, logistica: 'pendente', fotos: [],
        },
    ],
    ficha: { salvos: [] },
    listas: LISTAS,
    eixos: EIXOS,
    limites: LIMITES,
    situacao: {
        etapa: 'lista',
        faltas: ['medidas', 'custo', 'categoria', 'título', 'família', 'ambiente', 'descrição', 'fotos (0 de 3)'],
        avisos: [], no_publicador: false, categoria_travada: false, mapeado_em: null,
    },
    edicao: { livre: true, motivo: null, onda_comecou: null },
    // F6: o sha1 do cadastro (sem estoque nem fotos) — a gaveta o devolve nas escritas que mudam conteúdo.
    versao: '3f786850e387550fdab836ed7e6dc881de23001b',
};

/** `POST …/9/linhas` com tudo o que a F1 cobre: salvo, mas ainda faltam a categoria e as fotos (HTTP 200). */
export const SALVO_INCOMPLETO = {
    ...CADASTRO,
    produto: {
        ...CADASTRO.produto, titulo: 'Criado-Mudo 2 Gavetas Jatobá', familia: 'Criados-mudos', ambientes: ['Quarto'],
        descricao: 'Criado-mudo em jatobá maciço com duas gavetas.',
    },
    variacoes: [{ ...CADASTRO.variacoes[0], custo: 1890, estoque: 4, volumes: [{ c: 50, l: 40, a: 60, kg: 18.5 }], logistica: 'me2' }],
    listas: {
        familias: [...LISTAS.familias, { id: 7, nome: 'Criados-mudos' }],
        ambientes: [...LISTAS.ambientes, { id: 4, nome: 'Quarto' }],
    },
    situacao: { ...CADASTRO.situacao, faltas: ['categoria', 'fotos (0 de 3)'] },
    erros: [],
    avisos: [],
    totais: { criadas: 0, atualizadas: 1, sem_mudanca: 0, com_erro: 0, absorvidos_da_espera: 0 },
    criadas_nas_listas: { familias: ['Criados-mudos'], ambientes: ['Quarto'] },
    mensagem: 'Salvo. Ainda faltam: categoria, fotos (0 de 3).',
};

/** O mesmo "Salvar", com a última falta resolvida: o produto saiu da Lista. */
export const SALVO_COMPLETO = {
    ...SALVO_INCOMPLETO,
    situacao: { ...SALVO_INCOMPLETO.situacao, etapa: 'mapa', faltas: [] },
    criadas_nas_listas: { familias: [], ambientes: [] },
    mensagem: 'Lista completa: o produto seguiu para o mapeamento.',
};

/** Um "Salvar" que parou no custo: a linha volta em `erros` (HTTP 200) e nada dela gravou. */
export const SALVO_COM_ERRO = {
    ...CADASTRO,
    erros: [{ indice: 0, chave: 'v91', codigo: 'CRIADO-01', mensagem: 'Use números no formato 1.234,56.', campos: { custo: 'Use números no formato 1.234,56.' } }],
    avisos: [],
    totais: { criadas: 0, atualizadas: 0, sem_mudanca: 0, com_erro: 1, absorvidos_da_espera: 0 },
    criadas_nas_listas: { familias: [], ambientes: [] },
    mensagem: 'Confira os campos marcados: o que está neles não foi salvo.',
};

/** O motivo da trava, como o servidor escreve (a gaveta e o 409). */
export const MOTIVO_DA_TRAVA = 'A oferta “Cadeira jatobá assento palhinha” já saiu do Planejamento (o robô já preparou, está na revisão ou no ar). '
    + 'Mudar o cadastro agora faria o robô refazer texto e imagens e não muda o anúncio no ar.';

/** `GET …/2/cadastro` — a cadeira (2 cores), que já está na revisão: só leitura. */
export const CADASTRO_TRAVADO = {
    ...CADASTRO,
    produto: {
        id: 2, nome: 'Cadeira jatobá assento palhinha', titulo: 'Cadeira Jatobá Assento Palhinha', familia: 'Cadeiras',
        ambientes: ['Sala de jantar'], categoria: { id: 'MLB193945', nome: 'Cadeiras', caminho_texto: 'Casa › Móveis › Cadeiras', estado: 'confirmada' },
        descricao: 'Cadeira em jatobá maciço.', eixo: 'cor', eixo_2: null, origem_ml: false,
    },
    variacoes: [
        { id: 21, codigo: 'CJ-211-NAT', valor: 'Natural', valor_2: null, ordem: 1, custo: 290, estoque: null, volumes: [{ c: 50, l: 56, a: 60, kg: 7 }], bling: false, estoque_do_bling: false, dona_da_cor: true, logistica: 'me2', fotos: [] },
        { id: 22, codigo: 'CJ-211-MEL', valor: 'Mel', valor_2: null, ordem: 2, custo: 310, estoque: 3, volumes: [{ c: 50, l: 56, a: 60, kg: 7 }], bling: false, estoque_do_bling: false, dona_da_cor: true, logistica: 'me2', fotos: [] },
    ],
    situacao: { etapa: null, faltas: [], avisos: [], no_publicador: true, categoria_travada: true, mapeado_em: null },
    edicao: { livre: false, motivo: MOTIVO_DA_TRAVA, onda_comecou: null },
};

/** O criado-mudo com a Simples numa onda que já começou (o robô ainda não mexeu) e já no Publicador. */
export const CADASTRO_ONDA_COMECOU = {
    ...SALVO_INCOMPLETO,
    situacao: { ...SALVO_INCOMPLETO.situacao, no_publicador: true },
    edicao: { livre: true, motivo: null, onda_comecou: { id: 7, nome: 'Onda 1 · simples', data: '2026-11-03' } },
};

/** `POST {escrita.produtos}` (201): o produto nasceu com o nome e o SKU. */
export const CRIADO = {
    ...CADASTRO,
    absorvidos_da_espera: 0,
    avisos: [],
    mensagem: 'Produto criado.',
};

// ─── 3ª entrega, F2: a categoria e a ficha técnica ──────────────────────────

/**
 * `GET {escrita.categorias}?q=Criado-mudo 2 gavetas` — as candidatas do preditor, com `folha` e `anunciavel`. A 1ª
 * não é a última do caminho; a 2ª é a SUGERIDA (última e aceita produto novo); a 3ª é a última, mas fechada para
 * produto novo.
 */
export const CANDIDATAS = {
    categorias: [
        { id: 'MLB1901', nome: 'Quarto', caminho: [{ id: 'MLB1574', nome: 'Casa, Móveis e Decoração' }, { id: 'MLB1901', nome: 'Quarto' }], caminho_texto: 'Casa, Móveis e Decoração > Quarto', folha: false, anunciavel: false },
        { id: 'MLB1902', nome: 'Criados-mudos', caminho: [{ id: 'MLB1574', nome: 'Casa, Móveis e Decoração' }, { id: 'MLB1901', nome: 'Quarto' }, { id: 'MLB1902', nome: 'Criados-mudos' }], caminho_texto: 'Casa, Móveis e Decoração > Quarto > Criados-mudos', folha: true, anunciavel: true },
        { id: 'MLB1903', nome: 'Cabeceiras antigas', caminho: [{ id: 'MLB1574', nome: 'Casa, Móveis e Decoração' }, { id: 'MLB1903', nome: 'Cabeceiras antigas' }], caminho_texto: 'Casa, Móveis e Decoração > Cabeceiras antigas', folha: true, anunciavel: false },
    ],
    indisponivel: false,
};

/**
 * `GET {escrita.categorias}/MLB1902/campos` — a definição da ficha (a `definicao()` do servidor, sem explicações):
 * os obrigatórios, a Marca com sugestões, uma lista que aceita valor próprio, o campo que é eixo (Cor), Sim/Não,
 * número com várias unidades, lista de várias opções, um "não se aplica" e o grupo das medidas do produto montado.
 */
export const CAMPOS_DA_CATEGORIA = {
    grupos: [
        { grupo: 'Principais', campos: [
            { id: 'BRAND', nome: 'Marca', obrigatorio: true, tipo: 'texto', valores: [], unidades: [], unidade_padrao: null, max: 60, sugestoes: ['Genérica', 'ECF Móveis'] },
            { id: 'MAIN_MATERIAL', nome: 'Material principal', obrigatorio: true, tipo: 'lista', valores: [{ id: '1', nome: 'Madeira' }, { id: '2', nome: 'MDF' }], unidades: [], unidade_padrao: null, max: 60, aceita_outro: true },
            { id: 'COLOR', nome: 'Cor', obrigatorio: false, tipo: 'lista', valores: [{ id: '52049', nome: 'Preto' }, { id: '51994', nome: 'Branco' }], unidades: [], unidade_padrao: null, max: null, eixo_do_portal: 'cor' },
        ] },
        { grupo: 'Mais detalhes', campos: [
            { id: 'WITH_DRAWERS', nome: 'Com gavetas', obrigatorio: false, tipo: 'sim_nao', valores: [], unidades: [], unidade_padrao: null, max: null },
            { id: 'MAX_WEIGHT', nome: 'Peso máximo suportado', obrigatorio: false, tipo: 'numero_unidade', valores: [], unidades: [{ id: 'kg', nome: 'kg' }, { id: 'g', nome: 'g' }], unidade_padrao: 'kg', max: null },
            { id: 'STYLES', nome: 'Estilos', obrigatorio: false, tipo: 'lista', multivalor: true, valores: [{ id: '10', nome: 'Moderno' }, { id: '11', nome: 'Rústico' }], unidades: [], unidade_padrao: null, max: null },
            { id: 'FINISH', nome: 'Acabamento', obrigatorio: false, tipo: 'texto', valores: [], unidades: [], unidade_padrao: null, max: 60, nao_se_aplica: true },
        ] },
        { grupo: 'Medidas do produto', medidas_do_produto: true, campos: [
            { id: 'WIDTH', nome: 'Largura', obrigatorio: false, tipo: 'numero_unidade', valores: [], unidades: [{ id: 'cm', nome: 'cm' }], unidade_padrao: 'cm', max: null },
            { id: 'HEIGHT', nome: 'Altura', obrigatorio: false, tipo: 'numero_unidade', valores: [], unidades: [{ id: 'cm', nome: 'cm' }], unidade_padrao: 'cm', max: null },
        ] },
    ],
    indisponivel: false,
};

/** `GET {escrita.categorias}/MLB1902/titulo?nome=…` — os termos e o limite da categoria. */
export const TERMOS_DA_CATEGORIA = {
    limite: 70,
    indisponivel: false,
    total: 3,
    categoria: { caminho: ['Casa, Móveis e Decoração', 'Quarto', 'Criados-mudos'] },
    termos: [
        { posicao: 1, termo: 'criado mudo', grupo: 'popular', relacionado: true, origem: 'categoria' },
        { posicao: 2, termo: 'mesa de cabeceira', grupo: 'desejado', relacionado: false, origem: 'categoria' },
        { posicao: 3, termo: 'jatobá', grupo: null, relacionado: true, origem: 'categoria' },
    ],
    acima: null,
};

/** O criado-mudo com a categoria GRAVADA e a ficha parcial (só a Marca): falta o Material, obrigatório. */
export const CADASTRO_COM_CATEGORIA = {
    ...SALVO_INCOMPLETO,
    produto: {
        ...SALVO_INCOMPLETO.produto,
        categoria: { id: 'MLB1902', nome: 'Criados-mudos', caminho_texto: 'Casa, Móveis e Decoração > Quarto > Criados-mudos', estado: 'confirmada' },
    },
    ficha: { salvos: [{ id: 'BRAND', nome: 'Marca', valor: 'ECF Móveis', valor_id: null, unidade: null }] },
    situacao: { ...SALVO_INCOMPLETO.situacao, faltas: ['ficha: Material principal', 'fotos (0 de 3)'] },
};

/** O mesmo produto já no Publicador (a onda não começou, o robô não mexeu): editável, com a categoria TRAVADA. */
export const CADASTRO_CATEGORIA_TRAVADA = {
    ...CADASTRO_COM_CATEGORIA,
    situacao: { ...CADASTRO_COM_CATEGORIA.situacao, no_publicador: true, categoria_travada: true },
};

// ─── 3ª entrega, F3: as fotos e o frete ─────────────────────────────────────

/** Uma foto como o retrato manda: a miniatura e a original pela rota da equipe (`trilha.fotos`), nunca `/portal/`. */
const foto = (variacao, id, nome) => ({
    id, url: `/trilha/459/fotos/${variacao}/${id}`, url_original: `/trilha/459/fotos/${variacao}/${id}?original=1`, nome_original: nome,
});

/** A resposta do envio de 2 fotos ao criado-mudo (rota 10): o retrato novo — ainda falta 1 foto (e a categoria). */
export const CADASTRO_COM_FOTOS = {
    ...SALVO_INCOMPLETO,
    variacoes: [{ ...SALVO_INCOMPLETO.variacoes[0], fotos: [foto(91, 501, 'frente.jpg'), foto(91, 502, 'lado.jpg')] }],
    situacao: { ...SALVO_INCOMPLETO.situacao, faltas: ['categoria', 'fotos (2 de 3)'] },
    mensagem: '2 fotos enviadas.',
};

/** O mesmo, com a 3ª foto: as fotos completas. */
export const CADASTRO_FOTOS_COMPLETAS = {
    ...CADASTRO_COM_FOTOS,
    variacoes: [{ ...CADASTRO_COM_FOTOS.variacoes[0], fotos: [...CADASTRO_COM_FOTOS.variacoes[0].fotos, foto(91, 503, 'detalhe.jpg')] }],
    situacao: { ...CADASTRO_COM_FOTOS.situacao, faltas: ['categoria'] },
};

/**
 * O criado-mudo numa caixa que passa do tamanho do Mercado Envios (só vai por transportadora): com a trilha de
 * trabalho o ME1 é AVISO (pergunta 3) — o produto está completo e saiu da Lista.
 */
export const CADASTRO_ME1 = {
    ...SALVO_COMPLETO,
    variacoes: [{
        ...SALVO_COMPLETO.variacoes[0], volumes: [{ c: 216, l: 98, a: 60, kg: 62 }], logistica: 'me1',
        fotos: CADASTRO_FOTOS_COMPLETAS.variacoes[0].fotos,
    }],
    situacao: { ...SALVO_COMPLETO.situacao, etapa: 'mapa', faltas: [], avisos: ['vai por ME1 (transportadora)'] },
};

/** Uma variação da camiseta (a forma do retrato). */
const daCamiseta = (id, codigo, valor, valor2, { dona = false, logistica = 'me2', fotos = [] } = {}) => ({
    id, codigo, valor, valor_2: valor2, ordem: id - 120, custo: 25, estoque: null, volumes: [{ c: 30, l: 25, a: 3, kg: 0.3 }],
    bling: false, estoque_do_bling: false, dona_da_cor: dona, logistica, fotos,
});

/**
 * Uma camiseta de 2 cores × 2 tamanhos (a moda). As fotos são da COR: a Branca as tem no tamanho M (a 1ª variação
 * dela COM foto é a dona); a Preta, sem foto, guarda na P (a 1ª dela). A Preta M passa do Mercado Envios (aviso).
 */
export const CADASTRO_CORES_E_TAMANHOS = {
    ...CADASTRO,
    produto: { ...CADASTRO.produto, id: 12, nome: 'Camiseta básica', eixo: 'cor', eixo_2: 'tamanho' },
    variacoes: [
        daCamiseta(121, 'CAM-BR-P', 'Branca', 'P'),
        daCamiseta(122, 'CAM-BR-M', 'Branca', 'M', { dona: true, fotos: [foto(122, 601, 'branca-1.jpg'), foto(122, 602, 'branca-2.jpg'), foto(122, 603, 'branca-3.jpg')] }),
        daCamiseta(123, 'CAM-PR-P', 'Preta', 'P', { dona: true }),
        daCamiseta(124, 'CAM-PR-M', 'Preta', 'M', { logistica: 'me1' }),
    ],
    situacao: { ...CADASTRO.situacao, faltas: ['fotos (0 de 3)'], avisos: ['vai por ME1 (transportadora)'] },
};

// ─── 3ª entrega, F7: a planilha por categoria (§17.4 do desenho) ────────────

/**
 * `POST {escrita.planilha.previa}` — a prévia de uma planilha com DUAS abas (Camisetas e Criados-mudos). A forma é a
 * do §17.4, igual no gravar: a camiseta nova (2 cores × 3 tamanhos, 6 linhas) e a regata recusada (variação sem
 * valor); o criado-mudo 9 atualizado com um aviso de campo da ficha (o campo inválido sai sozinho), o 12 sem
 * mudança e a cadeira (2) recusada por inteiro (andou: o erro do produto inteiro vem com a 1ª linha e `coluna` nula). Os
 * `totais` do servidor vêm de propósito DIFERENTES da lista (a tela conta pela lista).
 */
export const PREVIA_DA_PLANILHA = {
    gravado: false,
    abas: [
        { categoria_id: 'MLB31447', categoria_nome: 'Camisetas', linhas: 8 },
        { categoria_id: 'MLB1902', categoria_nome: 'Criados-mudos', linhas: 3 },
    ],
    produtos: [
        {
            chave: 'CAM-01', aba: 'MLB31447', linhas: [4, 5, 6, 7, 8, 9], produto_id: null, nome: 'Camiseta básica', codigo: 'CAM-01',
            situacao: 'novo', variacoes: { novas: 6, atualizadas: 0 }, erros: [], avisos: [], faltas: ['fotos (0 de 3)', 'descrição'], versao: null,
        },
        {
            chave: 'REG-01', aba: 'MLB31447', linhas: [10, 11], produto_id: null, nome: 'Regata lisa', codigo: 'REG-01',
            situacao: 'recusado', variacoes: { novas: 0, atualizadas: 0 },
            erros: [{ linha: 11, coluna: 'Variação', mensagem: 'Informe a variação: com duas ou mais linhas, cada uma precisa da sua.' }],
            avisos: [], faltas: [], versao: null,
        },
        {
            chave: 'CRIADO-01', aba: 'MLB1902', linhas: [4], produto_id: 9, nome: 'Criado-mudo 2 gavetas', codigo: 'CRIADO-01',
            situacao: 'atualizado', variacoes: { novas: 0, atualizadas: 1 }, erros: [],
            avisos: [{ linha: 4, coluna: 'Material', mensagem: '“Madeira maciça de jatobá” não é uma opção deste campo: ele ficou de fora.' }],
            faltas: ['fotos (0 de 3)'], versao: 'a'.repeat(40),
        },
        {
            chave: 'CRIADO-02', aba: 'MLB1902', linhas: [5], produto_id: 12, nome: 'Criado-mudo 1 gaveta', codigo: 'CRIADO-02',
            situacao: 'sem_mudanca', variacoes: { novas: 0, atualizadas: 0 }, erros: [], avisos: [], faltas: [], versao: 'b'.repeat(40),
        },
        {
            chave: 'CJ-211', aba: 'MLB1902', linhas: [6], produto_id: 2, nome: 'Cadeira jatobá assento palhinha', codigo: 'CJ-211',
            situacao: 'recusado', variacoes: { novas: 0, atualizadas: 0 },
            erros: [{ linha: 6, coluna: null, mensagem: 'Este produto já andou na trilha: o cadastro dele é só leitura.' }],
            avisos: [], faltas: [], versao: 'c'.repeat(40),
        },
    ],
    totais: { novos: 9, atualizados: 9, sem_mudanca: 9, recusados: 9 },
    mensagem: '1 produto novo, 1 atualizado, 1 sem mudança e 2 ficam de fora.',
};

/** `POST {escrita.planilha.gravar}` — o resultado da mesma planilha: a camiseta nasceu (produto 130); os recusados, de fora. */
export const RESULTADO_DA_PLANILHA = {
    ...PREVIA_DA_PLANILHA,
    gravado: true,
    produtos: PREVIA_DA_PLANILHA.produtos.map((p) => (p.chave === 'CAM-01' ? { ...p, produto_id: 130 } : p)),
    totais: { novos: 1, atualizados: 1, sem_mudanca: 1, recusados: 2 },
    mensagem: '1 produto criado, 1 atualizado, 1 sem mudança e 2 ficaram de fora.',
};

// ─── 3ª entrega, F8: importar os anúncios da loja (§18.4 do desenho) ────────

/** Um anúncio da lista, na forma do §18.4 (o que o teste não muda vem com o valor do exemplo do desenho). */
const anuncio = (mlb, extra = {}) => ({
    mlb, titulo: `Anúncio ${mlb}`, thumbnail: `http://http2.mlstatic.com/D_${mlb}-I.jpg`, permalink: `https://produto.mercadolivre.com.br/${mlb.replace('MLB', 'MLB-')}`,
    preco: 199.9, tipo: 'Clássico', ativo: true, status: 'Ativo', catalogo: false, estoque: 12, vendas_total: 30, visitas_30d: 410,
    fotos: 8, saude_nivel: 'Média', feito_pela_ecf: false, na_lista: null, importacao: null, ...extra,
});

/**
 * `GET {escrita.mercado_livre.anuncios}` — a 1ª página (de 2) dos anúncios da loja: um comum e um pausado (marcáveis),
 * um importado antes (o produto 9 está na Lista: "Abrir"), um com o SKU de um produto que a trilha não tem nos dados
 * (sem "Abrir"), um sendo importado por outra aba e um de catálogo feito pela ECF.
 */
export const LISTA_DO_ML = {
    conectado: true,
    atualizado_em: '2026-11-03T11:35:00-03:00',
    total: 41,
    pagina: 1,
    paginas: 2,
    limite: 20,
    itens: [
        anuncio('MLB4001', { titulo: 'Poltrona Decorativa Linho Pé Palito Sala Quarto' }),
        anuncio('MLB4002', { titulo: 'Mesa Lateral Jatobá Redonda', ativo: false, status: 'Pausado', estoque: 0, vendas_total: 3, visitas_30d: 12 }),
        anuncio('MLB4003', { titulo: 'Criado Mudo Jatobá 2 Gavetas', na_lista: { produto_id: 9, nome: 'Criado-mudo 2 gavetas', como: 'importado' } }),
        anuncio('MLB4004', { titulo: 'Banqueta Alta Jatobá', na_lista: { produto_id: 999, nome: 'Banqueta alta', como: 'sku' } }),
        anuncio('MLB4005', { titulo: 'Aparador Buffet 4 Portas', importacao: { situacao: 'importando' } }),
        anuncio('MLB4006', { titulo: 'Cadeira Jatobá Palhinha', catalogo: true, feito_pela_ecf: true, saude_nivel: null }),
    ],
};

/**
 * `POST {escrita.mercado_livre.importar}` (e o `GET …/estado` logo depois) — uma importação ANDANDO, com as seis
 * situações do §18.4. O `titulo` aparece duas vezes no item do desenho (o do anúncio e o de venda): no JSON vale o
 * último — aqui, no "novo", é o que o robô escreveu; nos que andam, o do anúncio. Os `totais` vêm de propósito
 * DIFERENTES da lista (a tela conta pela lista, como na planilha).
 */
export const ESTADO_DO_ML = {
    em_andamento: true,
    totais: { novos: 9, na_lista: 9, recusados: 9, erros: 9 },
    itens: [
        {
            mlb: 'MLB4001', situacao: 'novo', passo: null, produto_id: 140, nome: 'Poltrona Decorativa Linho Pé Palito', codigo: 'POLT-01',
            variacoes: 6, fotos: { trazidas: 9, falharam: 1 }, ficha: { gravados: 14, recusados: 2, pela_ia: 5 },
            titulo: 'Poltrona Decorativa Estofada Linho Pés Palito', avisos: ['1 foto não veio do anúncio: envie pela gaveta do produto.'],
            faltas: ['custo', 'família', 'ambiente'], mensagem: null,
        },
        {
            mlb: 'MLB4002', titulo: 'Mesa Lateral Jatobá Redonda', situacao: 'importando', passo: 'Trazendo as fotos', produto_id: 141, nome: 'Mesa lateral jatobá redonda',
            codigo: 'MESA-LAT-01', variacoes: 1, fotos: null, ficha: null, avisos: [], faltas: [], mensagem: null,
        },
        {
            mlb: 'MLB4007', titulo: 'Estante Jatobá 5 Prateleiras', situacao: 'fila', passo: null, produto_id: null, nome: null, codigo: null,
            variacoes: null, fotos: null, ficha: null, avisos: [], faltas: [], mensagem: null,
        },
        {
            mlb: 'MLB4008', titulo: 'Mesa de Jantar Jatobá 6 Lugares', situacao: 'recusado', passo: null, produto_id: null, nome: null, codigo: null,
            variacoes: null, fotos: null, ficha: null, avisos: [], faltas: [], mensagem: 'O SKU CJ-210 já é do produto “Mesa de jantar jatobá 6 lugares”: nada foi gravado.',
        },
        {
            mlb: 'MLB4009', titulo: 'Rack Jatobá 1,80 m', situacao: 'erro', passo: null, produto_id: null, nome: null, codigo: null,
            variacoes: null, fotos: null, ficha: null, avisos: [], faltas: [], mensagem: 'A categoria do anúncio não respondeu agora. Tente de novo.',
        },
        {
            mlb: 'MLB4010', titulo: 'Criado Mudo Jatobá 2 Gavetas', situacao: 'na_lista', passo: null, produto_id: 9, nome: 'Criado-mudo 2 gavetas', codigo: 'CRIADO-01',
            variacoes: 1, fotos: null, ficha: null, avisos: [], faltas: [], mensagem: 'Já estava na Lista (importado antes): nada mudou.',
        },
    ],
};

/** A mesma importação TERMINADA: a mesa e a estante viraram produto (a estante, sem título — a IA ficou fora). */
export const ESTADO_DO_ML_FIM = {
    ...ESTADO_DO_ML,
    em_andamento: false,
    totais: { novos: 3, na_lista: 1, recusados: 1, erros: 1 },
    itens: ESTADO_DO_ML.itens.map((i) => {
        if (i.mlb === 'MLB4002') {
            return {
                ...i, situacao: 'novo', passo: null, titulo: 'Mesa Lateral Redonda Jatobá Maciço Sala', fotos: { trazidas: 4, falharam: 0 },
                ficha: { gravados: 11, recusados: 0, pela_ia: 3 }, faltas: ['custo', 'família', 'ambiente'],
            };
        }
        if (i.mlb === 'MLB4007') {
            return {
                ...i, situacao: 'novo', produto_id: 142, nome: 'Estante jatobá 5 prateleiras', codigo: 'EST-05', variacoes: 1, titulo: null,
                fotos: { trazidas: 0, falharam: 0 }, ficha: { gravados: 0, recusados: 0, pela_ia: 0 },
                avisos: ['Escreva o título na gaveta.'], faltas: ['custo', 'família', 'ambiente', 'título', 'fotos (0 de 3)'],
            };
        }

        return i;
    }),
};

/** `GET …/9/cadastro` do produto que veio de um anúncio (D41): o título de venda do robô e o do anúncio original. */
export const CADASTRO_DO_ML = {
    ...SALVO_INCOMPLETO,
    produto: {
        ...SALVO_INCOMPLETO.produto, origem_ml: true,
        titulo: 'Criado-Mudo 2 Gavetas Jatobá Maciço', titulo_do_anuncio: 'Criado Mudo Jatoba Macico 2 Gavetas Promoção Frete Grátis',
    },
};

// ═══ 4ª entrega, fatia 4a: a prévia do anúncio (só leitura, porta da equipe) ═══

/** A foto da prévia como o servidor manda (§19.5): a URL da rota da trilha e `gerada` (o robô fez). */
const fotoDaPrevia = (item, id, gerada = false) => ({ id: String(id), url: `/trilha/459/ofertas/${item}/fotos/${id}`, gerada });

/** A ficha agrupada de cada cor (o anúncio da cor leva o valor dela). */
const fichaDaCadeira = (cor) => [
    {
        grupo: 'Principais',
        itens: [
            { nome: 'Marca', valor: 'Casa Jatobá' },
            { nome: 'Material da estrutura', valor: 'Jatobá maciço' },
            { nome: 'Cor', valor: cor },
        ],
    },
    {
        grupo: 'Dimensões',
        itens: [
            { nome: 'Altura', valor: '92 cm' },
            { nome: 'Largura', valor: '45 cm' },
            { nome: 'Profundidade', valor: '52 cm' },
        ],
    },
];

/**
 * `GET {escrita.revisao.previa}` com `s-2` no lugar do marcador — a prévia PRONTA da cadeira (§19.5 do desenho): dois
 * tipos × duas cores. No Premium a capa é outra (a ambientação que o robô fez vem primeiro, D6 do Publicador), as
 * parcelas vêm na forma do retrato de origem (`sem_promocao`/`com_promocao`: o §19.5 só mostra o `null` do Clássico) e
 * a cor Mel tem uma promoção prevista. O Clássico foi ajustado (899,90 × 879,90 da Precificação); o Premium, não. O
 * `envio` leva também `frete_gratis` e `retirada` (o servidor repassa os do retrato; o §19.5 só mostra `modo` e `aviso`).
 */
export const PREVIA_DO_ANUNCIO = {
    item: { id: 's-2', nome: 'Cadeira jatobá assento palhinha', tipo: 'Simples', etapa: 'revisao', status: 'Pré-pronto · esperando a revisão' },
    pronta: true,
    motivo: null,
    revisao: 37,
    categoria: { caminho: ['Casa, Móveis e Decoração', 'Sala de Jantar', 'Cadeiras'] },
    tipos: [{ chave: 'classico', nome: 'Clássico' }, { chave: 'premium', nome: 'Premium' }],
    variantes: [{ chave: 'v1', rotulo: 'Natural' }, { chave: 'v2', rotulo: 'Mel' }],
    anuncios: [
        {
            tipo: 'classico', variante: 'v1', titulo: 'Cadeira Jatobá Assento Palhinha Madeira Maciça Sala De Jantar', preco: 899.9, promocao: null, parcelas: null,
            fotos: [fotoDaPrevia('s-2', 881), fotoDaPrevia('s-2', 882), fotoDaPrevia('s-2', 883), fotoDaPrevia('s-2', 890, true), fotoDaPrevia('s-2', 891, true)],
            caracteristicas: fichaDaCadeira('Natural'),
            envio: { modo: 'me2', frete_gratis: true, retirada: false, aviso: null },
        },
        {
            tipo: 'classico', variante: 'v2', titulo: 'Cadeira Jatobá Assento Palhinha Madeira Maciça Sala De Jantar', preco: 899.9,
            promocao: { preco: 809.91, percentual: 10, ajustada_ao_minimo: false }, parcelas: null,
            fotos: [fotoDaPrevia('s-2', 884), fotoDaPrevia('s-2', 885), fotoDaPrevia('s-2', 886), fotoDaPrevia('s-2', 892, true)],
            caracteristicas: fichaDaCadeira('Mel'),
            envio: { modo: 'me2', frete_gratis: true, retirada: false, aviso: null },
        },
        {
            tipo: 'premium', variante: 'v1', titulo: 'Cadeira Jatobá Palhinha Madeira Maciça Para Sala De Jantar', preco: 989.9, promocao: null,
            parcelas: { sem_promocao: { vezes: 10, valor: 98.99 }, com_promocao: null },
            fotos: [fotoDaPrevia('s-2', 890, true), fotoDaPrevia('s-2', 881), fotoDaPrevia('s-2', 882), fotoDaPrevia('s-2', 883), fotoDaPrevia('s-2', 891, true)],
            caracteristicas: fichaDaCadeira('Natural'),
            envio: { modo: 'me2', frete_gratis: true, retirada: false, aviso: null },
        },
        {
            tipo: 'premium', variante: 'v2', titulo: 'Cadeira Jatobá Palhinha Madeira Maciça Para Sala De Jantar', preco: 989.9, promocao: null,
            parcelas: { sem_promocao: { vezes: 10, valor: 98.99 }, com_promocao: null },
            fotos: [fotoDaPrevia('s-2', 892, true), fotoDaPrevia('s-2', 884), fotoDaPrevia('s-2', 885), fotoDaPrevia('s-2', 886)],
            caracteristicas: fichaDaCadeira('Mel'),
            envio: { modo: 'me2', frete_gratis: true, retirada: false, aviso: null },
        },
    ],
    descricao: 'Olá! Que bom ter você na Casa Jatobá.\nCadeira jatobá com assento de palhinha natural.\n\nESPECIFICAÇÕES TÉCNICAS\n• Medidas: 45 × 52 × 92 cm\n• Material: jatobá maciço\n\nCUIDADOS E RECOMENDAÇÕES\n• Limpe com pano seco.',
    preco: {
        classico: { valor: 899.9, da_precificacao: 879.9, ajustado: true },
        premium: { valor: 989.9, da_precificacao: 989.9, ajustado: false },
    },
    preparo: { titulo: 'ok', descricao: 'ok', imagens: 'ok' },
    publicacao: null,
    aprovacao: null,
    bloqueios: [],
};

/**
 * A mesma rota para um item sem prévia ainda (§19.5: `pronta: false`, `motivo` neutro, `anuncios: []`). O `preco` vem
 * `null` (como o servidor manda fora da prévia pronta); na pronta, o tipo desligado também vem `null`.
 */
export const PREVIA_NAO_PRONTA = {
    item: { id: 's-4', nome: 'Rack para TV 1,80 m madeira maciça', tipo: 'Simples', etapa: 'revisao', status: 'Faltam 2 itens para publicar' },
    pronta: false,
    motivo: 'O robô ainda está preparando este anúncio.',
    revisao: 12,
    categoria: null,
    tipos: [],
    variantes: [],
    anuncios: [],
    descricao: null,
    preco: null,
    preparo: { titulo: 'ok', descricao: 'ok', imagens: null },
    publicacao: null,
    aprovacao: null,
    bloqueios: [],
};

/** O 409 do item que saiu da revisão depois que a trilha carregou (§19.4: a guarda 409 vem antes do 422). */
export const CONFLITO_DA_PREVIA = { message: 'Este anúncio não está mais na revisão.' };

// ═══ 4ª entrega, 4c: o preço ajustável (§19.11 do desenho) ═══════════════════

/** As chaves CANÔNICAS das cores no rascunho (a `cor` do pedido vai como vem, sem codificar). */
const COR_NATURAL = 'COLOR=id:52049';
const COR_MEL = 'COLOR=id:52055';
const CHAVE_CANONICA = { v1: COR_NATURAL, v2: COR_MEL };

/** A prévia com a cor na chave canônica e o preço de cada anúncio trocado por `precoDe(tipo, cor)`. */
function comPrecos(previa, precoDe) {
    return {
        ...previa,
        variantes: previa.variantes.map((v) => ({ ...v, chave: CHAVE_CANONICA[v.chave] ?? v.chave })),
        anuncios: previa.anuncios.map((a) => {
            const variante = CHAVE_CANONICA[a.variante] ?? a.variante;

            return { ...a, variante, preco: precoDe(a.tipo, variante) ?? a.preco };
        }),
    };
}

/**
 * `GET {escrita.revisao.previa}` depois da 4c: o `preco` ampliado — `minimo` por tipo (da 1ª cor ativa), `por_cor` e
 * `premium_igual` — e `avisos` vazio. O Clássico foi ajustado (899,90 × 879,90 da Precificação, mínimo 820,00); o
 * Premium segue a Precificação (989,90, mínimo 900,00). As duas cores com o mesmo preço.
 */
export const PREVIA_COM_PRECO = {
    ...comPrecos(PREVIA_DO_ANUNCIO, () => null),
    preco: {
        classico: { valor: 899.9, da_precificacao: 879.9, minimo: 820, ajustado: true },
        premium: { valor: 989.9, da_precificacao: 989.9, minimo: 900, ajustado: false },
        por_cor: false,
        premium_igual: false,
    },
    avisos: [],
};

/** A mesma, com o Clássico da cor Mel a 949,90 (uma cor com preço próprio: `por_cor`). */
export const PREVIA_POR_COR = {
    ...comPrecos(PREVIA_DO_ANUNCIO, (tipo, cor) => (tipo === 'classico' && cor === COR_MEL ? 949.9 : null)),
    preco: { ...PREVIA_COM_PRECO.preco, por_cor: true },
    avisos: [],
};

/**
 * `PUT {escrita.revisao.preco}` 200 — `{revisao: 37, precos: {classico: 949.9}, cor: null}`: a prévia NOVA (a revisão
 * subiu, o Clássico de todas as cores a 949,90, a linha agora "a conferir").
 */
export const PRECO_SALVO = {
    ...comPrecos(PREVIA_DO_ANUNCIO, (tipo) => (tipo === 'classico' ? 949.9 : null)),
    item: { ...PREVIA_DO_ANUNCIO.item, status: 'Pré-pronto · a conferir' },
    revisao: 38,
    preco: {
        classico: { valor: 949.9, da_precificacao: 879.9, minimo: 820, ajustado: true },
        premium: { valor: 989.9, da_precificacao: 989.9, minimo: 900, ajustado: false },
        por_cor: false,
        premium_igual: false,
    },
    avisos: [],
};

/** 200 com o Clássico abaixo do mínimo (800,00 < 820,00): grava, e o aviso vem em `avisos` — não trava. */
export const PRECO_ABAIXO_DO_MINIMO = {
    ...comPrecos(PREVIA_DO_ANUNCIO, (tipo) => (tipo === 'classico' ? 800 : null)),
    revisao: 38,
    preco: {
        classico: { valor: 800, da_precificacao: 879.9, minimo: 820, ajustado: true },
        premium: { valor: 989.9, da_precificacao: 989.9, minimo: 900, ajustado: false },
        por_cor: false,
        premium_igual: false,
    },
    avisos: ['Abaixo do mínimo da Precificação no Clássico (R$ 820,00).'],
};

/** 409 da revisão (outra aba, ou o robô, mexeu): `{revisao_atual, message}` SEM `travado` (padrão F6/D47). */
export const CONFLITO_DO_PRECO = { revisao_atual: 39, message: 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.' };

/** 409 do item na fila de publicação (ou publicando, publicado, fora da revisão): só `{message}`, neutro. */
export const PRECO_NA_FILA = { message: 'Este anúncio já está na fila de publicação: o preço não muda mais por aqui.' };

/** 422 do Premium igual ao Clássico (D46), no campo do Premium. */
export const ERROS_DO_PRECO = {
    message: 'O Premium não pode ter o mesmo preço do Clássico.',
    errors: { 'precos.premium': ['O Premium não pode ter o mesmo preço do Clássico.'] },
};

// ═══ 4ª entrega, 4d: aprovar e publicar (§19.13 do desenho) ═══════════════════

/**
 * `POST {escrita.revisao.publicar}` 202 — `{revisao: 37, ciente: false}`: o pedido entrou e a conferência começou. As
 * mensagens são as do servidor (`PublicacaoNaTrilha::MSG_*`, 04/11): nas situações que andam, elas repetem o título
 * da tela e não viram detalhe.
 */
export const PUBLICAR_ACEITO = { publicacao: { situacao: 'conferindo', mensagem: 'Conferindo o anúncio antes de publicar…', avisos: [], mlbs: [] } };

/** `GET {escrita.revisao.publicacao}` — a conferência deixou avisos: a tela lista e pede [Publicar assim mesmo] (`ciente`). */
export const PUBLICACAO_AVISOS = {
    publicacao: {
        situacao: 'avisos',
        mensagem: 'A conferência deu avisos. Se estiver de acordo, publique assim mesmo.',
        avisos: ['A foto 3 da cor Mel tem menos de 1.200 px: pode perder o zoom.', { mensagem: 'O título do Premium usa só 3 dos termos mais buscados.' }],
        mlbs: [],
    },
};

/** Na fila de publicação (a situação sai da fila de verdade depois do `na_fila`). */
export const PUBLICACAO_NA_FILA = { publicacao: { situacao: 'na_fila', mensagem: 'Na fila de publicação.', avisos: [], mlbs: [] } };

/** Publicando agora. */
export const PUBLICACAO_PUBLICANDO = { publicacao: { situacao: 'publicando', mensagem: 'Publicando agora…', avisos: [], mlbs: [] } };

/** Publicado (um anúncio por tipo × cor): "Publicado · em análise" (D59). */
export const PUBLICACAO_PUBLICADA = {
    publicacao: {
        situacao: 'publicado',
        mensagem: 'Publicado. Nas primeiras horas o anúncio fica em análise antes de ir à venda.',
        avisos: [],
        mlbs: ['MLB5123456781', 'MLB5123456782', 'MLB5123456783', 'MLB5123456784'],
    },
};

/** A conferência achou erro (sempre bloqueia): a mensagem e os erros em texto neutro em `avisos`; nada na fila. */
export const PUBLICACAO_RECUSADA = {
    publicacao: {
        situacao: 'recusado',
        mensagem: 'A conferência encontrou problemas que impedem publicar. Corrija e tente de novo.',
        avisos: ['A categoria pede o campo “Material do assento”.'],
        mlbs: [],
    },
};

/** A publicação falhou depois da fila. */
export const PUBLICACAO_COM_ERRO = {
    publicacao: { situacao: 'erro', mensagem: 'A publicação não deu certo. Confira o anúncio e tente de novo.', avisos: [], mlbs: [] },
};

/** `GET` de um item que nunca pediu (ou o pedido venceu no cache). */
export const SEM_PUBLICACAO = { publicacao: null };

/** A prévia (GET) com a publicação na fila — `publicacao` pelo mesmo cálculo: a gaveta retoma o acompanhamento. */
export const PREVIA_NA_FILA = {
    ...PREVIA_COM_PRECO,
    item: { ...PREVIA_COM_PRECO.item, status: 'Na fila de publicação' },
    publicacao: PUBLICACAO_NA_FILA.publicacao,
};

/** A prévia com os avisos da última conferência (o preço continua editável; o primário vira [Publicar assim mesmo]). */
export const PREVIA_COM_AVISOS = { ...PREVIA_COM_PRECO, publicacao: PUBLICACAO_AVISOS.publicacao };

/** A prévia de quem já foi publicado pela trilha: no ar (conferência), "Publicado · em análise". */
export const PREVIA_PUBLICADA = {
    ...PREVIA_COM_PRECO,
    item: { ...PREVIA_COM_PRECO.item, etapa: 'lanc', status: 'Em análise · Clássico e Premium' },
    publicacao: PUBLICACAO_PUBLICADA.publicacao,
};

/** A prévia pronta com um bloqueio (o que falta para publicar): sem [Aprovar e publicar]. */
export const PREVIA_COM_BLOQUEIO = { ...PREVIA_COM_PRECO, bloqueios: ['Falta a garantia do anúncio.'] };

/** 409 da revisão (o preço mudou noutra aba depois que a gaveta abriu): `{revisao_atual, message}` SEM `travado`. */
export const CONFLITO_DO_PUBLICAR = { revisao_atual: 39, message: 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.' };

/** 409 do item que já está na fila (outra aba pediu): só `{message}`, neutro. */
export const PUBLICAR_JA_NA_FILA = { message: 'Este anúncio já está na fila de publicação.' };

/** 403 fora da loja liberada (D48): a mensagem neutra do §19.13. */
export const PUBLICAR_FORA_DA_LOJA = { message: 'Publicar pela trilha ainda não está liberado para esta loja.' };

/** 422 do Premium igual ao Clássico (D46: no publicar, TODAS as cores ativas). */
export const PUBLICAR_PREMIUM_IGUAL = {
    message: 'O Premium não pode ter o mesmo preço do Clássico.',
    errors: { 'precos.premium': ['O Premium não pode ter o mesmo preço do Clássico na cor Mel.'] },
};

// ═══ 4ª entrega, 4b: pedir ajuste ao robô (§19.14 do desenho) ═══════════════

/**
 * A prévia pronta (a da 4c) com o que a 4b acrescenta: `ajuste` (nenhum pedido ainda) e `ajustes_restantes_hoje` (o teto
 * é 3 por anúncio por dia, §19.12).
 */
export const PREVIA_PARA_AJUSTE = { ...PREVIA_COM_PRECO, ajuste: null, ajustes_restantes_hoje: 3 };

/**
 * `POST {escrita.revisao.ajuste}` 202 — `{revisao: 37, refazer: ['titulo', 'descricao']}`: o pedido entrou na fila da IA.
 * As mensagens desta seção são as do servidor (`AjusteDoRoboNaTrilha::MSG_*`, 04/11).
 */
export const AJUSTE_ACEITO = {
    ajuste: {
        situacao: 'fila',
        partes: { titulo: { situacao: 'fila', mensagem: 'Na fila do robô.' }, descricao: { situacao: 'fila', mensagem: 'Na fila do robô.' } },
        restantes_hoje: 2,
        mensagem: 'Pedido recebido: o robô começa em instantes.',
    },
};

/** `GET {escrita.revisao.ajuste}` — o robô já refez o título e está na descrição. */
export const AJUSTE_FAZENDO = {
    ajuste: {
        situacao: 'fazendo',
        partes: { titulo: { situacao: 'refeito', mensagem: 'Título refeito.' }, descricao: { situacao: 'fazendo', mensagem: 'O robô está refazendo.' } },
        restantes_hoje: 2,
        mensagem: 'O robô está refazendo…',
    },
};

/**
 * Terminou: o título foi refeito; a descrição a equipe tinha editado à mão (o robô só reescreve o campo que ainda é
 * dele — `mantido`, com a mensagem do servidor).
 */
export const AJUSTE_FEITO = {
    ajuste: {
        situacao: 'feito',
        partes: {
            titulo: { situacao: 'refeito', mensagem: 'Título refeito.' },
            descricao: { situacao: 'mantido', mensagem: 'A equipe editou a descrição; o robô não mexe nela.' },
        },
        restantes_hoje: 2,
        mensagem: 'Ajuste pronto.',
    },
};

/** Só o título pedido, e a cota diária da loja acabou: `erro` sem gastar (§19.14) — a causa única vira a mensagem geral. */
export const AJUSTE_COM_ERRO = {
    ajuste: {
        situacao: 'erro',
        partes: { titulo: { situacao: 'erro', mensagem: 'O limite diário do robô para esta loja acabou. Amanhã libera de novo.' } },
        restantes_hoje: 2,
        mensagem: 'O limite diário do robô para esta loja acabou. Amanhã libera de novo.',
    },
};

/** As duas partes falharam por motivos diferentes: a mensagem geral é a genérica; a causa vai em cada parte. */
export const AJUSTE_COM_DOIS_ERROS = {
    ajuste: {
        situacao: 'erro',
        partes: {
            titulo: { situacao: 'erro', mensagem: 'O robô não achou um título diferente do atual.' },
            descricao: { situacao: 'erro', mensagem: 'O robô chegou ao mesmo texto; nada mudou.' },
        },
        restantes_hoje: 1,
        mensagem: 'O robô não conseguiu fazer o ajuste.',
    },
};

/** `GET` de um item que nunca pediu ajuste (ou o pedido venceu no cache). */
export const SEM_AJUSTE = { ajuste: null };

/** A prévia com o ajuste andando (a mesma forma do GET): a gaveta retoma o acompanhamento; a linha diz D60. */
export const PREVIA_AJUSTANDO = {
    ...PREVIA_COM_PRECO,
    item: { ...PREVIA_COM_PRECO.item, status: 'Robô refazendo · título, descrição' },
    ajuste: AJUSTE_FAZENDO.ajuste,
    ajustes_restantes_hoje: 2,
};

/** O título novo que o robô escreveu (diferente dos dois de antes — `evitar`). */
export const TITULO_REFEITO = 'Cadeira De Jantar Jatobá Maciço Assento Palhinha Natural';

/** A prévia recarregada no fim do ajuste: o título novo nos dois tipos, a revisão subiu, o resultado em `ajuste`. */
export const PREVIA_AJUSTADA = {
    ...PREVIA_COM_PRECO,
    revisao: 38,
    anuncios: PREVIA_COM_PRECO.anuncios.map((a) => ({ ...a, titulo: TITULO_REFEITO })),
    ajuste: AJUSTE_FEITO.ajuste,
    ajustes_restantes_hoje: 2,
};

/** A prévia de um anúncio que já teve os 3 ajustes de hoje: o botão fica desligado, com a dica. */
export const PREVIA_SEM_AJUSTES = {
    ...PREVIA_COM_PRECO,
    ajuste: { ...AJUSTE_FEITO.ajuste, restantes_hoje: 0 },
    ajustes_restantes_hoje: 0,
};

/** 409 da revisão (o preço mudou noutra aba depois que a gaveta abriu): `{revisao_atual, message}` SEM `travado`. */
export const CONFLITO_DO_AJUSTE = { revisao_atual: 39, message: 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.' };

/** 409 com um ajuste já andando (outra aba pediu): só `{message}`, neutro. */
export const AJUSTE_JA_ANDANDO = { message: 'O robô já está refazendo este anúncio. Espere terminar.' };

/** 409 do item na fila de publicação (ou publicando, publicado, fora da revisão, não pronto): só `{message}`. */
export const AJUSTE_NA_FILA_DE_PUBLICACAO = { message: 'Este anúncio já está na fila de publicação: o robô não mexe mais nele.' };

/** 422 do limite do dia (§19.12: 3 ajustes por anúncio por dia), em `errors.refazer`. */
export const AJUSTE_NO_LIMITE = {
    message: 'Este anúncio já teve 3 ajustes hoje. Amanhã libera de novo.',
    errors: { refazer: ['Este anúncio já teve 3 ajustes hoje. Amanhã libera de novo.'] },
};

/** O `GET` depois do 422 do limite: o último ajuste do dia, com `restantes_hoje: 0`. */
export const AJUSTE_DO_LIMITE = { ajuste: { ...AJUSTE_FEITO.ajuste, restantes_hoje: 0 } };

/**
 * O kit da fase N (ou o rascunho que o robô nunca preparou): o texto não é do robô. O servidor manda
 * `ajustes_restantes_hoje: 0` também aqui, e o `preparo` vem sem título nem descrição — o botão some (não é limite).
 */
export const PREVIA_SEM_ROBO = {
    ...PREVIA_COM_PRECO,
    preparo: { titulo: null, descricao: null, imagens: null },
    ajuste: null,
    ajustes_restantes_hoje: 0,
};

// ═══ 4ª entrega, 4f: as fotos que o CLIENTE envia (o contrato do §19.15) ═════

/** A base das fotos do cliente (`cliente_escrita.fotos`): só a porta do cliente de verdade a recebe. */
export const CLIENTE_ESCRITA = { fotos: '/portal/459/trilha/produtos/__PRODUTO__/fotos' };

/** A porta do cliente de verdade, com a trilha de trabalho e o endereço das fotos. */
export const PROPS_CLIENTE_COM_FOTOS = { ...paraCliente(PROPS_TRABALHO), cliente_escrita: CLIENTE_ESCRITA };

/** O quadro LITERAL do §19.15 (a lista de fotos vem curta no desenho; o `faltam` é o do servidor, não a conta). */
export const QUADRO_DO_CONTRATO = {
    produto: { id: 91, nome: 'Cadeira Jatobá', editavel: true, motivo: null },
    cores: [{
        variacao_id: 301, rotulo: 'Natural', minimo: 3, maximo: 12,
        fotos: [{ id: 5501, url: '/portal/88/trilha/fotos/301/5501' }], faltam: 1,
    }],
    limites: { tamanho_mb: 10, tipos: ['jpg', 'png', 'webp'], por_envio: 12 },
};

const fotoDoCliente = (variacao, id) => ({ id, url: `/portal/459/trilha/fotos/${variacao}/${id}` });

/** A frase do servidor quando o produto andou ou saiu da Lista (o `motivo` e o 409). */
export const MOTIVO_DAS_FOTOS = 'Este produto já seguiu para a próxima etapa. Para trocar as fotos, fale com a equipe.';

/**
 * `GET` das fotos da banqueta (produto 6, na Lista): Natural com 1 foto (faltam 2), Mel completa (3). O quadro é o da
 * régua das fotos por cor do servidor; a caixa desenha só ele (o `variacoes` da Lista do exemplo não entra).
 */
export const QUADRO_DE_FOTOS = {
    produto: { id: 6, nome: 'Banqueta alta jatobá 75 cm', editavel: true, motivo: null },
    cores: [
        { variacao_id: 601, rotulo: 'Natural', minimo: 3, maximo: 12, fotos: [fotoDoCliente(601, 5501)], faltam: 2 },
        { variacao_id: 602, rotulo: 'Mel', minimo: 3, maximo: 12, fotos: [fotoDoCliente(602, 5511), fotoDoCliente(602, 5512), fotoDoCliente(602, 5513)], faltam: 0 },
    ],
    limites: { tamanho_mb: 10, tipos: ['jpg', 'png', 'webp'], por_envio: 12 },
};

/** `POST …/variacoes/601/fotos` com 2 fotos: o quadro novo (Natural completa; a banqueta segue na Lista pela descrição). */
export const QUADRO_ENVIADO = {
    ...QUADRO_DE_FOTOS,
    cores: [
        { ...QUADRO_DE_FOTOS.cores[0], fotos: [fotoDoCliente(601, 5501), fotoDoCliente(601, 5502), fotoDoCliente(601, 5503)], faltam: 0 },
        QUADRO_DE_FOTOS.cores[1],
    ],
    avisos: [],
};

/** Os avisos do envio (`FotosDoClienteNaTrilha::AVISO_SEGUIU` e `AVISO_JA_EM_PREPARO`). */
export const AVISO_SEGUIU = 'Fotos recebidas. O cadastro ficou completo e o produto seguiu para a próxima etapa.';
export const AVISO_JA_EM_PREPARO = 'As fotos ficaram guardadas, mas o anúncio deste produto já começou a ser montado: avise a equipe para usar as fotos novas.';

/** O envio que COMPLETOU o produto: ele saiu da Lista — o quadro novo já vem só leitura, com o aviso. */
export const QUADRO_ENVIADO_SEGUIU = {
    ...QUADRO_ENVIADO,
    produto: { ...QUADRO_ENVIADO.produto, editavel: false, motivo: MOTIVO_DAS_FOTOS },
    avisos: [AVISO_SEGUIU],
};

/** `DELETE …/variacoes/602/fotos/5512`: o quadro novo (Mel com 2, falta 1). */
export const QUADRO_DEPOIS_DE_EXCLUIR = {
    ...QUADRO_DE_FOTOS,
    cores: [
        QUADRO_DE_FOTOS.cores[0],
        { ...QUADRO_DE_FOTOS.cores[1], fotos: [fotoDoCliente(602, 5511), fotoDoCliente(602, 5513)], faltam: 1 },
    ],
};

/** A banqueta com Natural no teto (12 fotos): o [Enviar fotos] dela some. */
export const QUADRO_COR_CHEIA = {
    ...QUADRO_DE_FOTOS,
    cores: [
        { ...QUADRO_DE_FOTOS.cores[0], fotos: Array.from({ length: 12 }, (_, i) => fotoDoCliente(601, 5600 + i)), faltam: 0 },
        QUADRO_DE_FOTOS.cores[1],
    ],
};

/** `GET` das fotos da cadeira (produto 2, já mapeado): só leitura, com o motivo. */
export const QUADRO_SO_LEITURA = {
    produto: { id: 2, nome: 'Cadeira jatobá assento palhinha', editavel: false, motivo: MOTIVO_DAS_FOTOS },
    cores: [
        { variacao_id: 201, rotulo: 'Natural', minimo: 3, maximo: 12, fotos: [fotoDoCliente(201, 4101), fotoDoCliente(201, 4102), fotoDoCliente(201, 4103), fotoDoCliente(201, 4104)], faltam: 0 },
        { variacao_id: 202, rotulo: 'Mel', minimo: 3, maximo: 12, fotos: [fotoDoCliente(202, 4111), fotoDoCliente(202, 4112), fotoDoCliente(202, 4113), fotoDoCliente(202, 4114)], faltam: 0 },
    ],
    limites: { tamanho_mb: 10, tipos: ['jpg', 'png', 'webp'], por_envio: 12 },
};

/** 409 do envio ou da exclusão: o produto andou (ou saiu da Lista) — só `{message}`, nada grava. */
export const CONFLITO_DAS_FOTOS = { message: MOTIVO_DAS_FOTOS };

/** 422 das regras de imagem (`RegrasDoCadastro::mensagensDasImagens`): uma mensagem por arquivo, em `imagens.N`. */
export const ERROS_DAS_FOTOS = {
    message: 'A imagem 2 não está num formato aceito. Envie JPG, PNG ou WebP.',
    errors: {
        'imagens.1': ['A imagem 2 não está num formato aceito. Envie JPG, PNG ou WebP.'],
        'imagens.0': ['A imagem 1 passa de 10 MB; tente uma menor.'],
    },
};

/** O 413 do `post_max_size`, que o servidor devolve como 422 em `errors.imagens` (`bootstrap/app.php`). */
export const FOTOS_GRANDES_DEMAIS = {
    message: 'A imagem é grande demais para enviar; tente uma menor.',
    errors: { imagens: ['A imagem é grande demais para enviar; tente uma menor.'] },
};

/**
 * Cor e tamanho: a foto é da COR e mora na "dona" dela — nem sempre a 1ª variação (aqui o Azul guarda as fotos no
 * M, `variacao_id` 712, e não no P, 711). O envio e a exclusão vão EXATAMENTE para o `variacao_id` do quadro.
 */
export const QUADRO_COR_E_TAMANHO = {
    produto: { id: 12, nome: 'Camiseta básica algodão', editavel: true, motivo: null },
    cores: [
        { variacao_id: 712, rotulo: 'Azul', minimo: 3, maximo: 12, fotos: [fotoDoCliente(712, 7001)], faltam: 2 },
        { variacao_id: 721, rotulo: 'Preto', minimo: 3, maximo: 12, fotos: [], faltam: 3 },
    ],
    limites: { tamanho_mb: 10, tipos: ['jpg', 'png', 'webp'], por_envio: 12 },
};

/** O produto que não varia: uma galeria só, `rotulo` nulo. */
export const QUADRO_SEM_COR = {
    produto: { id: 9, nome: 'Criado-mudo 2 gavetas', editavel: true, motivo: null },
    cores: [{ variacao_id: 901, rotulo: null, minimo: 3, maximo: 12, fotos: [fotoDoCliente(901, 9001)], faltam: 2 }],
    limites: { tamanho_mb: 10, tipos: ['jpg', 'png', 'webp'], por_envio: 12 },
};

/** 403 da escrita vindo do `portal.auth` (a aba é de outra loja): `{message}` em pt-BR. */
export const SEM_ACESSO_A_EMPRESA = { message: 'Você não tem acesso a esta empresa.' };
