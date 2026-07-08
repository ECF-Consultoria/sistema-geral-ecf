import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatCurrency, formatPercent } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { BarChart2, Star, AlertTriangle, DollarSign, Users } from 'lucide-react';
// Phase 72 Plan 03 v15.0 — Widget de empresas pendentes de NPS na carteira do analista/estrategista
import NpsPendingWidget from '@/Components/Nps/NpsPendingWidget';

const PERIOD_OPTIONS = [
    { value: '1', label: 'Último dia' },
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '180', label: 'Últimos 6 meses' },
];

export default function UserDashboard({ stats, period, companies, my_surveys, my_ppas, sugadores_pendentes_carteira = 0, nps_pendentes = [] }) {
    // Phase 72 Plan 03 v15.0 — Guard defensivo pra prop nps_pendentes (Plan 72-02 injection)
    const npsPendentesList = nps_pendentes ?? [];
    return (
        <AppLayout title="Dashboard">
            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Select value={period} onValueChange={v => router.get(route('dashboard'), { period: v }, { preserveState: true })}>
                        <SelectTrigger className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PERIOD_OPTIONS.map(o => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                    {[
                        { label: 'Empresas', value: stats.total_companies, icon: Users, color: 'text-blue-400', sub: 'Sua carteira' },
                        { label: 'TACOS Médio', value: formatPercent(stats.avg_tacos), icon: BarChart2, color: 'text-yellow-400', sub: 'Últimos 30 dias · Adman' },
                        { label: 'NPS Médio', value: stats.avg_nps, icon: Star, color: 'text-emerald-400', sub: null },
                        { label: 'Absenteísmo', value: formatPercent(stats.absenteeism_rate), icon: AlertTriangle, color: 'text-red-400', sub: null },
                        { label: 'Faturamento', value: formatCurrency(stats.total_revenue), icon: DollarSign, color: 'text-purple-400', sub: 'Últimos 30 dias · Adman' },
                    ].map(({ label, value, icon: Icon, color, sub }) => (
                        <Card key={label}>
                            <CardContent className="p-5 flex items-center gap-3">
                                <Icon className={`h-8 w-8 ${color} shrink-0`} />
                                <div>
                                    <p className="text-muted-foreground text-xs">{label}</p>
                                    <p className={`text-xl font-bold ${color}`}>{value}</p>
                                    {sub && <p className="text-muted-foreground/60 text-[10px] mt-0.5">{sub}</p>}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card>
                        <CardHeader><CardTitle className="text-base">Minhas Empresas</CardTitle></CardHeader>
                        <CardContent className="space-y-3 max-h-80 overflow-y-auto">
                            {companies.map(c => (
                                <div key={c.id} className="flex items-center justify-between py-2 border-b border-border last:border-0">
                                    <div>
                                        <p className="text-sm font-medium">{c.name}</p>
                                        {c.goals?.length > 0 && (
                                            <p className="text-xs text-muted-foreground">{c.goals.length} meta(s) ativa(s)</p>
                                        )}
                                    </div>
                                    <div className="text-right space-y-0.5">
                                        <p className="text-xs">TACOS: <span className="text-yellow-400">{c.tacos ? `${Number(c.tacos).toFixed(2)}%` : '-'}</span></p>
                                        <p className="text-xs">Fat: <span className="text-blue-400">{c.revenue ? formatCurrency(c.revenue) : '-'}</span></p>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">Últimas Pesquisas NPS</CardTitle></CardHeader>
                        <CardContent className="space-y-2 max-h-80 overflow-y-auto">
                            {my_surveys.map(s => (
                                <div key={s.id} className="flex items-center justify-between py-2 border-b border-border last:border-0">
                                    <div>
                                        <p className="text-sm font-medium">{s.company?.name}</p>
                                        <p className="text-xs text-muted-foreground">{s.created_at}</p>
                                    </div>
                                    <Badge variant={s.status === 'completed' ? 'success' : s.status === 'expired' ? 'destructive' : 'secondary'}>
                                        {s.status === 'completed' ? 'Respondido' : s.status === 'expired' ? 'Expirado' : 'Pendente'}
                                    </Badge>
                                </div>
                            ))}
                            {my_surveys.length === 0 && <p className="text-muted-foreground text-sm text-center py-4">Nenhuma pesquisa enviada</p>}
                        </CardContent>
                    </Card>
                </div>

                {my_ppas.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Meus PPAs Recentes</CardTitle></CardHeader>
                        <CardContent className="space-y-2">
                            {my_ppas.map(p => (
                                <div key={p.id} className="flex items-center justify-between py-2 border-b border-border last:border-0">
                                    <div>
                                        <p className="text-sm font-medium">{p.title}</p>
                                        <p className="text-xs text-muted-foreground">{p.company?.name}</p>
                                    </div>
                                    <Badge variant={p.status === 'completed' ? 'success' : p.status === 'sent' ? 'default' : 'secondary'}>
                                        {p.status === 'completed' ? 'Concluído' : p.status === 'sent' ? 'Enviado' : 'Rascunho'}
                                    </Badge>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {sugadores_pendentes_carteira > 0 && (
                    <Card className="border-red-500/30 bg-red-500/[0.02]">
                        <CardContent className="p-5 flex items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <AlertTriangle className="h-8 w-8 text-red-400 shrink-0" />
                                <div>
                                    <p className="text-sm font-medium">
                                        {sugadores_pendentes_carteira} sugador{sugadores_pendentes_carteira !== 1 ? 'es' : ''} pendente{sugadores_pendentes_carteira !== 1 ? 's' : ''} na sua carteira
                                    </p>
                                    <p className="text-muted-foreground text-xs mt-0.5">
                                        Anúncios ou campanhas drenando investimento sem retorno — vale revisar
                                    </p>
                                </div>
                            </div>
                            <button
                                onClick={() => router.visit(route('sugadores.index', { status: 'pendente' }))}
                                className="inline-flex items-center gap-1.5 px-3 h-8 rounded-md text-xs font-semibold bg-red-500/15 text-red-300 border border-red-500/30 hover:bg-red-500/25"
                            >
                                Ver agora
                            </button>
                        </CardContent>
                    </Card>
                )}

                {/* Phase 72 Plan 03 v15.0 — Widget de empresas pendentes de NPS este mês.
                    Escopo restrito à carteira do usuário (analista/estrategista) via
                    NpsPendingService::forCarteira. Empty state tratado dentro do widget. */}
                <NpsPendingWidget pendentes={npsPendentesList} />
            </div>
        </AppLayout>
    );
}
