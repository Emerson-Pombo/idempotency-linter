<?php

/*
|--------------------------------------------------------------------------
| idempotency-linter — catálogos de detecção
|--------------------------------------------------------------------------
|
| ATENÇÃO: formato pré-alfa, ainda vai mudar.
|
| "sinks"  → chamadas com efeito colateral sensível a duplicação.
| "guards" → chamadas/estruturas que contam como proteção contra reexecução.
|
| Tipos de correspondência suportados (consumidos pelo motor de análise):
|   - static_call : Classe::metodo()           (ex.: facades). Chave "methods".
|   - method_call : $this->prop->metodo() / $param->metodo() quando o tipo
|                   declarado é a classe/interface/trait (ou uma subclasse). Chave "methods".
|   - function    : funcao(). Nomes na chave "functions".
|   - array_key   : array literal com a chave string (sem diferenciar maiúsculas). Chave "keys". Só para guards.
|   - interface   : a classe do job implementa a interface (só para guards).
|
| "chains" ensinam o linter a seguir encadeamentos sem inferir tipos: chamar um
| dos "methods" em "class" (estaticamente, como numa facade, ou em uma instância
| desse tipo) devolve um objeto do tipo "returns". Ex.: Mail::to($u)->send($m).
|
| Guards com 'partial' => true reduzem o risco em um nível em vez de eliminar o achado.
|
| Risco: 'high' | 'medium' | 'low'
|
*/

// Métodos de PendingRequest (cliente HTTP do Laravel) que devolvem o próprio objeto.
$httpFluent = [
    'withHeaders', 'withHeader', 'withToken', 'withBasicAuth', 'withDigestAuth', 'withUserAgent',
    'withOptions', 'withBody', 'withQueryParameters', 'withUrlParameters', 'withCookies', 'withoutVerifying',
    'accept', 'acceptJson', 'asJson', 'asForm', 'asMultipart', 'contentType', 'attach',
    'timeout', 'connectTimeout', 'retry', 'baseUrl', 'throw',
];

