// ═══════════════════════════════════════════════════════════════════════════
// Motor do Planejador FULL — reposição do estoque no Fulfillment do ML.
//
// Porta da função compute() do "Planejador_FULL.html" que o time usava com
// upload de planilha. A conta é a mesma; mudou a origem dos dados: tudo vem da
// coleta diária na API (FullColetaService) e a "Entrada pendente" vem dos envios
// registrados no módulo (FullEnviosService), porque a API não a entrega.
//
// Fica no navegador (e não no PHP) para cada ajuste de lead time, galpão ou
// quantidade recalcular a tela na hora, como no HTML.
//
// Datas são DIAS INTEIROS desde 1970-01-01 (UTC) — sem fuso, sem hora.
// ═══════════════════════════════════════════════════════════════════════════

export const DIA_MS = 86400000;
const EPS = 1e-9;

export const CONFIG_PADRAO = {
    preparo: 2,
    agenda: 3,
    transito: 2,
    recebimento: 3,
    proximoDespacho: '',
    intervaloEnvios: 14,
    segA: 10,
    segB: 7,
    segC: 5,
    fatorSazonal: 1,
    limiteExcesso: 90,
    leadTimeFornecedor: 15,
    intervaloCompras: 30,
    abcA: 0.8,
    abcB: 0.95,
};

// ─── datas ───────────────────────────────────────────────────────────────
export function paraDia(iso) {
    if (!iso || typeof iso !== 'string') return null;
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return null;
    return Math.round(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) / DIA_MS);
}

export function deDia(n) {
    if (n == null || !Number.isFinite(n)) return '';
    return new Date(n * DIA_MS).toISOString().slice(0, 10);
}

export function hojeDia(agora = new Date()) {
    return Math.round(Date.UTC(agora.getFullYear(), agora.getMonth(), agora.getDate()) / DIA_MS);
}

// ─── texto e números ────────────────────────────────────────────────────
export function normalizar(s) {
    return String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/\s+/g, ' ').trim();
}

export function arredondarMultiplo(q, m) {
    const mult = Math.max(1, Math.round(m || 1));
    return Math.ceil(q / mult) * mult;
}

const preenchido = (v) => v != null && v !== '';

/** Chave de agrupamento por SKU: produto sem SKU vira um grupo só dele. */
export const chaveSku = (p) => (p.sku ? p.sku : `#${p.cod}`);

// ─── cálculo principal ──────────────────────────────────────────────────
/**
 * @param {object} entrada
 * @param {object} entrada.config         ajustes (chaves de CONFIG_PADRAO)
 * @param {string} entrada.dataBase       dia em que o estoque foi lido (YYYY-MM-DD)
 * @param {number} entrada.diasVendas     dias da janela de vendas (30)
 * @param {Array}  entrada.produtos       [{cod, sku, titulo, marca, anuncios, status, aptas, transferencia, pendente, vendas, faturamento}]
 * @param {object} entrada.skus           {chaveSku: {galpao, aberto, abertoData, fornecedor, multiplo, leadTime}}
 * @param {object} entrada.itens          {cod: {ajuste, qtdManual, fora, observacao}}
 * @param {Array}  entrada.fornecedores   [{nome, leadTime, ciclo, observacoes}]
 * @param {number} [entrada.hoje]         dia de hoje (para testes)
 */
