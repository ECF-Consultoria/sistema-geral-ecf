// ─── Exemplo de props da Trilha de produtos (porta da EQUIPE) ───────────────
//
// O formato é o contrato entre `App\Http\Controllers\Trilha\*` e as telas
// `Pages/Trilha/*`. Os dados imitam a "Casa Jatobá" do protótipo do Erlon e
// cobrem todas as etapas, um produto com duas cores, combo agrupado, kit,
// combit, oferta sem produto na lista, publicação com falha e anúncio parcial.
//
// O nome começa com `_` para o `node --test "tests/js/**/*.test.js"` não o
// tomar por teste. `paraCliente()` imita o que o servidor tira na porta do
// cliente (cliente.ecf…): nada de link do Publicador, de preparo, de MLB nem
// da lista de sugestões.

export const PROPS_EQUIPE = {
    visao: 'equipe',
    sessao_equipe: false,
    empresa: { id: 459, nome: 'Casa Jatobá', logo_url: null },
    voltar: { url: '/trilha', rotulo: 'Empresas' },
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
        links: { portal_abrir: null, planejamento: '/portal/459/estrutura/sugestoes', produtos: '/portal/459/estrutura/produtos', publicador: null },
        produtos: props.produtos.map(({ url_publicador, sugestoes_pendentes, ...p }) => ({
            ...p,
            sugestoes_pendentes: [],
            url_ficha: `/portal/459/estrutura/produtos/${p.id}`,
        })),
        ofertas: props.ofertas.map(({ url_publicador, preparo, ...o }) => ({
            ...o,
            preparo: null,
            anuncios: (o.anuncios ?? []).map((a) => ({ ...a, mlb: null })),
        })),
    };
}
