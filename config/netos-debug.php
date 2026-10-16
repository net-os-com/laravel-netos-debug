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

    /*
    | Every call this application makes with the HTTP client, shipped to the
    | HTTP client view as it finishes — including the ones made from queued
    | jobs, the scheduler and artisan commands, where there is no request to
    | hang them off.
    |
    | Only calls that go through Laravel's own client are seen: those are the
    | ones the framework fires events for. A package that builds its own Guzzle
    | client stays invisible, because Guzzle has no way to be watched globally.
    |
    | Credentials are never shipped. Authorization, cookie and proxy headers are
    | always replaced with a note of their length, and `redact` adds to that
    | list — each entry is matched against the header name with `fnmatch`, so
    | `x-*-token` covers a family of them.
    */
    'http_client' => [
        'enabled' => env('NETOS_DEBUG_HTTP_CLIENT', true),

        /*
        | URLs that are never shipped, matched with `Str::is()`. Health checks
        | and anything that would report on this tool itself belong here.
        */
        'except' => [],

        'redact' => [
            'x-api-key',
            'api-key',
            'x-auth-token',
            'x-*-signature',
        ],

        /*
        | Bodies longer than this are cut, with a note saying so. A page of JSON
        | from an upstream is worth seeing; a 40MB export is not.
        */
        'max_body_bytes' => (int) env('NETOS_DEBUG_HTTP_CLIENT_BODY_BYTES', 16 * 1_024),
    ],

    /*
    | Saved API requests, written as OpenAPI documents inside this repository so
    | they can be reviewed and merged like anything else the team owns. One file
    | per collection, relative to the application root unless the path is
    | absolute.
    |
    | Nothing secret is written: values that resolve at send time are stored as
    | the reference — `{{ token }}` — rather than as what it stands for.
    */
    'collections' => [
        'path' => env('NETOS_DEBUG_COLLECTIONS_PATH', 'openapi'),
    ],

    /*
    | Where the schema diagram is kept. Both schema.dbml and schema.layout.json
    | land here, beside the migrations they describe.
    */
    'erd' => [
        'path' => env('NETOS_DEBUG_ERD_PATH', 'database'),
    ],
];
