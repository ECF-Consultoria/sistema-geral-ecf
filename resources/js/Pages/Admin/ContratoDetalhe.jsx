import { Fragment, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Building2, AlertTriangle, Send, UserCog, Ban, RefreshCcw, ChevronDown, ChevronRight } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';
import { classeContrato, rotuloContrato, formatarHaDias } from '@/lib/contratoStatus';

/**
 * Admin/ContratoDetalhe.jsx — Fase 131 Planos 04 (D-01/D-03/D-11, ADM-01/02,
 * UI-02) e 05 (CLICK-07/CLICK-09/CLICK-10, D-05/D-13/D-14, UI-04/UI-06).
 *
 * A tela de detalhe da empresa (D-01): página cheia (não painel lateral, não
 * edição inline) onde o Administrativo completa o que o Comercial deixou
 * pela metade (ADM-01/ADM-02), dispara a geração do contrato (UI-02) e — o
 * que este plano acrescenta — age sobre um contrato já enviado exatamente
 * como a API da Clicksign permite, nem mais, nem menos.
 *
 * ⛔ O que esta tela NÃO oferece, e por quê (medido em 2026-08-14,
 * `CLICKSIGN-SANDBOX-EMPIRICO.md` §15): não existe correção de e-mail de
 * quem assina (PATCH/PUT devolvem 404) e não existe cancelamento do que já
 * foi enviado pela API (DELETE→403, POST/cancel→404, PATCH status→400).
 * "Ajustar" abre direto a explicação (RAMO B, D-14) e
 * "Registrar cancelamento" REGISTRA autor+motivo+data e instrui a concluir
 * no painel — nunca promete cancelar de fato (D-13).
 */
