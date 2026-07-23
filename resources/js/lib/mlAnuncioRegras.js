// ═══════════════════════════════════════════════════════════════════════
// Regras de publicação do Mercado Livre — funções PURAS (sem React).
//
// Concentra o "guard-rail" do anúncio (o que impede/prejudica a publicação) e
// a análise de saúde/qualidade que o wizard exibe ao publicador. Fica separado
// do AnunciarML.jsx para: (1) não inchar o componente, (2) ser testável via
// node --test (tests/js/mlAnuncioRegras.test.js).
//
// Filosofia (herdada da Fase 85 e do comportamento do POST /items):
//   • `erros`  = o ML REJEITA com certeza → bloqueiam a publicação.
//   • `avisos` = não bloqueiam, mas pioram qualidade/exposição/ranqueamento
//                (ou são falso-positivos frequentes, ex.: frete). Só orientam.
//
// Fontes (pesquisa 2026-07-23, docs + API pública api.mercadolibre.com):
//   • settings de GET /categories/{id}: minimum_price/maximum_price,
//     max_pictures_per_item (12), max_pictures_per_item_var (10),
//     max_description_length (50000), listing_allowed, status, item_conditions.
//   • Imagens: mínimo prático = 1 (POST /items rejeita pictures vazio).
//   • Frete grátis obrigatório (ME2) a partir de ~R$79 (mandatory_free_shipping).
//   • Nota fiscal NÃO entra na publicação — é pós-venda (orders/packs/shipments).
// ═══════════════════════════════════════════════════════════════════════

// ─── Limites do Mercado Envios (pacote embalado) ───
// maiorLado/soma/peso: reusa o limiar canônico do Onboarding ("Precisa de ME1"),
// validado pelo usuário em produção — acima disso o item normalmente NÃO cabe no
// Mercado Envios padrão e exige ME1/logística própria. ladoMin: 3 cm por lado é o
// mínimo que o POST /items aceita (abaixo disso a publicação falha).
export const LIMITES_MERCADO_ENVIOS = {
    pesoMaxG:       50000,  // 50 kg
    maiorLadoMaxCm: 200,    // 2,0 m
    somaMaxCm:      300,    // A+L+C
    ladoMinCm:      3,      // mínimo por lado (regra do publish)
};

// ─── Quantidade de fotos recomendada (qualidade/exposição) ───
export const FOTOS_RECOMENDADAS_MIN = 3;   // abaixo disso, aviso de qualidade
export const FOTOS_MAX_PADRAO       = 12;  // fallback quando settings não trouxer

// ─── Frete grátis obrigatório (ME2) ───
export const PRECO_FRETE_GRATIS_OBRIGATORIO = 79; // R$ — a partir daqui o ME2 exige frete grátis

// ─── Modalidades de frete que o vendedor DEFINE na publicação ───
// Flex (self_service) e Full (fulfillment) NÃO entram aqui: o logistic_type é
// derivado da habilitação da conta no ML, não do payload de publicação.
export const MODALIDADES_FRETE = [
    { v: 'me2',           l: 'Mercado Envios (recomendado)', dica: 'ML calcula o frete e gera a etiqueta. Elegível a Flex/Full conforme a conta.' },
    { v: 'me1',           l: 'Logística própria (ME1)',       dica: 'Você organiza o envio. Exige ME1 habilitado na conta (com ME2 ativo antes).' },
    { v: 'not_specified', l: 'Combinar com o comprador',      dica: 'Sem cálculo automático de frete — o comprador combina o envio com você.' },
];

// ─── Dicas de boas práticas para a descrição ───
export const DICAS_DESCRICAO = [
    'Texto puro, sem HTML — quebre em parágrafos curtos.',
    'Sem telefone, e-mail, WhatsApp ou links externos (o ML modera e pode pausar o anúncio).',
    'Comece pelos benefícios, depois medidas, materiais e conteúdo da embalagem.',
    'Evite promessas de "frete grátis"/"melhor preço" no texto — isso vai nas configurações, não na descrição.',
];

