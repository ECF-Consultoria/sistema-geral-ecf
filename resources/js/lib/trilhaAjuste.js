// ─── Trilha de produtos — pedir ajuste ao agente pela prévia (4ª entrega, fatia 4b) ─
//
// As contas PURAS do ajuste na gaveta `v:{item}` (`Components/Trilha/Revisao/AjusteDoRobo.jsx`):
// a URL, quando o botão [Pedir ajuste ao agente] aparece (e quando fica desligado),
// a escolha "O que refazer?" (título e/ou descrição — nunca texto livre), o corpo
// do pedido, o texto de cada situação do ajuste e o resultado por parte, quando
// perguntar de novo, o que cada erro quer dizer e as travas que o ajuste põe no
// preço e no [Aprovar e publicar] enquanto anda.
//
// O contrato é o §19.14 do desenho (`.planning/trilha/ENTREGA-3-DESENHO.md`):
// - `POST {escrita.revisao.ajuste}` (com `__ITEM__` no lugar do id) com
//   `{revisao, refazer: ['titulo'|'descricao']}` → 202 `{ajuste}`;
// - `GET` na MESMA URL → `{ajuste}` ou `{ajuste: null}`;
// - `ajuste = {situacao: fila|fazendo|feito|erro, partes: {titulo?: {situacao:
//   refeito|mantido|erro|fila|fazendo, mensagem}, descricao?: {…}}, restantes_hoje, mensagem}`;
// - a prévia traz `ajuste` (o mesmo cálculo do GET) e `ajustes_restantes_hoje`;
// - 409 da revisão velha com `revisao_atual` (SEM `travado`, o padrão F6); os
//   outros 409 (fora da revisão, na fila/publicando/publicado, não pronta, ajuste
//   em andamento) só com `message`; 422 em `errors.refazer` (vazio, parte
//   desconhecida, o limite de 3 ajustes por anúncio por dia).
//
// Quem decide é o SERVIDOR: a tela só esconde o botão quando o pedido não teria
// como passar, e mostra a mensagem que ele devolver.
//
// Mora no pedaço que SÓ a equipe baixa (o `lazy` da gaveta) e não importa nada:
// as libs `trilha*` são varridas como "de fora" da pasta `Revisao/` (as libs da
// prévia, do preço e da publicação não podem ser importadas por elas). Por isso
// o marcador, a régua da mensagem do framework e a lista das situações da
// publicação que travam são CÓPIAS — o teste confere que são iguais às de lá.
//
// Sem React, sem axios e sem classe do Tailwind (arquivo `.js`). Os textos não
// citam o marketplace nem o Portal (D59); quem refaz é "o agente" (R9, 04/11/2026: "robô" virou "agente").

/** O marcador do id do item nas URLs da revisão (§19.4) — o mesmo `MARCADOR_DO_ITEM` da prévia. */
export const MARCADOR_DO_ITEM = '__ITEM__';

/** As partes que o agente refaz, na ordem do contrato (e do corpo do pedido). Nada além delas: sem texto livre. */
export const PARTES = Object.freeze(['titulo', 'descricao']);

/** As situações do ajuste (§19.14). */
export const SITUACOES = Object.freeze(['fila', 'fazendo', 'feito', 'erro']);

/** As situações em que o ajuste ainda anda: a gaveta pergunta de novo, e o preço e o publicar ficam desligados. */
export const EM_ANDAMENTO = Object.freeze(['fila', 'fazendo']);

/** As situações de cada parte (§19.14). */
export const SITUACOES_DA_PARTE = Object.freeze(['refeito', 'mantido', 'erro', 'fila', 'fazendo']);

/** As situações da parte que já terminou (viram uma linha do resultado). */
const PARTE_TERMINADA = ['refeito', 'mantido', 'erro'];

/**
 * As situações da PUBLICAÇÃO em que o pedido de ajuste não passaria (o 409 "na fila/publicando/publicado") — e a
 * conferência, que perderia a validade com a revisão nova. Cópia de `EM_ANDAMENTO` + `publicado` de
 * `lib/trilhaPublicar.js` (esta lib não a importa); o teste confere que são as mesmas.
 */
export const PUBLICACAO_QUE_TRAVA = Object.freeze(['conferindo', 'na_fila', 'publicando', 'publicado']);

