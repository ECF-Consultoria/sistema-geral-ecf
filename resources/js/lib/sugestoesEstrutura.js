// ═══════════════════════════════════════════════════════════════════════
// Rótulos e textos da tela "Sugestões de ofertas" (Fase 168) — SÓ formatação.
// Os textos seguem o contrato de copy da UI-SPEC (sem exclamação). Os limites
// (título, código, lote) vêm do servidor, por isso as mensagens que os citam são
// funções.
// ═══════════════════════════════════════════════════════════════════════

export const ROTULO_FASE = { combo: 'Combo', kit: 'Kit', combit: 'Combit' };

export const MSG_SKU_REPETIDO = 'Já existe uma oferta com este código na Lista SKUs. Você pode aceitar assim mesmo.';
export const MSG_FALHA_REDE = 'Não foi possível salvar agora. Suas edições continuam na tela. Tente de novo.';
export const MSG_GUARDA = 'Há nomes ou códigos editados que ainda não foram aceitos. Sair sem aceitar?';

export const msgLimiteDoLote = (limite) => `Você pode aceitar até ${limite} de uma vez. Aceite estas e marque as próximas.`;
export const msgMarcamosPrimeiras = (limite) => `Marcamos as primeiras ${limite}. Aceite e marque as próximas.`;
export const msgSkuLongo = (max) => `O código passa de ${max} caracteres. Encurte para poder aceitar.`;
export const textoTituloLongo = (n, max) => `O título passa de ${max} caracteres (${n}). O Mercado Livre pode cortar.`;

export const textoMarcadas = (n) => `${n} ${n === 1 ? 'marcada' : 'marcadas'}`;
export const rotuloAceitarMarcadas = (n) => `Aceitar ${textoMarcadas(n)}`;

/** Resultado do aceite: criadas, já existiam e erros, cada parte só quando há. */
export function textoResultadoAceite(resultado) {
    const criadas = resultado?.criadas?.length ?? 0;
    const existiam = resultado?.ja_existiam?.length ?? 0;
    const erros = resultado?.erros?.length ?? 0;
    const partes = [];

    if (criadas > 0) {
        partes.push(criadas === 1
            ? '1 oferta criada. Ela já está na Lista SKUs.'
            : `${criadas} ofertas criadas. Elas já estão na Lista SKUs.`);
    }
    if (existiam > 0) {
        partes.push(existiam === 1
            ? '1 já existia e saiu da lista.'
            : `${existiam} já existiam e saíram da lista.`);
    }
    if (erros > 0) {
        partes.push(erros === 1
            ? '1 não pôde ser criada. Veja o cartão que continua marcado.'
            : `${erros} não puderam ser criadas. Veja os cartões que continuam marcados.`);
    }

    return partes.join(' ');
}

export const textoDescarte = (n) => (n === 1 ? 'Sugestão descartada.' : `${n} sugestões descartadas.`);

/** Restauração: `jaExistem` são as que não voltaram porque a composição já virou oferta. */
export function textoRestauracao(n, jaExistem) {
    const partes = [];
    if (n > 0) partes.push(n === 1 ? '1 sugestão restaurada.' : `${n} sugestões restauradas.`);

    if (jaExistem > 0) {
        if (n > 0) {
            partes.push(jaExistem === 1
                ? '1 não voltou: já existe uma oferta com esta composição.'
                : `${jaExistem} não voltaram: já existem ofertas com estas composições.`);
        } else {
            partes.push(jaExistem === 1
                ? 'Já existe uma oferta com esta composição.'
                : 'Já existem ofertas com estas composições.');
        }
    }

    return partes.join(' ');
}

/** "1 × Mesa + 4 × Cadeira" */
export const composicaoEmLinha = (itens) => (itens ?? []).map((i) => `${i.quantidade} × ${i.produto_nome}`).join(' + ');

/**
 * Qual estado vazio a tela desenha (UI-SPEC "Estados da tela"). `null` = há sugestões na página.
 * Só escolhe o texto: as contagens vêm do servidor.
 *
 * @returns {'sem_produtos'|'filtro_vazio'|'tudo_revisado'|'sem_sugestoes'|null}
 */
export function qualEstadoVazio({ temProdutos, contagens, filtroAtivo, aceitouNaSessao = false, qtdItens = 0 }) {
    if (qtdItens > 0) return null;
    if (! temProdutos) return 'sem_produtos';

    const vigentes = contagens?.sugestoes ?? 0;
    if (filtroAtivo && vigentes > 0) return 'filtro_vazio';
    if (vigentes > 0) return null;
    if ((contagens?.descartadas ?? 0) > 0 || aceitouNaSessao) return 'tudo_revisado';

    return 'sem_sugestoes';
}

// ─── Aba Sem tipo e janela de tipo (Fase 168-15) ────────────────────────────

export const textoTipoDefinido = (nome) => `Tipo definido: ${nome}. As sugestões de Kit e Combit foram atualizadas.`;

/** "Pode ser Banco ou Banqueta." (3 ou mais: "Pode ser A, B ou C."). Sem candidatos: null. */
export function textoPodeSer(candidatos) {
    const nomes = (candidatos ?? []).map((c) => c.nome).filter(Boolean);
    if (nomes.length === 0) return null;
    if (nomes.length === 1) return `Pode ser ${nomes[0]}.`;

    return `Pode ser ${nomes.slice(0, -1).join(', ')} ou ${nomes[nomes.length - 1]}.`;
}

/**
 * Opções do select de tipo: os candidatos da inferência primeiro (na ordem recebida),
 * depois os demais tipos na ordem de `tipos`, sem repetir. Candidato que não é tipo da empresa some.
 *
 * @returns {{valor: string, rotulo: string}[]}
 */
export function opcoesDeTipo(tipos, candidatos) {
    const porSlug = new Map((tipos ?? []).map((t) => [t.slug, t]));
    const vistos = new Set();
    const saida = [];
    const incluir = (t) => {
        if (! t || vistos.has(t.slug)) return;
        vistos.add(t.slug);
        saida.push({ valor: t.slug, rotulo: t.nome });
    };

    for (const c of candidatos ?? []) incluir(porSlug.get(c.slug));
    for (const t of tipos ?? []) incluir(t);

    return saida;
}

/**
 * Corpo do PUT de tipo e quantidades. Texto vazio vira null (herda o padrão do tipo);
 * '0' passa como '0' (não gera): nunca confundir os dois (T-168-49).
 *
 * @returns {{tipo_id: number|null, qtd_combo: string|null, qtd_combit: string|null}}
 */
export function corpoDaGeracao({ tipo, qtdCombo, qtdCombit }, tipos) {
    const achado = (tipos ?? []).find((t) => t.slug === tipo);
    const texto = (v) => {
        const limpo = String(v ?? '').trim();

        return limpo === '' ? null : limpo;
    };

    return { tipo_id: achado ? achado.id : null, qtd_combo: texto(qtdCombo), qtd_combit: texto(qtdCombit) };
}
