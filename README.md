## toxo-cloud-laravel

Laravel package for talking to **TOXO Cloud** from PHP, mirroring the Python `toxo-cloud` client for `.toxo` layers.

### Install

Add the package via Composer (after you publish it or use a VCS path):

```bash
composer require toxo/toxo-cloud-laravel
```

Laravel will auto-discover the service provider. You can also register it manually in `config/app.php` if needed:

```php
'providers' => [
    // ...
    Toxo\Cloud\Laravel\ToxoCloudServiceProvider::class,
],
```

Publish the config:

```bash
php artisan vendor:publish --provider="Toxo\Cloud\Laravel\ToxoCloudServiceProvider" --tag=config
```

Then set your API key (same resolution order as the Python client):

```bash
export GEMINI_API_KEY="YOUR_GEMINI_API_KEY"
# or
export GOOGLE_API_KEY="YOUR_GOOGLE_API_KEY"
# or
export TOXO_CLOUD_API_KEY="YOUR_TOXO_CLOUD_API_KEY"
```

### Usage

Resolve the client from the container or inject it:

```php
use Toxo\Cloud\Laravel\ToxoCloudClient;

class AskController
{
    public function __invoke(ToxoCloudClient $toxo)
    {
        $answer = $toxo->query(
            base_path('layers/finance_expert.toxo'),
            'Explain inflation in simple words.'
        );

        return response()->json(['answer' => $answer]);
    }
}
```

Multimodal query:

```php
$summary = $toxo->queryMultimodal(
    base_path('layers/ops_expert.toxo'),
    'Summarize this policy and list action items.',
    [
        'document_path' => storage_path('app/policy.pdf'),
    ]
);
```

Train from data:

```php
$result = $toxo->trainFromData([
    'description' => 'Support expert for refunds and cancellations.',
    'examples' => [
        ['input' => 'How do I get a refund?', 'output' => '...'],
    ],
    'contexts' => [
        'Always be friendly and concise.',
    ],
    'epochs' => 5,
]);
```

Resume training from an existing layer:

```php
$result = $toxo->trainResumeFromData([
    'base_layer'  => base_path('layers/support_expert_v1.toxo'),
    'description' => 'Support expert v2 with better escalation rules.',
    'examples'    => [
        ['input' => 'How to handle a failed payment escalation?', 'output' => '...'],
    ],
    'contexts'    => [
        'Prefer clear step-by-step escalation paths.',
    ],
    'epochs'      => 3,
]);
```