/** De quanto em quanto tempo a gaveta pergunta enquanto o ajuste anda (o padrão da 4d: a cada 3 s). */
export const INTERVALO_DA_CONSULTA_MS = 3000;

/** O teto de perguntas seguidas (30 min a cada 3 s): a gaveta esquecida aberta não pergunta para sempre. */
export const MAX_CONSULTAS = 600;

export const ROTULO_DO_PEDIR = 'Pedir ajuste ao agente';
export const PERGUNTA_DA_ESCOLHA = 'O que refazer?';
export const ROTULO_DO_REFAZER = 'Refazer';
export const ROTULO_ENVIANDO = 'Enviando…';

/** O botão desligado no limite do dia (3 ajustes por anúncio, §19.12). */
export const DICA_DO_LIMITE = 'Limite de ajustes de hoje atingido.';

/** O botão desligado com preço digitado e não salvo: o ajuste muda a revisão e o preço digitado se perderia. */
export const DICA_DO_PRECO_PENDENTE = 'Salve ou descarte o preço digitado antes de pedir ajuste.';

/** O [Refazer] desligado enquanto nada foi escolhido (pelo menos uma parte). */
export const DICA_DA_ESCOLHA = 'Escolha o título, a descrição ou os dois.';

/** O 409 da revisão sem mensagem própria (o mesmo texto da caixa do preço e da publicação). */
export const MSG_MUDOU = 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.';

/** Os outros 409 sem mensagem própria. */
export const MSG_CONFLITO = 'O agente não pode refazer este anúncio agora. Recarregue a página para ver onde ele está.';

/** O 422 sem mensagem que sirva. */
export const MSG_CONFIRA = 'Confira o que pediu ao agente e tente de novo.';

/** O 403 sem mensagem própria. */
export const MSG_PROIBIDO = 'Você não tem permissão para pedir ajuste neste anúncio.';

/** Passou do teto de perguntas: o ajuste segue no servidor, a gaveta só para de olhar. */
export const MSG_PAROU = 'Parei de acompanhar por aqui. O agente continua o ajuste mesmo com a tela fechada.';

/** Os avisos da página quando a gaveta vê o ajuste terminar. */
export const AVISO_TERMINOU = 'O agente terminou o ajuste.';
export const AVISO_FALHOU = 'O ajuste do agente não deu certo.';

/** A caixa "Preço" só leitura enquanto o agente refaz (o pedido sobe a revisão: o preço salvo no meio levaria 409). */
export const TRAVA_DO_PRECO = 'O agente está refazendo o anúncio: o preço fica só para leitura até ele terminar.';

/** O [Aprovar e publicar] desligado enquanto o agente refaz (publicaria o texto de antes). */
export const TRAVA_DA_PUBLICACAO = 'Espere o agente terminar o ajuste antes de publicar.';

const DA_PARTE = {
    titulo: {
        nome: 'Título',
        minusculo: 'título',
        refeito: 'Título refeito.',
        mantido: 'Título mantido: a equipe editou.',
        erro: 'Título: o agente não conseguiu refazer.',
    },
    descricao: {
        nome: 'Descrição',
        minusculo: 'descrição',
        refeito: 'Descrição refeita.',
        mantido: 'Descrição mantida: a equipe editou.',
        erro: 'Descrição: o agente não conseguiu refazer.',
    },
};

/** O tom de cada linha do resultado (nomes de tom, não classes). */
const TOM_DA_PARTE = { refeito: 'ok', mantido: 'neutro', erro: 'bad' };

const numero = (v) => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));
const textoLimpo = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
const lista = (v) => (Array.isArray(v) ? v : []);
const temChave = (objeto, chave) => Object.prototype.hasOwnProperty.call(objeto, chave);

// ─── A URL ───────────────────────────────────────────────────────────────────

/**
 * A URL do ajuste (`escrita.revisao.ajuste` com o id): a MESMA para o `POST` (pedir) e o `GET` (acompanhar). Null sem o
 * endereço (quem não escreve, o servidor de antes da 4b), sem o marcador ou sem o id.
 */
export function urlDoAjuste(escrita, itemId) {
    const modelo = escrita?.revisao?.ajuste;
    if (typeof modelo !== 'string' || !modelo.includes(MARCADOR_DO_ITEM)) return null;
    if (itemId === null || itemId === undefined || String(itemId).trim() === '') return null;

    return modelo.split(MARCADOR_DO_ITEM).join(encodeURIComponent(String(itemId)));
}