// ─── Exposição e parcelamento por tipo de anúncio ───
// Texto informativo exibido ao lado do seletor Clássico/Premium (etapa 3).
export const INFO_EXPOSICAO_TIER = {
    gold_special: {
        nome:         'Clássico',
        exposicao:    'Exposição padrão nas buscas.',
        parcelamento: 'Parcelamento sem juros custeado pelo ML nas condições vigentes.',
        comissao:     'Comissão menor que o Premium.',
    },
    gold_pro: {
        nome:         'Premium',
        exposicao:    'Maior exposição nas buscas e destaque.',
        parcelamento: 'Parcelamento sem juros ampliado — forte apelo de conversão.',
        comissao:     'Comissão maior que o Clássico (custeia a exposição).',
    },
};

/** Peso volumétrico em kg (C×L×A / 6000). Retorna null se faltar dimensão. */
export function pesoVolumetricoKg(comprimentoCm, larguraCm, alturaCm) {
    const c = Number(comprimentoCm), l = Number(larguraCm), a = Number(alturaCm);
    if (!c || !l || !a) return null;
    return (c * l * a) / 6000;
}

/**
 * Checa o pacote embalado contra os limites do Mercado Envios.
 * @returns {{excedeME: boolean, ladoMinInvalido: boolean, motivos: string[]}}
 */
export function checarDimensoesPacote({ pesoG, comprimentoCm, larguraCm, alturaCm }) {
    const peso  = Number(pesoG) || 0;
    const dims  = [comprimentoCm, larguraCm, alturaCm].map(v => Number(v) || 0);
    const soma  = dims.reduce((s, v) => s + v, 0);
    const maior = Math.max(...dims);
    const motivos = [];

    if (peso > LIMITES_MERCADO_ENVIOS.pesoMaxG) {
        motivos.push(`peso ${(peso / 1000).toFixed(1)} kg acima de ${LIMITES_MERCADO_ENVIOS.pesoMaxG / 1000} kg`);
    }
    if (maior > LIMITES_MERCADO_ENVIOS.maiorLadoMaxCm) {
        motivos.push(`maior lado ${maior} cm acima de ${LIMITES_MERCADO_ENVIOS.maiorLadoMaxCm} cm`);
    }
    if (soma > LIMITES_MERCADO_ENVIOS.somaMaxCm) {
        motivos.push(`soma das medidas ${soma} cm acima de ${LIMITES_MERCADO_ENVIOS.somaMaxCm} cm`);
    }

    // Lado mínimo (3 cm): só considera lados efetivamente preenchidos (> 0)
    const ladoMinInvalido = dims.some(v => v > 0 && v < LIMITES_MERCADO_ENVIOS.ladoMinCm);

    return { excedeME: motivos.length > 0, ladoMinInvalido, motivos };
}

/**
 * Normaliza o objeto `settings` da categoria (GET /categories/{id}).
 * Tolera categoria null (etapa antes de escolher categoria).
 */
export function extrairSettingsCategoria(categoria) {
    const s = categoria?.settings ?? {};
    const num = (v) => (typeof v === 'number' && !Number.isNaN(v) ? v : null);

    return {
        precoMin:       num(s.minimum_price),                 // 0/8/null — só bloqueia se > 0
        precoMax:       num(s.maximum_price),                 // quase sempre null
        maxFotos:       num(s.max_pictures_per_item) ?? FOTOS_MAX_PADRAO,
        maxFotosVar:    num(s.max_pictures_per_item_var) ?? 10,
        maxDescricao:   num(s.max_description_length) ?? 50000,
        maxTitulo:      num(s.max_title_length) ?? 60,
        listingAllowed: s.listing_allowed !== false,          // default permissivo (só bloqueia se explicitamente false)
        statusOk:       s.status === undefined || s.status === 'enabled',
        itemConditions: Array.isArray(s.item_conditions) ? s.item_conditions : null,
    };
}

/**
 * Conta a completude da ficha técnica (obrigatórios + opcionais preenchidos).
 * `preenchido(attr)` recebe a definição do atributo e devolve bool.
 */
