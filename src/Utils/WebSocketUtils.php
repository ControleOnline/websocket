<?php

namespace ControleOnline\Utils;

trait WebSocketUtils
{
    private function parseHeaders(string $buffer): array
    {
        $headers = [];
        $headerLines = explode("\r\n", substr($buffer, 0, strpos($buffer, "\r\n\r\n")));

        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim(strtolower($key))] = trim($value);
            }
        }

        return $headers;
    }


    private function calculateWebSocketAccept(string $secWebSocketKey): string
    {
        $magicString = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        return base64_encode(sha1($secWebSocketKey . $magicString, true));
    }


    public function generateHandshakeRequest(string $host, int $port, string $secWebSocketKey): string
    {
        return "GET / HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$secWebSocketKey}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
    }

    private function generateHandshakeResponse(array $headers): ?string
    {
        if (
            !isset($headers['upgrade']) || strtolower($headers['upgrade']) !== 'websocket' ||
            !isset($headers['connection']) || strtolower($headers['connection']) !== 'upgrade' ||
            !isset($headers['sec-websocket-key']) ||
            !isset($headers['sec-websocket-version']) || $headers['sec-websocket-version'] !== '13'
        ) {
            return null;
        }

        $secWebSocketKey = $headers['sec-websocket-key'];
        $magicString = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        $hash = base64_encode(sha1($secWebSocketKey . $magicString, true));

        return "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: $hash\r\n\r\n";
    }

    public function encodeWebSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $frameHead = [];
        $payloadLength = strlen($payload);

        $frameHead[0] = 0x80 | $opcode;

        $mask = true;
        $maskingKey = $mask ? random_bytes(4) : '';

        if ($payloadLength > 65535) {
            $frameHead[1] = ($mask ? 0x80 : 0) | 0x7F;
            $frameHead[2] = ($payloadLength >> 56) & 0xFF;
            $frameHead[3] = ($payloadLength >> 48) & 0xFF;
            $frameHead[4] = ($payloadLength >> 40) & 0xFF;
            $frameHead[5] = ($payloadLength >> 32) & 0xFF;
            $frameHead[6] = ($payloadLength >> 24) & 0xFF;
            $frameHead[7] = ($payloadLength >> 16) & 0xFF;
            $frameHead[8] = ($payloadLength >> 8) & 0xFF;
            $frameHead[9] = $payloadLength & 0xFF;
        } elseif ($payloadLength > 125) {
            $frameHead[1] = ($mask ? 0x80 : 0) | 0x7E;
            $frameHead[2] = ($payloadLength >> 8) & 0xFF;
            $frameHead[3] = $payloadLength & 0xFF;
        } else {
            $frameHead[1] = ($mask ? 0x80 : 0) | $payloadLength;
        }

        $maskedPayload = $payload;
        if ($mask) {
            for ($i = 0; $i < $payloadLength; $i++) {
                $maskedPayload[$i] = $payload[$i] ^ $maskingKey[$i % 4];
            }
        }

        error_log('Enviando frame WebSocket: ' . bin2hex(pack('C*', ...$frameHead) . ($mask ? $maskingKey : '') . $maskedPayload));
        return pack('C*', ...$frameHead) . ($mask ? $maskingKey : '') . $maskedPayload;
    }

    public  function decodeWebSocketFrame(string $data): ?string
    {
        error_log('Decodificando frame WebSocket: ' . bin2hex($data));
        $unmaskedPayload = '';
        $payloadOffset = 2;
        $masked = (ord($data[1]) >> 7) & 0x1;
        $payloadLength = ord($data[1]) & 0x7F;

        error_log("Máscara: $masked, Comprimento do payload: $payloadLength");

        if ($payloadLength === 126) {
            $payloadOffset = 4;
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
            error_log("Payload estendido (16 bits): $payloadLength");
        } elseif ($payloadLength === 127) {
            $payloadOffset = 10;
            $payloadLength = unpack('J', substr($data, 2, 8))[1];
            error_log("Payload estendido (64 bits): $payloadLength");
        }

        if ($masked) {
            if (strlen($data) < $payloadOffset + 4 + $payloadLength) {
                error_log('Frame incompleto: tamanho insuficiente');
                return null;
            }
            $maskingKey = substr($data, $payloadOffset, 4);
            $payload = substr($data, $payloadOffset + 4, $payloadLength);
            error_log('Chave de máscara: ' . bin2hex($maskingKey));
            for ($i = 0; $i < $payloadLength; $i++) {
                $unmaskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
            }
        } else {
            if (strlen($data) < $payloadOffset + $payloadLength) {
                error_log('Frame incompleto: tamanho insuficiente (sem máscara)');
                return null;
            }
            $unmaskedPayload = substr($data, $payloadOffset, $payloadLength);
        }

        error_log('Payload decodificado: ' . $unmaskedPayload);
        return $unmaskedPayload;
    }
}
