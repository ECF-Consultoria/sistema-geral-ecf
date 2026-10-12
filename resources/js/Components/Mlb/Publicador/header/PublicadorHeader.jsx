import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Plus, Search, Settings } from 'lucide-react';
import { cn } from '@/lib/utils';
import AvisoContaTravada from '../AvisoContaTravada';
import { textoUtil } from '../BarraDaConta';
import GlyphDaFase from '../identidade/GlyphDaFase';
import AbasDoHeader from './AbasDoHeader';
import ConexaoDoHeader from './ConexaoDoHeader';
import ModalNovaPublicacao from './ModalNovaPublicacao';
import SeletorDeEmpresaDoHeader from './SeletorDeEmpresaDoHeader';
import { useAtalhosDoHeader } from './atalhosDoHeader';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 04 — a CASCA do PublicadorHeader global (HEAD-01/02/03/08).
//
// O QUE ESTA PEÇA SUBSTITUI: quando as 9 páginas trocarem (planos 177-07/08),
// este header toma o lugar de `BarraDaConta` (trilha + nome + selos + "Trocar
// empresa" + slot de ações) e de `AbasDaConta` (as 5 abas) — num só bloco
// sticky no topo do `<main>`, com a geometria do protótipo
// (`new_layout_publicador/PublicadorHeader.dc.html`).
//
// ⚠️ POR QUE `-mx-6 -mt-6` (HEAD-14): o `<main>` do `AppLayout` tem `p-6`
// (24px) e o header precisa encostar nas bordas para cumprir FID-05 (container
// `min-width:1240px`, conteúdo `max-width:1600px`, `padding: 0 36px`). A margem
// negativa resolve isso NO PRÓPRIO header, sem prop nova e sem alterar uma
// única linha do `AppLayout` (D2). O `sticky top-0` continua valendo porque
// quem rola é o `<main>` (`overflow-y-auto`), não a janela.
//
// O MODAL "NOVA PUBLICAÇÃO" (Fase 178, plano 03) JÁ ESTÁ AQUI: é
// `ModalNovaPublicacao`, montado no fim do `<header>`. ⚠️ O lugar do JSX só
// define quem é o pai React — o conteúdo é PORTADO para o `document.body` por
// `Dialog.Portal`, e tem de ser: o `backdrop-filter` desta casca cria
// containing block para `position: fixed`, então um overlay escrito aqui
// dentro nasceria recortado na faixa de 109px do topo. O porquê completo, a
// geometria (620px a `top:110px`) e as divergências estão no topo de
// `ModalNovaPublicacao.jsx`.
//
// JÁ ENTREGUE: as abas-pílula da linha 2 vêm de `AbasDoHeader` (plano 177-05)
// — são QUATRO de propósito, "Desempenho" fica FORA por decisão do usuário
// (HEAD-09) e o porquê está escrito no topo daquele arquivo. O seletor de
// empresa com o dropdown de 360px e a pill de conexão com o popover de 320px
// vêm de `SeletorDeEmpresaDoHeader` e `ConexaoDoHeader` (plano 177-06).
//
// ⚠️⚠️ A CONTA DO `strokeWidth` DOS ÍCONES (correção de fidelidade, 12/10/2026)
//
// Os SVG do protótipo usam `viewBox="0 0 16 16"`; o Lucide usa `0 0 24 24`. O
// `stroke-width` é medido NO SISTEMA DE COORDENADAS do viewBox, então o MESMO
// número não dá o mesmo traço na tela:
//     protótipo: 1.6 × (15/16) = 1,5 px  ·  Lucide: 1.6 × (15/24) = 1,0 px
// Repetir o número do protótipo deixava os ícones com DOIS TERÇOS da espessura
// desenhada. O fator é 24/16 = 1,5 e vale para TODO ícone do Lucide neste
// header: 1.4 → 2.1 · 1.6 → 2.4 · 1.7 → 2.55 · 2 → 3.
//
// ⚠️ O separador "/" abaixo NÃO entra na conta: ele é SVG inline com o viewBox
// do próprio protótipo (16×22), então o 1.4 dele já é o traço certo.
//
// ⚠️ O CTA NÃO NASCE MORTO (EXMP-03): "Nova publicação", a busca de 250px, o
// ⌘K/Ctrl+K e o "N" fora de campo de digitação abrem o MESMO modal — uma
// função (`abrirNovaPublicacao`), três chamadores. Sem conta usável os dois
// botões seguem `disabled` com `title` explicando, e o modal não abre: um
// clique que não faz nada e não explica é o que EXMP-03 proíbe.
//
// DIVERGÊNCIAS DECLARADAS (FID-02 — cada uma com restrição concreta):
//   1. Props: o protótipo recebe `active`, `mlConnected` e `erpConnected`
//      porque é mock. Aqui a MESMA informação chega dentro de `cabecalho`
//      (objeto do servidor, plano 177-03); `aba` continua prop própria porque
//      é a página que sabe em que aba está. Forma diferente, informação igual.
//   2. Borda da busca e do separador vertical: o protótipo usa #222222, que
//      NÃO está na tabela de tokens do README. TOK-15 proíbe hex literal fora
//      da camada, então vale `border-pub-line` (#262626) — 4 unidades de
//      luminância numa borda de 1px. Para o valor exato, o caminho é
//      acrescentar o token na camada, não escrever o hex na tela.
//   3. O separador "/" segue SVG inline: o Lucide 1.11 não tem barra inclinada
//      fina de separação (exceção nominal a TOK-14, registrada).
//   4. Slot `acoes`: não existe no protótipo. Entra no fim da linha 2, à
//      direita — a linha 1 fica byte a byte com o protótipo e a ação é de
//      página, como as abas (HEAD-11 exige que o slot sobreviva).
//   5. `AvisoContaTravada` no header: não existe no protótipo; reusado como
//      está, SEM redesenho (HEAD-11 exige a capacidade; redesenhá-lo seria
//      escopo inventado).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Por que os dois botões podem ficar desabilitados: `cabecalho` chega NULO nas
 * três páginas do assistente antigo (massa/histórico/meus), quando a Company
 * não cai em nenhum programa do Publicador. Sem conta não existe destino, e um
 * clique que não faz nada e não explica é o que EXMP-03 proíbe.
 */
