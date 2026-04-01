## toxo-cloud-laravel

Laravel package for calling **TOXO Cloud** from PHP, mirroring the Python `toxo-cloud` client for `.toxo` layers.

### What this package is for

- **Bring your own `.toxo` layer** (small file that contains your domain rules/soft-prompt/memory)
- Call TOXO Cloud endpoints from Laravel using a thin PHP client (Guzzle)
- Run query, multimodal query, feedback, training, resume training, extraction, RAG, and agent workflows

### Capability matrix (endpoint parity)

| Capability | Endpoint | PHP method |
|------------|----------|------------|
| Service root | `GET /` | `$client->root()` |
| Health check | `GET /health` | `$client->health()` |
| Text query | `POST /v1/query` | `$client->query()` |
| Multimodal (image/document) | `POST /v1/query_multimodal` | `$client->queryMultimodal()` |
| Feedback | `POST /v1/feedback` | `$client->feedback()` |
| Train from data | `POST /v1/train_from_data` | `$client->trainFromData()` |
| Resume training | `POST /v1/train_resume_from_data` | `$client->trainResumeFromData()` |
| Extract contexts | `POST /v1/train/extract_contexts` | `$client->trainExtractContexts()` |
| RAG index | `POST /v1/rag/index` | `$client->ragIndex()` |
| RAG query | `POST /v1/rag/query` | `$client->ragQuery()` |
| Agent workflow | `POST /v1/agent/run` | `$client->agentRun()` |

### Install

```bash
composer require toxo/toxo-cloud-laravel
```

Laravel auto-discovers the service provider.

Publish config:

```bash
php artisan vendor:publish --provider="Toxo\Cloud\Laravel\ToxoCloudServiceProvider" --tag=config
```

### Authentication (API key resolution)

The client resolves keys in this order:

1. Explicit method option `['api_key' => '...']` / param `api_key`
2. `config('toxo-cloud.api_key')` (`TOXO_CLOUD_API_KEY`)
3. `GEMINI_API_KEY`
4. `GOOGLE_API_KEY`

If no key is available, endpoints that require provider access will fail.

Example `.env`:

```bash
TOXO_CLOUD_API_KEY="YOUR_GEMINI_OR_PROVIDER_KEY"
TOXO_CLOUD_TIMEOUT=120
```

### Basic usage (controller / DI)

```php
use Toxo\Cloud\Laravel\ToxoCloudClient;

class AskController
{
    public function __invoke(ToxoCloudClient $toxo)
    {
        $answer = $toxo->query(
            base_path('layers/finance_expert.toxo'),
            'Explain inflation in simple words.',
            [
                'response_depth' => 'balanced', // concise | balanced | detailed
                'model' => 'gemini-2.5-flash-lite',
                'provider' => 'gemini',
            ]
        );

        return response()->json(['answer' => $answer]);
    }
}
```

### Error handling

- Non-2xx responses throw a `RuntimeException` with best-effort `detail` from the response body.
- File paths (`.toxo`, docs/images, agent config) are validated locally and throw early if missing.

## Endpoint-by-endpoint examples

All examples below assume:

```php
use Toxo\Cloud\Laravel\ToxoCloudClient;

$client = app(ToxoCloudClient::class);
```

### 1) `GET /` (service root)

```php
$info = $client->root();
```

### 2) `GET /health` (health check)

```php
$health = $client->health(); // e.g. ["status" => "ok"]
```

### 3) `POST /v1/query` (text query)

```php
$answer = $client->query(
    base_path('layers/finance_expert.toxo'),
    'Explain inflation in 3 bullets.',
    [
        'response_depth' => 'balanced',
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash-lite',
    ]
);
```

### 4) `POST /v1/query_multimodal` (image or document query)

Exactly one source is required:

- `image_path`
- `document_path`
- `image_base64`
- `document_base64`

```php
// Document from path (mime inferred when possible)
$docAnswer = $client->queryMultimodal(
    base_path('layers/ops_expert.toxo'),
    'Summarize this policy and list action items.',
    [
        'document_path' => storage_path('app/policy.pdf'),
    ]
);

// Image from path
$imgAnswer = $client->queryMultimodal(
    base_path('layers/ops_expert.toxo'),
    'Extract key metrics from this dashboard screenshot.',
    [
        'image_path' => storage_path('app/dashboard.png'),
    ]
);
```

Notes:

- If you pass **raw base64**, the client wraps it into `data:<mime>;base64,...`.
- If you pass a path and mime cannot be detected, you can force it via `mime_type`.

### 5) `POST /v1/feedback` (quality feedback)

