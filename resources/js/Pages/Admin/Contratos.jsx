import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, router } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import { FileSignature, Search, ChevronLeft, ChevronRight } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { cn, formatDate } from '@/lib/utils';
import {
    CONTRATO_STATUS_LABELS,
    classeContrato,
    rotuloContrato,
    classeContratoComPreparo,
    rotuloContratoComPreparo,
    formatarHaDias,
    SEM_CONTRATO,
    SEM_CONTRATO_LABEL,
    PREPARANDO_TITULO,
    MONTAGEM_TRAVADA_TITULO,
} from '@/lib/contratoStatus';

// ─── Plano 138-06 (COMERC-02, D-12) — rótulos das 7 pendências comerciais.
// Mesmo bloco de Comercial/EmpresasListagem.jsx (não há enum compartilhado
// entre PHP e JS no projeto — a sincronia é manual, por convenção). São 7
// chaves, não 8 (Pitfall 5 do RESEARCH da Fase 138).
const PENDENCIAS_LABELS = {
    sem_servico:             'Sem serviço',
    sem_valor:               'Sem valor',
    servico_nao_reconhecido: 'Serviço não reconhecido',
    sem_setor:               'Sem setor (catálogo)',
    sem_contato:             'Sem contato',
    valor_revisar:           'Revisar valor',
    possivel_duplicidade:    'Possível duplicidade',
};

// ─── Fase 137 Plano 07 (ETAPA-05) — rótulos das 9 etapas do fluxo de entrada
// (§10 do PDF v23.0). Fonte de verdade é `Company::ETAPAS`
// (app/Models/Company.php) — mesmo bloco de Pages/Companies/Index.jsx, para
// as duas telas não divergirem no vocabulário.
const ETAPA_LABELS = {
    aguardando_administrativo: 'Aguardando Administrativo',
    administrativo_andamento:  'Administrativo em Andamento',
    aguardando_assinatura:     'Aguardando Assinatura',
    administrativo_concluido:  'Administrativo Concluído',
    aguardando_distribuicao:   'Aguardando Distribuição',
    aguardando_onboarding:     'Aguardando Onboarding',
    onboarding_andamento:      'Onboarding em Andamento',
    onboarding_concluido:      'Onboarding Concluído',
    em_operacao:               'Em Operação',
};

/**
 * Admin/Contratos.jsx — Fase 131 Plano 03 (UI-01, D-01, D-04, D-09).
 *
 * A lista de contratos do Administrativo: onde ele enxerga o estado real de
 * cada contrato sem abrir o banco — filtro por situação, busca por empresa
 * e resumo com a contagem de cada uma das 7 situações (D-04).
 *
 * Ponto focal (131-UI-SPEC.md): o grid de resumo por situação, no topo —
 * responde "onde as coisas estão paradas" antes de o Administrativo ler
 * uma única linha, e também FUNCIONA como filtro (clique liga/desliga).
 *
 * Plano 131-04 — a linha agora abre o detalhe da empresa
 * (`admin.contratos.show`), que é onde o Administrativo completa o
 * cadastro e dispara a geração do contrato.
 */
