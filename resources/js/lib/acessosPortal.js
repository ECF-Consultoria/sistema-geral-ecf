export function contatoDoAlvo(alvo, empresas) {
    const empresa = alvo?.startsWith('e:')
        ? empresas.find((e) => String(e.id) === alvo.slice(2)) : null;
    return {
        nome: empresa?.contato?.nome ?? '',
        email: empresa?.contato?.email ?? '',
        telefone: empresa?.contato?.telefone ?? '',
        cargo: empresa?.contato?.cargo ?? '',
    };
}

export function acessoComEmail(email, usuarios) {
    const normalizado = email.trim().toLowerCase();
    return normalizado ? usuarios.find((u) => u.email.trim().toLowerCase() === normalizado) : undefined;
}

/**
 * O atalho `?portal_company=<id>` vale para UMA abertura do modal.
 *
 * Devolve o id pedido e a URL já sem o parâmetro, para quem chama trocar a URL
 * do navegador por ela. Sem isso, voltar à sub-aba — que remonta o componente —
 * ou recarregar a página reabria o modal com a mesma empresa, indefinidamente.
 */
export function consumirEmpresaDaUrl(href) {
    const url = new URL(href);
    const id = url.searchParams.get('portal_company');
    url.searchParams.delete('portal_company');

    return { id, url: url.pathname + url.search + url.hash, mudou: id !== null };
}
