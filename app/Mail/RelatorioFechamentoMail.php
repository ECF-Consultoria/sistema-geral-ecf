<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Browsershot\Browsershot;

/**
 * Mailable do Relatório Geral de Fechamento.
 * Corpo: HTML resumido com tabela de empresas.
 * Anexo: PDF gerado via Browsershot (Chrome headless) — mesmo layout do "Gerar Relatório".
 */
class RelatorioFechamentoMail extends Mailable
{
    use Queueable, SerializesModels;

    // Caminho do Chrome — lido de config/pdf.php (que lê CHROME_BINARY_PATH do .env)
    // Usar config() e não env() diretamente para funcionar com config:cache
    private function chromePath(): string
    {
        return config('pdf.chrome_binary', '/usr/bin/google-chrome');
    }

    public function __construct(public array $dados)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Relatório de Fechamento — ' . ($this->dados['mesLabel'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.relatorio-fechamento',
            with: ['dados' => $this->dados],
        );
    }

    public function attachments(): array
    {
        $mesLabel    = $this->dados['mesLabel'] ?? 'fechamento';
        $nomeArquivo = 'relatorio-' . str($mesLabel)->slug() . '.pdf';

        $pdfContent = $this->gerarPdf($mesLabel);

        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $pdfContent,
                $nomeArquivo,
            )->withMime('application/pdf'),
        ];
    }

    /**
     * Renderiza o mesmo HTML do "Gerar Relatório" e converte para PDF via Chrome headless.
     * O logo é embutido como base64 para evitar dependência de rede durante a geração.
     */
    private function gerarPdf(string $mesLabel): string
    {
        // Apache/www-data muitas vezes não tem HOME definido; Chrome precisa para criar perfil temporário
        if (!getenv('HOME')) {
            putenv('HOME=/tmp');
        }

        $geradoEm = Carbon::now()->setTimezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i');

        $html = view('admin.relatorio-geral', [
            'relatorios'      => $this->dados['relatorios'],
            'mes_label'       => $mesLabel,
            'mes_selecionado' => '',
            'gerado_em'       => $geradoEm,
            'filtro_recebido' => '',
        ])->render();

        // Substitui a URL do logo pela versão base64 para funcionar sem rede
        $logoPath = public_path('images/logo.png');
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
            $html = str_replace(asset('images/logo.png'), $logoBase64, $html);
        }

        // Remove o script de auto-print (window.print) e o botão fixo
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<button[^>]*class="print-btn"[^>]*>.*?<\/button>/is', '', $html);

        try {
            return Browsershot::html($html)
                ->setChromePath($this->chromePath())
                ->noSandbox()
                ->addChromiumArguments([
                    'disable-dev-shm-usage',
                    'disable-gpu',
                    'disable-setuid-sandbox',
                    'disable-extensions',
                    'no-first-run',
                    'no-default-browser-check',
                    'user-data-dir=/tmp/browsershot-chrome',
                ])
                ->emulateMedia('print')
                ->format('A4')
                ->margins(10, 12, 10, 12)
                ->timeout(60)
                ->pdf();
        } catch (\Throwable $e) {
            // Loga o erro completo incluindo stdout/stderr do Chrome para diagnóstico
            \Illuminate\Support\Facades\Log::error('[Fechamento] Erro ao gerar PDF: ' . $e->getMessage(), [
                'chrome_path' => $this->chromePath(),
                'home' => getenv('HOME'),
                'process_output' => method_exists($e, 'getProcess') ? $e->getProcess()->getOutput() : null,
                'process_error'  => method_exists($e, 'getProcess') ? $e->getProcess()->getErrorOutput() : null,
            ]);
            throw $e;
        }
    }
}
