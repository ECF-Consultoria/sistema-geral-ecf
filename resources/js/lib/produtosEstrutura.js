import { createElement as h } from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';

// ═══════════════════════════════════════════════════════════════════════
// Produtos do Mapeamento Estrutural: formatação e contrato do POST linhas da
// ficha (167-11; 167-18/D-23: sem grade).
//
// SÓ formatação e o contrato do POST. Logística, peso cubado, frete e "Falta"
// chegam calculados do servidor (PORTAL-02) e aqui apenas se exibem: este arquivo
// não tem regra de negócio nenhuma, e um gate (tests/js/estrutura-produtos.test.js)
// barra qualquer conta que apareça. Sem JSX de propósito: arquivo .js.
// ═══════════════════════════════════════════════════════════════════════

/** Pílulas de logística (12px, sem borda; ME1 é neutro porque não é problema). */
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

/** Conteúdo em linha para caber no cartão da lista. */
const emLinha = (...filhos) => h('span', { className: 'inline-flex min-w-0 items-center gap-1 text-[13px]' }, ...filhos);

const TIP_ME1 = 'Fora do tamanho do envio ME2. O frete usa a tabela da sua transportadora; ainda não calculamos aqui.';
const TIP_FALHOU = 'Não deu para consultar o Mercado Livre agora. Tente de novo.';
const TIP_REFERENCIA = 'Informe o custo para o frete usar o preço certo.';
const TIP_FAIXA = 'Neste preço o frete pode mudar de faixa.';

/**
 * Célula "Frete ME2": só mostra o que o servidor mandou (estimativa da tabela ECF, valor
 * do ML, falha, faixa de referência, ME1 e o alerta de faixa). Nenhum limite mora aqui.
 * `forma = 'pilha'` (ficha, 167-19) empilha valor e apoio; a 'linha' é a da lista.
 */
export function renderFrete(row, { consultando = false } = {}, forma = 'linha') {
    if (! row?.id) return null;
    const pilha = forma === 'pilha';
    const caixa = (...filhos) => (pilha
        ? h('span', { className: 'inline-flex flex-col leading-tight' }, ...filhos)
        : emLinha(...filhos));
    const apoio = (t, title) => h('span', { className: pilha ? 'text-[13px] text-white/60' : 'truncate text-[12px] text-white/45', title }, t);
    const valorCls = pilha ? 'text-[15px] font-semibold tabular-nums text-white' : 'tabular-nums text-white/60';

    if (consultando) {
        return caixa(h(Loader2, { size: 12, className: 'shrink-0 animate-spin text-white/45', 'aria-hidden': 'true' }), apoio('consultando'));
    }
    if (row.logistica === 'me1') {
        return caixa(h('span', { className: 'text-white/60', title: TIP_ME1 }, '—'), apoio('sem frete aqui', TIP_ME1));
    }
    if (! row.logistica || row.logistica === 'pendente' || ! row.frete || row.frete.valor == null) {
        return caixa(h('span', { className: 'text-white/60' }, '—'));
    }
    const t = textoFrete(row.frete);
    const tip = row.frete.falhou ? TIP_FALHOU : row.frete.preco_origem === 'referencia' ? TIP_REFERENCIA : undefined;
    const alerta = row.frete.alerta_faixa ? h(AlertTriangle, { size: 12, className: 'shrink-0 text-amber-300', title: TIP_FAIXA, 'aria-label': TIP_FAIXA }) : null;

    if (pilha) {
        return caixa(
            h('span', { className: 'inline-flex items-center justify-center gap-1' }, h('span', { className: valorCls }, t.valor), alerta),
            apoio(t.apoio, tip),
        );
    }

    return caixa(h('span', { className: valorCls }, t.valor), apoio(t.apoio, tip), alerta);
}

/** Célula "Peso cubado": "cobrado" só quando o cubado é o faturado (decisão do servidor). */
export function renderPesoCubado(row) {
    if (row?.peso_cubado == null) return null;
    const cobrado = row.cubado_cobrado ? h('span', { className: 'text-[12px] text-white/45' }, 'cobrado') : null;

    return h('span', { className: 'inline-flex items-center gap-1 tabular-nums text-white/60', title: `Peso cobrado: ${fmtKg(row.peso_faturado, 2)}` },
        fmtKg(row.peso_cubado, 2), cobrado);
}

