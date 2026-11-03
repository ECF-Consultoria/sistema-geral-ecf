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
