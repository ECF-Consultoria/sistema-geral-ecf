import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { cn } from '@/lib/utils';
import { textoSeguro, textoUtil } from '../BarraDaConta';
import { haQuanto } from '../tempo';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 06 — a PILL DE CONEXÃO da linha 1 (28px) e o seu popover de
// 320px (HEAD-06/HEAD-07).
//
// Protótipo: `new_layout_publicador/PublicadorHeader.dc.html`
//   · pill        → :73-75 (altura 28px, raio 999, dois dots de 7px)
//   · popover     → :77-89 (320px, raio 12px, linhas de 12px/14px)
//   · rodapé      → :85-88 ("Última coleta há 25 min · Atualizar agora")
//
// ⚠️⚠️ O ERP NÃO EXISTE COMO INTEGRAÇÃO — ELE É SÓ *DECLARADO* ⚠️⚠️
//
// O protótipo desenha "ERP Bling · Conectado · 32 SKUs · estoque atualizado há
// 25 min". Nada disso existe neste sistema: não há integração com ERP nenhum.
// A empresa apenas DIZ, no onboarding, qual usa — é o que
// `CabecalhoPublicadorService` entrega em `integracoes.erp.declarado` (o nome
// ou `null`), e o docblock daquela classe chama isso de "honestidade 1".
//
// Por isso, aqui:
//   · a linha do ERP diz "Declarado: X" ou "Não informado" — **NUNCA
//     "Conectado"** (há asserção NEGATIVA no gate de render);
//   · o 2º dot da pill é o **Portal do Cliente**, não o ERP: o Portal tem
//     estado MEDIDO (`situacao`/`novas`) e é a capacidade que o `SeloPortal`
//     entrega hoje, que HEAD-11 manda preservar;
//   · a meta do Mercado Livre NÃO diz "token renova em 4 h" (frase do
//     protótipo): o contrato expõe só `ativo|expirado|sem_token` e não existe
//     carimbo de expiração. Texto sem fonte não nasce (EXMP-01).
//
// ⚠️ "ATUALIZAR AGORA" ENFILEIRA (D-05/REG-06): é `router.post` na rota
// `mlb.anuncios.meus.atualizar`, que já existe e despacha
// `SyncMlAcervoCompanyJob`. NENHUMA chamada HTTP ao Mercado Livre, NENHUMA
// coleta em processo, NENHUMA escrita no ML. Nunca `router.put/delete/patch`.
//
// ⚠️ DIVERGÊNCIA DECLARADA (implementação, não aparência): o protótipo abre o
// popover com `position:absolute` + um backdrop transparente
// (`fixed;inset:0;z-index:60`). Aqui é `@radix-ui/react-popover` com `Portal`.
// A restrição é concreta: este header vive DENTRO do `<main>`, que tem
// `overflow-y-auto` (e D2 proíbe tocar no `AppLayout`), então conteúdo absoluto
// ali seria recortado pelo scrollport e rolaria com a página. O Radix leva o
// conteúdo para fora da árvore e já entrega **Esc** e **clique fora**
// (`DismissableLayer`) — o comportamento de HEAD-13 é o mesmo. Por isso
// `onEscapeKeyDown`/`onPointerDownOutside` NÃO são desabilitados aqui.
//
// ⚠️ POR QUE `PainelDeConexoes` É EXPORT NOMEADO: o `node --test` deste projeto
// roda sem jsdom, e o renderizador de servidor do React **não suporta portal**
// ("Portals are not currently supported by the server renderer"). Com o corpo
// do popover só dentro de `Popover.Portal`, as três linhas e o rodapé nunca
// poderiam ser conferidos por render REAL — só por leitura de fonte, que é
// exatamente o tipo de gate fraco que o plano 177-04 teve de reescrever. O
// painel é um componente puro, e o `Popover.Content` é uma casca em volta
// dele. Mesmo precedente do `criarOuvinteDeAtalhos` (plano 177-04).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * D23 — sem `Company` não existe acervo para coletar. Literal EXATO, idêntico
 * ao de `AbasDoHeader.jsx`, `AbasDaConta.jsx` e `ModoAnuncioTabs.jsx`. NÃO
 * VARIAR: há teste procurando esta string.
 */