```php
$result = $client->feedback(
    base_path('layers/finance_expert.toxo'),
    'What is inflation?',
    'Inflation is the rise in prices over time.',
    [
        'rating' => 9.0,
        'suggestions' => ['Add one real-world example.'],
    ]
);
```

### 6) `POST /v1/train_from_data` (train a new layer)

This returns a response containing `layer_base64`. This package **does not auto-save**; you choose where to write the `.toxo`.

```php
$result = $client->trainFromData([
    'description' => 'Finance helper for beginner investing questions.',
    'examples' => [
        ['input' => 'What is diversification?', 'output' => 'Spreading risk across asset types.'],
        ['input' => 'Explain compounding.', 'output' => 'Interest on interest over time.'],
    ],
    'contexts' => [
        'Prefer simple explanations for beginners unless they request details.',
    ],
    'epochs' => 3,
    'llm_provider' => 'gemini',
    'llm_model' => 'gemini-2.5-flash-lite',
]);

$layerBytes = base64_decode($result['layer_base64'] ?? '');
file_put_contents(base_path('layers/finance_trained.toxo'), $layerBytes);
```

### 7) `POST /v1/train_resume_from_data` (resume training from an existing `.toxo`)

```php
$result = $client->trainResumeFromData([
    'base_layer'  => base_path('layers/support_expert_v1.toxo'),
    'description' => 'Support expert v2 with better escalation rules.',
    'examples'    => [
        ['input' => 'How to handle a failed payment escalation?', 'output' => '...'],
    ],
    'contexts'    => [
        'Prefer clear step-by-step escalation paths.',
    ],
    'epochs'      => 3,
    'llm_provider' => 'gemini',
    'llm_model' => 'gemini-2.5-flash-lite',
]);

file_put_contents(
    base_path('layers/support_expert_v2.toxo'),
    base64_decode($result['layer_base64'] ?? '')
);
```

You can also include documents (each doc is uploaded as base64):

```php
$result = $client->trainResumeFromData([
    'base_layer' => base_path('layers/support_expert_v1.toxo'),
    'description' => 'Support expert v2 (add policy docs).',
    'documents' => [
        storage_path('app/policy.pdf'),
        storage_path('app/faq.md'),
    ],
    'epochs' => 2,
]);
```

### 8) `POST /v1/train/extract_contexts` (extract contexts from docs)

```php
$result = $client->trainExtractContexts([
    'docs' => [
        storage_path('app/policy.pdf'),
        storage_path('app/faq.md'),
    ],
    'index_id' => 'billing_contexts',
    'domain' => 'billing',
]);
```

### 9) `POST /v1/rag/index` (index docs in cloud vector store)

```php
$result = $client->ragIndex([
    'index_id' => 'product_docs_v1',
    'domain' => 'product',
    'docs' => [
        storage_path('app/docs/guide.md'),
        storage_path('app/docs/faq.pdf'),
    ],
]);
```

Optionally include a layer for domain-aware chunking / preprocessing:

```php
$result = $client->ragIndex([
    'index_id' => 'product_docs_v1',
    'domain' => 'product',
    'layer' => base_path('layers/product_expert.toxo'),
    'docs' => [storage_path('app/docs/faq.pdf')],
]);
```

### 10) `POST /v1/rag/query` (query a cloud index)

```php
$result = $client->ragQuery([
    'layer' => base_path('layers/product_expert.toxo'),
    'index_id' => 'product_docs_v1',
    'question' => 'What are the cancellation rules?',
    'top_k' => 5,
    'response_depth' => 'balanced',
]);

$answer = $result['response'] ?? '';
```

### 11) `POST /v1/agent/run` (agent orchestration)

`agentRun()` accepts:

- `config`: array OR path to JSON/YAML file
- `layer_map`: map of `agent_id => layer_path`

```php
$result = $client->agentRun([
    'config' => base_path('agent_config.json'),
    'layer_map' => [
        'research' => base_path('layers/research_expert.toxo'),
        'writer' => base_path('layers/writer_expert.toxo'),
    ],
    'llm_provider' => 'gemini',
    'llm_model' => 'gemini-2.5-flash-lite',
]);

$final = $result['final'] ?? null;
```

YAML config support requires:

```bash
composer require symfony/yaml
```

## Notes / gotchas

- **Timeouts**: training and indexing can take a while; this client uses higher per-endpoint timeouts (same idea as the Python client).
- **File sizes**: docs/images are uploaded as base64; keep an eye on payload size.
- **Where the `.toxo` is saved**: for training endpoints you must write `layer_base64` to a file yourself (examples above).

