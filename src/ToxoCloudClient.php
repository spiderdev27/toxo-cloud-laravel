<?php

namespace Toxo\Cloud\Laravel;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;

class ToxoCloudClient
{
    private const INTERNAL_API_URL = 'https://toxo-api-cwsddklqcq-uc.a.run.app';

    protected HttpClient $http;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct(?string $apiKey = null, int $timeout = 120)
    {
        $this->apiKey = $apiKey
            ?? env('GEMINI_API_KEY')
            ?? env('GOOGLE_API_KEY')
            ?? env('TOXO_CLOUD_API_KEY');

        $this->timeout = $timeout;

        $this->http = new HttpClient([
            'base_uri' => rtrim(self::INTERNAL_API_URL, '/'),
            'timeout'  => $this->timeout,
            // Cloud Run + some container libcurl builds can hang on HTTP/2 for GET requests.
            // Force HTTP/1.1 for consistent behavior across environments.
            'version'  => 1.1,
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ],
        ]);
    }

    /**
     * Service root (`GET /`).
     */
    public function root(): array
    {
        return $this->get('/', 20);
    }

    /**
     * Health check (`GET /health`).
     */
    public function health(): array
    {
        return $this->get('/health', 20);
    }

    /**
     * Text query (`POST /v1/query`).
     */
    public function query(string $layerPath, string $question, array $options = []): string
    {
        $payload = [
            'layer_base64'   => $this->layerBase64($layerPath),
            'question'       => $question,
            'api_key'        => $options['api_key'] ?? $this->apiKey,
            'model'          => $options['model'] ?? 'gemini-2.5-flash-lite',
            'provider'       => $options['provider'] ?? 'gemini',
            'context'        => $options['context'] ?? null,
            'response_depth' => $options['response_depth'] ?? 'balanced',
        ];

        $data = $this->post('/v1/query', $payload);

        return (string) Arr::get($data, 'response', '');
    }

    /**
     * Multimodal query (`POST /v1/query_multimodal`).
     *
     * Exactly one of: image_path, document_path, image_base64, document_base64.
     */
    public function queryMultimodal(string $layerPath, string $question, array $options = []): string
    {
        $imagePath      = $options['image_path'] ?? null;
        $documentPath   = $options['document_path'] ?? null;
        $imageBase64    = $options['image_base64'] ?? null;
        $documentBase64 = $options['document_base64'] ?? null;
        $mimeType       = $options['mime_type'] ?? null;

        $sources = array_filter([
            $imagePath,
            $documentPath,
            $imageBase64,
            $documentBase64,
        ]);

        if (count($sources) !== 1) {
            throw new \InvalidArgumentException(
                'Provide exactly one of image_path, document_path, image_base64, document_base64'
            );
        }

        $effectiveMime = $mimeType;
        $imgB64 = $imageBase64;
        $docB64 = $documentBase64;

        if ($imagePath) {
            if (! is_file($imagePath)) {
                throw new \RuntimeException("Image not found: {$imagePath}");
            }
            $bytes = file_get_contents($imagePath);
            if ($bytes === false) {
                throw new \RuntimeException("Failed to read image: {$imagePath}");
            }
            $imgB64 = base64_encode($bytes);
            if (! $effectiveMime) {
                $effectiveMime = $this->guessMimeType($imagePath) ?: 'image/png';
            }
            if ($imgB64 && ! str_starts_with((string) $imgB64, 'data:')) {
                $mt = $effectiveMime ?: 'image/png';
                $imgB64 = "data:{$mt};base64,{$imgB64}";
            }
        }

        if ($documentPath) {
            if (! is_file($documentPath)) {
                throw new \RuntimeException("Document not found: {$documentPath}");
            }
            $bytes = file_get_contents($documentPath);
            if ($bytes === false) {
                throw new \RuntimeException("Failed to read document: {$documentPath}");
            }
            $docB64 = base64_encode($bytes);
            if (! $effectiveMime) {
                $effectiveMime = $this->guessMimeType($documentPath) ?: 'application/octet-stream';
            }
            if ($docB64 && ! str_starts_with((string) $docB64, 'data:')) {
                $mt = $effectiveMime ?: 'application/pdf';
                $docB64 = "data:{$mt};base64,{$docB64}";
            }
        }

        if ($documentBase64 && ! str_starts_with((string) $documentBase64, 'data:')) {
            $mt = $effectiveMime ?: 'application/pdf';
            $docB64 = "data:{$mt};base64,{$documentBase64}";
        }

        if ($imageBase64 && ! str_starts_with((string) $imageBase64, 'data:')) {
            $mt = $effectiveMime ?: 'image/png';
            $imgB64 = "data:{$mt};base64,{$imageBase64}";
        }

        $payload = [
            'layer_base64'     => $this->layerBase64($layerPath),
            'question'         => $question,
            'image_base64'     => $imgB64,
            'document_base64'  => $docB64,
            'mime_type'        => $effectiveMime,
            'api_key'          => $options['api_key'] ?? $this->apiKey,
            'model'            => $options['model'] ?? 'gemini-2.5-flash-lite',
            'provider'         => $options['provider'] ?? 'gemini',
            'context'          => $options['context'] ?? null,
            'response_depth'   => $options['response_depth'] ?? 'balanced',
        ];

        $data = $this->post('/v1/query_multimodal', $payload, max($this->timeout, 180));

        return (string) Arr::get($data, 'response', '');
    }

    /**
     * Feedback (`POST /v1/feedback`).
     */
    public function feedback(string $layerPath, string $question, string $response, array $options = []): array
    {
        $payload = [
            'layer_base64' => $this->layerBase64($layerPath),
            'question'     => $question,
            'response'     => $response,
            'rating'       => $options['rating'] ?? null,
            'suggestions'  => $options['suggestions'] ?? null,
        ];

        return $this->post('/v1/feedback', $payload, 40);
    }

    /**
     * Train from data (`POST /v1/train_from_data`).
     */
    public function trainFromData(array $params): array
    {
        $examples = [];
        foreach ($params['examples'] ?? [] as $ex) {
            $examples[] = [
                'input'  => $ex['input'] ?? $ex['question'] ?? '',
                'output' => $ex['output'] ?? $ex['answer'] ?? '',
            ];
        }

        $contexts = [];
        foreach ($params['contexts'] ?? [] as $ctx) {
            $contexts[] = ['text' => $ctx];
        }

        $payload = [
            'description'  => $params['description'],
            'epochs'       => $params['epochs'] ?? 10,
            'llm_provider' => $params['llm_provider'] ?? 'gemini',
            'llm_model'    => $params['llm_model'] ?? null,
            'api_key'      => $params['api_key'] ?? $this->apiKey,
            'domain'       => $params['domain'] ?? null,
            'examples'     => $examples,
            'contexts'     => $contexts,
            'mode'         => $params['mode'] ?? null,
            'num_examples' => $params['num_examples'] ?? 5,
        ];

        return $this->post('/v1/train_from_data', $payload, max($this->timeout, 600));
    }

    /**
     * Resume training from an existing `.toxo` layer (`POST /v1/train_resume_from_data`).
     */
    public function trainResumeFromData(array $params): array
    {
        $examples = [];
        foreach ($params['examples'] ?? [] as $ex) {
            $examples[] = [
                'input'  => $ex['input'] ?? $ex['question'] ?? '',
                'output' => $ex['output'] ?? $ex['answer'] ?? '',
            ];
        }

        $contexts = [];
        foreach ($params['contexts'] ?? [] as $ctx) {
            $contexts[] = ['text' => $ctx];
        }

        $documents = [];
        foreach ($params['documents'] ?? [] as $docPath) {
            $documents[] = $this->docPayload($docPath);
        }

        $payload = [
            'base_layer_base64' => $this->layerBase64($params['base_layer']),
            'description'       => $params['description'],
            'epochs'            => $params['epochs'] ?? 10,
            'llm_provider'      => $params['llm_provider'] ?? 'gemini',
            'llm_model'         => $params['llm_model'] ?? null,
            'api_key'           => $params['api_key'] ?? $this->apiKey,
            'domain'            => $params['domain'] ?? null,
            'examples'          => $examples,
            'contexts'          => $contexts,
            'documents'         => $documents,
            'mode'              => $params['mode'] ?? null,
            'num_examples'      => $params['num_examples'] ?? 5,
        ];

        return $this->post('/v1/train_resume_from_data', $payload, max($this->timeout, 600));
    }

    /**
     * Extract contexts (`POST /v1/train/extract_contexts`).
     */
    public function trainExtractContexts(array $params): array
    {
        $docs = [];
        foreach ($params['docs'] ?? [] as $docPath) {
            $docs[] = $this->docPayload($docPath);
        }

        $payload = [
            'index_id' => $params['index_id'] ?? 'train_extract_contexts',
            'domain'   => $params['domain'] ?? 'general',
            'api_key'  => $params['api_key'] ?? $this->apiKey,
            'docs'     => $docs,
        ];

        return $this->post('/v1/train/extract_contexts', $payload, max($this->timeout, 600));
    }

    /**
     * RAG index (`POST /v1/rag/index`).
     */
    public function ragIndex(array $params): array
    {
        $docs = [];
        foreach ($params['docs'] ?? [] as $docPath) {
            $docs[] = $this->docPayload($docPath);
        }

        $payload = [
            'index_id'     => $params['index_id'],
            'domain'       => $params['domain'] ?? 'general',
            'layer_base64' => isset($params['layer']) ? $this->layerBase64($params['layer']) : null,
            'api_key'      => $params['api_key'] ?? $this->apiKey,
            'docs'         => $docs,
        ];

        return $this->post('/v1/rag/index', $payload, max($this->timeout, 600));
    }

    /**
     * RAG query (`POST /v1/rag/query`).
     */
    public function ragQuery(array $params): array
    {
        $payload = [
            'layer_base64'   => $this->layerBase64($params['layer']),
            'index_id'       => $params['index_id'],
            'question'       => $params['question'],
            'api_key'        => $params['api_key'] ?? $this->apiKey,
            'llm_provider'   => $params['llm_provider'] ?? 'gemini',
            'llm_model'      => $params['llm_model'] ?? null,
            'top_k'          => (int) ($params['top_k'] ?? 5),
            'response_depth' => $params['response_depth'] ?? 'balanced',
        ];

        return $this->post('/v1/rag/query', $payload, max($this->timeout, 300));
    }

    /**
     * Agent run (`POST /v1/agent/run`).
     */
    public function agentRun(array $params): array
    {
        $config = $params['config'];
        if (! is_array($config)) {
            $path = (string) $config;
            if (! is_file($path)) {
                throw new \RuntimeException("Config file not found: {$path}");
            }
            $text = file_get_contents($path);
            if ($text === false) {
                throw new \RuntimeException("Failed to read config file: {$path}");
            }
            $lower = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($lower, ['yml', 'yaml'], true)) {
                if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                    throw new \RuntimeException('symfony/yaml is required to read YAML agent config');
                }
                $config = \Symfony\Component\Yaml\Yaml::parse($text) ?? [];
            } else {
                $config = json_decode($text, true) ?? [];
            }
        }

        $layers = [];
        foreach ($params['layer_map'] as $agentId => $path) {
            $layers[] = [
                'agent_id'    => $agentId,
                'layer_path'  => (string) $path,
                'layer_base64'=> $this->layerBase64($path),
            ];
        }

        $payload = [
            'config'       => $config,
            'layers'       => $layers,
            'api_key'      => $params['api_key'] ?? $this->apiKey,
            'llm_provider' => $params['llm_provider'] ?? 'gemini',
            'llm_model'    => $params['llm_model'] ?? null,
        ];

        return $this->post('/v1/agent/run', $payload, max($this->timeout, 1800));
    }

    /**
     * Helper: encode a `.toxo` layer as base64.
     */
    protected function layerBase64(string $path): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Layer file not found: {$path}");
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to read layer file: {$path}");
        }
        return base64_encode($bytes);
    }

    /**
     * Helper: build document payload (path, base64, mime_type).
     */
    protected function docPayload(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Document not found: {$path}");
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to read document: {$path}");
        }
        return [
            'path'        => $path,
            'data_base64' => base64_encode($bytes),
            'mime_type'   => $this->guessMimeType($path),
        ];
    }

    protected function guessMimeType(string $path): ?string
    {
        if (function_exists('mime_content_type')) {
            return @mime_content_type($path) ?: null;
        }

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            return $finfo->file($path) ?: null;
        }

        return null;
    }

    /**
     * Low-level GET helper.
     */
    protected function get(string $path, ?int $timeout = null): array
    {
        try {
            $response = $this->http->get($path, [
                'timeout' => $timeout ?? $this->timeout,
                'version' => 1.1,
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                ],
            ]);
        } catch (RequestException $e) {
            $detail = '';
            $resp = $e->getResponse();
            if ($resp) {
                $body = (string) $resp->getBody();
                $decoded = json_decode($body, true);
                if (is_array($decoded) && isset($decoded['detail'])) {
                    $detail = (string) $decoded['detail'];
                } else {
                    $detail = mb_substr(trim($body), 0, 400);
                }
            }

            if ($detail !== '') {
                throw new \RuntimeException($e->getMessage() . ' | detail: ' . $detail, 0, $e);
            }

            throw $e;
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new \RuntimeException('Unexpected response from TOXO Cloud');
        }

        return $data;
    }

    /**
     * Low-level POST helper.
     */
    protected function post(string $path, array $payload, ?int $timeout = null): array
    {
        try {
            $response = $this->http->post($path, [
                'json'    => $payload,
                'timeout' => $timeout ?? $this->timeout,
            ]);
        } catch (RequestException $e) {
            $detail = '';
            $resp = $e->getResponse();
            if ($resp) {
                $body = (string) $resp->getBody();
                $decoded = json_decode($body, true);
                if (is_array($decoded) && isset($decoded['detail'])) {
                    $detail = (string) $decoded['detail'];
                } else {
                    $detail = mb_substr(trim($body), 0, 400);
                }
            }

            if ($detail !== '') {
                throw new \RuntimeException($e->getMessage() . ' | detail: ' . $detail, 0, $e);
            }

            throw $e;
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new \RuntimeException('Unexpected response from TOXO Cloud');
        }

        return $data;
    }
}

