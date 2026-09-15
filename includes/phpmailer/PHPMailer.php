<?php
/**
 * PHPMailer — Robust bundled SMTP mailer for Despatch Management System
 * Handles: STARTTLS (port 587), SSL (port 465), Plain (port 25)
 */
namespace PHPMailer\PHPMailer;

class Exception extends \Exception {}

class SMTP
{
    const CRLF = "\r\n";
    private $conn = null;
    public  $Timeout   = 30;
    private $lastReply = '';
    public  $debugLog  = [];

    public function connect(string $host, int $port, int $timeout = 30): bool
    {
        $errno = 0; $errstr = '';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]);
        $this->conn = @stream_socket_client(
            $host . ':' . $port, $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->conn) {
            $this->dbg("CONNECT FAILED [{$host}:{$port}] — {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($this->conn, $timeout);
        $banner = $this->read();
        $this->dbg("<<< {$banner}");
        return $this->code($banner) === 220;
    }

    public function startTLS(): bool
    {
        // RFC 3207: client sends STARTTLS, server responds 220
        if (!$this->cmd('STARTTLS', 220)) return false;
        stream_context_set_option($this->conn, 'ssl', 'verify_peer',      false);
        stream_context_set_option($this->conn, 'ssl', 'verify_peer_name', false);
        stream_context_set_option($this->conn, 'ssl', 'allow_self_signed',true);
        $ok = stream_socket_enable_crypto(
            $this->conn, true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
            | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
            | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        );
        $this->dbg('TLS upgrade: ' . ($ok ? 'OK' : 'FAILED'));
        return (bool)$ok;
    }

    public function hello(string $host): bool
    {
        return $this->cmd("EHLO {$host}", 250) || $this->cmd("HELO {$host}", 250);
    }

    public function auth(string $user, string $pass, string $method = 'LOGIN'): bool
    {
        if ($method === 'LOGIN') {
            if (!$this->cmd('AUTH LOGIN', 334)) return false;
            if (!$this->cmd(base64_encode($user), 334)) return false;
            return $this->cmd(base64_encode($pass), 235);
        }
        if ($method === 'PLAIN') {
            return $this->cmd('AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), 235);
        }
        return false;
    }

    public function mailFrom(string $from): bool { return $this->cmd("MAIL FROM:<{$from}>", 250); }
    public function rcptTo(string $to): bool      { return $this->cmd("RCPT TO:<{$to}>",    [250,251]); }

    public function data(string $message): bool
    {
        if (!$this->cmd('DATA', 354)) return false;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $message));
        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
            fwrite($this->conn, rtrim($line, "\r") . self::CRLF);
        }
        return $this->cmd('.', 250);
    }

    public function quit(): void
    {
        if (is_resource($this->conn)) {
            @fwrite($this->conn, 'QUIT' . self::CRLF);
            fclose($this->conn);
        }
        $this->conn = null;
    }

    public function getLastReply(): string { return $this->lastReply; }

    private function cmd(string $command, $expect): bool
    {
        if (!is_resource($this->conn)) return false;
        $this->dbg(">>> {$command}");
        fwrite($this->conn, $command . self::CRLF);
        $reply = $this->read();
        $this->dbg("<<< {$reply}");
        $c = $this->code($reply);
        return is_array($expect) ? in_array($c, $expect, true) : ($c === $expect);
    }

    private function read(): string
    {
        $data = ''; $end = time() + $this->Timeout;
        while (is_resource($this->conn) && !feof($this->conn)) {
            if (time() > $end) break;
            $line = fgets($this->conn, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $this->lastReply = trim($data);
        return $this->lastReply;
    }

    private function code(string $r): int { return (int)substr(ltrim($r), 0, 3); }
    private function dbg(string $m): void { $this->debugLog[] = date('H:i:s') . ' ' . $m; }
}

class PHPMailer
{
    public string $Host        = '';
    public int    $Port        = 587;
    public string $SMTPSecure  = 'tls';
    public bool   $SMTPAuth    = true;
    public string $Username    = '';
    public string $Password    = '';
    public string $AuthType    = 'LOGIN';
    public string $From        = '';
    public string $FromName    = '';
    public string $Subject     = '';
    public string $Body        = '';
    public string $AltBody     = '';
    public string $CharSet     = 'UTF-8';
    public string $ContentType = 'text/html';
    public string $Mailer      = 'smtp';
    public int    $Timeout     = 30;
    public string $ErrorInfo   = '';

    private array  $to          = [];
    private array  $cc          = [];
    private array  $bcc         = [];
    private array  $attachments = [];
    private string $boundary    = '';
    private ?SMTP  $smtp        = null;

    public function isSMTP(): void { $this->Mailer = 'smtp'; }
    public function isMail(): void { $this->Mailer = 'mail'; }

    public function addAddress(string $e, string $n = ''): bool { return $this->addAddr('to',  $e, $n); }
    public function addCC     (string $e, string $n = ''): bool { return $this->addAddr('cc',  $e, $n); }
    public function addBCC    (string $e, string $n = ''): bool { return $this->addAddr('bcc', $e, $n); }
    public function clearAddresses(): void  { $this->to  = []; }
    public function clearCCs(): void        { $this->cc  = []; }
    public function clearAttachments(): void{ $this->attachments = []; }

    private function addAddr(string $kind, string $e, string $n): bool
    {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $this->ErrorInfo = "Invalid address: {$e}"; return false;
        }
        $this->{$kind}[] = [$e, $n];
        return true;
    }

    public function addAttachment(string $path, string $name='', string $enc='base64', string $type='application/octet-stream'): bool
    {
        if (!file_exists($path)) { $this->ErrorInfo = "File not found: {$path}"; return false; }
        $this->attachments[] = ['src'=>'file','path'=>$path,'name'=>$name?:basename($path),'mime'=>$type];
        return true;
    }

    public function addStringAttachment(string $data, string $filename, string $enc='base64', string $type='application/octet-stream'): bool
    {
        $this->attachments[] = ['src'=>'string','data'=>$data,'name'=>$filename,'mime'=>$type];
        return true;
    }

    public function send(): bool
    {
        $this->ErrorInfo = '';
        if (empty($this->to))   { $this->ErrorInfo = 'No To address set.';   return false; }
        if (empty($this->From)) { $this->ErrorInfo = 'No From address set.';  return false; }
        try {
            return $this->Mailer === 'smtp' ? $this->smtpSend() : $this->mailSend();
        } catch (\Throwable $e) {
            $this->ErrorInfo = $e->getMessage(); return false;
        }
    }

    private function smtpSend(): bool
    {
        $this->smtp = new SMTP();
        $this->smtp->Timeout = $this->Timeout;

        // SSL wraps the socket; TLS upgrades it after connect
        $connectHost = ($this->SMTPSecure === 'ssl') ? 'ssl://' . $this->Host : $this->Host;

        if (!$this->smtp->connect($connectHost, $this->Port, $this->Timeout)) {
            $this->ErrorInfo = "Cannot connect to {$this->Host}:{$this->Port}. "
                . "Debug: " . implode(' | ', array_slice($this->smtp->debugLog, -4));
            return false;
        }

        $hello = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        if (!$this->smtp->hello($hello)) {
            $this->setErr('EHLO failed'); return false;
        }

        if ($this->SMTPSecure === 'tls') {
            if (!$this->smtp->startTLS()) {
                $this->setErr('STARTTLS failed'); return false;
            }
            // Re-negotiate EHLO after TLS
            if (!$this->smtp->hello($hello)) {
                $this->setErr('EHLO after TLS failed'); return false;
            }
        }

        if ($this->SMTPAuth) {
            if (!$this->smtp->auth($this->Username, $this->Password, $this->AuthType)) {
                $this->setErr('Authentication failed — check username/password'); return false;
            }
        }

        if (!$this->smtp->mailFrom($this->From)) {
            $this->setErr('MAIL FROM rejected'); return false;
        }

        foreach (array_merge($this->to, $this->cc, $this->bcc) as [$addr]) {
            if (!$this->smtp->rcptTo($addr)) {
                $this->setErr("RCPT TO rejected for {$addr}"); return false;
            }
        }

        $msg = $this->buildHeaders() . "\r\n" . $this->buildBody();
        if (!$this->smtp->data($msg)) {
            $this->setErr('DATA failed'); return false;
        }

        $this->smtp->quit();
        return true;
    }

    private function mailSend(): bool
    {
        $headers = $this->buildHeaders();
        $body    = $this->buildBody();
        return mail(
            $this->formatList($this->to),
            $this->enc($this->Subject),
            $body,
            $headers
        );
    }

    private function buildHeaders(): string
    {
        $this->boundary = '=_' . md5(uniqid('', true));
        $h  = "MIME-Version: 1.0\r\n";
        $h .= "Date: " . date('r') . "\r\n";
        $h .= "Message-ID: <" . md5(uniqid('', true)) . "@despatch>\r\n";
        $h .= "From: " . $this->fmt($this->From, $this->FromName) . "\r\n";
        $h .= "To: "   . $this->formatList($this->to) . "\r\n";
        if ($this->cc) $h .= "Cc: " . $this->formatList($this->cc) . "\r\n";
        $h .= "Subject: " . $this->enc($this->Subject) . "\r\n";
        $h .= "X-Mailer: DespatchMgmt/2.0\r\n";
        if (empty($this->attachments)) {
            $h .= "Content-Type: {$this->ContentType}; charset={$this->CharSet}\r\n";
            $h .= "Content-Transfer-Encoding: base64\r\n";
        } else {
            $h .= "Content-Type: multipart/mixed; boundary=\"{$this->boundary}\"\r\n";
        }
        return $h;
    }

    private function buildBody(): string
    {
        if (empty($this->attachments)) {
            return chunk_split(base64_encode($this->Body));
        }
        $b   = $this->boundary;
        $msg = "--{$b}\r\n"
             . "Content-Type: {$this->ContentType}; charset={$this->CharSet}\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($this->Body)) . "\r\n";
        foreach ($this->attachments as $a) {
            $raw  = $a['src'] === 'file' ? file_get_contents($a['path']) : $a['data'];
            $name = $this->enc($a['name']);
            $msg .= "--{$b}\r\n"
                  . "Content-Type: {$a['mime']}; name=\"{$name}\"\r\n"
                  . "Content-Transfer-Encoding: base64\r\n"
                  . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                  . chunk_split(base64_encode($raw)) . "\r\n";
        }
        $msg .= "--{$b}--\r\n";
        return $msg;
    }

    private function fmt(string $e, string $n = ''): string
    {
        return $n === '' ? $e : '"' . addslashes($n) . '" <' . $e . '>';
    }
    private function formatList(array $list): string
    {
        return implode(', ', array_map(fn($a) => $this->fmt($a[0], $a[1]), $list));
    }
    private function enc(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
    private function setErr(string $msg): void
    {
        $log = $this->smtp ? implode(' | ', array_slice($this->smtp->debugLog, -6)) : '';
        $this->ErrorInfo = $msg . '. Reply: ' . ($this->smtp ? $this->smtp->getLastReply() : '')
                         . ' | Debug: ' . $log;
        $this->smtp && $this->smtp->quit();
    }

    public function getDebugLog(): array { return $this->smtp ? $this->smtp->debugLog : []; }
}