const TITULO_SEM_CONTA = 'Abra uma empresa do Publicador para começar uma nova publicação — esta tela está fora do fluxo por empresa.';

/** `title` e `aria-label` do ícone de Configurações são SEMPRE o mesmo texto. */
const ROTULO_CONFIGURACOES = 'Configurações da empresa';
const ROTULO_CONFIGURACOES_SEM_CONTA = `${ROTULO_CONFIGURACOES} — ${TITULO_SEM_CONTA}`;

/**
 * @param {object} props
 * @param {'visao-geral'|'produtos'|'publicacoes'|'alavancas'|'configuracoes'} [props.aba]
 *   a aba ativa — consumida por `AbasDoHeader` na linha 2. `'configuracoes'`
 *   continua sendo valor válido (é o ícone da linha 1, HEAD-08) e nesse caso
 *   nenhuma das quatro abas fica marcada como ativa.
 * @param {object|null} [props.cabecalho] a prop do servidor (plano 177-03).
 *   Pode ser `null` — e isso não é caso raro.
 * @param {React.ReactNode} [props.acoes] ações da página (Sincronizar, "+
 *   Produto", "Atualizar agora", voltar aos Produtos).
 * @param {boolean} [props.liberada] conta liberada para publicar (D21/D26).
 */
export default function PublicadorHeader({ aba, cabecalho = null, acoes = null, liberada = true }) {
    // `textoUtil` em vez de `String(...)`: a chave vai para `route()` como
    // parâmetro de URL, e um objeto vindo no lugar dela montaria um destino
    // inventado. Sem chave usável, `conta` é null e os dois botões ficam
    // desabilitados com explicação.
    // ⚠️ `textoUtil` e não `textoSeguro(..., null)` (plano 06): `textoSeguro`
    // devolve `''` para string vazia, e `''` passa por todo `!== null` — daria
    // `route('…', { conta: '' })`, um destino inventado do mesmo jeito.
    const conta = textoUtil(cabecalho?.empresa?.chave);
    const temConta = conta !== null;

    // O usuário logado, só para o dropdown ler as contas recentes do
    // `localStorage` (a chave é por usuário). Vem de `usePage()` AQUI DENTRO,
    // como `AnunciosEmpresas.jsx` já faz — nenhuma prop nova nas 9 páginas.
    const userId = usePage().props?.auth?.user?.id ?? null;

    // O único estado de abertura que mora neste arquivo (plano 178-03). ⚠️ NÃO
    // PROCURE OS OUTROS: o dropdown de empresa e o popover de conexão são dois
    // `Popover` do Radix e cada um cuida da própria abertura (177-06).
    const [novaPublicacaoAberta, setNovaPublicacaoAberta] = useState(false);

    // ⚠️ A ASSINATURA E OS CHAMADORES NÃO MUDARAM na Fase 178 — só o corpo, que
    // é o que o plano 177-04 deixou escrito. Esta função serve TRÊS chamadores:
    // a busca de 250px, o CTA amarelo e o hook de atalhos (⌘K/Ctrl+K e "N").
    // Sem conta não há destino nem modal (EXMP-03).
    function abrirNovaPublicacao() {
        if (!temConta) return;
        setNovaPublicacaoAberta(true);
    }

    // O Esc do hook de atalhos (HEAD-13) fecha o modal por aqui.
    //
    // ⚠️ ESC FECHA POR DOIS CAMINHOS, E ISSO NÃO É DEFEITO: o `DismissableLayer`
    // do Radix já trata `onEscapeKeyDown` dentro da `Dialog.Content`, e o
    // ouvinte de `window` do hook chama esta função. `setNovaPublicacaoAberta
    // (false)` é idempotente, então a redundância é segura — e é de propósito,
    // porque o foco pode estar fora da caixa (o portal convive com o resto da
    // página). NÃO "limpe" nenhum dos dois caminhos.
    function fecharTudo() {
        setNovaPublicacaoAberta(false);
    }

    useAtalhosDoHeader({ aoAbrirBusca: abrirNovaPublicacao, aoFechar: fecharTudo });

    return (
        <header className="sticky top-0 z-50 -mx-6 -mt-6 min-w-[1240px] border-b border-pub-line-subtle-2 bg-pub-page/[0.86] font-pub text-pub-txt backdrop-blur-[14px] backdrop-saturate-[1.4]">
            <div className="mx-auto max-w-[1600px] px-[36px]">
                {/* ─── Linha 1 (60px) ─── */}
                <div className="flex h-[60px] items-center justify-between gap-5">
                    <div className="flex min-w-0 items-center gap-[10px]">
                        <Link
                            href={route('mlb.anuncios.index')}
                            title="Publicador de Anúncios"
                            className="inline-flex h-[34px] flex-none items-center gap-[9px] whitespace-nowrap rounded-pub-control pl-[4px] pr-[10px] hover:bg-pub-hover"
                        >
                            <GlyphDaFase fase={2} tamanho="marca" />
                            <span className="text-[14px] font-semibold tracking-[-0.01em]">Publicador</span>
                        </Link>

                        {/* O "/" do protótipo (PublicadorHeader.dc.html:32): 16×22,
                            traço de 1,4px arredondado. Decorativo. */}
                        <svg
                            width="16"
                            height="22"
                            viewBox="0 0 16 22"
                            fill="none"
                            strokeWidth={1.4}
                            strokeLinecap="round"
                            aria-hidden="true"
                            className="flex-none stroke-pub-seg-ring"
                        >
                            <path d="M11 3L5 19" />
                        </svg>

                        {/* Identidade da conta: o seletor (tile de 28px + nome +
                            código + dropdown de 360px) e a pill de conexão com o
                            popover de 320px (plano 177-06).
                            ⚠️ `?? null` em TODOS os acessos: o default de parâmetro
                            só cobre `undefined`, e `cabecalho: null` numa recarga
                            parcial do Inertia já derrubou tela neste projeto. */}
                        <SeletorDeEmpresaDoHeader
                            empresa={cabecalho?.empresa ?? null}
                            integracoes={cabecalho?.integracoes ?? null}
                            userId={userId}
                        />
                        <ConexaoDoHeader
                            integracoes={cabecalho?.integracoes ?? null}
                            companyId={cabecalho?.company_id ?? null}
                        />

                        {!liberada && <AvisoContaTravada variante="selo" />}
                    </div>

                    {/* ─── Lado direito: busca → Configurações → separador → CTA ─── */}
                    <div className="flex flex-none items-center gap-2">
                        <button
                            type="button"
                            onClick={abrirNovaPublicacao}
                            disabled={!temConta}
                            title={temConta ? undefined : TITULO_SEM_CONTA}
                            className={cn(
                                'flex h-[34px] w-[250px] items-center gap-[10px] whitespace-nowrap rounded-pub-control border border-pub-line bg-pub-surface pl-[12px] pr-[6px] text-left text-[13px] text-pub-txt-3',
                                temConta
                                    ? 'hover:border-pub-line-off hover:text-pub-fase1'
                                    : 'cursor-not-allowed opacity-50',
                            )}
                        >
                            <Search size={14} strokeWidth={2.4} aria-hidden="true" className="flex-none" />
                            <span className="flex-1 text-left">Buscar produto ou MLB</span>
                            <span className="flex flex-none gap-[3px]">
                                <span className="grid h-[20px] min-w-[20px] place-items-center rounded-[5px] border border-pub-line-strong bg-pub-hover-2 font-pub-mono text-[10.5px] text-pub-txt-2">⌘</span>
                                <span className="grid h-[20px] min-w-[20px] place-items-center rounded-[5px] border border-pub-line-strong bg-pub-hover-2 font-pub-mono text-[10.5px] text-pub-txt-2">K</span>
                            </span>
                        </button>

                        {/* Configurações SAI das abas e vira ícone aqui (HEAD-08).
                            Sem conta, nunca um link para `undefined`: um <span>
                            desabilitado que diz por que não dá. */}
                        {temConta ? (
                            <Link
                                href={route('mlb.anuncios.publicador.configuracoes', { conta })}
                                title={ROTULO_CONFIGURACOES}
                                aria-label={ROTULO_CONFIGURACOES}
                                className="grid h-[34px] w-[34px] flex-none place-items-center rounded-pub-control text-pub-txt-3 hover:bg-pub-hover-2 hover:text-pub-txt"
                            >
                                <Settings size={17} strokeWidth={2.1} aria-hidden="true" />
                            </Link>
                        ) : (
                            <span
                                aria-disabled="true"
                                title={ROTULO_CONFIGURACOES_SEM_CONTA}
                                aria-label={ROTULO_CONFIGURACOES_SEM_CONTA}
                                className="grid h-[34px] w-[34px] flex-none cursor-not-allowed place-items-center rounded-pub-control text-pub-txt-off opacity-50"
                            >
                                <Settings size={17} strokeWidth={2.1} aria-hidden="true" />
                            </span>
                        )}

                        <span className="mx-[4px] h-[20px] w-px flex-none bg-pub-line" aria-hidden="true" />

                        <button
                            type="button"
                            onClick={abrirNovaPublicacao}
                            disabled={!temConta}
                            title={temConta ? undefined : TITULO_SEM_CONTA}
                            className={cn(
                                'inline-flex h-[34px] flex-none items-center gap-[7px] whitespace-nowrap rounded-pub-control bg-pub-accent pl-[12px] pr-[14px] text-[13px] font-semibold text-pub-page shadow-pub-cta',
                                temConta ? 'hover:bg-pub-accent-hover' : 'cursor-not-allowed opacity-50',
                            )}
                        >
                            <Plus size={14} strokeWidth={3} aria-hidden="true" className="flex-none" />
                            Nova publicação
                            <span className="ml-[2px] rounded-pub-badge bg-pub-page/[0.14] px-[5px] py-px font-pub-mono text-[10.5px]">N</span>
                        </button>
                    </div>
                </div>

                {/* ─── Linha 2 (nav, 48px) ─── */}
                <nav aria-label="Abas da conta" className="flex h-[48px] items-center justify-between gap-[2px]">
                    {/* As QUATRO abas-pílula (plano 177-05). São quatro de
                        propósito: "Desempenho" não entra (HEAD-09) — o porquê
                        está escrito em `AbasDoHeader.jsx`.
                        ⚠️ `?? null` em TODOS os acessos: o default de parâmetro
                        só cobre `undefined`, e `cabecalho: null` numa recarga
                        parcial do Inertia já derrubou tela neste projeto. */}
                    <AbasDoHeader
                        aba={aba}
                        conta={conta}
                        companyId={cabecalho?.company_id ?? null}
                        contadores={cabecalho?.contadores ?? null}
                    />

                    {acoes !== null && <div className="flex flex-none items-center gap-2">{acoes}</div>}
                </nav>
            </div>

            {/* ─── O modal "Nova publicação" de 620px (plano 178-03) ───
                ⚠️ O conteúdo é PORTADO para o `document.body`: o lugar deste
                JSX só define quem é o pai React, não onde os pixels caem. Com
                `aberto=false` o Radix não renderiza nada. */}
            <ModalNovaPublicacao
                aberto={novaPublicacaoAberta}
                aoFechar={() => setNovaPublicacaoAberta(false)}
                conta={conta}
            />
        </header>
    );
}
