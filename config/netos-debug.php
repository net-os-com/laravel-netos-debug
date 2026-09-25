<?php

declare(strict_types=1);

return [
    /*
    | Leave this null to let `environments` decide. Setting it explicitly wins
    | either way, so a `false` here switches the collector off everywhere.
    */
    'enabled' => env('NETOS_DEBUG_ENABLED'),

    /*
    | Environments in which requests are shipped when `enabled` is null.
    */
    'environments' => ['local'],

    /*
    | Request paths that are never shipped, matched with `Request::is()`. These
    | are tools that would otherwise report on themselves.
    */
    'except' => [
        '_debugbar*',
        'telescope*',
        'horizon*',
        '_ignition*',
    ],

    /*
    | Payloads larger than this are trimmed before they are sent: query
    | backtraces go first, then the query list itself is halved until it fits.
    */
    'max_payload_bytes' => 512 * 1_024,

    /*
    | Runs EXPLAIN on every select a request made and ships the plan along with
    | it, so the Requests view can show the access type and the row estimate.
    |
    | Off by default: it roughly doubles the number of queries per request, and
    | it runs them after the response has been sent, where nothing is waiting on
    | the result but the database still does the work.
    */
    'explain_queries' => env('NETOS_DEBUG_EXPLAIN_QUERIES', false),

    /*
    | A cap on how many selects get explained, so one N+1-heavy request cannot
    | turn fifty queries into a hundred.
    */
    'max_explained_queries' => (int) env('NETOS_DEBUG_MAX_EXPLAINED_QUERIES', 25),

    /*
    | Debugbar needs a handful of non-default options for its data to satisfy
    | the payload contract. Leave this on to have them applied for you; set it
    | to false if the project would rather own those settings itself.
    */
    'configure_debugbar' => true,
];