export function calcular({ config = {}, dataBase, diasVendas = 30, produtos = [], skus = {}, itens = {}, fornecedores = [], hoje } = {}) {
    const C = { ...CONFIG_PADRAO, ...config };
    for (const k of Object.keys(CONFIG_PADRAO)) {
        if (k !== 'proximoDespacho') C[k] = Number(C[k]);
    }
    const base = paraDia(dataBase) ?? hojeDia();
    const today = hoje ?? hojeDia();
    const dias = Math.max(1, Number(diasVendas) || 30);

    const porFornecedor = {};
    fornecedores.forEach((f) => { if (f && f.nome) porFornecedor[normalizar(f.nome)] = f; });

    const LTfull = C.preparo + C.agenda + C.transito + C.recebimento;
    const ship = paraDia(C.proximoDespacho) ?? base + C.preparo + C.agenda;
    const arrival = ship + C.transito + C.recebimento;
    const daysA = arrival - base;

    const ativos = produtos.filter((p) => p.aptas > 0 || p.pendente > 0 || p.transferencia > 0 || p.vendas > 0);
    const totalFat = ativos.reduce((a, p) => a + (p.faturamento || 0), 0);

    // Curva ABC por faturamento: soma de quem fatura MAIS que o item (empate não conta),
    // como o HTML original — feito em ordem para não ser O(n²).
    const faturamentoAcima = new Map();
    {
        const ordenados = [...ativos].sort((a, b) => (b.faturamento || 0) - (a.faturamento || 0));
        let acumulado = 0;
        let i = 0;
        while (i < ordenados.length) {
            const f = ordenados[i].faturamento || 0;
            let j = i;
            while (j < ordenados.length && (ordenados[j].faturamento || 0) === f) {
                faturamentoAcima.set(ordenados[j].cod, acumulado);
                j++;
            }
            acumulado += f * (j - i);
            i = j;
        }
    }

    const rows = ativos.map((p) => {
        const it = itens[p.cod] || {};
        const chave = chaveSku(p);
        const sd = skus[chave] || {};
        const ajuste = Number(it.ajuste) || 0;
        const d = (p.vendas / dias) * C.fatorSazonal * (1 + ajuste);
        const fat = p.faturamento || 0;
        const acima = faturamentoAcima.get(p.cod) || 0;
        const abc = totalFat > 0 && fat > 0
            ? (acima / totalFat < C.abcA ? 'A' : acima / totalFat < C.abcB ? 'B' : 'C')
            : 'C';
        const seg = abc === 'A' ? C.segA : abc === 'B' ? C.segB : C.segC;
        const aCaminho = (p.pendente || 0) + (p.transferencia || 0);
        const pos = (p.aptas || 0) + aCaminho;
        const cobertura = d > 0 ? pos / d : null;
        const ruptura = d > 0 ? base + Math.floor(pos / d + EPS) : null;
        const projecao = pos - d * daysA;
        const necessidade = d * (C.intervaloEnvios + seg) - Math.max(0, projecao);
        const multiplo = Number(sd.multiplo) || 1;
        const fora = !!it.fora;
        const sugerido = !fora && d > 0 && necessidade > 0 ? arredondarMultiplo(Math.ceil(necessidade - 1e-9), multiplo) : 0;
        const iniciarAte = d > 0 ? base + Math.floor((pos - d * (LTfull + seg)) / d + EPS) : null;

        let status;
        if (d <= 0) status = 'semgiro';
        else if (p.aptas <= 0) status = 'ruptura';
        else if (projecao < 0) status = 'critico';
        else if (projecao < d * seg) status = 'atencao';
        else if (cobertura > C.limiteExcesso) status = 'excesso';
        else status = 'ok';

        const receitaDia = (fat / dias) * C.fatorSazonal * (1 + ajuste);
        const diasPerdidos = d > 0 ? Math.max(0, daysA - pos / d) : 0;
        const excesso = d > 0 ? Math.max(0, Math.floor(pos - d * C.limiteExcesso + EPS)) : pos;

        return {
            cod: p.cod,
            sku: p.sku || null,
            chaveSku: chave,
            titulo: p.titulo,
            marca: p.marca || null,
            anuncios: p.anuncios || [],
            anuncioStatus: p.status || null,
            vendas: p.vendas,
            faturamento: fat,
            d,
            ajuste,
            abc,
            seg,
            aptas: p.aptas || 0,
            pendente: p.pendente || 0,
            transferencia: p.transferencia || 0,
            aCaminho,
            pos,
            cobertura,
            ruptura,
            projecao,
            sugerido,
            iniciarAte,
            status,
            receitaDia,
            emRisco: diasPerdidos * receitaDia,
            diasPerdidos,
            excesso,
            fora,
            qtdManual: preenchido(it.qtdManual) ? Number(it.qtdManual) : null,
            observacao: it.observacao || '',
        };
    });

    // Estoque do galpão por SKU, dividido entre os Códigos ML na proporção da necessidade.
    const porSku = {};
    rows.forEach((r) => { (porSku[r.chaveSku] = porSku[r.chaveSku] || []).push(r); });

    Object.keys(porSku).forEach((chave) => {
        const sd = skus[chave] || {};
        const informado = preenchido(sd.galpao);
        const g = informado ? Number(sd.galpao) || 0 : null;
        const lista = porSku[chave];
        const somaSug = lista.reduce((a, r) => a + (r.fora ? 0 : r.sugerido), 0);
        lista.forEach((r) => {
            const s = r.fora ? 0 : r.sugerido;
            r.galpaoInformado = informado;
            r.galpaoSku = g;
            r.podeEnviar = !informado ? s : somaSug <= 0 ? 0 : Math.min(s, Math.floor((g * s) / somaSug + EPS));
            r.falta = informado ? s - r.podeEnviar : null;
            r.compartilhado = lista.length > 1;
            r.qtdFinal = r.fora ? 0 : (r.qtdManual != null ? r.qtdManual : r.podeEnviar);
        });
    });

    // Compras por SKU.
    const compras = Object.keys(porSku).map((chave) => {
        const lista = porSku[chave];
        const sd = skus[chave] || {};
        const fornecedor = sd.fornecedor || lista[0].marca || 'Outros';
        const f = porFornecedor[normalizar(fornecedor)] || {};
        const lt = preenchido(sd.leadTime) ? Number(sd.leadTime) : (preenchido(f.leadTime) ? Number(f.leadTime) : C.leadTimeFornecedor);
        const ciclo = preenchido(f.ciclo) ? Number(f.ciclo) : C.intervaloCompras;
        const d = lista.reduce((a, r) => a + r.d, 0);
        const posFull = lista.reduce((a, r) => a + r.pos, 0);
        const informado = preenchido(sd.galpao);
        const galpao = informado ? Number(sd.galpao) || 0 : 0;
        const aberto = Number(sd.aberto) || 0;
        const posTotal = posFull + galpao + aberto;
        const seg = Math.max(...lista.map((r) => r.seg));
        const LTtotal = lt + LTfull;
        const multiplo = Number(sd.multiplo) || 1;
        const pedirAte = d > 0 ? base + Math.floor((posTotal - d * (LTtotal + seg)) / d + EPS) : null;
        const comprar = d > 0 ? arredondarMultiplo(Math.max(0, Math.ceil(d * (LTtotal + ciclo + seg) - posTotal - 1e-9)), multiplo) : 0;

        let status;
        if (d <= 0) status = 'semgiro';
        else if (pedirAte < today) status = 'ja';
        else if (pedirAte <= today + 7) status = 'semana';
        else if (pedirAte <= today + 30) status = 'mes';
        else status = 'ok';

        const necessidadeProx = lista.reduce((a, r) => a + (r.fora ? 0 : r.sugerido), 0);

        return {
            chaveSku: chave,
            sku: lista[0].sku,
            titulo: lista[0].titulo,
            cods: lista.map((r) => r.cod),
            fornecedor,
            lt,
            ciclo,
            d,
            posFull,
            galpao,
            informado,
            aberto,
            abertoData: sd.abertoData || '',
            posTotal,
            cobertura: d > 0 ? posTotal / d : null,
            seg,
            pedirAte,
            comprar,
            status,
            necessidadeProx,
            faltaProx: informado ? Math.max(0, necessidadeProx - galpao) : null,
            multiplo,
            faturamento: lista.reduce((a, r) => a + r.faturamento, 0),
        };
    });

    const conta = (st) => rows.filter((r) => r.status === st).length;

    return {
        C,
        base,
        today,
        LTfull,
        ship,
        arrival,
        daysA,
        dias,
        rows,
        compras,
        totais: {
            itens: rows.length,
            ruptura: conta('ruptura'),
            critico: conta('critico'),
            atencao: conta('atencao'),
            ok: conta('ok'),
            excesso: conta('excesso'),
            semgiro: conta('semgiro'),
            sugUnidades: rows.reduce((a, r) => a + (r.fora ? 0 : r.sugerido), 0),
            finalUnidades: rows.reduce((a, r) => a + r.qtdFinal, 0),
            finalItens: rows.filter((r) => r.qtdFinal > 0).length,
            emRisco: rows.reduce((a, r) => a + r.emRisco, 0),
            receitaDiaRuptura: rows.filter((r) => r.status === 'ruptura').reduce((a, r) => a + r.receitaDia, 0),
            excessoUnidades: rows.reduce((a, r) => a + r.excesso, 0),
            comprarJa: compras.filter((b) => b.status === 'ja').length,
            comprarSemana: compras.filter((b) => b.status === 'semana').length,
            comprarUrgenteUnidades: compras.filter((b) => b.status === 'ja' || b.status === 'semana').reduce((a, b) => a + b.comprar, 0),
            semGalpao: compras.filter((b) => !b.informado && b.d > 0).length,
        },
    };
}

