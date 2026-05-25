<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class ImportCompaniesFromXlsx extends Command
{
    protected $signature   = 'companies:import-xlsx {--dry-run : Simula sem gravar}';
    protected $description = 'Importa empresas do xlsx accounts-1779733075231 (uso único)';

    // Nickname → CustId extraídos do xlsx accounts-1779733075231.xlsx
    private array $empresas = [
        ['RELOJOARIA_WENUS', '570267839'],
        ['DSG VARIEDADES', '734056579'],
        ['CAMILLOPARTSFILIALSCCAMILLO', '724397733'],
        ['GENUINEAUTOMOTIVE', '577803235'],
        ['SHOPPING_ECOMM2', '805635431'],
        ['PESQUISE DESCONTOS', '194497048'],
        ['FARMACIAVIDANATURALMANIPULE', '1169405259'],
        ['DEUSA COSMÉTICOS', '707299758'],
        ['ELLITEMEGASTORE', '278508769'],
        ['VIVACONFORTO', '220649509'],
        ['KAPRAKAZA.COM', '1078120806'],
        ['FEMAKBRASIL', '240993445'],
        ['SMARTBUY2749106', '1066675941'],
        ['TUDO_PARA_VOCE_E_SUA_CASA_3', '1551070596'],
        ['TUDO_PARA_VOCE_E_SUA_CASA_1', '1159541804'],
        ['USE4U_LOJA', '1487458262'],
        ['CAMILLOPARTS MATRIZ', '267873368'],
        ['EDUMAC PARTS', '290329489'],
        ['OXONBOX', '1421364758'],
        ['OX-ON', '166366500'],
        ['DG AUTOPARTS', '451654807'],
        ['BYMOBILLECOMERCIODEMOVEISL', '436501796'],
        ['MEGAMIX_ONLINE', '435138055'],
        ['LUCCAUTO.COM', '23616476'],
        ['RORIZMARCENARIA', '1160599556'],
        ['NNINTERIORES', '2000990296'],
        ['PARMAMOVEIS', '627710919'],
        ['JRJR20240417125157', '1772055405'],
        ['NITRO METAIS', '823756546'],
        ['CONF KATITUS', '273398055'],
        ['IMPERIOACESSORIOS2023', '1538805567'],
        ['K2 FIOS_CABOS', '114138900'],
        ['VSVS20230118104959', '1289180832'],
        ['DISPLAY-MAX', '314874662'],
        ['MILANIMOVELARIA', '1675401376'],
        ['ERIANELSHOP', '694305749'],
        ['ARBMULT', '661489725'],
        ['ARTIDÔNIO JOSÉ', '31259325'],
        ['LOJASHEEP', '2357207452'],
        ['JARADECOR', '2272186759'],
        ['HOMEDESIGNER', '1923007702'],
        ['RODRICALHAS', '1679239354'],
        ['ELPIS_MOVEIS', '1986322052'],
        ['NNMOVEISINTERIORES', '2429286880'],
        ['D.A DECOR', '663557212'],
        ['FULLBX', '2466080057'],
        ['ESTILUZ ILUMINACAO', '233511402'],
        ['APOLLOSMOVEIS', '1162877510'],
        ['TRWCAMILLOPARTS', '576276943'],
        ['NEPOMOVEIS', '2421093002'],
        ['VARELLAADILSONDESOUZAVARELL', '520283543'],
        ['CLICK_DECOR', '2463977064'],
        ['RACKBOARD', '80505015'],
        ['ELETRICAADR.COM', '293850899'],
        ['MXBSHOES', '1817486873'],
        ['CLICKBUYECOMM', '1767620878'],
        ['HIDRAMASTER_HIDRAULICOS', '1716828387'],
        ['WEHOUSE', '358180856'],
        ['ZURCDECOR.COM.BR', '649444951'],
        ['ATELIEDOCONFORTO', '1499963069'],
        ['FUTUCA_SP', '805989898'],
        ['MOBLIE', '2116859660'],
        ['ABSOLUTCARGOEPESCA', '96846274'],
        ['ADHARAPRINTSHOP FERNANDO', '462789629'],
        ['AMARISBRAND', '1518091839'],
        ['KAITONCOMERCIO', '2016630057'],
        ['SAITAMA E', '127818931'],
        ['ADVANZCOMMERCE', '1480097248'],
        ['USEWEAM', '1651097425'],
        ['BOXLISBOA', '1411460869'],
        ['MILANILENONN', '160518908'],
        ['ZMDISTRIBUIDORA', '272383863'],
        ['FACASERECHIM', '710041135'],
        ['PEDROSOPECAS', '426439359'],
        ['RFSTORE888', '1633567932'],
        ['MSFERRAM', '428595367'],
        ['AUTONEXX', '1414879544'],
        ['VISAMMER', '404937248'],
        ['SANTOSSIMOES', '1392222305'],
        ['JOYPLANET', '2410087059'],
        ['DIFFERENT MIXX', '382368355'],
        ['FUTUCACOMERCIO', '75188345'],
        ['FUTUCAMG', '2459133414'],
        ['RHINOAUTOPARTS', '53770288'],
        ['MOVELOVEOFICIAL', '268200296'],
        ['MOVESHOW', '390792232'],
        ['VINOWIPEREMOVEDOR', '1761617589'],
        ['GRAN BELO', '433720509'],
        ['MPOZENATO', '17050324'],
        ['LAURA LAR.', '273196837'],
        ['POZELAR', '98355168'],
        ['RHINO SP', '163428374'],
        ['LYAMDECOR', '128163338'],
        ['DROSSI INTERIORES', '28749980'],
        ['DIPLANY', '177237120'],
        ['ROSSI DECOR', '251682125'],
        ['VV20251023145803', '2943589813'],
        ['RENOVAFER', '1845591926'],
        ['CL20251113082537', '2987502187'],
        ['LUIZ-AUTO-ME', '203710548'],
        ['LUCCMAX', '1039099160'],
        ['ITUFARMA1', '1941081291'],
        ['IMAGINARIOCASAEACESSORIOS', '262823234'],
        ['PRADO FERRAGENS', '125675645'],
        ['RHINO ES', '1378538842'],
        ['BARAOSHOP VARIEDADES', '91464987'],
        ['CAPIVO', '473365192'],
        ['DESK DESIGN', '51493328'],
        ['TOTAL-PARTS-CONTA', '221947051'],
        ['SPECIALE HOME', '540459947'],
        ['MOBILIAE', '2263265805'],
        ['MAXIGOLD SUPLEMENTOS', '1147495456'],
        ['JBDECORHOME', '1123741754'],
        ['MG MÓVEIS', '312480620'],
        ['RR20250826190645', '2654037900'],
        ['TOMELIN ARAMADOS', '431707006'],
        ['IMPERIALECOMMERCEOFICIAL', '1356665418'],
        ['IMPERIALECOMMERCE02', '2506964236'],
        ['KAMATZUSC', '3242580153'],
        ['CARAIBAALUMINIO', '1107394917'],
        ['AVF_2K_COMERCIAL', '2674179141'],
        ['STARCONFORT', '728881623'],
        ['SHOPCABECEIRAS', '1289169658'],
        ['TSOCKS BRASIL', '1316350461'],
        ['USEMILLE', '373784852'],
        ['SPINELLADECOR', '3223656591'],
        ['RCLCOMERCIO', '1201778632'],
        ['IMPORTSDANTAS2', '1489211668'],
        ['ALCOMERCIOEIMPORTACAO', '820887953'],
        ['MOBILEMOTOS', '662520165'],
        ['PETSHOPBRASIL', '557945769'],
        ['RPM_MARKETPLACE', '2204524556'],
        ['VSHOSPITALAR', '2383061020'],
        ['EZIOFREDIANI', '162401487'],
        ['GARCIAAUTOPECASOFICIAL', '2147641869'],
        ['Kaiton Comercio LTDA', '578879169226495'],
        ['OLIVEIRA_AUTO_PECAS', '1335157078'],
        ['LUCCAUTO', '340540329'],
        ['KATITUS', '426052771'],
        ['CAMILLO PARTS', '1237026799'],
        ['GENUINE AUTOMOTIVE', '349667948'],
        ['brav utilidades', '573852348'],
        ['WEHOUSE.COM.BR', '1423420147'],
        ['TKJ DECORAÇÕES', '1135724283'],
        ['ZM Distribuidora Automotiva LTDA.', '1637975054'],
        ['FACAS ERECHIM LTDA', '217467701'],
        ['contatokaiton', '1373656065'],
        ['Santos Simões', '738365196'],
        ['Saitama Ecom', '1134034927'],
        ['ByMobille', '1246437201'],
        ['Mpozenato', '375188138'],
        ['Gran Belo', '406699478'],
        ['Laura Lar', '1081500407'],
        ['Lyam Decor | Loja', '1107280577'],
        ["D'Rossi Interiores", '348584331'],
        ['Rhino Auto Parts', '655402127'],
        ['BOSS AUTO CARE', '1246634808'],
        ['ITUFARMA', '1598197093'],
        ['Rhino Auto Parts ES', '1060585622'],
        ['Baraoshop Variedades', '451455097'],
        ['ERIANELSHOP', '1234939581'],
        ['BQ Modas', '1010510141'],
        ['Autonexx Prime', '1464009232'],
        ['Rhino Autoparts', '1528304855'],
        ['Mobiliaê', '1473876265'],
        ['Tuki Pet', '944253608'],
        ['MATROM KIDS', '1559277346'],
        ['FRANCO TECIDOS E RETALHOS', '1225425014'],
        ['Tuki Vet', '1088545040'],
    ];

    public function handle(): int
    {
        $dryRun  = $this->option('dry-run');
        $criados = 0;
        $pulados = 0;

        foreach ($this->empresas as [$nome, $custId]) {
            $existe = Company::where('adman_account_id', $custId)->exists();

            if ($existe) {
                $this->line("  PULADO  [{$custId}] {$nome}");
                $pulados++;
                continue;
            }

            if (!$dryRun) {
                Company::create([
                    'name'             => trim($nome),
                    'adman_account_id' => $custId,
                    'service_type'     => ['gestao'],
                    'status'           => 'ativo',
                    'active'           => true,
                ]);
            }

            $this->info("  CRIADO  [{$custId}] {$nome}");
            $criados++;
        }

        $this->newLine();
        $this->table(['Resultado', 'Qtd'], [
            ['Criadas', $criados],
            ['Puladas (já existiam)', $pulados],
            ['Total processado', $criados + $pulados],
        ]);

        if ($dryRun) {
            $this->warn('Modo dry-run — nenhum registro foi gravado.');
        }

        return self::SUCCESS;
    }
}