export function completudeFicha(obrigatorios, opcionais, preenchido) {
    const cont = (lista) => (lista || []).filter(a => preenchido(a)).length;
    const obrTotal = (obrigatorios || []).length;
    const obrOk    = cont(obrigatorios);
    const opcTotal = (opcionais || []).length;
    const opcOk    = cont(opcionais);
    return {
        obrTotal, obrOk,
        opcTotal, opcOk,
        obrCompleta: obrTotal === 0 || obrOk === obrTotal,
        // % dos opcionais (o obrigatório é gate, não escala): usado no medidor
        opcPct: opcTotal === 0 ? 100 : Math.round((opcOk / opcTotal) * 100),
    };
}

/**
 * Análise de saúde/qualidade do anúncio + guard-rail de publicação.
 *
 * @param {object} p
 * @param {string} p.titulo
 * @param {object|null} p.categoria      settings da categoria (GET /categories/{id})
 * @param {string} p.categoryId
 * @param {number|string} p.preco
 * @param {number|string} p.estoque
 * @param {string} p.condicao            'new' | 'used' | 'not_specified'
 * @param {string} p.tipoAnuncio         'gold_special' | 'gold_pro'
 * @param {string} p.imagemUrl           URL da imagem principal (anúncio sem variação)
 * @param {string} p.descricao
 * @param {boolean} p.temVariacoes
 * @param {Array}  p.variacoes
 * @param {Array}  p.obrigatorios        atributos obrigatórios da categoria
 * @param {Array}  p.opcionais           atributos opcionais (características secundárias)
 * @param {(attr)=>boolean} p.preenchido predicado de "atributo preenchido"
 * @param {string|number} p.pesoG,p.comprimentoCm,p.larguraCm,p.alturaCm
 * @param {string} p.shippingMode        'me2' | 'me1' | 'not_specified'
 * @param {boolean} p.freteGratis
 * @returns {{erros: Array, avisos: Array, ficha: object, score: number}}
 *   erros/avisos: [{ campo, mensagem }]
 */
