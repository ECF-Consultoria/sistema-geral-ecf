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

// ═══════════════════════════════════════════════════════════════════════
// Linha é publicável quando TUDO isto vale:
//   - já foi salva (tem id);
//   - a aba tem categoria — `montarPayloadLinha` manda `category_id: aba.category_id`,
//     e POST /items sem categoria é 400 garantido no ML. Isso é pré-existente e
//     discreto (a aba "Sem categoria" só recebia rascunho antigo), mas "Remover
//     categoria" passa a jogar linhas boas lá em lote: sem esta guarda, remover uma
//     categoria com 20 linhas faria a PublishBar oferecer "Publicar 20" = 20 erros 400;
//   - não tem erro local bloqueante;
//   - não está em voo (`publicando`) — não redisparar job já enfileirado;
//   - não foi publicada — já foi, sai da contagem.
// Linha com ERRO continua publicável de propósito: corrigir o campo e republicar é
// o caminho de recuperação, e bloquear isso deixaria o publicador sem saída.
//
// A categoria entra AQUI e não em `errosLocaisLinha` de propósito: linha sem categoria
// não é linha errada (pode estar só esperando classificação), e `errosLocaisLinha` é
// compartilhada com o realce da grade — mexer nela pintaria essas linhas de vermelho.
// ═══════════════════════════════════════════════════════════════════════
export const linhaPublicavel = (l, aba) =>
    !!l.id
    && !!aba?.category_id
    && !l.publicando
    && l.statusServidor !== 'publicado'
    && errosLocaisLinha(l, aba).length === 0;

// ═══════════════════════════════════════════════════════════════════════
// Preço no padrão BR: aceita "129,99" e "129.99" (FIX-83-5).
//
// A coerção mora AQUI (chamada pelo onCellsEdited, o ponto único de escrita) e
// NÃO em `montarPayloadLinha`, por dois motivos:
//   1. o autosave chama `montarPayloadLinha` a cada 600ms — corrigir só na saída
//      faria o banco persistir `price: null` (Number('129,99') = NaN) em silêncio,
//      muito antes de qualquer tentativa de publicar;
//   2. `errosLocaisLinha` faz `Number(l.price) > 0` — uma vírgula no estado marcaria
//      a linha como "falta preço" com o campo visivelmente preenchido na tela.
//
// Regra: sem vírgula, devolve como está (não inventar parsing — "1234.56" é decimal).
// Com vírgula, o ponto é separador de milhar do padrão BR: "1.234,56" → "1234.56".
// ═══════════════════════════════════════════════════════════════════════
export function normalizarPreco(texto) {
    const t = String(texto ?? '').trim();
    if (!t) return '';
    if (!t.includes(',')) return t;
    return t.replace(/\./g, '').replace(',', '.');
}

// ═══════════════════════════════════════════════════════════════════════
// Merge do estado do SERVIDOR (prop `rascunhos`) de volta para as linhas das abas.
//
// É a peça central da correção do lote: a página lê `rascunhos` uma vez só, num
// useEffect de dependência vazia. Sem este merge, o polling recarrega a prop e a
// grade (que renderiza `abas`) não vê absolutamente nada — que é o bug de hoje.
//
// DOIS FATOS NÃO ÓBVIOS:
//
// 1. `massa()` NÃO devolve rascunho publicado — o whereIn do controller só traz
//    [rascunho, validado, erro, publicando]. Então "sumiu da prop ENQUANTO
//    publicava" é a ÚNICA evidência de sucesso que chega ao frontend. Sem essa
//    inferência, toda linha publicada com sucesso ficaria em "publicando" para
//    sempre — recriando o mesmo bug por outro caminho.
//    Cuidado: só inferir sucesso quando a linha ESTAVA publicando. Uma linha
//    recém-criada que a prop ainda não conhece também "não está lá", e marcá-la
//    como publicada apagaria o trabalho do usuário.
//
// 2. O merge só toca os 4 campos do servidor — NUNCA os campos de edição. A lista
//    é literal e fechada de propósito (nada de spread de `r`): é o que impede um
//    reload de 3s em 3s de sobrescrever o título que o publicador está digitando.
//
// Devolve a MESMA referência quando nada muda (em cada nível: linha, aba e array).
// Isso não é micro-otimização: o efeito roda a cada 3s durante a publicação, e o
// React só evita o re-render se a referência voltar igual — senão o canvas inteiro
// redesenha e o cursor de edição pisca.
//
// Os status são strings literais, sincronizadas à mão com MlAnuncioRascunho::STATUS_*
// (convenção do projeto: sem enum compartilhado entre PHP e JS).
// ═══════════════════════════════════════════════════════════════════════
export function mesclarStatusRascunhos(abas, rascunhos) {
    const porId = new Map((rascunhos ?? []).map((r) => [r.id, r]));
    let mudouAlgo = false;

    const novas = (abas ?? []).map((aba) => {
        let mudouAba = false;

        const linhas = (aba.linhas ?? []).map((l) => {
            // Linha ainda não persistida: a prop não sabe dela.
            if (!l.id) return l;

            const r = porId.get(l.id);
            let alvo;

            if (r) {
                alvo = {
                    statusServidor: r.status ?? null,
                    erroResumo: r.erro_resumo ?? null,
                    erroCompleto: r.erro_completo ?? null,
                    publicando: r.status === 'publicando',
                };
            } else if (l.publicando === true) {
                // Sumiu da prop enquanto publicava == publicou (ver fato 1 acima)
                alvo = {
                    statusServidor: 'publicado',
                    erroResumo: null,
                    erroCompleto: null,
                    publicando: false,
                };
            } else {
                // Linha recém-criada que a prop ainda não conhece — nunca inferir nada
                return l;
            }

            const igual = l.statusServidor === alvo.statusServidor
                && l.erroResumo === alvo.erroResumo
                && l.erroCompleto === alvo.erroCompleto
                && l.publicando === alvo.publicando;
            if (igual) return l;

            mudouAba = true;
            return { ...l, ...alvo };
        });

        if (!mudouAba) return aba;
        mudouAlgo = true;
        return { ...aba, linhas };
    });

    return mudouAlgo ? novas : abas;
}

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
        // ─── Estado vindo do SERVIDOR (preenchido pelo merge do polling) ───
        // Existem sempre (e não ad-hoc) porque o merge compara valor com valor:
        // com `undefined` no meio, "não mudou" e "não sei" viram a mesma coisa.
        publicando: false,   // job em voo na fila
        statusServidor: null, // 'rascunho' | 'validado' | 'publicando' | 'publicado' | 'erro'
        erroResumo: null,    // mensagem curta pt-BR do backend
        erroCompleto: null,  // resposta crua da API do ML (para o "ver detalhes")
    };
}
