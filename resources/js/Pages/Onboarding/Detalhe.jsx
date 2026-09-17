import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity, ArrowLeft, ExternalLink, FileText, Handshake, KeyRound,
    Link2, MoreHorizontal, Quote,
} from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import CabecalhoOnboarding from '@/Components/Onboarding/Painel/CabecalhoOnboarding';
import ProximaAcaoDestaque from '@/Components/Onboarding/Painel/ProximaAcaoDestaque';
import ChecklistPorEtapa from '@/Components/Onboarding/Painel/ChecklistPorEtapa';
import { LinhaPasso } from '@/Components/Onboarding/Painel/DetalheOnboarding';
import { DiagnosticoDaConta, ResumoDoCliente } from '@/Components/Onboarding/Painel/LateralDaFicha';
import AgendaCompacta from '@/Components/Onboarding/Painel/AgendaCompacta';
import Responsabilidades from '@/Components/Onboarding/Painel/Responsabilidades';
import AtividadeRecente from '@/Components/Onboarding/Painel/AtividadeRecente';
import RelatorioInicial from '@/Components/Onboarding/RelatorioInicial';
import ConviteGoogle from '@/Components/Onboarding/Painel/ConviteGoogle';
import AcessoDoClienteAoPortal from '@/Components/Onboarding/Painel/AcessoDoClienteAoPortal';
import BlocoAcessos from '@/Components/Onboarding/Painel/BlocoAcessos';
import ContextoDaVenda from '@/Components/Onboarding/Painel/ContextoDaVenda';
import BlocoInvestimento from '@/Components/Onboarding/Painel/BlocoInvestimento';
import BlocoContatos from '@/Components/Onboarding/Painel/BlocoContatos';
import BlocoAgenda from '@/Components/Onboarding/Painel/BlocoAgenda';
import MapeamentoInicial from '@/Components/Onboarding/MapeamentoInicial';
import FotografiaDaConta from '@/Components/Onboarding/FotografiaDaConta';

/**
 * Onboarding/Detalhe — a FICHA interna de um onboarding.
 *
 * ### O que esta tela virou em 14/09
 * Desde que o onboarding passou a ser conduzido pelo Portal do Cliente — em
 * reunião, com a tela compartilhada —, esta página deixou de ser o lugar onde
 * o trabalho acontece. O negócio a descreveu como "muito feio" e perguntou se
 * não era melhor apagá-la.
 *
 * Ela não foi apagada por um motivo concreto: **dez dos dezoito passos da
 * régua não estão no portal**. São os internos — reunião realizada, os itens
 * que só o Analista preenche, os três da ADMAN que o negócio ainda vai
 * definir. Apagar esta tela deixaria esses dez sem nenhum lugar onde serem
 * fechados.
 *
 * Então ela mudou de função, não de existência:
 *
 *  - **lê-se aqui**: em que pé está, o que trava, o que a conta mostra, o que
 *    já foi respondido — inclusive o que o cliente respondeu no portal;
 *  - **opera-se no portal**: o botão "Abrir o portal do cliente" é a ação
 *    principal do topo, e cada item conduzido lá leva a marca de corrente;
 *  - **o que só existe aqui** (os dez internos, contatos, agenda,
 *    investimento, acessos) continua editável — a um clique de distância.
 *
 * ### Por que os formulários saíram da lista
 * A versão anterior empilhava, na mesma coluna estreita, 18 passos com quatro
 * selos cada e seis formulários abertos. Cada bloco era defensável sozinho; o
 * conjunto era uma coluna de dois metros. Agora a tela usa a largura toda:
 * checklist à esquerda em cartões por etapa, respostas à direita em cartões de
 * leitura, e todo formulário abre em modal — por cima, sem navegar, que era a
 * razão de eles estarem na mesma tela desde 19/08.
 *
 * ### O que continua fora de qualquer etapa
 * Portal do cliente, contexto da venda, de quem é a bola, atividade recente e
 * relatório inicial. São consultas: lidas uma vez, e empilhadas custavam
 * rolagem em toda visita. Foram para o menu "⋯" do topo.
 */
