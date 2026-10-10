// ═══════════════════════════════════════════════════════════════════════
// Quais submódulos do Mapeamento Estrutural a pessoa vê (09/10/2026).
//
// Quem decide é o servidor (`VisibilidadeDoMapeamento`): a prop `modulos` de toda página do
// portal já chega só com os submódulos visíveis — o cliente vê Produtos, Planejamento,
// Precificação e Mapeamento; a equipe vê todos. Um escondido só aparece quando a pessoa está
// nele (`oculto: true`), e por isso não conta como visível aqui.
//
// Serve para os links internos não apontarem para uma tela que a pessoa não vê.
// ═══════════════════════════════════════════════════════════════════════

/** O submódulo `chave` do Mapeamento está no menu desta pessoa? */
export function submoduloVisivel(modulos, chave) {
    const subs = (modulos ?? []).find((m) => m.chave === 'estrutura')?.submodulos ?? [];

    return subs.some((s) => s.chave === chave && ! s.oculto);
}

/**
 * Para onde vai "ver a oferta criada": a Lista SKUs para quem a vê; senão a Precificação,
 * que é o passo seguinte (com a busca pelo SKU quando há um só).
 *
 * @returns {{ rotulo: string, href: string, ondeFica: string }}
 */
export function destinoDaOfertaCriada(modulos, sku = null, rota = (nome, params) => route(nome, params)) {
    if (submoduloVisivel(modulos, 'lista')) {
        return { rotulo: 'Ver na Lista SKUs', href: rota('portal.auth.estrutura.lista', sku ? { q: sku } : {}), ondeFica: 'na Lista SKUs' };
    }

    return { rotulo: 'Precificar agora', href: rota('portal.auth.estrutura.precificacao', sku ? { q: sku } : {}), ondeFica: 'na Precificação' };
}