export const TITULO_SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

/**
 * Por que "Reconectar →" leva à LISTA de empresas e não ao link de reconexão:
 * o link é o mesmo link público do Onboarding, e quem clica nele é quem tem a
 * sessão do Mercado Livre no navegador. Um clique INTERNO da ECF autoriza a
 * conta errada — foi o caso Masitto, 27/08, e é o aviso que
 * `LinkReconexao.jsx` carrega. Na lista de empresas o link aparece num botão
 * que COPIA (para enviar ao cliente), que é a forma segura. Então o header
 * navega para lá em vez de oferecer o clique perigoso.
 */
export const AVISO_RECONEXAO = 'Copie o link de reconexão na lista de empresas e envie ao CLIENTE: ele precisa abrir no navegador dele. Um clique interno da ECF autoriza a conta errada.';

/** Tons de status — tabela no escopo do MÓDULO (nunca variável de componente lida dentro de um `.map()`, REND-04). */
const COR_DO_TEXTO = {
    ok: 'text-pub-ok-txt',
    atencao: 'text-pub-warn-txt',
    erro: 'text-pub-danger-txt',
    neutro: 'text-pub-txt-2',
    ausente: 'text-pub-txt-4',
};

const COR_DO_DOT = {
    ok: 'bg-pub-ok',
    atencao: 'bg-pub-warn',
    erro: 'bg-pub-danger',
    neutro: 'bg-pub-neutral-dot',
    ausente: 'bg-pub-neutral-dot',
};

/** `situacao` do Portal que PEDE ação — as duas que o `SeloPortal` já destaca hoje. */
function portalPendente(integracoes) {
    const situacao = textoSeguro(integracoes?.portal?.situacao, null);
    return situacao === 'novas' || situacao === 'nunca';
}

/** Tom do Portal: medido e em dia, medido e pendente, ou não há Portal (neutro, nunca verde). */
export function tomDoPortal(integracoes) {
    const situacao = textoSeguro(integracoes?.portal?.situacao, null);
    if (situacao === 'sincronizado') return 'ok';
    if (situacao === 'novas' || situacao === 'nunca') return 'atencao';
    return 'neutro';
}

/**
 * O estado de conexão da conta, em UMA casa.
 *
 * É a regra `dot` do protótipo (`PublicadorHeader.dc.html:226`) com o ERP
 * trocado pelo Portal, e serve a DOIS lugares: a pill daqui e a bolinha de
 * 10px do tile em `SeletorDeEmpresaDoHeader.jsx`. Export nomeado justamente
 * para não existirem duas implementações da mesma pergunta — foi o motivo de
 * `recentesDoPublicador.js` existir, e vale igual aqui.
 *
 * @returns {'desconectado'|'atencao'|'ok'|null} `null` = não medido (sem
 *   `integracoes`): quem desenha NÃO desenha nada, porque um dot colorido é uma
 *   afirmação sobre a conexão e "não sabemos" não é "está tudo bem".
 */
export function estadoDaConexao(integracoes) {
    const estado = textoSeguro(integracoes?.mercado_livre?.estado, null);
    if (estado === null) return null;
    if (estado !== 'ativo') return 'desconectado';
    return portalPendente(integracoes) ? 'atencao' : 'ok';
}

/** Aparência da pill por estado — os 3 casos do protótipo (:216). */
export const PILL = {
    desconectado: {
        rotulo: 'Mercado Livre desconectado',
        classe: 'border-pub-danger/[0.45] bg-pub-danger/[0.10] text-pub-danger-txt',
    },
    atencao: {
        rotulo: 'Portal com novidades',
        classe: 'border-pub-warn/[0.45] bg-pub-warn/[0.10] text-pub-warn-txt',
    },
    ok: {
        rotulo: 'Conectado',
        classe: 'border-pub-line bg-transparent text-pub-txt-2',
    },
};