// ─── O ajuste ────────────────────────────────────────────────────────────────

/**
 * O ajuste na forma da tela: `{situacao, partes: {titulo?: {situacao, mensagem}, descricao?: …}, restantes_hoje,
 * mensagem}`. Null quando não há (nunca pediu) ou quando a situação não é do contrato — a tela não inventa um estado
 * que não conhece. Parte fora de `PARTES` sai; a situação dela fora do contrato vira null (segue o ajuste).
 */
export function normalizarAjuste(ajuste) {
    if (!ajuste || typeof ajuste !== 'object' || !SITUACOES.includes(ajuste.situacao)) return null;
    const partes = {};
    const vindas = ajuste.partes && typeof ajuste.partes === 'object' && !Array.isArray(ajuste.partes) ? ajuste.partes : {};
    for (const parte of PARTES) {
        if (!temChave(vindas, parte)) continue;
        const p = vindas[parte];
        partes[parte] = {
            situacao: SITUACOES_DA_PARTE.includes(p?.situacao) ? p.situacao : null,
            mensagem: textoLimpo(p?.mensagem),
        };
    }
    const restantes = numero(ajuste.restantes_hoje);

    return {
        situacao: ajuste.situacao,
        partes,
        restantes_hoje: restantes === null ? null : Math.max(0, Math.floor(restantes)),
        mensagem: textoLimpo(ajuste.mensagem),
    };
}

/** O ajuste de uma resposta (`{ajuste}` do 202, do GET e da prévia). */
export const ajusteDaResposta = (resposta) => normalizarAjuste(resposta?.ajuste);

/** O ajuste que a prévia traz (o mesmo cálculo do GET). */
export const ajusteDaPrevia = (previa) => normalizarAjuste(previa?.ajuste);

/** A situação (ou null). */
export const situacaoDoAjuste = (ajuste) => (SITUACOES.includes(ajuste?.situacao) ? ajuste.situacao : null);

/** O ajuste ainda anda (na fila ou fazendo)? */
export const emAndamento = (ajuste) => EM_ANDAMENTO.includes(situacaoDoAjuste(ajuste));

/** As partes do ajuste, na ordem do contrato. */
const partesDo = (ajuste) => PARTES.filter((p) => ajuste?.partes && temChave(ajuste.partes, p));

/** "título, descrição" — as partes que ainda andam (ou todas, se nenhuma disse que terminou). */
function partesQueAndam(ajuste) {
    const todas = partesDo(ajuste);
    const andando = todas.filter((p) => !PARTE_TERMINADA.includes(ajuste.partes[p].situacao));

    return (andando.length ? andando : todas).map((p) => DA_PARTE[p].minusculo).join(', ');
}

/** O texto sem caixa e sem a pontuação do fim ("Ajuste feito." → "ajuste feito"). */
const semPontaFinal = (t) => String(t ?? '').trim().toLowerCase().replace(/[\s.…!?:;,]+$/u, '');

/** A `mensagem` do servidor diz algo além do título? (A régua da 4d: começando pelo título, ela não vira detalhe.) */
const dizMais = (mensagem, titulo) => !!mensagem && !semPontaFinal(mensagem).startsWith(semPontaFinal(titulo));

/**
 * O resultado por parte (só as que terminaram, na ordem do contrato): "Título refeito.", "Descrição mantida: a equipe
 * editou.", "Título: o agente não conseguiu refazer." — e, no erro, o porquê do servidor (quando não repete o geral).
 *
 * @returns {Array<{parte: string, nome: string, situacao: string, texto: string, detalhe: ?string, tom: string}>}
 */
export function resultadosDoAjuste(ajuste) {
    const a = normalizarAjuste(ajuste);
    if (!a) return [];

    return partesDo(a)
        .filter((p) => PARTE_TERMINADA.includes(a.partes[p].situacao))
        .map((p) => {
            const { situacao, mensagem } = a.partes[p];
            const detalhe = situacao === 'erro' && mensagem && semPontaFinal(mensagem) !== semPontaFinal(a.mensagem) ? mensagem : null;

            return { parte: p, nome: DA_PARTE[p].nome, situacao, texto: DA_PARTE[p][situacao], detalhe, tom: TOM_DA_PARTE[situacao] };
        });
}

