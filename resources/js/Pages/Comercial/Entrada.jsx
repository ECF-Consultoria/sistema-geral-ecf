import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { formatDate } from '@/lib/utils';

// Fase 138 (COMERC-01/02/03, D-01/D-02/D-06) — casca do módulo Entrada.
// Lista as empresas em fluxo de entrada (etapas 1 a 4 do §10) com os 8
// campos mínimos do §2. NUNCA re-export puro de outra página (anti-padrão
// medido em `.planning/learnings/painel-polos-status-e-meta.md:83-88` — o
// bundler elimina o módulo do manifest do Vite e a rota morre em runtime
// com "Unable to locate file in Vite manifest"). O checklist dos 8 itens
// (grupo de WhatsApp, e-mail colaborador, links, mensagem de boas-vindas
// etc.) chega na Fase 139; os filtros/ações da tela chegam no plano 138-08
// deste mesmo plano — esta versão é a casca mínima funcional.
export default function Entrada({ companies }) {
    const linhas = companies?.data ?? [];

    return (
        <AppLayout title="Comercial · Entrada">
            <div className="p-6 space-y-4">
                <div>
                    <h1 className="text-xl font-semibold text-white">Entrada</h1>
                    <p className="text-sm text-white/60">
                        Empresas em fluxo de entrada — do momento em que a venda é fechada até a
                        conclusão de todo o processo administrativo. O checklist de itens
                        (grupo de WhatsApp, e-mail do colaborador, links, mensagem de boas-vindas)
                        chega na Fase 139; esta tela ainda é só a listagem.
                    </p>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>CNPJ</TableHead>
                                    <TableHead>Serviços</TableHead>
                                    <TableHead>Setor</TableHead>
                                    <TableHead>Origem</TableHead>
                                    <TableHead>Responsável comercial</TableHead>
                                    <TableHead>Data da venda</TableHead>
                                    <TableHead>Contato</TableHead>
                                    <TableHead>Contrato</TableHead>
                                    <TableHead>Pendência do fluxo</TableHead>
                                    <TableHead>Pendências do cadastro</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {linhas.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={11} className="text-center text-white/50 py-8">
                                            Nenhuma empresa em fluxo de entrada no momento.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {linhas.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell>{c.name}</TableCell>
                                        <TableCell>{c.cnpj ?? '—'}</TableCell>
                                        <TableCell>{(c.servicos ?? []).join(', ') || '—'}</TableCell>
                                        <TableCell>{c.setor_dominante ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={c.origem === 'hubspot' ? 'default' : 'secondary'}>
                                                {c.origem === 'hubspot' ? 'HubSpot' : 'Manual'}
                                            </Badge>
                                        </TableCell>
                                        {/* hubspot_owner_nome === null é NORMAL (cadastro manual nunca teve deal) — nunca tratar como erro. */}
                                        <TableCell>{c.hubspot_owner_nome ?? '—'}</TableCell>
                                        <TableCell>{c.data_venda ? formatDate(c.data_venda) : '—'}</TableCell>
                                        <TableCell>{c.nome_contato ?? '—'}</TableCell>
                                        <TableCell>{c.contrato_badge?.status ?? '—'}</TableCell>
                                        {/* D-11: pendência do fluxo e pendências do cadastro em colunas SEPARADAS, nunca somadas. */}
                                        <TableCell>
                                            {c.pendencia_fluxo?.aberta
                                                ? <Badge variant="destructive">{c.pendencia_fluxo.motivo ?? 'Pendente'}</Badge>
                                                : '—'}
                                        </TableCell>
                                        <TableCell>
                                            {(c.pendencias_cadastro ?? []).length > 0
                                                ? c.pendencias_cadastro.join(', ')
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
