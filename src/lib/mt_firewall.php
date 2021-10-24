<?php

class MT_Firewall extends MT {

    private $source; //source address list
    private $action;

    public function __construct(&$data) {
        parent::__construct($data);
        $this->path = '/ip/firewall/filter/';
    }

    public function test() {
        $this->set('disabled', 'drop');
    }

    public function set($source, $action) {
        $this->source = $source;
        $this->action = $action;
        $this->write($this->$action(), 'add');
    }

    public function delete($source, $action) {
        $this->source = $source;
        $this->action = $action;
        $this->write($this->$action(), 'remove');
    }

    private function get_host_ip() {
        $a = json_decode(shell_exec('ip -4 -j address list up'), true);
        return $a[1]['addr_info'][0]['local'] ?? false ; // best guess - skip the loop back
    }

    private function drop() {
        return (object) [
                    'src-address-list' => $this->source,
                    'chain' => 'forward',
                    'connection-state' => 'new',
                    'action' => 'drop',
                    'comment' => $this->comment($this->source),
        ];
    }

    protected function comment() {
        return 'rpa,' . $this->action . ',' . $this->source;
    }

}
