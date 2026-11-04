// ─── Trilha de produtos — aprovar e publicar pela prévia (4ª entrega, fatia 4d) ─
//
// As contas PURAS do rodapé da gaveta `v:{item}` (`Components/Trilha/Revisao/PublicarAnuncio.jsx`):
// as URLs, o corpo do pedido, quando o primário [Aprovar e publicar] aparece (ou
// vira [Publicar assim mesmo]), o texto de cada situação da publicação, quando
// perguntar de novo, o que cada erro quer dizer e a trava da caixa "Preço".
//
// O contrato é o §19.13 do desenho (`.planning/trilha/ENTREGA-3-DESENHO.md`):
// - `POST {escrita.revisao.publicar}` (com `__ITEM__` no lugar do id) com
//   `{revisao, ciente}` → 202 `{publicacao}`;
// - `GET {escrita.revisao.publicacao}` → `{publicacao}` ou `{publicacao: null}`;
// - `publicacao = {situacao, mensagem, avisos: [], mlbs: []}`, com `situacao` ∈
//   `conferindo | avisos | na_fila | publicando | publicado | recusado | erro`;
// - 403 fora da loja liberada (mensagem neutra); 409 da revisão velha com
//   `revisao_atual` (SEM `travado`, o padrão F6); os outros 409 (fora da revisão,
//   na fila, publicando, publicado, não pronta) só com `message`; 422 do Premium
//   igual ao Clássico. A prévia (GET) também traz `publicacao`, pelo mesmo cálculo.
//
// Quem decide é o SERVIDOR: a tela só esconde o botão quando o pedido não teria
// como passar, e mostra a mensagem que ele devolver.
//
// Mora no pedaço que SÓ a equipe baixa (o `lazy` da gaveta) e não importa a lib
// da prévia nem a do preço: as libs `trilha*` são varridas como "de fora" da pasta
// `Revisao/` (tests/js/trilha-empresa-render.test.js e trilha-preco.test.js). Por
// isso o marcador e a régua da mensagem do framework são cópias — o teste confere
// que o marcador é o mesmo.
//
// Sem React, sem axios e sem classe do Tailwind (arquivo `.js`). Os textos não
// citam o marketplace, o Portal nem o "agente" (D59): o cartão vai ao cliente na 4e.

/** O marcador do id do item nas URLs da revisão (§19.4) — o mesmo `MARCADOR_DO_ITEM` da prévia. */
export const MARCADOR_DO_ITEM = '__ITEM__';

/** As situações do contrato (§19.13), na ordem em que acontecem. */
export const SITUACOES = Object.freeze(['conferindo', 'avisos', 'na_fila', 'publicando', 'publicado', 'recusado', 'erro']);

/** As situações em que o pedido ainda anda: a gaveta pergunta de novo e o preço fica só leitura. */
export const EM_ANDAMENTO = Object.freeze(['conferindo', 'na_fila', 'publicando']);

/** De quanto em quanto tempo a gaveta pergunta enquanto anda (§19.13: a cada 3 s). */
export const INTERVALO_DA_CONSULTA_MS = 3000;

/** O teto de perguntas seguidas (30 min a cada 3 s): a gaveta esquecida aberta não pergunta para sempre. */
export const MAX_CONSULTAS = 600;

export const ROTULO_DO_PUBLICAR = 'Aprovar e publicar';
export const ROTULO_DO_CIENTE = 'Publicar assim mesmo';
export const ROTULO_ENVIANDO = 'Enviando…';

/** A pergunta da confirmação no rodapé: nada sai sem ela. */
export const PERGUNTA_DA_CONFIRMACAO = 'Publicar este anúncio na loja agora?';

/** O 409 da revisão sem mensagem própria (o mesmo texto da caixa do preço, 4c). */
export const MSG_MUDOU = 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.';

/** O 403 sem mensagem própria (o texto neutro do servidor, §19.13). */
export const MSG_NAO_LIBERADO = 'Publicar pela trilha ainda não está liberado para esta loja.';

/** Os outros 409 sem mensagem própria. */
export const MSG_CONFLITO = 'Este anúncio não pode ser publicado agora. Recarregue a página para ver onde ele está.';

