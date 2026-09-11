import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, router } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import { ListChecks, Search, Webhook, ChevronLeft, ChevronRight } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';
import { rotuloContrato, classeContrato, formatarHaDias, SEM_CONTRATO, SEM_CONTRATO_LABEL } from '@/lib/contratoStatus';

// ─── Plano 151-08 (COMERC-02, D-11) — rótulos das 7 pendências comerciais.
// Mesmo bloco de Comercial/EmpresasListagem.jsx e Admin/Contratos.jsx — o
// projeto não tem enum compartilhado entre PHP e JS, a sincronia é manual.
// São 7 chaves, não 8 (Pitfall 5 do RESEARCH da Fase 151) — não inventar
// uma oitava aqui.
const PENDENCIAS_LABELS = {
    sem_servico:             'Sem serviço',
    sem_valor:               'Sem valor',
    servico_nao_reconhecido: 'Serviço não reconhecido',
    sem_setor:               'Sem setor (catálogo)',
    sem_contato:             'Sem contato',
    valor_revisar:           'Revisar valor',
    possivel_duplicidade:    'Possível duplicidade',
};

// ─── Fase 150 (ETAPA-05) — rótulos das 9 etapas do fluxo de entrada (§10 do
// PDF v23.0). Fonte de verdade é `Company::ETAPAS` (app/Models/Company.php)
// — mesmo bloco de Admin/Contratos.jsx e Pages/Companies/Index.jsx, para as
// telas não divergirem no vocabulário.
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

// Badge de origem — "empresa/processo" nasceu por webhook do HubSpot ou por
// cadastro manual do Comercial (D-13, as duas portas de nascimento).
function OrigemBadge({ origem }) {
    if (origem === 'hubspot') {
        return (
            <Badge variant="default" className="gap-1">
                <Webhook size={10} /> HubSpot
            </Badge>
        );
    }
    return <Badge variant="secondary">Manual</Badge>;
}

/**
 * Badge de status do contrato — mesmo molde de `ContratoBadge` em
 * `Comercial/EmpresasListagem.jsx`. NUNCA é link: o Comercial não tem
 * `admin.contratos`, um clique daria 403.
 */
function ContratoBadge({ badge }) {
    // Serviço isento (ex.: só Polos) não passa por contrato: travessão,
    // nunca "Aguardando Administrativo" (viraria fila fantasma sem saída).
    if (!badge) {
        return <span className="text-white/30" title="Este serviço não passa por contrato">—</span>;
    }
    if (badge.status === SEM_CONTRATO) {
        return (
            <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', classeContrato(badge.status))}>
                {SEM_CONTRATO_LABEL} {formatarHaDias(badge.dias)}
            </span>
        );
    }
    return (
        <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', classeContrato(badge.status))}>
            {rotuloContrato(badge.status)} {formatarHaDias(badge.dias)}
        </span>
    );
}

/**
 * Comercial/Entrada.jsx — Fase 151 Plano 08 (COMERC-02, D-01/D-02/D-06/D-11).
 *
 * Módulo Entrada dentro da Área Comercial: lista as empresas em fluxo de
 * entrada (etapas 1 a 4 do §10) com os 8 campos mínimos do §2.
 *
 * ### O checklist chegou — e não mora aqui (Fase 152)
 * Esta tela continua sendo LISTAGEM. O checklist administrativo é da Fase 152,
 * são **9** itens (a lista do §5, D-01) e não 8 como esta nota dizia antes, e
 * quem os mostra e opera é a ficha `admin.contratos.show`, alcançada pela ação
 * "Abrir" de cada linha (D-08). A ficha é ÚNICA para os dois módulos: a rota
 * aceita `admin.contratos` OU `comercial.entrada` (D-17), e é o próprio
 * payload dela que recorta a seção Contrato para quem não tem a permissão de
 * módulo.
 *
 * Componente REAL, nunca re-export puro — anti-padrão medido em
 * `.planning/learnings/painel-polos-status-e-meta.md:83-88`: o bundler
 * elimina o módulo do manifest do Vite e a rota morre em runtime com
 * "Unable to locate file in Vite manifest", sem falhar no `npm run build`.
 */
