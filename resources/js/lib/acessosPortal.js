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