export default function Contratos({ linhas, filters = {}, resumo = {}, sem_contrato_count = 0, bloqueio_ativo = false, servicos = [] }) {
    const [qInput, setQInput] = useState(filters.q || '');
    const debounceRef = useRef(null);

    const applyFilter = (key, value) => {
        router.get(route('admin.contratos.index'), {
            ...filters,
            [key]: value || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const onSearchChange = (e) => {
        const v = e.target.value;
        setQInput(v);
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => applyFilter('q', v), 400);
    };

    // Sincroniza qInput quando filters.q mudar (ex: voltar via back button)
    useEffect(() => {
        setQInput(filters.q || '');
    }, [filters.q]);

    const linhasData = linhas?.data ?? [];
    const semContratoAtivo = filters.situacao === SEM_CONTRATO;

    return (
        <AppLayout title="Adm · Contratos">
            <main className="p-6">
                <div className="space-y-4">
                    <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                        <FileSignature size={20} className="text-ecf-yellow" />
                        Contratos
                    </h1>

                    {/* Fase 133 Plano 03 (D-04) — faixa informativa sem card, que
                        aparece só com o interruptor ligado e some quando desligado.
                        Conta a consequência para quem lê, não o mecanismo — sem
                        jargão de arquitetura no texto (UI-06). */}
                    {bloqueio_ativo && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                            <p className="font-semibold">Estas empresas estão aguardando a assinatura do contrato</p>
                            <p className="mt-0.5 text-amber-300/80">
                                Enquanto o contrato não for assinado, a empresa não entra na operação. É proposital
                                e vale para todas — não é problema de uma empresa específica. Assim que a assinatura
                                for concluída, a empresa entra sozinha, sem ninguém precisar fazer nada.
                            </p>
                        </div>
                    )}

                    {/* Ponto focal — grid de resumo por situação, 7 estados (D-04),
                        também funciona como filtro. NÃO copiar o número de colunas
                        do grid de pendências do Comercial (lá são 8 pendências,
                        aqui são 7 estados — a contagem de colunas é diferente de
                        propósito). */}
                    <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2">
                        {Object.entries(CONTRATO_STATUS_LABELS).map(([status, label]) => {
                            const active = filters.situacao === status;
                            const count = resumo?.[status] ?? 0;
                            return (
                                <button
                                    key={status}
                                    type="button"
                                    onClick={() => applyFilter('situacao', active ? null : status)}
                                    className={cn(
                                        'rounded-xl border px-3 py-3 text-left transition-colors',
                                        active
                                            ? 'border-ecf-yellow bg-ecf-yellow/[0.06]'
                                            : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.04]'
                                    )}
                                >
                                    <div className="text-2xl font-bold tabular-nums text-white">{count}</div>
                                    <div className="text-[12px] text-white/60 mt-0.5">{label}</div>
                                </button>
                            );
                        })}
                    </div>

                    {/* Linha do estado só-de-exibição (sem contrato ainda) — NÃO é
                        um oitavo card do grid acima (D-04 trava o resumo em 7). */}
                    <button
                        type="button"
                        onClick={() => applyFilter('situacao', semContratoAtivo ? null : SEM_CONTRATO)}
                        className={cn(
                            'block text-left text-[13px] transition-colors',
                            semContratoAtivo ? 'text-white font-semibold' : 'text-white/50 hover:text-white/80'
                        )}
                    >
                        {sem_contrato_count} empresa{sem_contrato_count === 1 ? '' : 's'} aguardando o Administrativo
                    </button>

                    {/* Busca, filtro por serviço e ordenação — mesma marcação da
                        barra de Comercial/EmpresasListagem.jsx (select nativo com
                        os tokens ecf-*), para as duas telas não divergirem. Os
                        três entram pelo mesmo `applyFilter`, que espalha
                        `...filters`, então combinam entre si e com o filtro de
                        situação dos cartões acima. */}
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative flex-1 min-w-[220px] max-w-md">
                            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
                            <Input
                                value={qInput}
                                onChange={onSearchChange}
                                placeholder="Buscar por empresa..."
                                className="pl-9 focus:border-ecf-yellow/40"
                            />
                        </div>

                        <select
                            value={filters.servico ?? ''}
                            onChange={(e) => applyFilter('servico', e.target.value)}
                            className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            aria-label="Filtrar por serviço"
                        >
                            <option value="" className="bg-[#0f1116]">Todos os serviços</option>
                            {servicos.map((s) => (
                                <option key={s.id} value={s.id} className="bg-[#0f1116]">{s.nome}</option>
                            ))}
                        </select>

                        <select
                            value={filters.ordenar ?? 'recente'}
                            onChange={(e) => applyFilter('ordenar', e.target.value)}
                            className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            aria-label="Ordenar a lista"
                        >
                            <option value="recente" className="bg-[#0f1116]">Empresa mais recente</option>
                            <option value="vencimento" className="bg-[#0f1116]">Término mais próximo</option>
                        </select>
                    </div>

                    {/* Tabela compacta */}
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Empresa</TableHead>
                                        {/* Plano 138-06 (COMERC-02) — 6 colunas novas dos 8 campos
                                            mínimos do §2 (2 já existiam: Empresa e Serviço). */}
                                        <TableHead className="text-[11px] uppercase tracking-wide">CNPJ</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Setor</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Responsável comercial</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Data da venda</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Etapa</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Pendências</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Serviço</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Situação</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Parado há</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Término</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {linhasData.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={12} className="text-center py-10">
                                                {/* O vazio precisa dizer QUAL recorte esvaziou a lista —
                                                    antes citava só a busca, e quem tinha esvaziado pelo
                                                    filtro de serviço lia uma explicação que não era a dele. */}
                                                {(filters.q || filters.servico || filters.situacao) ? (
                                                    <p className="text-[13px] text-white/40">
                                                        {filters.q
                                                            ? <>Nenhuma empresa encontrada para "{filters.q}" com os filtros atuais.</>
                                                            : <>Nenhuma empresa encontrada com os filtros atuais.</>}
                                                        {' '}Revise a busca, o serviço ou a situação escolhida.
                                                    </p>
                                                ) : (
                                                    <>
                                                        <p className="text-[13px] text-white/60 font-semibold">Nenhum contrato encontrado</p>
                                                        <p className="text-[12px] text-white/30 mt-1">
                                                            Ainda não há contratos administrativos registrados. Eles aparecem aqui assim que uma empresa completa o cadastro e o contrato é gerado.
                                                        </p>
                                                    </>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {linhasData.map((linha, idx) => (
                                        <TableRow
                                            key={`${linha.company_id}-${linha.servico_id}-${idx}`}
                                            onClick={() => router.visit(route('admin.contratos.show', linha.company_id))}
                                            className="cursor-pointer hover:bg-white/[0.03]"
                                        >
                                            <TableCell className="text-[13px] font-medium text-white/85">{linha.company_nome}</TableCell>
                                            <TableCell className="text-[13px] text-white/60">{linha.company_cnpj ?? '—'}</TableCell>
                                            <TableCell className="text-[13px] text-white/60">{linha.setor_dominante ?? '—'}</TableCell>
                                            {/* hubspot_owner_nome nulo é NORMAL: cadastro manual nunca
                                                teve deal no HubSpot, então nunca tem responsável
                                                comercial — travessão, nunca estado de erro. */}
                                            <TableCell className="text-[13px] text-white/60">{linha.hubspot_owner_nome ?? '—'}</TableCell>
                                            <TableCell className="text-[13px] text-white/60">
                                                {linha.data_venda ? formatDate(linha.data_venda) : '—'}
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/60">
                                                {linha.etapa ? (ETAPA_LABELS[linha.etapa] ?? linha.etapa) : 'Sem etapa (legado)'}
                                            </TableCell>
                                            {/* D-11 — pendência do fluxo e pendências do cadastro são
                                                DUAS COISAS visualmente distintas na mesma célula, nunca
                                                um número só somado. */}
                                            <TableCell onClick={(e) => e.stopPropagation()}>
                                                <div className="flex flex-wrap items-center gap-1 max-w-[220px]">
                                                    {linha.pendencia_fluxo?.aberta && (
                                                        <Badge
                                                            variant="destructive"
                                                            title={linha.pendencia_fluxo.motivo ?? undefined}
                                                        >
                                                            Pendência
                                                        </Badge>
                                                    )}
                                                    {(linha.pendencias_cadastro ?? []).map((slug) => (
                                                        <Badge key={slug} variant="warning">
                                                            {PENDENCIAS_LABELS[slug] ?? slug}
                                                        </Badge>
                                                    ))}
                                                    {!linha.pendencia_fluxo?.aberta && (linha.pendencias_cadastro ?? []).length === 0 && (
                                                        <span className="text-white/30">—</span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/60">{linha.servico_nome}</TableCell>
                                            <TableCell>
                                                <span
                                                    className={cn(
                                                        'inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border',
                                                        linha.status === SEM_CONTRATO ? classeContrato(linha.status) : classeContratoComPreparo(linha.status, linha.preparando, linha.montagem_travada),
                                                    )}
                                                    title={linha.montagem_travada ? MONTAGEM_TRAVADA_TITULO : (linha.preparando ? PREPARANDO_TITULO : undefined)}
                                                >
                                                    {linha.status === SEM_CONTRATO ? SEM_CONTRATO_LABEL : rotuloContratoComPreparo(linha.status, linha.preparando, linha.montagem_travada)}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/50">{formatarHaDias(linha.dias_parado)}</TableCell>
                                            {/* Término vazio é contrato por prazo indeterminado, não
                                                dado faltando — por isso "Sem prazo" e não "—". */}
                                            <TableCell className="text-[13px] text-white/50">
                                                {linha.data_vencimento
                                                    ? formatDate(linha.data_vencimento)
                                                    : <span className="text-white/30">Sem prazo</span>}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={route('admin.contratos.show', linha.company_id)}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="text-[12px] text-white/50 hover:text-white/80 hover:underline"
                                                >
                                                    Abrir
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Paginator paginator={linhas} />
                </div>
            </main>
        </AppLayout>
    );
}

// ─── Paginação Inertia (forward/back simples) — molde de Comercial/EmpresasListagem.jsx ─
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
                <Link
                    href={prev || '#'}
                    preserveScroll
                    preserveState
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !prev && 'opacity-30 pointer-events-none',
                    )}
                >
                    <ChevronLeft size={13} /> Anterior
                </Link>
                <Link
                    href={next || '#'}
                    preserveScroll
                    preserveState
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !next && 'opacity-30 pointer-events-none',
                    )}
                >
                    Próxima <ChevronRight size={13} />
                </Link>
            </div>
        </div>
    );
}
