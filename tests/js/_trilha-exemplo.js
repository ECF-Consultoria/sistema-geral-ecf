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
        produtos: props.produtos.map(({ url_publicador, sugestoes_pendentes, ...p }) => ({
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

/** As que ainda entram, mudam ou saem de onda (`planejavel` = tem produto na Lista e não andou). */
const PLANEJAVEIS = new Set(['c-2-4', 'o-31', 'o-32', 'c-3-2', 'c-1-2']);

export const PROPS_TRABALHO = {
    ...PROPS_EQUIPE,
    trabalho: true,
    pode_editar: true,
    escrita: ESCRITA,
    ondas: ONDAS,
    produtos: PROPS_EQUIPE.produtos.map((p) => {
        // O rack: a Simples dele já andou (está na revisão) — com a trilha de trabalho, ele já está mapeado.
        if (p.id === 4) return { ...p, etapa: null, mapeado_em: null, mapeado_por: null };
        // O aparador foi mapeado na trilha.
        if (p.id === 3) return { ...p, mapeado_em: '2026-11-01T14:32:00-03:00', mapeado_por: 'Rafael' };

        return { ...p, mapeado_em: null, mapeado_por: null };
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
