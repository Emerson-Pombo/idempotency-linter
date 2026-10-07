<?php

/*
|--------------------------------------------------------------------------
| idempotency-linter — detection catalogs
|--------------------------------------------------------------------------
|
| NOTE: pre-1.0 format, it may still change between 0.x versions.
|
| "sinks"  → calls with side effects that are sensitive to duplication.
| "guards" → calls/structures that count as protection against re-execution.
|
| Supported match types (consumed by the analysis engine):
|   - static_call : Class::method()            (e.g. facades). Key "methods".
|   - method_call : $this->prop->method() / $param->method() when the declared
|                   type is the class/interface/trait (or a subclass). Key "methods".
|   - function    : function(). Names go in the "functions" key.
|   - array_key   : array literal with the string key (case-insensitive). Key "keys". Guards only.
|   - interface   : the job class implements the interface (guards only).
|
| "chains" teach the linter to follow chained calls without inferring types:
| calling one of the "methods" on "class" (statically, as on a facade, or on an
| instance of that type) returns an object of type "returns".
| E.g. Mail::to($u)->send($m).
|
| Guards with 'partial' => true lower the risk by one level instead of removing
| the finding.
|
| Risk: 'high' | 'medium' | 'low'
|
*/

// PendingRequest (Laravel's HTTP client) methods that return the object itself.
$httpFluent = [
    'withHeaders', 'withHeader', 'withToken', 'withBasicAuth', 'withDigestAuth', 'withUserAgent',
    'withOptions', 'withBody', 'withQueryParameters', 'withUrlParameters', 'withCookies', 'withoutVerifying',
    'accept', 'acceptJson', 'asJson', 'asForm', 'asMultipart', 'contentType', 'attach',
    'timeout', 'connectTimeout', 'retry', 'baseUrl', 'throw',
];

return [

    // Paths analyzed when the command is called without arguments.
    'paths' => [
        'app/Jobs',
    ],

    // Interface that identifies a queue job.
    'job_interface' => 'Illuminate\Contracts\Queue\ShouldQueue',

    // Method analyzed in each job.
    'entry_method' => 'handle',

    'sinks' => [

        'payment' => [
            'risk' => 'high',
            'message' => 'Call to a payment gateway without an idempotency check.',
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
            'message' => 'Email sent without an idempotency check.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Mail', 'methods' => ['send', 'raw']],
                ['type' => 'method_call', 'class' => 'Illuminate\Mail\PendingMail', 'methods' => ['send']],
            ],
        ],

        'notification' => [
            'risk' => 'medium',
            'message' => 'Notification sent without an idempotency check.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Notification', 'methods' => ['send', 'sendNow']],
                ['type' => 'method_call', 'class' => 'Illuminate\Notifications\Notifiable', 'methods' => ['notify', 'notifyNow']],
                ['type' => 'method_call', 'class' => 'Illuminate\Notifications\AnonymousNotifiable', 'methods' => ['notify', 'notifyNow']],
            ],
        ],

        'http' => [
            'risk' => 'medium',
            'message' => 'HTTP request with side effects (POST/PUT/PATCH/DELETE) without an idempotency check.',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Http', 'methods' => ['post', 'put', 'patch', 'delete']],
                ['type' => 'method_call', 'class' => 'Illuminate\Http\Client\PendingRequest', 'methods' => ['post', 'put', 'patch', 'delete']],
            ],
        ],

        'database_insert' => [
            'risk' => 'low',
            'message' => 'Database insert without an idempotency check.',
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
            'description' => 'Atomic cache lock/flag (Cache::lock, Cache::add).',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Support\Facades\Cache', 'methods' => ['lock', 'add']],
            ],
        ],

        'upsert' => [
            'description' => 'Naturally idempotent write (firstOrCreate, updateOrCreate, upsert, insertOrIgnore).',
            'match' => [
                ['type' => 'static_call', 'class' => 'Illuminate\Database\Eloquent\Model', 'methods' => ['firstOrCreate', 'updateOrCreate', 'upsert']],
                ['type' => 'method_call', 'class' => 'Illuminate\Database\Query\Builder', 'methods' => ['upsert', 'insertOrIgnore', 'updateOrInsert']],
            ],
        ],

        'idempotency_key' => [
            'description' => 'Idempotency key sent to the provider (e.g. Stripe\'s idempotency_key option).',
            'match' => [
                ['type' => 'array_key', 'keys' => ['idempotency_key', 'Idempotency-Key']],
            ],
        ],

        // ShouldBeUnique only prevents duplicate jobs in the queue; it does NOT
        // protect against re-execution after a failure/timeout. Kept here as a weak signal.
        'unique_job' => [
            'description' => 'Job implements ShouldBeUnique (partial protection).',
            'partial' => true,
            'match' => [
                ['type' => 'interface', 'class' => 'Illuminate\Contracts\Queue\ShouldBeUnique'],
            ],
        ],

    ],

    'chains' => [

        // Email: Mail::to($u)->cc($c)->send($m)
        ['class' => 'Illuminate\Support\Facades\Mail', 'methods' => ['to', 'cc', 'bcc'], 'returns' => 'Illuminate\Mail\PendingMail'],
        ['class' => 'Illuminate\Mail\PendingMail', 'methods' => ['to', 'cc', 'bcc', 'locale'], 'returns' => 'Illuminate\Mail\PendingMail'],

        // HTTP: Http::withToken($t)->acceptJson()->post($url)
        ['class' => 'Illuminate\Support\Facades\Http', 'methods' => $httpFluent, 'returns' => 'Illuminate\Http\Client\PendingRequest'],
        ['class' => 'Illuminate\Http\Client\PendingRequest', 'methods' => $httpFluent, 'returns' => 'Illuminate\Http\Client\PendingRequest'],

        // On-demand notification: Notification::route('mail', $to)->notify($n)
        ['class' => 'Illuminate\Support\Facades\Notification', 'methods' => ['route'], 'returns' => 'Illuminate\Notifications\AnonymousNotifiable'],
        ['class' => 'Illuminate\Notifications\AnonymousNotifiable', 'methods' => ['route'], 'returns' => 'Illuminate\Notifications\AnonymousNotifiable'],

    ],

];
