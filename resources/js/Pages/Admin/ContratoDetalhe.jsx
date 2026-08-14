import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Building2, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { classeContrato, rotuloContrato, formatarHaDias } from '@/lib/contratoStatus';

/**
 * Admin/ContratoDetalhe.jsx — Fase 131 Plano 04 (D-01/D-03/D-11, ADM-01/02,
 * UI-02).
 *
 * A tela de detalhe da empresa (D-01): página cheia (não painel lateral, não
 * edição inline) onde o Administrativo completa o que o Comercial deixou
 * pela metade (ADM-01/ADM-02) e dispara a geração do contrato quando está
 * tudo pronto (UI-02).
 *
 * D-03 — quando há pendência, o botão "Gerar contrato" fica VISÍVEL e
 * DESABILITADO, com a lista do que falta ao lado — nunca escondido.
 *
 * D-11 — a falta do e-mail do colaborador aparece como pendência destacada,
 * mas NÃO desabilita o botão: contrato e acesso à conta do Mercado Livre são
 * coisas diferentes. `email_colaborador` fica fora de `faltantes`.
 *
 * A tela NÃO recalcula elegibilidade — `pode_gerar_contrato`, `faltantes` e
 * `motivo_bloqueio` vêm prontos do backend, únicas fontes.
 *
 * ⚠️ A coluna "Ações" da lista de contratos fica preparada e vazia: reenviar
 * aviso/ajustar quem assina/registrar cancelamento entram no plano 131-05,
 * liberar manualmente no 131-06. Não criar botão sem rota — `route()` do
 * Ziggy lança e derruba a tela inteira.
 */
export default function ContratoDetalhe({
    company,
    contratos_servico = [],
    faltantes = [],
    email_colaborador_pendente = false,
    configuracao_ecf_faltante = [],
    pode_gerar_contrato = false,
    motivo_bloqueio = null,
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
                                    {contratos.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell className="text-[13px] text-white/85">{c.servico_nome}</TableCell>
                                            <TableCell>
                                                <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', classeContrato(c.status))}>
                                                    {rotuloContrato(c.status)}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-[13px] text-white/50">{formatarHaDias(c.dias_parado)}</TableCell>
                                            {/* Ações: preparada e vazia — ver docblock do topo. */}
                                            <TableCell className="text-[13px] text-white/20">—</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </main>
        </AppLayout>
    );
}
