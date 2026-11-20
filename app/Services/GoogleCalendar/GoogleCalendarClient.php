<?php

namespace App\Services\GoogleCalendar;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien Google Calendar API v3 memakai Service Account (JWT RS256 → access token, di-cache 55 menit).
 * Tanpa paket google/apiclient. Calendar ID kalender utama seseorang = alamat Gmail-nya.
 */
class GoogleCalendarClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_URL = 'https://www.googleapis.com/calendar/v3';

    // Penuh: membuat kalender & mengatur ACL (berbagi) selain menulis event.
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';

    private ?array $key = null;

    /** Data file kunci JSON service account. */
    public function credentials(): array
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $path = (string) config('google_calendar.credentials');
        if ($path === '' || ! is_file($path)) {
            throw new GoogleCalendarException('File kunci Service Account belum dipasang di server (lihat Bantuan → Google Calendar).');
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new GoogleCalendarException('File kunci Service Account tidak valid. Unduh ulang kunci berformat JSON dari Google Cloud Console.');
        }

        return $this->key = $json;
    }

    public function configured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (GoogleCalendarException) {
            return false;
        }
    }

    /** Email service account — inilah yang harus diberi akses ke kalender tiap orang. */
    public function serviceAccountEmail(): ?string
    {
        return $this->configured() ? $this->credentials()['client_email'] : null;
    }

    /** Buat kalender sekunder milik Service Account; mengembalikan calendar ID. */
    public function createCalendar(string $summary): string
    {
        $data = $this->send('post', '/calendars', [
            'summary' => $summary,
            'timeZone' => config('google_calendar.timezone'),
            'description' => 'Janji temu Egao Dental Care (otomatis dari aplikasi).',
        ], $summary);

        return (string) $data['id'];
    }

    /** Bagikan kalender ke email (hak lihat) + Google mengirim email undangan. Mengembalikan ID aturan ACL. */
    public function share(string $calendarId, string $email): string
    {
        $data = $this->send('post', '/calendars/'.rawurlencode($calendarId).'/acl?sendNotifications=true', [
            'role' => 'reader',
            'scope' => ['type' => 'user', 'value' => $email],
        ], $calendarId);

        return (string) $data['id'];
    }

    /** Cabut akses; aturan yang sudah tidak ada dianggap berhasil. */
    public function unshare(string $calendarId, string $ruleId): void
    {
        try {
            $this->send('delete', '/calendars/'.rawurlencode($calendarId).'/acl/'.rawurlencode($ruleId), [], $calendarId);
        } catch (GoogleCalendarNotFound) {
            // sudah dicabut
        }
    }

    public function insert(string $calendarId, array $event): string
    {
        $data = $this->send('post', '/calendars/'.rawurlencode($calendarId).'/events?sendUpdates=none', $event, $calendarId);

        return (string) $data['id'];
    }

    /** Ubah event; null bila event sudah tidak ada (dihapus manual di Google). */
    public function patch(string $calendarId, string $eventId, array $event): ?string
    {
        try {
            $data = $this->send('patch', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId).'?sendUpdates=none', $event, $calendarId);
        } catch (GoogleCalendarNotFound) {
            return null;
        }

        return (string) $data['id'];
    }

    /** Hapus event; event yang sudah tidak ada dianggap berhasil. */
    public function delete(string $calendarId, string $eventId): void
    {
        try {
            $this->send('delete', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId).'?sendUpdates=none', [], $calendarId);
        } catch (GoogleCalendarNotFound) {
            // sudah terhapus
        }
    }

    public function token(): string
    {
        $key = $this->credentials();

        return Cache::remember('gcal_token:'.md5($key['client_email']), now()->addMinutes(55), function () use ($key) {
            try {
                $response = Http::asForm()->acceptJson()->timeout(config('google_calendar.timeout'))
                    ->post($key['token_uri'] ?? self::TOKEN_URL, [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $this->jwt($key),
                    ]);
            } catch (ConnectionException) {
                throw new GoogleCalendarException('Tidak dapat terhubung ke Google. Periksa koneksi internet server.');
            }

            $token = $response->json('access_token');
            if (! $response->successful() || ! $token) {
                throw new GoogleCalendarException('Login Service Account ditolak Google. Pastikan kunci masih aktif dan Google Calendar API sudah diaktifkan.');
            }

            return $token;
        });
    }

    private function jwt(array $key): string
    {
        $enc = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();

        $unsigned = $enc(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$enc(json_encode([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => $key['token_uri'] ?? self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $pkey = openssl_pkey_get_private($key['private_key']);
        if (! $pkey || ! openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new GoogleCalendarException('Private key Service Account tidak bisa dibaca. Unduh ulang kunci JSON.');
        }

        return $unsigned.'.'.$enc($signature);
    }

    private function send(string $method, string $path, array $data, string $calendarId, bool $retried = false): array
    {
        try {
            $request = Http::withToken($this->token())->acceptJson()->timeout(config('google_calendar.timeout'));
            $response = $method === 'get' || $method === 'delete'
                ? $request->{$method}(self::API_URL.$path)
                : $request->asJson()->{$method}(self::API_URL.$path, $data);
        } catch (ConnectionException) {
            throw new GoogleCalendarException('Tidak dapat terhubung ke Google. Periksa koneksi internet server.');
        }

        if ($response->status() === 401 && ! $retried) {
            Cache::forget('gcal_token:'.md5($this->credentials()['client_email']));

            return $this->send($method, $path, $data, $calendarId, true);
        }

        if (in_array($response->status(), [404, 410], true) && (str_contains($path, '/events/') || str_contains($path, '/acl/'))) {
            throw new GoogleCalendarNotFound('Event tidak ditemukan.');
        }

        if (! $response->successful()) {
            throw new GoogleCalendarException($this->describeError($response, $calendarId));
        }

        return $response->json() ?? [];
    }

    private function describeError(Response $response, string $calendarId): string
    {
        return match (true) {
            $response->status() === 404 => 'Kalender Egao tidak ditemukan di Google (mungkin terhapus). Tekan "Siapkan kalender" di Pengaturan › Google Calendar.',
            $response->status() === 403 => 'Akses ditolak Google: pastikan Google Calendar API aktif di project Service Account.',
            $response->status() === 429 => 'Batas permintaan Google tercapai. Akan dicoba lagi otomatis.',
            default => 'Google Calendar menolak permintaan (HTTP '.$response->status().'): '
                .str((string) ($response->json('error.message') ?? $response->body()))->limit(200),
        };
    }
}
