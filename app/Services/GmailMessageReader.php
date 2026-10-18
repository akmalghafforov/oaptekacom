<?php

namespace App\Services;

use Closure;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * @phpstan-type MessageSummary array{id: ?string, from: string, subject: string, date: string, snippet: string, unread: bool}
 * @phpstan-type BodyParts array{plain: string, html: string, attachments: list<array{name: string, size: int}>}
 */
class GmailMessageReader
{
    public function metadataFields(): string
    {
        $fields = 'partId,filename,mimeType,body(size,attachmentId),parts(partId)';
        for ($level = 0; $level < 4; $level++) {
            $fields = 'partId,filename,mimeType,body(size,attachmentId),parts('.$fields.')';
        }

        return 'id,labelIds,snippet,payload(headers,'.$fields.')';
    }

    public function attachmentMetadata(Gmail $gmail, string $messageId, int $depth = 4): Message
    {
        do {
            $fields = 'partId,filename,mimeType,body(size,attachmentId),parts(partId)';
            for ($level = 0; $level < $depth; $level++) {
                $fields = 'partId,filename,mimeType,body(size,attachmentId),parts('.$fields.')';
            }
            $message = $gmail->users_messages->get('me', $messageId, ['format' => 'full', 'fields' => 'id,internalDate,labelIds,snippet,payload(headers,'.$fields.')']);
            $hasMore = $message->getPayload() && $this->hasDeeperParts($message->getPayload(), $depth);
            $depth += 4;
        } while ($hasMore);

        return $message;
    }

    public function hasDeeperParts(MessagePart $part, int $depth): bool
    {
        if ($depth === 0) {
            return count($part->getParts() ?? []) > 0;
        }
        foreach ($part->getParts() ?? [] as $child) {
            if ($this->hasDeeperParts($child, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{part_id: string, name: string, size: int}> */
    public function attachments(MessagePart $part): array
    {
        $attachments = [];
        if ($part->getFilename()) {
            $attachments[] = ['part_id' => (string) $part->getPartId(), 'name' => $this->header($part->getFilename()), 'size' => max(0, (int) $part->getBody()?->getSize())];
        }
        foreach ($part->getParts() ?? [] as $child) {
            $attachments = array_merge($attachments, $this->attachments($child));
        }

        return $attachments;
    }

    /** @return MessageSummary */
    public function metadata(Message $message): array
    {
        $headers = [];
        foreach ($message->getPayload()?->getHeaders() ?? [] as $header) {
            $headers[strtolower($header->getName())] = $this->header($header->getValue());
        }

        return ['id' => $message->getId(), 'from' => $headers['from'] ?? 'Неизвестный отправитель', 'subject' => $headers['subject'] ?? '(Без темы)', 'date' => $headers['date'] ?? '', 'snippet' => $message->getSnippet() ?? '', 'unread' => in_array('UNREAD', $message->getLabelIds() ?? [], true)];
    }

    /**
     * @param  Closure(string): string  $attachment
     * @return MessageSummary&array{plain: string, html: string, document: string, attachments: list<array{name: string, size: int}>}
     */
    public function read(Message $message, Closure $attachment): array
    {
        $remainingBodyFetches = 8;
        $body = $message->getPayload() ? $this->part($message->getPayload(), $attachment, $remainingBodyFetches) : ['plain' => '', 'html' => '', 'attachments' => []];
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(1024 * 1024)->blockElement('a');
        foreach (['p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'h1', 'h2', 'h3', 'hr'] as $element) {
            $config = $config->allowElement($element);
        }
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'img', 'video', 'audio', 'link', 'meta', 'base'] as $element) {
            $config = $config->dropElement($element);
        }
        $html = (new HtmlSanitizer($config))->sanitize($body['html']);
        $document = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; base-uri \'none\'; form-action \'none\'"></head><body>'.$html.'</body></html>';

        return $this->metadata($message) + ['plain' => $body['plain'], 'html' => $html, 'document' => $document, 'attachments' => $message->getPayload() ? $this->attachments($message->getPayload()) : []];
    }

    /** @return BodyParts */
    private function part(MessagePart $part, Closure $attachment, int &$remainingBodyFetches, int $depth = 0): array
    {
        $result = ['plain' => '', 'html' => '', 'attachments' => []];
        if ($depth > 30) {
            return $result;
        }
        $filename = $part->getFilename() ?? '';
        if ($filename !== '') {
            $result['attachments'][] = ['name' => $this->header($filename), 'size' => max(0, (int) $part->getBody()?->getSize())];

            return $result;
        }
        $type = strtolower($part->getMimeType() ?? '');
        if (in_array($type, ['text/plain', 'text/html'], true)) {
            $data = $part->getBody()?->getData();
            if (($data === null || $data === '') && $part->getBody()?->getAttachmentId() && $remainingBodyFetches > 0) {
                $remainingBodyFetches--;
                $data = $attachment($part->getBody()->getAttachmentId());
            }
            $decoded = $this->decode($data ?? '');
            $charset = 'UTF-8';
            foreach ($part->getHeaders() ?? [] as $header) {
                if (strtolower($header->getName()) === 'content-type' && preg_match('/charset\s*=\s*["\x27]?([^\s;"\x27]+)/i', $header->getValue(), $matches)) {
                    $charset = $matches[1];
                }
            }
            try {
                $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
            } catch (\ValueError) {
                $decoded = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
            }
            $result[$type === 'text/plain' ? 'plain' : 'html'] = $decoded;

            return $result;
        }
        foreach ($part->getParts() ?? [] as $child) {
            $content = $this->part($child, $attachment, $remainingBodyFetches, $depth + 1);
            foreach (['plain', 'html'] as $format) {
                if ($type === 'multipart/alternative') {
                    if ($content[$format] !== '') {
                        $result[$format] = $content[$format];
                    }
                } elseif ($content[$format] !== '') {
                    $result[$format] .= ($result[$format] !== '' ? "\n" : '').$content[$format];
                }
            }
            $result['attachments'] = array_merge($result['attachments'], $content['attachments']);
        }

        return $result;
    }

    private function decode(string $data): string
    {
        if ($data === '' || strlen($data) > 8 * 1024 * 1024 || ! preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $data) || strlen(rtrim($data, '=')) % 4 === 1 || (str_contains($data, '=') && strlen($data) % 4 !== 0)) {
            return '';
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== rtrim($data, '=')) {
            return '';
        }

        return $decoded;
    }

    private function header(string $value): string
    {
        $encodedWord = '=\?[^?\s]+\?(?:b\?(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?|q\?(?:[!\x23-\x3C\x3E\x40-\x7E]|=[A-Fa-f0-9]{2})*)\?=';

        return preg_replace_callback('~'.$encodedWord.'(?:[ \t\r\n]*'.$encodedWord.')*~i', static function (array $matches): string {
            $decoded = @iconv_mime_decode($matches[0], ICONV_MIME_DECODE_STRICT, 'UTF-8');

            return $decoded === false ? $matches[0] : $decoded;
        }, $value) ?? $value;
    }
}
