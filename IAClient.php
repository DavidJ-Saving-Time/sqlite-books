<?php

class IAClient
{
    private string $email;
    private string $password;
    private string $jar;       // internal cookie jar path (temp)
    private bool $loggedIn = false;
    private int $timeout;

    private string $loginPage = 'https://archive.org/account/login';
    private string $avail     = 'https://archive.org/services/loans/availability';
    private string $loans     = 'https://archive.org/services/loans/loan/';

    public function __construct(string $email, string $password, int $timeout = 25)
    {
        $this->email    = $email;
        $this->password = $password;
        $this->timeout  = $timeout;
        $this->jar      = sys_get_temp_dir() . '/ia_' . bin2hex(random_bytes(6)) . '.jar';
    }

    public function availability(string $identifier): array
    {
        $url = $this->avail . '?' . http_build_query(['identifier' => $identifier]);
        return $this->requestJson('GET', $url);
    }

    public function borrow(string $identifier): array
    {
        $this->ensureSession();
        $res = $this->requestJson('POST', $this->loans, ['action' => 'borrow_book', 'identifier' => $identifier]);
        if ($this->unauth($res)) { $this->relogin(); $res = $this->requestJson('POST', $this->loans, ['action'=>'borrow_book','identifier'=>$identifier]); }
        return $res;
    }

    public function mediaUrl(string $identifier, string $format = 'pdf', bool $follow = false): array
    {
        $this->ensureSession();
        $qs = http_build_query(['action'=>'media_url','identifier'=>$identifier,'format'=>$format,'redirect'=>1]);
        $head = $this->head($this->loans.'?'.$qs, $follow);
        return ['status'=>$head['status'], 'location'=>$head['location'] ?? null, 'headers'=>$head['headers']];
    }

    public function returnLoan(string $identifier): array
    {
        $this->ensureSession();
        $res = $this->requestJson('POST', $this->loans, ['action'=>'return_loan','identifier'=>$identifier]);
        if ($this->unauth($res)) { $this->relogin(); $res = $this->requestJson('POST', $this->loans, ['action'=>'return_loan','identifier'=>$identifier]); }
        return $res;
    }

    /* ---- internals ---- */
    private function ensureSession(): void
    {
        if ($this->loggedIn && $this->hasSessionCookies()) return;
        $this->login();
    }
    private function relogin(): void
    {
        $this->loggedIn = false;
        @unlink($this->jar);
        $this->login();
    }
    private function hasSessionCookies(): bool
    {
        if (!is_file($this->jar)) return false;
        $txt = @file_get_contents($this->jar);
        return $txt && stripos($txt, 'logged-in-user') !== false && stripos($txt, 'logged-in-sig') !== false;
    }
    private function login(): void
    {
        // 1) Fetch login page
        $html = $this->requestRaw('GET', $this->loginPage);

        // 2) Parse form action + hidden inputs
        [$action, $fields] = $this->parseForm($html, $this->loginPage);

        // 3) Populate credentials (field names can vary)
        $emailKey = $this->pickField($fields, ['username','email','login','input-email']) ?? 'username';
        $passKey  = $this->pickField($fields, ['password','passwd','input-password'])  ?? 'password';
        $fields[$emailKey] = $this->email;
        $fields[$passKey]  = $this->password;

        // 4) Submit login
        $this->requestRaw('POST', $action, $fields, true);

        if (!$this->hasSessionCookies()) {
            throw new RuntimeException('IA login failed (no session cookies set). Check email/password or account status.');
        }
        $this->loggedIn = true;
    }
    private function unauth($resp): bool
    {
        if (!is_array($resp)) return false;
        $s = json_encode($resp);
        return stripos($s, 'unauth') !== false || stripos($s, 'not logged in') !== false || stripos($s, 'forbidden') !== false;
    }
    private function parseForm(string $html, string $base): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $forms = $doc->getElementsByTagName('form');
        if ($forms->length === 0) return [$base, []];
        /** @var DOMElement $form */
        $form   = $forms->item(0);
        $action = $form->getAttribute('action') ?: $base;
        if (!preg_match('~^https?://~i', $action)) {
            $action = rtrim($this->origin($base), '/') . '/' . ltrim($action, '/');
        }

        $fields = [];
        foreach ($form->getElementsByTagName('input') as $in) {
            $name  = $in->getAttribute('name'); if ($name==='') continue;
            $type  = strtolower($in->getAttribute('type'));
            $value = $in->getAttribute('value');
            if (in_array($type, ['hidden','text','email','password'])) $fields[$name] = $value;
        }
        return [$action, $fields];
    }
    private function pickField(array $fields, array $candidates): ?string
    {
        foreach ($candidates as $cand) {
            foreach ($fields as $k => $_) if (strcasecmp($k, $cand) === 0) return $k;
        }
        return null;
    }
    private function origin(string $url): string
    {
        $p = parse_url($url);
        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host'] ?? '';
        $port   = isset($p['port']) ? ':' . $p['port'] : '';
        return $scheme . '://' . $host . $port;
    }
    private function baseCurl(string $url): \CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HEADER         => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_USERAGENT      => 'IAClient-PHP/1.0',
        ]);
        return $ch;
    }
    private function requestJson(string $method, string $url, array $post = null): array
    {
        $raw = $this->requestRaw($method, $url, $post);
        $json = json_decode($raw, true);
        return is_array($json) ? $json : ['raw' => $raw];
    }
    private function requestRaw(string $method, string $url, array $post = null, bool $formEncoded = false): string
    {
        $ch = $this->baseCurl($url);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($formEncoded) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post ?? []);
            }
        }

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo) throw new RuntimeException("cURL error: $err", $errNo);
        if ($code >= 400) throw new RuntimeException("HTTP $code for $url\n$body");
        return $body ?: '';
    }
    private function head(string $url, bool $follow): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_USERAGENT      => 'IAClient-PHP/1.0',
        ]);
        $headers = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc     = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if (!$follow && preg_match('/\nLocation:\s*(.+)\r?/i', $headers, $m)) {
            $loc = trim($m[1]);
        }
        return ['status'=>$status,'location'=>$loc,'headers'=>$headers];
    }
}