/**
 * Rótulo e tom do Mercado Livre.
 *
 * ⚠️ `sem_token` é "Falta reconectar", NÃO "sem conexão": o docblock de
 * `SeloConta.jsx` explica que essas contas autorizaram antes de 21/09/2026,
 * quando o callback descartava a credencial — autorizaram de verdade. Dizer
 * "sem conexão" mandaria o consultor pedir uma autorização do zero. O
 * vocabulário é o MESMO do selo que já está no ar (REG-01).
 */
function statusDoMercadoLivre(estado) {
    if (estado === 'ativo') return { rotulo: 'Conectado', tom: 'ok' };
    if (estado === 'expirado') return { rotulo: 'Token expirado', tom: 'erro' };
    if (estado === 'sem_token') return { rotulo: 'Falta reconectar', tom: 'erro' };
    // Estado AUSENTE não é estado ruim: sem o campo, nada é afirmado. Na prática
    // o popover nunca abre sem ele (a pill não existe — ver `estadoDaConexao`),
    // mas o painel não pode inventar um diagnóstico se for renderizado sozinho.
    return { rotulo: 'Não informado', tom: 'ausente' };
}

/** "Conta {nome} · {ml_user_id}" — cada campo por `textoUtil`: vazio NÃO vira "Conta ". */
function metaDoMercadoLivre(ml) {
    const nome = textoUtil(ml?.conta_nome);
    const id = textoUtil(ml?.conta_ml_id);
    if (nome !== null && id !== null) return `Conta ${nome} · ${id}`;
    if (nome !== null) return `Conta ${nome}`;
    if (id !== null) return `Conta ${id}`;
    return 'Conta não identificada';
}

/** Meta e status do Portal do Cliente, no vocabulário do `SeloPortal` (REG-01). */
function linhaDoPortal(portal) {
    const situacao = textoSeguro(portal?.situacao, null);
    const quantas = Number.isFinite(portal?.novas) ? portal.novas : null;

    if (situacao === 'sincronizado') {
        const quando = haQuanto(portal?.sincronizado_em);
        return { meta: quando ? `Tudo sincronizado ${quando}` : 'Tudo sincronizado', rotulo: 'Sincronizado' };
    }
    if (situacao === 'novas') {
        const meta = quantas === null
            ? 'Ofertas novas no Portal'
            : `${quantas} ${quantas === 1 ? 'oferta nova' : 'ofertas novas'}`;
        return { meta, rotulo: 'Ofertas novas' };
    }
    if (situacao === 'nunca') {
        const meta = quantas === null
            ? 'Ofertas nunca sincronizadas'
            : `${quantas} ${quantas === 1 ? 'oferta nunca sincronizada' : 'ofertas nunca sincronizadas'}`;
        return { meta, rotulo: 'Nunca sincronizado' };
    }
    return { meta: 'Sem ofertas no Portal', rotulo: 'Sem Portal' };
}

/**
 * As TRÊS linhas do popover, como DADO. O protótipo tem duas (ML e ERP); a
 * terceira é o Portal, porque a informação que o `SeloPortal` dá hoje não pode
 * se perder (HEAD-11) e porque é o único segundo sinal de conexão REAL que
 * existe.
 *
 * Export nomeado para o gate conferir o conteúdo sem renderizar — e é aqui que
 * a asserção negativa "o ERP nunca diz Conectado" morde.
 */
export function linhasDaConexao(integracoes) {
    const estadoMl = textoSeguro(integracoes?.mercado_livre?.estado, null);
    const statusMl = statusDoMercadoLivre(estadoMl);
    const erpDeclarado = textoUtil(integracoes?.erp?.declarado);
    const portal = linhaDoPortal(integracoes?.portal);

    return [
        {
            chave: 'mercado-livre',
            tile: 'ML',
            tileClasse: 'bg-pub-accent text-pub-page',
            nome: 'Mercado Livre',
            meta: metaDoMercadoLivre(integracoes?.mercado_livre),
            rotulo: statusMl.rotulo,
            tom: statusMl.tom,
            reconectar: textoUtil(integracoes?.mercado_livre?.link_reconexao) !== null,
        },
        {
            chave: 'erp',
            tile: 'ERP',
            tileClasse: 'bg-pub-line-subtle text-pub-fase1',
            nome: 'ERP',
            // ⚠️ "Declarado", NUNCA "Conectado": não existe integração para medir.
            meta: erpDeclarado === null ? 'Não informado' : `Declarado: ${erpDeclarado}`,
            rotulo: erpDeclarado === null ? 'Não informado' : 'Declarado',
            tom: erpDeclarado === null ? 'ausente' : 'neutro',
            reconectar: false,
        },
        {
            chave: 'portal',
            tile: 'PT',
            tileClasse: 'bg-pub-line-subtle text-pub-fase1',
            nome: 'Portal do Cliente',
            meta: portal.meta,
            rotulo: portal.rotulo,
            tom: tomDoPortal(integracoes),
            reconectar: false,
        },
    ];
}

