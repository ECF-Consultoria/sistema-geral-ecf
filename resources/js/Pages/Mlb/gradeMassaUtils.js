// ═══════════════════════════════════════════════════════════════════════
// Helpers puros da grade de anúncio em massa (/mlb/anuncios → Em massa).
//
// Vivem fora de AnunciarMassa.jsx porque a grade (GradeAnuncioGlide.jsx) também
// precisa deles: se ficassem na página, a grade importaria da página e a página
// importaria da grade — ciclo de import. Módulo puro, sem React, sem I/O.
//
// REGRA (SHEET2-07): estas funções são a barreira de validação que impede anúncio
// inválido de ir pro Mercado Livre. Foram movidas de AnunciarMassa.jsx sem NENHUMA
// mudança de comportamento — mesma implementação, mesma ordem de checagem.
// ═══════════════════════════════════════════════════════════════════════
// ─── Gera um EAN-13 válido (padrão Mercado Livre) — copiado de AnunciarML.jsx:327 ───
// Prefixo 789 (Brasil) + 9 dígitos aleatórios + dígito verificador (Módulo 10).
export function gerarEan13(existentes = new Set()) {
    const digitoVerificador = (base12) => {
        let soma = 0;
        for (let i = 0; i < 12; i++) {
            const mult = ((i + 1) % 2 === 0) ? 3 : 1; // par ×3, ímpar ×1
            soma += Number(base12[i]) * mult;
        }
        const resto = soma % 10;
        return resto === 0 ? 0 : 10 - resto;
    };
    for (let t = 0; t < 50; t++) {
        let base = '789';
        for (let i = 0; i < 9; i++) base += Math.floor(Math.random() * 10);
        const ean = base + digitoVerificador(base);
        if (!existentes.has(ean)) return ean;
    }
    let base = '789';
    for (let i = 0; i < 9; i++) base += Math.floor(Math.random() * 10);
    return base + digitoVerificador(base);
}

// ─── Nome curto do caminho da categoria (última folha do breadcrumb) ───
export const nomeCurto = (caminho, categoryId) => {
    if (Array.isArray(caminho) && caminho.length) return caminho[caminho.length - 1];
    return categoryId ?? 'Sem categoria';
};

// ─── Ids de atributo que representam Marca e Modelo no ML ───
// Obrigatórios locais além de título/preço/estoque (espelha AnunciarML.jsx L1102-1106).
export const ATTR_MARCA = 'BRAND';
export const ATTR_MODELO = 'MODEL';

// ═══════════════════════════════════════════════════════════════════════
// SHEET-05: erro LOCAL bloqueante de uma linha (derivado no front, síncrono).
// É a ÚNICA barreira dura da grade — avisos do /items/validate NÃO entram aqui.
// Retorna a lista de campos obrigatórios faltando (vazia = linha publicável).
// Marca/Modelo só são exigidos quando a categoria da aba os traz como obrigatórios
// (algumas categorias não pedem MODEL); título/preço/estoque valem sempre.
// ═══════════════════════════════════════════════════════════════════════
export function errosLocaisLinha(l, aba) {
    const faltando = [];
    if (!String(l.title ?? '').trim()) faltando.push('título');
    if (!(Number(l.price) > 0)) faltando.push('preço');
    if (!(Number(l.estoque) > 0)) faltando.push('estoque');

    // Marca/Modelo: só cobra se a categoria ativa os declara obrigatórios
    const obrigIds = new Set((aba?.obrigatorios ?? []).map((o) => String(o.id)));
    if (obrigIds.has(ATTR_MARCA) && !String(l.attrs?.[ATTR_MARCA] ?? '').trim()) faltando.push('marca');
    if (obrigIds.has(ATTR_MODELO) && !String(l.attrs?.[ATTR_MODELO] ?? '').trim()) faltando.push('modelo');

    return faltando;
}

// Linha é publicável quando: já foi salva (tem id) E não tem erro local bloqueante.
export const linhaPublicavel = (l, aba) => !!l.id && errosLocaisLinha(l, aba).length === 0;

// Remove acentos + trim + lowercase (p/ casar "Clássico" com "classico" etc.)
export const semAcento = (s) =>
    String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();

// "Clássico"/"Classico" → gold_special ; "Premium" → gold_pro ; senão null (mantém célula)
export function normalizarTipoAnuncio(texto) {
    const t = semAcento(texto);
    if (!t) return null;
    if (t.startsWith('clas')) return 'gold_special';
    if (t.startsWith('prem')) return 'gold_pro';
    return null; // não reconhecido → deixa intacto + aviso
}

// "10x20x30" (ou 10×20×30 / 10*20*30) → { alturaCm, larguraCm, comprimentoCm }
export function parseDimensoes(texto) {
    const partes = String(texto ?? '').split(/[x×*]/i).map((p) => p.trim()).filter(Boolean);
    if (partes.length < 2) return null;
    const [a, l, c] = partes;
    return {
        alturaCm: a ?? '',
        larguraCm: l ?? '',
        comprimentoCm: c ?? '',
    };
}

// Casa o texto colado com values[].name (case-insensitive+trim) → value_id;
// se nenhum casar, devolve o texto cru (value_name livre — o backend aceita).
export function casarValueList(texto, values) {
    const alvo = semAcento(texto);
    if (!alvo) return '';
    const match = (values ?? []).find((v) => semAcento(v.name) === alvo);
    return match ? (match.name ?? texto) : texto; // grava o name (a grade guarda name; payload manda value_name)
}

// ─── Linha vazia (estrutura em memória de uma linha da grade) ───
// campos base + atributos por id (attrs) + meta de origem + estado de persistência.
export function linhaVazia() {
    return {
        uid: Math.random().toString(36).slice(2),
        id: null,            // id do ml_anuncio_rascunho (null enquanto não salvo)
        title: '',
        tier: 'gold_special', // Clássico; gold_pro = Premium
        price: '',
        estoque: '',
        sku: '',
        gtin: '',
        pesoG: '',
        alturaCm: '',
        larguraCm: '',
        comprimentoCm: '',
        descricao: '',
        attrs: {},           // { [attrId]: value } — ficha técnica da categoria
        origem: {},          // { [campo]: 'cliente' | 'publicador' }
        salvando: false,
        salvo: false,
    };
}