/**
 * O que o rodapé mostra do ajuste: o título neutro da situação ("Agente refazendo · título, descrição", D60 — só as partes
 * que ainda andam), o tom da pílula, o detalhe (no erro, a causa que o servidor manda), o resultado por parte e se
 * ainda anda. Null sem ajuste.
 *
 * @returns {?{situacao: string, titulo: string, tom: string, detalhe: ?string, resultados: Array, andando: boolean}}
 */
export function vistaDoAjuste(ajuste) {
    const a = normalizarAjuste(ajuste);
    if (!a) return null;
    const quais = partesQueAndam(a);
    const resultados = resultadosDoAjuste(a);
    let titulo;
    let tom;
    if (a.situacao === 'fila') {
        titulo = quais ? `Na fila do agente · ${quais}` : 'Na fila do agente';
        tom = 'run';
    } else if (a.situacao === 'fazendo') {
        titulo = quais ? `Agente refazendo · ${quais}` : 'Agente refazendo';
        tom = 'run';
    } else if (a.situacao === 'feito') {
        titulo = AVISO_TERMINOU;
        // Nada refeito (a equipe tinha editado tudo, ou deu erro numa parte): não é verde.
        tom = resultados.some((r) => r.situacao === 'refeito') || !resultados.length ? 'ok' : 'warn';
    } else {
        titulo = AVISO_FALHOU;
        tom = 'bad';
    }

    const andando = EM_ANDAMENTO.includes(a.situacao);
    // A mensagem geral do servidor só no ERRO, e só quando nenhuma parte já traz a causa: andando, o título diz o que
    // importa (as partes); no feito, ela é genérica ("Ajuste pronto.") e o resultado por parte diz o resto; no erro, ela
    // é a causa única (a cota, a IA que falhou) ou um texto genérico quando cada parte falhou por um motivo.
    const detalhe = a.situacao === 'erro' && !resultados.some((r) => r.detalhe) && dizMais(a.mensagem, titulo) ? a.mensagem : null;

    return { situacao: a.situacao, titulo, tom, detalhe, resultados, andando };
}

// ─── O que vale: o ajuste da gaveta × o da prévia ───────────────────────────

/**
 * O estado local do ajuste (o hook só guarda o resultado destas funções). `ajuste` undefined = vale o da prévia; null =
 * nenhum; `previa` = a prévia que estava aberta quando o `ajuste` local mudou (a marca de quem é mais novo);
 * `escolhendo` = a escolha "O que refazer?" aberta no rodapé; `partes` = o que está marcado nela; `voltas` = perguntas
 * seguidas; `parou` = um erro que não passa sozinho; `falha` = o texto da última pergunta que falhou.
 */
export const ESTADO_DO_AJUSTE = Object.freeze({
    ajuste: undefined,
    previa: null,
    escolhendo: false,
    partes: Object.freeze([]),
    enviando: false,
    erro: null,
    voltas: 0,
    parou: false,
    falha: null,
});

/**
 * O ajuste que vale: o da gaveta (o do pedido e das perguntas) enquanto ele é o mais novo; chegou uma prévia DEPOIS
 * dele (o fim do ajuste recarrega a prévia), vale o da prévia — e, se ela não trouxer nenhum, fica o resultado que a
 * gaveta viu. `daPrevia` = `ajusteDaPrevia(previa)` já calculado (o hook o guarda por prévia).
 */
export function ajusteEfetivo(local, previa, daPrevia = ajusteDaPrevia(previa)) {
    if (!local || local.ajuste === undefined) return daPrevia;
    if (local.previa === previa) return local.ajuste;

    return daPrevia ?? local.ajuste;
}

/**
 * Quantos ajustes ainda cabem hoje neste anúncio: o número da resposta mais nova — o do ajuste da gaveta enquanto ele
 * é mais novo que a prévia; senão o `ajustes_restantes_hoje` da prévia (ou o `restantes_hoje` do ajuste dela). Null =
 * não se sabe (o servidor decide).
 */
export function restantesHoje(local, previa) {
    if (local && local.ajuste !== undefined && local.previa === previa) {
        const doLocal = numero(local.ajuste?.restantes_hoje);
        if (doLocal !== null) return Math.max(0, doLocal);
    }
    const daPrevia = numero(previa?.ajustes_restantes_hoje) ?? numero(previa?.ajuste?.restantes_hoje);

    return daPrevia === null ? null : Math.max(0, daPrevia);
}