/** Prioridade de uma linha: ruptura/crítico, depois atenção, ok; desempate por receita/dia. */
const grupoPrioridade = (st) => (st === 'ruptura' || st === 'critico' ? 3 : st === 'atencao' ? 2 : st === 'ok' ? 1 : 0);
export const porPrioridade = (a, b) => grupoPrioridade(b.status) - grupoPrioridade(a.status) || b.receitaDia - a.receitaDia || (a.cod < b.cod ? -1 : a.cod > b.cod ? 1 : 0);

/**
 * Projeção do estoque no FULL dia a dia: sem envio e com o envio de `qtdFinal`.
 * @returns {Array<{t:number, dia:number, sem:number, com:number}>}
 */
export function projecaoEstoque(row, R) {
    const dias = Math.max(R.daysA + R.C.intervaloEnvios + 7, 21);
    const serie = [];
    for (let t = 0; t <= dias; t++) {
        const sem = Math.max(0, row.pos - row.d * t);
        let com = sem;
        if (t >= R.daysA) {
            const naChegada = Math.max(0, row.pos - row.d * R.daysA) + row.qtdFinal;
            com = Math.max(0, naChegada - row.d * (t - R.daysA));
        }
        serie.push({ t, dia: R.base + t, sem, com });
    }
    return serie;
}