export default function ContratoDetalhe({
    company,
    contratos_servico = [],
    faltantes = [],
    email_colaborador_pendente = false,
    configuracao_ecf_faltante = [],
    pode_gerar_contrato = false,
    motivo_bloqueio = null,
    painel_clicksign_url = null,
    contratos = [],
}) {
    const { flash } = usePage().props;

    const cadastroForm = useForm({
        cnpj:               company.cnpj ?? '',
        email_cliente:      company.email_cliente ?? '',
        nome_contato:       company.nome_contato ?? '',
        email_colaborador:  company.email_colaborador ?? '',
        contratos_servico:  contratos_servico.map((cs) => ({
            id:                cs.id,
            data_contratacao:  cs.data_contratacao ?? '',
            data_vencimento:   cs.data_vencimento ?? '',
        })),
    });

    const salvarCadastro = (e) => {
        e.preventDefault();
        cadastroForm.patch(route('admin.contratos.cadastro', company.id), { preserveScroll: true });
    };

    const atualizarDataServico = (id, campo, valor) => {
        cadastroForm.setData(
            'contratos_servico',
            cadastroForm.data.contratos_servico.map((cs) => (cs.id === id ? { ...cs, [campo]: valor } : cs)),
        );
    };

    const gerarForm = useForm({});
    const gerarContrato = () => {
        gerarForm.post(route('admin.contratos.gerar', company.id), { preserveScroll: true });
    };

    // Texto ao lado do botão desabilitado quando NÃO é falta de dado mínimo
    // (esses já aparecem na lista de `faltantes`) — sem jargão.
    const MOTIVO_BLOQUEIO_TEXTO = {
        ja_em_andamento:      'Já existe um contrato em andamento para um dos serviços desta empresa.',
        aguardando_comercial: 'Ainda há uma pendência comercial nesta empresa antes de gerar o contrato.',
        isento:               'Nenhum serviço desta empresa passa por contrato.',
    };

    // ─── CLICK-07 — Reenviar aviso ──────────────────────────────────────
    // "Por alguns segundos" após o clique — evita repetir o 429 em sequência
    // (UI-SPEC). Sem contagem regressiva exata exigida.
    const [signatarioReenviando, setSignatarioReenviando] = useState(null);
    const [signatariosRecemReenviados, setSignatariosRecemReenviados] = useState(() => new Set());

    function reenviarAviso(contratoId, signatarioId) {
        setSignatarioReenviando(signatarioId);
        router.post(route('admin.contratos.reenviar', [contratoId, signatarioId]), {}, {
            preserveScroll: true,
            onFinish: () => {
                setSignatarioReenviando(null);
                setSignatariosRecemReenviados((atual) => new Set(atual).add(signatarioId));
                setTimeout(() => {
                    setSignatariosRecemReenviados((atual) => {
                        const proximo = new Set(atual);
                        proximo.delete(signatarioId);
                        return proximo;
                    });
                }, 8000);
            },
        });
    }

    // ─── CLICK-09/UI-04 — Ajustar quem assina (RAMO B, D-14) ────────────
    // Não existe bifurcação: o botão "Ajustar" abre direto a explicação de
    // que não dá para só corrigir o e-mail — o CTA primário leva ao MESMO
    // fluxo de registrar cancelamento (D-06/D-07/D-14).
    const [ajustarContratoId, setAjustarContratoId] = useState(null);

    // ─── CLICK-10/D-13 — Registrar cancelamento (registra aqui, cancela no painel) ─
    const cancelarForm = useForm({ motivo: '' });
    const [cancelarContratoId, setCancelarContratoId] = useState(null);

    function abrirCancelamento(contratoId) {
        cancelarForm.reset();
        cancelarForm.clearErrors();
        setAjustarContratoId(null);
        setCancelarContratoId(contratoId);
    }

    function confirmarCancelamento(e) {
        e.preventDefault();
        cancelarForm.post(route('admin.contratos.cancelamento', cancelarContratoId), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelarContratoId(null);
                // O rótulo promete registrar E ir para a Clicksign — só abre
                // a aba DEPOIS que a resposta volta, e só se a URL do painel
                // veio configurada. Rótulo sem destino é exatamente o que
                // esta fase recusou.
                if (painel_clicksign_url) {
                    window.open(painel_clicksign_url, '_blank', 'noopener');
                }
            },
        });
    }

    // ─── D-05 — Estado `erro`: assume a falha e oferece a saída ─────────
    // Sem carimbo persistido de "quantas vezes já tentou" — a distinção
    // 1ª/2ª falha é por sessão de navegação (reseta ao recarregar a página).
    const [tentativasErro, setTentativasErro] = useState({});
    const [detalhesTecnicosAbertos, setDetalhesTecnicosAbertos] = useState({});

    function tentarNovamente(contratoId) {
        setTentativasErro((atual) => ({ ...atual, [contratoId]: (atual[contratoId] ?? 0) + 1 }));
        gerarContrato();
    }

    return (
        <AppLayout title={`Adm · Contrato — ${company.name}`}>
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    {/* Cabeçalho */}
                    <div>
                        <Link
                            href={route('admin.contratos.index')}
                            className="inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/70 mb-2"
                        >
                            <ArrowLeft size={12} /> Voltar para Contratos
                        </Link>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <Building2 size={20} className="text-ecf-yellow" />
                            {company.name}
                        </h1>
                    </div>

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-[13px] text-red-300">
                            {flash.error}
                        </div>
                    )}
                    {/* CLICK-07 — 429 é resposta ESPERADA da Clicksign, nunca erro:
                        canal neutro/âmbar, nunca vermelho. */}
                    {flash?.aviso && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                            <p className="font-semibold">Aguarde um pouco antes de reenviar</p>
                            <p className="mt-0.5 text-amber-300/80">{flash.aviso}</p>
                        </div>
                    )}

                    {/* Ponto focal da tela (131-UI-SPEC.md): quando há pendência, o
                        bloco de "falta completar" + botão desabilitado, adjacentes
                        (D-03). Quando não há, o botão ativo sozinho — nunca os dois
                        ao mesmo tempo. */}
                    {!pode_gerar_contrato ? (
                        <Card>
                            <CardContent className="p-4 space-y-3">
                                <h2 className="text-white/85 text-[15px] font-semibold">Falta completar antes de gerar o contrato</h2>
                                {faltantes.length > 0 && (
                                    <ul className="space-y-1.5">
                                        {faltantes.map((item, idx) => (
                                            <li key={idx} className="text-[13px] text-white/60 flex items-center gap-2">
                                                <span className="h-1.5 w-1.5 rounded-full bg-white/30 shrink-0" />
                                                {item.rotulo}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {motivo_bloqueio && MOTIVO_BLOQUEIO_TEXTO[motivo_bloqueio] && (
                                    <p className="text-[13px] text-white/50">{MOTIVO_BLOQUEIO_TEXTO[motivo_bloqueio]}</p>
                                )}
                                <Button disabled className="opacity-40 cursor-not-allowed">
                                    Gerar contrato
                                </Button>
                            </CardContent>
                        </Card>
                    ) : (
                        <div>
                            <Button
                                type="button"
                                onClick={gerarContrato}
                                disabled={gerarForm.processing}
                                className="bg-ecf-yellow text-black hover:bg-ecf-yellow/90"
                            >
                                {gerarForm.processing ? 'Gerando…' : 'Gerar contrato'}
                            </Button>
                        </div>
                    )}

                    {/* Formulário de completar cadastro (ADM-01) */}
                    <Card>
                        <CardContent className="p-4">
                            <h2 className="text-white/85 text-[15px] font-semibold mb-3">Cadastro da empresa</h2>
                            <form onSubmit={salvarCadastro} className="space-y-4">
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>CNPJ</Label>
                                        <Input
                                            value={cadastroForm.data.cnpj}
                                            onChange={(e) => cadastroForm.setData('cnpj', e.target.value)}
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.cnpj && <p className="text-red-400 text-[11px]">{cadastroForm.errors.cnpj}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>E-mail do cliente</Label>
                                        <Input
                                            type="email"
                                            value={cadastroForm.data.email_cliente}
                                            onChange={(e) => cadastroForm.setData('email_cliente', e.target.value)}
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.email_cliente && (
                                            <p className="text-red-400 text-[11px]">{cadastroForm.errors.email_cliente}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Nome de quem assina pela empresa</Label>
                                    <Input
                                        value={cadastroForm.data.nome_contato}
                                        onChange={(e) => cadastroForm.setData('nome_contato', e.target.value)}
                                        className="focus:border-ecf-yellow/40"
                                    />
                                    {cadastroForm.errors.nome_contato && (
                                        <p className="text-red-400 text-[11px]">{cadastroForm.errors.nome_contato}</p>
                                    )}
                                </div>

                                <div className="space-y-1.5">
                                    <Label>E-mail do colaborador</Label>
                                    <Input
                                        type="email"
                                        value={cadastroForm.data.email_colaborador}
                                        onChange={(e) => cadastroForm.setData('email_colaborador', e.target.value)}
                                        className="focus:border-ecf-yellow/40"
                                    />
                                    {cadastroForm.errors.email_colaborador && (
                                        <p className="text-red-400 text-[11px]">{cadastroForm.errors.email_colaborador}</p>
                                    )}
                                    {/* D-11 — pendência destacada que NÃO impede gerar o contrato. */}
                                    {email_colaborador_pendente && (
                                        <p className="text-[12px] text-amber-300/80">
                                            Falta o e-mail do colaborador. Ele não impede gerar o contrato, mas sem ele a ECF não consegue acessar a conta do cliente.
                                        </p>
                                    )}
                                </div>

                                {contratos_servico.length > 0 && (
                                    <div className="space-y-3 pt-3 border-t border-white/[0.06]">
                                        <p className="text-[11px] text-white/40 uppercase tracking-wide">Datas por serviço</p>
                                        {contratos_servico.map((cs) => {
                                            const item = cadastroForm.data.contratos_servico.find((x) => x.id === cs.id) ?? {
                                                data_contratacao: '',
                                                data_vencimento: '',
                                            };
                                            return (
                                                <div key={cs.id} className="grid grid-cols-3 gap-3 items-end">
                                                    <div className="text-[13px] text-white/70">{cs.servico_nome}</div>
                                                    <div className="space-y-1.5">
                                                        <Label className="text-[11px]">Data de início</Label>
                                                        <Input
                                                            type="date"
                                                            value={item.data_contratacao}
                                                            onChange={(e) => atualizarDataServico(cs.id, 'data_contratacao', e.target.value)}
                                                            className="focus:border-ecf-yellow/40"
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label className="text-[11px]">Data de término</Label>
                                                        <Input
                                                            type="date"
                                                            value={item.data_vencimento}
                                                            onChange={(e) => atualizarDataServico(cs.id, 'data_vencimento', e.target.value)}
                                                            className="focus:border-ecf-yellow/40"
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                <Button type="submit" variant="outline" disabled={cadastroForm.processing}>
                                    {cadastroForm.processing ? 'Salvando…' : 'Salvar cadastro'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Configuração interna da ECF — bloco SEPARADO, nunca misturado
                        com a lista de pendências da empresa acima (.env, não dado
                        da empresa). */}
                    {configuracao_ecf_faltante.length > 0 && (
                        <Card className="border-rose-500/20">
                            <CardContent className="p-4 space-y-2">
                                <div className="flex items-center gap-2">
                                    <AlertTriangle size={14} className="text-rose-400" />
                                    <h2 className="text-rose-300 text-[15px] font-semibold">Configuração interna da ECF pendente</h2>
                                </div>
                                <p className="text-[12px] text-white/50">
                                    Isto não é responsabilidade do cliente — um administrador precisa resolver.
                                </p>
                                <ul className="space-y-1">
                                    {configuracao_ecf_faltante.map((item, idx) => (
                                        <li key={idx} className="text-[13px] text-white/60">{item}</li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}

                    {/* Lista dos contratos desta empresa. */}
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Serviço</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Situação</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Parado há</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {contratos.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-center py-8 text-[13px] text-white/40">
                                                Nenhum contrato administrativo registrado ainda para esta empresa.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {contratos.map((c) => {
                                        const podeAgir = c.status === 'aguardando_assinaturas';
                                        const podeCancelar = (c.status === 'rascunho' || c.status === 'aguardando_assinaturas')
                                            && !c.cancelamento_solicitado_em;
                                        const signatariosPendentes = podeAgir
                                            ? (c.signatarios || []).filter((s) => s.situacao === 'pendente')
                                            : [];
                                        const tentativas = tentativasErro[c.id] ?? 0;

                                        return (
                                            <Fragment key={c.id}>
                                                <TableRow>
                                                    <TableCell className="text-[13px] text-white/85 align-top">{c.servico_nome}</TableCell>
                                                    <TableCell className="align-top">
                                                        <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', classeContrato(c.status))}>
                                                            {rotuloContrato(c.status)}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="text-[13px] text-white/50 align-top">{formatarHaDias(c.dias_parado)}</TableCell>
                                                    <TableCell className="align-top">
                                                        <div className="space-y-2 min-w-[220px]">
                                                            {signatariosPendentes.map((s) => {
                                                                const reenviando = signatarioReenviando === s.id;
                                                                const recemReenviado = signatariosRecemReenviados.has(s.id);
                                                                return (
                                                                    <div key={s.id} className="flex items-center justify-between gap-2 text-[12px]">
                                                                        <span className="text-white/60 truncate">{s.nome}</span>
                                                                        <div className="flex items-center gap-1 shrink-0">
                                                                            <button
                                                                                type="button"
                                                                                title="Reenviar aviso"
                                                                                disabled={reenviando || recemReenviado}
                                                                                onClick={() => reenviarAviso(c.id, s.id)}
                                                                                className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-white/50 hover:text-white/80 hover:bg-white/[0.06] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                                                            >
                                                                                <Send size={11} />
                                                                                {reenviando ? 'Reenviando…' : 'Reenviar aviso'}
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                title="Ajustar"
                                                                                onClick={() => setAjustarContratoId(c.id)}
                                                                                className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-white/50 hover:text-white/80 hover:bg-white/[0.06] transition-colors"
                                                                            >
                                                                                <UserCog size={11} />
                                                                                Ajustar
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                            {podeCancelar && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => abrirCancelamento(c.id)}
                                                                    className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-red-400/70 hover:text-red-300 hover:bg-red-500/[0.08] text-[12px] transition-colors"
                                                                >
                                                                    <Ban size={11} />
                                                                    Registrar cancelamento
                                                                </button>
                                                            )}
                                                            {c.status === 'erro' && (
                                                                <button
                                                                    type="button"
                                                                    disabled={gerarForm.processing}
                                                                    onClick={() => tentarNovamente(c.id)}
                                                                    className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-ecf-yellow/80 hover:text-ecf-yellow hover:bg-ecf-yellow/[0.06] text-[12px] disabled:opacity-40 transition-colors"
                                                                >
                                                                    <RefreshCcw size={11} />
                                                                    {gerarForm.processing ? 'Gerando…' : 'Tentar novamente'}
                                                                </button>
                                                            )}
                                                            {signatariosPendentes.length === 0 && !podeCancelar && c.status !== 'erro' && (
                                                                <span className="text-[13px] text-white/20">—</span>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>

                                                {/* Aviso persistente de cancelamento solicitado (D-13) — âmbar,
                                                    "aguardando algo externo", nunca vermelho de erro. Some
                                                    sozinho quando a confirmação da Clicksign fechar o estado. */}
                                                {c.cancelamento_solicitado_em && (
                                                    <TableRow key={`${c.id}-cancelamento-solicitado`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3 py-2 text-[12px] text-amber-300/90">
                                                                Cancelamento pedido por {c.cancelamento_solicitado_por_nome || 'alguém do time'} em{' '}
                                                                {formatDate(c.cancelamento_solicitado_em)} — ainda não concluído no painel da Clicksign.
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}

                                                {/* D-05 — Estado `erro`: assume a falha e oferece a saída. */}
                                                {c.status === 'erro' && (
                                                    <TableRow key={`${c.id}-erro`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-rose-500/20 bg-rose-500/[0.05] px-3 py-2.5 text-[12px] space-y-1.5">
                                                                {tentativas === 0 ? (
                                                                    <>
                                                                        <p className="text-rose-300 font-semibold">Não deu para enviar este contrato</p>
                                                                        <p className="text-white/50">
                                                                            O problema foi aqui do nosso lado, não com o cliente. Tente novamente — na
                                                                            maioria das vezes resolve.
                                                                        </p>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <p className="text-rose-300 font-semibold">Continua sem enviar</p>
                                                                        <p className="text-white/50">
                                                                            Tentamos de novo e não deu certo. Avise o time técnico para verificar — pode
                                                                            levar um tempinho para resolver.
                                                                        </p>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => setDetalhesTecnicosAbertos((atual) => ({ ...atual, [c.id]: !atual[c.id] }))}
                                                                            className="inline-flex items-center gap-1 text-white/40 hover:text-white/70"
                                                                        >
                                                                            {detalhesTecnicosAbertos[c.id] ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
                                                                            Ver detalhes técnicos
                                                                        </button>
                                                                        {detalhesTecnicosAbertos[c.id] && (
                                                                            <p className="text-white/35 font-mono text-[11px] break-words">
                                                                                {flash?.error || 'Sem detalhes adicionais registrados nesta sessão.'}
                                                                            </p>
                                                                        )}
                                                                    </>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </main>

            {/* RAMO B (D-06/D-07/D-14) — não existe bifurcação: corrigir o
                e-mail e trocar a pessoa colapsam no mesmo caminho. */}
            <Dialog open={ajustarContratoId !== null} onOpenChange={(v) => { if (!v) setAjustarContratoId(null); }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Não dá para só corrigir o e-mail</DialogTitle>
                    </DialogHeader>
                    <p className="text-[13px] text-white/60">
                        Depois que o contrato é enviado, não é possível trocar o e-mail de quem assina. Se
                        foi só um erro de digitação, cancele este contrato e gere um novo com o e-mail
                        certo.
                    </p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setAjustarContratoId(null)}>
                            Voltar
                        </Button>
                        <Button
                            type="button"
                            className="bg-red-600 text-white hover:bg-red-600/90"
                            onClick={() => abrirCancelamento(ajustarContratoId)}
                        >
                            Registrar cancelamento
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* CLICK-10/D-13 — registra aqui, cancela no painel. */}
            <Dialog open={cancelarContratoId !== null} onOpenChange={(v) => { if (!v) setCancelarContratoId(null); }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Registrar cancelamento deste contrato</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={confirmarCancelamento} className="space-y-3">
                        <p className="text-[13px] text-white/60">
                            O cancelamento em si precisa ser feito no painel da Clicksign — o sistema não
                            consegue cancelar sozinho. Aqui você registra quem pediu e por quê, para ficar
                            no histórico.
                        </p>
                        <div className="space-y-1.5">
                            <Label>Motivo do cancelamento</Label>
                            <Textarea
                                rows={4}
                                placeholder="Explique por que este contrato está sendo cancelado."
                                value={cancelarForm.data.motivo}
                                onChange={(e) => cancelarForm.setData('motivo', e.target.value)}
                            />
                            {cancelarForm.errors.motivo && (
                                <p className="text-red-400 text-[11px]">{cancelarForm.errors.motivo}</p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCancelarContratoId(null)}>
                                Voltar
                            </Button>
                            <Button
                                type="submit"
                                disabled={cancelarForm.processing}
                                className="bg-red-600 text-white hover:bg-red-600/90"
                            >
                                {cancelarForm.processing
                                    ? 'Registrando…'
                                    : (painel_clicksign_url ? 'Registrar e ir para a Clicksign' : 'Registrar cancelamento')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