/**
 * O corpo do popover de 320px: as três linhas e o rodapé da coleta.
 *
 * Componente puro (nenhum estado, nenhum efeito) justamente para o gate poder
 * renderizar de verdade — ver o aviso sobre portais no topo do arquivo.
 *
 * @param {object} props
 * @param {object|null} [props.integracoes]
 * @param {number|string|null} [props.companyId] `null` dispara D23
 * @param {boolean} [props.enfileirando] troca o rótulo do botão e liga o spinner
 * @param {Function} [props.aoAtualizar]
 */
export function PainelDeConexoes({ integracoes = null, companyId = null, enfileirando = false, aoAtualizar = () => {} }) {
    const coleta = integracoes?.coleta ?? null;
    const nuncaColetado = coleta?.nunca_coletado === true;
    const quandoColetou = haQuanto(coleta?.coletado_em);

    return (
        <>
            {linhasDaConexao(integracoes).map((linha, indice) => (
                // ⚠️ REND-04: toda flag nasce DENTRO deste callback (ou já vem
                // pronta no próprio item). O Rollup já eliminou variável de
                // escopo do componente lida só dentro de um `.map()` no bundle
                // de produção deste projeto.
                <div
                    key={linha.chave}
                    className={cn(
                        'flex items-center gap-[12px] px-[14px] py-[12px]',
                        indice > 0 && 'border-t border-pub-line-subtle',
                    )}
                >
                    <span className={cn('grid h-[30px] w-[30px] flex-none place-items-center rounded-[8px] text-[10px] font-bold', linha.tileClasse)}>
                        {linha.tile}
                    </span>
                    <span className="flex min-w-0 flex-1 flex-col gap-[2px]">
                        <span className="text-[13px] font-medium">{linha.nome}</span>
                        <span className="text-[11.5px] text-pub-txt-3">
                            {linha.meta}
                            {linha.reconectar && (
                                <>
                                    {' '}
                                    <Link
                                        href={route('mlb.anuncios.index')}
                                        title={AVISO_RECONEXAO}
                                        aria-label={AVISO_RECONEXAO}
                                        className="whitespace-nowrap font-medium text-pub-txt"
                                    >
                                        Reconectar →
                                    </Link>
                                </>
                            )}
                        </span>
                    </span>
                    <span className={cn('flex flex-none items-center gap-[6px] whitespace-nowrap text-[12px] font-medium', COR_DO_TEXTO[linha.tom])}>
                        <span aria-hidden="true" className={cn('h-[7px] w-[7px] rounded-full', COR_DO_DOT[linha.tom])} />
                        {linha.rotulo}
                    </span>
                </div>
            ))}

            {/* ─── Rodapé: a coleta (D-05/REG-06) ─── */}
            <div className="flex items-center justify-between border-t border-pub-line-subtle bg-pub-sunken px-[14px] py-[10px] text-[12px] text-pub-txt-3">
                {/* ⚠️ "nunca coletado" é frase própria, NUNCA "há 0 min":
                    "ninguém mediu" não é "medimos e deu zero". */}
                <span>
                    {nuncaColetado || quandoColetou === null
                        ? 'Acervo nunca coletado'
                        : `Última coleta ${quandoColetou}`}
                </span>

                {companyId === null ? (
                    <span
                        title={TITULO_SEM_COMPANY}
                        aria-label={TITULO_SEM_COMPANY}
                        className="whitespace-nowrap text-pub-txt-4"
                    >
                        {TITULO_SEM_COMPANY}
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={aoAtualizar}
                        disabled={enfileirando}
                        title="Enfileira a coleta do acervo desta conta — a tela mostra os dados novos na próxima visita."
                        className={cn(
                            'inline-flex items-center gap-[6px] whitespace-nowrap font-medium text-pub-txt',
                            enfileirando && 'cursor-wait opacity-60',
                        )}
                    >
                        {enfileirando && (
                            <span
                                aria-hidden="true"
                                className="h-[12px] w-[12px] flex-none animate-pub-spin rounded-full border-[1.5px] border-pub-line-off border-t-pub-accent"
                            />
                        )}
                        {enfileirando ? 'Enfileirando…' : 'Atualizar agora'}
                    </button>
                )}
            </div>
        </>
    );
}