// ─── formatos (pt-BR) ───────────────────────────────────────────────────
const nf0 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
const DIAS_SEMANA = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

export const fmtInt = (n) => (n == null || !Number.isFinite(n) ? '—' : nf0.format(Math.round(n)));
export const fmtDec = (n) => (n == null || !Number.isFinite(n) ? '—' : (Math.abs(n) >= 100 ? nf0.format(n) : nf1.format(n)));
export const fmtBRL = (n) => (n == null || !Number.isFinite(n) ? '—' : brl.format(n));
export const fmtCobertura = (c) => (c == null ? 'sem venda' : c > 999 ? '999+ d' : `${fmtDec(c)} d`);

export function fmtDia(dia, modo) {
    if (dia == null || !Number.isFinite(dia)) return '—';
    const d = new Date(dia * DIA_MS);
    const s = `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
    if (modo === 'semana') return `${DIAS_SEMANA[d.getUTCDay()]} ${s}`;
    if (modo === 'ano') return `${s}/${d.getUTCFullYear()}`;
    return s;
}

export function fmtRelativo(dia, hoje = hojeDia()) {
    if (dia == null) return '';
    const n = dia - hoje;
    if (n === 0) return 'hoje';
    if (n === 1) return 'amanhã';
    if (n === -1) return 'ontem';
    return n > 0 ? `em ${n} dias` : `há ${-n} dias`;
}
