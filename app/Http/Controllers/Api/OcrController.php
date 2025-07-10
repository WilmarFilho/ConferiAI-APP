<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;
use OpenAI\Laravel\Facades\OpenAI;

use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;

class OcrController extends Controller
{
    /**
     * Ponto de entrada principal: processa a imagem, extrai os dados, consulta os resultados e verifica a premiação.
     */
    public function processarImagem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $imageAnnotator = null;

        try {
            $imageContent = file_get_contents($request->file('file'));
            
            // 1. Extração do texto bruto com Google Vision
            $imageAnnotator = new ImageAnnotatorClient();
            $image = (new Image())->setContent($imageContent);
            $feature = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);
            $annotateImageRequest = (new AnnotateImageRequest())->setImage($image)->setFeatures([$feature]);
            $batchRequest = (new BatchAnnotateImagesRequest())->setRequests([$annotateImageRequest]);
            $response = $imageAnnotator->batchAnnotateImages($batchRequest);
            $annotation = $response->getResponses()[0]->getFullTextAnnotation();

            if (!$annotation) {
                 return response()->json(['message' => 'Nenhum texto foi detectado na imagem.', 'text_bruto' => ''], 200);
            }

            $textoCompleto = $annotation->getText();

            // 2. Extração inteligente dos dados do recibo usando IA
            $dadosRecibo = $this->extrairDadosDoReciboComIA($textoCompleto);

            if (empty($dadosRecibo['concurso']) || empty($dadosRecibo['tipoJogo'])) {
                 return response()->json([
                    'Mensagem' => 'Não foi possível identificar o tipo de jogo ou o número do concurso no recibo.',
                    'Texto Bruto' => $textoCompleto,
                    'Apostas' => $dadosRecibo['apostas'] ?? []
                 ], 200);
            }

            // 3. Consulta do resultado oficial na API da Caixa
            $resultadoOficial = $this->consultarResultadoCaixa($dadosRecibo['tipoJogo'], $dadosRecibo['concurso']);
            
            // 4. Verificação detalhada da premiação
            $verificacao = $this->verificarPremiacao($dadosRecibo['apostas'], $resultadoOficial);
            
            $mensagemFinal = $verificacao['foiPremiado'] 
                ? "Parabéns! Você tem uma ou mais apostas premiadas."
                : "Não foi dessa vez. Nenhuma aposta premiada.";

