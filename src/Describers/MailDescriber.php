<?php

declare(strict_types=1);

namespace NetOs\Debug\Describers;

/**
 * The mail a request sent.
 *
 * Debugbar hands over Symfony's own collector output: recipients, subject, the
 * raw header block, and — when the body is being shown — the text and HTML
 * parts. The header block is a single string, so the addresses worth seeing are
 * parsed back out of it here rather than in the view.
 */
class MailDescriber
{
    /** A request that sends more mail than this has a problem the list cannot fix. */
    private const int MAX_MAILS = 20;

    /**
     * Bodies are the one part of a request payload that can run to megabytes,
     * and a debug view only ever shows the top of one.
     */
    private const int MAX_BODY_BYTES = 8 * 1_024;

    /** Headers worth lifting out; the rest stay in the raw block. */
    private const array ADDRESS_HEADERS = ['from', 'cc', 'bcc', 'reply-to'];

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function describe(array $data): array
    {
        $section = $data['symfonymailer_mails'] ?? null;

        if (! is_array($section) || ! is_array($section['mails'] ?? null)) {
            return [];
        }

        $mails = [];

        foreach (array_slice($section['mails'], 0, self::MAX_MAILS) as $mail) {
            if (! is_array($mail)) {
                continue;
            }

            $headers = $this->headers((string) ($mail['headers'] ?? ''));

            $mails[] = [
                'subject' => (string) ($mail['subject'] ?? ''),
                'to' => array_values(array_map('strval', (array) ($mail['to'] ?? []))),
                'from' => $headers['from'] ?? [],
                'cc' => $headers['cc'] ?? [],
                'bcc' => $headers['bcc'] ?? [],
                'replyTo' => $headers['reply-to'] ?? [],
                'text' => $this->body($mail['body'] ?? null),
                'html' => $this->body($mail['html'] ?? null),
            ];
        }

        return $mails;
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(string $raw): array
    {
        $found = [];

        foreach (explode("\n", $raw) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));

            if (! in_array($name, self::ADDRESS_HEADERS, true)) {
                continue;
            }

            $addresses = array_filter(array_map('trim', explode(',', substr($line, $colon + 1))));

            $found[$name] = array_values($addresses);
        }

        return $found;
    }

    /**
     * @return array{value: string, truncated: bool}|null
     */
    private function body(mixed $body): ?array
    {
        if (! is_string($body) || $body === '') {
            return null;
        }

        $truncated = strlen($body) > self::MAX_BODY_BYTES;

        return [
            'value' => $truncated ? substr($body, 0, self::MAX_BODY_BYTES) : $body,
            'truncated' => $truncated,
        ];
    }
}
