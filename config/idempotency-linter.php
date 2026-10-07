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
| Tipos de correspondência suportados (o motor de análise vai consumir isto):
|   - static_call : Classe::metodo()           (ex.: facades)
|   - method_call : $obj->metodo() em instância de uma classe/interface
|   - function    : funcao()
|   - interface   : a classe do job implementa a interface (só para guards)
|
| Risco: 'high' | 'medium' | 'low'
|
*/

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

];
