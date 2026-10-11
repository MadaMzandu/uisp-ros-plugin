<?php
/*****************************
 *
 * RouterOS PHP API class v1.6
 * Author: Denis Basta
 * Contributors:
 *    Nick Barnes
 *    Ben Menking (ben [at] infotechsc [dot] com)
 *    Jeremy Jefferson (http://jeremyj.com)
 *    Cristian Deluxe (djcristiandeluxe [at] gmail [dot] com)
 *    Mikhail Moskalev (mmv.rus [at] gmail [dot] com)
 *
 * http://www.mikrotik.com
 * http://wiki.mikrotik.com/wiki/API_PHP_class
 *
 ******************************/

class RouterosAPI
{
    var $debug = false; //  Show debug information
    var $connected = false; //  Connection state
    var $port = 8728;  //  Port to connect to (default 8729 for ssl)
    var $ssl = false; //  Connect using SSL (must enable api-ssl in IP/Services)
    var $allow_self_signed = false;
    var $timeout = 3;     //  Connection attempt timeout and data read timeout
    var $attempts = 5;     //  Connection attempt count
    var $delay = 3;     //  Delay between connection attempts in seconds

    var $socket;            //  Variable for storing socket resource
    var $error_no;          //  Variable for storing connection error number, if any
    var $error_str;         //  Variable for storing connection error text, if any
    private ?string $connection_failure = null;
    private bool $logging_in = false;

    /* Check, can be var used in foreach  */