export default function Entrada({ companies, filters = {}, resumo = {} }) {
    // Busca com debounce — mesmo padrão de EmpresasListagem.jsx/Admin/Contratos.jsx,
    // evita um request por caractere digitado.
    const [qInput, setQInput] = useState(filters.q || '');
    const debounceRef = useRef(null);

    const applyFilter = (key, value) => {
        router.get(route('comercial.entrada.index'), {
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

    // Sincroniza qInput quando filters.q mudar (ex.: voltar via back button).
    useEffect(() => {
        setQInput(filters.q || '');
    }, [filters.q]);

    const linhas = companies?.data ?? [];

    return (
        <AppLayout title="Comercial · Entrada">
            <main className="p-6">
                <div className="space-y-4">
                    <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                        <ListChecks size={20} className="text-ecf-yellow" />
                        Entrada
                    </h1>

                    {/* D-06 — nada finge estar pronto: um bloco de texto avisando que
                        o checklist chega na Fase 152 é aceitável, um controle morto
                        (botão/coluna/ícone) não é. Sem controle interativo aqui. */}
                    <p className="text-[13px] text-white/50 max-w-3xl">
                        Empresas em fluxo de entrada — do momento em que a venda é fechada até a
                        conclusão de todo o processo administrativo (§10, etapas 1 a 4). Os itens
                        do checklist do módulo (grupo de WhatsApp, e-mail do colaborador, links,
                        mensagem de boas-vindas) chegam na Fase 152; esta tela ainda é só a
                        listagem.
                    </p>

                    {/* Busca + ordenação — mesmo padrão server-side de EmpresasListagem.jsx
                        e Admin/Contratos.jsx: nada de filtro em memória. */}
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative flex-1 min-w-[240px] max-w-md">
                            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
                            <Input
                                value={qInput}
                                onChange={onSearchChange}
                                placeholder="Buscar por nome ou CNPJ..."
                                className="pl-9 focus:border-ecf-yellow/40"
                            />
                        </div>
                        <select
                            value={filters.ordem || 'recentes'}
                            onChange={(e) => applyFilter('ordem', e.target.value)}
                            className="h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            aria-label="Ordenar a lista"
                        >
                            <option value="recentes" className="bg-[#0f1116]">Mais recentes</option>
                            <option value="antigas" className="bg-[#0f1116]">Mais antigas</option>
                        </select>
                    </div>

                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Empresa</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">CNPJ</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Serviços</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Setor</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Origem</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Responsável comercial</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Data da venda</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Contato</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Status do contrato</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Pendências</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Etapa</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {linhas.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={12} className="text-center py-10">
                                                {filters.q ? (
                                                    <p className="text-[13px] text-white/40">
                                                        Nenhuma empresa encontrada para "{filters.q}".
                                                    </p>
                                                ) : (
                                                    <>
                                                        <p className="text-[13px] text-white/60 font-semibold">
                                                            Nenhuma empresa em fluxo de entrada no momento.
                                                        </p>
                                                        <p className="text-[12px] text-white/30 mt-1">
                                                            Elas aparecem aqui assim que uma venda é marcada ganha no
                                                            HubSpot ou cadastrada manualmente pelo Comercial.
                                                        </p>
                                                    </>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {linhas.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell className="text-[13px] font-medium text-white/85">{c.name}</TableCell>
                                            <TableCell className="text-[13px] text-white/60">{c.cnpj ?? '—'}</TableCell>
                                            <TableCell className="text-[13px] text-white/60">
                                                {(c.servicos ?? []).length > 0 ? c.servicos.join(', ') : '—'}
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/60">{c.setor_dominante ?? '—'}</TableCell>
                                            <TableCell><OrigemBadge origem={c.origem} /></TableCell>
                                            {/* hubspot_owner_nome nulo é NORMAL: cadastro manual nunca teve
                                                deal no HubSpot, então nunca tem responsável comercial —
                                                travessão, nunca estado de erro nem alerta. */}
                                            <TableCell className="text-[13px] text-white/60">{c.hubspot_owner_nome ?? '—'}</TableCell>
                                            <TableCell className="text-[13px] text-white/60">
                                                {c.data_venda ? formatDate(c.data_venda) : '—'}
                                            </TableCell>
                                            <TableCell
                                                className="text-[13px] text-white/60"
                                                title={[c.telefone, c.email_cliente].filter(Boolean).join(' · ') || undefined}
                                            >
                                                {c.nome_contato ?? '—'}
                                            </TableCell>
                                            <TableCell><ContratoBadge badge={c.contrato_badge} /></TableCell>
                                            {/* D-11 — pendência do fluxo e pendências do cadastro são DUAS
                                                coisas visualmente distintas na mesma célula, nunca um
                                                número só somado. */}
                                            <TableCell onClick={(e) => e.stopPropagation()}>
                                                <div className="flex flex-wrap items-center gap-1 max-w-[220px]">
                                                    {c.pendencia_fluxo?.aberta && (
                                                        <Badge
                                                            variant="destructive"
                                                            title={c.pendencia_fluxo.motivo ?? undefined}
                                                        >
                                                            Pendência
                                                        </Badge>
                                                    )}
                                                    {(c.pendencias_cadastro ?? []).map((slug) => (
                                                        <Badge key={slug} variant="warning">
                                                            {PENDENCIAS_LABELS[slug] ?? slug}
                                                        </Badge>
                                                    ))}
                                                    {!c.pendencia_fluxo?.aberta && (c.pendencias_cadastro ?? []).length === 0 && (
                                                        <span className="text-white/30">—</span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/60">
                                                {c.etapa ? (ETAPA_LABELS[c.etapa] ?? c.etapa) : 'Sem etapa (legado)'}
                                            </TableCell>
                                            {/* D-08 — a MESMA ficha que o Administrativo abre pela
                                                listagem Contrato. Trecho copiado de Admin/Contratos.jsx
                                                de propósito: uma ficha só, um caminho só. */}
                                            <TableCell onClick={(e) => e.stopPropagation()}>
                                                <Link
                                                    href={route('comercial.entrada.show', c.id)}
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

                    <Paginator paginator={companies} />
                </div>
            </main>
        </AppLayout>
    );
}

// ─── Paginação Inertia (forward/back simples) — mesmo molde de Admin/Contratos.jsx ─
function Paginator({ paginator }) {
    if (!paginator || paginator.last_page <= 1) return null;
    const prev = paginator.prev_page_url;
    const next = paginator.next_page_url;
    return (
        <div className="flex items-center justify-between border-t border-white/[0.06] px-4 py-2 bg-white/[0.02]">
            <span className="text-white/40 text-[12px]">
                Página {paginator.current_page} de {paginator.last_page} — {paginator.total} empresas
            </span>
            <div className="flex items-center gap-1">
                <button
                    type="button"
                    disabled={!prev}
                    onClick={() => prev && router.visit(prev, { preserveScroll: true, preserveState: true })}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !prev && 'opacity-30 pointer-events-none',
                    )}
                >
                    <ChevronLeft size={13} /> Anterior
                </button>
                <button
                    type="button"
                    disabled={!next}
                    onClick={() => next && router.visit(next, { preserveScroll: true, preserveState: true })}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-lg border border-white/10 px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.05]',
                        !next && 'opacity-30 pointer-events-none',
                    )}
                >
                    Próxima <ChevronRight size={13} />
                </button>
            </div>
        </div>
    );
}
