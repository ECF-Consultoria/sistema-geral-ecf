import { CheckCircle } from 'lucide-react';

export default function ThankYou() {
    return (
        <div className="min-h-screen bg-background flex items-center justify-center p-4">
            <div className="text-center space-y-4 max-w-sm">
                <CheckCircle className="h-20 w-20 text-emerald-400 mx-auto" />
                <h1 className="text-2xl font-bold text-foreground">Obrigado pela avaliação!</h1>
                <p className="text-muted-foreground">
                    Sua resposta foi registrada com sucesso. O feedback é muito importante para continuarmos melhorando nosso atendimento.
                </p>
                <p className="text-muted-foreground text-sm">ECF Consultoria</p>
            </div>
        </div>
    );
}