    /**
     * Login to RouterOS
     *
     * @param string $ip Hostname (IP or domain) of the RouterOS server
     * @param string $login The RouterOS username
     * @param string $password The RouterOS password
     *
     * @return boolean                If we are connected or not
     */
    public function connect($ip, $login, $password)
    {
        $host = trim((string)$ip, '[]');
        $target = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $transportFailure = $this->ssl ? 'router_tls_connect_failed' : 'router_tcp_connect_failed';
        $this->connection_failure = $transportFailure;
        $this->error_no = 0;
        $this->error_str = $transportFailure;
        for ($ATTEMPT = 1; $ATTEMPT <= $this->attempts; $ATTEMPT++) {
            $this->connected = false;
            $this->connection_failure = null;
            $this->error_no = 0;
            $this->error_str = null;
            $PROTOCOL = ($this->ssl ? 'tls://' : 'tcp://');
            $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                'allow_self_signed' => (bool)$this->allow_self_signed, 'peer_name' => $host]]);
            $this->debug('Connection attempt #' . $ATTEMPT . ' to ' . $PROTOCOL . $target . ':' . $this->port . '...');
            try { $this->socket = $this->openSocket($PROTOCOL . $target . ':' . $this->port, $context); }
            catch (Throwable $error) { $this->socket = false; }
            if ($this->socket) {
                stream_set_timeout($this->socket, $this->timeout);
                try {
                $this->logging_in = true;
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $RESPONSE = $this->read(false);
                $state = $this->loginResponseState($RESPONSE, true);
                if ($state === 'challenge') {
                    // Login method pre-v6.43; only a valid hexadecimal challenge is usable.
                    $challenge = substr($RESPONSE[1], 5);
                    $this->write('/login', false);
                    $this->write('=name=' . $login, false);
                    $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $challenge)));
                    $state = $this->loginResponseState($this->read(false), false);
                }
                if ($state === 'success') { $this->connected = true; break; }
                $this->connection_failure = $state;
                } catch (Throwable $error) {
                    $this->connection_failure = $this->loginExceptionCode($error);
                } finally {
                    $this->logging_in = false;
                }
                $this->error_str = $this->connection_failure;
                $this->disconnect();
            } else $this->error_str = $this->connection_failure = $transportFailure;
            if ($ATTEMPT < $this->attempts) sleep($this->delay);
        }

        if ($this->connected) {
            $this->connection_failure = null;
            $this->error_no = 0;
            $this->error_str = null;
            $this->debug('Connected...');
        } else {
            $this->debug('Error...');
        }
        return $this->connected;
    }

    /** Safe diagnostic only; a TLS connection failure can involve network or negotiation. */
    public function connectionFailure(): ?string { return $this->connection_failure; }

    protected function openSocket(string $address, $context)
    {
        $transportError = '';
        return @stream_socket_client($address, $this->error_no, $transportError, $this->timeout, STREAM_CLIENT_CONNECT, $context);
    }

    private function loginResponseState($response, bool $allowChallenge): string
    {
        if (!is_array($response)) return 'router_login_protocol_error';
        if (in_array('!trap', $response, true)) return 'router_login_rejected';
        if (in_array('!fatal', $response, true)) return 'router_login_connection_closed';
        if ($response === ['!done']) return 'success';
        if ($allowChallenge && count($response) === 2 && ($response[0] ?? null) === '!done'
            && is_string($response[1]) && preg_match('/^=ret=[a-fA-F0-9]{32}$/D', $response[1]) === 1) return 'challenge';
        return 'router_login_protocol_error';
    }

    private function loginExceptionCode(Throwable $error): string
    {
        switch ($error->getMessage()) {
            case 'RouterOS response timed out': return 'router_login_timeout';
            case 'RouterOS connection is closed':
            case 'RouterOS connection closed during response': return 'router_login_connection_closed';
            case 'RouterOS connection failed while writing': return 'router_login_write_failed';
            default: return 'router_login_protocol_error';
        }
    }

    /**
     * Print text for debug purposes
     *
     * @param string $text Text to print
     *
     * @return void
     */
    public function debug($text)
    {
        if ($this->debug) {
            echo $text . "\n";
        }
    }

    /**
     * Write (send) data to Router OS
     *
     * @param string $command A string with the command to send
     * @param mixed $param2 If we set an integer, the command will send this data as a "tag"
     *                                If we set it to boolean true, the funcion will send the comand and finish
     *                                If we set it to boolean false, the funcion will send the comand and wait for next command
     *                                Default: true
     *
     * @return boolean                Return false if no command especified
     */
    public function write($command, $param2 = true)
    {
        if ($command) {
            $command = (string)$command;
            $this->writeBytes($this->encodeLength(strlen($command)) . $command);
            $safe = $this->logging_in && strpos($command, '=') === 0 ? '=login-attribute=[redacted]'
                : (preg_match('/^=(password|response)=/i', $command) ? '=secret=[redacted]' : $command);
            $this->debug('<<< [' . strlen($command) . '] ' . $safe);

            if (gettype($param2) == 'integer') {
                $this->writeBytes($this->encodeLength(strlen('.tag=' . $param2)) . '.tag=' . $param2 . chr(0));
                $this->debug('<<< [' . strlen('.tag=' . $param2) . '] .tag=' . $param2);
            } elseif (gettype($param2) == 'boolean') {
                if ($param2) $this->writeBytes(chr(0));
            }

            return true;
        } else {
            return false;
        }
    }

    private function writeBytes(string $bytes): void
    {
        if (!is_resource($this->socket)) throw new RuntimeException('RouterOS connection is closed');
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($this->socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                $this->connected = false;
                throw new RuntimeException('RouterOS connection failed while writing');
            }
            $offset += $written;
        }
    }

    public function finishSentence(): void
    {
        $this->writeBytes(chr(0));
    }

    private function readBytes(int $length): string
    {
        if (!is_resource($this->socket)) throw new RuntimeException('RouterOS connection is closed');
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = @fread($this->socket, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                $status = stream_get_meta_data($this->socket);
                $this->connected = false;
                throw new RuntimeException(!empty($status['timed_out'])
                    ? 'RouterOS response timed out' : 'RouterOS connection closed during response');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    /**
     *
     *
     * @param string $length
     *
     * @return void
     */
    public function encodeLength($length)
    {
        if ($length < 0x80) {
            $length = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $length = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $length = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $length = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length >= 0x10000000) {
            $length = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        return $length;
    }

    /**
     * Read data from Router OS
     *
     * @param boolean $parse Parse the data? default: true
     *
     * @return array                  Array with parsed or unparsed data
     */
    public function read($parse = true)
    {
        $RESPONSE = array();
        $receiveddone = false;
        while (true) {
            // Read the first byte of input which gives us some or all of the length
            // of the remaining reply.
            $BYTE = ord($this->readBytes(1));
            if ($BYTE > 0xF0) throw new RuntimeException('RouterOS returned an unsupported control byte');
            $LENGTH = 0;
            // If the first bit is set then we need to remove the first four bits, shift left 8
            // and then read another byte in.
            // We repeat this for the second and third bits.
            // If the fourth bit is set, we need to remove anything left in the first byte
            // and then read in yet another byte.
            if ($BYTE & 128) {
                if (($BYTE & 192) == 128) {
                    $LENGTH = (($BYTE & 63) << 8) + ord($this->readBytes(1));
                } else {
                    if (($BYTE & 224) == 192) {
                        $LENGTH = (($BYTE & 31) << 8) + ord($this->readBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readBytes(1));
                    } else {
                        if (($BYTE & 240) == 224) {
                            $LENGTH = (($BYTE & 15) << 8) + ord($this->readBytes(1));
                            $LENGTH = ($LENGTH << 8) + ord($this->readBytes(1));
                            $LENGTH = ($LENGTH << 8) + ord($this->readBytes(1));
                        } else {
                            $LENGTH = ord($this->readBytes(1));
                            $LENGTH = ($LENGTH << 8) + ord($this->readBytes(1));
                            $LENGTH = ($LENGTH << 8) + ord($this->readBytes(1));
                            $LENGTH = ($LENGTH << 8) + ord($this->readBytes(1));
                        }
                    }
                }
            } else {
                $LENGTH = $BYTE;
            }

            $_ = "";

            // If we have got more characters to read, read them in.
            if ($LENGTH > 0) {
                if ($LENGTH > 16 * 1024 * 1024) throw new RuntimeException('RouterOS response word exceeds the limit');
                $_ = $this->readBytes($LENGTH);
                $retlen = strlen($_);
                $RESPONSE[] = $_;
                $this->debug('>>> [' . $retlen . '/' . $LENGTH . '] bytes read.');
            }

            // If we get a !done, make a note of it.
            if ($_ == "!done" || $_ == '!fatal') {
                $receiveddone = true;
            }
            if ($_ === '!fatal') $this->connected = false;

            $STATUS = socket_get_status($this->socket);
            if ($LENGTH > 0) {
                $safe = $this->logging_in ? '[login reply word]'
                    : (preg_match('/^=(password|response)=/i', $_) ? '=secret=[redacted]' : $_);
                $this->debug('>>> [' . $LENGTH . ', ' . $STATUS['unread_bytes'] . ']' . $safe);
            }

            if ($LENGTH === 0 && $receiveddone) {
                break;
            }
        }

        if ($parse) {
            $RESPONSE = $this->parseResponse($RESPONSE);
        }

        return $RESPONSE;
    }

    /**
     * Parse response from Router OS
     *
     * @param array $response Response data
     *
     * @return array                  Array with parsed data
     */
    public function parseResponse($response)
    {
        if (is_array($response)) {
            $PARSED = array();
            $CURRENT = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, array('!fatal', '!re', '!trap'))) {
                    if ($x == '!re') {
                        $CURRENT =& $PARSED[];
                    } else {
                        $CURRENT =& $PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = array();
                    if (isset($x[0]) && $x[0] === '=') {
                        [$key, $value] = array_pad(explode('=', substr($x, 1), 2), 2, '');
                        if ($key === 'ret') $singlevalue = $value;
                        $CURRENT[$key] = $value;
                    }
                }
            }

            if (empty($PARSED) && !is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }

            return $PARSED;
        } else {
            return array();
        }
    }


    /**
     * Parse response from Router OS
     *
     * @param array $response Response data
     *
     * @return array                  Array with parsed data
     */
    public function parseResponse4Smarty($response)
    {
        if (is_array($response)) {
            $PARSED = array();
            $CURRENT = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, array('!fatal', '!re', '!trap'))) {
                    if ($x == '!re') {
                        $CURRENT =& $PARSED[];
                    } else {
                        $CURRENT =& $PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = array();
                    if (preg_match_all('/[^=]+/i', $x, $MATCHES)) {
                        if ($MATCHES[0][0] == 'ret') {
                            $singlevalue = $MATCHES[0][1];
                        }
                        $CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');
                    }
                }
            }
            foreach ($PARSED as $key => $value) {
                $PARSED[$key] = $this->arrayChangeKeyName($value);
            }
            return $PARSED;
            if (empty($PARSED) && !is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }
        } else {
            return array();
        }
    }


    /**
     * Change "-" and "/" from array key to "_"
     *
     * @param array $array Input array
     *
     * @return array                  Array with changed key names
     */
    public function arrayChangeKeyName(&$array)
    {
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                $tmp = str_replace("-", "_", $k);
                $tmp = str_replace("/", "_", $tmp);
                if ($tmp) {
                    $array_new[$tmp] = $v;
                } else {
                    $array_new[$k] = $v;
                }
            }
            return $array_new;
        } else {
            return $array;
        }
    }

    /**
     * Write (send) data to Router OS
     *
     * @param string $com A string with the command to send
     * @param array $arr An array with arguments or queries
     *
     * @return array                  Array with parsed
     */
    public function comm($com, $arr = array())
    {
        $count = count($arr);
        $this->write($com, !$arr);
        $i = 0;
        if ($this->isIterable($arr)) {
            foreach ($arr as $k => $v) {
                switch ($k[0]) {
                    case "?":
                        $el = "$k=$v";
                        break;
                    case "~":
                        $el = "$k~$v";
                        break;
                    default:
                        $el = "=$k=$v";
                        break;
                }

                $last = ($i++ == $count - 1);
                $this->write($el, $last);
            }
        }

        return $this->read();
    }

    public function isIterable($var)
    {
        return $var !== null
            && (is_array($var)
                || $var instanceof Traversable
                || $var instanceof Iterator
                || $var instanceof IteratorAggregate
            );
    }

    /**
     * Standard destructor
     *
     * @return void
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Disconnect from RouterOS
     *
     * @return void
     */
    public function disconnect()
    {
        // let's make sure this socket is still valid.  it may have been closed by something else
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
        $this->socket = null;
        $this->debug('Disconnected...');
    }
}
