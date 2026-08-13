import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, Unlock } from 'lucide-react';
import { cn } from '@/lib/utils';

// Causas de ContratosPresosService (App\Services\Contratos\ContratosPresosService::CAUSA_*)
// espelhadas à mão em JS, na linguagem simples que a D-05 pede — o projeto
// não compartilha enum entre PHP e JS (convenção já usada em outras telas).
const CAUSA_LABELS = {
    rascunho_parado:          'Contrato criado e nunca enviado',
    aguardando_alem_do_prazo: 'Enviado e ainda sem assinatura',
    assinado_sem_liberacao:   'Assinado, mas a empresa não foi liberada',
    recusado_pelo_cliente:    'O cliente recusou a assinatura',
    prazo_expirado:           'O prazo acabou sem todas as assinaturas',
    cancelado:                'Contrato cancelado',
    erro_tecnico:             'Falha técnica na integração — ninguém recusou nada',
};

// Causas que merecem a faixa de destaque vermelha antes de confirmar (D-11)
// — estados em que "liberar mesmo assim" é uma decisão que precisa de aviso
// explícito, porque o contrato não terminou bem.
const CAUSAS_DE_DESTAQUE = ['recusado_pelo_cliente', 'prazo_expirado', 'cancelado', 'erro_tecnico'];

const CAUSA_DESTAQUE_TEXTO = {
    recusado_pelo_cliente: 'Este contrato foi RECUSADO pelo cliente. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
    prazo_expirado:        'O PRAZO deste contrato EXPIROU sem todas as assinaturas. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
    cancelado:              'Este contrato foi CANCELADO. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
    erro_tecnico:            'Houve um ERRO TÉCNICO na integração com a Clicksign — ninguém recusou nada. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
};

const inputCls = 'h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 transition-colors';

/**
 * Admin/ContratosLiberacaoManual — Fase 130 Plano 04 (D-10/D-11).
 *
 * Tela DELIBERADAMENTE DESCARTÁVEL: a Fase 131 reescreve esta página e troca
 * `role:admin` pela permissão `admin.contratos`. Por isso, superfície
 * mínima — empresa, serviço, motivo (select) + detalhe (textarea) — sem
 * nenhum componente compartilhado novo e sem entrada no menu do AppLayout.
 */