/**
 * @param {object} props
 * @param {object|null} [props.integracoes] `cabecalho.integracoes` do servidor
 *   (plano 177-03). Pode ser `null` — e isso NÃO é caso raro: `cabecalho` chega
 *   nulo em 3 páginas. Sem estado medido, a pill simplesmente não é desenhada.
 * @param {number|string|null} [props.companyId] o id da Company. `null` dispara
 *   D23: "Atualizar agora" não existe e a linha explica por quê.
 */
export default function ConexaoDoHeader({ integracoes = null, companyId = null }) {
    const [aberto, setAberto] = useState(false);
    const [enfileirando, setEnfileirando] = useState(false);

    const estado = estadoDaConexao(integracoes);

    // Sem estado medido não há o que afirmar: a pill não aparece (nem cinza,
    // nem verde). "Não medimos" ≠ "medimos e está tudo bem".
    if (estado === null) return null;

    const estadoMl = textoSeguro(integracoes?.mercado_livre?.estado, null);
    const pill = PILL[estado];

    function atualizarAgora() {
        if (companyId === null) return;
        // ⚠️ ENFILEIRA (D-05/REG-06). `router.post` na rota que despacha
        // `SyncMlAcervoCompanyJob`. Nunca `put/delete/patch`, nunca uma chamada
        // HTTP ao Mercado Livre daqui.
        router.post(route('mlb.anuncios.meus.atualizar', { company: companyId }), {}, {
            preserveScroll: true,
            onStart: () => setEnfileirando(true),
            onFinish: () => setEnfileirando(false),
        });
    }

    return (
        <Popover.Root open={aberto} onOpenChange={setAberto}>
            <Popover.Trigger asChild>
                <button
                    type="button"
                    title={`Conexões da conta — ${pill.rotulo}`}
                    aria-label={`Conexões da conta — ${pill.rotulo}`}
                    className={cn(
                        'inline-flex h-[28px] flex-none items-center gap-2 whitespace-nowrap rounded-full border pl-[8px] pr-[10px] text-[12px] font-medium hover:border-pub-line-off',
                        pill.classe,
                    )}
                >
                    <span className="flex flex-none gap-[3px]">
                        <span
                            title="Mercado Livre"
                            aria-label="Mercado Livre"
                            className={cn('h-[7px] w-[7px] rounded-full', estadoMl === 'ativo' ? 'bg-pub-ok' : 'bg-pub-danger')}
                        />
                        <span
                            title="Portal do Cliente"
                            aria-label="Portal do Cliente"
                            className={cn('h-[7px] w-[7px] rounded-full', COR_DO_DOT[tomDoPortal(integracoes)])}
                        />
                    </span>
                    {pill.rotulo}
                </button>
            </Popover.Trigger>

            <Popover.Portal>
                <Popover.Content
                    align="start"
                    sideOffset={8}
                    aria-label="Conexões da conta"
                    className="z-[61] w-[320px] overflow-hidden rounded-pub-panel border border-pub-line-strong bg-pub-surface font-pub text-pub-txt shadow-pub-popover"
                >
                    <PainelDeConexoes
                        integracoes={integracoes}
                        companyId={companyId}
                        enfileirando={enfileirando}
                        aoAtualizar={atualizarAgora}
                    />
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