/** "Resta 1 ajuste hoje." / "Restam 2 ajustes hoje." / "Nenhum ajuste restante hoje."; null sem o número. */
export function textoDosRestantes(restantes) {
    const n = numero(restantes);
    if (n === null) return null;
    if (n <= 0) return 'Nenhum ajuste restante hoje.';

    return n === 1 ? 'Resta 1 ajuste hoje.' : `Restam ${Math.floor(n)} ajustes hoje.`;
}

// ─── O botão ─────────────────────────────────────────────────────────────────

/**
 * O agente escreveu o texto deste anúncio? O `preparo` da prévia (a régua da linha, só na porta da equipe) traz a pílula do
 * título e da descrição quando o preparo do agente passou por elas; no kit da fase N e no rascunho que o agente nunca
 * preparou, as duas vêm nulas.
 */
export const textoDoRobo = (previa) => previa?.preparo?.titulo != null || previa?.preparo?.descricao != null;

/**
 * O botão secundário [Pedir ajuste ao agente], ou null quando ele não aparece. Aparece só para quem recebeu a URL, com a
 * prévia pronta (e não o 409 dela), o item NA REVISÃO, sem publicação andando ou feita (conferindo, na fila, publicando,
 * publicado — avisos, recusado e erro deixam ajustar e tentar de novo) e sem ajuste andando. No limite do dia (0
 * restantes) ou com preço digitado e não salvo, fica desligado com a dica; com 0 e um texto que o agente não escreveu
 * (`textoDoRobo`), some. `ajuste` e `publicacao` são os que valem agora (os da gaveta, ou os da prévia).
 *
 * @returns {?{rotulo: string, desligado: boolean, dica: ?string}}
 */
export function acaoDoAjuste({ escrita, previa, oferta = null, ajuste = null, publicacao = null, restantes = null, precoPendente = false }) {
    if (!urlDoAjuste(escrita, oferta?.id ?? previa?.item?.id)) return null;
    if (previa?.pronta !== true || previa?.conflito || !lista(previa?.anuncios).length) return null;
    if ((previa?.item?.etapa ?? oferta?.etapa) !== 'revisao') return null;
    if (PUBLICACAO_QUE_TRAVA.includes(publicacao?.situacao)) return null;
    if (emAndamento(ajuste)) return null;
    const n = numero(restantes);
    // O servidor manda 0 também quando o agente não tem o que refazer (o kit da fase N, o rascunho sem o preparo dele):
    // com 0 e nenhum texto do agente no `preparo`, o botão some — "limite atingido" seria mentira.
    if (n !== null && n <= 0 && !textoDoRobo(previa)) return null;
    let dica = null;
    if (n !== null && n <= 0) dica = DICA_DO_LIMITE;
    else if (precoPendente) dica = DICA_DO_PRECO_PENDENTE;

    return { rotulo: ROTULO_DO_PEDIR, desligado: !!dica, dica };
}

// ─── A escolha "O que refazer?" ─────────────────────────────────────────────

/** Marca ou desmarca uma parte; o resultado fica na ordem do contrato (não na do clique). Parte desconhecida não entra. */
export function alternarParte(partes, parte) {
    const atuais = new Set(lista(partes).filter((p) => PARTES.includes(p)));
    if (PARTES.includes(parte)) {
        if (atuais.has(parte)) atuais.delete(parte);
        else atuais.add(parte);
    }

    return PARTES.filter((p) => atuais.has(p));
}

/**
 * A escolha aberta no rodapé: as duas partes (marcada ou não), "Restam N ajustes hoje", e se o [Refazer] pode sair —
 * pelo menos uma parte, nada de preço pendente; enviando, ele fica ocupado (focável), não desligado.
 *
 * @returns {{opcoes: Array<{parte: string, nome: string, marcada: boolean}>, restantes: ?string, podeRefazer: boolean,
 *   dica: ?string, rotulo: string, ocupado: boolean}}
 */