/** O 422 sem mensagem que sirva. */
export const MSG_CONFIRA = 'Confira o anúncio e tente de novo.';

/** O primário desligado enquanto há preço digitado e não salvo (ou salvando): publicaria o preço velho. */
export const DICA_DO_PRECO_PENDENTE = 'Salve ou descarte o preço digitado antes de publicar.';

/**
 * O primário desligado com o Premium igual ao Clássico em alguma cor (`preco.premium_igual` da prévia — a MESMA régua
 * com que o servidor responde 422 no publicar, D46): clicar só traria o erro.
 */
export const DICA_DO_PREMIUM_IGUAL = 'O Premium está com o mesmo preço do Clássico: ajuste o preço antes de publicar.';

/** Passou do teto de perguntas: a publicação segue no servidor, a gaveta só para de olhar. */
export const MSG_PAROU = 'Parei de acompanhar por aqui. A publicação continua mesmo com a tela fechada.';

/** O aviso da página quando a gaveta vê a publicação terminar. */
export const AVISO_PUBLICADO = 'Anúncio publicado · em análise.';

const TEXTO_DA_SITUACAO = {
    conferindo: { titulo: 'Conferindo o anúncio…', tom: 'run' },
    avisos: { titulo: 'Conferido, com avisos: leia antes de publicar.', tom: 'warn' },
    na_fila: { titulo: 'Na fila de publicação', tom: 'run' },
    publicando: { titulo: 'Publicando agora', tom: 'run' },
    publicado: { titulo: 'Publicado · em análise', tom: 'ok' },
    recusado: { titulo: 'A conferência achou erros: nada foi publicado.', tom: 'bad' },
    erro: { titulo: 'A publicação falhou.', tom: 'bad' },
};

const ROTULO_DA_LISTA = {
    avisos: 'Avisos da conferência:',
    recusado: 'O que impede de publicar:',
    erro: 'Detalhes:',
};

const TRAVA_DO_PRECO = {
    conferindo: 'O anúncio está sendo conferido para a publicação: o preço fica só para leitura.',
    na_fila: 'Este anúncio está na fila de publicação: o preço não muda mais por aqui.',
    publicando: 'Este anúncio está sendo publicado agora: o preço não muda mais por aqui.',
    publicado: 'Este anúncio já foi publicado: o preço não muda mais por aqui.',
};

const numero = (v) => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));
const textoLimpo = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
const lista = (v) => (Array.isArray(v) ? v : []);
const temChave = (objeto, chave) => Object.prototype.hasOwnProperty.call(objeto, chave);

/** Texto, ou objeto com a mensagem (`mensagem`, `texto`, `message`, `titulo`), como texto; o resto sai. */
const comoTexto = (item) => (typeof item === 'string'
    ? textoLimpo(item)
    : textoLimpo(item?.mensagem) ?? textoLimpo(item?.texto) ?? textoLimpo(item?.message) ?? textoLimpo(item?.titulo));

const textos = (itens) => lista(itens).map(comoTexto).filter(Boolean);

// ─── As URLs ─────────────────────────────────────────────────────────────────

function comItem(modelo, itemId) {
    if (typeof modelo !== 'string' || !modelo.includes(MARCADOR_DO_ITEM)) return null;
    if (itemId === null || itemId === undefined || String(itemId).trim() === '') return null;

    return modelo.split(MARCADOR_DO_ITEM).join(encodeURIComponent(String(itemId)));
}

/** A URL do `POST` de publicar (`escrita.revisao.publicar` com o id). Null sem o endereço (quem não escreve), sem o marcador ou sem o id. */
export const urlDoPublicar = (escrita, itemId) => comItem(escrita?.revisao?.publicar, itemId);

/** A URL do `GET` do acompanhamento (`escrita.revisao.publicacao` com o id). Null como a de cima. */
export const urlDaPublicacao = (escrita, itemId) => comItem(escrita?.revisao?.publicacao, itemId);

// ─── A publicação ────────────────────────────────────────────────────────────