export default function ContratosLiberacaoManual({ contratos, motivos }) {
    const [selecionadoId, setSelecionadoId] = useState(contratos?.[0]?.id ?? null);

    const contratoSelecionado = (contratos || []).find(c => c.id === selecionadoId) ?? null;

    const { data, setData, post, processing, errors, reset } = useForm({
        company_id: contratoSelecionado?.company_id ?? '',
        servico_id: contratoSelecionado?.servico_id ?? '',
        contrato_assinatura_id: contratoSelecionado?.id ?? '',
        motivo_slug: '',
        motivo_detalhe: '',
    });

    function selecionar(contrato) {
        setSelecionadoId(contrato.id);
        setData({
            company_id: contrato.company_id,
            servico_id: contrato.servico_id,
            contrato_assinatura_id: contrato.id,
            motivo_slug: '',
            motivo_detalhe: '',
        });
    }

    function confirmarLiberacao() {
        post(route('contratos.liberacao-manual.store'), {
            preserveScroll: true,
            onSuccess: () => reset('motivo_slug', 'motivo_detalhe'),
        });
    }

    const podeConfirmar = Boolean(data.motivo_slug) && data.motivo_detalhe.trim().length >= 5 && !processing;
    const mostrarDestaque = contratoSelecionado && CAUSAS_DE_DESTAQUE.includes(contratoSelecionado.causa);

    return (
        <AppLayout title="Liberação manual de contratos">
            <main className="p-6">
                <div className="max-w-3xl space-y-6">

                    <div>
                        <h1 className="text-[18px] font-semibold text-white flex items-center gap-2">
                            <Unlock size={18} className="text-ecf-yellow" />
                            Liberação manual de contratos
                        </h1>
                        <p className="text-[13px] text-white/40 mt-1">
                            Use quando a Clicksign falhou e a empresa precisa ser liberada para o
                            operacional mesmo assim. Toda liberação fica registrada com seu nome e o
                            motivo informado.
                        </p>
                    </div>

                    {/* ── Lista de empresas presas ── */}
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-6 space-y-4">
                        <h2 className="text-[15px] font-semibold text-white">Empresas paradas</h2>

                        {(!contratos || contratos.length === 0) ? (
                            <p className="text-[13px] text-white/25 py-3 text-center">
                                Nenhuma empresa parada no momento.
                            </p>
                        ) : (
                            <div className="space-y-1.5">
                                {contratos.map((contrato) => (
                                    <button
                                        key={contrato.id}
                                        type="button"
                                        onClick={() => selecionar(contrato)}
                                        className={cn(
                                            'w-full text-left flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg border transition-colors',
                                            selecionadoId === contrato.id
                                                ? 'border-ecf-yellow/40 bg-ecf-yellow/[0.06]'
                                                : 'border-white/[0.06] bg-white/[0.02] hover:bg-white/[0.04]',
                                        )}
                                    >
                                        <div className="min-w-0">
                                            <p className="text-[13px] font-medium text-white/90 truncate">
                                                {contrato.company_nome} — {contrato.servico_nome}
                                            </p>
                                            <p className="text-[12px] text-white/40 mt-0.5">
                                                {CAUSA_LABELS[contrato.causa] || contrato.causa} · parado há {contrato.dias_parado} dia(s)
                                            </p>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* ── Formulário de liberação ── */}
                    {contratoSelecionado && (
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-6 space-y-5">
                            <div>
                                <h2 className="text-[15px] font-semibold text-white">
                                    Liberar {contratoSelecionado.company_nome} — {contratoSelecionado.servico_nome}
                                </h2>
                            </div>

                            {/* D-11 — faixa de destaque obrigatória antes do botão de confirmar */}
                            {mostrarDestaque && (
                                <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg border border-red-500/30 bg-red-500/[0.06] text-red-300 text-[13px] font-semibold">
                                    <AlertTriangle size={16} className="shrink-0 mt-0.5" />
                                    <span>{CAUSA_DESTAQUE_TEXTO[contratoSelecionado.causa]}</span>
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <label className="text-[12px] text-white/40 font-medium">Motivo</label>
                                <select
                                    value={data.motivo_slug}
                                    onChange={e => setData('motivo_slug', e.target.value)}
                                    className={cn(inputCls, 'w-full')}
                                >
                                    <option value="">Selecione um motivo...</option>
                                    {Object.entries(motivos || {}).map(([slug, rotulo]) => (
                                        <option key={slug} value={slug}>{rotulo}</option>
                                    ))}
                                </select>
                                {errors.motivo_slug && <p className="text-[12px] text-red-400">{errors.motivo_slug}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-[12px] text-white/40 font-medium">
                                    Detalhe (obrigatório)
                                </label>
                                <textarea
                                    value={data.motivo_detalhe}
                                    onChange={e => setData('motivo_detalhe', e.target.value)}
                                    rows={3}
                                    placeholder="Explique o que aconteceu, mesmo que seja um resumo curto."
                                    className={cn(
                                        'w-full px-3 py-2 rounded-lg border bg-white/[0.03] text-[13px] text-white/80 placeholder:text-white/20 focus:outline-none transition-colors',
                                        errors.motivo_detalhe
                                            ? 'border-red-500/40 focus:border-red-500/60'
                                            : 'border-white/[0.08] focus:border-ecf-yellow/40',
                                    )}
                                />
                                {errors.motivo_detalhe && <p className="text-[12px] text-red-400">{errors.motivo_detalhe}</p>}
                                {!errors.motivo_detalhe && data.motivo_detalhe.trim().length > 0 && data.motivo_detalhe.trim().length < 5 && (
                                    <p className="text-[12px] text-white/25">Escreva pelo menos 5 caracteres.</p>
                                )}
                            </div>

                            <button
                                type="button"
                                onClick={confirmarLiberacao}
                                disabled={!podeConfirmar}
                                className="h-9 px-4 rounded-lg bg-ecf-yellow text-black text-[13px] font-semibold hover:bg-ecf-yellow/90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                {processing ? 'Liberando...' : 'Confirmar liberação'}
                            </button>
                        </div>
                    )}

                </div>
            </main>
        </AppLayout>
    );
}
