import { createElement as h } from 'react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════
// Produtos do Mapeamento Estrutural: colunas da grade e formatação (Fase 167-11).
//
// SÓ colunas e formatação. Logística, peso cubado, frete e "Falta" chegam
// calculados do servidor (PORTAL-02) e aqui apenas se exibem: este arquivo não
// tem regra de negócio nenhuma, e um gate (tests/js/estrutura-produtos.test.js)
// barra qualquer conta que apareça. Sem JSX de propósito: arquivo .js.
// ═══════════════════════════════════════════════════════════════════════

/** Pílulas da coluna Logística (12px, sem borda; ME1 é neutro porque não é problema). */
export const ESTILO_LOGISTICA = {
    me2_full: 'bg-emerald-500/10 text-emerald-300',
    me2:      'bg-sky-500/10 text-sky-300',
    me1:      'bg-white/[0.06] text-white/60',
    pendente: 'bg-white/[0.04] text-white/45',
};

const num = (n, casas = 1) => Number(n).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas });

/** "27,8 kg" (uma casa; vazio quando não há número). */
export function fmtKg(n, casas = 1) {
    if (n === null || n === undefined || n === '' || Number.isNaN(Number(n))) return '';

    return `${num(n, casas)} kg`;
}

/** Medida sem zeros inúteis: 186 → "186", 43.5 → "43,5". */
const medida = (n) => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 });

/** "186×43×12 · 27,8 kg" para um volume {c,l,a,kg}. */
export function fmtMedida(v) {
    if (! v) return '';

    return `${medida(v.c)}×${medida(v.l)}×${medida(v.a)} · ${fmtKg(v.kg)}`;
}

/** 1º volume e, havendo mais, " +N volume(s)". */
export function resumoVolumes(volumes) {
    if (! Array.isArray(volumes) || volumes.length === 0) return '';
    const mais = volumes.length - 1;

    return fmtMedida(volumes[0]) + (mais > 0 ? ` +${mais} ${mais === 1 ? 'volume' : 'volumes'}` : '');
}

