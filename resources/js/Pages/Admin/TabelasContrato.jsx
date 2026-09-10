import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { FileSearch, Search, AlertTriangle, ChevronLeft, ChevronRight, CheckCircle2, HelpCircle } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';

/**
 * Admin/TabelasContrato.jsx — Fase 140 Plano 05 (TAB-08/TAB-09, D-05/D-06).
 *
 * A tela de conferência das tabelas de cobrança lidas do Clicksign (planos 140-01 a 140-04): uma
 * pessoa do Administrativo confere, contrato a contrato, de qual empresa é cada leitura, compara
 * com o que o sistema cobra hoje e só então confirma — é a confirmação que vira dado de cobrança de
 * verdade.
 *
 * ⚠️ ZERO casamentos automáticos na varredura real (85 contratos, 140-03-SUMMARY.md) — toda linha
 * nasce sem empresa confirmada. Esta tela é "confirmar 85 vínculos", não "revisar exceções": o
 * caminho comum tem que ser rápido, e o incerto tem que PARECER incerto — nunca um check verde para
 * quem só teve o nome parecido.
 *
 * Vocabulário sem jargão (regra do projeto + copy do plano): nunca "proposta"/"parser"/
 * "envelope"/"score"/"palpite" como rótulo técnico na tela — os textos já vêm prontos do backend
 * (`confianca_label`/`tipo_cobranca_label`), a mesma redação do relatório do plano 140-03.
 */

const fmtBRL = (n) => (n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 }));

// Nunca um check verde para 'incerto' — o destaque visual é o que faz o incerto PARECER incerto.
const CONFIANCA_ESTILO = {
    certo:    'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    provavel: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
    incerto:  'border-white/15 bg-white/[0.04] text-white/60',
};

const SITUACAO_LABEL = {
    pendente:   'A conferir',
    confirmada: 'Confirmado',
    descartada: 'Descartado',
};

function ConfiancaBadge({ confianca, label, ambiguo }) {
    const Icone = confianca === 'certo' ? CheckCircle2 : (confianca === 'provavel' ? HelpCircle : AlertTriangle);
    return (
        <div className="flex flex-col gap-1">
            <span className={cn('inline-flex items-center gap-1.5 w-fit rounded-full border px-2 py-0.5 text-[11px] font-semibold', CONFIANCA_ESTILO[confianca] ?? CONFIANCA_ESTILO.incerto)}>
                <Icone size={12} />
                {label}
            </span>
            {ambiguo && (
                <span className="text-[11px] text-amber-400/90">há outra empresa parecida</span>
            )}
        </div>
    );
}

function ListaFaixas({ faixas }) {
    if (!faixas || faixas.length === 0) {
        return <p className="text-white/30 text-[12px]">Nenhuma faixa.</p>;
    }
    return (
        <div className="rounded-lg border border-white/[0.06] overflow-hidden">
            {faixas.map((f, i) => (
                <div
                    key={f.ordem ?? i}
                    className={cn('grid grid-cols-[1fr_auto] gap-3 px-3 py-2 text-[12px] font-mono', i > 0 && 'border-t border-white/[0.04]')}
                >
                    <span className="text-white/60">{f.limite_superior != null ? `até ${fmtBRL(f.limite_superior)}` : 'acima disso'}</span>
                    <span className="text-emerald-400/80 font-semibold">
                        {f.valor_e_piso ? 'a partir de ' : ''}{fmtBRL(f.valor)}
                    </span>
                </div>
            ))}
        </div>
    );
}

