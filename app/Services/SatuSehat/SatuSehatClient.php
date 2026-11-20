<?php

namespace App\Services\SatuSehat;

use App\Models\Branch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP SATUSEHAT untuk satu cabang: token OAuth2 (client credentials, di-cache) + panggilan FHIR R4.
 */
class SatuSehatClient
{
    public function __construct(private Branch $branch, private string $env)
    {
        if (! $branch->hasSatuSehatCredentials()) {
            throw new SatuSehatException("Kredensial SATUSEHAT {$branch->name} belum lengkap (Organization ID, Client ID, Client Secret).");
        }
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->send('post', $path, $body);
    }

    public function token(): string
    {
        return Cache::remember($this->tokenKey(), now()->addMinutes(50), function () {
            try {
                $response = Http::asForm()->acceptJson()->timeout(config('satusehat.timeout'))
                    ->post($this->url('auth_url').'/accesstoken?grant_type=client_credentials', [
                        'client_id' => $this->branch->satusehat_client_id,
                        'client_secret' => $this->branch->satusehat_client_secret,
                    ]);
            } catch (ConnectionException) {
                throw new SatuSehatException('Tidak dapat terhubung ke SATUSEHAT. Periksa koneksi internet server.');
            }

            $token = $response->json('access_token');
            if (! $response->successful() || ! $token) {
                throw new SatuSehatException('Login SATUSEHAT gagal: Client ID / Client Secret salah atau tidak cocok dengan pilihan Sandbox/Production.');
            }

            return $token;
        });
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    private function send(string $method, string $path, array $data, bool $retried = false): array
    {
        try {
            $response = Http::withToken($this->token())->acceptJson()->timeout(config('satusehat.timeout'))
                ->{$method}(rtrim($this->url('fhir_url').'/'.ltrim($path, '/'), '/'), $data);
        } catch (ConnectionException) {
            throw new SatuSehatException('Tidak dapat terhubung ke SATUSEHAT. Periksa koneksi internet server.');
        }

        if ($response->status() === 401 && ! $retried) {
            $this->forgetToken();

            return $this->send($method, $path, $data, true);
        }

        if (! $response->successful()) {
            throw new SatuSehatException(static::describeError($response));
        }

        return $response->json() ?? [];
    }

    /** Ringkas pesan error SATUSEHAT (OperationOutcome) menjadi satu kalimat. */
    public static function describeError(Response $response): string
    {
        $issues = collect($response->json('issue') ?? [])
            ->map(fn ($i) => $i['details']['text'] ?? $i['diagnostics'] ?? $i['code'] ?? null)
            ->filter()->unique()->take(3);

        $detail = $issues->isNotEmpty()
            ? $issues->implode('; ')
            : (string) ($response->json('fault.faultstring') ?? $response->json('message') ?? '');

        return str('SATUSEHAT menolak (HTTP '.$response->status().')'.($detail !== '' ? ': '.$detail : '.'))->limit(500)->toString();
    }

    private function url(string $key): string
    {
        return rtrim((string) config("satusehat.environments.{$this->env}.{$key}"), '/');
    }

    private function tokenKey(): string
    {
        return "satusehat:token:{$this->env}:{$this->branch->id}:".md5((string) $this->branch->satusehat_client_id);
    }
}