export function vistaDaEscolha({ partes = [], restantes = null, precoPendente = false, enviando = false } = {}) {
    const marcadas = alternarParte(partes, null);
    let dica = null;
    if (!marcadas.length) dica = DICA_DA_ESCOLHA;
    else if (precoPendente) dica = DICA_DO_PRECO_PENDENTE;

    return {
        opcoes: PARTES.map((p) => ({ parte: p, nome: DA_PARTE[p].nome, marcada: marcadas.includes(p) })),
        restantes: textoDosRestantes(restantes),
        podeRefazer: !dica,
        dica: enviando ? null : dica,
        rotulo: enviando ? ROTULO_ENVIANDO : ROTULO_DO_REFAZER,
        ocupado: !!enviando,
    };
}

/**
 * O corpo do `POST` (§19.14): a `revisao` da prévia aberta (a de agora) e as partes marcadas, na ordem do contrato —
 * só `titulo`/`descricao`, nenhuma outra chave (sem texto livre).
 */
export const corpoDoAjuste = (previa, partes) => ({ revisao: previa?.revisao ?? null, refazer: alternarParte(partes, null) });

/** O ajuste que a tela supõe quando o 202 vem sem corpo: na fila, com as partes pedidas. */
export function ajusteNaFila(corpo) {
    const partes = {};
    for (const p of alternarParte(corpo?.refazer, null)) partes[p] = { situacao: 'fila', mensagem: null };

    return { situacao: 'fila', partes, restantes_hoje: null, mensagem: null };
}

// ─── Acompanhar ──────────────────────────────────────────────────────────────

/** Perguntar de novo? Só enquanto anda, sem ter parado (erro que não passa) e abaixo do teto. */
export const devePerguntar = (ajuste, voltas = 0, parou = false) => !parou && emAndamento(ajuste) && voltas < MAX_CONSULTAS;

/** A gaveta parou de olhar com o ajuste ainda andando (o teto, ou um erro que não passa)? */
export const parouDeAcompanhar = (ajuste, voltas = 0, parou = false) => emAndamento(ajuste) && (parou || voltas >= MAX_CONSULTAS);

/**
 * A consulta que falhou de um jeito que não passa sozinho (a sessão caiu, sem permissão, o item sumiu): para de
 * perguntar. Rede, 429 e 5xx tentam de novo na próxima volta.
 */
export const devePararNoErro = (erro) => [401, 403, 404, 419].includes(erro?.response?.status);

/** A gaveta viu o ajuste terminar agora (andava e virou feito ou erro): recarrega a prévia e a Lista. */
export const terminouOAjuste = (antes, depois) => EM_ANDAMENTO.includes(antes) && (depois === 'feito' || depois === 'erro');

/**
 * Depois do pedido (`{ajuste, erro}` de `enviarAjuste`): fecha a escolha, sai do "enviando" com o erro (ou sem); o
 * ajuste que veio (o 202, ou o estado lido depois de um 409/422) passa a valer e o acompanhamento recomeça do zero.
 * `ajuste` undefined = não mexe.
 */
export function estadoDepoisDoEnvio(estado, resposta) {
    const base = { ...ESTADO_DO_AJUSTE, ...estado, escolhendo: false, partes: [], enviando: false, erro: resposta?.erro ?? null };
    if (resposta?.ajuste === undefined) return base;

    return { ...base, ajuste: resposta.ajuste, voltas: 0, parou: false, falha: null };
}

/**
 * Depois de uma pergunta (`{ajuste, falha, parar}` de `perguntarAjuste`): conta a volta; o ajuste novo passa a valer
 * (null = sumiu: para de perguntar); a falha fica à vista e, se não passa sozinha, para.
 */
export function estadoDepoisDaConsulta(estado, resposta) {
    const base = { ...ESTADO_DO_AJUSTE, ...estado, voltas: (estado?.voltas ?? 0) + 1, falha: resposta?.falha ?? null, parou: !!resposta?.parar };

    return resposta?.ajuste === undefined ? base : { ...base, ajuste: resposta.ajuste };
}

// ─── As travas enquanto o agente refaz ────────────────────────────────────────

/** A caixa "Preço" só leitura enquanto o ajuste anda (ou o pedido sai), com o porquê; null = segue a regra dela. */
export const travaDoPreco = (ajuste, enviando = false) => (enviando || emAndamento(ajuste) ? TRAVA_DO_PRECO : null);

/** O [Aprovar e publicar] desligado enquanto o ajuste anda, com o porquê; null = segue a regra dele. */
export const travaDaPublicacao = (ajuste, enviando = false) => (enviando || emAndamento(ajuste) ? TRAVA_DA_PUBLICACAO : null);