export default function Detalhe({
    onboarding,
    passos,
    relatorio = null,
    reuniao = null,
    link = null,
    mapeamento = null,
    fotografia = null,
    respostas = null,
    acessos = null,
    proxima_acao = null,
    responsabilidades = null,
    linha_do_tempo = [],
    atividade = [],
}) {
    // Qual caixa está aberta (`null` = nenhuma). Um estado só: duas abertas ao
    // mesmo tempo não faria sentido nenhum.
    const [caixa, setCaixa] = useState(null);

    // O passo aberto no detalhe. Separado de `caixa` porque carrega o objeto
    // inteiro, não uma chave.
    const [passoAberto, setPassoAberto] = useState(null);

    // O passo vive no payload; guardar o objeto congelaria o estado dele no
    // momento do clique — depois de concluir, o modal seguiria mostrando
    // "aberto" até fechar e reabrir.
    const passoAtual = passoAberto
        ? passos.find((p) => p.id === passoAberto) ?? null
        : null;

    const consultas = [
        { chave: 'portal',       rotulo: 'Portal do cliente',      icone: Link2 },
        { chave: 'acessos',      rotulo: 'Acessos que o cliente vê', icone: KeyRound, oculto: ! acessos },
        { chave: 'relatorio',    rotulo: 'Relatório inicial',      icone: FileText, oculto: ! relatorio },
        { chave: 'contexto',     rotulo: 'Contexto da venda',      icone: Quote },
        { chave: 'responsaveis', rotulo: 'De quem é a bola',       icone: Handshake },
        { chave: 'atividade',    rotulo: 'Atividade recente',      icone: Activity },
    ].filter((c) => ! c.oculto);

    const TITULOS = {
        resumo:       'Resumo do cliente',
        rotina:       'Rotina de reuniões',
        mapeamento:   'Mapeamento da conta',
        portal:       'Portal do cliente',
        acessos:      'Acessos que o cliente vê',
        relatorio:    'Relatório inicial',
        contexto:     'Contexto da venda',
        responsaveis: 'De quem é a bola',
        atividade:    'Atividade recente',
    };

    return (
        <AppLayout title="Detalhe do onboarding">
            <Head title={`Onboarding — ${onboarding.empresa.nome}`} />

            <div className="space-y-4">
                {/* ─── Barra de identificação e ações ───────────────────── */}
                <div className="flex items-center justify-between gap-3 flex-wrap">
                    {/* Volta para o cockpit (aba Onboarding de /companies), que
                        é a lista de onde se chega aqui desde 20/08. O painel
                        antigo em `/onboarding` continua existindo, mas não é
                        mais o caminho de ida. */}
                    <Link
                        href={route('companies.index', { tab: 'onboarding' })}
                        className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-ecf-yellow transition-colors"
                    >
                        <ArrowLeft size={13} /> Voltar aos onboardings
                    </Link>

                    <div className="flex items-center gap-2">
                        {/* A ação principal da tela desde que o onboarding
                            passou a ser conduzido pelo portal. Abre no nome de
                            quem clicou, e fica registrado. */}
                        {link?.pode_entrar && (
                            <a
                                href={route('companies.portal.abrir', onboarding.empresa.id)}
                                target="_blank"
                                rel="noopener"
                                className="inline-flex items-center gap-1.5 rounded-xl bg-ecf-yellow px-3.5 py-2 text-[12.5px] font-semibold text-ecf-bg hover:bg-ecf-yellow/90 transition-colors"
                                title="Abre o portal desta empresa no seu nome — é por lá que se conduz a reunião"
                            >
                                <ExternalLink size={13} /> Abrir o portal do cliente
                            </a>
                        )}

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    className="grid place-items-center h-9 w-9 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white/55 hover:text-white hover:border-white/20 transition-colors"
                                    aria-label="Mais informações deste onboarding"
                                >
                                    <MoreHorizontal size={16} />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                {consultas.map(({ chave, rotulo, icone: Icone }) => (
                                    <DropdownMenuItem
                                        key={chave}
                                        onSelect={() => setCaixa(chave)}
                                        className="gap-2 text-[13px]"
                                    >
                                        <Icone size={14} className="text-white/40" /> {rotulo}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <CabecalhoOnboarding onboarding={onboarding} linhaDoTempo={linha_do_tempo} />

                <ProximaAcaoDestaque
                    situacao={onboarding.situacao}
                    situacaoLabel={onboarding.situacao_label}
                    passo={proxima_acao}
                    ultimoAcessoCliente={link?.ultimo_acesso ?? null}
                    aoVerPendencia={(passo) => setPassoAberto(passo.id)}
                />

                {/* ─── O corpo: trabalho à esquerda, respostas à direita ── */}
                <div className="grid gap-4 xl:grid-cols-3 items-start">
                    <div className="space-y-4 xl:col-span-2">
                        <ChecklistPorEtapa passos={passos} aoAbrirPasso={(p) => setPassoAberto(p.id)} />

                        {/* A Agenda de verdade (16/09/2026): mini calendário,
                            próximos eventos e o "Agendar" em drawer. Desde
                            17/09 fica embaixo do checklist, na coluna larga —
                            na da direita ela espremia e deixava o vazio aqui. */}
                        <AgendaCompacta
                            onboarding={onboarding}
                            reuniao={reuniao}
                            rotina={respostas?.agenda}
                            aoAjustarRotina={() => setCaixa('rotina')}
                        />
                    </div>

                    <div className="space-y-4">
                        <ResumoDoCliente
                            onboarding={onboarding}
                            mapeamento={mapeamento}
                            contatos={respostas?.contatos ?? []}
                            investimento={respostas?.investimento}
                            aoEditar={() => setCaixa('resumo')}
                        />

                        {mapeamento && (
                            <DiagnosticoDaConta
                                mapeamento={mapeamento}
                                aoAbrir={() => setCaixa('mapeamento')}
                            />
                        )}
                    </div>
                </div>

                {/* O mesmo retrato que o cliente vê no portal. Largura cheia
                    porque é um gráfico: espremido numa coluna de um terço, as
                    13 semanas viram borrão. */}
                <FotografiaDaConta
                    fotografia={fotografia}
                    ehEquipe
                    rota={route('onboarding.fotografia.coletar', onboarding.id)}
                />
            </div>

            {/* ─── O passo, em detalhe ──────────────────────────────────── */}
            <Dialog open={passoAtual !== null} onOpenChange={(aberto) => ! aberto && setPassoAberto(null)}>
                <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Passo do onboarding</DialogTitle>
                    </DialogHeader>

                    {passoAtual && (
                        <>
                            <LinhaPasso
                                passo={passoAtual}
                                onboardingId={onboarding.id}
                                confirmacao={respostas?.confirmacoes?.[passoAtual.chave]}
                            />

                            {passoAtual.no_portal && (
                                <p className="flex items-start gap-1.5 text-[12px] text-white/40 leading-relaxed">
                                    <Link2 size={13} className="shrink-0 mt-0.5" />
                                    Este item é conduzido no portal do cliente, com ele na chamada. Dá
                                    para resolver aqui também — o registro é o mesmo.
                                </p>
                            )}
                        </>
                    )}
                </DialogContent>
            </Dialog>

            {/* ─── As caixas: formulários e consultas ───────────────────── */}
            <Dialog open={caixa !== null} onOpenChange={(aberto) => ! aberto && setCaixa(null)}>
                <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{TITULOS[caixa] ?? ''}</DialogTitle>
                    </DialogHeader>

                    {caixa === 'resumo' && (
                        <div className="space-y-4">
                            <BlocoContatos
                                onboardingId={onboarding.id}
                                contatos={respostas?.contatos ?? []}
                            />
                            <BlocoInvestimento
                                onboardingId={onboarding.id}
                                investimento={respostas?.investimento}
                            />
                        </div>
                    )}

                    {caixa === 'rotina' && (
                        <div className="space-y-4">
                            {/* A reunião de onboarding e os eventos avulsos são
                                marcados pelo "Agendar" da Agenda. Aqui fica a
                                rotina: dia, horário e periodicidade — e o
                                convite em série que nasce deles. */}
                            <BlocoAgenda onboardingId={onboarding.id} agenda={respostas?.agenda} />
                            <ConviteGoogle onboardingId={onboarding.id} tipos={['recorrente']} />
                        </div>
                    )}

                    {caixa === 'mapeamento' && mapeamento && (
                        <MapeamentoInicial
                            mapeamento={mapeamento}
                            contexto="interno"
                            rotaSincronizar={route('onboarding.mapeamento.sincronizar', onboarding.id)}
                            rotaConfirmar={route('onboarding.mapeamento.confirmar', onboarding.id)}
                        />
                    )}

                    {caixa === 'portal' && (
                        <AcessoDoClienteAoPortal companyId={onboarding.empresa.id} link={link} />
                    )}

                    {caixa === 'acessos' && acessos && (
                        <BlocoAcessos
                            rota={route('onboarding.acessos.empresa', onboarding.id)}
                            valores={acessos}
                            titulo="Acessos que o cliente vê"
                            ajuda="Link do App ECF e e-mail para o convite, desta empresa."
                        />
                    )}

                    {caixa === 'relatorio' && relatorio && (
                        <RelatorioInicial onboardingId={onboarding.id} relatorio={relatorio} />
                    )}

                    {caixa === 'contexto' && (
                        <ContextoDaVenda spin={onboarding.spin} contexto={onboarding.contexto} />
                    )}

                    {caixa === 'responsaveis' && (
                        <Responsabilidades responsabilidades={responsabilidades} />
                    )}

                    {caixa === 'atividade' && <AtividadeRecente atividade={atividade} />}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