/**
 * A publicação na forma da tela: `{situacao, mensagem, avisos: string[], mlbs: []}`. Null quando não há (nunca pediu)
 * ou quando a situação não é do contrato — a tela não inventa um estado que não conhece.
 */
export function normalizarPublicacao(publicacao) {
    if (!publicacao || typeof publicacao !== 'object' || !SITUACOES.includes(publicacao.situacao)) return null;

    return {
        situacao: publicacao.situacao,
        mensagem: textoLimpo(publicacao.mensagem),
        avisos: textos(publicacao.avisos),
        mlbs: lista(publicacao.mlbs).filter((m) => m !== null && m !== undefined && m !== ''),
    };
}

/** A publicação de uma resposta (`{publicacao}` do 202, do GET e da prévia). */
export const publicacaoDaResposta = (resposta) => normalizarPublicacao(resposta?.publicacao);

/** A situação (ou null). */
export const situacaoDe = (publicacao) => (SITUACOES.includes(publicacao?.situacao) ? publicacao.situacao : null);

/** O pedido ainda anda (conferindo, na fila ou publicando)? */
export const emAndamento = (publicacao) => EM_ANDAMENTO.includes(situacaoDe(publicacao));

/** O texto sem caixa e sem a pontuação do fim ("Na fila de publicação." → "na fila de publicação"). */
const semPontaFinal = (t) => String(t ?? '').trim().toLowerCase().replace(/[\s.…!?:;,]+$/u, '');

/**
 * A `mensagem` do servidor diz algo além do título? O servidor manda uma em toda situação, e nas que andam ela repete
 * o título ("Na fila de publicação.", "Publicando agora…", "Conferindo o anúncio antes de publicar…"): começando pelo
 * título (sem caixa e sem a pontuação do fim), ela não vira detalhe.
 */
const dizMais = (mensagem, titulo) => !!mensagem && !semPontaFinal(mensagem).startsWith(semPontaFinal(titulo));

/** "1 anúncio criado." / "4 anúncios criados."; null sem nenhum. */
export const textoDosAnuncios = (quantos) => (quantos > 0 ? `${quantos} ${quantos === 1 ? 'anúncio criado' : 'anúncios criados'}.` : null);

/**
 * O que o rodapé mostra da publicação: o título neutro da situação, o tom da pílula, o detalhe (a `mensagem` do
 * servidor, quando diz mais que o título; no publicado, quantos anúncios foram criados), a lista (os avisos da conferência,
 * ou os erros do recusado) com o rótulo dela, e se ainda anda. Null sem publicação.
 *
 * @returns {?{situacao: string, titulo: string, tom: string, detalhe: ?string, lista: string[], rotuloDaLista: ?string, andando: boolean}}
 */
export function vistaDaPublicacao(publicacao) {
    const p = normalizarPublicacao(publicacao);
    if (!p) return null;
    const { titulo, tom } = TEXTO_DA_SITUACAO[p.situacao];
    let detalhe = dizMais(p.mensagem, titulo) ? p.mensagem : null;
    if (p.situacao === 'publicado') detalhe = [detalhe, textoDosAnuncios(p.mlbs.length)].filter(Boolean).join(' ') || null;

    return {
        situacao: p.situacao,
        titulo,
        tom,
        detalhe,
        lista: p.avisos,
        rotuloDaLista: p.avisos.length ? ROTULO_DA_LISTA[p.situacao] ?? 'Avisos:' : null,
        andando: EM_ANDAMENTO.includes(p.situacao),
    };
}

// ─── O primário ──────────────────────────────────────────────────────────────

/** A prévia tem algum bloqueio (texto, ou objeto com a mensagem)? O mesmo filtro de `bloqueiosDaPrevia`. */
const temBloqueio = (previa) => textos(previa?.bloqueios).length > 0;