// ─── Painel de conferência (Dialog) — seletor de empresa + comparação lado a lado ──
function PainelConferencia({ linha, empresas, onClose }) {
    const [busca, setBusca] = useState('');
    const [companyId, setCompanyId] = useState(null);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);

    const resultados = useMemo(() => {
        const termo = busca.trim().toLowerCase();
        if (termo === '') return [];
        return empresas
            .filter((e) => e.name.toLowerCase().includes(termo) || (e.cnpj ?? '').includes(termo))
            .slice(0, 8);
    }, [busca, empresas]);

    const empresaSelecionada = empresas.find((e) => e.id === companyId) ?? null;

    function confirmar() {
        if (!companyId) {
            setErro('Escolha a empresa antes de confirmar.');
            return;
        }
        setEnviando(true);
        setErro(null);
        router.post(route('admin.contratos.tabelas.confirmar', linha.id), { company_id: companyId }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setErro(Object.values(errors)[0] ?? 'Não foi possível confirmar.'),
            onFinish: () => setEnviando(false),
        });
    }

    function descartar() {
        if (!confirm('Descartar esta leitura? Nada será gravado na cobrança desta empresa.')) return;
        setEnviando(true);
        router.post(route('admin.contratos.tabelas.descartar', linha.id), {}, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setEnviando(false),
        });
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{linha.nome_envelope}</DialogTitle>
                </DialogHeader>

                <p className="text-white/40 text-[12px] -mt-2">
                    {linha.envelope_data ? formatDate(linha.envelope_data) : 'data não informada'} · {linha.tipo_cobranca_label}
                </p>

                {linha.motivo && (
                    <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[12px] text-amber-300 flex gap-2">
                        <AlertTriangle size={14} className="shrink-0 mt-0.5" />
                        <span>{linha.motivo}</span>
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">Lida deste contrato</p>
                        {linha.tipo_cobranca === 'tabela' ? (
                            <ListaFaixas faixas={linha.faixas} />
                        ) : linha.tipo_cobranca === 'valor_fixo' ? (
                            <p className="text-[13px] text-white/80">{linha.valor_fixo_formatado} por mês</p>
                        ) : (
                            <p className="text-white/30 text-[12px]">Sem dado de cobrança para conferir.</p>
                        )}
                        {(linha.cnpj_lido || linha.razao_social_lida) && (
                            <p className="text-[11px] text-white/40">
                                {linha.razao_social_lida && <>Razão social: {linha.razao_social_lida}<br /></>}
                                {linha.cnpj_lido && <>CNPJ: {linha.cnpj_lido}</>}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">Em uso hoje{empresaSelecionada ? ` · ${empresaSelecionada.name}` : ''}</p>
                        {!empresaSelecionada && (
                            <p className="text-white/30 text-[12px]">Escolha a empresa ao lado para comparar.</p>
                        )}
                        {empresaSelecionada && !linha.tabela_em_uso && (
                            <p className="text-amber-400 text-[12px]">Esta empresa ainda não tem cobrança definida.</p>
                        )}
                        {empresaSelecionada && linha.tabela_em_uso && (
                            <ListaFaixas faixas={linha.tabela_em_uso.faixas} />
                        )}
                    </div>
                </div>

                <div className="space-y-2 pt-2 border-t border-white/[0.06]">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">De qual empresa é este contrato?</p>

                    {linha.candidatos && linha.candidatos.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {linha.candidatos.map((c) => (
                                <button
                                    key={c.company_id}
                                    type="button"
                                    onClick={() => { setCompanyId(c.company_id); setErro(null); }}
                                    className={cn(
                                        'rounded-full border px-2.5 py-1 text-[11px] transition-colors',
                                        companyId === c.company_id
                                            ? 'border-ecf-yellow bg-ecf-yellow/10 text-ecf-yellow'
                                            : 'border-white/15 text-white/60 hover:bg-white/[0.05]'
                                    )}
                                >
                                    {c.nome}
                                </button>
                            ))}
                        </div>
                    )}

                    <div className="relative">
                        <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
                        <Input
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            placeholder="Buscar empresa por nome ou CNPJ..."
                            className="pl-8"
                        />
                    </div>

                    {resultados.length > 0 && (
                        <div className="rounded-lg border border-white/[0.08] max-h-40 overflow-y-auto">
                            {resultados.map((e) => (
                                <button
                                    key={e.id}
                                    type="button"
                                    onClick={() => { setCompanyId(e.id); setBusca(''); setErro(null); }}
                                    className={cn(
                                        'block w-full text-left px-3 py-1.5 text-[12px] hover:bg-white/[0.05] transition-colors',
                                        companyId === e.id ? 'bg-ecf-yellow/10 text-ecf-yellow' : 'text-white/70'
                                    )}
                                >
                                    {e.name}{e.cnpj ? ` — ${e.cnpj}` : ''}
                                </button>
                            ))}
                        </div>
                    )}

                    {empresaSelecionada && (
                        <p className="text-[12px] text-white/70">
                            Empresa escolhida: <span className="font-semibold text-white">{empresaSelecionada.name}</span>
                        </p>
                    )}

                    {erro && <p className="text-red-400 text-[12px]">{erro}</p>}
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={descartar} disabled={enviando}>
                        Descartar
                    </Button>
                    <Button type="button" onClick={confirmar} disabled={enviando || !companyId}>
                        {enviando ? 'Confirmando...' : 'Confirmar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function TabelasContrato({ propostas, filters = {}, resumo = {}, empresas = [] }) {
    const [conferindoId, setConferindoId] = useState(null);
    const linhasData = propostas?.data ?? [];
    const linhaAberta = linhasData.find((l) => l.id === conferindoId) ?? null;

    const applyFilter = (key, value) => {
        router.get(route('admin.contratos.tabelas.index'), {
            ...filters,
            [key]: value || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout title="Adm · Tabelas de cobrança">
            <main className="p-6">
                <div className="space-y-4">
                    <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                        <FileSearch size={20} className="text-ecf-yellow" />
                        Conferência de tabelas de cobrança
                    </h1>
                    <p className="text-white/50 text-[13px] max-w-2xl">
                        Cada linha veio de um contrato assinado. Nenhuma foi confirmada automaticamente — confira a
                        empresa e a tabela antes de aceitar.
                    </p>

                    {/* Resumo — sempre sobre o universo inteiro, nunca o recorte filtrado. */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-3 py-3">
                            <div className="text-2xl font-bold tabular-nums text-white">{resumo.casaram_seguranca ?? 0}</div>
                            <div className="text-[12px] text-white/60 mt-0.5">Confirmadas pelo CNPJ</div>
                        </div>
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-3 py-3">
                            <div className="text-2xl font-bold tabular-nums text-white">{resumo.duvidosos ?? 0}</div>
                            <div className="text-[12px] text-white/60 mt-0.5">Precisam de conferência</div>
                        </div>
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-3 py-3">
                            <div className="text-2xl font-bold tabular-nums text-white">{resumo.valor_fixo ?? 0}</div>
                            <div className="text-[12px] text-white/60 mt-0.5">Valor fixo — sem faixa</div>
                        </div>
                        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-3 py-3">
                            <div className="text-2xl font-bold tabular-nums text-white">{resumo.ilegiveis ?? 0}</div>
                            <div className="text-[12px] text-white/60 mt-0.5">Abrir o contrato à mão</div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={filters.situacao ?? 'pendente'}
                            onChange={(e) => applyFilter('situacao', e.target.value)}
                            className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            aria-label="Filtrar por situação"
                        >
                            <option value="pendente" className="bg-[#0f1116]">A conferir</option>
                            <option value="confirmada" className="bg-[#0f1116]">Confirmadas</option>
                            <option value="descartada" className="bg-[#0f1116]">Descartadas</option>
                        </select>

                        <select
                            value={filters.confianca ?? ''}
                            onChange={(e) => applyFilter('confianca', e.target.value)}
                            className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            aria-label="Filtrar pelo grau de certeza"
                        >
                            <option value="" className="bg-[#0f1116]">Todos</option>
                            <option value="certo" className="bg-[#0f1116]">Confirmadas pelo CNPJ</option>
                            <option value="provavel" className="bg-[#0f1116]">Parece ser esta</option>
                            <option value="incerto" className="bg-[#0f1116]">Só um palpite</option>
                        </select>
                    </div>

                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Contrato</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Empresa</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Situação</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Cobrança</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {linhasData.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="text-center py-10">
                                                <p className="text-[13px] text-white/40">Nada para conferir neste recorte.</p>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {linhasData.map((linha) => (
                                        <TableRow key={linha.id}>
                                            <TableCell className="text-[13px] text-white/85 max-w-[260px]">
                                                <div className="font-medium truncate">{linha.nome_envelope}</div>
                                                <div className="text-white/40 text-[11px]">
                                                    {linha.envelope_data ? formatDate(linha.envelope_data) : 'data não informada'}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/70">
                                                {linha.company_nome ?? <span className="text-white/30">nenhuma empresa parecida</span>}
                                                <div className="mt-1">
                                                    <ConfiancaBadge confianca={linha.confianca} label={linha.confianca_label} ambiguo={linha.ambiguo} />
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-[12px] text-white/50">{SITUACAO_LABEL[linha.situacao] ?? linha.situacao}</TableCell>
                                            <TableCell className="text-[13px] text-white/70">
                                                <div>{linha.tipo_cobranca_label}</div>
                                                {linha.tipo_cobranca === 'valor_fixo' && linha.valor_fixo_formatado && (
                                                    <div className="text-white/40 text-[11px]">{linha.valor_fixo_formatado} por mês</div>
                                                )}
                                                {linha.tipo_cobranca === 'tabela' && linha.faixas_formatadas?.length > 0 && (
                                                    <div className="text-white/40 text-[11px]">{linha.faixas_formatadas.length} faixas</div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {linha.situacao === 'pendente' ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => setConferindoId(linha.id)}
                                                        className="text-[12px] font-semibold text-ecf-yellow hover:underline"
                                                    >
                                                        Conferir
                                                    </button>
                                                ) : (
                                                    <span className="text-[11px] text-white/30">
                                                        {linha.confirmado_por_nome ? `por ${linha.confirmado_por_nome}` : '—'}
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Paginator paginator={propostas} />
                </div>
            </main>

            {linhaAberta && (
                <PainelConferencia
                    linha={linhaAberta}
                    empresas={empresas}
                    onClose={() => setConferindoId(null)}
                />
            )}
        </AppLayout>
    );
}

// ─── Paginação Inertia (forward/back simples) — molde de Admin/Contratos.jsx ─
function Paginator({ paginator }) {
    if (!paginator || paginator.last_page <= 1) return null;
    const prev = paginator.prev_page_url;
    const next = paginator.next_page_url;
    return (
        <div className="flex items-center justify-between border-t border-white/[0.06] px-4 py-2 bg-white/[0.02]">
            <span className="text-white/40 text-[12px]">
                Página {paginator.current_page} de {paginator.last_page} — {paginator.total} contratos
            </span>
            <div className="flex items-center gap-1">
                <a
                    href={prev || '#'}
                    onClick={(e) => { e.preventDefault(); if (prev) router.visit(prev, { preserveScroll: true, preserveState: true }); }}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !prev && 'opacity-30 pointer-events-none',
                    )}
                >
                    <ChevronLeft size={13} /> Anterior
                </a>
                <a
                    href={next || '#'}
                    onClick={(e) => { e.preventDefault(); if (next) router.visit(next, { preserveScroll: true, preserveState: true }); }}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !next && 'opacity-30 pointer-events-none',
                    )}
                >
                    Próxima <ChevronRight size={13} />
                </a>
            </div>
        </div>
    );
}