/** Iniciais para o quadro da foto (D-29: sem upload): 1ª letra das duas primeiras palavras. */
export function iniciais(nome) {
    return String(nome ?? '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}

/** Caminho da categoria em partes ("A > B > C" → [A, B, C]) e o estado que o servidor deu. */
export function partesDaCategoria(linha) {
    const caminho = String(linha?.categoria_ml_caminho ?? '').trim();
    const partes = caminho
        ? caminho.split(' > ').map((p) => p.trim()).filter(Boolean)
        : [linha?.categoria_ml_nome ?? linha?.categoria_ml_id].filter(Boolean);

    return { partes, estado: linha?.categoria_estado ?? 'vazia' };
}

const listaEm = (itens) => (itens.length > 1 ? `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}` : itens[0]);

/** "Produto salvo." e, quando o servidor criou família/ambiente na lista da empresa, a frase de aviso. */
export function textoProdutoSalvo(data) {
    const criadas = data?.criadas_nas_listas ?? { familias: [], ambientes: [] };
    const partes = [];
    if (criadas.familias?.length) partes.push(`${criadas.familias.length > 1 ? 'as famílias' : 'a família'} ${listaEm(criadas.familias)}`);
    if (criadas.ambientes?.length) partes.push(`${criadas.ambientes.length > 1 ? 'os ambientes' : 'o ambiente'} ${listaEm(criadas.ambientes)}`);

    return `Produto salvo.${partes.length ? ` Criamos ${partes.join(' e ')}.` : ''}`;
}

/** "custo · categoria", na ordem que o servidor mandou. */
export function textoFalta(pendencias, rotulos = {}) {
    if (! Array.isArray(pendencias)) return '';

    return pendencias.map((p) => rotulos[p] ?? p).join(' · ');
}

/** Custo do servidor ("120.00") para o texto que se digita ("120,00"). */
const custoParaTexto = (c) => (c === null || c === undefined || c === '' ? '' : Number(c).toFixed(2).replace('.', ','));

/** Servidor → tela: acrescenta a chave estável e os textos das células de escolha. */
export function linhaDoServidor(linha, rotulos = {}) {
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

/**
 * Ficha → contrato do POST linhas. Campos de escolha só vão quando mudaram
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
    if (row._categoriaEscolhida && row.categoria_ml_id) {
        // Escolhida no picker: vai o id (o servidor confere se é folha), nunca o nome como texto.
        out.categoria_ml_id = row.categoria_ml_id;
    } else if (alterou('categoria')) {
        const t = String(row.categoria ?? '').trim();
        if (/^MLB\d+$/i.test(t)) out.categoria_ml_id = t;
        else out.categoria_texto = t;
    }
    // Caixas digitadas no editor vão como lista (vazia = limpar); o servidor interpreta os números.
    if (Array.isArray(row.volumes_digitados)) out.volumes = row.volumes_digitados;
    else if (alterou('volumes_texto') && String(row.volumes_texto ?? '').trim() !== '') out.volumes_texto = row.volumes_texto;
    if (alterou('custo') && String(row.custo ?? '').trim() !== '') out.custo = row.custo;

    return out;
}

// ─── Cartões da lista (167-20, D-25/D-30) ───────────────────────────────────

/**
 * Cores que a bolinha sabe desenhar (D-30: só cor CONHECIDA). Chave sem acento e
 * em minúsculo. O estilo vem SEMPRE daqui — o texto digitado pelo cliente nunca
 * vai para `style` (T-167-78).
 */
export const CORES_CONHECIDAS = {
    preto: '#1c1c1e', branco: '#f4f4f5', cinza: '#9ca3af', grafite: '#4b4f56', chumbo: '#52525b', prata: '#c0c4cc',
    dourado: '#c9a227', cobre: '#b87333', bege: '#d9c4a3', areia: '#d6c3a5', creme: '#f1e6c8', 'off white': '#efeae0',
    offwhite: '#efeae0', marrom: '#6b4426', caramelo: '#b5703a', cafe: '#5b3a29', natural: '#c89f73', madeira: '#a47148',
    nogueira: '#7a5230', freijo: '#b08a5a', carvalho: '#a0784b', amendoa: '#c9a27e', mel: '#c88a3d', rosa: '#f2b8b5',
    vermelho: '#c0392b', vinho: '#6d1f2f', bordo: '#7b1e2b', laranja: '#e67e22', terracota: '#c4673f', amarelo: '#f2c94c',
    mostarda: '#c9a13b', verde: '#3f8f5a', oliva: '#6b7a3a', azul: '#3b6fb6', marinho: '#1f2f55', turquesa: '#2bb3a8',
    roxo: '#6b3fa0', lilas: '#b39ddb', nude: '#e3bc9a',
};

/** Sem acento, minúsculo e espaços colapsados. */
const normalizar = (t) => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

/** Cor da bolinha da variação: só quando o eixo é Cor e o valor é conhecido; senão `null` (bolinha neutra). */
export function corDaVariacao(row) {
    if (row?.eixo !== 'cor') return null;
    const valor = normalizar(row.valor);
    if (! valor) return null;

    return CORES_CONHECIDAS[valor] ?? CORES_CONHECIDAS[valor.split(' ')[0]] ?? null;
}

/** O que falta no produto: junta as pendências de todas as variações, na ordem do servidor (a das chaves de `rotulos`). */
export function faltaDoProduto(variacoes, rotulos = {}) {
    const chaves = Object.keys(rotulos);
    const todas = new Set();
    (variacoes ?? []).forEach((v) => (v.pendencias ?? []).forEach((p) => todas.add(p)));
    const ordenadas = [...todas].sort((a, b) => {
        const ia = chaves.indexOf(a);
        const ib = chaves.indexOf(b);

        return (ia < 0 ? 999 : ia) - (ib < 0 ? 999 : ib);
    });
    const porVariacao = (variacoes ?? [])
        .filter((v) => (v.pendencias ?? []).length > 0)
        .map((v) => ({ codigo: v.codigo, texto: textoFalta(v.pendencias, rotulos) }));

    return { texto: ordenadas.map((p) => rotulos[p] ?? p).join(' · '), porVariacao };
}

/** Dica da variação com o que o cartão não mostra: medidas, peso cubado e custo (tudo vindo do servidor). */
export function detalheDaVariacao(row) {
    const partes = [resumoVolumes(row?.volumes) || 'sem medidas'];
    if (row?.peso_cubado != null) partes.push(`Peso cubado ${fmtKg(row.peso_cubado, 2)}`);
    if (row?.custo !== '' && row?.custo != null) {
        const n = Number(String(row.custo).replace(',', '.'));
        if (! Number.isNaN(n)) partes.push(`Custo ${n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`);
    }

    return partes.join(' · ');
}