return [

    // Caminhos analisados quando o comando é chamado sem argumento.
    'paths' => [
        'app/Jobs',
    ],

    // Interface que identifica um job de fila.
    'job_interface' => 'Illuminate\Contracts\Queue\ShouldQueue',

    // Método analisado em cada job.
    'entry_method' => 'handle',

    'sinks' => [

        'payment' => [
            'risk' => 'high',
            'message' => 'Chamada a gateway de pagamento sem verificação de idempotência.',
            'match' => [
                ['type' => 'method_call', 'class' => 'Stripe\Service\PaymentIntentService', 'methods' => ['create', 'confirm', 'capture']],
                ['type' => 'method_call', 'class' => 'Stripe\Service\ChargeService', 'methods' => ['create']],
                ['type' => 'method_call', 'class' => 'Stripe\Service\RefundService', 'methods' => ['create']],
                ['type' => 'static_call', 'class' => 'Stripe\PaymentIntent', 'methods' => ['create']],
                ['type' => 'static_call', 'class' => 'Stripe\Charge', 'methods' => ['create']],
                ['type' => 'method_call', 'class' => 'Laravel\Cashier\Billable', 'methods' => ['charge', 'invoiceFor', 'refund']],
            ],
        ],

        'mail' => [
            'risk' => 'medium',
            'message' => 'Envio de e-mail sem verificação de idempotência.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Mail', 'methods' => ['send', 'raw']],
                ['type' => 'method_call', 'class' => 'Illuminate\Mail\PendingMail', 'methods' => ['send']],
            ],
        ],

        'notification' => [
            'risk' => 'medium',
            'message' => 'Envio de notificação sem verificação de idempotência.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Notification', 'methods' => ['send', 'sendNow']],
                ['type' => 'method_call', 'class' => 'Illuminate\Notifications\Notifiable', 'methods' => ['notify', 'notifyNow']],
                ['type' => 'method_call', 'class' => 'Illuminate\Notifications\AnonymousNotifiable', 'methods' => ['notify', 'notifyNow']],
            ],
        ],

        'http' => [
            'risk' => 'medium',
            'message' => 'Requisição HTTP com efeito colateral (POST/PUT/PATCH/DELETE) sem verificação de idempotência.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Http', 'methods' => ['post', 'put', 'patch', 'delete']],
                ['type' => 'method_call', 'class' => 'Illuminate\Http\Client\PendingRequest', 'methods' => ['post', 'put', 'patch', 'delete']],
            ],
        ],

        'database_insert' => [
            'risk' => 'low',
            'message' => 'Inserção no banco sem verificação de idempotência.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\DB', 'methods' => ['insert']],
                ['type' => 'method_call', 'class' => 'Illuminate\Database\Query\Builder', 'methods' => ['insert', 'insertGetId']],
                ['type' => 'static_call', 'class' => 'Illuminate\Database\Eloquent\Model', 'methods' => ['create', 'insert', 'forceCreate']],
                ['type' => 'method_call', 'class' => 'Illuminate\Database\Eloquent\Model', 'methods' => ['save']],
            ],
        ],

    ],

    'guards' => [

        'cache_lock' => [
            'description' => 'Lock/flag atômico em cache (Cache::lock, Cache::add).',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Cache', 'methods' => ['lock', 'add']],
            ],
        ],

        'upsert' => [
            'description' => 'Escrita idempotente por natureza (firstOrCreate, updateOrCreate, upsert, insertOrIgnore).',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Database\Eloquent\Model', 'methods' => ['firstOrCreate', 'updateOrCreate', 'upsert']],
                ['type' => 'method_call', 'class' => 'Illuminate\Database\Query\Builder', 'methods' => ['upsert', 'insertOrIgnore', 'updateOrInsert']],
            ],
        ],

        'idempotency_key' => [
            'description' => 'Chave de idempotência enviada ao provedor (ex.: opção idempotency_key do Stripe).',
            'match' => [
                ['type' => 'array_key', 'keys' => ['idempotency_key', 'Idempotency-Key']],
            ],
        ],

        // ShouldBeUnique só evita jobs duplicados na fila; NÃO protege contra
        // reexecução após falha/timeout. Mantido aqui como sinal fraco.
        'unique_job' => [
            'description' => 'Job implementa ShouldBeUnique (proteção parcial).',
            'partial' => true,
            'match' => [
                ['type' => 'interface', 'class' => 'Illuminate\Contracts\Queue\ShouldBeUnique'],
            ],
        ],

    ],

    'chains' => [

        // E-mail: Mail::to($u)->cc($c)->send($m)
        ['class' => 'Illuminate\Support\Facades\Mail', 'methods' => ['to', 'cc', 'bcc'], 'returns' => 'Illuminate\Mail\PendingMail'],
        ['class' => 'Illuminate\Mail\PendingMail', 'methods' => ['to', 'cc', 'bcc', 'locale'], 'returns' => 'Illuminate\Mail\PendingMail'],

        // HTTP: Http::withToken($t)->acceptJson()->post($url)
        ['class' => 'Illuminate\Support\Facades\Http', 'methods' => $httpFluent, 'returns' => 'Illuminate\Http\Client\PendingRequest'],
        ['class' => 'Illuminate\Http\Client\PendingRequest', 'methods' => $httpFluent, 'returns' => 'Illuminate\Http\Client\PendingRequest'],

        // Notificação sob demanda: Notification::route('mail', $to)->notify($n)
        ['class' => 'Illuminate\Support\Facades\Notification', 'methods' => ['route'], 'returns' => 'Illuminate\Notifications\AnonymousNotifiable'],
        ['class' => 'Illuminate\Notifications\AnonymousNotifiable', 'methods' => ['route'], 'returns' => 'Illuminate\Notifications\AnonymousNotifiable'],

    ],

];
