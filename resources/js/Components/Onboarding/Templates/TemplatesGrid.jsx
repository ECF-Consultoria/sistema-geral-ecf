import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Users, ListChecks } from 'lucide-react';
import { formatDate } from '@/lib/utils';

/**
 * TemplatesGrid — um card por serviço, modo lista da Tela 2 (135-UI-SPEC).
 *
 * Cada card mostra a versão publicada atual (ou o empty state, quando o
 * serviço ainda não tem template) e um botão que abre o modo edição — que ao
 * publicar cria a versão N+1, nunca edita in-place (D-07).
 */
export default function TemplatesGrid({ servicos, onEditar }) {
    if (!servicos?.length) {
        return (
            <div className="rounded-xl border border-dashed border-white/[0.1] bg-white/[0.01] p-10 text-center">
                <p className="text-white/40 text-sm">Nenhum serviço ativo cadastrado.</p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            {servicos.map((servico) => (
                <Card key={servico.id} className="bg-ecf-card border-white/[0.08]">
                    <CardHeader className="pb-2 flex-row items-center gap-2 space-y-0">
                        <ListChecks size={16} className="text-white/40 shrink-0" />
                        <h3 className="text-white font-display font-bold text-lg truncate">
                            {servico.nome}
                        </h3>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {servico.template ? (
                            <>
                                <p className="text-white/70 text-[13px]">
                                    Versão {servico.template.versao} · publicada em{' '}
                                    {formatDate(servico.template.publicado_em)}
                                </p>
                                <p className="text-white/50 text-[13px] flex items-center gap-1.5">
                                    <Users size={13} className="shrink-0" />
                                    {servico.template.onboardings_ativos_count}{' '}
                                    {servico.template.onboardings_ativos_count === 1
                                        ? 'onboarding ativo nesta versão'
                                        : 'onboardings ativos nesta versão'}
                                </p>
                                <Button type="button" onClick={() => onEditar(servico)} className="w-full">
                                    Editar template
                                </Button>
                            </>
                        ) : (
                            <>
                                {/*
                                    Copy exata do Copywriting Contract (135-UI-SPEC.md).
                                    Escrita literal (não interpolada com servico.nome) de
                                    propósito: o contrato hardcoda "Gestão" porque o v1 desta
                                    fase cobre só o template de Gestão (escopo travado) — não
                                    é um bug esquecer de generalizar, é a copy aprovada.
                                */}
                                <div className="space-y-1">
                                    <p className="text-white/80 text-sm font-semibold">
                                        Gestão ainda não tem template publicado
                                    </p>
                                    <p className="text-white/50 text-[13px]">
                                        Monte os passos do checklist e clique em Publicar versão para
                                        ativar o onboarding automático deste serviço.
                                    </p>
                                </div>
                                <Button type="button" onClick={() => onEditar(servico)} className="w-full">
                                    Criar template
                                </Button>
                            </>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
