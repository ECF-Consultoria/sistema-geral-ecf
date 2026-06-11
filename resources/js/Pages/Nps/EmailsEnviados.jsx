import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/Components/ui/select';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/Components/ui/table';
import { Link, router } from '@inertiajs/react';
import { Fragment, useState, useEffect, useRef } from 'react';
import {
    Inbox, Search, ExternalLink, AlertTriangle, CheckCircle2, ChevronDown, ChevronRight,
} from 'lucide-react';
import { cn, formatDateTime } from '@/lib/utils';

/**
 * Phase 32 Plan 04 — Pagina admin /nps/emails-enviados.
 *
 * Lista paginada dos disparos do comando `nps:disparar-mensal` (registrados em
 * `nps_email_envios` pelo Plan 32-01). Filtros minimos (D-06): mes + busca.
 *
 * - Header: titulo + total do mes selecionado
 * - Filtros: Select de mes (ultimos 12) + Input de busca com debounce 300ms
 * - Tabela: data, empresa, destinatario, assunto, status (badge), survey link
 * - Linhas de falha permitem expandir o `erro_msg`
 * - Paginacao 25/pg preservando ?mes e ?q
 */
export default function EmailsEnviados({
    envios,
    mes,
    q = '',
    meses_disponiveis = [],
    total_mes = 0,
}) {
    // Estado local do input de busca — debounce manda a query so depois de 300ms
    const [busca, setBusca] = useState(q ?? '');
    const debounceRef = useRef(null);
    // Linhas expandidas (para mostrar erro_msg em status=falha)
    const [expanded, setExpanded] = useState({});

    // ─── Debounce do input de busca ──────────────────────────────────────────
    // So dispara navegacao quando o usuario para de digitar por 300ms. Evita
    // 1 request por tecla. Preserva o mes selecionado.
    useEffect(() => {
        // Se o valor digitado eh igual ao prop atual, nao precisa navegar
        // (acontece no carregamento inicial e nos retornos de navegacao).
        if (busca === (q ?? '')) return;

        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            router.get(
                route('nps.emails-enviados.index'),
                { mes, q: busca },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca]);

    // Troca de mes recarrega imediatamente preservando a busca atual
    const handleMesChange = (novoMes) => {
        router.get(
            route('nps.emails-enviados.index'),
            { mes: novoMes, q: busca },
            { preserveState: true, preserveScroll: true },
        );
    };

    // Label "Junho/2026" a partir do `mes` (YYYY-MM) — usado no header
    const mesLabelHeader = (() => {
        const opt = meses_disponiveis.find(m => m.value === mes);
        return opt?.label ?? mes;
    })();

    // Toggle de expansao da linha de erro
    const toggleExpand = (id) => {
        setExpanded(prev => ({ ...prev, [id]: !prev[id] }));
    };

    // Trunca o assunto preservando o original no `title` (tooltip nativo)
    const truncate = (txt, n = 60) => {
        if (!txt) return '—';
        return txt.length > n ? txt.slice(0, n - 1) + '…' : txt;
    };

    const data = envios?.data ?? [];
    const currentPage = envios?.current_page ?? 1;
    const lastPage = envios?.last_page ?? 1;

    // Navegacao da paginacao preserva mes + q
    const goToPage = (page) => {
        router.get(
            route('nps.emails-enviados.index'),
            { mes, q: busca, page },
            { preserveState: true, preserveScroll: false },
        );
    };

    return (
        <AppLayout title="Emails NPS enviados">
            <div className="space-y-5">

                {/* ─── Header com titulo + total do mes ───────────────────── */}
                <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
                    <div>
                        <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-1">
                            NPS Mensal · Log de envios
                        </p>
                        <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">
                            Emails NPS enviados
                        </h1>
                        <p className="text-white/40 text-sm mt-1">
                            {total_mes} envio{total_mes === 1 ? '' : 's'} em {mesLabelHeader}
                        </p>
                    </div>
                </div>

                {/* ─── Filtros: mes (Select) + busca (Input) ──────────────── */}
                <div className="flex flex-col md:flex-row gap-3 md:items-center">
                    <div className="flex items-center gap-2">
                        <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">
                            Mês
                        </p>
                        <Select value={mes} onValueChange={handleMesChange}>
                            <SelectTrigger className="w-[160px] bg-ecf-card border-white/[0.08]">
                                <SelectValue placeholder="Selecionar mês..." />
                            </SelectTrigger>
                            <SelectContent>
                                {meses_disponiveis.map(m => (
                                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2 flex-1 md:max-w-md">
                        <Search className="h-4 w-4 text-white/40" />
                        <Input
                            type="text"
                            value={busca}
                            onChange={e => setBusca(e.target.value)}
                            placeholder="Buscar por empresa ou email…"
                            className="bg-ecf-card border-white/[0.08] text-sm"
                        />
                    </div>
                </div>

                {/* ─── Tabela de envios ──────────────────────────────────── */}
                <Card className="bg-ecf-card border-white/[0.08]">
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[40px]"></TableHead>
                                    <TableHead>Data</TableHead>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Destinatário</TableHead>
                                    <TableHead>Assunto</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Survey</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center py-10 text-muted-foreground">
                                            <Inbox className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            Nenhum envio NPS para o mês selecionado
                                        </TableCell>
                                    </TableRow>
                                )}

                                {data.map(envio => {
                                    const isFalha = envio.status === 'falha';
                                    const isExpanded = !!expanded[envio.id];
                                    const podeVerSurvey = !isFalha && envio.survey && envio.survey.token;

                                    return (
                                        <Fragment key={envio.id}>
                                            <TableRow className={cn(isFalha && 'bg-red-500/[0.02]')}>
                                                <TableCell className="w-[40px]">
                                                    {isFalha && envio.erro_msg && (
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleExpand(envio.id)}
                                                            className="text-white/40 hover:text-white"
                                                            aria-label={isExpanded ? 'Recolher erro' : 'Expandir erro'}
                                                        >
                                                            {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                        </button>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm whitespace-nowrap">
                                                    {formatDateTime(envio.created_at)}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {envio.company ? (
                                                        <Link
                                                            href={`/companies/${envio.company.id}`}
                                                            target="_blank"
                                                            className="text-white hover:text-ecf-yellow inline-flex items-center gap-1"
                                                        >
                                                            {envio.company.name}
                                                            <ExternalLink className="h-3 w-3 opacity-50" />
                                                        </Link>
                                                    ) : (
                                                        <span className="text-white/40">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm text-white/70">
                                                    {envio.destinatario || '—'}
                                                </TableCell>
                                                <TableCell className="text-sm max-w-[280px]">
                                                    <span className="block truncate text-white/70" title={envio.assunto || ''}>
                                                        {truncate(envio.assunto, 60)}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {isFalha ? (
                                                        <Badge variant="destructive" className="gap-1">
                                                            <AlertTriangle className="h-3 w-3" /> Falha
                                                        </Badge>
                                                    ) : (
                                                        <Badge className="bg-emerald-500/15 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/20 gap-1">
                                                            <CheckCircle2 className="h-3 w-3" /> Enviado
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {podeVerSurvey ? (
                                                        <a
                                                            href={`/nps/${envio.survey.token}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex"
                                                        >
                                                            <Button size="sm" variant="outline" className="h-7 text-xs">
                                                                Ver
                                                                <ExternalLink className="h-3 w-3 ml-1" />
                                                            </Button>
                                                        </a>
                                                    ) : (
                                                        <span className="text-white/30 text-xs">—</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>

                                            {/* Linha expandida com o erro_msg em status=falha */}
                                            {isFalha && isExpanded && envio.erro_msg && (
                                                <TableRow className="bg-red-500/[0.04]">
                                                    <TableCell colSpan={7} className="text-xs text-red-300 font-mono whitespace-pre-wrap py-3 px-6">
                                                        <span className="text-red-400/70 font-sans text-[10px] tracking-widest uppercase block mb-1">
                                                            Detalhe do erro
                                                        </span>
                                                        {envio.erro_msg}
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

                {/* ─── Paginacao (preserva mes + q) ───────────────────────── */}
                {lastPage > 1 && (
                    <div className="flex items-center justify-center gap-3">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={currentPage <= 1}
                            onClick={() => goToPage(currentPage - 1)}
                        >
                            Anterior
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            Página {currentPage} de {lastPage}
                            {envios.total > 0 && (
                                <span className="text-white/30 ml-2">· {envios.total} no total</span>
                            )}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={currentPage >= lastPage}
                            onClick={() => goToPage(currentPage + 1)}
                        >
                            Próxima
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