            // 5. Montagem da resposta final completa e detalhada
            return response()->json([
                'Mensagem' => $mensagemFinal,
                'Concurso' => $resultadoOficial['numero'],
                'TipoJogo' => $resultadoOficial['tipoJogo'],
                'NumerosSorteados' => $resultadoOficial['listaDezenas'],
                'FoiPremiado' => $verificacao['foiPremiado'],
                'ValorPremioTotal' => $verificacao['valorTotalPremio'],
                'ResultadosPorAposta' => $verificacao['resultadosDetalhados'],
                'Texto Bruto' => $textoCompleto,
            ], 200);

        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'error' => 'Ocorreu uma falha inesperada no processamento.',
                'details' => $e->getMessage()
            ], 500);
        } finally {
            if ($imageAnnotator) {
                $imageAnnotator->close();
            }
        }
    }
    
    /**
     * Usa a IA para extrair tipo de jogo, concurso e apostas do texto do OCR.
     */
    private function extrairDadosDoReciboComIA(string $textoOcr): array
    {
        $prompt = "
            Você é um assistente especialista em analisar recibos de loteria da Caixa Econômica Federal do Brasil.
            Sua tarefa é extrair 3 informações do texto abaixo: o tipo do jogo, o número do concurso e as sequências de números apostados.
            
            Regras importantes:
            1. Identifique o tipo de jogo. Os valores possíveis para 'tipoJogo' devem ser: 'megasena', 'lotofacil', 'quina', 'lotomania', 'timemania', 'duplasena', 'federal', 'loteca', 'diadesorte', 'supersete'.
            2. Identifique o número do concurso (geralmente identificado como 'CONCURSO', 'CONC' ou 'C:').
            3. Extraia todas as sequências de números que são claramente apostas. Os números devem ser retornados como inteiros.
            4. O texto pode ter erros de OCR. Tente inferir os dados corretos mesmo com imperfeições.
            
            Retorne o resultado APENAS como um objeto JSON válido, com a seguinte estrutura: 
            {\"tipoJogo\": \"nome_do_jogo\", \"concurso\": numero_do_concurso, \"apostas\": [[numeros_aposta_1], [numeros_aposta_2]]}
            
            Se não encontrar algum dado, retorne o campo como null. Exemplo: {\"tipoJogo\": \"megasena\", \"concurso\": null, \"apostas\": []}

            Texto para análise:
            \"{$textoOcr}\"
        ";

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o',
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            $jsonResult = $response->choices[0]->message->content;
            $data = json_decode($jsonResult, true);

            return [
                'tipoJogo' => $data['tipoJogo'] ?? null,
                'concurso' => $data['concurso'] ?? null,
                'apostas' => $data['apostas'] ?? []
            ];

        } catch (Exception $e) {
            throw new Exception('Falha ao chamar a API da OpenAI: ' . $e->getMessage());
        }
    }

    /**
     * Consulta o endpoint da Caixa para obter o resultado oficial de um concurso.
     */
    private function consultarResultadoCaixa(string $tipoJogo, int $concurso): array
    {
        $url = "https://servicebus2.caixa.gov.br/portaldeloterias/api/{$tipoJogo}/{$concurso}";

        $response = Http::withOptions(['verify' => false])->get($url);

        if ($response->failed()) {
             if ($response->status() == 404) {
                 throw new Exception("O concurso {$concurso} para o jogo '{$tipoJogo}' não foi encontrado.");
             }
             throw new Exception("Falha ao se comunicar com a API da Caixa. Status: " . $response->status());
        }

        return $response->json();
    }
    
    /**
     * Mapeia a descrição da faixa de prêmio para um número de acertos.
     */
    private function getAcertosFromDescricao(string $descricao): ?int
    {
        $descricaoLower = strtolower($descricao);

        if (strpos($descricaoLower, 'sena') !== false) return 6;
        if (strpos($descricaoLower, 'quina') !== false) return 5;
        if (strpos($descricaoLower, 'quadra') !== false) return 4;
        if (strpos($descricaoLower, 'terno') !== false) return 3;

        preg_match('/^\d+/', $descricao, $matches);
        if (!empty($matches)) {
            return (int)$matches[0];
        }

        return null;
    }

    /**
     * Compara as apostas do usuário com o resultado oficial e calcula o prêmio detalhadamente.
     */
    private function verificarPremiacao(array $apostasUsuario, array $resultadoOficial): array
    {
        $numerosSorteados = $resultadoOficial['listaDezenas'];
        $rateioPremio = $resultadoOficial['listaRateioPremio'];
        
        $valorTotalPremio = 0.0;
        $resultadosDetalhados = [];

        $mapaDePremios = [];
        foreach ($rateioPremio as $faixa) {
            $acertosNecessarios = $this->getAcertosFromDescricao($faixa['descricaoFaixa']);
            if ($acertosNecessarios !== null) {
                $mapaDePremios[$acertosNecessarios] = [
                    'valor' => $faixa['valorPremio'],
                    'descricao' => $faixa['descricaoFaixa']
                ];
            }
        }
        
        foreach ($apostasUsuario as $aposta) {
            // Transforma cada número da aposta (ex: 6) em uma string com 2 dígitos e zero à esquerda (ex: "06")
            // para garantir uma comparação exata com os dados da API da Caixa.
            $apostaFormatada = array_map(function($num) {
                return sprintf('%02d', $num);
            }, $aposta);
    
            $numerosAcertados = array_intersect($apostaFormatada, $numerosSorteados);
            
            $quantidadeAcertos = count($numerosAcertados);

            $detalheAposta = [
                'aposta' => $aposta,
                'acertos' => $quantidadeAcertos,
                'numerosAcertados' => array_values($numerosAcertados),
                'isPremiada' => false,
                'valorPremio' => 0.0,
                'descricaoPremio' => 'Sem premiação'
            ];

            if ($quantidadeAcertos > 0 && isset($mapaDePremios[$quantidadeAcertos])) {
                $premioInfo = $mapaDePremios[$quantidadeAcertos];
                $detalheAposta['isPremiada'] = true;
                $detalheAposta['valorPremio'] = $premioInfo['valor'];
                $detalheAposta['descricaoPremio'] = $premioInfo['descricao'];
                
                $valorTotalPremio += $premioInfo['valor'];
            }
            
            $resultadosDetalhados[] = $detalheAposta;
        }
        
        return [
            'foiPremiado' => $valorTotalPremio > 0,
            'valorTotalPremio' => $valorTotalPremio,
            'resultadosDetalhados' => $resultadosDetalhados
        ];
    }
}