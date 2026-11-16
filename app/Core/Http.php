<?php
declare(strict_types=1);

namespace App\Core;

final class Http
{
    /** @return array{status:int,body:array,raw:string} */
    public static function json(string $method, string $url, array $payload = [], array $headers = [], int $timeout = 20): array
    {
        $ch = curl_init($url);
        $isPost = strtoupper($method) !== 'GET';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        ]);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            throw new \RuntimeException('HTTP error: ' . $err);
        }
        $decoded = json_decode($raw, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $raw];
    }
}