/**
 * O primário do rodapé, ou null quando ele não aparece. Aparece só para quem recebeu as DUAS URLs (publicar e
 * acompanhar — sem a 2ª a gaveta não teria como mostrar o andamento), com a prévia pronta (e não o 409 dela), o item
 * NA REVISÃO, sem bloqueios e sem publicação andando ou feita. Com `avisos` ele vira [Publicar assim mesmo] (`ciente`);
 * depois do recusado e do erro, volta a ser [Aprovar e publicar] (tentar de novo). Com preço digitado e não salvo (ou
 * salvando) — publicaria o preço velho — ou com o Premium igual ao Clássico (o servidor recusaria), fica desligado com a
 * dica. `publicacao` undefined = a da prévia; null = nenhuma.
 *
 * @returns {?{acao: 'publicar'|'ciente', ciente: boolean, rotulo: string, desligado: boolean, ocupado: boolean, dica: ?string}}
 */
export function acaoDaPublicacao({ escrita, previa, oferta = null, publicacao = undefined, precoPendente = false, enviando = false }) {
    const id = oferta?.id ?? previa?.item?.id;
    if (!urlDoPublicar(escrita, id) || !urlDaPublicacao(escrita, id)) return null;
    if (previa?.pronta !== true || previa?.conflito || !lista(previa?.anuncios).length) return null;
    if ((previa?.item?.etapa ?? oferta?.etapa) !== 'revisao') return null;
    if (temBloqueio(previa)) return null;
    const situacao = situacaoDe(publicacao !== undefined ? publicacao : normalizarPublicacao(previa?.publicacao));
    if (EM_ANDAMENTO.includes(situacao) || situacao === 'publicado') return null;
    const ciente = situacao === 'avisos';
    let motivo = null;
    if (precoPendente) motivo = DICA_DO_PRECO_PENDENTE;
    else if (previa?.preco?.premium_igual === true) motivo = DICA_DO_PREMIUM_IGUAL;

    return {
        acao: ciente ? 'ciente' : 'publicar',
        ciente,
        rotulo: enviando ? ROTULO_ENVIANDO : ciente ? ROTULO_DO_CIENTE : ROTULO_DO_PUBLICAR,
        desligado: !!motivo && !enviando,
        ocupado: !!enviando,
        dica: enviando ? null : motivo,
    };
}

/** O corpo do `POST` (§19.13): a `revisao` da prévia aberta (a de agora) e o `ciente` (os avisos foram lidos). */
export const corpoDoPublicar = (previa, ciente = false) => ({ revisao: previa?.revisao ?? null, ciente: ciente === true });

// ─── Acompanhar ──────────────────────────────────────────────────────────────

/** Perguntar de novo? Só enquanto anda, sem ter parado (erro que não passa) e abaixo do teto. */
export const devePerguntar = (publicacao, voltas = 0, parou = false) => !parou && emAndamento(publicacao) && voltas < MAX_CONSULTAS;

/** A gaveta parou de olhar com o pedido ainda andando (o teto, ou um erro que não passa)? */
export const parouDeAcompanhar = (publicacao, voltas = 0, parou = false) => emAndamento(publicacao) && (parou || voltas >= MAX_CONSULTAS);

/**
 * A consulta que falhou de um jeito que não passa sozinho (a sessão caiu, sem permissão, o item sumiu): para de
 * perguntar. Rede, 429 e 5xx tentam de novo na próxima volta.
 */
export const devePararNoErro = (erro) => [401, 403, 404, 419].includes(erro?.response?.status);

/** A gaveta viu a publicação terminar agora (andava e virou publicado): recarrega a Lista e avisa. */
export const terminouDePublicar = (antes, depois) => EM_ANDAMENTO.includes(antes) && depois === 'publicado';

// ─── O estado do rodapé (puro: o hook só guarda o resultado) ─────────────────

/**
 * O estado do rodapé ao abrir (e a cada prévia nova — o hook o prende à prévia em que nasceu). `publicacao` undefined
 * = vale a da prévia; null = nenhuma; `voltas` = perguntas seguidas; `parou` = um erro que não passa sozinho; `falha` =
 * o texto da última pergunta que falhou.
 */
export const ESTADO_DA_PUBLICACAO = Object.freeze({
    publicacao: undefined,
    confirmando: false,
    enviando: false,
    erro: null,
    voltas: 0,
    parou: false,
    falha: null,
});

/**
 * Depois do pedido (`{publicacao, erro}` de `enviarPublicacao`): sai do "enviando" com o erro (ou sem); a publicação que
 * veio (o 202, ou o estado lido depois de um 409) passa a valer e o acompanhamento recomeça do zero. `publicacao`
 * undefined = não mexe.
 */