export function analisarAnuncio(p) {
    const erros = [];
    const avisos = [];
    const s = extrairSettingsCategoria(p.categoria);

    const temVars   = !!(p.temVariacoes && p.variacoes && p.variacoes.length > 0);
    const precoNum  = p.preco !== '' && p.preco != null ? Number(p.preco) : null;

    // ─── Ficha técnica ───
    const ficha = completudeFicha(p.obrigatorios, p.opcionais, p.preenchido);

    // ═══ ERROS (bloqueiam) ═══

    // Categoria que não aceita novos anúncios (raro, mas explícito no settings)
    if (p.categoryId && (!s.listingAllowed || !s.statusOk)) {
        erros.push({ campo: 'categoria', mensagem: 'Esta categoria não aceita novos anúncios — escolha uma categoria-folha ativa.' });
    }

    // Condição não suportada pela categoria
    if (p.categoryId && s.itemConditions && p.condicao && !s.itemConditions.includes(p.condicao)) {
        erros.push({ campo: 'condição', mensagem: 'Esta categoria não aceita a condição selecionada — troque novo/usado.' });
    }

    // Foto obrigatória (≥1). Com variações, cada variação valida a própria foto
    // (validarVariacoesLocalmente); aqui cobrimos o anúncio SEM variação.
    if (!temVars && !p.imagemUrl) {
        erros.push({ campo: 'imagem', mensagem: 'O anúncio precisa de ao menos 1 foto (o Mercado Livre recusa anúncio sem imagem — Premium sempre exige).' });
    }

    // Obrigatórios da ficha técnica incompletos → o ML devolve missing_required
    if (!ficha.obrCompleta) {
        const faltam = ficha.obrTotal - ficha.obrOk;
        erros.push({ campo: 'ficha técnica', mensagem: `Faltam ${faltam} atributo(s) obrigatório(s) da categoria — o Mercado Livre recusa sem eles.` });
    }

    // Preço abaixo do mínimo da categoria (só quando o settings traz piso numérico > 0)
    if (precoNum != null && s.precoMin && precoNum < s.precoMin) {
        erros.push({ campo: 'preço', mensagem: `Preço abaixo do mínimo desta categoria (R$ ${s.precoMin.toFixed(2)}).` });
    }
    // Preço acima do máximo (raro — settings quase sempre null)
    if (precoNum != null && s.precoMax && precoNum > s.precoMax) {
        erros.push({ campo: 'preço', mensagem: `Preço acima do máximo desta categoria (R$ ${s.precoMax.toFixed(2)}).` });
    }

    // Descrição além do teto técnico
    if (p.descricao && p.descricao.length > s.maxDescricao) {
        erros.push({ campo: 'descrição', mensagem: `Descrição excede o limite de ${s.maxDescricao.toLocaleString('pt-BR')} caracteres.` });
    }

    // ═══ AVISOS (não bloqueiam) ═══

    // Dimensões vs Mercado Envios
    const dim = checarDimensoesPacote(p);
    if (dim.excedeME) {
        avisos.push({ campo: 'dimensões', mensagem: `Pacote grande (${dim.motivos.join('; ')}) — pode exceder o Mercado Envios e exigir ME1/logística própria.` });
    }
    if (dim.ladoMinInvalido) {
        avisos.push({ campo: 'dimensões', mensagem: `Cada lado do pacote precisa de pelo menos ${LIMITES_MERCADO_ENVIOS.ladoMinCm} cm — a publicação pode falhar.` });
    }

    // Poucas fotos (qualidade/exposição) — só anúncio sem variação
    if (!temVars && p.imagemUrl) {
        // Nesta tela o anúncio simples tem 1 imagem principal (URL). Reforça a boa prática de mais fotos.
        avisos.push({ campo: 'fotos', mensagem: `Anúncios com ${FOTOS_RECOMENDADAS_MIN}+ fotos convertem melhor e ranqueiam melhor. Você pode enviar até ${s.maxFotos}.` });
    }

    // Ficha técnica opcional incompleta prejudica exposição
    if (ficha.obrCompleta && ficha.opcTotal > 0 && ficha.opcOk < ficha.opcTotal) {
        avisos.push({ campo: 'ficha técnica', mensagem: `Ficha técnica ${ficha.opcPct}% preenchida — quanto mais completa, melhor o ranqueamento e a exibição nas buscas.` });
    }

    // Descrição vazia ou curta
    if (!p.descricao || p.descricao.trim().length < 50) {
        avisos.push({ campo: 'descrição', mensagem: 'Descrição vazia ou muito curta — descreva benefícios, medidas e conteúdo da embalagem.' });
    }

    // Frete grátis obrigatório (ME2) a partir de R$79
    if (p.shippingMode === 'me2' && precoNum != null && precoNum >= PRECO_FRETE_GRATIS_OBRIGATORIO && !p.freteGratis) {
        avisos.push({ campo: 'frete', mensagem: `Acima de R$ ${PRECO_FRETE_GRATIS_OBRIGATORIO} o Mercado Envios costuma exigir frete grátis — ative para evitar recusa/pausa.` });
    }

    return { erros, avisos, ficha, score: calcularScore({ ...p, temVars, ficha, precoNum, settings: s }) };
}

/**
 * Score de saúde 0–100 (heurístico) para o painel "Saúde do anúncio".
 * Soma de sinais ponderados: título, categoria, obrigatórios, opcionais, fotos,
 * descrição, dimensões, preço.
 */
function calcularScore({ titulo, categoryId, temVars, variacoes, imagemUrl, descricao, ficha, precoNum, pesoG, comprimentoCm, larguraCm, alturaCm }) {
    let pontos = 0;
    const add = (cond, peso) => { if (cond) pontos += peso; };

    add(!!titulo && titulo.trim().length >= 20, 12);              // título com substância
    add(!!categoryId, 12);                                         // categoria escolhida
    add(ficha.obrCompleta, 20);                                    // obrigatórios completos (gate forte)
    add(ficha.opcTotal === 0 || ficha.opcPct >= 60, 14);          // ficha técnica rica
    const temFoto = temVars ? (variacoes || []).some(v => (v.picture_ids || []).length) : !!imagemUrl;
    add(temFoto, 16);                                              // ao menos 1 foto
    add(!!descricao && descricao.trim().length >= 120, 14);       // descrição decente
    add(!!(pesoG && comprimentoCm && larguraCm && alturaCm), 8);  // dimensões completas (frete)
    add(precoNum != null && precoNum > 0, 4);                     // preço definido

    return Math.min(100, pontos);
}