/** Frete como o servidor devolveu: valor e a origem em texto de apoio. */
export function textoFrete(frete) {
    if (! frete || frete.valor === null || frete.valor === undefined) return { valor: '', apoio: '—' };
    const valor = Number(frete.valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    let apoio = frete.origem === 'api' ? 'ML' : 'estimativa';
    if (frete.falhou) apoio = 'não consultado';
    else if (frete.preco_origem === 'referencia') apoio = 'faixa de referência';

    return { valor, apoio };
}

/** "custo · categoria", na ordem que o servidor mandou. */
export function textoFalta(pendencias, rotulos = {}) {
    if (! Array.isArray(pendencias)) return '';

    return pendencias.map((p) => rotulos[p] ?? p).join(' · ');
}

const reais = (n) => Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

/** Custo do servidor ("120.00") para o texto que se digita ("120,00"). */
const custoParaTexto = (c) => (c === null || c === undefined || c === '' ? '' : Number(c).toFixed(2).replace('.', ','));

/** Servidor → grade: acrescenta a chave estável e os textos das células de escolha. */
export function linhaDaGrade(linha, rotulos = {}) {
    const ambientes = linha.ambientes ?? [];
    const pronta = {
        ...linha,
        _k: `v${linha.id}`,
        familia: linha.familia ?? '',
        eixo_rotulo: linha.eixo_rotulo ?? '',
        valor: linha.valor ?? '',
        categoria: linha.categoria_ml_nome ?? linha.categoria_ml_id ?? '',
        ambientes_texto: ambientes.join(', '),
        volumes_texto: linha.volumes_texto ?? '',
        custo: custoParaTexto(linha.custo),
        falta: textoFalta(linha.pendencias, rotulos),
    };
    // Retrato do que o servidor tem: só o que o cliente mexeu volta no POST.
    pronta._base = campoEditaveis(pronta);

    return pronta;
}

const CAMPOS_EDITAVEIS = ['codigo', 'nome', 'eixo_rotulo', 'valor', 'familia', 'ambientes_texto', 'categoria', 'volumes_texto', 'custo'];

/** Os campos que a pessoa pode alterar numa linha, para comparar antes/depois. */
export function campoEditaveis(row) {
    return Object.fromEntries(CAMPOS_EDITAVEIS.map((c) => [c, String(row[c] ?? '')]));
}

/** Houve mudança do cliente nesta linha? (compara só os campos editáveis) */
export function mudou(antes, depois) {
    return CAMPOS_EDITAVEIS.some((c) => String(antes?.[c] ?? '') !== String(depois?.[c] ?? ''));
}

/**
 * Grade → contrato do POST linhas. Campos de escolha só vão quando mudaram
 * (célula em branco enviada apagaria o dado); código e nome vão sempre.
 */
export function linhaParaServidor(row) {
    const base = row._base ?? {};
    const novo = ! row.id;
    // Sem retrato (linha digitada do zero) tudo conta como novo; com retrato, só o que mudou.
    const alterou = (c) => ! row._base || String(row[c] ?? '') !== String(base[c] ?? '');
    const out = {
        chave: row._k,
        codigo: String(row.codigo ?? '').trim(),
        nome: String(row.nome ?? '').trim(),
    };
    if (row.id) out.id = row.id;
    if (row.produto_id) out.produto_id = row.produto_id;
    // Grupo e "Variação" só existem para linha nova vinda da colagem com cabeçalho.
    if (novo && ! row.produto_id && row.grupo) out.grupo = row.grupo;
    if (novo && row.variacao) out.variacao = row.variacao;

    if (alterou('eixo_rotulo') && row.eixo_rotulo) out.eixo = row.eixo_rotulo;
    if (alterou('valor') && String(row.valor ?? '') !== '') out.valor = row.valor;
    if (alterou('familia') && String(row.familia ?? '') !== '') out.familia = row.familia;

    if (alterou('ambientes_texto') && (row.ambientes_texto || row.id)) {
        out.ambientes = String(row.ambientes_texto ?? '').split(/[,;|]/).map((s) => s.trim()).filter(Boolean);
    }
    if (alterou('categoria')) {
        const t = String(row.categoria ?? '').trim();
        if (/^MLB\d+$/i.test(t)) out.categoria_ml_id = t;
        else out.categoria_texto = t;
    }
    if (alterou('volumes_texto') && String(row.volumes_texto ?? '').trim() !== '') out.volumes_texto = row.volumes_texto;
    if (alterou('custo') && String(row.custo ?? '').trim() !== '') out.custo = row.custo;

    return out;
}

const celula = (...filhos) => h('div', { className: 'flex h-full w-full items-center gap-1 px-2 text-[13px]' }, ...filhos);
const fraco = (t) => h('span', { className: 'text-white/25' }, t);

/**
 * As 14 colunas do contrato de tela, na ordem. `editores` (opcional) troca
 * Família, Ambiente, Categoria e Volumes por picker quando os planos 13/14 chegarem.
 */
export function colunasDaGrade({ eixos = {}, logisticas = {}, editores = {} } = {}) {
    const picker = (col, chave) => (editores[chave] ? { ...col, type: 'picker', renderEditor: editores[chave] } : col);
    const produto = (valor, row) => (row?._primeira === false ? 'text-white/40' : null);

    return [
        { id: 'codigo', label: 'Ref', type: 'text', frozen: true, width: 120, placeholder: 'código',
            conditionalFormat: (v, row) => cn('font-mono', row?._primeira === false && 'border-l-2 border-white/15 pl-2') },
        { id: 'nome', label: 'Produto', type: 'text', frozen: true, width: 240, placeholder: 'nome do produto',
            conditionalFormat: produto },
        { id: 'eixo_rotulo', label: 'Eixo', type: 'select', width: 96, options: Object.values(eixos) },
        { id: 'valor', label: 'Valor', type: 'text', width: 120, placeholder: 'ex.: Natural' },
        picker({ id: 'familia', label: 'Família (linha de design)', type: 'text', width: 152, placeholder: 'escolher',
            conditionalFormat: produto }, 'familia'),
        picker({ id: 'ambientes_texto', label: 'Ambiente', type: 'text', width: 168, placeholder: 'escolher',
            conditionalFormat: produto,
            renderCell: (v, row) => {
                const lista = String(v ?? '').split(',').map((s) => s.trim()).filter(Boolean);
                if (lista.length === 0) return celula(fraco('escolher'));

                return celula(
                    h('span', { className: cn('truncate', row?._primeira === false ? 'text-white/40' : 'text-white/85') }, lista[0]),
                    lista.length > 1 ? h('span', { className: 'shrink-0 text-[12px] text-white/45' }, `+${lista.length - 1}`) : null,
                );
            } }, 'ambientes'),
        picker({ id: 'categoria', label: 'Categoria ML', type: 'text', width: 220, placeholder: 'escolher',
            conditionalFormat: produto,
            renderCell: (v, row) => {
                if (! v) return celula(fraco('escolher'));
                const apoio = row?.categoria_estado === 'a_confirmar' ? 'a confirmar' : row?.categoria_estado === 'nao_validada' ? 'não validada' : null;

                return h('div', { className: 'flex h-full w-full items-center gap-1 px-2 text-[13px]', title: row?.categoria_ml_caminho || String(v) },
                    h('span', { className: cn('truncate', row?._primeira === false ? 'text-white/40' : 'text-white/85') }, v),
                    apoio ? h('span', { className: 'shrink-0 text-[12px] text-white/45' }, apoio) : null);
            } }, 'categoria'),
        picker({ id: 'volumes_texto', label: 'Volumes', type: 'text', width: 200, placeholder: 'adicionar medidas',
            renderCell: (v, row) => {
                const resumo = resumoVolumes(row?.volumes);
                if (resumo) return h('div', { className: 'flex h-full w-full items-center px-2 font-mono text-[13px] text-white/85', title: String(v ?? '') },
                    h('span', { className: 'truncate' }, resumo));
                if (v) return h('div', { className: 'flex h-full w-full items-center px-2 font-mono text-[13px] text-white/85' }, h('span', { className: 'truncate' }, v));

                return celula(fraco('adicionar medidas'));
            } }, 'volumes'),
        { id: 'peso_total', label: 'Peso total', type: 'readonly', width: 88, separador: true,
            renderCell: (v, row) => celula(h('span', { className: 'tabular-nums text-white/60' }, fmtKg(row?.peso_total))) },
        { id: 'custo', label: 'Custo', type: 'text', width: 104, align: 'right', placeholder: '0,00',
            renderCell: (v) => (v === '' || v == null
                ? celula(fraco('0,00'))
                : h('div', { className: 'flex h-full w-full items-center justify-end px-2 text-[13px] tabular-nums text-white/85' },
                    /^-?\d+([.,]\d+)?$/.test(String(v)) ? reais(String(v).replace(',', '.')) : String(v))) },
        { id: 'peso_cubado', label: 'Peso cubado', type: 'readonly', width: 96, separador: true,
            renderCell: (v, row) => {
                if (row?.peso_cubado == null) return celula();
                const cobrado = row.cubado_cobrado ? h('span', { className: 'text-[12px] text-white/45' }, 'cobrado') : null;

                return h('div', { className: 'flex h-full w-full items-center gap-1 px-2 text-[13px] tabular-nums text-white/60', title: `Peso cobrado: ${fmtKg(row.peso_faturado, 2)}` },
                    fmtKg(row.peso_cubado, 2), cobrado);
            } },
        { id: 'logistica', label: 'Logística', type: 'readonly', width: 104,
            renderCell: (v, row) => {
                const chave = row?.logistica ?? 'pendente';
                const rotulo = logisticas[chave] ?? chave;
                if (! row?.id) return celula();

                return celula(h('span', { className: cn('whitespace-nowrap rounded-full px-2 py-1 text-[12px]', ESTILO_LOGISTICA[chave] ?? ESTILO_LOGISTICA.pendente),
                    title: chave === 'pendente' ? 'Pendente: completar cadastro' : undefined }, rotulo));
            } },
        { id: 'frete', label: 'Frete ME2', type: 'readonly', width: 120,
            renderCell: (v, row) => {
                if (! row?.id) return celula();
                const t = textoFrete(row.frete);

                return celula(
                    t.valor ? h('span', { className: 'tabular-nums text-white/60' }, t.valor) : null,
                    h('span', { className: 'truncate text-[12px] text-white/45' }, t.apoio),
                    row.frete?.alerta_faixa ? h('span', { className: 'text-[12px] text-amber-300', title: 'Neste preço o frete pode mudar de faixa.' }, '!') : null,
                );
            } },
        { id: 'falta', label: 'Falta', type: 'readonly', width: 160,
            renderCell: (v, row) => h('div', { className: 'flex h-full w-full items-center px-2 text-[12px] text-white/40', title: String(row?.falta ?? '') },
                h('span', { className: 'truncate' }, row?.falta ?? '')) },
    ];
}

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');

const CABECALHOS = {
    ref: 'codigo', codigo: 'codigo', sku: 'codigo', grupo: 'grupo', variacao: 'variacao',
    produto: 'nome', nome: 'nome', eixo: 'eixo_rotulo', valor: 'valor',
    ambiente: 'ambientes_texto', ambientes: 'ambientes_texto',
    categoria: 'categoria', categoriaml: 'categoria', volumes: 'volumes_texto', custo: 'custo',
};

/** Nome de cabeçalho do modelo (sem caixa/acento) → campo da grade, ou null. */
export function campoDoCabecalho(texto) {
    const n = semAcento(texto);
    if (n.startsWith('familia')) return 'familia';

    return CABECALHOS[n] ?? null;
}

/**
 * Se a 1ª linha colada tem os cabeçalhos do modelo, devolve as linhas de dados
 * já mapeadas POR NOME de coluna (qualquer ordem); senão, null e a grade cola por posição.
 * Exige ao menos dois cabeçalhos conhecidos, um deles Ref ou Produto.
 */
export function lerBlocoComCabecalho(matriz) {
    if (! Array.isArray(matriz) || matriz.length < 2) return null;
    const campos = matriz[0].map(campoDoCabecalho);
    const conhecidos = campos.filter(Boolean);
    if (conhecidos.length < 2 || ! (conhecidos.includes('codigo') || conhecidos.includes('nome'))) return null;

    return matriz.slice(1)
        .filter((cels) => cels.some((c) => String(c ?? '').trim() !== ''))
        .map((cels) => {
            const linha = {};
            campos.forEach((campo, i) => { if (campo) linha[campo] = String(cels[i] ?? '').trim(); });

            return linha;
        });
}