/**
 * A caixa "Preço" (o controle de `usePrecoDaPrevia`) com a trava do ajuste: só leitura com o porquê — o mesmo jeito
 * com que a 4d trava o preço. Sem trava, ou sem caixa editável, devolve como veio.
 */
export const comTravaDoPreco = (preco, trava) => (trava && preco?.editavel ? { ...preco, estado: { ...preco.estado, leitura: trava } } : preco);

/**
 * O rodapé da publicação (o controle de `usePublicacaoDaPrevia`) com a trava do ajuste: o primário desligado, com a
 * dica. "Enviando…" (ocupado) não muda — o pedido já saiu. Sem trava, ou sem primário, devolve como veio.
 */
export const comTravaDaPublicacao = (publicar, dica) => (dica && publicar?.acao && !publicar.acao.ocupado
    ? { ...publicar, acao: { ...publicar.acao, desligado: true, dica } }
    : publicar);

// ─── Os erros do pedido ──────────────────────────────────────────────────────

// O idioma do Laravel nesta instalação é o inglês: a mensagem padrão do framework (e a das regras sem texto próprio)
// não vai para a tela — a cópia da régua de `trilhaEscrita.js`, que esta lib não importa (puxa o axios).
const DO_FRAMEWORK = /^(?:conflict|not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|the given data was invalid)\b|^the .+ (?:field|must|may|is)\b/i;

const doServidor = (m) => {
    const t = textoLimpo(m);

    return t && !DO_FRAMEWORK.test(t) ? t : null;
};

/** A 1ª mensagem que serve de `errors` — as de `refazer` (e `refazer.0`…) primeiro. */
function primeiraDosErros(errors) {
    const entradas = errors && typeof errors === 'object' ? Object.entries(errors) : [];
    const ordem = [...entradas.filter(([k]) => /^refazer(?:\.|$)/.test(k)), ...entradas.filter(([k]) => !/^refazer(?:\.|$)/.test(k))];

    return ordem.flatMap(([, m]) => [m].flat()).map(doServidor).find(Boolean) ?? null;
}

/**
 * O que um erro do `POST` quer dizer para o rodapé:
 * - `revisao` — 409 com `revisao_atual` (sem `travado`): o anúncio mudou depois que a gaveta abriu → [Recarregar];
 * - `conflito` — os outros 409 (fora da revisão, na fila, publicando, publicado, não pronta, ajuste em andamento);
 * - `proibido` — 403;
 * - `invalido` — 422 (o limite do dia, `refazer` vazio ou desconhecido): a 1ª mensagem de `errors.refazer`;
 * - `outro` — o resto (rede, 429, 500…): `comum`, o texto de `mensagemDoErro` que quem chama passa.
 *
 * @returns {{tipo: 'revisao'|'conflito'|'proibido'|'invalido'|'outro', mensagem: string, revisaoAtual: ?number}}
 */
export function erroDoAjuste(erro, comum = null) {
    const resposta = erro?.response;
    const data = resposta?.data && typeof resposta.data === 'object' ? resposta.data : {};
    const status = resposta?.status;
    if (status === 409) {
        if (temChave(data, 'revisao_atual') && !data.travado) {
            return { tipo: 'revisao', mensagem: doServidor(data.message) ?? MSG_MUDOU, revisaoAtual: numero(data.revisao_atual) };
        }

        return { tipo: 'conflito', mensagem: doServidor(data.message) ?? doServidor(data.motivo) ?? MSG_CONFLITO, revisaoAtual: null };
    }
    if (status === 403) return { tipo: 'proibido', mensagem: doServidor(data.message) ?? MSG_PROIBIDO, revisaoAtual: null };
    if (status === 422) return { tipo: 'invalido', mensagem: primeiraDosErros(data.errors) ?? doServidor(data.message) ?? MSG_CONFIRA, revisaoAtual: null };

    return { tipo: 'outro', mensagem: textoLimpo(comum) ?? 'Não deu certo. Tente de novo.', revisaoAtual: null };
}

/** Depois deste erro vale perguntar o estado uma vez? (Outra aba já pediu; ou o limite: o número de hoje vem junto.) */
export const devePerguntarDepoisDoErro = (erro) => erro?.tipo === 'conflito' || erro?.tipo === 'invalido';