export function estadoDepoisDoEnvio(estado, resposta) {
    const base = { ...estado, confirmando: false, enviando: false, erro: resposta?.erro ?? null };
    if (resposta?.publicacao === undefined) return base;

    return { ...base, publicacao: resposta.publicacao, voltas: 0, parou: false, falha: null };
}

/**
 * Depois de uma pergunta (`{publicacao, falha, parar}` de `perguntarPublicacao`): conta a volta; a publicação nova
 * passa a valer (null = o pedido sumiu: para de perguntar); a falha fica à vista e, se não passa sozinha, para.
 */
export function estadoDepoisDaConsulta(estado, resposta) {
    const base = { ...estado, voltas: (estado?.voltas ?? 0) + 1, falha: resposta?.falha ?? null, parou: !!resposta?.parar };

    return resposta?.publicacao === undefined ? base : { ...base, publicacao: resposta.publicacao };
}

/**
 * A caixa "Preço" só leitura enquanto a publicação anda (ou depois de publicada), com o porquê; null = segue a regra
 * dela. Com `avisos`, `recusado` e `erro` o preço continua editável: é o que se ajusta antes de tentar de novo.
 */
export const travaDoPreco = (publicacao) => TRAVA_DO_PRECO[situacaoDe(publicacao)] ?? null;

// ─── Os erros do pedido ──────────────────────────────────────────────────────

// O idioma do Laravel nesta instalação é o inglês: a mensagem padrão do framework (e a das regras sem texto próprio)
// não vai para a tela — a cópia da régua de `trilhaEscrita.js`, que esta lib não importa (puxa o axios).
const DO_FRAMEWORK = /^(?:conflict|not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|the given data was invalid)\b|^the .+ (?:field|must|may|is)\b/i;

const doServidor = (m) => {
    const t = textoLimpo(m);

    return t && !DO_FRAMEWORK.test(t) ? t : null;
};

/**
 * O que um erro do `POST` quer dizer para o rodapé:
 * - `revisao` — 409 com `revisao_atual` (sem `travado`): o anúncio mudou depois que a gaveta abriu → [Recarregar];
 * - `conflito` — os outros 409 (fora da revisão, na fila, publicando, publicado, não pronta): a mensagem do servidor;
 * - `proibido` — 403 (a loja não liberada para publicar pela trilha): a mensagem neutra do servidor;
 * - `invalido` — 422 (o Premium igual ao Clássico): a 1ª mensagem de `errors`, ou a geral;
 * - `outro` — o resto (rede, 429, 500…): `comum`, o texto de `mensagemDoErro` que quem chama passa.
 *
 * @returns {{tipo: 'revisao'|'conflito'|'proibido'|'invalido'|'outro', mensagem: string, revisaoAtual: ?number}}
 */
export function erroDaPublicacao(erro, comum = null) {
    const resposta = erro?.response;
    const data = resposta?.data && typeof resposta.data === 'object' ? resposta.data : {};
    const status = resposta?.status;
    if (status === 409) {
        if (temChave(data, 'revisao_atual') && !data.travado) {
            return { tipo: 'revisao', mensagem: doServidor(data.message) ?? MSG_MUDOU, revisaoAtual: numero(data.revisao_atual) };
        }

        return { tipo: 'conflito', mensagem: doServidor(data.message) ?? doServidor(data.motivo) ?? MSG_CONFLITO, revisaoAtual: null };
    }
    if (status === 403) return { tipo: 'proibido', mensagem: doServidor(data.message) ?? MSG_NAO_LIBERADO, revisaoAtual: null };
    if (status === 422) {
        const doCampo = Object.values(data.errors ?? {}).flatMap((m) => [m].flat()).map(doServidor).find(Boolean);

        return { tipo: 'invalido', mensagem: doCampo ?? doServidor(data.message) ?? MSG_CONFIRA, revisaoAtual: null };
    }

    return { tipo: 'outro', mensagem: textoLimpo(comum) ?? 'Não deu certo. Tente de novo.', revisaoAtual: null };
}
